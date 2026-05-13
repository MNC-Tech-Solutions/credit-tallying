<?php
$targetLocationId = isset($_GET['locationId']) ? trim($_GET['locationId']) : '';
$isDemo = $targetLocationId === 'WphrMU0x3Ocd2pEpBJcH';

if ($targetLocationId !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $targetLocationId)) {
    echo '<html><body><div style="text-align:center;padding:20px;"><h2>Invalid subaccount ID.</h2></div></body></html>';
    exit;
}

function getCsvFiles($directory) {
    if (!is_dir($directory)) return [];
    return glob($directory . '/*.csv') ?: [];
}

function processAll($csvFiles, $isDemo, $targetLocationId) {
    $results = [];
    foreach ($csvFiles as $filePath) {
        if (!file_exists($filePath)) continue;
        $file = fopen($filePath, 'r');
        if ($file === false) continue;
        $header = fgetcsv($file);
        if ($header === false) { fclose($file); continue; }
        $map = array_flip(array_map(fn($c) => strtolower(trim($c)), $header));
        $idxId     = $map['location id']      ?? $map['locationid']      ?? $map['location_id'] ?? false;
        $idxType   = $map['transaction type'] ?? $map['transactiontype'] ?? $map['type']        ?? false;
        $idxAmount = $map['original amount']  ?? $map['amount']                                  ?? false;
        $idxDate   = $map['activity date']    ?? $map['date']                                    ?? false;
        $idxName   = $map['location name']    ?? $map['locationname']                            ?? false;
        $idxDesc   = $map['description']                                                         ?? false;
        if ($idxId === false || $idxAmount === false || $idxType === false) { fclose($file); continue; }
        while (($row = fgetcsv($file)) !== false) {
            if (trim($row[$idxType] ?? '') !== 'Conversation and Voice AI') continue;
            $locId = trim($row[$idxId] ?? '');
            if (!$isDemo && $targetLocationId !== '' && $locId !== $targetLocationId) continue;
            $amount  = floatval($row[$idxAmount] ?? 0);
            $locName = ($idxName !== false && !empty($row[$idxName])) ? trim($row[$idxName]) : $locId;
            $desc    = ($idxDesc !== false) ? trim($row[$idxDesc] ?? '') : '';
            $monthKey = null;
            if ($idxDate !== false && !empty($row[$idxDate])) {
                $clean = str_replace(',', '', preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $row[$idxDate]));
                $ts = strtotime($clean);
                if ($ts) $monthKey = date('Y-m', $ts);
            }
            if (!isset($results[$locId])) {
                $results[$locId] = ['locationName' => $locName, 'conversationaiAmount' => 0, 'conversationaiCount' => 0, 'monthlyData' => []];
            }
            $results[$locId]['conversationaiAmount'] += $amount;
            $results[$locId]['conversationaiCount']++;
            if ($monthKey) {
                if (!isset($results[$locId]['monthlyData'][$monthKey]))
                    $results[$locId]['monthlyData'][$monthKey] = ['totalAmount' => 0, 'count' => 0, 'transactions' => []];
                $results[$locId]['monthlyData'][$monthKey]['totalAmount'] += $amount;
                $results[$locId]['monthlyData'][$monthKey]['count']++;
                $results[$locId]['monthlyData'][$monthKey]['transactions'][] = ['date' => $row[$idxDate], 'amount' => $amount, 'description' => $desc];
            }
        }
        fclose($file);
    }
    return $results;
}

$csvDirectory  = __DIR__ . '/csv_files';
$csvFiles      = getCsvFiles($csvDirectory);
$processedData = processAll($csvFiles, $isDemo, $targetLocationId);

if (empty($processedData) && $targetLocationId !== '') {
    echo '<html><body><div style="text-align:center;padding:40px;"><h2>No valid data found for this subaccount.</h2></div></body></html>';
    exit;
}

uasort($processedData, fn($a, $b) => $b['conversationaiAmount'] <=> $a['conversationaiAmount']);

$subaccountData = [];
$grandTotal = 0; $grandCount = 0;
foreach ($processedData as $locationId => $data) {
    $grandTotal += $data['conversationaiAmount'];
    $grandCount += $data['conversationaiCount'];
    $subaccountData[$locationId] = [
        'name'                 => $data['locationName'],
        'conversationaiAmount' => $data['conversationaiAmount'],
        'conversationaiCount'  => $data['conversationaiCount'],
        'monthlyData'          => $data['monthlyData'],
    ];
}

$globalMonthly = [];
foreach ($subaccountData as $data) {
    foreach ($data['monthlyData'] as $monthKey => $mdata) {
        if (!isset($globalMonthly[$monthKey]))
            $globalMonthly[$monthKey] = ['totalAmount' => 0, 'count' => 0, 'monthName' => date('F Y', strtotime($monthKey.'-01'))];
        $globalMonthly[$monthKey]['totalAmount'] += $mdata['totalAmount'];
        $globalMonthly[$monthKey]['count']       += $mdata['count'];
    }
}
krsort($globalMonthly);

$subaccountDataJson = json_encode($subaccountData, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$globalMonthlyJson  = json_encode($globalMonthly,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

if ($isDemo) { $initialTotal = $grandTotal; $initialCount = $grandCount; }
else {
    $d = $subaccountData[$targetLocationId] ?? null;
    if (!$d) { echo '<html><body><div style="text-align:center;padding:40px;"><h2>No valid data found.</h2></div></body></html>'; exit; }
    $initialTotal = $d['conversationaiAmount']; $initialCount = $d['conversationaiCount'];
}
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
    --bg:        #f5f4f1;
    --surface:   #faf9f7;
    --card:      #ffffff;
    --border:    #e8e5e0;
    --border2:   #d6d2cb;
    --accent:      #7c3aed;
    --accent-soft: rgba(124,58,237,0.08);
    --teal:        #0d8a7a;
    --teal-soft:   rgba(13,138,122,0.09);
    --amber:       #b45309;
    --amber-soft:  rgba(180,83,9,0.09);
    --blue:        #1e6fa8;
    --blue-soft:   rgba(30,111,168,0.09);
    --text:        #1a1916;
    --text2:       #6b6760;
    --text3:       #b0aca5;
    --r: 20px; --r-sm: 12px; --r-xs: 8px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.06),0 1px 2px rgba(0,0,0,0.04);
    --shadow-md: 0 4px 16px rgba(0,0,0,0.07),0 1px 4px rgba(0,0,0,0.04);
    --shadow-xl: 0 24px 60px rgba(0,0,0,0.12),0 4px 16px rgba(0,0,0,0.06);
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding-bottom:80px;line-height:1.6;font-size:15px;}
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;background:radial-gradient(ellipse 70% 50% at 10% 0%,rgba(124,58,237,0.04) 0%,transparent 60%),radial-gradient(ellipse 50% 40% at 90% 100%,rgba(13,138,122,0.03) 0%,transparent 60%);}
.wrap{max-width:1280px;margin:0 auto;padding:0 28px;position:relative;z-index:1;}

/* TOPBAR */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:32px 0 28px;margin-bottom:24px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:16px;}
.brand{display:flex;align-items:center;gap:16px;}
.brand-icon{width:48px;height:48px;border-radius:14px;background:var(--accent);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(124,58,237,0.25);flex-shrink:0;}
.brand-icon svg{width:22px;height:22px;}
.brand-name{font-family:'DM Serif Display',serif;font-size:22px;color:var(--text);letter-spacing:-0.3px;}
.brand-sub{font-size:12.5px;color:var(--text2);font-weight:400;margin-top:1px;}
.myr-ctl{display:flex;align-items:center;gap:12px;background:var(--card);border:1px solid var(--border);border-radius:var(--r-sm);padding:10px 18px;box-shadow:var(--shadow-sm);}
.myr-lbl{font-size:13px;font-weight:500;color:var(--text2);}
.tog{position:relative;width:44px;height:24px;cursor:pointer;flex-shrink:0;}
.tog input{opacity:0;width:0;height:0;}
.tog-track{position:absolute;inset:0;background:var(--border2);border-radius:999px;transition:.25s;}
.tog-track::before{content:'';position:absolute;width:18px;height:18px;border-radius:50%;background:#fff;top:3px;left:3px;transition:.25s;box-shadow:0 1px 4px rgba(0,0,0,0.18);}
.tog input:checked+.tog-track{background:var(--teal);}
.tog input:checked+.tog-track::before{transform:translateX(20px);}
.rate-row{display:none;align-items:center;gap:6px;}
.rate-row.on{display:flex;}
.rate-lbl2{font-size:12px;color:var(--text2);font-weight:500;}
.rate-inp{width:68px;padding:5px 10px;background:var(--teal-soft);border:1px solid rgba(13,138,122,0.25);border-radius:var(--r-xs);font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;color:var(--teal);text-align:right;outline:none;transition:.2s;}
.rate-inp:focus{border-color:var(--teal);box-shadow:0 0 0 3px rgba(13,138,122,0.12);}

/* FILTERS */
.filters{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;}
.flt-box{flex:1;min-width:200px;max-width:320px;position:relative;}
.flt-box svg{position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--text3);width:14px;height:14px;}
.flt{width:100%;padding:11px 40px 11px 16px;font-size:13.5px;font-family:'DM Sans',sans-serif;font-weight:500;color:var(--text);background:var(--card);border:1px solid var(--border);border-radius:var(--r-sm);cursor:pointer;appearance:none;outline:none;transition:.2s;box-shadow:var(--shadow-sm);}
.flt:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);}

/* KPIs */
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:24px;position:relative;overflow:hidden;box-shadow:var(--shadow-sm);transition:transform .2s,box-shadow .2s;animation:fadeUp .5s ease both;}
.kpi:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
.kpi-accent-bar{position:absolute;top:0;left:24px;right:24px;height:3px;border-radius:0 0 4px 4px;background:var(--ka,var(--accent));opacity:.7;}
.kpi-ico{width:40px;height:40px;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;margin-bottom:14px;background:var(--kibg,var(--accent-soft));}
.kpi-ico svg{width:18px;height:18px;}
.kpi-lbl{font-size:11px;font-weight:600;letter-spacing:.9px;text-transform:uppercase;color:var(--text2);margin-bottom:5px;}
.kpi-val{font-family:'DM Serif Display',serif;font-size:28px;letter-spacing:-.5px;color:var(--text);line-height:1;}
.kpi-myr{font-size:13px;font-weight:600;color:var(--teal);margin-top:5px;display:none;}
.kpi-myr.on{display:block;}
.kpi-sub{font-size:12.5px;color:var(--text2);margin-top:7px;font-weight:400;}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.kpi:nth-child(1){animation-delay:.05s}.kpi:nth-child(2){animation-delay:.1s}.kpi:nth-child(3){animation-delay:.15s}.kpi:nth-child(4){animation-delay:.2s}

/* TABS */
.tabs-bar{display:flex;gap:4px;background:var(--card);border:1px solid var(--border);border-radius:var(--r-sm);padding:5px;margin-bottom:20px;box-shadow:var(--shadow-sm);width:fit-content;}
.tab-btn{padding:9px 22px;font-size:13.5px;font-weight:600;color:var(--text2);background:transparent;border:none;border-radius:9px;cursor:pointer;transition:background .18s,color .18s;white-space:nowrap;font-family:'DM Sans',sans-serif;}
.tab-btn:hover{color:var(--text);background:var(--bg);}
.tab-btn.active{background:var(--accent);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,0.3);}
.tab-pane{display:none;animation:fadeUp .3s ease both;}
.tab-pane.active{display:block;}

/* PANEL */
.panel{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:16px;}
.panel-hdr{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px;}
.panel-ttl{font-family:'DM Serif Display',serif;font-size:17px;color:var(--text);letter-spacing:-.2px;}
.chip{font-size:11px;font-weight:600;padding:4px 12px;border-radius:999px;background:var(--bg);color:var(--text2);border:1px solid var(--border);}
.panel-body{padding:20px 24px;}

/* SERVICE BARS */
.svc-item{margin-bottom:16px;}.svc-item:last-child{margin-bottom:0;}
.svc-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:7px;gap:12px;}
.svc-name{font-size:13.5px;font-weight:500;color:var(--text);}
.svc-r{text-align:right;flex-shrink:0;}
.svc-usd{font-size:13.5px;font-weight:700;color:var(--accent);display:block;}
.svc-myr{font-size:11px;font-weight:600;color:var(--teal);display:none;margin-top:2px;}
.svc-myr.on{display:block;}
.svc-pct{font-size:11px;color:var(--text3);margin-top:2px;display:block;}
.bar-track{height:6px;background:var(--bg);border-radius:999px;overflow:hidden;border:1px solid var(--border);}
.bar-fill{height:100%;border-radius:999px;background:var(--accent);opacity:.7;transition:width .7s cubic-bezier(.22,1,.36,1);}
.bar-teal{background:var(--teal);}

/* TABLE */
.tbl-scroll{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
thead tr{background:var(--bg);border-bottom:1px solid var(--border);}
thead th{padding:13px 22px;font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);text-align:left;white-space:nowrap;}
th.r{text-align:right;}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--accent-soft);}
tbody td{padding:15px 22px;font-size:14px;vertical-align:middle;}
.td-r{text-align:right;}
.proj-name{font-weight:600;color:var(--text);font-size:14px;}
.proj-co{font-size:12px;color:var(--text2);margin-top:3px;}
.amt-usd{font-weight:700;color:var(--accent);font-variant-numeric:tabular-nums;}
.amt-myr{font-size:11.5px;color:var(--teal);display:none;font-weight:600;margin-top:3px;}
.amt-myr.on{display:block;}
.qty-val{color:var(--text2);font-weight:500;font-variant-numeric:tabular-nums;}
.rnk{width:30px;height:30px;border-radius:var(--r-xs);font-size:12px;font-weight:700;color:var(--text2);background:var(--bg);border:1px solid var(--border);display:inline-flex;align-items:center;justify-content:center;}
.rnk.top{background:var(--accent-soft);color:var(--accent);border-color:rgba(124,58,237,.2);}
.share-row{display:flex;align-items:center;gap:10px;}
.share-bar{flex:1;height:5px;background:var(--bg);border-radius:999px;overflow:hidden;min-width:48px;border:1px solid var(--border);}
.share-fill{height:100%;background:var(--accent);opacity:.65;border-radius:999px;}
.share-pct{font-size:12px;color:var(--text2);font-weight:600;min-width:38px;text-align:right;}
.pill{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:600;padding:4px 11px;border-radius:999px;}
.pill-active{background:var(--accent-soft);color:var(--accent);border:1px solid rgba(124,58,237,.18);}
.pill-inactive{background:var(--bg);color:var(--text2);border:1px solid var(--border);}
.dot{width:7px;height:7px;border-radius:50%;}
.dot-active{background:var(--accent);}.dot-inactive{background:var(--border2);}

/* MONTHLY ACCORDION */
.mo-row{border-bottom:1px solid var(--border);}
.mo-row:last-child{border-bottom:none;}
.mo-hdr{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;cursor:pointer;transition:background .15s;user-select:none;flex-wrap:wrap;gap:12px;}
.mo-hdr:hover{background:var(--bg);}
.mo-row.open>.mo-hdr{background:var(--bg);}
.mo-l{display:flex;align-items:center;gap:16px;}
.mo-badge{width:50px;height:50px;border-radius:14px;background:var(--accent-soft);border:1px solid rgba(124,58,237,.15);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;}
.mo-badge-num{font-family:'DM Serif Display',serif;font-size:20px;line-height:1;color:var(--accent);}
.mo-badge-abbr{font-size:10px;font-weight:600;color:var(--accent);opacity:.6;text-transform:uppercase;}
.mo-name{font-size:15px;font-weight:600;color:var(--text);}
.mo-qty{font-size:12.5px;color:var(--text2);margin-top:2px;}
.mo-r{display:flex;align-items:center;gap:18px;}
.mo-amts{text-align:right;}
.mo-usd{font-size:17px;font-weight:700;color:var(--text);font-variant-numeric:tabular-nums;}
.mo-myr{font-size:12px;color:var(--teal);font-weight:600;display:none;margin-top:2px;}
.mo-myr.on{display:block;}
.chevron{width:20px;height:20px;color:var(--text3);transition:transform .25s;flex-shrink:0;}
.mo-row.open .chevron{transform:rotate(180deg);color:var(--text2);}
.mo-body{display:none;padding:4px 24px 20px;}
.mo-row.open .mo-body{display:block;}

/* TX CARDS in monthly */
.tx-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:8px;margin-top:8px;}
.tx-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;display:flex;justify-content:space-between;align-items:center;gap:12px;transition:background .15s,box-shadow .15s;}
.tx-card:hover{background:var(--card);box-shadow:var(--shadow-md);}
.tx-date{font-size:12.5px;color:var(--text2);font-weight:500;}
.tx-desc{font-size:12px;color:var(--text3);margin-top:2px;}
.tx-amt-usd{font-size:14px;font-weight:700;color:var(--accent);text-align:right;}
.tx-amt-myr{font-size:11px;color:var(--teal);font-weight:600;display:none;margin-top:2px;text-align:right;}
.tx-amt-myr.on{display:block;}

.empty-row{text-align:center;padding:48px 20px;color:var(--text3);font-size:14px;}

/* MODAL */
.modal{display:none;position:fixed;inset:0;background:rgba(26,25,22,.45);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:9999;align-items:center;justify-content:center;padding:24px;}
.modal.on{display:flex;}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:var(--r);width:100%;max-width:700px;max-height:88vh;overflow-y:auto;box-shadow:var(--shadow-xl);padding:32px;position:relative;animation:fadeUp .22s ease;}
.modal-close{position:absolute;top:20px;right:22px;width:34px;height:34px;border-radius:var(--r-xs);border:1px solid var(--border);background:var(--bg);color:var(--text2);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;line-height:1;}
.modal-close:hover{background:var(--accent-soft);border-color:rgba(124,58,237,.2);color:var(--accent);}
.modal-ttl{font-family:'DM Serif Display',serif;font-size:22px;color:var(--text);margin-bottom:4px;padding-right:40px;}
.modal-period{font-size:13px;color:var(--text2);margin-bottom:20px;}
.modal-total{display:inline-flex;align-items:baseline;gap:10px;background:var(--accent-soft);border:1px solid rgba(124,58,237,.15);padding:10px 18px;border-radius:var(--r-sm);margin-bottom:24px;}
.mt-usd{font-size:22px;font-weight:700;color:var(--accent);}
.mt-myr{font-size:14px;font-weight:600;color:var(--teal);display:none;}
.mt-myr.on{display:inline;}

::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:999px;}

@media(max-width:640px){
    .kpi-val{font-size:24px;}
    .brand-name{font-size:19px;}
    .topbar{padding:20px 0 18px;}
    .flt-box{max-width:100%;}
    th.hs,td.hs{display:none;}
    .tabs-bar{width:100%;}
    .tab-btn{flex:1;text-align:center;padding:9px 12px;}
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
                <path d="M18 8c-5.523 0-10 4.477-10 10s4.477 10 10 10 10-4.477 10-10S23.523 8 18 8zm0 4a2 2 0 110 4 2 2 0 010-4zm0 12c-3.314 0-6-1.343-6-3 0-1.657 2.686-3 6-3s6 1.343 6 3c0 1.657-2.686 3-6 3z" fill="white" opacity=".9"/>
            </svg>
        </div>
        <div>
            <div class="brand-name">Credits Dashboard</div>
            <div class="brand-sub">Conversation &amp; Voice AI Spend</div>
        </div>
    </div>
    <div class="myr-ctl">
        <span class="myr-lbl">Show MYR</span>
        <label class="tog"><input type="checkbox" id="myrTog"><span class="tog-track"></span></label>
        <div class="rate-row" id="rateRow">
            <span class="rate-lbl2">1 USD =</span>
            <input type="number" id="myrRate" class="rate-inp" value="3.92" min="1" step="0.01">
            <span class="rate-lbl2">MYR</span>
        </div>
    </div>
</div>

<!-- FILTERS -->
<div class="filters">
    <div class="flt-box">
        <select id="fltMonth" class="flt">
            <option value="">All Months</option>
            <?php foreach ($globalMonthly as $mKey => $m): ?>
            <option value="<?= htmlspecialchars($mKey) ?>"><?= htmlspecialchars($m['monthName']) ?></option>
            <?php endforeach; ?>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <?php if ($isDemo): ?>
    <div class="flt-box">
        <select id="fltSub" class="flt">
            <option value="">All Subaccounts</option>
            <?php foreach ($subaccountData as $locId => $d): ?>
            <option value="<?= htmlspecialchars($locId) ?>"><?= htmlspecialchars($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <?php endif; ?>
</div>

<!-- KPIs -->
<div class="kpis">
    <div class="kpi" style="--ka:var(--accent);--kibg:var(--accent-soft)">
        <div class="kpi-accent-bar"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg></div>
        <div class="kpi-lbl">Total Spend</div>
        <div class="kpi-val" id="kpiTotal">$<?= number_format($initialTotal,2) ?></div>
        <div class="kpi-myr" id="kpiTotalMyr" data-usd="<?= $initialTotal ?>">RM <?= number_format($initialTotal*3.92,2) ?></div>
        <div class="kpi-sub" id="kpiCountSub"><?= number_format($initialCount) ?> AI interactions</div>
    </div>
    <div class="kpi" style="--ka:var(--teal);--kibg:var(--teal-soft)">
        <div class="kpi-accent-bar" style="background:var(--teal)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg></div>
        <div class="kpi-lbl">Subaccounts</div>
        <div class="kpi-val" id="kpiSubs"><?= count($subaccountData) ?></div>
        <div class="kpi-sub">tracked subaccounts</div>
    </div>
    <div class="kpi" style="--ka:var(--amber);--kibg:var(--amber-soft)">
        <div class="kpi-accent-bar" style="background:var(--amber)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div>
        <div class="kpi-lbl">Months</div>
        <div class="kpi-val"><?= count($globalMonthly) ?></div>
        <div class="kpi-sub">billing periods</div>
    </div>
    <div class="kpi" style="--ka:var(--blue);--kibg:var(--blue-soft)">
        <div class="kpi-accent-bar" style="background:var(--blue)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
        <div class="kpi-lbl">Transactions</div>
        <div class="kpi-val" id="kpiTx"><?= number_format($initialCount) ?></div>
        <div class="kpi-sub">total interactions</div>
    </div>
</div>

<!-- TABS -->
<div class="tabs-bar">
    <button class="tab-btn active" data-tab="overview">Overview</button>
    <?php if ($isDemo): ?><button class="tab-btn" data-tab="subaccounts">Subaccounts</button><?php endif; ?>
    <button class="tab-btn" data-tab="monthly">Monthly</button>
</div>

<!-- TAB: OVERVIEW -->
<div class="tab-pane active" id="tab-overview">
    <div class="panel">
        <div class="panel-hdr">
            <span class="panel-ttl">Monthly Spend Overview</span>
            <span class="chip" id="ovChip"><?= count($globalMonthly) ?> months</span>
        </div>
        <div class="panel-body" id="ovBars">
            <?php foreach ($globalMonthly as $mKey => $m):
                $lbl = date('M Y', strtotime($mKey.'-01'));
                $pct = $grandTotal > 0 ? round($m['totalAmount']/$grandTotal*100,1) : 0;
            ?>
            <div class="svc-item">
                <div class="svc-top">
                    <span class="svc-name"><?= htmlspecialchars($lbl) ?></span>
                    <span class="svc-r">
                        <span class="svc-usd">$<?= number_format($m['totalAmount'],2) ?></span>
                        <span class="svc-pct"><?= $m['count'] ?> txns &nbsp;·&nbsp; <?= $pct ?>%</span>
                        <span class="svc-myr" data-usd="<?= $m['totalAmount'] ?>">RM <?= number_format($m['totalAmount']*3.92,2) ?></span>
                    </span>
                </div>
                <div class="bar-track"><div class="bar-fill bar-teal" style="width:<?= $pct ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- TAB: SUBACCOUNTS -->
<?php if ($isDemo): ?>
<div class="tab-pane" id="tab-subaccounts">
    <div class="panel">
        <div class="panel-hdr">
            <span class="panel-ttl">Subaccounts</span>
            <span class="chip" id="subChip"><?= count($subaccountData) ?> subaccounts</span>
        </div>
        <div class="tbl-scroll">
            <table>
                <thead><tr>
                    <th style="width:52px;">#</th>
                    <th>Subaccount</th>
                    <th class="r">Total Spent</th>
                    <th class="r hs">Interactions</th>
                    <th class="r hs">Share</th>
                    <th class="hs">Status</th>
                </tr></thead>
                <tbody id="subTbody">
                <?php $rk=0; foreach ($subaccountData as $locId => $d): $rk++;
                    $active = $d['conversationaiAmount'] > 0; ?>
                <tr>
                    <td><span class="rnk <?= $rk<=3?'top':'' ?>"><?= $rk ?></span></td>
                    <td>
                        <div class="proj-name"><?= htmlspecialchars($d['name']) ?></div>
                        <div class="proj-co"><?= htmlspecialchars($locId) ?></div>
                    </td>
                    <td class="td-r">
                        <div class="amt-usd">$<?= number_format($d['conversationaiAmount'],2) ?></div>
                        <div class="amt-myr" data-usd="<?= $d['conversationaiAmount'] ?>">RM <?= number_format($d['conversationaiAmount']*3.92,2) ?></div>
                    </td>
                    <td class="td-r hs"><span class="qty-val"><?= number_format($d['conversationaiCount']) ?></span></td>
                    <td class="td-r hs">
                        <div class="share-row">
                            <div class="share-bar"><div class="share-fill" style="width:<?= $grandTotal>0?round($d['conversationaiAmount']/$grandTotal*100,1):0 ?>%"></div></div>
                            <span class="share-pct"><?= $grandTotal>0?round($d['conversationaiAmount']/$grandTotal*100,1):0 ?>%</span>
                        </div>
                    </td>
                    <td class="hs"><span class="pill <?= $active?'pill-active':'pill-inactive' ?>"><span class="dot <?= $active?'dot-active':'dot-inactive' ?>"></span><?= $active?'Active':'Inactive' ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- TAB: MONTHLY -->
<div class="tab-pane" id="tab-monthly">
    <div class="panel" style="overflow:hidden;">
        <div class="panel-hdr">
            <span class="panel-ttl">Monthly Breakdown</span>
            <span class="chip"><?= count($globalMonthly) ?> periods</span>
        </div>
        <?php $mi=0; foreach ($globalMonthly as $mKey => $m): $mi++;
            $mNum2 = (int)date('m', strtotime($mKey.'-01'));
            $mAbbr = date('M', strtotime($mKey.'-01'));
            $mLbl  = date('F Y', strtotime($mKey.'-01'));
        ?>
        <div class="mo-row <?= $mi===1?'open':'' ?>" data-month="<?= htmlspecialchars($mKey) ?>">
            <div class="mo-hdr">
                <div class="mo-l">
                    <div class="mo-badge">
                        <div class="mo-badge-num"><?= str_pad($mNum2,2,'0',STR_PAD_LEFT) ?></div>
                        <div class="mo-badge-abbr"><?= $mAbbr ?></div>
                    </div>
                    <div>
                        <div class="mo-name"><?= htmlspecialchars($mLbl) ?></div>
                        <div class="mo-qty" id="mqty_<?= str_replace('-','_',$mKey) ?>"><?= number_format($m['count']) ?> interactions</div>
                    </div>
                </div>
                <div class="mo-r">
                    <div class="mo-amts">
                        <div class="mo-usd" id="musd_<?= str_replace('-','_',$mKey) ?>">$<?= number_format($m['totalAmount'],2) ?></div>
                        <div class="mo-myr" data-usd="<?= $m['totalAmount'] ?>">RM <?= number_format($m['totalAmount']*3.92,2) ?></div>
                    </div>
                    <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>
            <div class="mo-body" id="mb_<?= str_replace('-','_',$mKey) ?>"></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- /wrap -->

<!-- MODAL -->
<div id="modal" class="modal">
    <div class="modal-box">
        <button id="modalClose" class="modal-close">&times;</button>
        <div id="modalTtl" class="modal-ttl"></div>
        <div id="modalPeriod" class="modal-period"></div>
        <div class="modal-total">
            <span id="mtUsd" class="mt-usd"></span>
            <span id="mtMyr" class="mt-myr"></span>
        </div>
        <div class="tbl-scroll">
            <table>
                <thead><tr>
                    <th>Date</th>
                    <th class="r">Amount</th>
                    <th class="hs">Description</th>
                </tr></thead>
                <tbody id="modalTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const allData   = <?= $subaccountDataJson ?>;
    const allMonths = <?= $globalMonthlyJson ?>;
    const isDemo    = <?= json_encode($isDemo) ?>;
    const singleId  = <?= json_encode($targetLocationId) ?>;

    let showMyr = false, myrRate = 3.92;
    let selMonth = '', selSub = isDemo ? '' : singleId;

    const fU = v => '$' + Number(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const fM = v => 'RM ' + (Number(v)*myrRate).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    // ── MYR ──
    const myrTog = document.getElementById('myrTog');
    const rateRow = document.getElementById('rateRow');
    const myrRateEl = document.getElementById('myrRate');

    function applyMyr() {
        const km = document.getElementById('kpiTotalMyr');
        if (km) { km.textContent = fM(km.dataset.usd); km.classList.toggle('on', showMyr); }
        document.querySelectorAll('.amt-myr,.svc-myr,.mo-myr,.tx-amt-myr,.mt-myr').forEach(e => {
            if (e.dataset.usd !== undefined) e.textContent = fM(e.dataset.usd);
            e.classList.toggle('on', showMyr);
        });
    }
    myrTog.addEventListener('change', () => { showMyr = myrTog.checked; rateRow.classList.toggle('on', showMyr); applyMyr(); });
    myrRateEl.addEventListener('input', () => { myrRate = parseFloat(myrRateEl.value)||3.92; applyMyr(); updateKPIs(); });

    // ── TABS ──
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
            // rebuild open accordion in monthly tab
            if (btn.dataset.tab === 'monthly') {
                document.querySelectorAll('.mo-row.open').forEach(row => {
                    buildMonthBody(row.dataset.month, row.querySelector('.mo-body'));
                });
            }
        });
    });

    // ── FILTERS ──
    document.getElementById('fltMonth')?.addEventListener('change', e => { selMonth = e.target.value; updateAll(); });
    document.getElementById('fltSub')?.addEventListener('change',   e => { selSub   = e.target.value; updateAll(); });

    // ── COMPUTE FILTERED DATA ──
    function getFiltered() {
        let total = 0, count = 0;
        const subs = {};

        Object.entries(allData).forEach(([id, d]) => {
            if (selSub && id !== selSub) return;
            let amt, cnt;
            if (selMonth) {
                const m = d.monthlyData?.[selMonth];
                if (!m) return;
                amt = m.totalAmount; cnt = m.count;
            } else {
                amt = d.conversationaiAmount; cnt = d.conversationaiCount;
            }
            if (!amt) return;
            subs[id] = { ...d, fAmt: amt, fCnt: cnt };
            total += amt; count += cnt;
        });
        return { total, count, subs };
    }

    function updateKPIs() {
        const { total, count, subs } = getFiltered();
        document.getElementById('kpiTotal').textContent = fU(total);
        const km = document.getElementById('kpiTotalMyr');
        km.dataset.usd = total; km.textContent = fM(total); km.classList.toggle('on', showMyr);
        document.getElementById('kpiCountSub').textContent = count.toLocaleString() + ' AI interactions';
        document.getElementById('kpiTx').textContent = count.toLocaleString();
        document.getElementById('kpiSubs').textContent = Object.keys(subs).length;
        if (document.getElementById('subChip')) document.getElementById('subChip').textContent = Object.keys(subs).length + ' subaccounts';
    }

    function updateOverview() {
        const { total, subs } = getFiltered();
        // rebuild monthly bars from filtered data
        const monthTotals = {};
        Object.values(subs).forEach(d => {
            if (selMonth) {
                const m = d.monthlyData?.[selMonth]; if (!m) return;
                if (!monthTotals[selMonth]) monthTotals[selMonth] = { total:0, count:0 };
                monthTotals[selMonth].total += m.totalAmount;
                monthTotals[selMonth].count += m.count;
            } else {
                Object.entries(d.monthlyData||{}).forEach(([mk, m]) => {
                    if (!monthTotals[mk]) monthTotals[mk] = { total:0, count:0 };
                    monthTotals[mk].total += m.totalAmount;
                    monthTotals[mk].count += m.count;
                });
            }
        });
        const overallTotal = Object.values(monthTotals).reduce((s,m)=>s+m.total,0);
        const sorted = Object.entries(monthTotals).sort((a,b)=>b[0].localeCompare(a[0]));
        const c = document.getElementById('ovBars');
        if (!sorted.length) { c.innerHTML = '<div class="empty-row">No data for selected filters</div>'; return; }
        c.innerHTML = sorted.map(([mk, m]) => {
            const lbl = new Date(mk+'-02').toLocaleString('en-US',{month:'short',year:'numeric'});
            const pct = overallTotal > 0 ? (m.total/overallTotal*100).toFixed(1) : 0;
            return `<div class="svc-item">
                <div class="svc-top">
                    <span class="svc-name">${esc(lbl)}</span>
                    <span class="svc-r">
                        <span class="svc-usd">${fU(m.total)}</span>
                        <span class="svc-pct">${m.count} txns &nbsp;·&nbsp; ${pct}%</span>
                        <span class="svc-myr ${showMyr?'on':''}" data-usd="${m.total}">${fM(m.total)}</span>
                    </span>
                </div>
                <div class="bar-track"><div class="bar-fill bar-teal" style="width:${pct}%"></div></div>
            </div>`;
        }).join('');
        if (document.getElementById('ovChip')) document.getElementById('ovChip').textContent = sorted.length + ' months';
    }

    function updateSubTable() {
        const tbody = document.getElementById('subTbody');
        if (!tbody) return;
        const { total, subs } = getFiltered();
        const sorted = Object.entries(subs).sort((a,b)=>b[1].fAmt-a[1].fAmt);
        if (!sorted.length) { tbody.innerHTML = `<tr><td colspan="6"><div class="empty-row">No data for selected filters</div></td></tr>`; return; }
        tbody.innerHTML = sorted.map(([id,d],i) => {
            const share = total>0?(d.fAmt/total*100).toFixed(1):0;
            const active = d.fAmt > 0;
            return `<tr>
                <td><span class="rnk ${i<3?'top':''}">${i+1}</span></td>
                <td><div class="proj-name">${esc(d.name)}</div><div class="proj-co">${esc(id)}</div></td>
                <td class="td-r">
                    <div class="amt-usd">${fU(d.fAmt)}</div>
                    <div class="amt-myr ${showMyr?'on':''}" data-usd="${d.fAmt}">${fM(d.fAmt)}</div>
                </td>
                <td class="td-r hs"><span class="qty-val">${d.fCnt.toLocaleString()}</span></td>
                <td class="td-r hs"><div class="share-row"><div class="share-bar"><div class="share-fill" style="width:${share}%"></div></div><span class="share-pct">${share}%</span></div></td>
                <td class="hs"><span class="pill ${active?'pill-active':'pill-inactive'}"><span class="dot ${active?'dot-active':'dot-inactive'}"></span>${active?'Active':'Inactive'}</span></td>
            </tr>`;
        }).join('');
    }

    function updateMonthlyAccordion() {
        document.querySelectorAll('.mo-row.open').forEach(row => {
            buildMonthBody(row.dataset.month, row.querySelector('.mo-body'));
        });
    }

    function updateAll() { updateKPIs(); updateOverview(); updateSubTable(); updateMonthlyAccordion(); }

    // ── ACCORDION ──
    document.querySelectorAll('.mo-hdr').forEach(hdr => {
        hdr.addEventListener('click', () => {
            const row  = hdr.closest('.mo-row');
            const mKey = row.dataset.month;
            const body = row.querySelector('.mo-body');
            const wasOpen = row.classList.contains('open');
            row.classList.toggle('open');
            if (!wasOpen) buildMonthBody(mKey, body);
        });
    });

    // open first
    const first = document.querySelector('.mo-row.open');
    if (first) buildMonthBody(first.dataset.month, first.querySelector('.mo-body'));

    function buildMonthBody(mKey, container) {
        const txns = [];
        Object.entries(allData).forEach(([id, d]) => {
            if (selSub && id !== selSub) return;
            const m = d.monthlyData?.[mKey]; if (!m) return;
            m.transactions.forEach(tx => txns.push({ ...tx, subName: d.name }));
        });

        // Update header amount for this month
        const total = txns.reduce((s,t)=>s+t.amount,0);
        const usdEl = document.getElementById('musd_' + mKey.replace('-','_'));
        const qtyEl = document.getElementById('mqty_' + mKey.replace('-','_'));
        if (usdEl) usdEl.textContent = fU(total);
        if (qtyEl) qtyEl.textContent = txns.length.toLocaleString() + ' interactions';

        if (!txns.length) { container.innerHTML = '<div class="empty-row" style="padding:16px 0">No data for this period</div>'; return; }

        const sorted = [...txns].sort((a,b)=>b.amount-a.amount);
        container.innerHTML = `<div class="tx-grid">${sorted.map(tx => {
            const clean  = tx.date.replace(/(\d+)(st|nd|rd|th)/i,'$1').replace(/,/g,'');
            const ts = new Date(clean);
            const fd = isNaN(ts)?tx.date:ts.toLocaleString('en-US',{month:'short',day:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit',hour12:true});
            return `<div class="tx-card">
                <div>
                    <div class="tx-date">${esc(fd)}${isDemo?` <span style="color:var(--text3)">· ${esc(tx.subName)}</span>`:''}</div>
                    ${tx.description?`<div class="tx-desc">${esc(tx.description)}</div>`:''}
                </div>
                <div>
                    <div class="tx-amt-usd">${fU(tx.amount)}</div>
                    <div class="tx-amt-myr ${showMyr?'on':''}" data-usd="${tx.amount}">${fM(tx.amount)}</div>
                </div>
            </div>`;
        }).join('')}</div>`;
    }

    const modal = document.getElementById('modal');
    document.getElementById('modalClose').addEventListener('click', ()=>modal.classList.remove('on'));
    modal.addEventListener('click', e=>{ if(e.target===modal) modal.classList.remove('on'); });
});
</script>
</body>
</html>