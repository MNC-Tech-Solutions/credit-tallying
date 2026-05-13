<?php
/**
 * GHL Credits Dashboard – Redesigned
 * Design: DM Sans + DM Serif Display, warm neutral palette.
 * Fix: remaining credits/amount shows negative (red) when exceeded.
 * currently using for other sub accounts
 */

$targetLocationId = isset($_GET['locationId']) ? trim($_GET['locationId']) : '';

if ($targetLocationId === '') {
    displayError("No subaccount ID provided.");
}
if (!preg_match('/^[A-Za-z0-9_-]+$/', $targetLocationId)) {
    displayError("Invalid subaccount ID.");
}

function getCsvFiles($directory) {
    if (!is_dir($directory)) return [];
    return glob($directory . '/*.csv') ?: [];
}

function getCreditLimits($filePath, $targetLocationId = null) {
    $creditLimits = [];
    if (!file_exists($filePath)) return $creditLimits;
    $file = fopen($filePath, 'r');
    if (!$file) return $creditLimits;
    $header = fgetcsv($file);
    if (!$header) { fclose($file); return $creditLimits; }

    $locationIdIndex     = false;
    $whatsappCreditIndex = false;
    $emailCreditIndex    = false;
    $callCreditIndex     = false;

    foreach ($header as $index => $column) {
        $col = strtolower(trim($column));
        if ($col === 'locationid') $locationIdIndex = $index;
        if ($col === 'totalamount' || $col === 'whatsappcredits') $whatsappCreditIndex = $index;
        if ($col === 'emailcredits') $emailCreditIndex = $index;
        if ($col === 'callcredits') $callCreditIndex = $index;
    }
    if ($locationIdIndex === false) { fclose($file); return $creditLimits; }

    while (($row = fgetcsv($file)) !== false) {
        if (empty($row[$locationIdIndex])) continue;
        $locationId = trim($row[$locationIdIndex]);
        if ($targetLocationId !== null && $locationId !== $targetLocationId) continue;
        $creditLimits[$locationId] = [
            'whatsappCredit' => $whatsappCreditIndex !== false ? floatval($row[$whatsappCreditIndex] ?? 0) : 0,
            'emailCredit'    => $emailCreditIndex    !== false ? floatval($row[$emailCreditIndex]    ?? 0) : 0,
            'callCredit'     => $callCreditIndex     !== false ? floatval($row[$callCreditIndex]     ?? 0) : 0,
        ];
    }
    fclose($file);
    return $creditLimits;
}

function processCsvFiles($csvFiles, $targetLocationId = null) {
    $results = [];
    foreach ($csvFiles as $filePath) {
        if (basename($filePath) === 'total_credits.csv') continue;
        if (!file_exists($filePath)) continue;
        $file = fopen($filePath, 'r');
        if (!$file) continue;
        $header = fgetcsv($file);
        if (!$header) { fclose($file); continue; }

        $locationIdIndex   = false; $locationNameIndex = false;
        $typeIndex         = false; $amountIndex       = false;
        $dateIndex         = false; $descriptionIndex  = false;

        foreach ($header as $index => $column) {
            $col = strtolower(trim($column));
            if ($col === 'locationid'   || $col === 'location id')   $locationIdIndex   = $index;
            if ($col === 'locationname' || $col === 'location name') $locationNameIndex = $index;
            if ($col === 'type'         || $col === 'transaction type') $typeIndex      = $index;
            if ($col === 'amount')       $amountIndex      = $index;
            if ($col === 'date'         || $col === 'activity date') $dateIndex         = $index;
            if ($col === 'description')  $descriptionIndex = $index;
        }
        if ($locationIdIndex === false || $amountIndex === false || $typeIndex === false) { fclose($file); continue; }

        while (($row = fgetcsv($file)) !== false) {
            if (empty($row[$locationIdIndex]) || !isset($row[$amountIndex]) || empty($row[$typeIndex])) continue;
            $locationId = trim($row[$locationIdIndex]);
            if ($targetLocationId !== null && $locationId !== $targetLocationId) continue;

            $type        = trim($row[$typeIndex]);
            $description = $descriptionIndex !== false ? strtolower(trim($row[$descriptionIndex] ?? '')) : '';

            if (stripos($type, 'WhatsApp') !== false || stripos($description, 'whatsapp') !== false) {
                $category = 'whatsapp'; $creditAmount = 0.50;
            } elseif (stripos($type, 'Emails') !== false || stripos($description, 'emails') !== false) {
                $category = 'email'; $creditAmount = 0.005;
            } elseif ($type === 'Voice Minutes - Outbound Calls' || $type === 'Voice Minutes - Inbound Calls') {
                $category = 'call'; $creditAmount = floatval($row[$amountIndex]);
            } else {
                continue;
            }

            $locationName = $locationNameIndex !== false && !empty($row[$locationNameIndex]) ? trim($row[$locationNameIndex]) : $locationId;

            if (!isset($results[$locationId])) {
                $results[$locationId] = ['locationName' => $locationName, 'emailAmount' => 0, 'whatsappAmount' => 0, 'callAmount' => 0,
                    'emailCount' => 0, 'whatsappCount' => 0, 'callCount' => 0, 'monthlyData' => []];
            }

            if ($category === 'email')     { $results[$locationId]['emailAmount']     += $creditAmount; $results[$locationId]['emailCount']++; }
            if ($category === 'whatsapp')  { $results[$locationId]['whatsappAmount']  += $creditAmount; $results[$locationId]['whatsappCount']++; }
            if ($category === 'call')      { $results[$locationId]['callAmount']      += $creditAmount; $results[$locationId]['callCount']++; }

            if ($dateIndex !== false && !empty($row[$dateIndex])) {
                $dateStr = preg_replace('/(st|nd|rd|th)/', '', $row[$dateIndex]);
                $date    = DateTime::createFromFormat('M j Y, h:i:s A', trim($dateStr));
                if ($date) {
                    $monthKey = $date->format('Y-m');
                    if (!isset($results[$locationId]['monthlyData'][$monthKey])) {
                        $results[$locationId]['monthlyData'][$monthKey] = ['types' => []];
                    }
                    if (!isset($results[$locationId]['monthlyData'][$monthKey]['types'][$category])) {
                        $results[$locationId]['monthlyData'][$monthKey]['types'][$category] = ['totalAmount' => 0, 'count' => 0, 'transactions' => []];
                    }
                    $results[$locationId]['monthlyData'][$monthKey]['types'][$category]['totalAmount'] += $creditAmount;
                    $results[$locationId]['monthlyData'][$monthKey]['types'][$category]['count']++;
                    $results[$locationId]['monthlyData'][$monthKey]['types'][$category]['transactions'][] = [
                        'date'         => $row[$dateIndex],
                        'amount'       => $creditAmount,
                        'originalType' => $type,
                        'description'  => $descriptionIndex !== false ? ($row[$descriptionIndex] ?? '') : '',
                    ];
                }
            }
        }
        fclose($file);
    }
    return $results;
}

function displayError($msg) {
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><link href='https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600&family=DM+Serif+Display&display=swap' rel='stylesheet'></head><body style='background:#f5f4f1;font-family:DM Sans,system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;'><div style='background:#fff;border:1px solid #e8e5e0;border-radius:20px;padding:48px 40px;text-align:center;max-width:420px;box-shadow:0 4px 16px rgba(0,0,0,0.07);'><div style='font-size:40px;margin-bottom:12px;'>⚠️</div><h2 style='font-family:DM Serif Display,serif;color:#1a1916;margin:0 0 8px;font-size:22px;'>$msg</h2></div></body></html>");
}

// Execute
$csvDirectory     = __DIR__ . '/csv_files';
$creditLimitsFile = __DIR__ . '/total_credits.csv';
$csvFiles         = getCsvFiles($csvDirectory);
$creditLimits     = getCreditLimits($creditLimitsFile, $targetLocationId);
$processedData    = processCsvFiles($csvFiles, $targetLocationId);

if (empty($processedData)) displayError("No valid data found for this subaccount.");

// Build subaccount data
$subaccountData = [];
foreach ($processedData as $locationId => $data) {
    $emailUsedAmountRm    = $data['emailAmount'];
    $whatsappUsedAmountRm = $data['whatsappAmount'];
    $callUsedAmountRm     = $data['callAmount'];
    $whatsappCredit       = $creditLimits[$locationId]['whatsappCredit'] ?? 0;
    $emailCredit          = $creditLimits[$locationId]['emailCredit']    ?? 0;
    $callCredit           = $creditLimits[$locationId]['callCredit']     ?? 0;
    $whatsappAmountRm     = $whatsappCredit / 2;
    $emailAmountRm        = $emailCredit * 0.005;
    $callAmountRm         = $callCredit * 0.054;
    $emailUsedCredit      = $emailUsedAmountRm    / 0.005;
    $whatsappUsedCredit   = $whatsappUsedAmountRm / 0.50;
    $callUsedCredit       = $callUsedAmountRm     / 0.054;

    // NO max(0,...) — allow negative to show overuse
    $emailRemainingCredit    = $emailCredit    - $emailUsedCredit;
    $whatsappRemainingCredit = $whatsappCredit - $whatsappUsedCredit;
    $callRemainingCredit     = $callCredit     - $callUsedCredit;
    $emailRemainingAmount    = $emailAmountRm    - $emailUsedAmountRm;
    $whatsappRemainingAmount = $whatsappAmountRm - $whatsappUsedAmountRm;
    $callRemainingAmount     = $callAmountRm     - $callUsedAmountRm;

    $subaccountData[$locationId] = [
        'name'                    => $data['locationName'],
        'emailCredit'             => $emailCredit,
        'whatsappCredit'          => $whatsappCredit,
        'callCredit'              => $callCredit,
        'emailUsedCredit'         => $emailUsedCredit,
        'whatsappUsedCredit'      => $whatsappUsedCredit,
        'callUsedCredit'          => $callUsedCredit,
        'emailRemainingCredit'    => $emailRemainingCredit,
        'whatsappRemainingCredit' => $whatsappRemainingCredit,
        'callRemainingCredit'     => $callRemainingCredit,
        'emailAmount'             => $emailAmountRm,
        'whatsappAmount'          => $whatsappAmountRm,
        'callAmount'              => $callAmountRm,
        'emailUsedAmount'         => $emailUsedAmountRm,
        'whatsappUsedAmount'      => $whatsappUsedAmountRm,
        'callUsedAmount'          => $callUsedAmountRm,
        'emailRemainingAmount'    => $emailRemainingAmount,
        'whatsappRemainingAmount' => $whatsappRemainingAmount,
        'callRemainingAmount'     => $callRemainingAmount,
        'emailPercent'            => $emailAmountRm    > 0 ? min(100, round($emailUsedAmountRm    / $emailAmountRm    * 100)) : 0,
        'whatsappPercent'         => $whatsappAmountRm > 0 ? min(100, round($whatsappUsedAmountRm / $whatsappAmountRm * 100)) : 0,
        'callPercent'             => $callAmountRm     > 0 ? min(100, round($callUsedAmountRm     / $callAmountRm     * 100)) : 0,
        'monthlyData'             => $data['monthlyData'],
    ];
}

$initialData = $subaccountData[$targetLocationId] ?? null;
if (!$initialData) displayError("No valid data found for this subaccount.");

ksort($initialData['monthlyData']);
$initialData['monthlyData'] = array_reverse($initialData['monthlyData'], true);

$subaccountDataJson = json_encode($subaccountData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Helper: format signed number
function fmtSigned($val, $decimals = 0) {
    return ($val < 0 ? '-' : '') . number_format(abs($val), $decimals);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credits Dashboard | <?php echo htmlspecialchars($targetLocationId); ?></title>
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

    /* TOPBAR */
    .topbar{display:flex;align-items:center;justify-content:space-between;padding:32px 0 28px;margin-bottom:28px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:16px;}
    .brand{display:flex;align-items:center;gap:14px;}
    .brand-icon{width:46px;height:46px;border-radius:14px;background:linear-gradient(135deg,var(--wa) 0%,var(--wa-light) 100%);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(37,211,102,0.28);flex-shrink:0;}
    .brand-icon svg{width:22px;height:22px;}
    .brand-name{font-family:'DM Serif Display',serif;font-size:21px;color:var(--text);letter-spacing:-0.3px;}
    .brand-sub{font-size:12px;color:var(--text2);margin-top:1px;}
    .id-chip{font-size:12px;font-weight:600;color:var(--text2);background:var(--card);border:1px solid var(--border);border-radius:var(--r-xs);padding:6px 14px;box-shadow:var(--shadow-sm);}

    /* GRID */
    .usage-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:24px;}

    /* USAGE CARD */
    .ucard{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:0;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s;animation:fadeUp .5s ease both;}
    .ucard:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
    .ucard::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:var(--r) var(--r) 0 0;}
    .ucard.wa::before{background:linear-gradient(90deg,var(--wa),var(--wa-light));}
    .ucard.em::before{background:linear-gradient(90deg,var(--em),var(--em-light));}
    .ucard.call::before{background:linear-gradient(90deg,var(--call),var(--call-light));}
    .ucard:nth-child(1){animation-delay:.05s}
    .ucard:nth-child(2){animation-delay:.12s}
    .ucard:nth-child(3){animation-delay:.19s}

    .ucard-inner{padding:26px 28px;}

    /* CARD HEADER */
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
    .ucard-pct.exceeded{background:var(--danger-soft);color:var(--danger);}

    /* PROGRESS */
    .prog-wrap{margin:0 0 20px;}
    .prog-labels{display:flex;justify-content:space-between;font-size:12px;font-weight:600;color:var(--text2);margin-bottom:8px;}
    .prog-track{height:10px;background:var(--bg);border-radius:999px;overflow:hidden;border:1px solid var(--border);}
    .prog-fill{height:100%;border-radius:999px;transition:width 1.2s cubic-bezier(.22,1,.36,1);}
    .ucard.wa .prog-fill{background:linear-gradient(90deg,var(--wa),var(--wa-light));}
    .ucard.em .prog-fill{background:linear-gradient(90deg,var(--em),var(--em-light));}
    .ucard.call .prog-fill{background:linear-gradient(90deg,var(--call),var(--call-light));}
    .prog-fill.exceeded{background:linear-gradient(90deg,var(--danger),#ef4444);}
    .prog-midmarks{display:flex;justify-content:space-between;font-size:11px;color:var(--text3);margin-top:5px;}

    /* TABS */
    .utabs{display:flex;gap:2px;background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:4px;margin-bottom:18px;}
    .utab{flex:1;padding:8px 14px;font-size:13px;font-weight:600;color:var(--text2);background:transparent;border:none;border-radius:9px;cursor:pointer;transition:background .18s,color .18s;white-space:nowrap;font-family:'DM Sans',sans-serif;text-align:center;}
    .utab:hover{color:var(--text);background:var(--bg);}
    .utab.active{color:#fff;}
    .ucard.wa .utab.active{background:var(--wa);}
    .ucard.em .utab.active{background:var(--em);}
    .ucard.call .utab.active{background:var(--call);}

    /* METRICS */
    .metrics-pane{display:none;}
    .metrics-pane.active{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
    .metric{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;}
    .metric-lbl{font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);margin-bottom:4px;}
    .metric-val{font-family:'DM Serif Display',serif;font-size:19px;color:var(--text);letter-spacing:-.3px;}
    .metric-val.exceeded{color:var(--danger);}
    .metric-sub{font-size:11px;color:var(--text3);margin-top:3px;}
    .metric.exceeded-tile{border-color:var(--danger-mid);background:var(--danger-soft);}

    /* PANEL */
    .panel{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;box-shadow:var(--shadow-sm);animation:fadeUp .5s .2s ease both;}
    .panel-hdr{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--border);}
    .panel-ttl{font-family:'DM Serif Display',serif;font-size:17px;color:var(--text);}
    .panel-body{padding:20px 24px;}

    /* SELECT */
    .sel-wrap{position:relative;}
    .sel-wrap svg{position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--text3);width:14px;height:14px;}
    .month-sel{width:100%;padding:12px 44px 12px 16px;font-size:14px;font-family:'DM Sans',sans-serif;font-weight:500;color:var(--text);background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);cursor:pointer;appearance:none;outline:none;transition:.2s;box-shadow:var(--shadow-sm);}
    .month-sel:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);}

    /* CATEGORY TILES */
    .mo-tiles{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;}
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

    /* MODAL */
    .modal{display:none;position:fixed;inset:0;background:rgba(26,25,22,.45);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:9999;align-items:center;justify-content:center;padding:24px;}
    .modal.on{display:flex;}
    .modal-box{background:var(--card);border:1px solid var(--border);border-radius:var(--r);width:100%;max-width:640px;max-height:88vh;overflow-y:auto;box-shadow:var(--shadow-xl);position:relative;animation:fadeUp .22s ease;}
    .modal-head{padding:26px 28px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;justify-content:space-between;gap:16px;position:sticky;top:0;background:var(--card);z-index:1;}
    .modal-cat{font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
    .modal-cat.wa{color:var(--wa);}
    .modal-cat.em{color:var(--em);}
    .modal-cat.call{color:var(--call);}
    .modal-ttl{font-family:'DM Serif Display',serif;font-size:20px;color:var(--text);line-height:1.2;}
    .modal-meta{font-size:13px;color:var(--text2);margin-top:4px;}
    .modal-close{width:34px;height:34px;border-radius:var(--r-xs);border:1px solid var(--border);background:var(--bg);color:var(--text2);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;line-height:1;flex-shrink:0;}
    .modal-close:hover{background:var(--accent-soft);border-color:rgba(124,58,237,.2);color:var(--accent);}
    .modal-body{padding:20px 28px 28px;}
    .modal-summary{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;}
    .ms-tile{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;}
    .ms-lbl{font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);margin-bottom:4px;}
    .ms-val{font-family:'DM Serif Display',serif;font-size:20px;color:var(--text);}

    /* TABLE */
    .tbl-scroll{overflow-x:auto;border-radius:var(--r-sm);border:1px solid var(--border);}
    table{width:100%;border-collapse:collapse;}
    thead tr{background:var(--bg);}
    thead th{padding:11px 16px;font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);text-align:left;border-bottom:1px solid var(--border);}
    th.r,td.r{text-align:right;}
    tbody tr{border-bottom:1px solid var(--border);transition:background .12s;}
    tbody tr:last-child{border-bottom:none;}
    tbody tr:hover{background:var(--surface);}
    tbody td{padding:12px 16px;font-size:13.5px;vertical-align:middle;}
    .td-date{color:var(--text2);font-size:13px;}
    .td-type{color:var(--text);font-weight:500;}
    .td-amt{font-weight:700;font-variant-numeric:tabular-nums;}
    .td-amt.wa{color:var(--wa);}
    .td-amt.em{color:var(--em);}
    .td-amt.call{color:var(--call);}

    @keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
    ::-webkit-scrollbar{width:5px;height:5px;}
    ::-webkit-scrollbar-track{background:transparent;}
    ::-webkit-scrollbar-thumb{background:var(--border2);border-radius:999px;}

    @media(max-width:900px){
        .usage-grid{grid-template-columns:1fr 1fr;}
    }
    @media(max-width:640px){
        .usage-grid,.mo-tiles,.modal-summary{grid-template-columns:1fr;}
        .metrics-pane.active{grid-template-columns:1fr 1fr;}
    }
    @media(max-width:420px){
        .metrics-pane.active{grid-template-columns:1fr;}
    }
    </style>
</head>
<body>
<div class="wrap">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="brand">
            <div class="brand-icon">
                <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M18 6C11.373 6 6 11.373 6 18c0 2.215.605 4.29 1.658 6.071L6 30l6.144-1.614A11.93 11.93 0 0018 30c6.627 0 12-5.373 12-12S24.627 6 18 6zm-4 9h8m-8 4h5" stroke="white" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <div>
                <div class="brand-name">Credits Dashboard</div>
                <div class="brand-sub">WhatsApp &amp; Email Spend Tracker</div>
            </div>
        </div>
        <div class="id-chip">ID: <?php echo htmlspecialchars($targetLocationId); ?></div>
    </div>

    <!-- USAGE CARDS -->
    <div class="usage-grid">

        <?php
        $cards = [
            'wa' => [
                'cls'    => 'wa',
                'label'  => 'WhatsApp',
                'pct'    => $initialData['whatsappPercent'],
                'credit' => $initialData['whatsappCredit'],
                'usedC'  => $initialData['whatsappUsedCredit'],
                'remC'   => $initialData['whatsappRemainingCredit'],
                'total'  => $initialData['whatsappAmount'],
                'usedA'  => $initialData['whatsappUsedAmount'],
                'remA'   => $initialData['whatsappRemainingAmount'],
                'rate'   => 'RM 0.50 / msg',
                'icon'   => '<path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6A8.38 8.38 0 0112 10h.5a8.48 8.48 0 018 8v.5z"/>',
            ],
            'em' => [
                'cls'    => 'em',
                'label'  => 'Email',
                'pct'    => $initialData['emailPercent'],
                'credit' => $initialData['emailCredit'],
                'usedC'  => $initialData['emailUsedCredit'],
                'remC'   => $initialData['emailRemainingCredit'],
                'total'  => $initialData['emailAmount'],
                'usedA'  => $initialData['emailUsedAmount'],
                'remA'   => $initialData['emailRemainingAmount'],
                'rate'   => 'RM 0.005 / email',
                'icon'   => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
            ],
            'call' => [
                'cls'    => 'call',
                'label'  => 'Call',
                'pct'    => $initialData['callPercent'],
                'credit' => $initialData['callCredit'],
                'usedC'  => $initialData['callUsedCredit'],
                'remC'   => $initialData['callRemainingCredit'],
                'total'  => $initialData['callAmount'],
                'usedA'  => $initialData['callUsedAmount'],
                'remA'   => $initialData['callRemainingAmount'],
                'rate'   => 'RM 0.054 / min',
                'icon'   => '<path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 8.63 19.79 19.79 0 010 2H3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L4.09 9.9a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/>',
            ],
        ];
        foreach ($cards as $c):
            $exceeded    = $c['pct'] >= 100;
            $pctLabel    = $exceeded ? '100%+ used' : $c['pct'] . '% used';
            $midCredit   = number_format(round($c['credit'] / 2));
            $maxCredit   = number_format($c['credit']);
            $midAmount   = number_format($c['total'] / 2, 2);
            $maxAmount   = number_format($c['total'], 2);
        ?>
        <div class="ucard <?php echo $c['cls']; ?>">
            <div class="ucard-inner">
                <!-- Head -->
                <div class="ucard-head">
                    <div class="ucard-ico">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--<?php echo $c['cls']; ?>)" stroke-width="2"><?php echo $c['icon']; ?></svg>
                    </div>
                    <div class="ucard-ttl"><?php echo $c['label']; ?></div>
                    <div class="ucard-pct <?php echo $exceeded ? 'exceeded' : ''; ?>"><?php echo $pctLabel; ?></div>
                </div>

                <!-- Progress -->
                <div class="prog-wrap">
                    <div class="prog-labels">
                        <span>Usage Progress</span>
                        <span><?php echo $c['pct']; ?>%</span>
                    </div>
                    <div class="prog-track">
                        <div class="prog-fill <?php echo $c['cls']; ?> <?php echo $exceeded ? 'exceeded' : ''; ?>" style="width:<?php echo $c['pct']; ?>%"></div>
                    </div>
                    <div class="prog-midmarks">
                        <span>0</span>
                        <span id="midmark-<?php echo $c['cls']; ?>">—</span>
                        <span id="maxmark-<?php echo $c['cls']; ?>">—</span>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="utabs">
                    <button class="utab active" data-tab="credits" data-card="<?php echo $c['cls']; ?>">Credits</button>
                    <button class="utab" data-tab="amount" data-card="<?php echo $c['cls']; ?>">Amount (RM)</button>
                </div>

                <!-- Credits pane -->
                <div class="metrics-pane active" id="pane-<?php echo $c['cls']; ?>-credits">
                    <div class="metric">
                        <div class="metric-lbl">Total</div>
                        <div class="metric-val"><?php echo number_format($c['credit']); ?></div>
                        <div class="metric-sub">credits allocated</div>
                    </div>
                    <div class="metric">
                        <div class="metric-lbl">Used</div>
                        <div class="metric-val"><?php echo number_format($c['usedC']); ?></div>
                        <div class="metric-sub">credits consumed</div>
                    </div>
                    <div class="metric <?php echo $c['remC'] < 0 ? 'exceeded-tile' : ''; ?>">
                        <div class="metric-lbl">Remaining</div>
                        <div class="metric-val <?php echo $c['remC'] < 0 ? 'exceeded' : ''; ?>">
                            <?php echo ($c['remC'] < 0 ? '−' : '') . number_format(abs($c['remC'])); ?>
                        </div>
                        <div class="metric-sub"><?php echo $c['remC'] < 0 ? 'over limit' : 'credits left'; ?></div>
                    </div>
                </div>

                <!-- Amount pane -->
                <div class="metrics-pane" id="pane-<?php echo $c['cls']; ?>-amount">
                    <div class="metric">
                        <div class="metric-lbl">Total</div>
                        <div class="metric-val">RM <?php echo number_format($c['total'], 2); ?></div>
                        <div class="metric-sub">budget allocated</div>
                    </div>
                    <div class="metric">
                        <div class="metric-lbl">Used</div>
                        <div class="metric-val">RM <?php echo number_format($c['usedA'], 2); ?></div>
                        <div class="metric-sub"><?php echo $c['rate']; ?></div>
                    </div>
                    <div class="metric <?php echo $c['remA'] < 0 ? 'exceeded-tile' : ''; ?>">
                        <div class="metric-lbl">Remaining</div>
                        <div class="metric-val <?php echo $c['remA'] < 0 ? 'exceeded' : ''; ?>">
                            <?php echo ($c['remA'] < 0 ? '−' : '') . 'RM ' . number_format(abs($c['remA']), 2); ?>
                        </div>
                        <div class="metric-sub"><?php echo $c['remA'] < 0 ? 'over budget' : 'remaining budget'; ?></div>
                    </div>
                </div>

                <!-- hidden data for JS midmarks -->
                <span style="display:none"
                    data-mid-credits="<?php echo $midCredit; ?>"
                    data-max-credits="<?php echo $maxCredit; ?>"
                    data-mid-amount="<?php echo $midAmount; ?>"
                    data-max-amount="<?php echo $maxAmount; ?>"
                    data-card="<?php echo $c['cls']; ?>"
                    class="midmark-data"></span>
            </div>
        </div>
        <?php endforeach; ?>

    </div><!-- /usage-grid -->

    <!-- MONTHLY PANEL -->
    <div class="panel">
        <div class="panel-hdr">
            <span class="panel-ttl">Monthly Transactions</span>
        </div>
        <div class="panel-body">
            <div class="sel-wrap">
                <select class="month-sel" id="monthPicker">
                    <option value="">Select a month to view transactions…</option>
                    <?php foreach ($initialData['monthlyData'] as $mKey => $mData):
                        $dt   = DateTime::createFromFormat('Y-m', $mKey);
                        $name = $dt ? $dt->format('F Y') : $mKey;
                    ?>
                    <option value="<?php echo htmlspecialchars($mKey); ?>"><?php echo htmlspecialchars($name); ?></option>
                    <?php endforeach; ?>
                </select>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="mo-tiles" id="moTiles" style="display:none"></div>
        </div>
    </div>

</div><!-- /wrap -->

<!-- MODAL -->
<div id="modal" class="modal">
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <div class="modal-cat" id="modalCat"></div>
                <div class="modal-ttl" id="modalTtl"></div>
                <div class="modal-meta" id="modalMeta"></div>
            </div>
            <button class="modal-close" id="modalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="modal-summary">
                <div class="ms-tile">
                    <div class="ms-lbl">Total Spent</div>
                    <div class="ms-val" id="msTotalAmt"></div>
                </div>
                <div class="ms-tile">
                    <div class="ms-lbl">Transactions</div>
                    <div class="ms-val" id="msTotalCnt"></div>
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
                    <tbody id="modalTbody"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const monthlyData    = <?php echo json_encode($initialData['monthlyData']); ?>;
const picker         = document.getElementById('monthPicker');
const tiles          = document.getElementById('moTiles');
const modal          = document.getElementById('modal');

// ── Midmarks ──
document.querySelectorAll('.midmark-data').forEach(el => {
    const card = el.dataset.card;
    const mc   = document.getElementById('midmark-' + card);
    const mx   = document.getElementById('maxmark-' + card);
    // Default to credits view
    if (mc) mc.textContent = el.dataset.midCredits;
    if (mx) mx.textContent = el.dataset.maxCredits;
    el.dataset.currentTab = 'credits';
});

// ── Tabs ──
document.querySelectorAll('.utab').forEach(btn => {
    btn.addEventListener('click', () => {
        const card = btn.dataset.card;
        const tab  = btn.dataset.tab;
        // Switch button state
        document.querySelectorAll(`.utab[data-card="${card}"]`).forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        // Switch pane
        document.querySelectorAll(`[id^="pane-${card}-"]`).forEach(p => p.classList.remove('active'));
        document.getElementById(`pane-${card}-${tab}`).classList.add('active');
        // Update midmarks
        const el  = document.querySelector(`.midmark-data[data-card="${card}"]`);
        const mc  = document.getElementById('midmark-' + card);
        const mx  = document.getElementById('maxmark-' + card);
        if (tab === 'credits') {
            mc.textContent = el.dataset.midCredits;
            mx.textContent = el.dataset.maxCredits;
        } else {
            mc.textContent = 'RM ' + el.dataset.midAmount;
            mx.textContent = 'RM ' + el.dataset.maxAmount;
        }
    });
});

// ── Month dropdown ──
picker.addEventListener('change', () => {
    const val = picker.value;
    tiles.innerHTML = '';
    if (!val) { tiles.style.display = 'none'; return; }

    const mData = monthlyData[val] || {};
    const types = mData.types || {};
    const dt    = new Date(val + '-02');
    const mName = dt.toLocaleString('en-US', { month: 'long', year: 'numeric' });

    let html = '';
    [['whatsapp','wa','WhatsApp'], ['email','em','Email'], ['call','call','Call']].forEach(([key, cls, label]) => {
        const d = types[key];
        if (!d) return;
        html += `
        <div class="mo-tile" onclick="openModal('${val}','${key}','${mName}')">
            <div>
                <div class="mo-tile-cat ${cls}"><span class="dot ${cls}"></span>${label}</div>
                <div class="mo-tile-amt">RM ${d.totalAmount.toFixed(2)}</div>
                <div class="mo-tile-cnt">${d.count} transaction${d.count !== 1 ? 's' : ''}</div>
            </div>
            <div class="mo-tile-arrow">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
        </div>`;
    });

    if (!html) html = '<p style="grid-column:1/-1;text-align:center;padding:24px 0;color:var(--text3);font-size:14px;">No transactions for this month.</p>';
    tiles.innerHTML = html;
    tiles.style.display = 'grid';
});

// ── Open modal ──
function openModal(mKey, cat, mName) {
    const d   = ((monthlyData[mKey] || {}).types || {})[cat];
    const cls = cat === 'whatsapp' ? 'wa' : cat === 'call' ? 'call' : 'em';
    const lbl = cat === 'whatsapp' ? 'WhatsApp' : cat === 'call' ? 'Call' : 'Email';
    if (!d) return;

    document.getElementById('modalCat').className   = 'modal-cat ' + cls;
    document.getElementById('modalCat').innerHTML   = `<span class="dot ${cls}"></span>${lbl}`;
    document.getElementById('modalTtl').textContent = mName;
    document.getElementById('modalMeta').textContent = d.count + ' transaction' + (d.count !== 1 ? 's' : '');
    document.getElementById('msTotalAmt').textContent = 'RM ' + d.totalAmount.toFixed(2);
    document.getElementById('msTotalCnt').textContent = d.count.toLocaleString();

    document.getElementById('modalTbody').innerHTML =
        [...d.transactions].sort((a, b) => b.amount - a.amount).map(tx => {
            const clean = tx.date.replace(/(st|nd|rd|th)/i, '').replace(/,/g, '');
            const ts    = new Date(clean);
            const fd    = isNaN(ts) ? tx.date : ts.toLocaleString('en-US', {
                month:'short', day:'2-digit', year:'numeric',
                hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:true
            });
            return `<tr>
                <td class="td-date">${fd}</td>
                <td class="td-type">${tx.originalType}</td>
                <td class="td-amt ${cls} r">RM ${tx.amount.toFixed(3)}</td>
            </tr>`;
        }).join('');

    modal.classList.add('on');
    document.body.style.overflow = 'hidden';
}

// ── Close modal ──
function closeModal() { modal.classList.remove('on'); document.body.style.overflow = ''; }
document.getElementById('modalClose').addEventListener('click', closeModal);
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>
</body>
</html>