<?php
/**
 * Credit Cron Job — webhook receiver for GHL "Cron Job: Credit Tracking" workflow.
 * Dual-mode: HTTP (production webhook) and CLI (testing).
 */

ini_set('memory_limit', '512M');

// ─── Configuration ────────────────────────────────────────────────────────────

define('TRANSACTIONS_DIR',  __DIR__ . '/csv_files');
define('TOTAL_CREDITS_CSV', __DIR__ . '/total_credits.csv');
define('ALERT_WEBHOOK_URL', 'https://services.leadconnectorhq.com/hooks/BXuCudh2EKUEmv1gC4ai/webhook-trigger/9599e28d-0783-43f9-9646-a14396316e75');
define('THRESHOLD_PERCENT', 80);

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

// ─── CSV helpers (mirroring credit_widget_admin.php) ─────────────────────────

function getCreditLimits(string $filePath): array {
    $limits = [];
    if (!file_exists($filePath)) {
        logMsg('ERROR', "Credit limits file not found: $filePath");
        return $limits;
    }
    $file = fopen($filePath, 'r');
    if (!$file) {
        logMsg('ERROR', "Cannot open credit limits file: $filePath");
        return $limits;
    }
    $header = fgetcsv($file);
    if (!$header) { fclose($file); return $limits; }

    $idxId = $idxWa = $idxEm = $idxCall = false;
    foreach ($header as $i => $col) {
        $col = strtolower(trim($col));
        if ($col === 'locationid'  || $col === 'location id')    $idxId   = $i;
        if ($col === 'totalamount' || $col === 'whatsappcredits') $idxWa  = $i;
        if ($col === 'emailcredits')                              $idxEm   = $i;
        if ($col === 'callcredits')                               $idxCall = $i;
    }
    if ($idxId === false) { fclose($file); return $limits; }

    while (($row = fgetcsv($file)) !== false) {
        if (empty($row[$idxId])) continue;
        $locId = trim($row[$idxId]);
        $limits[$locId] = [
            'WhatsApp' => $idxWa   !== false ? floatval($row[$idxWa]   ?? 0) * 0.50  : 0,
            'Email'    => $idxEm   !== false ? floatval($row[$idxEm]   ?? 0) * 0.005 : 0,
            'Call'     => $idxCall !== false ? floatval($row[$idxCall] ?? 0)          : 0,
        ];
    }
    fclose($file);
    return $limits;
}

function getTransactions(string $dir): array {
    $rows = [];
    $files = glob($dir . '/*.csv') ?: [];
    foreach ($files as $filePath) {
        if (basename($filePath) === 'total_credits.csv') continue;
        if (!file_exists($filePath)) continue;
        $file = fopen($filePath, 'r');
        if (!$file) continue;
        $header = fgetcsv($file);
        if (!$header) { fclose($file); continue; }

        $idxId = $idxName = $idxType = $idxAmt = $idxDesc = false;
        foreach ($header as $i => $col) {
            $col = strtolower(trim($col));
            if ($col === 'locationid'      || $col === 'location id')      $idxId   = $i;
            if ($col === 'locationname'    || $col === 'location name')    $idxName = $i;
            if ($col === 'type'            || $col === 'transaction type') $idxType = $i;
            if ($col === 'amount')                                         $idxAmt  = $i;
            if ($col === 'description')                                    $idxDesc = $i;
        }
        if ($idxId === false || $idxAmt === false || $idxType === false) { fclose($file); continue; }

        while (($row = fgetcsv($file)) !== false) {
            if (empty($row[$idxId]) || !isset($row[$idxAmt]) || empty($row[$idxType])) continue;
            $locId = trim($row[$idxId]);
            $type  = trim($row[$idxType]);
            $desc  = $idxDesc !== false ? strtolower(trim($row[$idxDesc] ?? '')) : '';

            if (stripos($type, 'WhatsApp') !== false || stripos($desc, 'whatsapp') !== false) {
                $usageType = 'WhatsApp'; $cost = 0.50;
            } elseif (stripos($type, 'Emails') !== false || stripos($desc, 'emails') !== false) {
                $usageType = 'Email'; $cost = 0.005;
            } elseif ($type === 'Voice Minutes - Outbound Calls' || $type === 'Voice Minutes - Inbound Calls') {
                $usageType = 'Call'; $cost = floatval($row[$idxAmt]);
            } else {
                continue;
            }

            $locName = $idxName !== false && !empty($row[$idxName]) ? trim($row[$idxName]) : $locId;
            $rows[] = ['locationId' => $locId, 'locationName' => $locName, 'usageType' => $usageType, 'cost' => $cost];
        }
        fclose($file);
    }
    return $rows;
}

function aggregateUsage(array $transactions): array {
    $usage = [];
    $names = [];
    foreach ($transactions as $row) {
        $loc  = $row['locationId'];
        $type = $row['usageType'];
        if (!isset($usage[$loc][$type])) $usage[$loc][$type] = 0.0;
        $usage[$loc][$type] += $row['cost'];
        if (!isset($names[$loc])) $names[$loc] = $row['locationName'];
    }
    return ['usage' => $usage, 'names' => $names];
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
                    $alerts[]          = ['location_id' => $locId, 'location_name' => $name, 'usage_type' => $usageType];
                    $summaryNoBudget[] = "- $name ($usageType): no budget allocated but credits used";
                }
                continue;
            }
            $pct = round(($totalUsed / $budget) * 100, 2);
            if ($pct >= THRESHOLD_PERCENT) {
                $alerts[]               = ['location_id' => $locId, 'location_name' => $name, 'usage_type' => $usageType];
                $summaryOverThreshold[] = "- $name ($usageType): $pct% of credits used";
            }
        }
    }

    $parts = [];
    if ($summaryNoBudget)     $parts[] = implode('<br>', $summaryNoBudget);
    if ($summaryOverThreshold) $parts[] = implode('<br>', $summaryOverThreshold);
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

    // Load CSV data
    if (!file_exists(TOTAL_CREDITS_CSV)) {
        throw new RuntimeException('Credit limits file not found: ' . TOTAL_CREDITS_CSV);
    }
    if (!is_dir(TRANSACTIONS_DIR)) {
        throw new RuntimeException('Transactions directory not found: ' . TRANSACTIONS_DIR);
    }

    $credits      = getCreditLimits(TOTAL_CREDITS_CSV);
    $transactions = getTransactions(TRANSACTIONS_DIR);

    logMsg('INFO', sprintf('Loaded %s transaction rows from csv_files/', number_format(count($transactions))));
    logMsg('INFO', sprintf('Loaded %s credit budget entries from total_credits.csv', number_format(count($credits))));

    $agg    = aggregateUsage($transactions);
    $usage  = $agg['usage'];
    $names  = $agg['names'];
    $pairs  = array_sum(array_map('count', $usage));
    logMsg('INFO', sprintf('Grouped into %s unique (location_id, usage_type) pairs', number_format($pairs)));

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
