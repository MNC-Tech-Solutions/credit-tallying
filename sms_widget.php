<?php
function getCsvFiles($directory) {
    if (!is_dir($directory)) return [];
    $csv = glob($directory . '/*.csv') ?: [];
    $tsv = glob($directory . '/*.tsv') ?: [];
    $txt = glob($directory . '/*.txt') ?: [];
    return array_merge($csv, $tsv, $txt);
}

function detectDelimiter($filePath) {
    $f = fopen($filePath, 'r');
    if (!$f) return ',';
    $line = fgets($f);
    fclose($f);
    // count tabs vs commas in the header line
    $tabs   = substr_count($line, "\t");
    $commas = substr_count($line, ',');
    return $tabs > $commas ? "\t" : ',';
}

function parseSmsCSVs($files) {
    $messages   = [];
    $byMonth    = [];
    $byStatus   = [];
    $byCampaign = [];
    $byTelco    = [];

    foreach ($files as $fp) {
        if (!file_exists($fp)) continue;

        $delim = detectDelimiter($fp);

        $f = fopen($fp, 'r');
        if (!$f) continue;

        // strip BOM if present
        $bom = fread($f, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($f);

        $hdr = fgetcsv($f, 0, $delim);
        if (!$hdr) { fclose($f); continue; }

        // normalize header
        $map = [];
        foreach ($hdr as $i => $col) {
            $map[strtolower(trim($col))] = $i;
        }

        $iId      = $map['message id']        ?? false;
        $iDate    = $map['date/time']          ?? $map['date'] ?? $map['datetime'] ?? false;
        $iFrom    = $map['from']               ?? false;
        $iTo      = $map['send to']            ?? $map['to']   ?? false;
        $iEvent   = $map['event title']        ?? false;
        $iContent = $map['message content']    ?? $map['content'] ?? $map['message'] ?? false;
        $iSmsNum  = $map['no of sms']          ?? $map['sms count'] ?? false;
        $iCountry = $map['country']            ?? false;
        $iTelco   = $map['telco']              ?? false;
        $iStatus  = $map['message status']     ?? $map['status'] ?? false;
        $iReason  = $map['undelivered reason'] ?? $map['reason'] ?? false;
        $iEventId = $map['event id']           ?? false;

        if ($iStatus === false && $iDate === false) { fclose($f); continue; }

        while (($row = fgetcsv($f, 0, $delim)) !== false) {
            if (count($row) < 2) continue; // skip blank/malformed lines

            $msgId    = $iId      !== false ? trim($row[$iId]      ?? '') : '';
            $dateRaw  = $iDate    !== false ? trim($row[$iDate]    ?? '') : '';
            $from     = $iFrom    !== false ? trim($row[$iFrom]    ?? '') : '';
            $to       = $iTo      !== false ? trim($row[$iTo]      ?? '') : '';
            $event    = $iEvent   !== false ? trim($row[$iEvent]   ?? '') : '';
            $content  = $iContent !== false ? trim($row[$iContent] ?? '') : '';
            $smsNum   = $iSmsNum  !== false ? intval($row[$iSmsNum] ?? 1) : 1;
            $country  = $iCountry !== false ? trim($row[$iCountry] ?? '') : '';
            $telco    = $iTelco   !== false ? trim($row[$iTelco]   ?? '') : '';
            $status   = $iStatus  !== false ? strtoupper(trim($row[$iStatus] ?? '')) : 'UNKNOWN';
            $reason   = $iReason  !== false ? trim($row[$iReason]  ?? '') : '';
            $eventId  = $iEventId !== false ? trim($row[$iEventId] ?? '') : '';

            if ($msgId === '' && $dateRaw === '' && $status === 'UNKNOWN') continue;

            // campaign = event title if set, else first 60 chars of content
            $campaign = ($event !== '') ? $event : (strlen($content) > 60 ? substr($content, 0, 60).'…' : $content);
            $campaignKey = ($event !== '') ? $event : ($eventId !== '' ? 'ID:'.$eventId : $campaign);

            // parse month
            $monthKey = '';
            if ($dateRaw !== '') {
                $ts = strtotime($dateRaw);
                if ($ts) $monthKey = date('Y-m', $ts);
            }

            $messages[] = [
                'id'         => $msgId,
                'date'       => $dateRaw,
                'monthKey'   => $monthKey,
                'from'       => $from,
                'to'         => $to,
                'event'      => $event,
                'content'    => $content,
                'campaign'   => $campaign,
                'campaignKey'=> $campaignKey,
                'smsNum'     => $smsNum,
                'country'    => $country,
                'telco'      => $telco,
                'status'     => $status,
                'reason'     => $reason,
                'eventId'    => $eventId,
            ];

            // by status
            if (!isset($byStatus[$status])) $byStatus[$status] = ['count'=>0,'sms'=>0];
            $byStatus[$status]['count']++;
            $byStatus[$status]['sms'] += $smsNum;

            // by month
            if ($monthKey) {
                if (!isset($byMonth[$monthKey])) $byMonth[$monthKey] = ['count'=>0,'sms'=>0,'label'=>date('F Y', strtotime($monthKey.'-01'))];
                $byMonth[$monthKey]['count']++;
                $byMonth[$monthKey]['sms'] += $smsNum;
            }

            // by campaign
            if ($campaignKey !== '') {
                if (!isset($byCampaign[$campaignKey])) $byCampaign[$campaignKey] = ['label'=>$campaign,'count'=>0,'sms'=>0,'statuses'=>[],'eventId'=>$eventId];
                $byCampaign[$campaignKey]['count']++;
                $byCampaign[$campaignKey]['sms'] += $smsNum;
                if (!isset($byCampaign[$campaignKey]['statuses'][$status])) $byCampaign[$campaignKey]['statuses'][$status] = 0;
                $byCampaign[$campaignKey]['statuses'][$status]++;
            }

            // by telco
            if ($telco !== '') {
                if (!isset($byTelco[$telco])) $byTelco[$telco] = ['count'=>0,'sms'=>0];
                $byTelco[$telco]['count']++;
                $byTelco[$telco]['sms'] += $smsNum;
            }
        }
        fclose($f);
    }

    krsort($byMonth);
    arsort($byStatus);
    uasort($byCampaign, fn($a,$b) => $b['count'] <=> $a['count']);
    arsort($byTelco);

    return compact('messages','byMonth','byStatus','byCampaign','byTelco');
}

// Try multiple folder names in order — use whichever has files
$candidateDirs = [
    __DIR__ . '/sms_csv',
    __DIR__ . '/sms',
    __DIR__ . '/csv_files',
    __DIR__,
];
$files = [];
foreach ($candidateDirs as $candidate) {
    $found = getCsvFiles($candidate);
    if (!empty($found)) { $files = $found; break; }
}

$data       = parseSmsCSVs($files);
$messages   = $data['messages'];
$byMonth    = $data['byMonth'];
$byStatus   = $data['byStatus'];
$byCampaign = $data['byCampaign'];
$byTelco    = $data['byTelco'];

// Debug info shown when no data found
$debugInfo = [
    'php_dir'    => __DIR__,
    'files_found'=> $files,
    'row_count'  => count($messages),
];

$totalMessages = count($messages);
$totalSms      = array_sum(array_column($messages, 'smsNum'));
$delivered     = ($byStatus['DELIVERED']['count'] ?? 0) + ($byStatus['ACCEPTED']['count'] ?? 0);
$deliveryRate  = $totalMessages > 0 ? round($delivered / $totalMessages * 100, 1) : 0;

// status color map
$statusColors = [
    'DELIVERED'   => ['color'=>'#0d8a7a','bg'=>'rgba(13,138,122,0.1)','border'=>'rgba(13,138,122,0.2)'],
    'ACCEPTED'    => ['color'=>'#1e6fa8','bg'=>'rgba(30,111,168,0.1)','border'=>'rgba(30,111,168,0.2)'],
    'SENT'        => ['color'=>'#7c3aed','bg'=>'rgba(124,58,237,0.1)','border'=>'rgba(124,58,237,0.2)'],
    'UNDELIVERED' => ['color'=>'#c0392b','bg'=>'rgba(192,57,43,0.1)', 'border'=>'rgba(192,57,43,0.2)'],
    'FAILED'      => ['color'=>'#b45309','bg'=>'rgba(180,83,9,0.1)',  'border'=>'rgba(180,83,9,0.2)'],
    'PENDING'     => ['color'=>'#b45309','bg'=>'rgba(180,83,9,0.08)','border'=>'rgba(180,83,9,0.18)'],
    'REJECTED'    => ['color'=>'#6b6760','bg'=>'rgba(107,103,96,0.1)','border'=>'rgba(107,103,96,0.2)'],
];
$defaultColor = ['color'=>'#6b6760','bg'=>'rgba(107,103,96,0.1)','border'=>'rgba(107,103,96,0.2)'];

$messagesJson  = json_encode($messages,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$byMonthJson   = json_encode($byMonth,    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$byStatusJson  = json_encode($byStatus,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$byCampaignJson= json_encode($byCampaign, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$byTelcoJson   = json_encode($byTelco,    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$statusColorsJson = json_encode($statusColors, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SMS Status Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
:root {
    --bg:        #f5f4f1;
    --surface:   #faf9f7;
    --card:      #ffffff;
    --border:    #e8e5e0;
    --border2:   #d6d2cb;
    --accent:      #0d8a7a;
    --accent-soft: rgba(13,138,122,0.08);
    --accent-mid:  rgba(13,138,122,0.15);
    --teal:        #0d8a7a;
    --teal-soft:   rgba(13,138,122,0.09);
    --blue:        #1e6fa8;
    --blue-soft:   rgba(30,111,168,0.09);
    --amber:       #b45309;
    --amber-soft:  rgba(180,83,9,0.09);
    --red:         #c0392b;
    --red-soft:    rgba(192,57,43,0.08);
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
body::before{content:'';position:fixed;inset:0;z-index:0;pointer-events:none;
    background:radial-gradient(ellipse 70% 50% at 10% 0%,rgba(13,138,122,0.04) 0%,transparent 60%),
               radial-gradient(ellipse 50% 40% at 90% 100%,rgba(30,111,168,0.03) 0%,transparent 60%);}
.wrap{max-width:1280px;margin:0 auto;padding:0 28px;position:relative;z-index:1;}

/* ── TOPBAR ── */
.topbar{display:flex;align-items:center;justify-content:space-between;padding:32px 0 28px;margin-bottom:24px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:16px;}
.brand{display:flex;align-items:center;gap:16px;}
.brand-icon{width:48px;height:48px;border-radius:14px;background:var(--accent);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 16px rgba(13,138,122,0.28);flex-shrink:0;}
.brand-icon svg{width:22px;height:22px;}
.brand-name{font-family:'DM Serif Display',serif;font-size:22px;color:var(--text);letter-spacing:-0.3px;}
.brand-sub{font-size:12.5px;color:var(--text2);font-weight:400;margin-top:1px;}

/* ── FILTERS ── */
.filters{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;}
.flt-box{flex:1;min-width:180px;max-width:300px;position:relative;}
.flt-box.wide{max-width:420px;}
.flt-box svg{position:absolute;right:14px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--text3);width:14px;height:14px;}
.flt{width:100%;padding:11px 40px 11px 16px;font-size:13.5px;font-family:'DM Sans',sans-serif;font-weight:500;color:var(--text);background:var(--card);border:1px solid var(--border);border-radius:var(--r-sm);cursor:pointer;appearance:none;outline:none;transition:.2s;box-shadow:var(--shadow-sm);}
.flt:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);}

/* search input */
.search-box{flex:1;min-width:200px;max-width:380px;position:relative;}
.search-box svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--text3);width:15px;height:15px;}
.search-inp{width:100%;padding:11px 16px 11px 40px;font-size:13.5px;font-family:'DM Sans',sans-serif;font-weight:500;color:var(--text);background:var(--card);border:1px solid var(--border);border-radius:var(--r-sm);outline:none;transition:.2s;box-shadow:var(--shadow-sm);}
.search-inp:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft);}
.search-inp::placeholder{color:var(--text3);}

/* ── KPIs ── */
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:16px;margin-bottom:24px;}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:24px;position:relative;overflow:hidden;box-shadow:var(--shadow-sm);transition:transform .2s,box-shadow .2s;animation:fadeUp .5s ease both;}
.kpi:hover{transform:translateY(-3px);box-shadow:var(--shadow-md);}
.kpi-accent-bar{position:absolute;top:0;left:24px;right:24px;height:3px;border-radius:0 0 4px 4px;background:var(--ka,var(--accent));opacity:.7;}
.kpi-ico{width:40px;height:40px;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;margin-bottom:14px;background:var(--kibg,var(--accent-soft));}
.kpi-ico svg{width:18px;height:18px;}
.kpi-lbl{font-size:11px;font-weight:600;letter-spacing:.9px;text-transform:uppercase;color:var(--text2);margin-bottom:5px;}
.kpi-val{font-family:'DM Serif Display',serif;font-size:28px;letter-spacing:-.5px;color:var(--text);line-height:1;}
.kpi-sub{font-size:12.5px;color:var(--text2);margin-top:7px;font-weight:400;}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.kpi:nth-child(1){animation-delay:.05s}.kpi:nth-child(2){animation-delay:.1s}.kpi:nth-child(3){animation-delay:.15s}.kpi:nth-child(4){animation-delay:.2s}.kpi:nth-child(5){animation-delay:.25s}

/* ── TABS ── */
.tabs-bar{display:flex;gap:4px;background:var(--card);border:1px solid var(--border);border-radius:var(--r-sm);padding:5px;margin-bottom:20px;box-shadow:var(--shadow-sm);width:fit-content;}
.tab-btn{padding:9px 22px;font-size:13.5px;font-weight:600;color:var(--text2);background:transparent;border:none;border-radius:9px;cursor:pointer;transition:background .18s,color .18s;white-space:nowrap;font-family:'DM Sans',sans-serif;}
.tab-btn:hover{color:var(--text);background:var(--bg);}
.tab-btn.active{background:var(--accent);color:#fff;box-shadow:0 2px 8px rgba(13,138,122,0.3);}
.tab-pane{display:none;animation:fadeUp .3s ease both;}
.tab-pane.active{display:block;}

/* ── PANEL ── */
.panel{background:var(--card);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:16px;}
.panel-hdr{display:flex;align-items:center;justify-content:space-between;padding:20px 24px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px;}
.panel-ttl{font-family:'DM Serif Display',serif;font-size:17px;color:var(--text);letter-spacing:-.2px;}
.chip{font-size:11px;font-weight:600;padding:4px 12px;border-radius:999px;background:var(--bg);color:var(--text2);border:1px solid var(--border);}
.panel-body{padding:20px 24px;}

/* STATUS PILLS */
.st-pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;letter-spacing:.4px;padding:3px 10px;border-radius:999px;white-space:nowrap;}
.st-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;}

/* STATUS BARS */
.svc-item{margin-bottom:16px;}.svc-item:last-child{margin-bottom:0;}
.svc-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:7px;gap:12px;}
.svc-name{font-size:13.5px;font-weight:600;color:var(--text);}
.svc-r{text-align:right;flex-shrink:0;}
.svc-count{font-size:13.5px;font-weight:700;display:block;}
.svc-pct{font-size:11px;color:var(--text3);margin-top:2px;display:block;}
.bar-track{height:6px;background:var(--bg);border-radius:999px;overflow:hidden;border:1px solid var(--border);}
.bar-fill{height:100%;border-radius:999px;opacity:.75;transition:width .7s cubic-bezier(.22,1,.36,1);}

/* ── TABLE ── */
.tbl-scroll{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
thead tr{background:var(--bg);border-bottom:1px solid var(--border);}
thead th{padding:13px 18px;font-size:11px;font-weight:600;letter-spacing:.7px;text-transform:uppercase;color:var(--text2);text-align:left;white-space:nowrap;}
th.r{text-align:right;}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s;cursor:pointer;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--accent-soft);}
tbody td{padding:13px 18px;font-size:13.5px;vertical-align:middle;}
.td-r{text-align:right;}
.td-mono{font-variant-numeric:tabular-nums;font-size:12.5px;color:var(--text2);}
.msg-content{font-size:12.5px;color:var(--text2);margin-top:3px;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.msg-to{font-size:12px;color:var(--text3);margin-top:2px;}

/* campaign cards */
.campaign-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:18px 20px;margin-bottom:10px;transition:box-shadow .15s,transform .15s;cursor:pointer;}
.campaign-card:last-child{margin-bottom:0;}
.campaign-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);background:var(--card);}
.cc-top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:12px;}
.cc-name{font-size:14px;font-weight:600;color:var(--text);line-height:1.4;}
.cc-meta{font-size:12px;color:var(--text2);margin-top:3px;}
.cc-count{font-family:'DM Serif Display',serif;font-size:22px;color:var(--text);text-align:right;line-height:1;}
.cc-label{font-size:11px;color:var(--text2);text-align:right;margin-top:2px;}
.cc-statuses{display:flex;flex-wrap:wrap;gap:6px;}

/* monthly accordion */
.mo-row{border-bottom:1px solid var(--border);}
.mo-row:last-child{border-bottom:none;}
.mo-hdr{display:flex;align-items:center;justify-content:space-between;padding:18px 24px;cursor:pointer;transition:background .15s;user-select:none;flex-wrap:wrap;gap:12px;}
.mo-hdr:hover{background:var(--bg);}
.mo-row.open>.mo-hdr{background:var(--bg);}
.mo-l{display:flex;align-items:center;gap:16px;}
.mo-badge{width:50px;height:50px;border-radius:14px;background:var(--accent-soft);border:1px solid var(--accent-mid);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;}
.mo-badge-num{font-family:'DM Serif Display',serif;font-size:20px;line-height:1;color:var(--accent);}
.mo-badge-abbr{font-size:10px;font-weight:600;color:var(--accent);opacity:.6;text-transform:uppercase;}
.mo-name{font-size:15px;font-weight:600;color:var(--text);}
.mo-sub{font-size:12.5px;color:var(--text2);margin-top:2px;}
.mo-r{display:flex;align-items:center;gap:18px;}
.mo-count{font-size:17px;font-weight:700;color:var(--text);}
.chevron{width:20px;height:20px;color:var(--text3);transition:transform .25s;flex-shrink:0;}
.mo-row.open .chevron{transform:rotate(180deg);color:var(--text2);}
.mo-body{display:none;padding:4px 24px 20px;}
.mo-row.open .mo-body{display:block;}

/* ── MODAL ── */
.modal{display:none;position:fixed;inset:0;background:rgba(26,25,22,.45);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:9999;align-items:center;justify-content:center;padding:24px;}
.modal.on{display:flex;}
.modal-box{background:var(--card);border:1px solid var(--border);border-radius:var(--r);width:100%;max-width:780px;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-xl);padding:32px;position:relative;animation:fadeUp .22s ease;}
.modal-close{position:absolute;top:20px;right:22px;width:34px;height:34px;border-radius:var(--r-xs);border:1px solid var(--border);background:var(--bg);color:var(--text2);font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:.15s;line-height:1;}
.modal-close:hover{background:var(--accent-soft);border-color:var(--accent-mid);color:var(--accent);}
.modal-ttl{font-family:'DM Serif Display',serif;font-size:22px;color:var(--text);margin-bottom:4px;padding-right:40px;line-height:1.3;}
.modal-sub{font-size:13px;color:var(--text2);margin-bottom:16px;}
.modal-content-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 18px;font-size:13px;color:var(--text2);margin-bottom:20px;line-height:1.6;}
.stat-chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px;}
.stat-chip{padding:6px 14px;border-radius:var(--r-sm);font-size:12px;font-weight:700;}

.empty-row{text-align:center;padding:48px 20px;color:var(--text3);font-size:14px;}

::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:999px;}

@media(max-width:640px){
    .kpi-val{font-size:24px;}.brand-name{font-size:19px;}.topbar{padding:20px 0 18px;}
    .flt-box,.search-box{max-width:100%;}.tabs-bar{width:100%;}.tab-btn{flex:1;text-align:center;padding:9px 10px;font-size:12.5px;}
    th.hs,td.hs{display:none;}
}
</style>
</head>
<body>
<div class="wrap">

<?php if (empty($messages)): ?>
<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:12px;padding:20px 24px;margin:24px 0;font-family:'DM Sans',sans-serif;font-size:14px;color:#856404;">
    <strong style="display:block;margin-bottom:8px;font-size:15px;">⚠ No SMS data found</strong>
    <div style="margin-bottom:6px;">Place your CSV/TSV file in one of these folders (relative to this PHP file):</div>
    <ul style="margin:6px 0 12px 20px;line-height:1.9;">
        <li><code style="background:#ffeaa7;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars(__DIR__) ?>/sms_csv/</code> <em>(recommended)</em></li>
        <li><code style="background:#ffeaa7;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars(__DIR__) ?>/sms/</code></li>
        <li><code style="background:#ffeaa7;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars(__DIR__) ?>/csv_files/</code></li>
        <li><code style="background:#ffeaa7;padding:2px 6px;border-radius:4px;"><?= htmlspecialchars(__DIR__) ?>/</code> (same folder as PHP)</li>
    </ul>
    <div>Supported extensions: <code>.csv</code>, <code>.tsv</code>, <code>.txt</code></div>
    <?php if (!empty($files)): ?>
    <div style="margin-top:10px;color:#155724;"><strong>Files detected:</strong> <?= implode(', ', array_map('basename', $files)) ?> — but 0 rows parsed. Check that the file has a <code>Message Status</code> column.</div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- TOPBAR -->
<div class="topbar">
    <div class="brand">
        <div class="brand-icon">
            <svg viewBox="0 0 36 36" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M6 8h24a2 2 0 012 2v14a2 2 0 01-2 2H10l-6 4V10a2 2 0 012-2z" fill="white" opacity=".9"/>
            </svg>
        </div>
        <div>
            <div class="brand-name">SMS Dashboard</div>
            <div class="brand-sub">Message Delivery Status Tracker</div>
        </div>
    </div>
</div>

<!-- FILTERS -->
<div class="filters">
    <!-- Month -->
    <div class="flt-box">
        <select id="fltMonth" class="flt">
            <option value="">All Months</option>
            <?php foreach ($byMonth as $mKey => $m): ?>
            <option value="<?= htmlspecialchars($mKey) ?>"><?= htmlspecialchars($m['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <!-- Status -->
    <div class="flt-box">
        <select id="fltStatus" class="flt">
            <option value="">All Statuses</option>
            <?php foreach ($byStatus as $st => $_): ?>
            <option value="<?= htmlspecialchars($st) ?>"><?= htmlspecialchars($st) ?></option>
            <?php endforeach; ?>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <!-- Campaign / Event -->
    <div class="flt-box wide">
        <select id="fltCampaign" class="flt">
            <option value="">All Campaigns</option>
            <?php foreach ($byCampaign as $ck => $cv): ?>
            <option value="<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($cv['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
    </div>
    <!-- Search message content -->
    <div class="search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" id="searchContent" class="search-inp" placeholder="Search message content…">
    </div>
</div>

<!-- KPIs -->
<div class="kpis">
    <div class="kpi" style="--ka:var(--accent);--kibg:var(--accent-soft)">
        <div class="kpi-accent-bar"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></div>
        <div class="kpi-lbl">Total Messages</div>
        <div class="kpi-val" id="kpiTotal"><?= number_format($totalMessages) ?></div>
        <div class="kpi-sub" id="kpiSmsTotal"><?= number_format($totalSms) ?> SMS units</div>
    </div>
    <div class="kpi" style="--ka:var(--teal);--kibg:var(--teal-soft)">
        <div class="kpi-accent-bar" style="background:var(--teal)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--teal)" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
        <div class="kpi-lbl">Delivery Rate</div>
        <div class="kpi-val" id="kpiRate"><?= $deliveryRate ?>%</div>
        <div class="kpi-sub" id="kpiDelivered"><?= number_format($delivered) ?> delivered/accepted</div>
    </div>
    <div class="kpi" style="--ka:var(--red);--kibg:var(--red-soft)">
        <div class="kpi-accent-bar" style="background:var(--red)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--red)" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
        <div class="kpi-lbl">Undelivered</div>
        <div class="kpi-val" id="kpiUndelivered"><?= number_format(($byStatus['UNDELIVERED']['count'] ?? 0) + ($byStatus['FAILED']['count'] ?? 0)) ?></div>
        <div class="kpi-sub">failed + undelivered</div>
    </div>
    <div class="kpi" style="--ka:var(--blue);--kibg:var(--blue-soft)">
        <div class="kpi-accent-bar" style="background:var(--blue)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--blue)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div>
        <div class="kpi-lbl">Campaigns</div>
        <div class="kpi-val" id="kpiCampaigns"><?= count($byCampaign) ?></div>
        <div class="kpi-sub">unique campaigns</div>
    </div>
    <div class="kpi" style="--ka:var(--amber);--kibg:var(--amber-soft)">
        <div class="kpi-accent-bar" style="background:var(--amber)"></div>
        <div class="kpi-ico"><svg viewBox="0 0 24 24" fill="none" stroke="var(--amber)" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.72A2 2 0 012 0h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 14.92z"/></svg></div>
        <div class="kpi-lbl">Telcos</div>
        <div class="kpi-val" id="kpiTelcos"><?= count($byTelco) ?></div>
        <div class="kpi-sub">carriers reached</div>
    </div>
</div>

<!-- TABS -->
<div class="tabs-bar">
    <button class="tab-btn active" data-tab="overview">Overview</button>
    <button class="tab-btn" data-tab="campaigns">Campaigns</button>
    <button class="tab-btn" data-tab="messages">Messages</button>
    <button class="tab-btn" data-tab="monthly">Monthly</button>
</div>

<!-- TAB: OVERVIEW -->
<div class="tab-pane active" id="tab-overview">
    <!-- Status breakdown -->
    <div class="panel">
        <div class="panel-hdr">
            <span class="panel-ttl">Status Breakdown</span>
            <span class="chip" id="stChip"><?= count($byStatus) ?> statuses</span>
        </div>
        <div class="panel-body" id="statusBars">
            <?php
            $maxSt = $byStatus ? max(array_column($byStatus,'count')) : 1;
            foreach ($byStatus as $st => $sv):
                $pct = $totalMessages > 0 ? round($sv['count']/$totalMessages*100,1) : 0;
                $c = $statusColors[$st] ?? $defaultColor;
            ?>
            <div class="svc-item" data-status="<?= htmlspecialchars($st) ?>">
                <div class="svc-top">
                    <span class="svc-name" style="color:<?= $c['color'] ?>"><?= htmlspecialchars($st) ?></span>
                    <span class="svc-r">
                        <span class="svc-count" style="color:<?= $c['color'] ?>"><?= number_format($sv['count']) ?></span>
                        <span class="svc-pct"><?= $pct ?>% &nbsp;·&nbsp; <?= number_format($sv['sms']) ?> SMS</span>
                    </span>
                </div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $c['color'] ?>"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Telco breakdown -->
    <div class="panel">
        <div class="panel-hdr">
            <span class="panel-ttl">By Carrier</span>
            <span class="chip" id="telcoChip"><?= count($byTelco) ?> carriers</span>
        </div>
        <div class="panel-body" id="telcoBars">
            <?php foreach ($byTelco as $telco => $tv):
                $pct = $totalMessages > 0 ? round($tv['count']/$totalMessages*100,1) : 0;
            ?>
            <div class="svc-item">
                <div class="svc-top">
                    <span class="svc-name"><?= htmlspecialchars($telco) ?></span>
                    <span class="svc-r">
                        <span class="svc-count"><?= number_format($tv['count']) ?></span>
                        <span class="svc-pct"><?= $pct ?>%</span>
                    </span>
                </div>
                <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:var(--blue)"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- TAB: CAMPAIGNS -->
<div class="tab-pane" id="tab-campaigns">
    <div class="panel">
        <div class="panel-hdr">
            <span class="panel-ttl">Campaigns</span>
            <span class="chip" id="campChip"><?= count($byCampaign) ?> campaigns</span>
        </div>
        <div class="panel-body" id="campaignList">
            <?php foreach ($byCampaign as $ck => $cv): ?>
            <div class="campaign-card" data-campaign="<?= htmlspecialchars($ck) ?>">
                <div class="cc-top">
                    <div>
                        <div class="cc-name"><?= htmlspecialchars($cv['label']) ?></div>
                        <?php if (!empty($cv['eventId'])): ?>
                        <div class="cc-meta">Event ID: <?= htmlspecialchars($cv['eventId']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="cc-count"><?= number_format($cv['count']) ?></div>
                        <div class="cc-label"><?= number_format($cv['sms']) ?> SMS</div>
                    </div>
                </div>
                <div class="cc-statuses">
                    <?php foreach ($cv['statuses'] as $st => $cnt):
                        $c = $statusColors[$st] ?? $defaultColor;
                    ?>
                    <span class="st-pill" style="background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>;border:1px solid <?= $c['border'] ?>">
                        <span class="st-dot" style="background:<?= $c['color'] ?>"></span>
                        <?= htmlspecialchars($st) ?> <?= number_format($cnt) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- TAB: MESSAGES -->
<div class="tab-pane" id="tab-messages">
    <div class="panel">
        <div class="panel-hdr">
            <span class="panel-ttl">All Messages</span>
            <span class="chip" id="msgChip"><?= number_format($totalMessages) ?> messages</span>
        </div>
        <div class="tbl-scroll">
            <table>
                <thead><tr>
                    <th>Campaign / Event</th>
                    <th class="hs">To</th>
                    <th class="hs">Date</th>
                    <th>Status</th>
                    <th class="r hs">SMS</th>
                    <th class="hs">Carrier</th>
                </tr></thead>
                <tbody id="msgTbody">
                <?php foreach (array_slice($messages, 0, 200) as $msg):
                    $c = $statusColors[$msg['status']] ?? $defaultColor;
                ?>
                <tr class="msg-row"
                    data-month="<?= htmlspecialchars($msg['monthKey']) ?>"
                    data-status="<?= htmlspecialchars($msg['status']) ?>"
                    data-campaign="<?= htmlspecialchars($msg['campaignKey']) ?>"
                    data-content="<?= htmlspecialchars(strtolower($msg['content'])) ?>">
                    <td>
                        <div style="font-weight:600;font-size:13.5px;color:var(--text)"><?= htmlspecialchars($msg['event'] ?: '—') ?></div>
                        <div class="msg-content"><?= htmlspecialchars($msg['content']) ?></div>
                    </td>
                    <td class="hs td-mono"><?= htmlspecialchars($msg['to']) ?></td>
                    <td class="hs td-mono"><?= htmlspecialchars($msg['date']) ?></td>
                    <td>
                        <span class="st-pill" style="background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>;border:1px solid <?= $c['border'] ?>">
                            <span class="st-dot" style="background:<?= $c['color'] ?>"></span>
                            <?= htmlspecialchars($msg['status']) ?>
                        </span>
                        <?php if (!empty($msg['reason'])): ?>
                        <div style="font-size:11px;color:var(--text3);margin-top:3px;"><?= htmlspecialchars($msg['reason']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="td-r hs td-mono"><?= $msg['smsNum'] ?></td>
                    <td class="hs td-mono"><?= htmlspecialchars($msg['telco']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="msgPagination" style="padding:16px 24px;border-top:1px solid var(--border);font-size:13px;color:var(--text2);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <span id="msgCount"><?= number_format($totalMessages) ?> messages</span>
            <span id="msgNote" style="color:var(--text3);font-size:12px;">Showing up to 200 rows. Use filters to narrow results.</span>
        </div>
    </div>
</div>

<!-- TAB: MONTHLY -->
<div class="tab-pane" id="tab-monthly">
    <div class="panel" style="overflow:hidden;">
        <div class="panel-hdr">
            <span class="panel-ttl">Monthly Breakdown</span>
            <span class="chip"><?= count($byMonth) ?> periods</span>
        </div>
        <?php $mi=0; foreach ($byMonth as $mKey => $m): $mi++;
            $mNum2=(int)date('m',strtotime($mKey.'-01'));
            $mAbbr=date('M',strtotime($mKey.'-01'));
            $mLbl=date('F Y',strtotime($mKey.'-01'));
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
                        <div class="mo-sub" id="mosub_<?= str_replace('-','_',$mKey) ?>"><?= number_format($m['count']) ?> messages &nbsp;·&nbsp; <?= number_format($m['sms']) ?> SMS</div>
                    </div>
                </div>
                <div class="mo-r">
                    <div class="mo-count" id="mocount_<?= str_replace('-','_',$mKey) ?>"><?= number_format($m['count']) ?></div>
                    <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                </div>
            </div>
            <div class="mo-body" id="mob_<?= str_replace('-','_',$mKey) ?>"></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

</div><!-- /wrap -->

<!-- MODAL: message detail -->
<div id="modal" class="modal">
    <div class="modal-box">
        <button id="modalClose" class="modal-close">&times;</button>
        <div id="modalTtl" class="modal-ttl"></div>
        <div id="modalSub" class="modal-sub"></div>
        <div id="modalContent" class="modal-content-box"></div>
        <div id="modalStats" class="stat-chips"></div>
        <div class="tbl-scroll">
            <table>
                <thead><tr>
                    <th>To</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="hs">Carrier</th>
                    <th class="r">SMS</th>
                </tr></thead>
                <tbody id="modalTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const allMessages  = <?= $messagesJson ?>;
    const allMonths    = <?= $byMonthJson ?>;
    const statusColors = <?= $statusColorsJson ?>;
    const defaultColor = {color:'#6b6760',bg:'rgba(107,103,96,0.1)',border:'rgba(107,103,96,0.2)'};

    let selMonth = '', selStatus = '', selCampaign = '', searchText = '';

    const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');

    function getColor(st) { return statusColors[st] || defaultColor; }

    function statusPill(st) {
        const c = getColor(st);
        return `<span class="st-pill" style="background:${c.bg};color:${c.color};border:1px solid ${c.border}"><span class="st-dot" style="background:${c.color}"></span>${esc(st)}</span>`;
    }

    // ── FILTERS ──
    document.getElementById('fltMonth').addEventListener('change',    e => { selMonth    = e.target.value; updateAll(); });
    document.getElementById('fltStatus').addEventListener('change',   e => { selStatus   = e.target.value; updateAll(); });
    document.getElementById('fltCampaign').addEventListener('change', e => { selCampaign = e.target.value; updateAll(); });
    let searchTimer;
    document.getElementById('searchContent').addEventListener('input', e => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { searchText = e.target.value.toLowerCase().trim(); updateAll(); }, 280);
    });

    function getFiltered() {
        return allMessages.filter(m => {
            if (selMonth    && m.monthKey    !== selMonth)    return false;
            if (selStatus   && m.status      !== selStatus)   return false;
            if (selCampaign && m.campaignKey !== selCampaign) return false;
            if (searchText  && !m.content.toLowerCase().includes(searchText) && !m.event.toLowerCase().includes(searchText)) return false;
            return true;
        });
    }

    function updateAll() {
        const filtered = getFiltered();
        updateKPIs(filtered);
        updateOverview(filtered);
        updateCampaigns(filtered);
        updateMsgTable(filtered);
        updateMonthlyAccordion(filtered);
    }

    // ── TABS ──
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById('tab-'+btn.dataset.tab).classList.add('active');
            if (btn.dataset.tab === 'monthly') updateMonthlyAccordion(getFiltered());
        });
    });

    // ── KPIs ──
    function updateKPIs(msgs) {
        const total     = msgs.length;
        const totalSms  = msgs.reduce((s,m)=>s+m.smsNum,0);
        const delivered = msgs.filter(m=>m.status==='DELIVERED'||m.status==='ACCEPTED').length;
        const failed    = msgs.filter(m=>m.status==='UNDELIVERED'||m.status==='FAILED').length;
        const rate      = total > 0 ? (delivered/total*100).toFixed(1) : 0;
        const campaigns = new Set(msgs.map(m=>m.campaignKey).filter(Boolean)).size;
        const telcos    = new Set(msgs.map(m=>m.telco).filter(Boolean)).size;

        document.getElementById('kpiTotal').textContent     = total.toLocaleString();
        document.getElementById('kpiSmsTotal').textContent  = totalSms.toLocaleString() + ' SMS units';
        document.getElementById('kpiRate').textContent      = rate + '%';
        document.getElementById('kpiDelivered').textContent = delivered.toLocaleString() + ' delivered/accepted';
        document.getElementById('kpiUndelivered').textContent = failed.toLocaleString();
        document.getElementById('kpiCampaigns').textContent = campaigns.toLocaleString();
        document.getElementById('kpiTelcos').textContent    = telcos.toLocaleString();
    }

    // ── OVERVIEW ──
    function updateOverview(msgs) {
        // status bars
        const stMap = {};
        msgs.forEach(m => {
            if (!stMap[m.status]) stMap[m.status] = {count:0, sms:0};
            stMap[m.status].count++;
            stMap[m.status].sms += m.smsNum;
        });
        const total = msgs.length;
        const sorted = Object.entries(stMap).sort((a,b)=>b[1].count-a[1].count);
        const sb = document.getElementById('statusBars');
        if (!sorted.length) { sb.innerHTML='<div class="empty-row">No data for selected filters</div>'; }
        else {
            sb.innerHTML = sorted.map(([st, sv]) => {
                const pct = total>0?(sv.count/total*100).toFixed(1):0;
                const c = getColor(st);
                return `<div class="svc-item">
                    <div class="svc-top">
                        <span class="svc-name" style="color:${c.color}">${esc(st)}</span>
                        <span class="svc-r">
                            <span class="svc-count" style="color:${c.color}">${sv.count.toLocaleString()}</span>
                            <span class="svc-pct">${pct}% &nbsp;·&nbsp; ${sv.sms.toLocaleString()} SMS</span>
                        </span>
                    </div>
                    <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:${c.color}"></div></div>
                </div>`;
            }).join('');
        }
        document.getElementById('stChip').textContent = sorted.length + ' statuses';

        // telco bars
        const tMap = {};
        msgs.forEach(m => { if (m.telco) { if (!tMap[m.telco]) tMap[m.telco]={count:0,sms:0}; tMap[m.telco].count++; tMap[m.telco].sms+=m.smsNum; } });
        const tSorted = Object.entries(tMap).sort((a,b)=>b[1].count-a[1].count);
        const tb = document.getElementById('telcoBars');
        if (!tSorted.length) { tb.innerHTML='<div class="empty-row">No data</div>'; }
        else {
            tb.innerHTML = tSorted.map(([tel, tv]) => {
                const pct = total>0?(tv.count/total*100).toFixed(1):0;
                return `<div class="svc-item">
                    <div class="svc-top">
                        <span class="svc-name">${esc(tel)}</span>
                        <span class="svc-r"><span class="svc-count">${tv.count.toLocaleString()}</span><span class="svc-pct">${pct}%</span></span>
                    </div>
                    <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:var(--blue)"></div></div>
                </div>`;
            }).join('');
        }
        document.getElementById('telcoChip').textContent = tSorted.length + ' carriers';
    }

    // ── CAMPAIGNS ──
    function updateCampaigns(msgs) {
        const cMap = {};
        msgs.forEach(m => {
            const ck = m.campaignKey; if (!ck) return;
            if (!cMap[ck]) cMap[ck] = {label:m.campaign, count:0, sms:0, statuses:{}, eventId:m.eventId};
            cMap[ck].count++;
            cMap[ck].sms += m.smsNum;
            if (!cMap[ck].statuses[m.status]) cMap[ck].statuses[m.status]=0;
            cMap[ck].statuses[m.status]++;
        });
        const sorted = Object.entries(cMap).sort((a,b)=>b[1].count-a[1].count);
        const cl = document.getElementById('campaignList');
        document.getElementById('campChip').textContent = sorted.length + ' campaigns';
        if (!sorted.length) { cl.innerHTML='<div class="empty-row">No campaigns for selected filters</div>'; return; }
        cl.innerHTML = sorted.map(([ck, cv]) => {
            const stHtml = Object.entries(cv.statuses).map(([st,cnt])=>{
                const c=getColor(st);
                return `<span class="st-pill" style="background:${c.bg};color:${c.color};border:1px solid ${c.border}"><span class="st-dot" style="background:${c.color}"></span>${esc(st)} ${cnt.toLocaleString()}</span>`;
            }).join('');
            return `<div class="campaign-card" data-campaign="${esc(ck)}">
                <div class="cc-top">
                    <div>
                        <div class="cc-name">${esc(cv.label)}</div>
                        ${cv.eventId?`<div class="cc-meta">Event ID: ${esc(cv.eventId)}</div>`:''}
                    </div>
                    <div><div class="cc-count">${cv.count.toLocaleString()}</div><div class="cc-label">${cv.sms.toLocaleString()} SMS</div></div>
                </div>
                <div class="cc-statuses">${stHtml}</div>
            </div>`;
        }).join('');

        // click campaign card → show its messages in modal
        cl.querySelectorAll('.campaign-card').forEach(card => {
            card.addEventListener('click', () => {
                const ck = card.dataset.campaign;
                const cv = cMap[ck];
                const campMsgs = msgs.filter(m=>m.campaignKey===ck);
                showCampaignModal(cv, campMsgs);
            });
        });
    }

    // ── MESSAGE TABLE ──
    function updateMsgTable(msgs) {
        const tbody = document.getElementById('msgTbody');
        const chip  = document.getElementById('msgChip');
        chip.textContent = msgs.length.toLocaleString() + ' messages';
        const show = msgs.slice(0, 300);
        if (!show.length) { tbody.innerHTML=`<tr><td colspan="6"><div class="empty-row">No messages for selected filters</div></td></tr>`; return; }
        tbody.innerHTML = show.map(m => {
            const c = getColor(m.status);
            return `<tr>
                <td>
                    <div style="font-weight:600;font-size:13.5px;color:var(--text)">${esc(m.event||'—')}</div>
                    <div class="msg-content">${esc(m.content)}</div>
                </td>
                <td class="hs td-mono">${esc(m.to)}</td>
                <td class="hs td-mono">${esc(m.date)}</td>
                <td>
                    <span class="st-pill" style="background:${c.bg};color:${c.color};border:1px solid ${c.border}"><span class="st-dot" style="background:${c.color}"></span>${esc(m.status)}</span>
                    ${m.reason?`<div style="font-size:11px;color:var(--text3);margin-top:3px;">${esc(m.reason)}</div>`:''}
                </td>
                <td class="td-r hs td-mono">${m.smsNum}</td>
                <td class="hs td-mono">${esc(m.telco)}</td>
            </tr>`;
        }).join('');
        document.getElementById('msgNote').textContent = msgs.length > 300 ? `Showing 300 of ${msgs.length.toLocaleString()} — use filters to narrow down.` : '';
    }

    // ── MONTHLY ACCORDION ──
    function updateMonthlyAccordion(msgs) {
        document.querySelectorAll('.mo-row.open').forEach(row => buildMonthBody(row.dataset.month, row.querySelector('.mo-body'), msgs));
    }

    document.querySelectorAll('.mo-hdr').forEach(hdr => {
        hdr.addEventListener('click', () => {
            const row  = hdr.closest('.mo-row');
            const mKey = row.dataset.month;
            const body = row.querySelector('.mo-body');
            const wasOpen = row.classList.contains('open');
            row.classList.toggle('open');
            if (!wasOpen) buildMonthBody(mKey, body, getFiltered());
        });
    });

    const first = document.querySelector('.mo-row.open');
    if (first) buildMonthBody(first.dataset.month, first.querySelector('.mo-body'), allMessages);

    function buildMonthBody(mKey, container, msgs) {
        const mMsgs = msgs.filter(m=>m.monthKey===mKey);

        const countEl = document.getElementById('mocount_'+mKey.replace('-','_'));
        const subEl   = document.getElementById('mosub_'+mKey.replace('-','_'));
        if (countEl) countEl.textContent = mMsgs.length.toLocaleString();
        if (subEl)   subEl.textContent   = mMsgs.length.toLocaleString()+' messages · '+mMsgs.reduce((s,m)=>s+m.smsNum,0).toLocaleString()+' SMS';

        if (!mMsgs.length) { container.innerHTML='<div class="empty-row" style="padding:16px 0">No messages for this period</div>'; return; }

        // group by status for this month
        const stMap = {};
        mMsgs.forEach(m => {
            if (!stMap[m.status]) stMap[m.status]={count:0,sms:0};
            stMap[m.status].count++; stMap[m.status].sms+=m.smsNum;
        });
        const total = mMsgs.length;
        const stRows = Object.entries(stMap).sort((a,b)=>b[1].count-a[1].count).map(([st,sv]) => {
            const c=getColor(st);
            const pct=total>0?(sv.count/total*100).toFixed(1):0;
            return `<div class="svc-item" style="margin-bottom:12px;">
                <div class="svc-top">
                    <span style="font-size:13px;font-weight:600;color:${c.color}">${esc(st)}</span>
                    <span class="svc-r"><span class="svc-count" style="color:${c.color};font-size:13px;">${sv.count.toLocaleString()}</span><span class="svc-pct">${pct}% · ${sv.sms} SMS</span></span>
                </div>
                <div class="bar-track"><div class="bar-fill" style="width:${pct}%;background:${c.color}"></div></div>
            </div>`;
        }).join('');

        container.innerHTML = `<div style="padding:8px 0 4px">${stRows}</div>`;
    }

    // ── CAMPAIGN MODAL ──
    function showCampaignModal(cv, msgs) {
        document.getElementById('modalTtl').textContent  = cv.label;
        document.getElementById('modalSub').textContent  = `${msgs.length.toLocaleString()} messages · ${msgs.reduce((s,m)=>s+m.smsNum,0).toLocaleString()} SMS units`;

        // show message content (first message)
        const sample = msgs[0];
        document.getElementById('modalContent').textContent = sample?.content || '';

        // stat chips
        const chips = Object.entries(cv.statuses).map(([st,cnt])=>{
            const c=getColor(st);
            return `<span class="stat-chip" style="background:${c.bg};color:${c.color};border:1px solid ${c.border}">${esc(st)}: ${cnt.toLocaleString()}</span>`;
        }).join('');
        document.getElementById('modalStats').innerHTML = chips;

        // table
        const tbody = document.getElementById('modalTbody');
        const sorted = [...msgs].sort((a,b)=>a.status.localeCompare(b.status));
        tbody.innerHTML = sorted.map(m => {
            const c=getColor(m.status);
            return `<tr>
                <td class="td-mono">${esc(m.to)}</td>
                <td class="td-mono">${esc(m.date)}</td>
                <td>${statusPill(m.status)}${m.reason?`<div style="font-size:11px;color:var(--text3);margin-top:2px">${esc(m.reason)}</div>`:''}</td>
                <td class="hs td-mono">${esc(m.telco)}</td>
                <td class="td-r td-mono">${m.smsNum}</td>
            </tr>`;
        }).join('');

        document.getElementById('modal').classList.add('on');
    }

    const modal = document.getElementById('modal');
    document.getElementById('modalClose').addEventListener('click',()=>modal.classList.remove('on'));
    modal.addEventListener('click', e=>{ if(e.target===modal) modal.classList.remove('on'); });
});
</script>
</body>
</html>