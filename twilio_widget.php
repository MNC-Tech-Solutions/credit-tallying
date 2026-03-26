<?php
/**
 * Twilio Usage Dashboard — Refined Tabbed Edition
 * Groups by: Project (column 0)
 * CSV columns: Project, Company Name, Item Category, Item Group, Item Country,
 * Item, Quantity, Amount (USD), Account Sid, Account Type,
 * Parent SID, Month, Year, Invoice Number
 */

function getCsvFiles($dir) {
    if (!is_dir($dir)) return [];
    return glob($dir . '/*.csv') ?: [];
}
function parseAmt($r) { return floatval(preg_replace('/[^0-9.\-]/', '', $r)); }
function parseQty($r)  { return intval(str_replace(',', '', $r)); }

function processTwilioCSVs($files) {
    $byProject = [];
    $byService  = [];
    $byMonth    = [];
    $grand = 0; $grandQty = 0;

    foreach ($files as $fp) {
        if (!file_exists($fp)) continue;
        $f = fopen($fp, 'r');
        if (!$f) continue;
        $hdr = fgetcsv($f);
        if (!$hdr) { fclose($f); continue; }

        $map = array_flip(array_map(fn($c) => strtolower(trim($c)), $hdr));

        $iProject  = $map['project']        ?? false;
        $iCompany  = $map['company name']   ?? false;
        $iCategory = $map['item category']  ?? false;
        $iGroup    = $map['item group']     ?? false;
        $iCountry  = $map['item country']   ?? false;
        $iItem     = $map['item']           ?? false;
        $iQty      = $map['quantity']       ?? false;
        $iAmount   = $map['amount (usd)']   ?? $map['amount'] ?? false;
        $iAccSid   = $map['account sid']    ?? false;
        $iAccType  = $map['account type']   ?? false;
        $iMonth    = $map['month']          ?? false;
        $iYear     = $map['year']           ?? false;

        if ($iAmount === false) { fclose($f); continue; }

        while (($row = fgetcsv($f)) !== false) {
            $project = $iProject !== false ? trim($row[$iProject] ?? '') : '';
            $company = $iCompany !== false ? trim($row[$iCompany] ?? '') : '';

            if (stripos($project, 'All Accounts') !== false) continue;
            if ($project === '') continue;

            $group    = $iGroup   !== false ? trim($row[$iGroup]   ?? '') : 'Other';
            $item     = $iItem    !== false ? trim($row[$iItem]    ?? '') : '';
            $country  = $iCountry !== false ? trim($row[$iCountry] ?? '') : '';
            $accType  = $iAccType !== false ? trim($row[$iAccType] ?? '') : '';
            $mNum     = $iMonth   !== false ? trim($row[$iMonth]   ?? '') : '';
            $yNum     = $iYear    !== false ? trim($row[$iYear]    ?? '') : '';

            $amount   = parseAmt($row[$iAmount] ?? '0');
            $qty      = $iQty !== false ? parseQty($row[$iQty] ?? '0') : 0;
            $monthKey = ($mNum !== '' && $yNum !== '') ? sprintf('%04d-%02d', $yNum, $mNum) : '';

            if (!isset($byProject[$project])) {
                $byProject[$project] = [
                    'name' => $project, 'company' => $company, 'accType' => $accType,
                    'total' => 0, 'qty' => 0, 'monthly' => [], 'services' => [],
                ];
            }
            $p = &$byProject[$project];
            $p['total'] += $amount; $p['qty'] += $qty;
            if ($company) $p['company'] = $company;
            if ($accType) $p['accType'] = $accType;

            if (!isset($p['services'][$group]))
                $p['services'][$group] = ['total' => 0, 'qty' => 0];
            $p['services'][$group]['total'] += $amount;
            $p['services'][$group]['qty']   += $qty;

            if ($monthKey) {
                if (!isset($p['monthly'][$monthKey]))
                    $p['monthly'][$monthKey] = ['total' => 0, 'qty' => 0, 'services' => []];
                $p['monthly'][$monthKey]['total'] += $amount;
                $p['monthly'][$monthKey]['qty']   += $qty;
                if (!isset($p['monthly'][$monthKey]['services'][$group]))
                    $p['monthly'][$monthKey]['services'][$group] = ['total'=>0,'qty'=>0,'items'=>[]];
                $p['monthly'][$monthKey]['services'][$group]['total'] += $amount;
                $p['monthly'][$monthKey]['services'][$group]['qty']   += $qty;
                $p['monthly'][$monthKey]['services'][$group]['items'][] = [
                    'item' => $item, 'country' => $country, 'qty' => $qty, 'amount' => $amount,
                ];
            }

            if (!isset($byService[$group]))
                $byService[$group] = ['total' => 0, 'qty' => 0];
            $byService[$group]['total'] += $amount;
            $byService[$group]['qty']   += $qty;

            if ($monthKey) {
                if (!isset($byMonth[$monthKey]))
                    $byMonth[$monthKey] = ['total'=>0,'qty'=>0,'label'=>date('F Y', strtotime($monthKey.'-01'))];
                $byMonth[$monthKey]['total'] += $amount;
                $byMonth[$monthKey]['qty']   += $qty;
            }

            $grand += $amount; $grandQty += $qty;
        }
        fclose($f);
    }

    uasort($byProject, fn($a,$b) => $b['total'] <=> $a['total']);
    arsort($byService);
    krsort($byMonth);

    return compact('byProject','byService','byMonth','grand','grandQty');
}

$dir   = __DIR__ . '/twilio_csv';
$files = getCsvFiles($dir);
$data  = processTwilioCSVs($files);

$byProject  = $data['byProject'];
$byService  = $data['byService'];
$byMonth    = $data['byMonth'];
$grand      = $data['grand'];
$grandQty   = $data['grandQty'];

$byProjectJson = json_encode($byProject, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$byServiceJson = json_encode($byService, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$byMonthJson   = json_encode($byMonth,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Twilio Usage Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
:root {
    --bg:        #f5f4f1;
    --surface:   #faf9f7;
    --card:      #ffffff;
    --border:    #e8e5e0;
    --border2:   #d6d2cb;
    --accent:      #c0392b;
    --accent-soft: rgba(192,57,43,0.08);
    --accent-mid:  rgba(192,57,43,0.15);
    --teal:        #0d8a7a;
    --teal-soft:   rgba(13,138,122,0.09);
    --amber:       #b45309;
    --amber-soft:  rgba(180,83,9,0.09);
    --blue:        #1e6fa8;
    --blue-soft:   rgba(30,111,168,0.09);
    --text:        #1a1916;
    --text2:       #6b6760;
    --text3:       #b0aca5;
    --r:    20px;
    --r-sm: 12px;
    --r-xs: 8px;
    --shadow-sm:  0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
    --shadow-md:  0 4px 16px rgba(0,0,0,0.07), 0 1px 4px rgba(0,0,0,0.04);
    --shadow-lg:  0 12px 40px rgba(0,0,0,0.09), 0 2px 8px rgba(0,0,0,0.05);
    --shadow-xl:  0 24px 60px rgba(0,0,0,0.12), 0 4px 16px rgba(0,0,0,0.06);
}

* { margin:0; padding:0; box-sizing:border-box; }
html { scroll-behavior:smooth; }

body {
    font-family: 'DM Sans', system-ui, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding-bottom: 80px;
    line-height: 1.6;
    font-size: 15px;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background:
        radial-gradient(ellipse 70% 50% at 10% 0%, rgba(192,57,43,0.04) 0%, transparent 60%),
        radial-gradient(ellipse 50% 40% at 90% 100%, rgba(13,138,122,0.03) 0%, transparent 60%);
}

.wrap {
    max-width: 1280px;
    margin: 0 auto;
    padding: 0 28px;
    position: relative;
    z-index: 1;
}

/* TABS SYSTEM */
.tabs-nav {
    display: flex;
    gap: 12px;
    margin-bottom: 24px;
    border-bottom: 1px solid var(--border);
    padding-bottom: 4px;
}
.tab-btn {
    padding: 10px 24px;
    background: none;
    border: none;
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    font-weight: 600;
    color: var(--text2);
    cursor: pointer;
    border-radius: var(--r-xs);
    transition: all 0.2s;
    position: relative;
}
.tab-btn:hover { background: var(--surface); color: var(--text); }
.tab-btn.active {
    color: var(--accent);
    background: var(--accent-soft);
}
.tab-btn.active::after {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 15%;
    right: 15%;
    height: 3px;
    background: var(--accent);
    border-radius: 99px;
}
.tab-pane { display: none; animation: fadeIn 0.3s ease; }
.tab-pane.active { display: block; }

@keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

/* ── TOPBAR ── */
.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 32px 0 28px;
    margin-bottom: 32px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
    gap: 16px;
}
.brand { display: flex; align-items: center; gap: 16px; }
.brand-icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: var(--accent);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 16px rgba(192,57,43,0.25);
    flex-shrink: 0;
}
.brand-icon svg { width: 22px; height: 22px; }
.brand-name {
    font-family: 'DM Serif Display', serif;
    font-size: 22px;
    color: var(--text);
    letter-spacing: -0.3px;
}
.brand-sub {
    font-size: 12.5px;
    color: var(--text2);
    font-weight: 400;
    margin-top: 1px;
    letter-spacing: 0.2px;
}
.myr-ctl {
    display: flex;
    align-items: center;
    gap: 12px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--r-sm);
    padding: 10px 18px;
    box-shadow: var(--shadow-sm);
}
.myr-lbl { font-size: 13px; font-weight: 500; color: var(--text2); }
.tog { position: relative; width: 44px; height: 24px; cursor: pointer; flex-shrink: 0; }
.tog input { opacity: 0; width: 0; height: 0; }
.tog-track {
    position: absolute;
    inset: 0;
    background: var(--border2);
    border-radius: 999px;
    transition: .25s;
}
.tog-track::before {
    content: '';
    position: absolute;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    top: 3px;
    left: 3px;
    transition: .25s;
    box-shadow: 0 1px 4px rgba(0,0,0,0.18);
}
.tog input:checked + .tog-track { background: var(--teal); }
.tog input:checked + .tog-track::before { transform: translateX(20px); }
.rate-row { display: none; align-items: center; gap: 6px; }
.rate-row.on { display: flex; }
.rate-lbl2 { font-size: 12px; color: var(--text2); font-weight: 500; }
.rate-inp {
    width: 68px;
    padding: 5px 10px;
    background: var(--teal-soft);
    border: 1px solid rgba(13,138,122,0.25);
    border-radius: var(--r-xs);
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    font-weight: 600;
    color: var(--teal);
    text-align: right;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.rate-inp:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13,138,122,0.12); }

/* ── FILTERS ── */
.filters { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 28px; }
.flt-box { flex: 1; min-width: 200px; max-width: 320px; position: relative; }
.flt-box svg {
    position: absolute;
    right: 14px;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
    color: var(--text3);
    width: 14px;
    height: 14px;
}
.flt {
    width: 100%;
    padding: 11px 40px 11px 16px;
    font-size: 13.5px;
    font-family: 'DM Sans', sans-serif;
    font-weight: 500;
    color: var(--text);
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--r-sm);
    cursor: pointer;
    appearance: none;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
    box-shadow: var(--shadow-sm);
}
.flt:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }

/* ── KPIs ── */
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 16px; margin-bottom: 24px; }
.kpi {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--r);
    padding: 26px;
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: transform .2s, box-shadow .2s;
    animation: fadeUp .5s ease both;
}
.kpi:hover { transform: translateY(-3px); box-shadow: var(--shadow-md); }
.kpi-accent-bar {
    position: absolute;
    top: 0; left: 24px; right: 24px;
    height: 3px;
    border-radius: 0 0 4px 4px;
    background: var(--ka, var(--accent));
    opacity: 0.7;
}
.kpi-ico {
    width: 40px;
    height: 40px;
    border-radius: var(--r-sm);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
    background: var(--kibg, var(--accent-soft));
}
.kpi-ico svg { width: 18px; height: 18px; }
.kpi-lbl {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.9px;
    text-transform: uppercase;
    color: var(--text2);
    margin-bottom: 6px;
}
.kpi-val {
    font-family: 'DM Serif Display', serif;
    font-size: 30px;
    letter-spacing: -0.5px;
    color: var(--text);
    line-height: 1;
}
.kpi-myr { font-size: 13px; font-weight: 600; color: var(--teal); margin-top: 5px; display: none; }
.kpi-myr.on { display: block; }
.kpi-sub { font-size: 12.5px; color: var(--text2); margin-top: 8px; font-weight: 400; }

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── PANELS & TABLES ── */
.two-col { display: grid; grid-template-columns: 1fr; gap: 16px; margin-bottom: 16px; }
@media(max-width: 860px) { .two-col { grid-template-columns: 1fr; } }

.panel, .big-panel, .months-panel {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--r);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    margin-bottom: 16px;
}
.panel-hdr {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px 24px 18px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
    gap: 8px;
}
.panel-ttl {
    font-family: 'DM Serif Display', serif;
    font-size: 17px;
    color: var(--text);
    letter-spacing: -0.2px;
}
.chip {
    font-size: 11px;
    font-weight: 600;
    padding: 4px 12px;
    border-radius: 999px;
    background: var(--bg);
    color: var(--text2);
    border: 1px solid var(--border);
}
.panel-body { padding: 20px 24px; }

/* Existing detail styles kept exactly as per user prompt */
.svc-item { margin-bottom: 18px; }
.svc-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; gap: 12px; }
.svc-name { font-size: 13.5px; font-weight: 500; color: var(--text); }
.svc-usd { font-size: 13.5px; font-weight: 700; color: var(--accent); }
.svc-myr { font-size: 11px; font-weight: 600; color: var(--teal); display: none; margin-top: 2px; }
.svc-myr.on { display: block; }
.svc-pct { font-size: 11px; color: var(--text3); display: block; }
.bar-track { height: 6px; background: var(--bg); border-radius: 999px; overflow: hidden; border: 1px solid var(--border); }
.bar-fill { height: 100%; border-radius: 999px; background: var(--accent); opacity: 0.7; transition: width .7s ease; }
.bar-teal { background: var(--teal); }

.tbl-scroll { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead tr { background: var(--bg); border-bottom: 1px solid var(--border); }
thead th { padding: 13px 22px; font-size: 11px; font-weight: 600; letter-spacing: 0.7px; text-transform: uppercase; color: var(--text2); text-align: left; }
th.r { text-align: right; }
tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
tbody tr:hover { background: var(--accent-soft); }
tbody td { padding: 16px 22px; font-size: 14px; vertical-align: middle; }
.td-r { text-align: right; }
.proj-name { font-weight: 600; color: var(--text); }
.proj-co { font-size: 12px; color: var(--text2); }
.amt-usd { font-weight: 700; color: var(--accent); }
.amt-myr { font-size: 11.5px; color: var(--teal); display: none; font-weight: 600; }
.amt-myr.on { display: block; }
.rnk { width: 30px; height: 30px; border-radius: var(--r-xs); font-size: 12px; font-weight: 700; color: var(--text2); background: var(--bg); border: 1px solid var(--border); display: inline-flex; align-items: center; justify-content: center; }
.rnk.top { background: var(--accent-soft); color: var(--accent); border-color: rgba(192,57,43,0.2); }
.type-tag { display: inline-flex; padding: 4px 11px; border-radius: 999px; font-size: 11px; font-weight: 600; }
.t-master { background: var(--accent-soft); color: var(--accent); }
.t-sub { background: var(--teal-soft); color: var(--teal); }
.share-row { display: flex; align-items: center; gap: 10px; }
.share-bar { flex: 1; height: 5px; background: var(--bg); border-radius: 999px; overflow: hidden; border: 1px solid var(--border); }
.share-fill { height: 100%; background: var(--accent); opacity: 0.65; }

.mo-row { border-bottom: 1px solid var(--border); }
.mo-hdr { display: flex; align-items: center; justify-content: space-between; padding: 20px 26px; cursor: pointer; }
.mo-l { display: flex; align-items: center; gap: 18px; }
.mo-badge { width: 52px; height: 52px; border-radius: 14px; background: var(--accent-soft); border: 1px solid rgba(192,57,43,0.15); display: flex; flex-direction: column; align-items: center; justify-content: center; }
.mo-badge-num { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--accent); line-height: 1; }
.mo-badge-abbr { font-size: 10px; font-weight: 600; color: var(--accent); opacity: 0.6; }
.mo-name { font-size: 15px; font-weight: 600; color: var(--text); }
.mo-amts { text-align: right; }
.mo-usd { font-size: 18px; font-weight: 700; }
.mo-myr { font-size: 12px; color: var(--teal); font-weight: 600; display: none; }
.mo-myr.on { display: block; }
.chevron { width: 20px; height: 20px; color: var(--text3); transition: transform .25s; }
.mo-row.open .chevron { transform: rotate(180deg); }
.mo-body { display: none; padding: 6px 26px 22px; }
.mo-row.open .mo-body { display: block; }
.svc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; margin-top: 10px; }
.svc-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r-sm); padding: 16px 18px; cursor: pointer; display: flex; justify-content: space-between; gap: 12px; }
.sc-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); margin-top: 6px; }
.sc-name { font-size: 13px; font-weight: 600; }
.sc-usd { font-size: 14px; font-weight: 700; color: var(--accent); }

/* MODAL */
.modal { display: none; position: fixed; inset: 0; background: rgba(26,25,22,0.45); backdrop-filter: blur(10px); z-index: 9999; align-items: center; justify-content: center; padding: 24px; }
.modal.on { display: flex; }
.modal-box { background: var(--card); border-radius: var(--r); width: 100%; max-width: 800px; max-height: 88vh; overflow-y: auto; padding: 32px; position: relative; }
.modal-close { position: absolute; top: 20px; right: 22px; width: 34px; height: 34px; cursor: pointer; border: 1px solid var(--border); background: var(--bg); border-radius: var(--r-xs); }
.modal-ttl { font-family: 'DM Serif Display', serif; font-size: 24px; margin-bottom: 4px; }
.modal-total { background: var(--accent-soft); padding: 10px 18px; border-radius: var(--r-sm); margin-bottom: 24px; display: inline-flex; align-items: baseline; gap: 10px; }
.mt-usd { font-size: 22px; font-weight: 700; color: var(--accent); }
.mt-myr { font-size: 14px; font-weight: 600; color: var(--teal); display: none; }
.mt-myr.on { display: inline; }

@media(max-width: 640px) {
    .kpi-val { font-size: 26px; }
    th.hs, td.hs { display: none; }
    .topbar { padding: 20px 0 18px; }
    .svc-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="wrap">

<div class="topbar">
    <div class="brand">
        <div class="brand-icon">
            <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="11" cy="11" r="5" fill="white" opacity=".9"/>
                <circle cx="25" cy="11" r="5" fill="white" opacity=".9"/>
                <circle cx="11" cy="25" r="5" fill="white" opacity=".9"/>
                <circle cx="25" cy="25" r="5" fill="white" opacity=".9"/>
            </svg>
        </div>
        <div>
            <div class="brand-name">Twilio Dashboard</div>
            <div class="brand-sub">Usage &amp; Spend Analytics</div>
        </div>
    </div>
    <div class="myr-ctl">
        <span class="myr-lbl">Show MYR</span>
        <label class="tog">
            <input type="checkbox" id="myrTog">
            <span class="tog-track"></span>
        </label>
        <div class="rate-row" id="rateRow">
            <span class="rate-lbl2">1 USD =</span>
            <input type="number" id="myrRate" class="rate-inp" value="3.9" min="1" step="0.01">
            <span class="rate-lbl2">MYR</span>
        </div>
    </div>
</div>

<div class="filters">
    <div class="flt-box">
        <select id="fltMonth" class="flt">
            <option value="">All Months</option>
            <?php foreach ($byMonth as $mKey => $m): ?>
            <option value="<?= htmlspecialchars($mKey) ?>"><?= htmlspecialchars($m['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="flt-box">
        <select id="fltProject" class="flt">
            <option value="">All Projects</option>
            <?php foreach ($byProject as $proj => $pd): ?>
            <option value="<?= htmlspecialchars($proj) ?>"><?= htmlspecialchars($proj) ?></option>
            <?php endforeach; ?>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <div class="flt-box">
        <select id="fltService" class="flt">
            <option value="">All Services</option>
            <?php foreach ($byService as $grp => $_): ?>
            <option value="<?= htmlspecialchars($grp) ?>"><?= htmlspecialchars($grp) ?></option>
            <?php endforeach; ?>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
</div>

<div class="kpis">
    <div class="kpi" style="--ka:var(--accent);--kibg:var(--accent-soft)">
        <div class="kpi-accent-bar"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>
        <div class="kpi-lbl">Total Spend</div>
        <div class="kpi-val" id="kpiTotal">$<?= number_format($grand,2) ?></div>
        <div class="kpi-myr" id="kpiTotalMyr" data-usd="<?= $grand ?>">RM <?= number_format($grand*3.9,2) ?></div>
        <div class="kpi-sub" id="kpiQty"><?= number_format($grandQty) ?> usage units</div>
    </div>
    <div class="kpi" style="--ka:var(--teal);--kibg:var(--teal-soft)">
        <div class="kpi-accent-bar" style="background:var(--teal)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg></div>
        <div class="kpi-lbl">Projects</div>
        <div class="kpi-val" id="kpiProjects"><?= count($byProject) ?></div>
        <div class="kpi-sub" id="kpiProjSub">tracked this period</div>
    </div>
    <div class="kpi" style="--ka:var(--blue);--kibg:var(--blue-soft)">
        <div class="kpi-accent-bar" style="background:var(--blue)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><path d="M4 6h16M4 12h8m-8 6h16"/></svg></div>
        <div class="kpi-lbl">Services</div>
        <div class="kpi-val" id="kpiSvcs"><?= count($byService) ?></div>
        <div class="kpi-sub">service groups</div>
    </div>
    <div class="kpi" style="--ka:var(--amber);--kibg:var(--amber-soft)">
        <div class="kpi-accent-bar" style="background:var(--amber)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div>
        <div class="kpi-lbl">Months</div>
        <div class="kpi-val"><?= count($byMonth) ?></div>
        <div class="kpi-sub">billing periods</div>
    </div>
</div>

<div class="tabs-nav">
    <button class="tab-btn active" onclick="openTab(event, 'tabOverview')">Overview</button>
    <button class="tab-btn" onclick="openTab(event, 'tabProjects')">Projects</button>
    <button class="tab-btn" onclick="openTab(event, 'tabHistory')">Billing History</button>
</div>

<div id="tabOverview" class="tab-pane active">
    <div class="two-col">
        <div class="panel">
            <div class="panel-hdr">
                <span class="panel-ttl">Spend by Service</span>
                <span class="chip" id="svcChip"><?= count($byService) ?> services</span>
            </div>
            <div class="panel-body" id="svcBars">
                <?php $maxSvc = $byService ? max(array_column($byService,'total')) : 1;
                foreach ($byService as $grp => $sv): 
                    $pct = $grand > 0 ? round($sv['total']/$grand*100,1) : 0; ?>
                <div class="svc-item" data-service="<?= htmlspecialchars($grp) ?>">
                    <div class="svc-top">
                        <span class="svc-name"><?= htmlspecialchars($grp) ?></span>
                        <span class="svc-r">
                            <span class="svc-usd">$<?= number_format($sv['total'],2) ?></span>
                            <span class="svc-pct"><?= $pct ?>%</span>
                            <span class="svc-myr" data-usd="<?= $sv['total'] ?>">RM <?= number_format($sv['total']*3.9,2) ?></span>
                        </span>
                    </div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div id="tabProjects" class="tab-pane">
    <div class="big-panel">
        <div class="panel-hdr">
            <span class="panel-ttl">Projects</span>
            <span class="chip" id="projChip"><?= count($byProject) ?> projects</span>
        </div>
        <div class="tbl-scroll">
            <table>
                <thead>
                    <tr>
                        <th style="width:52px;">#</th>
                        <th>Project</th>
                        <th class="r">Total Spent</th>
                        <th class="r hs">Units</th>
                        <th class="r hs">Share</th>
                        <th class="hs">Type</th>
                    </tr>
                </thead>
                <tbody id="projTbody">
                <?php $rk = 0; foreach ($byProject as $proj => $pd):
                    $rk++; $share = $grand > 0 ? round($pd['total']/$grand*100,1) : 0;
                    $isMaster = stripos($pd['accType']??'','master') !== false; ?>
                <tr>
                    <td><span class="rnk <?= $rk <= 3 ? 'top' : '' ?>"><?= $rk ?></span></td>
                    <td>
                        <div class="proj-name"><?= htmlspecialchars($proj) ?></div>
                        <?php if (!empty($pd['company']) && $pd['company'] !== $proj): ?>
                        <div class="proj-co"><?= htmlspecialchars($pd['company']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="td-r">
                        <div class="amt-usd">$<?= number_format($pd['total'],2) ?></div>
                        <div class="amt-myr" data-usd="<?= $pd['total'] ?>">RM <?= number_format($pd['total']*3.9,2) ?></div>
                    </td>
                    <td class="td-r hs"><span class="qty-val"><?= number_format($pd['qty']) ?></span></td>
                    <td class="td-r hs">
                        <div class="share-row">
                            <div class="share-bar"><div class="share-fill" style="width:<?= $share ?>%"></div></div>
                            <span class="share-pct"><?= $share ?>%</span>
                        </div>
                    </td>
                    <td class="hs"><span class="type-tag <?= $isMaster ? 't-master' : 't-sub' ?>"><?= $isMaster ? 'Master' : 'Sub' ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="tabHistory" class="tab-pane">
    <div class="months-panel">
        <div class="panel-hdr">
            <span class="panel-ttl">Monthly Breakdown</span>
            <span class="chip"><?= count($byMonth) ?> periods</span>
        </div>
        <?php $mi = 0; foreach ($byMonth as $mKey => $m):
            $mi++; $mNum2 = (int)date('m', strtotime($mKey.'-01'));
            $mAbbr = date('M', strtotime($mKey.'-01')); $mLbl = date('F Y', strtotime($mKey.'-01')); ?>
        <div class="mo-row <?= $mi === 1 ? 'open' : '' ?>" data-month="<?= htmlspecialchars($mKey) ?>">
            <div class="mo-hdr">
                <div class="mo-l">
                    <div class="mo-badge">
                        <div class="mo-badge-num"><?= str_pad($mNum2,2,'0',STR_PAD_LEFT) ?></div>
                        <div class="mo-badge-abbr"><?= $mAbbr ?></div>
                    </div>
                    <div>
                        <div class="mo-name"><?= htmlspecialchars($mLbl) ?></div>
                        <div class="mo-qty"><?= number_format($m['qty']) ?> units</div>
                    </div>
                </div>
                <div class="mo-r">
                    <div class="mo-amts">
                        <div class="mo-usd">$<?= number_format($m['total'],2) ?></div>
                        <div class="mo-myr" data-usd="<?= $m['total'] ?>">RM <?= number_format($m['total']*3.9,2) ?></div>
                    </div>
                    <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>
            <div class="mo-body" id="mb_<?= str_replace('-','_',$mKey) ?>"></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><div id="modal" class="modal">
    <div class="modal-box">
        <button id="modalClose" class="modal-close">&times;</button>
        <div id="modalTtl" class="modal-ttl"></div>
        <div id="modalPeriod" class="modal-period"></div>
        <div class="modal-total">
            <span id="mtUsd" class="mt-usd"></span>
            <span id="mtMyr" class="mt-myr"></span>
        </div>
        <div class="tbl-scroll">
            <table class="mtbl">
                <thead><tr><th>Item</th><th class="hs">Country</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead>
                <tbody id="modalTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Tab Handler
function openTab(evt, tabId) {
    let i, tabPane, tabBtn;
    tabPane = document.getElementsByClassName("tab-pane");
    for (i = 0; i < tabPane.length; i++) { tabPane[i].classList.remove("active"); }
    tabBtn = document.getElementsByClassName("tab-btn");
    for (i = 0; i < tabBtn.length; i++) { tabBtn[i].classList.remove("active"); }
    document.getElementById(tabId).classList.add("active");
    evt.currentTarget.classList.add("active");
}

document.addEventListener('DOMContentLoaded', () => {
    const allProject = <?= $byProjectJson ?>;
    const allService  = <?= $byServiceJson ?>;
    const allMonth    = <?= $byMonthJson ?>;

    let showMyr = false, myrRate = 3.9;
    let selMonth = '', selProject = '', selService = '';

    const fU = v => '$' + Number(v).toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
    const fM = v => 'RM ' + (Number(v) * myrRate).toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
    const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    const myrTog   = document.getElementById('myrTog');
    const rateRow  = document.getElementById('rateRow');
    const myrRateEl = document.getElementById('myrRate');

    function applyMyr() {
        const km = document.getElementById('kpiTotalMyr');
        km.textContent = fM(km.dataset.usd);
        km.classList.toggle('on', showMyr);

        document.querySelectorAll('.amt-myr, .svc-myr, .mo-myr, .sc-myr, .modal-myr').forEach(e => { 
            e.textContent = fM(e.dataset.usd); e.classList.toggle('on', showMyr); 
        });

        const mm = document.getElementById('mtMyr');
        if (mm && mm.dataset.usd) { mm.textContent = fM(mm.dataset.usd); mm.classList.toggle('on', showMyr); }
    }

    myrTog.addEventListener('change', () => { showMyr = myrTog.checked; rateRow.classList.toggle('on', showMyr); applyMyr(); });
    myrRateEl.addEventListener('input', () => { myrRate = parseFloat(myrRateEl.value) || 3.9; applyMyr(); updateKPIs(); });

    function getFiltered() {
        let total = 0, qty = 0;
        const projs = {}, svcs = {};
        Object.entries(allProject).forEach(([proj, pd]) => {
            if (selProject && proj !== selProject) return;
            let pt = 0, pq = 0;
            if (selMonth) {
                const m = pd.monthly?.[selMonth]; if (!m) return;
                if (selService) { const s = m.services?.[selService]; if (!s) return; pt = s.total; pq = s.qty; }
                else { pt = m.total; pq = m.qty; }
            } else {
                if (selService) { const s = pd.services?.[selService]; if (!s) return; pt = s.total; pq = s.qty; }
                else { pt = pd.total; pq = pd.qty; }
            }
            if (!pt) return;
            projs[proj] = { ...pd, ft: pt, fq: pq };
            total += pt; qty += pq;
        });
        Object.keys(allService).forEach(grp => {
            if (selService && grp !== selService) return;
            let st = 0;
            Object.entries(allProject).forEach(([proj, pd]) => {
                if (selProject && proj !== selProject) return;
                if (selMonth) st += pd.monthly?.[selMonth]?.services?.[grp]?.total || 0;
                else st += pd.services?.[grp]?.total || 0;
            });
            if (st > 0) svcs[grp] = st;
        });
        return { total, qty, projs, svcs };
    }

    function updateKPIs() {
        const { total, qty, projs, svcs } = getFiltered();
        document.getElementById('kpiTotal').textContent = fU(total);
        const km = document.getElementById('kpiTotalMyr');
        km.dataset.usd = total; km.textContent = fM(total); km.classList.toggle('on', showMyr);
        document.getElementById('kpiQty').textContent = Number(qty).toLocaleString() + ' usage units';
        document.getElementById('kpiProjects').textContent = Object.keys(projs).length;
        document.getElementById('kpiSvcs').textContent = Object.keys(svcs).length;
        document.getElementById('projChip').textContent = Object.keys(projs).length + ' projects';
        document.getElementById('svcChip').textContent = Object.keys(svcs).length + ' services';
    }

    function updateProjTable() {
        const { total, projs } = getFiltered();
        const tbody = document.getElementById('projTbody');
        const sorted = Object.entries(projs).sort((a,b) => b[1].ft - a[1].ft);
        if (!sorted.length) { tbody.innerHTML = `<tr><td colspan="6"><div class="empty-row">No data</div></td></tr>`; return; }
        tbody.innerHTML = sorted.map(([proj, pd], i) => {
            const share = total > 0 ? (pd.ft / total * 100).toFixed(1) : 0;
            const isMaster = (pd.accType || '').toLowerCase().includes('master');
            return `<tr><td><span class="rnk ${i < 3 ? 'top' : ''}">${i + 1}</span></td>
                <td><div class="proj-name">${esc(proj)}</div>${pd.company && pd.company !== proj ? `<div class="proj-co">${esc(pd.company)}</div>` : ''}</td>
                <td class="td-r"><div class="amt-usd">${fU(pd.ft)}</div><div class="amt-myr ${showMyr ? 'on' : ''}" data-usd="${pd.ft}">${fM(pd.ft)}</div></td>
                <td class="td-r hs"><span class="qty-val">${Number(pd.fq).toLocaleString()}</span></td>
                <td class="td-r hs"><div class="share-row"><div class="share-bar"><div class="share-fill" style="width:${share}%"></div></div><span class="share-pct">${share}%</span></div></td>
                <td class="hs"><span class="type-tag ${isMaster ? 't-master' : 't-sub'}">${isMaster ? 'Master' : 'Sub'}</span></td></tr>`;
        }).join('');
    }

    function updateSvcBars() {
        const { total, svcs } = getFiltered();
        const c = document.getElementById('svcBars');
        const sorted = Object.entries(svcs).sort((a,b) => b[1] - a[1]);
        if (!sorted.length) { c.innerHTML = '<div class="empty-row">No data</div>'; return; }
        c.innerHTML = sorted.map(([grp, amt]) => {
            const pct = total > 0 ? (amt / total * 100).toFixed(1) : 0;
            return `<div class="svc-item"><div class="svc-top"><span class="svc-name">${esc(grp)}</span><span class="svc-r"><span class="svc-usd">${fU(amt)}</span><span class="svc-pct">${pct}%</span><span class="svc-myr ${showMyr ? 'on' : ''}" data-usd="${amt}">${fM(amt)}</span></span></div>
                <div class="bar-track"><div class="bar-fill" style="width:${pct}%"></div></div></div>`;
        }).join('');
    }

    function buildMonthBody(mKey, container) {
        const svcMap = {};
        Object.entries(allProject).forEach(([proj, pd]) => {
            if (selProject && proj !== selProject) return;
            const m = pd.monthly?.[mKey]; if (!m) return;
            Object.entries(m.services || {}).forEach(([grp, sv]) => {
                if (selService && grp !== selService) return;
                if (!svcMap[grp]) svcMap[grp] = { total: 0, qty: 0, items: [] };
                svcMap[grp].total += sv.total; svcMap[grp].qty += sv.qty; svcMap[grp].items.push(...(sv.items || []));
            });
        });
        const sorted = Object.entries(svcMap).sort((a,b) => b[1].total - a[1].total);
        if (!sorted.length) { container.innerHTML = '<div class="empty-row">No data</div>'; return; }
        container.innerHTML = `<div class="svc-grid">${sorted.map(([grp, sv]) => `
            <div class="svc-card" data-grp="${esc(grp)}" data-month="${esc(mKey)}">
                <div style="display:flex;gap:10px;"><div class="sc-dot"></div><div><div class="sc-name">${esc(grp)}</div><div class="sc-qty">${Number(sv.qty).toLocaleString()} units</div></div></div>
                <div class="sc-r"><div class="sc-usd">${fU(sv.total)}</div><div class="sc-myr ${showMyr ? 'on' : ''}" data-usd="${sv.total}">${fM(sv.total)}</div></div>
            </div>`).join('')}</div>`;
        container.querySelectorAll('.svc-card').forEach(card => card.addEventListener('click', () => showModal(card.dataset.grp, mKey, svcMap[card.dataset.grp].total, svcMap[card.dataset.grp].items)));
    }

    function updateMonthBodies() { document.querySelectorAll('.mo-row.open').forEach(row => buildMonthBody(row.dataset.month, row.querySelector('.mo-body'))); }
    function updateAll() { updateKPIs(); updateProjTable(); updateSvcBars(); updateMonthBodies(); }

    document.getElementById('fltMonth').addEventListener('change', e => { selMonth = e.target.value; updateAll(); });
    document.getElementById('fltProject').addEventListener('change', e => { selProject = e.target.value; updateAll(); });
    document.getElementById('fltService').addEventListener('change', e => { selService = e.target.value; updateAll(); });

    document.querySelectorAll('.mo-hdr').forEach(hdr => {
        hdr.addEventListener('click', () => {
            const row = hdr.closest('.mo-row');
            const wasOpen = row.classList.contains('open');
            row.classList.toggle('open');
            if (!wasOpen) buildMonthBody(row.dataset.month, row.querySelector('.mo-body'));
        });
    });

    const first = document.querySelector('.mo-row.open');
    if (first) buildMonthBody(first.dataset.month, first.querySelector('.mo-body'));

    function showModal(grp, mKey, totalAmt, items) {
        document.getElementById('modalTtl').textContent = grp;
        document.getElementById('modalPeriod').textContent = allMonth[mKey]?.label || mKey;
        const mu = document.getElementById('mtUsd'), mm = document.getElementById('mtMyr');
        mu.textContent = fU(totalAmt); mm.textContent = fM(totalAmt); mm.dataset.usd = totalAmt; mm.classList.toggle('on', showMyr);
        const sorted = [...items].sort((a,b) => b.amount - a.amount);
        document.getElementById('modalTbody').innerHTML = sorted.map(tx => `<tr><td><div class="item-n">${esc(tx.item)}</div></td><td><div class="item-c">${esc(tx.country)}</div></td><td class="td-r"><span>${Number(tx.qty).toLocaleString()}</span></td><td class="td-r"><div class="amt-usd">${fU(tx.amount)}</div><div class="modal-myr amt-myr ${showMyr ? 'on' : ''}" data-usd="${tx.amount}">${fM(tx.amount)}</div></td></tr>`).join('');
        document.getElementById('modal').classList.add('on');
    }

    document.getElementById('modalClose').addEventListener('click', () => modal.classList.remove('on'));
    updateAll();
});
</script>
</body>
</html>