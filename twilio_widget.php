<?php
/**
 * Twilio Usage Dashboard — Premium Edition
 * Groups by: Project (column 0)
 * CSV columns: Project, Company Name, Item Category, Item Group, Item Country,
 *              Item, Quantity, Amount (USD), Account Sid, Account Type,
 *              Parent SID, Month, Year, Invoice Number
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

            // Skip aggregate rows
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
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --bg:        #07101f;
    --surface:   #0b1628;
    --card:      #0f1e33;
    --card2:     #132540;
    --border:    rgba(255,255,255,0.07);
    --border2:   rgba(255,255,255,0.13);
    --red:       #f43f5e;
    --red-glow:  rgba(244,63,94,0.22);
    --red-dim:   rgba(244,63,94,0.1);
    --amber:     #fbbf24;
    --teal:      #2dd4bf;
    --teal-glow: rgba(45,212,191,0.15);
    --blue:      #38bdf8;
    --indigo:    #818cf8;
    --violet:    #a78bfa;
    --text:      #eef2ff;
    --text2:     #7a8fad;
    --text3:     #3d506a;
    --r:         16px;
}
*{margin:0;padding:0;box-sizing:border-box;}
html{scroll-behavior:smooth;}
body {
    font-family:'Plus Jakarta Sans',system-ui,sans-serif;
    background:var(--bg); color:var(--text);
    min-height:100vh; padding-bottom:80px; line-height:1.55;
}
body::before {
    content:''; position:fixed; inset:0; z-index:0; pointer-events:none;
    background:
        radial-gradient(ellipse 60% 40% at 5% 0%, rgba(244,63,94,0.08) 0%, transparent 55%),
        radial-gradient(ellipse 50% 40% at 95% 100%, rgba(56,189,248,0.07) 0%, transparent 55%),
        radial-gradient(ellipse 40% 30% at 50% 50%, rgba(129,140,248,0.04) 0%, transparent 60%);
}
.wrap{max-width:1260px;margin:0 auto;padding:0 24px;position:relative;z-index:1;}

/* ── TOPBAR ── */
.topbar {
    display:flex; align-items:center; justify-content:space-between;
    padding:24px 0 22px; border-bottom:1px solid var(--border);
    margin-bottom:36px; flex-wrap:wrap; gap:16px;
}
.brand{display:flex;align-items:center;gap:16px;}
.brand-icon {
    width:48px;height:48px;border-radius:14px;
    background:linear-gradient(135deg,#f43f5e 0%,#fb923c 100%);
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 0 30px rgba(244,63,94,0.45),0 4px 12px rgba(0,0,0,0.4);
    flex-shrink:0;
}
.brand-icon svg{width:24px;height:24px;}
.brand-name{font-family:'Syne',sans-serif;font-size:21px;font-weight:800;letter-spacing:-.4px;}
.brand-sub{font-size:12px;color:var(--text2);font-weight:500;margin-top:3px;}

/* MYR control */
.myr-ctl {
    display:flex;align-items:center;gap:12px;
    background:var(--card);border:1px solid var(--border2);
    border-radius:14px;padding:10px 20px;
}
.myr-lbl{font-size:13px;font-weight:600;color:var(--text2);}
.tog{position:relative;width:46px;height:26px;cursor:pointer;flex-shrink:0;}
.tog input{opacity:0;width:0;height:0;}
.tog-track{
    position:absolute;inset:0;background:rgba(255,255,255,0.07);
    border-radius:999px;border:1px solid var(--border2);transition:.25s;
}
.tog-track::before{
    content:'';position:absolute;width:20px;height:20px;border-radius:50%;
    background:#2d3f5a;top:2px;left:2px;
    transition:.25s;box-shadow:0 2px 6px rgba(0,0,0,0.5);
}
.tog input:checked+.tog-track{background:rgba(45,212,191,0.18);border-color:var(--teal);}
.tog input:checked+.tog-track::before{background:var(--teal);transform:translateX(20px);box-shadow:0 0 8px rgba(45,212,191,0.5);}
.rate-row{display:none;align-items:center;gap:6px;}
.rate-row.on{display:flex;}
.rate-lbl2{font-size:12px;color:var(--text2);font-weight:500;}
.rate-inp{
    width:68px;padding:5px 10px;
    background:rgba(45,212,191,0.07);border:1px solid rgba(45,212,191,0.3);
    border-radius:8px;font-family:'Plus Jakarta Sans',sans-serif;
    font-size:13px;font-weight:700;color:var(--teal);text-align:right;outline:none;
    transition:border-color .2s;
}
.rate-inp:focus{border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-glow);}

/* ── FILTERS ── */
.filters{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:30px;}
.flt-box{flex:1;min-width:180px;max-width:300px;position:relative;}
.flt-box svg{position:absolute;right:13px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--text3);width:15px;height:15px;}
.flt{
    width:100%;padding:12px 40px 12px 16px;
    font-size:13px;font-family:'Plus Jakarta Sans',sans-serif;
    font-weight:600;color:var(--text);
    background:var(--card);border:1px solid var(--border2);
    border-radius:12px;cursor:pointer;appearance:none;outline:none;transition:.2s;
}
.flt:focus{border-color:var(--red);box-shadow:0 0 0 3px var(--red-dim);}
.flt option{background:#0f1e33;}

/* ── KPIS ── */
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:28px;}
.kpi {
    background:var(--card);border:1px solid var(--border);
    border-radius:var(--r);padding:22px;position:relative;overflow:hidden;
    transition:transform .2s,border-color .2s,box-shadow .2s;
    animation:fadeUp .5s ease both;
}
.kpi:hover{transform:translateY(-4px);border-color:var(--border2);box-shadow:0 12px 40px rgba(0,0,0,0.3);}
.kpi::after{
    content:'';position:absolute;
    top:0;left:0;right:0;height:2px;
    background:var(--ka,linear-gradient(90deg,var(--red),var(--amber)));
}
.kpi::before{
    content:'';position:absolute;
    top:0;right:0;width:120px;height:120px;
    background:radial-gradient(circle, var(--kglow,rgba(244,63,94,0.07)) 0%, transparent 70%);
    pointer-events:none;
}
.kpi-ico{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;margin-bottom:14px;background:var(--kibg,rgba(244,63,94,0.1));}
.kpi-ico svg{width:17px;height:17px;}
.kpi-lbl{font-size:11px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text2);margin-bottom:6px;}
.kpi-val{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;letter-spacing:-.8px;color:var(--text);line-height:1;}
.kpi-myr{font-size:13px;font-weight:600;color:var(--teal);margin-top:4px;display:none;}
.kpi-myr.on{display:block;}
.kpi-sub{font-size:12px;color:var(--text2);margin-top:6px;font-weight:500;}

@keyframes fadeUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
.kpi:nth-child(1){animation-delay:.06s}.kpi:nth-child(2){animation-delay:.12s}
.kpi:nth-child(3){animation-delay:.18s}.kpi:nth-child(4){animation-delay:.24s}

/* ── 2-COL ── */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;}
@media(max-width:880px){.two-col{grid-template-columns:1fr;}}

/* ── PANEL ── */
.panel{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;animation:fadeUp .5s ease .28s both;}
.panel-hdr{
    display:flex;align-items:center;justify-content:space-between;
    padding:18px 22px 16px;border-bottom:1px solid var(--border);
    flex-wrap:wrap;gap:8px;
}
.panel-ttl{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;letter-spacing:-.1px;}
.chip{font-size:11px;font-weight:700;padding:3px 10px;border-radius:999px;background:rgba(255,255,255,0.06);color:var(--text2);}
.panel-body{padding:18px 22px;}

/* service bars */
.svc-item{margin-bottom:15px;}
.svc-item:last-child{margin-bottom:0;}
.svc-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:7px;gap:8px;}
.svc-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.3;}
.svc-r{text-align:right;flex-shrink:0;}
.svc-usd{font-size:13px;font-weight:700;color:var(--red);display:block;}
.svc-myr{font-size:11px;font-weight:600;color:var(--teal);display:none;}
.svc-myr.on{display:block;}
.svc-pct{font-size:11px;color:var(--text3);margin-top:1px;display:block;}
.bar-track{height:5px;background:rgba(255,255,255,0.06);border-radius:999px;overflow:hidden;}
.bar-fill{height:100%;border-radius:999px;background:linear-gradient(90deg,var(--red),var(--amber));transition:width .7s cubic-bezier(.22,1,.36,1);}
.bar-teal{background:linear-gradient(90deg,var(--teal),var(--blue));}

/* ── PROJECTS TABLE ── */
.big-panel{
    background:var(--card);border:1px solid var(--border);
    border-radius:var(--r);overflow:hidden;margin-bottom:18px;
    animation:fadeUp .5s ease .32s both;
}
.tbl-scroll{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
thead tr{background:rgba(255,255,255,0.03);border-bottom:1px solid var(--border);}
thead th{
    padding:12px 20px;font-size:11px;font-weight:700;letter-spacing:.8px;
    text-transform:uppercase;color:var(--text2);text-align:left;white-space:nowrap;
}
th.r{text-align:right;}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:rgba(244,63,94,0.05);}
tbody td{padding:15px 20px;font-size:14px;vertical-align:middle;}
.td-r{text-align:right;}
.proj-name{font-weight:700;color:var(--text);font-size:14px;}
.proj-co{font-size:11px;color:var(--text2);margin-top:2px;font-weight:500;}
.amt-usd{font-weight:700;color:var(--red);font-variant-numeric:tabular-nums;}
.amt-myr{font-size:11px;color:var(--teal);display:none;font-weight:600;margin-top:2px;}
.amt-myr.on{display:block;}
.qty-val{color:var(--text2);font-weight:600;font-variant-numeric:tabular-nums;}
.rnk{
    width:28px;height:28px;border-radius:8px;
    font-size:12px;font-weight:700;color:var(--text2);
    background:rgba(255,255,255,0.05);
    display:inline-flex;align-items:center;justify-content:center;
}
.rnk.top{background:rgba(244,63,94,0.15);color:var(--red);}
.type-tag{
    display:inline-flex;align-items:center;
    font-size:11px;font-weight:700;letter-spacing:.3px;
    padding:4px 10px;border-radius:999px;
}
.t-master{background:rgba(244,63,94,0.12);color:var(--red);border:1px solid rgba(244,63,94,0.25);}
.t-sub{background:rgba(45,212,191,0.1);color:var(--teal);border:1px solid rgba(45,212,191,0.2);}
.share-row{display:flex;align-items:center;gap:8px;}
.share-bar{flex:1;height:4px;background:rgba(255,255,255,0.07);border-radius:999px;overflow:hidden;min-width:40px;}
.share-fill{height:100%;background:linear-gradient(90deg,var(--red),var(--amber));border-radius:999px;}
.share-pct{font-size:12px;color:var(--text2);font-weight:600;min-width:36px;text-align:right;}

/* ── MONTHLY ACCORDION ── */
.months-panel{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;animation:fadeUp .5s ease .36s both;}
.mo-row{border-bottom:1px solid var(--border);}
.mo-row:last-child{border-bottom:none;}
.mo-hdr{
    display:flex;align-items:center;justify-content:space-between;
    padding:18px 22px;cursor:pointer;transition:background .15s;
    user-select:none;flex-wrap:wrap;gap:10px;
}
.mo-hdr:hover{background:rgba(255,255,255,0.02);}
.mo-l{display:flex;align-items:center;gap:16px;}
.mo-num{
    font-family:'Syne',sans-serif;font-size:32px;font-weight:800;
    letter-spacing:-1.5px;line-height:1;
    background:linear-gradient(135deg,var(--red),var(--amber));
    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
    background-clip:text;
    min-width:46px;
}
.mo-name{font-size:15px;font-weight:700;color:var(--text);}
.mo-qty{font-size:12px;color:var(--text2);font-weight:500;margin-top:2px;}
.mo-r{display:flex;align-items:center;gap:18px;}
.mo-amts{text-align:right;}
.mo-usd{font-size:17px;font-weight:800;color:var(--text);font-variant-numeric:tabular-nums;}
.mo-myr{font-size:12px;color:var(--teal);font-weight:600;display:none;margin-top:2px;}
.mo-myr.on{display:block;}
.chevron{width:20px;height:20px;color:var(--text3);transition:transform .25s;flex-shrink:0;}
.mo-row.open .chevron{transform:rotate(180deg);color:var(--text2);}
.mo-body{display:none;padding:4px 22px 20px;}
.mo-row.open .mo-body{display:block;}

/* month body service cards */
.svc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px;margin-top:8px;}
.svc-card {
    background:rgba(255,255,255,0.03);border:1px solid var(--border);
    border-radius:12px;padding:14px 16px;cursor:pointer;
    transition:background .15s,border-color .15s,transform .15s;
    display:flex;justify-content:space-between;align-items:flex-start;gap:10px;
}
.svc-card:hover{background:rgba(244,63,94,0.07);border-color:rgba(244,63,94,0.3);transform:translateY(-2px);}
.sc-dot{width:8px;height:8px;border-radius:50%;background:var(--red);margin-top:5px;flex-shrink:0;}
.sc-name{font-size:13px;font-weight:700;color:var(--text);}
.sc-qty{font-size:11px;color:var(--text2);font-weight:500;margin-top:2px;}
.sc-r{text-align:right;flex-shrink:0;}
.sc-usd{font-size:14px;font-weight:800;color:var(--red);}
.sc-myr{font-size:11px;color:var(--teal);font-weight:600;display:none;margin-top:2px;}
.sc-myr.on{display:block;}

/* ── MODAL ── */
.modal{
    display:none;position:fixed;inset:0;
    background:rgba(5,12,25,0.82);backdrop-filter:blur(8px);
    z-index:9999;align-items:center;justify-content:center;padding:24px;
}
.modal.on{display:flex;}
.modal-box{
    background:var(--card2);border:1px solid var(--border2);
    border-radius:20px;width:100%;max-width:800px;max-height:90vh;
    overflow-y:auto;box-shadow:0 30px 100px rgba(0,0,0,0.7);
    padding:30px;position:relative;animation:fadeUp .25s ease;
}
.modal-close{
    position:absolute;top:20px;right:22px;
    width:34px;height:34px;border-radius:10px;border:none;
    background:rgba(255,255,255,0.07);color:var(--text2);
    font-size:20px;cursor:pointer;display:flex;align-items:center;justify-content:center;
    transition:background .15s,color .15s;
}
.modal-close:hover{background:rgba(244,63,94,0.2);color:var(--red);}
.modal-ttl{font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:4px;}
.modal-period{font-size:13px;color:var(--text2);margin-bottom:20px;font-weight:500;}
.modal-total{
    display:inline-flex;align-items:baseline;gap:10px;
    background:rgba(244,63,94,0.1);border:1px solid rgba(244,63,94,0.2);
    padding:10px 18px;border-radius:12px;margin-bottom:22px;
}
.mt-usd{font-size:22px;font-weight:800;color:var(--red);}
.mt-myr{font-size:14px;font-weight:600;color:var(--teal);display:none;}
.mt-myr.on{display:inline;}
.mtbl thead tr{background:rgba(255,255,255,0.03);}
.mtbl tbody tr:hover{background:rgba(244,63,94,0.06);}
.item-n{font-weight:600;font-size:13px;color:var(--text);}
.item-c{font-size:12px;color:var(--text2);font-weight:500;}

.empty-row{text-align:center;padding:48px 20px;color:var(--text2);font-size:14px;font-weight:500;}

/* scrollbar */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:rgba(255,255,255,0.09);border-radius:999px;}
::-webkit-scrollbar-thumb:hover{background:rgba(255,255,255,0.16);}

@media(max-width:640px){
    .kpi-val{font-size:24px;} .mo-num{font-size:24px;}
    th.hs,td.hs{display:none;} .brand-name{font-size:17px;}
    .topbar{padding:16px 0 14px;} .svc-grid{grid-template-columns:1fr;}
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
                <circle cx="11" cy="11" r="5.5" fill="white" opacity=".95"/>
                <circle cx="25" cy="11" r="5.5" fill="white" opacity=".95"/>
                <circle cx="11" cy="25" r="5.5" fill="white" opacity=".95"/>
                <circle cx="25" cy="25" r="5.5" fill="white" opacity=".95"/>
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

<!-- FILTERS -->
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

<!-- KPIs -->
<div class="kpis">
    <div class="kpi" style="--ka:linear-gradient(90deg,var(--red),var(--amber));--kglow:rgba(244,63,94,0.08);--kibg:rgba(244,63,94,0.1)">
        <div class="kpi-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
        </div>
        <div class="kpi-lbl">Total Spend</div>
        <div class="kpi-val" id="kpiTotal">$<?= number_format($grand,2) ?></div>
        <div class="kpi-myr" id="kpiTotalMyr" data-usd="<?= $grand ?>">RM <?= number_format($grand*3.9,2) ?></div>
        <div class="kpi-sub" id="kpiQty"><?= number_format($grandQty) ?> usage units</div>
    </div>
    <div class="kpi" style="--ka:linear-gradient(90deg,var(--teal),var(--blue));--kglow:rgba(45,212,191,0.07);--kibg:rgba(45,212,191,0.1)">
        <div class="kpi-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>
        </div>
        <div class="kpi-lbl">Projects</div>
        <div class="kpi-val" id="kpiProjects"><?= count($byProject) ?></div>
        <div class="kpi-sub" id="kpiProjSub">tracked this period</div>
    </div>
    <div class="kpi" style="--ka:linear-gradient(90deg,var(--indigo),var(--violet));--kglow:rgba(129,140,248,0.07);--kibg:rgba(129,140,248,0.1)">
        <div class="kpi-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--indigo)" stroke-width="2"><path d="M4 6h16M4 12h8m-8 6h16"/></svg>
        </div>
        <div class="kpi-lbl">Services</div>
        <div class="kpi-val" id="kpiSvcs"><?= count($byService) ?></div>
        <div class="kpi-sub">service groups</div>
    </div>
    <div class="kpi" style="--ka:linear-gradient(90deg,var(--amber),#f97316);--kglow:rgba(245,158,11,0.07);--kibg:rgba(245,158,11,0.1)">
        <div class="kpi-ico">
            <svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
        </div>
        <div class="kpi-lbl">Months</div>
        <div class="kpi-val"><?= count($byMonth) ?></div>
        <div class="kpi-sub">billing periods</div>
    </div>
</div>

<!-- 2-COL: Service Bars + Monthly Mini -->
<div class="two-col">
    <div class="panel">
        <div class="panel-hdr">
            <span class="panel-ttl">Spend by Service</span>
            <span class="chip" id="svcChip"><?= count($byService) ?> services</span>
        </div>
        <div class="panel-body" id="svcBars">
            <?php $maxSvc = $byService ? max(array_column($byService,'total')) : 1;
            foreach ($byService as $grp => $sv):
                $pct = $grand > 0 ? round($sv['total']/$grand*100,1) : 0;
            ?>
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

    <div class="panel">
        <div class="panel-hdr">
            <span class="panel-ttl">Monthly Overview</span>
            <span class="chip"><?= count($byMonth) ?> months</span>
        </div>
        <div class="panel-body" id="monthMini">
            <?php foreach ($byMonth as $mKey => $m):
                $lbl = date('M Y', strtotime($mKey.'-01'));
                $pct2 = $grand>0?round($m['total']/$grand*100,1):0;
            ?>
            <div class="svc-item">
                <div class="svc-top">
                    <span class="svc-name"><?= htmlspecialchars($lbl) ?></span>
                    <span class="svc-r">
                        <span class="svc-usd">$<?= number_format($m['total'],2) ?></span>
                        <span class="svc-pct"><?= $pct2 ?>%</span>
                        <span class="svc-myr" data-usd="<?= $m['total'] ?>">RM <?= number_format($m['total']*3.9,2) ?></span>
                    </span>
                </div>
                <div class="bar-track"><div class="bar-fill bar-teal" style="width:<?= $pct2 ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- PROJECTS TABLE -->
<div class="big-panel">
    <div class="panel-hdr">
        <span class="panel-ttl">Projects</span>
        <span class="chip" id="projChip"><?= count($byProject) ?> projects</span>
    </div>
    <div class="tbl-scroll">
        <table>
            <thead>
                <tr>
                    <th style="width:48px;">#</th>
                    <th>Project</th>
                    <th class="r">Total Spent</th>
                    <th class="r hs">Units</th>
                    <th class="r hs">Share</th>
                    <th class="hs">Type</th>
                </tr>
            </thead>
            <tbody id="projTbody">
            <?php $rk=0; foreach ($byProject as $proj => $pd):
                $rk++;
                $share = $grand>0?round($pd['total']/$grand*100,1):0;
                $isMaster = stripos($pd['accType']??'','master')!==false;
            ?>
            <tr>
                <td><span class="rnk <?= $rk<=3?'top':'' ?>"><?= $rk ?></span></td>
                <td>
                    <div class="proj-name"><?= htmlspecialchars($proj) ?></div>
                    <?php if(!empty($pd['company'])&&$pd['company']!==$proj): ?>
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
                <td class="hs"><span class="type-tag <?= $isMaster?'t-master':'t-sub' ?>"><?= $isMaster?'Master':'Sub' ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MONTHLY ACCORDION -->
<div class="months-panel">
    <div class="panel-hdr">
        <span class="panel-ttl">Monthly Breakdown</span>
        <span class="chip"><?= count($byMonth) ?> periods</span>
    </div>
    <?php $mi=0; foreach ($byMonth as $mKey => $m):
        $mi++;
        $mNum2 = (int)date('m', strtotime($mKey.'-01'));
        $mLbl  = date('F Y', strtotime($mKey.'-01'));
    ?>
    <div class="mo-row <?= $mi===1?'open':'' ?>" data-month="<?= htmlspecialchars($mKey) ?>">
        <div class="mo-hdr">
            <div class="mo-l">
                <div class="mo-num"><?= str_pad($mNum2,2,'0',STR_PAD_LEFT) ?></div>
                <div>
                    <div class="mo-name"><?= htmlspecialchars($mLbl) ?></div>
                    <div class="mo-qty"><?= number_format($m['qty']) ?> usage units</div>
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
            <table class="mtbl">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="hs">Country</th>
                        <th class="r">Qty</th>
                        <th class="r">Amount</th>
                    </tr>
                </thead>
                <tbody id="modalTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const allProject = <?= $byProjectJson ?>;
    const allService  = <?= $byServiceJson ?>;
    const allMonth    = <?= $byMonthJson ?>;

    let showMyr = false, myrRate = 3.9;
    let selMonth='', selProject='', selService='';

    const fU = v => '$'+Number(v).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const fM = v => 'RM '+(Number(v)*myrRate).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    // MYR toggle
    const myrTog = document.getElementById('myrTog');
    const rateRow = document.getElementById('rateRow');
    const myrRateEl = document.getElementById('myrRate');

    function applyMyr() {
        const km = document.getElementById('kpiTotalMyr');
        km.textContent = fM(km.dataset.usd); km.classList.toggle('on',showMyr);
        document.querySelectorAll('.amt-myr').forEach(e=>{e.textContent=fM(e.dataset.usd);e.classList.toggle('on',showMyr);});
        document.querySelectorAll('.svc-myr').forEach(e=>{e.textContent=fM(e.dataset.usd);e.classList.toggle('on',showMyr);});
        document.querySelectorAll('.mo-myr').forEach(e=>{e.textContent=fM(e.dataset.usd);e.classList.toggle('on',showMyr);});
        document.querySelectorAll('.sc-myr').forEach(e=>{e.textContent=fM(e.dataset.usd);e.classList.toggle('on',showMyr);});
        const mm=document.getElementById('mtMyr');
        if(mm&&mm.dataset.usd){mm.textContent=fM(mm.dataset.usd);mm.classList.toggle('on',showMyr);}
        document.querySelectorAll('.modal-myr').forEach(e=>{e.textContent=fM(e.dataset.usd);e.classList.toggle('on',showMyr);});
    }

    myrTog.addEventListener('change',()=>{showMyr=myrTog.checked;rateRow.classList.toggle('on',showMyr);applyMyr();});
    myrRateEl.addEventListener('input',()=>{myrRate=parseFloat(myrRateEl.value)||3.9;applyMyr();updateKPIs();});

    // filtered data
    function getFiltered() {
        let total=0,qty=0;
        const projs={},svcs={};
        Object.entries(allProject).forEach(([proj,pd])=>{
            if(selProject&&proj!==selProject)return;
            let pt=0,pq=0;
            if(selMonth){
                const m=pd.monthly?.[selMonth];if(!m)return;
                if(selService){const s=m.services?.[selService];if(!s)return;pt=s.total;pq=s.qty;}
                else{pt=m.total;pq=m.qty;}
            }else{
                if(selService){const s=pd.services?.[selService];if(!s)return;pt=s.total;pq=s.qty;}
                else{pt=pd.total;pq=pd.qty;}
            }
            if(!pt)return;
            projs[proj]={...pd,ft:pt,fq:pq};
            total+=pt;qty+=pq;
        });
        Object.keys(allService).forEach(grp=>{
            if(selService&&grp!==selService)return;
            let st=0;
            Object.entries(allProject).forEach(([proj,pd])=>{
                if(selProject&&proj!==selProject)return;
                if(selMonth)st+=pd.monthly?.[selMonth]?.services?.[grp]?.total||0;
                else st+=pd.services?.[grp]?.total||0;
            });
            if(st>0)svcs[grp]=st;
        });
        return{total,qty,projs,svcs};
    }

    function updateKPIs(){
        const{total,qty,projs,svcs}=getFiltered();
        document.getElementById('kpiTotal').textContent=fU(total);
        const km=document.getElementById('kpiTotalMyr');
        km.dataset.usd=total;km.textContent=fM(total);km.classList.toggle('on',showMyr);
        document.getElementById('kpiQty').textContent=Number(qty).toLocaleString()+' usage units';
        document.getElementById('kpiProjects').textContent=Object.keys(projs).length;
        document.getElementById('kpiSvcs').textContent=Object.keys(svcs).length;
        document.getElementById('projChip').textContent=Object.keys(projs).length+' projects';
        document.getElementById('svcChip').textContent=Object.keys(svcs).length+' services';
    }

    function updateProjTable(){
        const{total,projs}=getFiltered();
        const tbody=document.getElementById('projTbody');
        const sorted=Object.entries(projs).sort((a,b)=>b[1].ft-a[1].ft);
        if(!sorted.length){
            tbody.innerHTML=`<tr><td colspan="6"><div class="empty-row">No data for selected filters</div></td></tr>`;
            return;
        }
        tbody.innerHTML=sorted.map(([proj,pd],i)=>{
            const share=total>0?(pd.ft/total*100).toFixed(1):0;
            const isMaster=(pd.accType||'').toLowerCase().includes('master');
            return`<tr>
                <td><span class="rnk ${i<3?'top':''}">${i+1}</span></td>
                <td>
                    <div class="proj-name">${esc(proj)}</div>
                    ${pd.company&&pd.company!==proj?`<div class="proj-co">${esc(pd.company)}</div>`:''}
                </td>
                <td class="td-r">
                    <div class="amt-usd">${fU(pd.ft)}</div>
                    <div class="amt-myr ${showMyr?'on':''}" data-usd="${pd.ft}">${fM(pd.ft)}</div>
                </td>
                <td class="td-r hs"><span class="qty-val">${Number(pd.fq).toLocaleString()}</span></td>
                <td class="td-r hs">
                    <div class="share-row">
                        <div class="share-bar"><div class="share-fill" style="width:${share}%"></div></div>
                        <span class="share-pct">${share}%</span>
                    </div>
                </td>
                <td class="hs"><span class="type-tag ${isMaster?'t-master':'t-sub'}">${isMaster?'Master':'Sub'}</span></td>
            </tr>`;
        }).join('');
    }

    function updateSvcBars(){
        const{total,svcs}=getFiltered();
        const c=document.getElementById('svcBars');
        if(!Object.keys(svcs).length){c.innerHTML='<div class="empty-row">No service data</div>';return;}
        const sorted=Object.entries(svcs).sort((a,b)=>b[1]-a[1]);
        c.innerHTML=sorted.map(([grp,amt])=>{
            const pct=total>0?(amt/total*100).toFixed(1):0;
            return`<div class="svc-item">
                <div class="svc-top">
                    <span class="svc-name">${esc(grp)}</span>
                    <span class="svc-r">
                        <span class="svc-usd">${fU(amt)}</span>
                        <span class="svc-pct">${pct}%</span>
                        <span class="svc-myr ${showMyr?'on':''}" data-usd="${amt}">${fM(amt)}</span>
                    </span>
                </div>
                <div class="bar-track"><div class="bar-fill" style="width:${pct}%"></div></div>
            </div>`;
        }).join('');
    }

    function updateMonthBodies(){
        document.querySelectorAll('.mo-row.open').forEach(row=>{
            const mKey=row.dataset.month;
            const body=row.querySelector('.mo-body');
            buildMonthBody(mKey,body);
        });
    }

    function updateAll(){updateKPIs();updateProjTable();updateSvcBars();updateMonthBodies();}

    document.getElementById('fltMonth').addEventListener('change',e=>{selMonth=e.target.value;updateAll();});
    document.getElementById('fltProject').addEventListener('change',e=>{selProject=e.target.value;updateAll();});
    document.getElementById('fltService').addEventListener('change',e=>{selService=e.target.value;updateAll();});

    // accordion
    document.querySelectorAll('.mo-hdr').forEach(hdr=>{
        hdr.addEventListener('click',()=>{
            const row=hdr.closest('.mo-row');
            const mKey=row.dataset.month;
            const body=row.querySelector('.mo-body');
            const wasOpen=row.classList.contains('open');
            row.classList.toggle('open');
            if(!wasOpen)buildMonthBody(mKey,body);
        });
    });

    // open first on load
    const first=document.querySelector('.mo-row.open');
    if(first)buildMonthBody(first.dataset.month,first.querySelector('.mo-body'));

    function buildMonthBody(mKey,container){
        const svcMap={};
        Object.entries(allProject).forEach(([proj,pd])=>{
            if(selProject&&proj!==selProject)return;
            const m=pd.monthly?.[mKey];if(!m)return;
            Object.entries(m.services||{}).forEach(([grp,sv])=>{
                if(selService&&grp!==selService)return;
                if(!svcMap[grp])svcMap[grp]={total:0,qty:0,items:[]};
                svcMap[grp].total+=sv.total;svcMap[grp].qty+=sv.qty;
                svcMap[grp].items.push(...(sv.items||[]));
            });
        });
        const sorted=Object.entries(svcMap).sort((a,b)=>b[1].total-a[1].total);
        if(!sorted.length){container.innerHTML='<div class="empty-row" style="padding:16px 0;">No data for this period</div>';return;}
        container.innerHTML=`<div class="svc-grid">${sorted.map(([grp,sv])=>`
            <div class="svc-card" data-grp="${esc(grp)}" data-month="${esc(mKey)}">
                <div style="display:flex;gap:10px;align-items:flex-start;">
                    <div class="sc-dot"></div>
                    <div>
                        <div class="sc-name">${esc(grp)}</div>
                        <div class="sc-qty">${Number(sv.qty).toLocaleString()} units</div>
                    </div>
                </div>
                <div class="sc-r">
                    <div class="sc-usd">${fU(sv.total)}</div>
                    <div class="sc-myr ${showMyr?'on':''}" data-usd="${sv.total}">${fM(sv.total)}</div>
                </div>
            </div>`).join('')}</div>`;

        container.querySelectorAll('.svc-card').forEach(card=>{
            card.addEventListener('click',()=>{
                const g=card.dataset.grp;
                showModal(g,mKey,svcMap[g].total,svcMap[g].items);
            });
        });
    }

    // modal
    function showModal(grp,mKey,totalAmt,items){
        const lbl=allMonth[mKey]?.label||mKey;
        document.getElementById('modalTtl').textContent=grp;
        document.getElementById('modalPeriod').textContent=lbl;
        const mu=document.getElementById('mtUsd');
        const mm=document.getElementById('mtMyr');
        mu.textContent=fU(totalAmt);
        mm.textContent=fM(totalAmt);mm.dataset.usd=totalAmt;mm.classList.toggle('on',showMyr);
        const tbody=document.getElementById('modalTbody');
        const sorted=[...items].sort((a,b)=>b.amount-a.amount);
        tbody.innerHTML=sorted.map(tx=>`<tr>
            <td><div class="item-n">${esc(tx.item)}</div></td>
            <td class="hs"><div class="item-c">${esc(tx.country)}</div></td>
            <td class="td-r"><span class="qty-val">${Number(tx.qty).toLocaleString()}</span></td>
            <td class="td-r">
                <div class="amt-usd" style="font-size:13px;">${fU(tx.amount)}</div>
                <div class="modal-myr amt-myr ${showMyr?'on':''}" data-usd="${tx.amount}" style="font-size:11px;">${fM(tx.amount)}</div>
            </td>
        </tr>`).join('');
        document.getElementById('modal').classList.add('on');
    }

    const modal=document.getElementById('modal');
    document.getElementById('modalClose').addEventListener('click',()=>modal.classList.remove('on'));
    modal.addEventListener('click',e=>{if(e.target===modal)modal.classList.remove('on');});

    updateAll();
});
</script>
</body>
</html>