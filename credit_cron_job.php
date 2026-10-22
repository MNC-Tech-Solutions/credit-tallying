<?php
/**
 * Credit Cron Job — webhook receiver for GHL "Cron Job: Credit Tracking" workflow.
 * Dual-mode: HTTP (production webhook) and CLI (testing).
 */

ini_set('memory_limit', '512M');

// ─── Configuration ────────────────────────────────────────────────────────────

define('ALERT_WEBHOOK_URL', 'https://services.leadconnectorhq.com/hooks/BXuCudh2EKUEmv1gC4ai/webhook-trigger/9599e28d-0783-43f9-9646-a14396316e75');
define('THRESHOLD_PERCENT', 80);

// Zero-amount transactions are only excluded from billing/display starting this date.
// Transactions before this date still charge the flat rate regardless of amount.
// Keep in sync with credit_widget.php.
const ZERO_AMOUNT_EXCLUDE_SINCE = '2026-08-01';

// From this date WhatsApp is priced per type instead of a flat RM0.50. Only the types
// below are billed; every other WhatsApp type (inbound, conversation, free entry point,
// usage, calls) is excluded. Service messages: the first SERVICE_MSG_FREE_QUOTA per
// subaccount each calendar month are free, the rest are charged the service rate.
const WHATSAPP_TYPE_RATES_SINCE = '2026-10-01';
const WHATSAPP_TYPE_RATES = [   // keys are lowercase type names
    'whatsapp marketing messages' => 0.50,
    'whatsapp utility messages'   => 0.10,
    'whatsapp service messages'   => 0.10,
];
const SERVICE_MSG_TYPE       = 'whatsapp service messages';
const SERVICE_MSG_FREE_QUOTA = 1000;

// ─── Runtime detection ────────────────────────────────────────────────────────

$isCli  = (php_sapi_name() === 'cli');
$isDry  = false;
$rawBody = null;

// ─── Logging ─────────────────────────────────────────────────────────────────

function logMsg(string $level, string $msg): void {
    global $isCli;
    $line = sprintf('[%-5s]  %s', $level, $msg);
    if ($isCli) {
        echo $line . "\n";
    } else {
        error_log($line);
    }
}

// ─── RDS helpers ─────────────────────────────────────────────────────────────

function getDb(): PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    $host = getenv('DB_HOST') ?: 'ghl-credits-db.cr0yeukuujnk.ap-southeast-1.rds.amazonaws.com';
    $name = getenv('DB_NAME') ?: 'ghlcredits';
    $user = getenv('DB_USER') ?: 'admin';
    $pass = getenv('DB_PASS') ?: 'ghlcredits123';
    $db = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $db;
}

// Returns ['WhatsApp' => RM budget, 'Email' => RM budget, 'Call' => RM budget] per location
function getCreditLimits(): array {
    $limits = [];
    $stmt = getDb()->query('SELECT location_id, wa_credits, email_credits, call_credits FROM credit_limits');
    while ($row = $stmt->fetch()) {
        $locId = trim($row['location_id']);
        $limits[$locId] = [
            'WhatsApp' => floatval($row['wa_credits'])    * 0.50,
            'Email'    => floatval($row['email_credits'])  * 0.005,
            'Call'     => floatval($row['call_credits'])   * 0.054,
        ];
    }
    return $limits;
}

// SQL boolean: tx_date is in or after the month of $since. tx_date is stored as a
// formatted string like "Aug 1st 2026, 10:23:45 AM", so we compare year/month only
// (cutoffs are always the 1st of a month) using plain string functions — keeps the
// filter usable directly in SQL on a large/growing table.
function txOnOrAfterSql(string $since): string {
    $cutoff = new DateTime($since);
    $year   = (int)$cutoff->format('Y');
    $month  = (int)$cutoff->format('n');
    $txYear = "CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(tx_date, ',', 1), ' ', -1) AS UNSIGNED)";
    return "($txYear > $year OR ($txYear = $year
        AND FIELD(LEFT(tx_date, 3), 'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec') >= $month))";
}

// SQL expression: tx_date's month as "YYYY-MM" (same string format as txOnOrAfterSql()).
function txMonthSql(): string {
    return "CONCAT(SUBSTRING_INDEX(SUBSTRING_INDEX(tx_date, ',', 1), ' ', -1), '-',
        LPAD(FIELD(LEFT(tx_date, 3), 'Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'), 2, '0'))";
}

// SQL boolean: row is dated on/after WHATSAPP_TYPE_RATES_SINCE (per-type WhatsApp pricing).
function whatsappTypeRatesSql(): string {
    return txOnOrAfterSql(WHATSAPP_TYPE_RATES_SINCE);
}

// SQL boolean: row's type is one of the billed WHATSAPP_TYPE_RATES types.
function whatsappBilledTypeSql(): string {
    return "LOWER(IFNULL(type, '')) IN ('" . implode("','", array_keys(WHATSAPP_TYPE_RATES)) . "')";
}

// SQL boolean: WhatsApp service message under per-type pricing.
function serviceMessageSql(): string {
    return "(LOWER(IFNULL(type, '')) = '" . SERVICE_MSG_TYPE . "' AND " . whatsappTypeRatesSql() . ")";
}

// SQL boolean expression: TRUE unless the row is excluded from billing:
//  - a zero-amount transaction dated on/after ZERO_AMOUNT_EXCLUDE_SINCE (billed
//    WhatsApp types under per-type pricing are kept), or
//  - a WhatsApp row of a non-billed type dated on/after WHATSAPP_TYPE_RATES_SINCE.
function zeroAmountExcludeSql(): string {
    static $sql = null;
    if ($sql !== null) return $sql;
    $isWhatsApp = "(IFNULL(type, '') LIKE '%WhatsApp%' OR IFNULL(description, '') LIKE '%whatsapp%')";
    $sql = "NOT (
        (
            (amount IS NOT NULL AND amount = 0)
            AND " . txOnOrAfterSql(ZERO_AMOUNT_EXCLUDE_SINCE) . "
            AND NOT (" . whatsappTypeRatesSql() . " AND " . whatsappBilledTypeSql() . ")
        )
        OR (" . whatsappTypeRatesSql() . " AND $isWhatsApp AND NOT " . whatsappBilledTypeSql() . ")
    )";
    return $sql;
}

// RM per WhatsApp row (service messages are priced separately). $perType is true for
// rows dated on/after WHATSAPP_TYPE_RATES_SINCE; returns null for a non-billed type.
function whatsappRate(string $type, bool $perType): ?float {
    if (!$perType) return 0.50;
    return WHATSAPP_TYPE_RATES[strtolower(trim($type))] ?? null;
}

// Total RM billed for a subaccount's $count service messages in one month.
function serviceMessageCharge(int $count): float {
    return max(0, $count - SERVICE_MSG_FREE_QUOTA) * WHATSAPP_TYPE_RATES[SERVICE_MSG_TYPE];
}

// Returns usage (RM spent) and location names from transactions table.
// WhatsApp/Email follow credit_widget.php: match on type OR description, flat rate per row.
function getUsageFromDb(): array {
    $usage        = [];
    $names        = [];
    $serviceCount = [];
    $stmt = getDb()->query(
        'SELECT location_id, location_name, type, description, amount,
                ' . serviceMessageSql() . ' AS is_service, ' . txMonthSql() . ' AS tx_month,
                ' . whatsappTypeRatesSql() . ' AS per_type
         FROM transactions
         WHERE ' . zeroAmountExcludeSql()
    );
    while ($row = $stmt->fetch()) {
        if (empty($row['location_id']) || empty($row['type'])) continue;
        $locId       = trim($row['location_id']);
        $type        = trim($row['type']);
        $description = strtolower(trim($row['description'] ?? ''));

        if (!empty($row['is_service'])) {
            // Priced after the loop once the subaccount's total is known
            $month = $row['tx_month'];
            $serviceCount[$locId][$month] = ($serviceCount[$locId][$month] ?? 0) + 1;
            $usageType = 'WhatsApp'; $cost = 0.0;
        } elseif (stripos($type, 'WhatsApp') !== false || stripos($description, 'whatsapp') !== false) {
            $usageType = 'WhatsApp'; $cost = whatsappRate($type, !empty($row['per_type']));
            if ($cost === null) continue;
        } elseif (stripos($type, 'Emails') !== false || stripos($description, 'emails') !== false) {
            $usageType = 'Email'; $cost = 0.005;
        } elseif ($type === 'Voice Minutes - Outbound Calls' || $type === 'Voice Minutes - Inbound Calls') {
            $usageType = 'Call'; $cost = abs(floatval($row['amount'] ?? 0));
        } else {
            continue;
        }

        if (!isset($usage[$locId][$usageType])) $usage[$locId][$usageType] = 0.0;
        $usage[$locId][$usageType] += $cost;
        if (!isset($names[$locId]) && !empty($row['location_name'])) $names[$locId] = trim($row['location_name']);
    }
    foreach ($serviceCount as $locId => $months) {
        foreach ($months as $count) $usage[$locId]['WhatsApp'] += serviceMessageCharge($count);
    }
    return ['usage' => $usage, 'names' => $names];
}

// Allocated / used / exceed / balance in RM for one (location, usage type)
function creditFigures(float $budget, float $used): array {
    return [
        'allocated' => round($budget, 2),
        'used'      => round($used, 2),
        'exceed'    => round(max(0, $used - $budget), 2),
        'balance'   => round(max(0, $budget - $used), 2),
    ];
}

// RM amount: whole ringgit with commas, but keep cents for small amounts (e.g. Email)
function rm(float $v): string {
    return 'RM' . number_format($v, $v < 100 ? 2 : 0);
}

// One short line per usage type, e.g. "WhatsApp 119% · RM17,914 / RM15,000 · Over RM2,914"
function formatLine(string $usageType, float $budget, float $used): string {
    if ($budget <= 0) {
        return "• $usageType · Used " . rm($used);
    }
    $f    = creditFigures($budget, $used);
    $pct  = round(($used / $budget) * 100);
    $tail = $f['exceed'] > 0 ? 'Over ' . rm($f['exceed']) : 'Left ' . rm($f['balance']);
    return "• $usageType $pct% · " . rm($used) . ' / ' . rm($budget) . " · $tail";
}

// Groups lines under each subaccount name
function formatSection(string $title, array $byLocation): string {
    $out = ["<b>$title</b>"];
    foreach ($byLocation as $name => $lines) {
        $out[] = '<br>' . htmlspecialchars($name) . '<br>' . implode('<br>', $lines);
    }
    return implode('<br>', $out);
}

function buildAlerts(array $usage, array $names, array $credits): array {
    $alerts         = [];
    $summaryNoBudget = [];
    $summaryOverThreshold = [];
    foreach ($usage as $locId => $types) {
        foreach ($types as $usageType => $totalUsed) {
            if (!isset($credits[$locId])) {
                logMsg('WARN', "No budget entry found for location ($locId) — skipping");
                continue;
            }
            $budget = $credits[$locId][$usageType] ?? null;
            if ($budget === null) {
                logMsg('WARN', "No budget entry found for ($locId, $usageType) — skipping");
                continue;
            }
            $name = $names[$locId] ?? $locId;
            if ($budget <= 0) {
                if ($totalUsed > 0) {
                    $alerts[]                  = ['location_id' => $locId, 'location_name' => $name, 'usage_type' => $usageType] + creditFigures(0.0, $totalUsed);
                    $summaryNoBudget[$name][]  = formatLine($usageType, 0.0, $totalUsed);
                }
                continue;
            }
            $pct = round(($totalUsed / $budget) * 100, 2);
            if ($pct >= THRESHOLD_PERCENT) {
                $alerts[]                       = ['location_id' => $locId, 'location_name' => $name, 'usage_type' => $usageType, 'percent_used' => $pct] + creditFigures($budget, $totalUsed);
                $summaryOverThreshold[$name][]  = formatLine($usageType, $budget, $totalUsed);
            }
        }
    }

    $parts = [];
    if ($summaryOverThreshold) $parts[] = formatSection('Over ' . THRESHOLD_PERCENT . '% used (Used / Allocated)', $summaryOverThreshold);
    if ($summaryNoBudget)      $parts[] = formatSection('No budget allocated', $summaryNoBudget);
    $summary = implode('<br><br>', $parts);

    return ['alerts' => $alerts, 'summary' => $summary];
}

function buildPayload(array $rawBody, array $alerts, string $alertsSummary): array {
    $workflow  = $rawBody['workflow']  ?? [];
    $location  = $rawBody['location']  ?? [];
    return [
        'triggered_at'   => gmdate('Y-m-d\TH:i:s\Z'),
        'triggered_by'   => [
            'workflow_id'   => $workflow['id']   ?? '',
            'workflow_name' => $workflow['name'] ?? '',
            'contact_id'    => $rawBody['contact_id'] ?? '',
            'location_id'   => $location['id']   ?? '',
            'location_name' => $location['name'] ?? '',
        ],
        'alerts'         => $alerts,
        'total_alerts'   => count($alerts),
        'alerts_summary' => $alertsSummary,
    ];
}

function postWebhook(array $payload): void {
    $ch = curl_init(ALERT_WEBHOOK_URL);
    curl_setopt($ch, CURLOPT_POST,          true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,    json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER,    ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,       10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        logMsg('ERROR', "curl error posting to webhook: $curlErr");
    } elseif ($httpCode >= 200 && $httpCode < 300) {
        logMsg('INFO', "Webhook POST succeeded (HTTP $httpCode)");
    } else {
        logMsg('ERROR', "Webhook POST failed (HTTP $httpCode): $response");
    }
}

// ─── Entry point ──────────────────────────────────────────────────────────────

try {
    if ($isCli) {
        // Parse CLI flags
        $opts   = getopt('', ['dry-run', 'trigger:']);
        $isDry  = isset($opts['dry-run']);

        $mockPayload = [
            'timestamp' => '2026-05-18 02:48:39',
            'ip'        => '127.0.0.1',
            'raw_body'  => [
                'contact_id' => 'test_contact_001',
                'full_name'  => 'Test User',
                'location'   => [
                    'id'   => 'BXuCudh2EKUEmv1gC4ai',
                    'name' => 'SJ360',
                ],
                'workflow' => [
                    'id'   => 'c612e7d9-d649-4878-97f6-c953e7a5ecb7',
                    'name' => 'Cron Job: Credit Tracking',
                ],
                'customData' => [
                    'trigger_word' => isset($opts['trigger']) ? $opts['trigger'] : 'credit_cron_job',
                ],
            ],
        ];

        $rawBody = $mockPayload['raw_body'];
        logMsg('INFO', 'Mode: CLI' . ($isDry ? ' (dry-run)' : ''));
    } else {
        // HTTP mode
        $body = file_get_contents('php://input');
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'reason' => 'invalid JSON body']);
            exit;
        }
        $rawBody = isset($decoded['raw_body']) ? $decoded['raw_body'] : $decoded;
        logMsg('INFO', 'Mode: HTTP — POST received');
        logMsg('INFO', 'POST body: ' . json_encode($decoded));
    }

    // Trigger word validation
    $triggerWord = strtolower(trim($rawBody['customData']['trigger_word'] ?? ''));
    logMsg('INFO', "trigger_word: \"$triggerWord\"");

    if ($triggerWord !== 'credit_cron_job') {
        logMsg('INFO', 'trigger_word mismatch or missing — ignoring');
        if ($isCli) {
            echo json_encode(['status' => 'ignored', 'reason' => 'trigger_word mismatch or missing']) . "\n";
        } else {
            header('Content-Type: application/json');
            http_response_code(200);
            echo json_encode(['status' => 'ignored', 'reason' => 'trigger_word mismatch or missing']);
        }
        exit;
    }
    logMsg('INFO', 'trigger_word: MATCH, proceeding');

    // Load data from RDS
    $credits = getCreditLimits();
    $agg     = getUsageFromDb();
    $usage   = $agg['usage'];
    $names   = $agg['names'];
    $pairs   = array_sum(array_map('count', $usage));

    logMsg('INFO', sprintf('Loaded %s credit budget entries from credit_limits table', number_format(count($credits))));
    logMsg('INFO', sprintf('Grouped into %s unique (location_id, usage_type) pairs from transactions table', number_format($pairs)));
    $result  = buildAlerts($usage, $names, $credits);
    $alerts  = $result['alerts'];
    $payload = buildPayload($rawBody, $alerts, $result['summary']);

    logMsg('INFO', sprintf('Found %d entries at or above %d%% threshold', count($alerts), THRESHOLD_PERCENT));

    if ($isCli && $isDry) {
        logMsg('INFO', '[DRY RUN] Skipping webhook POST — payload preview:');
        echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
    } else {
        postWebhook($payload);
    }

    logMsg('INFO', 'Done.');

    if (!$isCli) {
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode([
            'status'       => 'ok',
            'total_alerts' => count($alerts),
            'triggered_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

} catch (Throwable $e) {
    logMsg('ERROR', 'Unhandled exception: ' . $e->getMessage());
    if (!$isCli) {
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode(['status' => 'error', 'reason' => $e->getMessage()]);
    }
}
