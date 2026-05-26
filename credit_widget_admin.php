<?php
/**
 * GHL Credits Dashboard – Admin / All Subaccounts
 * Design: DM Sans + DM Serif Display, warm neutral palette.
 * Fix: remaining shows negative (red) when exceeded.
 * currently using in sj360
 */
function getDb(): PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;

    $host = getenv('DB_HOST') ?: 'ghl-credits-db.cr0yeukuujnk.ap-southeast-1.rds.amazonaws.com';
    $name = getenv('DB_NAME') ?: 'ghlcredits';
    $user = getenv('DB_USER') ?: 'admin';
    $pass = getenv('DB_PASS') ?: 'ghlcredits123';

    $db = new PDO(
        "mysql:host={$host};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    return $db;
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function getCreditLimits() {
    $limits = [];
    $stmt = getDb()->query('SELECT location_id, wa_credits, email_credits, call_credits FROM credit_limits');
    while ($row = $stmt->fetch()) {
        $locId = trim($row['location_id'] ?? '');
        if ($locId === '') continue;
        $limits[$locId] = [
            'whatsappCredit' => floatval($row['wa_credits']    ?? 0),
            'emailCredit'    => floatval($row['email_credits'] ?? 0),
            'callCredit'     => floatval($row['call_credits']  ?? 0),
        ];
    }
    return $limits;
}

function getTransactionData() {
    $results = [];
    $stmt = getDb()->query('SELECT location_id, location_name, type, description, tx_date, amount FROM transactions');
    while ($row = $stmt->fetch()) {
        if (empty($row['location_id']) || empty($row['type'])) continue;
        $locId = trim($row['location_id']);
        $type  = trim($row['type']);
        $desc  = strtolower(trim($row['description'] ?? ''));

        if (stripos($type, 'WhatsApp') !== false || stripos($desc, 'whatsapp') !== false) {
            $cat = 'whatsapp'; $cost = 0.50;
        } elseif (stripos($type, 'Emails') !== false || stripos($desc, 'emails') !== false) {
            $cat = 'email'; $cost = 0.005;
        } elseif ($type === 'Voice Minutes - Outbound Calls' || $type === 'Voice Minutes - Inbound Calls') {
            $cat = 'call'; $cost = floatval($row['amount'] ?? 0);
        } else {
            continue;
        }

        $locName = !empty($row['location_name']) ? trim($row['location_name']) : $locId;

        if (!isset($results[$locId])) {
            $results[$locId] = [
                'locationName'   => $locName,
                'emailAmount'    => 0, 'whatsappAmount' => 0, 'callAmount'    => 0,
                'emailCount'     => 0, 'whatsappCount'  => 0, 'callCount'     => 0,
                'monthlyData'    => [],
            ];
        }

        if ($cat === 'email') {
            $results[$locId]['emailAmount']    += $cost;
            $results[$locId]['emailCount']++;
        } elseif ($cat === 'whatsapp') {
            $results[$locId]['whatsappAmount'] += $cost;
            $results[$locId]['whatsappCount']++;
        } else {
            $results[$locId]['callAmount']     += $cost;
            $results[$locId]['callCount']++;
        }

        if (!empty($row['tx_date'])) {
            $ds   = preg_replace('/(st|nd|rd|th)/', '', $row['tx_date']);
            $date = DateTime::createFromFormat('M j Y, h:i:s A', trim($ds));
            if ($date) {
                $mk = $date->format('Y-m');
                if (!isset($results[$locId]['monthlyData'][$mk]))
                    $results[$locId]['monthlyData'][$mk] = ['types' => []];
                if (!isset($results[$locId]['monthlyData'][$mk]['types'][$cat]))
                    $results[$locId]['monthlyData'][$mk]['types'][$cat] = ['totalAmount' => 0, 'count' => 0, 'transactions' => []];
                $results[$locId]['monthlyData'][$mk]['types'][$cat]['totalAmount'] += $cost;
                $results[$locId]['monthlyData'][$mk]['types'][$cat]['count']++;
                $results[$locId]['monthlyData'][$mk]['types'][$cat]['transactions'][] = [
                    'date'         => $row['tx_date'],
                    'amount'       => $cost,
                    'originalType' => $type,
                ];
            }
        }
    }
    return $results;
}

function getGhlLocations(): array {
    $cacheFile = sys_get_temp_dir() . '/ghl_locations.json';
    $cacheTtl  = 300;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && count($cached) > 0) return $cached;
    }

    $token = getenv('GHL_TOKEN') ?: 'pit-54903d5b-6a1d-4e3f-be88-d9debe7ede5d';
    $ch = curl_init('https://services.leadconnectorhq.com/locations/search?limit=100');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Version: 2023-02-21',
        ],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    $data      = json_decode($body ?: '', true);
    $locations = [];
    foreach ($data['locations'] ?? [] as $loc) {
        if (!empty($loc['id']) && !empty($loc['name'])) {
            $locations[] = ['id' => $loc['id'], 'name' => $loc['name']];
        }
    }
    usort($locations, fn($a, $b) => strcmp($a['name'], $b['name']));

    if (count($locations) > 0) {
        file_put_contents($cacheFile, json_encode($locations));
    }
    return $locations;
}

function displayError($msg) {
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><link href='https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600&family=DM+Serif+Display&display=swap' rel='stylesheet'></head><body style='background:#f5f4f1;font-family:DM Sans,system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;'><div style='background:#fff;border:1px solid #e8e5e0;border-radius:20px;padding:48px 40px;text-align:center;max-width:420px;box-shadow:0 4px 16px rgba(0,0,0,0.07);'><div style='font-size:40px;margin-bottom:12px;'>âš ï¸</div><h2 style='font-family:DM Serif Display,serif;color:#1a1916;margin:0 0 8px;font-size:22px;'>$msg</h2></div></body></html>");
}


// Handle topup POST before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_topup') {
    header('Content-Type: application/json');
    try {
        $locId   = trim($_POST['location_id'] ?? '');
        $date    = trim($_POST['topup_date'] ?? '');
        $waC     = max(0, intval($_POST['wa_credits']    ?? 0));
        $emC     = max(0, intval($_POST['email_credits'] ?? 0));
        $callC   = max(0, intval($_POST['call_credits']  ?? 0));
        $addedBy = trim($_POST['added_by'] ?? '');
        $notes   = trim($_POST['notes'] ?? '');
        if (!$locId || !$date || !$addedBy) {
            echo json_encode(['ok' => false, 'error' => 'Location, date, and added by are required.']);
            exit;
        }
        $db = getDb();

        // Log topup record
        $db->prepare("INSERT INTO credit_topups (location_id, topup_date, wa_credits, email_credits, call_credits, added_by, notes) VALUES (?, ?, ?, ?, ?, ?, ?)")
           ->execute([$locId, $date, $waC, $emC, $callC, $addedBy, $notes]);

        // Upsert credit_limits: add on top if exists, insert if new client
        $check = $db->prepare("SELECT location_id FROM credit_limits WHERE location_id = ?");
        $check->execute([$locId]);
        if ($check->fetch()) {
            $db->prepare("UPDATE credit_limits SET wa_credits = wa_credits + ?, email_credits = email_credits + ?, call_credits = call_credits + ? WHERE location_id = ?")
               ->execute([$waC, $emC, $callC, $locId]);
        } else {
            $db->prepare("INSERT INTO credit_limits (location_id, wa_credits, email_credits, call_credits) VALUES (?, ?, ?, ?)")
               ->execute([$locId, $waC, $emC, $callC]);
        }

        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_csv') {
    header('Content-Type: application/json');
    try {
        if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['ok' => false, 'error' => 'No file uploaded or upload error.']);
            exit;
        }

        $tmpPath = $_FILES['csv_file']['tmp_name'];
        $fh      = fopen($tmpPath, 'r');

        // Read and normalise header row
        $rawHeaders = fgetcsv($fh);
        if (!$rawHeaders) {
            echo json_encode(['ok' => false, 'error' => 'CSV file is empty.']);
            fclose($fh); exit;
        }
        $headers = array_map(fn($h) => strtolower(trim(preg_replace('/\s+/', '', $h))), $rawHeaders);

        $col = fn($names) => (function() use ($headers, $names) {
            foreach ((array)$names as $n) {
                $i = array_search($n, $headers);
                if ($i !== false) return $i;
            }
            return false;
        })();

        $g  = fn($idx, $row) => $idx !== false ? trim($row[$idx] ?? '') : null;
        $gf = fn($idx, $row) => $idx !== false && ($row[$idx] ?? '') !== '' ? floatval($row[$idx]) : null;

        $idxRowId    = $col(['transactionid', 'transaction id', 'id']);
        $idxId       = $col(['locationid', 'location id', 'location_id']);
        $idxName     = $col(['locationname', 'location name', 'location_name']);
        $idxType     = $col(['transactiontype', 'transaction type', 'type']);
        $idxDesc     = $col(['description']);
        $idxMsgDate  = $col(['activitydate', 'activity date', 'messagedate', 'message date']);
        $idxTxDate   = $col(['date', 'tx_date']);
        $idxAmount   = $col(['amount']);
        $idxBalance  = $col(['walletbalanceaftertransaction', 'balance']);
        $idxTotBal   = $col(['totalwalletbalance(includingwalletcredits)', 'totalbalance', 'total balance', 'total wallet balance']);
        $idxCredUsed = $col(['creditsused', 'credits used']);
        $idxOrigAmt  = $col(['originalamount', 'original amount']);
        $idxDisc     = $col(['discountapplied', 'discount applied', 'discountamount']);

        if ($idxId === false || $idxType === false) {
            echo json_encode(['ok' => false, 'error' => 'CSV must have locationId and type columns.']);
            fclose($fh); exit;
        }

        $sourceFile = $_FILES['csv_file']['name'];
        $db = getDb();
        $db->beginTransaction();
        $stmt = $db->prepare(
            'INSERT INTO transactions
             (row_id, location_id, location_name, type, description,
              message_date, tx_date, amount, balance, total_balance,
              credits_used, original_amount, discount_amount, source_file)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $count = 0;
        while (($row = fgetcsv($fh)) !== false) {
            $locId = $g($idxId, $row);
            if ($locId === '' || $locId === null) continue;
            $stmt->execute([
                $g($idxRowId,    $row),
                $locId,
                $g($idxName,     $row),
                $g($idxType,     $row),
                $g($idxDesc,     $row),
                $g($idxMsgDate,  $row),
                $g($idxTxDate,   $row),
                $gf($idxAmount,  $row),
                $gf($idxBalance, $row),
                $gf($idxTotBal,  $row),
                $gf($idxCredUsed,$row),
                $gf($idxOrigAmt, $row),
                $gf($idxDisc,    $row),
                $sourceFile,
            ]);
            $count++;
        }
        $db->commit();
        fclose($fh);
        @unlink($tmpPath);

        echo json_encode(['ok' => true, 'count' => $count]);
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) $db->rollBack();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

$creditLimits  = getCreditLimits();
$processedData = getTransactionData();

if (empty($processedData)) displayError("No transaction data found in the database.");

// ─── Build Subaccount Data ────────────────────────────────────────────────────

// Helper: build one subaccount record (allows negative remaining)
function buildRecord($waCredit, $emCredit, $callCredit, $waUsedRm, $emUsedRm, $callUsedRm) {
    $waMaxRm   = $waCredit / 2;
    $emMaxRm   = $emCredit * 0.005;
    $callMaxRm = $callCredit * 0.054;
    $waUsedC   = $waUsedRm   / 0.50;
    $emUsedC   = $emUsedRm   / 0.005;
    $callUsedC = $callUsedRm / 0.054;
    return [
        'whatsappCredit'          => $waCredit,
        'emailCredit'             => $emCredit,
        'callCredit'              => $callCredit,
        'whatsappUsedCredit'      => $waUsedC,
        'emailUsedCredit'         => $emUsedC,
        'callUsedCredit'          => $callUsedC,
        'whatsappRemainingCredit' => $waCredit   - $waUsedC,
        'emailRemainingCredit'    => $emCredit   - $emUsedC,
        'callRemainingCredit'     => $callCredit - $callUsedC,
        'whatsappAmount'          => $waMaxRm,
        'emailAmount'             => $emMaxRm,
        'callAmount'              => $callMaxRm,
        'whatsappUsedAmount'      => $waUsedRm,
        'emailUsedAmount'         => $emUsedRm,
        'callUsedAmount'          => $callUsedRm,
        'whatsappRemainingAmount' => $waMaxRm   - $waUsedRm,
        'emailRemainingAmount'    => $emMaxRm   - $emUsedRm,
        'callRemainingAmount'     => $callMaxRm - $callUsedRm,
        'whatsappPercent'         => $waMaxRm   > 0 ? min(100, round($waUsedRm   / $waMaxRm   * 100)) : 0,
        'emailPercent'            => $emMaxRm   > 0 ? min(100, round($emUsedRm   / $emMaxRm   * 100)) : 0,
        'callPercent'             => $callMaxRm > 0 ? min(100, round($callUsedRm / $callMaxRm * 100)) : 0,
    ];
}

$subaccountData = [];

// All-Subaccounts aggregate
$totWaCredit = $totEmCredit = $totCallCredit = $totWaUsed = $totEmUsed = $totCallUsed = 0;
foreach ($processedData as $locId => $data) {
    $totWaCredit   += $creditLimits[$locId]['whatsappCredit'] ?? 0;
    $totEmCredit   += $creditLimits[$locId]['emailCredit']    ?? 0;
    $totCallCredit += $creditLimits[$locId]['callCredit']     ?? 0;
    $totWaUsed     += $data['whatsappAmount'];
    $totEmUsed     += $data['emailAmount'];
    $totCallUsed   += $data['callAmount'];
}
$subaccountData[''] = array_merge(buildRecord($totWaCredit, $totEmCredit, $totCallCredit, $totWaUsed, $totEmUsed, $totCallUsed), [
    'name'        => 'All Subaccounts',
    'monthlyData' => [],
]);

// Individual subaccounts
foreach ($processedData as $locId => $data) {
    $waC   = $creditLimits[$locId]['whatsappCredit'] ?? 0;
    $emC   = $creditLimits[$locId]['emailCredit']    ?? 0;
    $callC = $creditLimits[$locId]['callCredit']     ?? 0;
    $subaccountData[$locId] = array_merge(
        buildRecord($waC, $emC, $callC, $data['whatsappAmount'], $data['emailAmount'], $data['callAmount']),
        [
            'name'        => $data['locationName'],
            'monthlyData' => $data['monthlyData'],
            'waCount'     => $data['whatsappCount'],
            'emCount'     => $data['emailCount'],
            'callCount'   => $data['callCount'],
        ]
    );
}

$initialData        = $subaccountData[''];
$subaccountDataJson = json_encode($subaccountData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Subaccount rows for PHP-rendered table
$subRows = [];
foreach ($processedData as $locId => $data) {
    $d = $subaccountData[$locId];
    $subRows[] = [
        'id'        => $locId,
        'name'      => $data['locationName'],
        'waUsed'    => $data['whatsappAmount'],
        'emUsed'    => $data['emailAmount'],
        'callUsed'  => $data['callAmount'],
        'waCount'   => $data['whatsappCount'],
        'emCount'   => $data['emailCount'],
        'callCount' => $data['callCount'],
        'waPct'     => $d['whatsappPercent'],
        'emPct'     => $d['emailPercent'],
        'callPct'   => $d['callPercent'],
        'waRem'     => $d['whatsappRemainingCredit'],
        'emRem'     => $d['emailRemainingCredit'],
        'callRem'   => $d['callRemainingCredit'],
    ];
}
usort($subRows, fn($a, $b) => ($b['waUsed'] + $b['emUsed'] + $b['callUsed']) <=> ($a['waUsed'] + $a['emUsed'] + $a['callUsed']));

// Fetch all GHL locations for dropdowns (cached 5 min)
$ghlLocations = getGhlLocations();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHL Credits Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <style>
    :root {
        --bg:          #f5f4f1;
        --surface:     #faf9f7;
        --card:        #ffffff;
        --border:      #e8e5e0;
        --border2:     #d6d2cb;
        --wa:          #25D366;
        --wa-light:    #1db954;
        --wa-soft:     rgba(37,211,102,0.09);
        --em:          #1e6fa8;
        --em-light:    #3b82f6;
        --em-soft:     rgba(30,111,168,0.09);
        --call:        #d97706;
        --call-light:  #f59e0b;
        --call-soft:   rgba(217,119,6,0.09);
        --accent:      #7c3aed;
        --accent-soft: rgba(124,58,237,0.08);
        --danger:      #dc2626;
        --danger-soft: rgba(220,38,38,0.08);
        --danger-mid:  rgba(220,38,38,0.18);
        --text:        #1a1916;
        --text2:       #6b6760;
        --text3:       #b0aca5;
        --r:    20px;
        --r-sm: 12px;
        --r-xs: 8px;
        --shadow-sm: 0 1px 3px rgba(0,0,0,0.06),0 1px 2px rgba(0,0,0,0.04);
        --shadow-md: 0 4px 16px rgba(0,0,0,0.07),0 1px 4px rgba(0,0,0,0.04);
        --shadow-xl: 0 24px 60px rgba(0,0,0,0.12),0 4px 16px rgba(0,0,0,0.06);
    }
    *{margin:0;padding:0;box-sizing:border-box;}
    html{scroll-behavior:smooth;}
    body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-bottom:80px;line-height:1.6;font-size:15px;}
    body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
        background:radial-gradient(ellipse 70% 50% at 10% 0%,rgba(37,211,102,0.04) 0%,transparent 60%),
                   radial-gradient(ellipse 50% 40% at 90% 100%,rgba(30,111,168,0.03) 0%,transparent 60%);}
    .wrap{max-width:1250px;margin:0 auto;padding:0 28px;position:relative;z-index:1;}

    /* ── TOPBAR ── */
    .topbar{display:flex;align-items:center;justify-content:space-between;padding:32px 0 28px;margin-bottom:28px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:16px;}
    .brand{display:flex;align-items:center;gap:14px;}
    .brand-icon{width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,var(--wa),var(--wa-light));display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(37,211,102,0.28);flex-shrink:0;}
    .brand-icon svg{width:22px;height:22px;}
    .brand-name{font-family:'DM Serif Display',serif;font-size:21px;color:var(--text);letter-spacing:-0.3px;}
    .brand-sub{font-size:12px;color:var(--text2);margin-top:1px;}

    /* Subaccount selector */
    .sel-wrap{position:relative;min-width:280px;}
    .sel-wrap svg.arr{position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--text3);width:14px;height:14px;}
    .sub-sel{width:100%;padding:11px 44px 11px 16px;font-size:14px;font-family:'DM Sans',sans-serif;font-weight:500;color:var(--text);background:var(--card);border:1px solid var(--border);border-radius:var(--r-sm);cursor:pointer;appearance:none;outline:none;transition:.2s;box-shadow:var(--shadow-sm);}
    .sub-sel:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);}

    /* ── USAGE CARDS ── */
    .usage-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px;}
    .ucard{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;box-shadow:var(--shadow-sm);transition:transform .2s,box-shadow .2s;animation:fadeUp .5s ease both;}
    .ucard:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
    .ucard::before{content:'';display:block;height:4px;}
    .ucard.wa::before{background:linear-gradient(90deg,var(--wa),var(--wa-light));}
    .ucard.em::before{background:linear-gradient(90deg,var(--em),var(--em-light));}
    .ucard.call::before{background:linear-gradient(90deg,var(--call),var(--call-light));}
    .ucard:nth-child(1){animation-delay:.05s}
    .ucard:nth-child(2){animation-delay:.12s}
    .ucard:nth-child(3){animation-delay:.19s}
    .ucard-inner{padding:26px 28px;}

    /* Card head */
    .ucard-head{display:flex;align-items:center;gap:12px;margin-bottom:20px;}
    .ucard-ico{width:40px;height:40px;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .ucard.wa .ucard-ico{background:var(--wa-soft);}
    .ucard.em .ucard-ico{background:var(--em-soft);}
    .ucard.call .ucard-ico{background:var(--call-soft);}
    .ucard-ico svg{width:19px;height:19px;}
    .ucard-ttl{font-family:'DM Serif Display',serif;font-size:18px;color:var(--text);}
    .ucard-pct{font-size:12px;font-weight:700;padding:3px 11px;border-radius:999px;margin-left:auto;}
    .ucard.wa .ucard-pct{background:var(--wa-soft);color:var(--wa);}
    .ucard.em .ucard-pct{background:var(--em-soft);color:var(--em);}
    .ucard.call .ucard-pct{background:var(--call-soft);color:var(--call);}
    .ucard-pct.exceeded{background:var(--danger-soft)!important;color:var(--danger)!important;}

    /* Progress */
    .prog-wrap{margin:0 0 20px;}
    .prog-labels-top{display:flex;justify-content:space-between;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:8px;}
    .prog-track{height:10px;background:var(--bg);border-radius:999px;overflow:hidden;border:1px solid var(--border);}
    .prog-fill{height:100%;border-radius:999px;transition:width 1.2s cubic-bezier(.22,1,.36,1);}
    .ucard.wa .prog-fill{background:linear-gradient(90deg,var(--wa),var(--wa-light));}
    .ucard.em .prog-fill{background:linear-gradient(90deg,var(--em),var(--em-light));}
    .ucard.call .prog-fill{background:linear-gradient(90deg,var(--call),var(--call-light));}
    .prog-fill.exceeded{background:linear-gradient(90deg,var(--danger),#ef4444)!important;}
    .prog-midmarks{display:flex;justify-content:space-between;font-size:11px;color:var(--text3);margin-top:5px;}

    /* Tabs */
    .utabs{display:flex;gap:2px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:4px;margin-bottom:18px;}
    .utab{flex:1;padding:8px 14px;font-size:13px;font-weight:600;color:var(--text2);background:transparent;border:none;border-radius:9px;cursor:pointer;transition:background .18s,color .18s;font-family:'DM Sans',sans-serif;text-align:center;}
    .utab:hover{color:var(--text);background:var(--bg);}
    .utab.active{color:#fff;}
    .ucard.wa .utab.active{background:var(--wa);}
    .ucard.em .utab.active{background:var(--em);}
    .ucard.call .utab.active{background:var(--call);}

    /* Metric panes */
    .metrics-pane{display:none;}
    .metrics-pane.active{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
    .metric{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;}
    .metric-lbl{font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);margin-bottom:4px;}
    .metric-val{font-family:'DM Serif Display',serif;font-size:19px;color:var(--text);letter-spacing:-.3px;}
    .metric-val.exceeded{color:var(--danger);}
    .metric-sub{font-size:11px;color:var(--text3);margin-top:3px;}
    .metric.exceeded-tile{border-color:var(--danger-mid);background:var(--danger-soft);}

    /* ── SUBACCOUNTS PANEL ── */
    .panel{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:20px;animation:fadeUp .5s .18s ease both;}
    .panel-hdr{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;border-bottom:1px solid var(--border);cursor:pointer;user-select:none;transition:background .15s;}
    .panel-hdr:hover{background:var(--surface);}
    .panel-hdr-l{display:flex;align-items:center;gap:12px;}
    .panel-ttl{font-family:'DM Serif Display',serif;font-size:17px;color:var(--text);}
    .chip{font-size:11px;font-weight:600;padding:4px 12px;border-radius:999px;background:var(--bg);color:var(--text2);border:1px solid var(--border);}
    .chevron{width:18px;height:18px;color:var(--text3);transition:transform .25s;flex-shrink:0;}
    .panel.open .chevron{transform:rotate(180deg);}
    .panel-body{display:none;padding:0;}
    .panel.open .panel-body{display:block;}

    /* Subaccounts table */
    .tbl-scroll{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;}
    thead tr{background:var(--bg);}
    thead th{padding:12px 20px;font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);text-align:left;border-bottom:1px solid var(--border);white-space:nowrap;}
    th.r,td.r{text-align:right;}
    tbody tr{border-bottom:1px solid var(--border);transition:background .12s;cursor:pointer;}
    tbody tr:last-child{border-bottom:none;}
    tbody tr:hover{background:var(--accent-soft);}
    tbody td{padding:14px 20px;font-size:14px;vertical-align:middle;}
    .td-name{font-weight:600;color:var(--text);}
    .td-id{font-size:11.5px;color:var(--text3);margin-top:2px;}
    .td-amt{font-weight:700;font-variant-numeric:tabular-nums;}
    .td-amt.wa{color:var(--wa);}
    .td-amt.em{color:var(--em);}
    .td-amt.call{color:var(--call);}
    .td-cnt{color:var(--text2);font-size:13px;}

    /* Mini bar */
    .mini-bar-wrap{display:flex;align-items:center;gap:8px;min-width:90px;}
    .mini-bar{flex:1;height:5px;background:var(--bg);border-radius:999px;overflow:hidden;border:1px solid var(--border);}
    .mini-fill{height:100%;border-radius:999px;}
    .mini-fill.wa{background:var(--wa);}
    .mini-fill.em{background:var(--em);}
    .mini-fill.call{background:var(--call);}
    .mini-fill.exceeded{background:var(--danger)!important;}
    .mini-pct{font-size:11px;font-weight:600;color:var(--text2);min-width:32px;text-align:right;}
    .mini-pct.exceeded{color:var(--danger);}

    /* Status pill */
    .pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:999px;}
    .pill-active{background:var(--wa-soft);color:var(--wa);border:1px solid rgba(37,211,102,.2);}
    .pill-inactive{background:var(--bg);color:var(--text3);border:1px solid var(--border);}
    .pdot{width:6px;height:6px;border-radius:50%;}
    .pdot-active{background:var(--wa);}
    .pdot-inactive{background:var(--border2);}

    /* ── MODAL ── */
    .modal{display:none;position:fixed;inset:0;background:rgba(26,25,22,.45);backdrop-filter:blur(10px);z-index:9999;align-items:center;justify-content:center;padding:24px;}
    .modal.on{display:flex;}
    .modal-box{background:var(--card);border:1px solid var(--border);border-radius:var(--r);width:100%;max-width:760px;max-height:88vh;overflow-y:auto;box-shadow:var(--shadow-xl);position:relative;animation:fadeUp .22s ease;}
    .modal-head{padding:26px 28px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:16px;position:sticky;top:0;background:var(--card);z-index:1;}
    .modal-ttl{font-family:'DM Serif Display',serif;font-size:20px;color:var(--text);}
    .modal-meta{font-size:13px;color:var(--text2);margin-top:4px;}
    .modal-close{width:34px;height:34px;border-radius:var(--r-xs);border:1px solid var(--border);background:var(--bg);color:var(--text2);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;line-height:1;flex-shrink:0;}
    .modal-close:hover{background:var(--accent-soft);border-color:rgba(124,58,237,.2);color:var(--accent);}
    .modal-body{padding:24px 28px 28px;}
    .modal-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px;}
    .mkpi{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;}
    .mkpi-lbl{font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);margin-bottom:4px;}
    .mkpi-val{font-family:'DM Serif Display',serif;font-size:18px;color:var(--text);}
    .mkpi-val.exceeded{color:var(--danger);}
    .mkpi.exceeded-tile{background:var(--danger-soft);border-color:var(--danger-mid);}
    .mo-section{margin-top:20px;}
    .mo-ttl{font-family:'DM Serif Display',serif;font-size:15px;color:var(--text);margin-bottom:10px;}
    .mo-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
    .mo-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;}
    .mo-name{font-size:13px;font-weight:600;color:var(--text);margin-bottom:6px;}
    .mo-cats{display:flex;gap:8px;flex-wrap:wrap;}
    .mo-cat{font-size:12px;font-weight:600;padding:3px 10px;border-radius:999px;}
    .mo-cat.wa{background:var(--wa-soft);color:var(--wa);}
    .mo-cat.em{background:var(--em-soft);color:var(--em);}
    .mo-empty{text-align:center;padding:24px;color:var(--text3);font-size:14px;}

    @keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
    ::-webkit-scrollbar{width:5px;height:5px;}
    ::-webkit-scrollbar-track{background:transparent;}
    ::-webkit-scrollbar-thumb{background:var(--border2);border-radius:999px;}

    /* ── DATE RANGE PICKER ── */
    .range-wrap{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
    .range-field{display:flex;flex-direction:column;gap:6px;}
    .range-lbl{font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);}
    .range-input{width:100%;padding:11px 14px;font-size:14px;font-family:'DM Sans',sans-serif;font-weight:500;color:var(--text);background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);outline:none;transition:.2s;box-shadow:var(--shadow-sm);cursor:pointer;}
    .range-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);}
    .range-actions{display:flex;gap:8px;margin-top:10px;}
    .range-btn{padding:10px 20px;font-size:14px;font-family:'DM Sans',sans-serif;font-weight:600;color:#fff;background:var(--accent);border:none;border-radius:var(--r-sm);cursor:pointer;transition:opacity .15s;}
    .range-btn:hover{opacity:.88;}
    .range-btn.clear{background:var(--surface);color:var(--text2);border:1px solid var(--border);}
    .range-hint{font-size:12px;color:var(--text3);margin-top:8px;min-height:18px;}
    @media(max-width:520px){.range-wrap{grid-template-columns:1fr;}}

    /* ── MONTHLY TILES (individual mode) ── */
    .mo-tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
    .mo-tile{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:18px 20px;cursor:pointer;transition:background .15s,box-shadow .15s,transform .15s;display:flex;justify-content:space-between;align-items:center;gap:12px;}
    .mo-tile:hover{background:var(--card);box-shadow:var(--shadow-md);transform:translateY(-2px);}
    .dot{width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0;}
    .dot.wa{background:var(--wa);}
    .dot.em{background:var(--em);}
    .dot.call{background:var(--call);}
    .mo-tile-cat{font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
    .mo-tile-cat.wa{color:var(--wa);}
    .mo-tile-cat.em{color:var(--em);}
    .mo-tile-cat.call{color:var(--call);}
    .mo-tile-amt{font-family:'DM Serif Display',serif;font-size:22px;color:var(--text);}
    .mo-tile-cnt{font-size:12px;color:var(--text2);margin-top:3px;}
    .mo-tile-arrow{width:32px;height:32px;border-radius:var(--r-xs);display:flex;align-items:center;justify-content:center;border:1px solid var(--border);color:var(--text3);flex-shrink:0;transition:background .15s,color .15s,border-color .15s;}
    .mo-tile:hover .mo-tile-arrow{background:var(--accent-soft);border-color:rgba(124,58,237,.2);color:var(--accent);}
    .mo-tile-arrow svg{width:14px;height:14px;}
    /* modal cat label */
    .modal-cat{font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
    .modal-cat.wa{color:var(--wa);}
    .modal-cat.em{color:var(--em);}
    .modal-cat.call{color:var(--call);}

    @media(max-width:900px){
        .usage-grid{grid-template-columns:1fr 1fr;}
    }
    @media(max-width:700px){
        .usage-grid{grid-template-columns:1fr;}
        .metrics-pane.active{grid-template-columns:1fr 1fr;}
        .modal-kpis{grid-template-columns:1fr 1fr;}
        .mo-grid{grid-template-columns:1fr;}
        .mo-tiles{grid-template-columns:1fr;}
        .sel-wrap{min-width:0;width:100%;}
        .topbar{flex-direction:column;align-items:flex-start;}
    }

    /* ── TOPUP FORM ── */
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;}
    .form-field{display:flex;flex-direction:column;gap:6px;}
    .form-field.full{grid-column:1/-1;}
    .form-lbl{font-size:11px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);}
    .form-lbl .req{color:var(--danger);}
    .form-actions{display:flex;gap:8px;justify-content:flex-end;padding-top:4px;}
    .form-msg{display:none;font-size:13px;border-radius:var(--r-xs);padding:10px 14px;margin-bottom:14px;}
    .form-msg.err{background:var(--danger-soft);border:1px solid var(--danger-mid);color:var(--danger);}
    .form-msg.ok{background:var(--wa-soft);border:1px solid rgba(37,211,102,.2);color:#1a7a45;}
    .form-msg.show{display:block;}
    @media(max-width:520px){.form-grid{grid-template-columns:1fr;}}
    /* ── CSV DROP ZONE ── */
    .drop-zone{border:2px dashed var(--border2);border-radius:var(--r-sm);padding:36px 24px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:var(--surface);}
    .drop-zone:hover,.drop-zone.drag-over{border-color:var(--accent);background:var(--accent-soft);}
    .drop-zone input[type=file]{display:none;}
    .drop-icon{width:36px;height:36px;margin:0 auto 10px;color:var(--text3);}
    .drop-hint{font-size:13px;color:var(--text2);margin-bottom:4px;}
    .drop-hint strong{color:var(--accent);}
    .drop-sub{font-size:12px;color:var(--text3);}
    .drop-filename{font-size:13px;font-weight:600;color:var(--text);margin-top:10px;display:none;}
    </style>
</head>
<body>
<div class="wrap">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 36 36" fill="none"><path d="M18 6C11.373 6 6 11.373 6 18c0 2.215.605 4.29 1.658 6.071L6 30l6.144-1.614A11.93 11.93 0 0018 30c6.627 0 12-5.373 12-12S24.627 6 18 6zm-4 9h8m-8 4h5" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
            </div>
            <div>
                <div class="brand-name">Credits Dashboard</div>
                <div class="brand-sub">WhatsApp &amp; Email Spend Tracker</div>
            </div>
        </div>
        <div class="sel-wrap">
            <select class="sub-sel" id="subSel">
                <option value="">All Subaccounts</option>
                <?php foreach ($ghlLocations as $loc): ?>
                <option value="<?php echo htmlspecialchars($loc['id']); ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <svg class="arr" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <button class="range-btn clear" id="openImportBtn" style="white-space:nowrap;flex-shrink:0;">&#8679; Import CSV</button>
        <button class="range-btn" id="openTopupBtn" style="white-space:nowrap;flex-shrink:0;">+ Top Up</button>
    </div>

    <!-- USAGE CARDS -->
    <div class="usage-grid" id="usageGrid">
        <?php
        $cards = [
            'wa' => [
                'cls'   => 'wa',
                'label' => 'WhatsApp',
                'pct'   => $initialData['whatsappPercent'],
                'credit'=> $initialData['whatsappCredit'],
                'usedC' => $initialData['whatsappUsedCredit'],
                'remC'  => $initialData['whatsappRemainingCredit'],
                'total' => $initialData['whatsappAmount'],
                'usedA' => $initialData['whatsappUsedAmount'],
                'remA'  => $initialData['whatsappRemainingAmount'],
                'rate'  => 'RM 0.50 / msg',
                'icon'  => '<path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6A8.38 8.38 0 0112 10h.5a8.48 8.48 0 018 8v.5z"/>',
            ],
            'em' => [
                'cls'   => 'em',
                'label' => 'Email',
                'pct'   => $initialData['emailPercent'],
                'credit'=> $initialData['emailCredit'],
                'usedC' => $initialData['emailUsedCredit'],
                'remC'  => $initialData['emailRemainingCredit'],
                'total' => $initialData['emailAmount'],
                'usedA' => $initialData['emailUsedAmount'],
                'remA'  => $initialData['emailRemainingAmount'],
                'rate'  => 'RM 0.005 / email',
                'icon'  => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
            ],
            'call' => [
                'cls'   => 'call',
                'label' => 'Call',
                'pct'   => $initialData['callPercent'],
                'credit'=> $initialData['callCredit'],
                'usedC' => $initialData['callUsedCredit'],
                'remC'  => $initialData['callRemainingCredit'],
                'total' => $initialData['callAmount'],
                'usedA' => $initialData['callUsedAmount'],
                'remA'  => $initialData['callRemainingAmount'],
                'rate'  => 'RM 0.054 / min',
                'icon'  => '<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 8.63 19.79 19.79 0 010 2H3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L4.09 9.9a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>',
            ],
        ];
        foreach ($cards as $c):
            $exc = $c['pct'] >= 100;
            $midC = number_format(round($c['credit'] / 2));
            $maxC = number_format($c['credit']);
            $midA = 'RM ' . number_format($c['total'] / 2, 2);
            $maxA = 'RM ' . number_format($c['total'], 2);
        ?>
        <div class="ucard <?php echo $c['cls']; ?>" id="ucard-<?php echo $c['cls']; ?>">
            <div class="ucard-inner">
                <div class="ucard-head">
                    <div class="ucard-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--<?php echo $c['cls']; ?>)" stroke-width="2"><?php echo $c['icon']; ?></svg>
                    </div>
                    <div class="ucard-ttl"><?php echo $c['label']; ?></div>
                    <div class="ucard-pct <?php echo $exc ? 'exceeded' : ''; ?>" id="pct-<?php echo $c['cls']; ?>"><?php echo $c['pct']; ?>% used</div>
                </div>
                <div class="prog-wrap">
                    <div class="prog-labels-top">
                        <span>Usage Progress</span>
                        <span id="pctlbl-<?php echo $c['cls']; ?>"><?php echo $c['pct']; ?>%</span>
                    </div>
                    <div class="prog-track">
                        <div class="prog-fill <?php echo $c['cls']; ?> <?php echo $exc ? 'exceeded' : ''; ?>" id="fill-<?php echo $c['cls']; ?>" style="width:<?php echo $c['pct']; ?>%"></div>
                    </div>
                    <div class="prog-midmarks">
                        <span>0</span>
                        <span id="mid-<?php echo $c['cls']; ?>"><?php echo $midC; ?></span>
                        <span id="max-<?php echo $c['cls']; ?>"><?php echo $maxC; ?></span>
                    </div>
                </div>
                <div class="utabs">
                    <button class="utab active" data-tab="credits" data-card="<?php echo $c['cls']; ?>">Credits</button>
                    <button class="utab" data-tab="amount" data-card="<?php echo $c['cls']; ?>">Amount (RM)</button>
                </div>
                <!-- Credits pane -->
                <div class="metrics-pane active" id="pane-<?php echo $c['cls']; ?>-credits">
                    <div class="metric">
                        <div class="metric-lbl">Total</div>
                        <div class="metric-val" id="<?php echo $c['cls']; ?>-cTotal"><?php echo number_format($c['credit']); ?></div>
                        <div class="metric-sub">allocated</div>
                    </div>
                    <div class="metric">
                        <div class="metric-lbl">Used</div>
                        <div class="metric-val" id="<?php echo $c['cls']; ?>-cUsed"><?php echo number_format($c['usedC']); ?></div>
                        <div class="metric-sub">consumed</div>
                    </div>
                    <div class="metric <?php echo $c['remC'] < 0 ? 'exceeded-tile' : ''; ?>" id="tile-<?php echo $c['cls']; ?>-cRem">
                        <div class="metric-lbl">Remaining</div>
                        <div class="metric-val <?php echo $c['remC'] < 0 ? 'exceeded' : ''; ?>" id="<?php echo $c['cls']; ?>-cRem">
                            <?php echo ($c['remC'] < 0 ? '−' : '') . number_format(abs($c['remC'])); ?>
                        </div>
                        <div class="metric-sub" id="sub-<?php echo $c['cls']; ?>-cRem"><?php echo $c['remC'] < 0 ? 'over limit' : 'credits left'; ?></div>
                    </div>
                </div>
                <!-- Amount pane -->
                <div class="metrics-pane" id="pane-<?php echo $c['cls']; ?>-amount">
                    <div class="metric">
                        <div class="metric-lbl">Total</div>
                        <div class="metric-val" id="<?php echo $c['cls']; ?>-aTotal">RM <?php echo number_format($c['total'], 2); ?></div>
                        <div class="metric-sub">budget</div>
                    </div>
                    <div class="metric">
                        <div class="metric-lbl">Used</div>
                        <div class="metric-val" id="<?php echo $c['cls']; ?>-aUsed">RM <?php echo number_format($c['usedA'], 2); ?></div>
                        <div class="metric-sub"><?php echo $c['rate']; ?></div>
                    </div>
                    <div class="metric <?php echo $c['remA'] < 0 ? 'exceeded-tile' : ''; ?>" id="tile-<?php echo $c['cls']; ?>-aRem">
                        <div class="metric-lbl">Remaining</div>
                        <div class="metric-val <?php echo $c['remA'] < 0 ? 'exceeded' : ''; ?>" id="<?php echo $c['cls']; ?>-aRem">
                            <?php echo ($c['remA'] < 0 ? '−' : '') . 'RM ' . number_format(abs($c['remA']), 2); ?>
                        </div>
                        <div class="metric-sub" id="sub-<?php echo $c['cls']; ?>-aRem"><?php echo $c['remA'] < 0 ? 'over budget' : 'remaining'; ?></div>
                    </div>
                </div>
                <!-- midmark data -->
                <span class="midmark-data" style="display:none"
                    data-card="<?php echo $c['cls']; ?>"
                    data-mid-c="<?php echo $midC; ?>"
                    data-max-c="<?php echo $maxC; ?>"
                    data-mid-a="<?php echo $midA; ?>"
                    data-max-a="<?php echo $maxA; ?>"></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── ALL: Subaccounts table panel ── -->
    <div class="panel open" id="subPanel">
        <div class="panel-hdr" id="subToggle">
            <div class="panel-hdr-l">
                <span class="panel-ttl">Subaccounts</span>
                <span class="chip"><?php echo count($processedData); ?> accounts</span>
            </div>
            <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="panel-body">
            <div class="tbl-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Subaccount</th>
                            <th class="r">WA Spent</th>
                            <th>WA Usage</th>
                            <th class="r">Email Spent</th>
                            <th>Email Usage</th>
                            <th class="r">Call Spent</th>
                            <th>Call Usage</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subRows as $i => $r):
                            $active   = ($r['waUsed'] + $r['emUsed'] + $r['callUsed']) > 0;
                            $waExc    = $r['waPct']   >= 100;
                            $emExc    = $r['emPct']   >= 100;
                            $callExc  = $r['callPct'] >= 100;
                        ?>
                        <tr style="cursor:default">
                            <td style="color:var(--text3);font-size:13px"><?php echo $i + 1; ?></td>
                            <td>
                                <div class="td-name"><?php echo htmlspecialchars($r['name']); ?></div>
                                <div class="td-id"><?php echo htmlspecialchars($r['id']); ?></div>
                            </td>
                            <td class="td-amt wa r">RM <?php echo number_format($r['waUsed'], 2); ?></td>
                            <td>
                                <div class="mini-bar-wrap">
                                    <div class="mini-bar"><div class="mini-fill wa <?php echo $waExc ? 'exceeded' : ''; ?>" style="width:<?php echo $r['waPct']; ?>%"></div></div>
                                    <span class="mini-pct <?php echo $waExc ? 'exceeded' : ''; ?>"><?php echo $r['waPct']; ?>%</span>
                                </div>
                            </td>
                            <td class="td-amt em r">RM <?php echo number_format($r['emUsed'], 2); ?></td>
                            <td>
                                <div class="mini-bar-wrap">
                                    <div class="mini-bar"><div class="mini-fill em <?php echo $emExc ? 'exceeded' : ''; ?>" style="width:<?php echo $r['emPct']; ?>%"></div></div>
                                    <span class="mini-pct <?php echo $emExc ? 'exceeded' : ''; ?>"><?php echo $r['emPct']; ?>%</span>
                                </div>
                            </td>
                            <td class="td-amt call r">RM <?php echo number_format($r['callUsed'], 2); ?></td>
                            <td>
                                <div class="mini-bar-wrap">
                                    <div class="mini-bar"><div class="mini-fill call <?php echo $callExc ? 'exceeded' : ''; ?>" style="width:<?php echo $r['callPct']; ?>%"></div></div>
                                    <span class="mini-pct <?php echo $callExc ? 'exceeded' : ''; ?>"><?php echo $r['callPct']; ?>%</span>
                                </div>
                            </td>
                            <td>
                                <span class="pill <?php echo $active ? 'pill-active' : 'pill-inactive'; ?>">
                                    <span class="pdot <?php echo $active ? 'pdot-active' : 'pdot-inactive'; ?>"></span>
                                    <?php echo $active ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── INDIVIDUAL: Monthly transactions panel ── -->
    <div class="panel open" id="monthPanel" style="display:none">
        <div class="panel-hdr" id="monthToggle">
            <div class="panel-hdr-l">
                <span class="panel-ttl">Monthly Transactions</span>
            </div>
            <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        </div>
        <div class="panel-body">
            <div style="padding:20px 24px">
                <div class="range-wrap">
                    <div class="range-field">
                        <label class="range-lbl" for="dateFrom">From</label>
                        <input class="range-input" type="date" id="dateFrom">
                    </div>
                    <div class="range-field">
                        <label class="range-lbl" for="dateTo">To</label>
                        <input class="range-input" type="date" id="dateTo">
                    </div>
                </div>
                <div class="range-actions">
                    <button class="range-btn" id="applyRange">Apply Range</button>
                    <button class="range-btn clear" id="clearRange">Clear</button>
                </div>
                <div class="range-hint" id="rangeHint"></div>
                <div class="mo-tiles" id="moTiles" style="display:none;margin-top:16px;"></div>
            </div>
        </div>
    </div>


</div><!-- /wrap -->

<!-- TRANSACTION MODAL (individual month category) -->
<div id="txModal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <div class="modal-cat" id="txModalCat"></div>
                <div class="modal-ttl" id="txModalTtl"></div>
                <div class="modal-meta" id="txModalMeta"></div>
            </div>
            <button class="modal-close" id="txModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="modal-summary" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;">
                <div class="ms-tile" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;">
                    <div class="ms-lbl" style="font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);margin-bottom:4px;">Total Spent</div>
                    <div class="ms-val" id="txTotalAmt" style="font-family:'DM Serif Display',serif;font-size:20px;color:var(--text);"></div>
                </div>
                <div class="ms-tile" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;">
                    <div class="ms-lbl" style="font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);margin-bottom:4px;">Transactions</div>
                    <div class="ms-val" id="txTotalCnt" style="font-family:'DM Serif Display',serif;font-size:20px;color:var(--text);"></div>
                </div>
            </div>
            <div class="tbl-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Type</th>
                            <th class="r">Amount (RM)</th>
                        </tr>
                    </thead>
                    <tbody id="txTbody"></tbody>
                </table>
            </div>
            <p style="margin-top:14px;font-size:12px;color:var(--text3);text-align:center;display:none;"></p>
        </div>
    </div>
</div>

<!-- TOPUP MODAL -->
<div id="topupModal" class="modal">
    <div class="modal-box" style="max-width:520px">
        <div class="modal-head">
            <div>
                <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--accent);margin-bottom:6px;">Credits</div>
                <div class="modal-ttl">Add Top Up</div>
                <div class="modal-meta">Record a credit top-up for a subaccount</div>
            </div>
            <button class="modal-close" id="topupModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-msg err" id="topupErr"></div>
            <div class="form-msg ok" id="topupOk">Top-up recorded successfully.</div>
            <form id="topupForm" autocomplete="off">
                <div class="form-grid">
                    <div class="form-field full">
                        <label class="form-lbl" for="tu_loc">Location <span class="req">*</span></label>
                        <select class="range-input" id="tu_loc" name="location_id" required style="cursor:pointer;">
                            <option value="">Select a subaccount…</option>
                            <?php foreach ($ghlLocations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['id']); ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-field">
                        <label class="form-lbl" for="tu_date">Date <span class="req">*</span></label>
                        <input class="range-input" type="date" id="tu_date" name="topup_date" required>
                    </div>
                    <div class="form-field">
                        <label class="form-lbl" for="tu_by">Added By <span class="req">*</span></label>
                        <input class="range-input" type="text" id="tu_by" name="added_by" placeholder="e.g. KY" maxlength="100" required>
                    </div>
                    <div class="form-field">
                        <label class="form-lbl" for="tu_wa">WhatsApp Credits</label>
                        <input class="range-input" type="number" id="tu_wa" name="wa_credits" value="0" min="0" step="1">
                    </div>
                    <div class="form-field">
                        <label class="form-lbl" for="tu_em">Email Credits</label>
                        <input class="range-input" type="number" id="tu_em" name="email_credits" value="0" min="0" step="1">
                    </div>
                    <div class="form-field">
                        <label class="form-lbl" for="tu_call">Call Credits</label>
                        <input class="range-input" type="number" id="tu_call" name="call_credits" value="0" min="0" step="1">
                    </div>
                    <div class="form-field full">
                        <label class="form-lbl" for="tu_notes">Notes</label>
                        <input class="range-input" type="text" id="tu_notes" name="notes" placeholder="Optional note…" maxlength="255">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="button" class="range-btn clear" id="topupCancelBtn">Cancel</button>
                    <button type="submit" class="range-btn" id="topupSubmitBtn">Save Top Up</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- IMPORT CSV MODAL -->
<div id="importModal" class="modal">
    <div class="modal-box" style="max-width:480px">
        <div class="modal-head">
            <div>
                <div style="font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--accent);margin-bottom:6px;">Transactions</div>
                <div class="modal-ttl">Import from CSV</div>
                <div class="modal-meta">Upload a GHL billing export to write to the database</div>
            </div>
            <button class="modal-close" id="importModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-msg err" id="importErr"></div>
            <div class="form-msg ok" id="importOk"></div>
            <form id="importForm" enctype="multipart/form-data">
                <div class="drop-zone" id="dropZone">
                    <input type="file" id="importFile" name="csv_file" accept=".csv">
                    <svg class="drop-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M17 8l-5-5-5 5M12 3v12"/>
                    </svg>
                    <div class="drop-hint"><strong>Click to browse</strong> or drag &amp; drop</div>
                    <div class="drop-sub">CSV files only &middot; locationId and type columns required</div>
                    <div class="drop-filename" id="dropFilename"></div>
                </div>
                <div class="form-actions" style="margin-top:20px;">
                    <button type="button" class="range-btn clear" id="importCancelBtn">Cancel</button>
                    <button type="submit" class="range-btn" id="importSubmitBtn" disabled style="opacity:.5;">Import</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const subaccountData = <?php echo $subaccountDataJson; ?>;

// ── Midmarks init ──
document.querySelectorAll('.midmark-data').forEach(el => {
    const c = el.dataset.card;
    document.getElementById('mid-' + c).textContent = el.dataset.midC;
    document.getElementById('max-' + c).textContent = el.dataset.maxC;
});

// ── Tabs ──
document.querySelectorAll('.utab').forEach(btn => {
    btn.addEventListener('click', () => {
        const card = btn.dataset.card;
        const tab  = btn.dataset.tab;
        document.querySelectorAll(`.utab[data-card="${card}"]`).forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        document.querySelectorAll(`[id^="pane-${card}-"]`).forEach(p => p.classList.remove('active'));
        document.getElementById(`pane-${card}-${tab}`).classList.add('active');
        const el = document.querySelector(`.midmark-data[data-card="${card}"]`);
        document.getElementById('mid-' + card).textContent = tab === 'credits' ? el.dataset.midC : el.dataset.midA;
        document.getElementById('max-' + card).textContent = tab === 'credits' ? el.dataset.maxC : el.dataset.maxA;
    });
});

// ── Panel toggles ──
document.getElementById('subToggle').addEventListener('click', () => {
    document.getElementById('subPanel').classList.toggle('open');
});
document.getElementById('monthToggle').addEventListener('click', () => {
    document.getElementById('monthPanel').classList.toggle('open');
});

// ── Formatters ──
function fmt0(n) { return Math.abs(n).toLocaleString('en-US', {maximumFractionDigits:0}); }
function fmt2(n) { return Math.abs(n).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2}); }
function signedC(n)  { return (n < 0 ? '−' : '') + fmt0(n); }
function signedRM(n) { return (n < 0 ? '−' : '') + 'RM ' + fmt2(n); }
function formatDate(d) { return d.toLocaleDateString('en-MY', {day:'numeric', month:'short', year:'numeric'}); }

// ── Apply card metrics + progress ──
function applyCard(cls, d) {
    const isWa   = cls === 'wa';
    const isCall = cls === 'call';
    const pct  = isWa ? d.whatsappPercent         : isCall ? d.callPercent         : d.emailPercent;
    const remC = isWa ? d.whatsappRemainingCredit : isCall ? d.callRemainingCredit : d.emailRemainingCredit;
    const remA = isWa ? d.whatsappRemainingAmount : isCall ? d.callRemainingAmount : d.emailRemainingAmount;
    const exc  = pct >= 100;
    const totC = isWa ? d.whatsappCredit          : isCall ? d.callCredit          : d.emailCredit;
    const usedC= isWa ? d.whatsappUsedCredit      : isCall ? d.callUsedCredit      : d.emailUsedCredit;
    const totA = isWa ? d.whatsappAmount          : isCall ? d.callAmount          : d.emailAmount;
    const usedA= isWa ? d.whatsappUsedAmount      : isCall ? d.callUsedAmount      : d.emailUsedAmount;

    const pctEl = document.getElementById('pct-' + cls);
    pctEl.textContent = pct + '% used';
    pctEl.classList.toggle('exceeded', exc);
    document.getElementById('pctlbl-' + cls).textContent = pct + '%';

    const fill = document.getElementById('fill-' + cls);
    fill.style.width = pct + '%';
    fill.classList.toggle('exceeded', exc);

    const el   = document.querySelector(`.midmark-data[data-card="${cls}"]`);
    const midC = fmt0(totC / 2), maxC = fmt0(totC);
    const midA = 'RM ' + fmt2(totA / 2), maxA = 'RM ' + fmt2(totA);
    el.dataset.midC = midC; el.dataset.maxC = maxC;
    el.dataset.midA = midA; el.dataset.maxA = maxA;
    const activeTab = document.querySelector(`.utab.active[data-card="${cls}"]`)?.dataset.tab || 'credits';
    document.getElementById('mid-' + cls).textContent = activeTab === 'credits' ? midC : midA;
    document.getElementById('max-' + cls).textContent = activeTab === 'credits' ? maxC : maxA;

    document.getElementById(cls + '-cTotal').textContent = fmt0(totC);
    document.getElementById(cls + '-cUsed').textContent  = fmt0(usedC);
    document.getElementById(cls + '-cRem').textContent   = signedC(remC);
    document.getElementById(cls + '-cRem').className     = 'metric-val' + (remC < 0 ? ' exceeded' : '');
    document.getElementById('tile-' + cls + '-cRem').className = 'metric' + (remC < 0 ? ' exceeded-tile' : '');
    document.getElementById('sub-' + cls + '-cRem').textContent = remC < 0 ? 'over limit' : 'credits left';

    document.getElementById(cls + '-aTotal').textContent = 'RM ' + fmt2(totA);
    document.getElementById(cls + '-aUsed').textContent  = 'RM ' + fmt2(usedA);
    document.getElementById(cls + '-aRem').textContent   = signedRM(remA);
    document.getElementById(cls + '-aRem').className     = 'metric-val' + (remA < 0 ? ' exceeded' : '');
    document.getElementById('tile-' + cls + '-aRem').className = 'metric' + (remA < 0 ? ' exceeded-tile' : '');
    document.getElementById('sub-' + cls + '-aRem').textContent = remA < 0 ? 'over budget' : 'remaining';
}

// ── Date range state ──
let currentLocId = '';

function setDateBounds(locId) {
    const mData = (subaccountData[locId] || {}).monthlyData || {};
    const months = Object.keys(mData).sort();
    const dateFrom = document.getElementById('dateFrom');
    const dateTo   = document.getElementById('dateTo');
    if (!months.length) return;
    dateFrom.min = months[0] + '-01';
    dateTo.min   = months[0] + '-01';
    const last = new Date(months[months.length - 1] + '-01');
    last.setMonth(last.getMonth() + 1);
    last.setDate(0);
    const pad = n => String(n).padStart(2,'0');
    const maxDate = last.getFullYear() + '-' + pad(last.getMonth()+1) + '-' + pad(last.getDate());
    dateFrom.max = maxDate;
    dateTo.max   = maxDate;
}

function resetDateRange() {
    document.getElementById('dateFrom').value  = '';
    document.getElementById('dateTo').value    = '';
    document.getElementById('rangeHint').textContent = '';
    const tiles = document.getElementById('moTiles');
    tiles.innerHTML = '';
    tiles.style.display = 'none';
    window._currentAggregated = null;
}

function filterAndRender() {
    const fromVal = document.getElementById('dateFrom').value;
    const toVal   = document.getElementById('dateTo').value;
    const hint    = document.getElementById('rangeHint');
    const tiles   = document.getElementById('moTiles');

    const from = fromVal ? new Date(fromVal) : null;
    const to   = toVal   ? new Date(toVal)   : null;

    if (from && to && from > to) {
        hint.textContent = '⚠ "From" date must be before "To" date.';
        tiles.style.display = 'none';
        return;
    }

    const mData = (subaccountData[currentLocId] || {}).monthlyData || {};
    const aggregated = {};

    Object.entries(mData).forEach(([mKey, mData]) => {
        const types = mData.types || {};
        Object.entries(types).forEach(([cat, catData]) => {
            (catData.transactions || []).forEach(tx => {
                const clean = tx.date.replace(/(st|nd|rd|th)/i,'').replace(/,/g,'');
                const ts    = new Date(clean);
                if (isNaN(ts)) return;
                if (from) { const d = new Date(ts); d.setHours(0,0,0,0); if (d < from) return; }
                if (to)   { const d = new Date(ts); d.setHours(0,0,0,0); if (d > to)   return; }
                if (!aggregated[cat]) aggregated[cat] = {totalAmount:0, count:0, transactions:[]};
                aggregated[cat].totalAmount += tx.amount;
                aggregated[cat].count++;
                aggregated[cat].transactions.push(tx);
            });
        });
    });

    let label = '';
    if (from && to)   label = formatDate(from) + ' – ' + formatDate(to);
    else if (from)    label = 'From ' + formatDate(from);
    else if (to)      label = 'Up to ' + formatDate(to);
    else              label = 'All available transactions';

    hint.textContent = label;

    let html = '';
    [['whatsapp','wa','WhatsApp'], ['email','em','Email'], ['call','call','Call']].forEach(([key, cls, lbl]) => {
        const d = aggregated[key];
        if (!d || d.count === 0) return;
        html += `
        <div class="mo-tile" onclick="openTxModal('${key}','${label}')">
            <div>
                <div class="mo-tile-cat ${cls}"><span class="dot ${cls}"></span>${lbl}</div>
                <div class="mo-tile-amt">RM ${fmt2(d.totalAmount)}</div>
                <div class="mo-tile-cnt">${d.count} transaction${d.count !== 1 ? 's' : ''}</div>
            </div>
            <div class="mo-tile-arrow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
        </div>`;
    });

    if (!html) html = '<p style="grid-column:1/-1;text-align:center;padding:20px 0;color:var(--text3);font-size:14px;">No transactions found in this date range.</p>';
    tiles.innerHTML = html;
    tiles.style.display = 'grid';
    window._currentAggregated = aggregated;
}

document.getElementById('applyRange').addEventListener('click', filterAndRender);
document.getElementById('clearRange').addEventListener('click', resetDateRange);
['dateFrom','dateTo'].forEach(id => {
    document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') filterAndRender(); });
});

const zeroData = {
    whatsappPercent:0, emailPercent:0, callPercent:0,
    whatsappCredit:0, emailCredit:0, callCredit:0,
    whatsappUsedCredit:0, emailUsedCredit:0, callUsedCredit:0,
    whatsappRemainingCredit:0, emailRemainingCredit:0, callRemainingCredit:0,
    whatsappAmount:0, emailAmount:0, callAmount:0,
    whatsappUsedAmount:0, emailUsedAmount:0, callUsedAmount:0,
    whatsappRemainingAmount:0, emailRemainingAmount:0, callRemainingAmount:0,
    monthlyData:{}
};

// ── Subaccount selector ──
document.getElementById('subSel').addEventListener('change', function () {
    const key = this.value;
    const d   = subaccountData[key] || (key === '' ? subaccountData[''] : zeroData);

    applyCard('wa', d);
    applyCard('em', d);
    applyCard('call', d);

    const isAll = key === '';
    document.getElementById('subPanel').style.display   = isAll ? '' : 'none';
    document.getElementById('monthPanel').style.display = isAll ? 'none' : '';

    if (!isAll) {
        currentLocId = key;
        resetDateRange();
        setDateBounds(key);
        document.getElementById('monthPanel').classList.add('open');
    }
});

// ── Transaction modal ──
function openTxModal(cat, rangeLabel) {
    const agg = window._currentAggregated || {};
    const t   = agg[cat];
    if (!t) return;

    const cls = cat === 'whatsapp' ? 'wa' : cat === 'call' ? 'call' : 'em';
    const lbl = cat === 'whatsapp' ? 'WhatsApp' : cat === 'call' ? 'Call' : 'Email';

    document.getElementById('txModalCat').className   = 'modal-cat ' + cls;
    document.getElementById('txModalCat').innerHTML   = `<span class="dot ${cls}"></span>${lbl}`;
    document.getElementById('txModalTtl').textContent = rangeLabel;
    document.getElementById('txModalMeta').textContent = t.count + ' transaction' + (t.count !== 1 ? 's' : '');
    document.getElementById('txTotalAmt').textContent  = 'RM ' + fmt2(t.totalAmount);
    document.getElementById('txTotalCnt').textContent  = t.count.toLocaleString();

    const tbody = document.getElementById('txTbody');
    if (t.transactions && t.transactions.length) {
        tbody.innerHTML = [...t.transactions].sort((a, b) => {
            const pa = new Date(a.date.replace(/(st|nd|rd|th)/i,'').replace(/,/g,''));
            const pb = new Date(b.date.replace(/(st|nd|rd|th)/i,'').replace(/,/g,''));
            return pb - pa;
        }).map(tx => {
            const clean = tx.date.replace(/(st|nd|rd|th)/i,'').replace(/,/g,'');
            const ts    = new Date(clean);
            const fd    = isNaN(ts) ? tx.date : ts.toLocaleString('en-US', {
                month:'short', day:'2-digit', year:'numeric',
                hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:true
            });
            return `<tr>
                <td class="td-date">${fd}</td>
                <td class="td-type">${tx.originalType || lbl}</td>
                <td class="td-amt ${cls} r">RM ${tx.amount.toFixed(3)}</td>
            </tr>`;
        }).join('');
    } else {
        tbody.innerHTML = `<tr><td colspan="3" style="text-align:center;padding:24px;color:var(--text3);font-size:13px;">No transactions found.</td></tr>`;
    }

    document.getElementById('txModal').classList.add('on');
    document.body.style.overflow = 'hidden';
}

function closeTxModal() {
    document.getElementById('txModal').classList.remove('on');
    document.body.style.overflow = '';
}
document.getElementById('txModalClose').addEventListener('click', closeTxModal);
document.getElementById('txModal').addEventListener('click', e => { if (e.target === document.getElementById('txModal')) closeTxModal(); });

// ── Topup modal ──
const topupModal = document.getElementById('topupModal');

function openTopupModal() {
    document.getElementById('topupErr').classList.remove('show');
    document.getElementById('topupOk').classList.remove('show');
    document.getElementById('topupForm').reset();
    document.getElementById('tu_date').value = new Date().toISOString().slice(0, 10);
    topupModal.classList.add('on');
    document.body.style.overflow = 'hidden';
}
function closeTopupModal() {
    topupModal.classList.remove('on');
    document.body.style.overflow = '';
}

document.getElementById('openTopupBtn').addEventListener('click', openTopupModal);
document.getElementById('topupModalClose').addEventListener('click', closeTopupModal);
document.getElementById('topupCancelBtn').addEventListener('click', closeTopupModal);
topupModal.addEventListener('click', e => { if (e.target === topupModal) closeTopupModal(); });

document.getElementById('topupForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn   = document.getElementById('topupSubmitBtn');
    const errEl = document.getElementById('topupErr');
    const okEl  = document.getElementById('topupOk');
    errEl.classList.remove('show');
    okEl.classList.remove('show');
    btn.disabled = true;
    btn.textContent = 'Saving…';
    try {
        const fd = new FormData(this);
        fd.append('action', 'add_topup');
        const res  = await fetch(location.href, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            okEl.classList.add('show');
            setTimeout(() => { closeTopupModal(); location.reload(); }, 1000);
        } else {
            errEl.textContent = data.error || 'Something went wrong.';
            errEl.classList.add('show');
            btn.disabled = false;
            btn.textContent = 'Save Top Up';
        }
    } catch (err) {
        errEl.textContent = 'Network error. Please try again.';
        errEl.classList.add('show');
        btn.disabled = false;
        btn.textContent = 'Save Top Up';
    }
});

// ── Import CSV modal ──
const importModal = document.getElementById('importModal');

function openImportModal() {
    document.getElementById('importErr').classList.remove('show');
    document.getElementById('importOk').classList.remove('show');
    document.getElementById('importForm').reset();
    document.getElementById('dropFilename').style.display = 'none';
    document.getElementById('dropFilename').textContent   = '';
    document.getElementById('importSubmitBtn').disabled   = true;
    document.getElementById('importSubmitBtn').style.opacity = '.5';
    importModal.classList.add('on');
    document.body.style.overflow = 'hidden';
}
function closeImportModal() {
    importModal.classList.remove('on');
    document.body.style.overflow = '';
}

document.getElementById('openImportBtn').addEventListener('click', openImportModal);
document.getElementById('importModalClose').addEventListener('click', closeImportModal);
document.getElementById('importCancelBtn').addEventListener('click', closeImportModal);
importModal.addEventListener('click', e => { if (e.target === importModal) closeImportModal(); });

// Drop zone behaviour
const dropZone   = document.getElementById('dropZone');
const importFile = document.getElementById('importFile');
const submitBtn  = document.getElementById('importSubmitBtn');

function setFile(file) {
    if (!file || !file.name.endsWith('.csv')) return;
    const dt = new DataTransfer();
    dt.items.add(file);
    importFile.files = dt.files;
    const fn = document.getElementById('dropFilename');
    fn.textContent   = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
    fn.style.display = 'block';
    submitBtn.disabled     = false;
    submitBtn.style.opacity = '1';
}

dropZone.addEventListener('click', () => importFile.click());
importFile.addEventListener('change', () => { if (importFile.files[0]) setFile(importFile.files[0]); });

dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    const file = e.dataTransfer.files[0];
    if (file) setFile(file);
});

document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn   = submitBtn;
    const errEl = document.getElementById('importErr');
    const okEl  = document.getElementById('importOk');
    errEl.classList.remove('show');
    okEl.classList.remove('show');
    if (!importFile.files[0]) {
        errEl.textContent = 'Please select a CSV file.';
        errEl.classList.add('show');
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Importing…';
    try {
        const fd = new FormData(this);
        fd.append('action', 'import_csv');
        const res  = await fetch(location.href, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            okEl.textContent = data.count + ' row' + (data.count !== 1 ? 's' : '') + ' imported successfully.';
            okEl.classList.add('show');
            setTimeout(() => { closeImportModal(); location.reload(); }, 1500);
        } else {
            errEl.textContent = data.error || 'Import failed.';
            errEl.classList.add('show');
            btn.disabled = false;
            btn.textContent = 'Import';
        }
    } catch (err) {
        errEl.textContent = 'Network error. Please try again.';
        errEl.classList.add('show');
        btn.disabled = false;
        btn.textContent = 'Import';
    }
});

// ── Escape closes any open modal ──
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeTxModal(); closeTopupModal(); closeImportModal(); }
});
</script>
</body>
</html>
