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

        if ($idxId === false || $idxAmount === false || $idxType === false) {
            fclose($file); continue;
        }

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
                $results[$locId] = [
                    'locationName'         => $locName,
                    'conversationaiAmount' => 0,
                    'conversationaiCount'  => 0,
                    'monthlyData'          => [],
                ];
            }

            $results[$locId]['conversationaiAmount'] += $amount;
            $results[$locId]['conversationaiCount']++;

            if ($monthKey) {
                if (!isset($results[$locId]['monthlyData'][$monthKey])) {
                    $results[$locId]['monthlyData'][$monthKey] = ['totalAmount' => 0, 'count' => 0, 'transactions' => []];
                }
                $results[$locId]['monthlyData'][$monthKey]['totalAmount'] += $amount;
                $results[$locId]['monthlyData'][$monthKey]['count']++;
                $results[$locId]['monthlyData'][$monthKey]['transactions'][] = [
                    'date'        => $row[$idxDate],
                    'amount'      => $amount,
                    'description' => $desc,
                ];
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
$grandTotal = 0;
$grandCount = 0;
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

// Build global monthly — sorted DESCENDING (newest first)
$globalMonthly = [];
foreach ($subaccountData as $data) {
    foreach ($data['monthlyData'] as $monthKey => $mdata) {
        if (!isset($globalMonthly[$monthKey])) {
            $globalMonthly[$monthKey] = [
                'totalAmount' => 0,
                'count'       => 0,
                'monthName'   => date('F Y', strtotime($monthKey . '-01')),
            ];
        }
        $globalMonthly[$monthKey]['totalAmount'] += $mdata['totalAmount'];
        $globalMonthly[$monthKey]['count']       += $mdata['count'];
    }
}
krsort($globalMonthly); // descending — newest first

$subaccountDataJson = json_encode($subaccountData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$globalMonthlyJson  = json_encode($globalMonthly,  JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

if ($isDemo) {
    $initialTotal = $grandTotal;
    $initialCount = $grandCount;
} else {
    $d = $subaccountData[$targetLocationId] ?? null;
    if (!$d) {
        echo '<html><body><div style="text-align:center;padding:40px;"><h2>No valid data found for this subaccount.</h2></div></body></html>';
        exit;
    }
    $initialTotal = $d['conversationaiAmount'];
    $initialCount = $d['conversationaiCount'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHL Credits Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    :root {
        --ai:       #7c3aed;
        --ai-light: #a78bfa;
        --dark:     #0f172a;
        --gray:     #64748b;
        --light:    #f8fafc;
        --border:   #e2e8f0;
        --shadow-sm: 0 2px 12px rgba(0,0,0,0.06);
        --shadow:    0 6px 24px rgba(0,0,0,0.08);
        --myr:       #0ea5e9;
    }
    * { margin:0; padding:0; box-sizing:border-box; }
    body {
        font-family: 'Inter', system-ui, sans-serif;
        background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        color: var(--dark);
        min-height: 100vh;
        padding: 32px 16px;
        line-height: 1.5;
    }
    .dashboard { max-width: 1180px; margin: 0 auto; }

    /* Header */
    .hdr {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 28px; gap: 12px; flex-wrap: wrap;
    }
    .hdr-title { font-size: 28px; font-weight: 700; letter-spacing: -0.5px; }
    .hdr-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .hdr-note {
        font-size: 13px; color: var(--gray); background: white;
        padding: 6px 14px; border-radius: 999px;
        border: 1px solid var(--border); font-weight: 500;
    }

    /* MYR Toggle */
    .myr-toggle-wrap {
        display: flex; align-items: center; gap: 8px;
        background: white; border: 1px solid var(--border);
        border-radius: 10px; padding: 6px 12px;
        box-shadow: var(--shadow-sm);
    }
    .myr-toggle-wrap label { font-size: 13px; font-weight: 600; color: var(--gray); white-space: nowrap; }
    .toggle-switch {
        position: relative; width: 38px; height: 22px; cursor: pointer;
    }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
        position: absolute; inset: 0;
        background: #cbd5e1; border-radius: 999px;
        transition: background 0.2s;
    }
    .toggle-slider:before {
        content: ''; position: absolute;
        width: 16px; height: 16px; border-radius: 50%;
        background: white; top: 3px; left: 3px;
        transition: transform 0.2s;
        box-shadow: 0 1px 4px rgba(0,0,0,0.2);
    }
    .toggle-switch input:checked + .toggle-slider { background: var(--myr); }
    .toggle-switch input:checked + .toggle-slider:before { transform: translateX(16px); }
    .myr-rate-wrap {
        display: none; align-items: center; gap: 4px;
    }
    .myr-rate-wrap.visible { display: flex; }
    .myr-rate-wrap label { font-size: 12px; color: var(--gray); font-weight: 500; }
    .myr-rate-input {
        width: 64px; padding: 3px 7px;
        border: 1px solid var(--border); border-radius: 6px;
        font-size: 13px; font-weight: 600; color: var(--dark);
        text-align: right;
    }
    .myr-rate-input:focus { outline: none; border-color: var(--myr); }

    /* Filters */
    .filter-row {
        display: flex; gap: 12px; flex-wrap: wrap;
        margin-bottom: 24px;
    }
    .filter-select {
        flex: 1; min-width: 200px; max-width: 360px;
        padding: 11px 40px 11px 16px;
        font-size: 15px; border: 1px solid var(--border); border-radius: 10px;
        background: white; color: var(--dark);
        cursor: pointer; appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right 14px center; background-size: 18px;
        box-shadow: var(--shadow-sm); font-weight: 500; transition: all 0.2s;
    }
    .filter-select:focus { outline: none; border-color: var(--ai); box-shadow: 0 0 0 3px rgba(124,58,237,0.15); }

    /* Summary Card */
    .card {
        background: white; border-radius: 16px; padding: 32px;
        box-shadow: var(--shadow); border-top: 5px solid var(--ai);
        position: relative; margin-bottom: 32px; transition: transform 0.2s;
    }
    .card:hover { transform: translateY(-2px); }
    .card-badge {
        position: absolute; top: 24px; right: 24px;
        background: rgba(124,58,237,0.1); color: var(--ai);
        font-size: 13px; font-weight: 600; padding: 6px 14px; border-radius: 999px;
    }
    .card-icon {
        width: 56px; height: 56px; background: rgba(124,58,237,0.1);
        border-radius: 14px; display: flex; align-items: center;
        justify-content: center; margin-bottom: 20px;
    }
    .card-icon svg { width: 28px; height: 28px; stroke: var(--ai); }
    .card-label { font-size: 14px; color: var(--gray); font-weight: 600; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 8px; }
    .card-value-row { display: flex; align-items: baseline; gap: 12px; margin-bottom: 6px; flex-wrap: wrap; }
    .card-value { font-size: 42px; font-weight: 800; color: var(--ai); letter-spacing: -1px; }
    .card-value-myr {
        font-size: 18px; font-weight: 600; color: var(--myr);
        opacity: 0; transition: opacity 0.2s; white-space: nowrap;
    }
    .card-value-myr.visible { opacity: 1; }
    .card-sub { font-size: 15px; color: var(--gray); font-weight: 500; }

    /* Table */
    .section-title { font-size: 18px; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
    .count-badge { background: #e2e8f0; color: var(--gray); font-size: 13px; padding: 4px 10px; border-radius: 999px; font-weight: 600; }
    .tbl-wrap { background: white; border-radius: 16px; overflow: hidden; box-shadow: var(--shadow); margin-bottom: 32px; }
    table { width: 100%; border-collapse: collapse; }
    thead tr { background: #f1f5f9; }
    thead th { padding: 14px 20px; text-align: left; font-size: 13px; font-weight: 600; color: var(--gray); text-transform: uppercase; letter-spacing: 0.6px; }
    thead th.right { text-align: right; }
    tbody tr { border-bottom: 1px solid var(--border); transition: background 0.15s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: rgba(124,58,237,0.04); }
    tbody td { padding: 16px 20px; font-size: 15px; }
    tbody td.right { text-align: right; font-weight: 700; color: var(--ai); font-variant-numeric: tabular-nums; }
    .subacc-name { font-weight: 600; color: var(--dark); }
    .subacc-id   { font-size: 12px; color: var(--gray); margin-top: 3px; }
    .amount-cell { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
    .amount-usd  { font-weight: 700; color: var(--ai); }
    .amount-myr  { font-size: 12px; font-weight: 500; color: var(--myr); opacity: 0; transition: opacity 0.2s; }
    .amount-myr.visible { opacity: 1; }
    .pill { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 999px; }
    .pill.active   { background: rgba(124,58,237,0.12); color: var(--ai); }
    .pill.inactive { background: #e2e8f0; color: var(--gray); }
    .dot { width: 8px; height: 8px; border-radius: 50%; }
    .dot.active   { background: var(--ai); }
    .dot.inactive { background: #cbd5e1; }

    /* Monthly / Modal */
    .monthly-wrap { background: white; border-radius: 16px; padding: 28px; box-shadow: var(--shadow); margin-bottom: 32px; }
    .monthly-card {
        border: 1px solid var(--border); border-radius: 12px; padding: 20px; margin-bottom: 12px;
        display: flex; justify-content: space-between; align-items: center;
        cursor: pointer; transition: all 0.2s;
    }
    .monthly-card:hover { border-color: var(--ai-light); box-shadow: 0 6px 20px rgba(124,58,237,0.12); transform: translateY(-2px); }
    .monthly-card-amounts { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
    .monthly-card-usd { font-weight: 700; font-size: 18px; color: var(--ai); }
    .monthly-card-myr { font-size: 13px; font-weight: 500; color: var(--myr); opacity: 0; transition: opacity 0.2s; }
    .monthly-card-myr.visible { opacity: 1; }
    .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
    .modal.visible { display: flex; }
    .modal-box { background: white; border-radius: 16px; width: 100%; max-width: 720px; max-height: 85vh; overflow-y: auto; box-shadow: 0 20px 70px rgba(0,0,0,0.25); position: relative; padding: 28px; }
    .modal-close { position: absolute; top: 16px; right: 20px; font-size: 28px; color: var(--gray); cursor: pointer; }
    .modal-title { font-size: 20px; font-weight: 700; margin-bottom: 20px; }

    @media (max-width: 640px) {
        .card-value { font-size: 32px; }
        .hdr-title  { font-size: 22px; }
        .hdr { flex-direction: column; align-items: flex-start; }
        thead th.hide-sm, tbody td.hide-sm { display: none; }
    }
    </style>
</head>
<body>
<div class="dashboard">

    <div class="hdr">
        <div class="hdr-title">Credits Dashboard</div>
        <div class="hdr-right">
            <!-- MYR Toggle -->
            <div class="myr-toggle-wrap">
                <label for="myrToggle">Show MYR</label>
                <label class="toggle-switch">
                    <input type="checkbox" id="myrToggle">
                    <span class="toggle-slider"></span>
                </label>
                <div class="myr-rate-wrap" id="myrRateWrap">
                    <label for="myrRate">1 USD =</label>
                    <input type="number" id="myrRate" class="myr-rate-input" value="3.92" min="1" step="0.01">
                    <label for="myrRate">MYR</label>
                </div>
            </div>
            <div class="hdr-note">Amounts in USD</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-row">
        <select id="monthFilter" class="filter-select">
            <option value="">All Months</option>
            <?php foreach ($globalMonthly as $monthKey => $m): ?>
            <option value="<?= htmlspecialchars($monthKey) ?>"><?= htmlspecialchars($m['monthName']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($isDemo): ?>
        <select id="subaccountSelect" class="filter-select">
            <option value="">All Subaccounts</option>
            <?php foreach ($subaccountData as $locId => $d): ?>
            <option value="<?= htmlspecialchars($locId) ?>"><?= htmlspecialchars($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>

    <!-- Summary Card -->
    <div class="card">
        <div class="card-badge" id="aiBadge"><?= number_format($initialCount) ?> transactions</div>
        <div class="card-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
        </div>
        <div class="card-label">Conversation &amp; Voice AI — Total Spent</div>
        <div class="card-value-row">
            <div class="card-value" id="aiTotal">$<?= number_format($initialTotal, 2) ?></div>
            <div class="card-value-myr" id="aiTotalMyr"></div>
        </div>
        <div class="card-sub" id="aiCount"><?= number_format($initialCount) ?> AI interactions</div>
    </div>

    <!-- Subaccounts Table (Demo) -->
    <?php if ($isDemo): ?>
    <div id="subaccTable">
        <div class="section-title">
            Subaccounts <span class="count-badge" id="subaccCount"><?= count($subaccountData) ?></span>
        </div>
        <div class="tbl-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Subaccount</th>
                        <th class="right">Total Spent</th>
                        <th class="right hide-sm">Interactions</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="subaccTbody">
                <?php foreach ($subaccountData as $locId => $d):
                    $active = $d['conversationaiAmount'] > 0;
                ?>
                <tr>
                    <td>
                        <div class="subacc-name"><?= htmlspecialchars($d['name']) ?></div>
                        <div class="subacc-id"><?= htmlspecialchars($locId) ?></div>
                    </td>
                    <td class="right">
                        <div class="amount-cell">
                            <span class="amount-usd">$<?= number_format($d['conversationaiAmount'], 2) ?></span>
                            <span class="amount-myr" data-usd="<?= $d['conversationaiAmount'] ?>"></span>
                        </div>
                    </td>
                    <td class="right hide-sm"><?= number_format($d['conversationaiCount']) ?></td>
                    <td>
                        <span class="pill <?= $active ? 'active' : 'inactive' ?>">
                            <span class="dot <?= $active ? 'active' : 'inactive' ?>"></span>
                            <?= $active ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Monthly Breakdown (single subaccount) -->
    <?php if (!$isDemo): ?>
    <div id="monthlyWrap" class="monthly-wrap">
        <div class="section-title">Monthly Breakdown</div>
        <select id="monthlySelect" class="filter-select" style="margin-bottom:16px;">
            <option value="">Select Month</option>
            <?php
            if (!empty($d['monthlyData'])) {
                krsort($d['monthlyData']); // newest first
                foreach ($d['monthlyData'] as $month => $_) {
                    $monthName = date('F Y', strtotime($month . '-01'));
                    echo "<option value=\"$month\">$monthName</option>";
                }
            }
            ?>
        </select>
        <div id="monthlyCard"></div>
    </div>
    <?php endif; ?>

</div>

<!-- Modal -->
<div id="modal" class="modal">
    <div class="modal-box">
        <span id="modalClose" class="modal-close">&times;</span>
        <div id="modalTitle" class="modal-title"></div>
        <div class="tbl-wrap" style="margin:0;">
            <table>
                <thead>
                    <tr>
                        <th>Date &amp; Time</th>
                        <th class="right">Amount</th>
                        <th class="hide-sm">Description</th>
                    </tr>
                </thead>
                <tbody id="modalTbody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const isDemo         = <?= json_encode($isDemo) ?>;
    const targetLocId    = <?= json_encode($targetLocationId) ?>;
    const allSubaccounts = <?= $subaccountDataJson ?>;
    const globalMonthly  = <?= $globalMonthlyJson ?>;

    let selectedSub   = isDemo ? '' : targetLocId;
    let selectedMonth = '';
    let showMyr       = false;
    let myrRate       = 3.92;

    const fmtUSD = v => '$' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmtMYR = v => 'RM ' + (v * myrRate).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc    = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

    // ── MYR Toggle ──
    const myrToggle   = document.getElementById('myrToggle');
    const myrRateWrap = document.getElementById('myrRateWrap');
    const myrRateInput = document.getElementById('myrRate');

    function applyMyrVisibility() {
        // Card
        const cardMyr = document.getElementById('aiTotalMyr');
        if (cardMyr) {
            cardMyr.classList.toggle('visible', showMyr);
        }
        // Table rows
        document.querySelectorAll('.amount-myr').forEach(el => {
            el.classList.toggle('visible', showMyr);
            if (showMyr) {
                const usd = parseFloat(el.dataset.usd || 0);
                el.textContent = fmtMYR(usd);
            }
        });
        // Monthly cards
        document.querySelectorAll('.monthly-card-myr').forEach(el => {
            el.classList.toggle('visible', showMyr);
            if (showMyr) {
                const usd = parseFloat(el.dataset.usd || 0);
                el.textContent = fmtMYR(usd);
            }
        });
    }

    function refreshCardMyr(usdVal) {
        const el = document.getElementById('aiTotalMyr');
        if (el) el.textContent = fmtMYR(usdVal);
    }

    myrToggle.addEventListener('change', () => {
        showMyr = myrToggle.checked;
        myrRateWrap.classList.toggle('visible', showMyr);
        applyMyrVisibility();
    });

    myrRateInput.addEventListener('input', () => {
        myrRate = parseFloat(myrRateInput.value) || 4.45;
        applyMyrVisibility();
        // also refresh card
        const { total } = getCurrentData();
        refreshCardMyr(total);
    });

    // ── Data helpers ──
    function getCurrentData() {
        let subs = selectedSub === '' || !isDemo
            ? { ...allSubaccounts }
            : allSubaccounts[selectedSub] ? { [selectedSub]: allSubaccounts[selectedSub] } : {};

        let total = 0, count = 0;

        if (selectedMonth) {
            const filtered = {};
            Object.entries(subs).forEach(([id, sub]) => {
                const m = sub.monthlyData?.[selectedMonth];
                if (m?.totalAmount > 0) {
                    filtered[id] = { ...sub, conversationaiAmount: m.totalAmount, conversationaiCount: m.count };
                    total += m.totalAmount;
                    count += m.count;
                }
            });
            return { subs: filtered, total, count };
        }

        Object.values(subs).forEach(sub => {
            total += sub.conversationaiAmount;
            count += sub.conversationaiCount;
        });
        return { subs, total, count };
    }

    function updateCard(total, count) {
        document.getElementById('aiTotal').textContent   = fmtUSD(total);
        document.getElementById('aiCount').textContent   = count.toLocaleString() + ' AI interactions';
        document.getElementById('aiBadge').textContent   = count.toLocaleString() + ' transactions';
        const myrEl = document.getElementById('aiTotalMyr');
        if (myrEl) myrEl.textContent = fmtMYR(total);
    }

    function renderTable(data) {
        const tbody = document.getElementById('subaccTbody');
        if (!tbody) return;
        document.getElementById('subaccCount').textContent = Object.keys(data).length;
        const frag = document.createDocumentFragment();

        if (!Object.keys(data).length) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="4" style="text-align:center;color:var(--gray);padding:32px;">No transactions found for this period.</td>`;
            frag.appendChild(tr);
            tbody.innerHTML = '';
            tbody.appendChild(frag);
            return;
        }

        Object.entries(data).forEach(([id, d]) => {
            const active = d.conversationaiAmount > 0;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>
                    <div class="subacc-name">${esc(d.name)}</div>
                    <div class="subacc-id">${esc(id)}</div>
                </td>
                <td class="right">
                    <div class="amount-cell">
                        <span class="amount-usd">${fmtUSD(d.conversationaiAmount)}</span>
                        <span class="amount-myr ${showMyr ? 'visible' : ''}" data-usd="${d.conversationaiAmount}">${showMyr ? fmtMYR(d.conversationaiAmount) : ''}</span>
                    </div>
                </td>
                <td class="right hide-sm">${d.conversationaiCount.toLocaleString()}</td>
                <td><span class="pill ${active?'active':'inactive'}"><span class="dot ${active?'active':'inactive'}"></span>${active?'Active':'Inactive'}</span></td>
            `;
            frag.appendChild(tr);
        });
        tbody.innerHTML = '';
        tbody.appendChild(frag);
    }

    function updateUI() {
        const { subs, total, count } = getCurrentData();
        updateCard(total, count);
        if (isDemo) renderTable(subs);
    }

    document.getElementById('monthFilter')?.addEventListener('change', e => {
        selectedMonth = e.target.value;
        updateUI();
    });

    document.getElementById('subaccountSelect')?.addEventListener('change', e => {
        selectedSub = e.target.value;
        updateUI();
    });

    updateUI();

    // ── Single subaccount monthly select ──
    const monthlySelect = document.getElementById('monthlySelect');
    if (!isDemo && monthlySelect) {
        monthlySelect.addEventListener('change', function () {
            const month     = this.value;
            const container = document.getElementById('monthlyCard');
            container.innerHTML = '';
            if (!month) return;

            const md = allSubaccounts[targetLocId]?.monthlyData?.[month];
            if (!md) return;

            const el = document.createElement('div');
            el.className = 'monthly-card';
            el.innerHTML = `
                <div style="display:flex;align-items:center;gap:16px;">
                    <div style="width:44px;height:44px;background:rgba(124,58,237,0.1);border-radius:12px;display:flex;align-items:center;justify-content:center;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--ai)" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                    </div>
                    <div>
                        <div style="font-weight:600;font-size:15px;">Conversation &amp; Voice AI</div>
                        <div style="font-size:13px;color:var(--gray);">${md.count} interaction${md.count !== 1 ? 's' : ''}</div>
                    </div>
                </div>
                <div class="monthly-card-amounts">
                    <span class="monthly-card-usd">${fmtUSD(md.totalAmount)}</span>
                    <span class="monthly-card-myr ${showMyr ? 'visible' : ''}" data-usd="${md.totalAmount}">${showMyr ? fmtMYR(md.totalAmount) : ''}</span>
                </div>
            `;
            el.addEventListener('click', () => showModal(month, md.transactions));
            container.appendChild(el);
        });
    }

    // ── Modal ──
    function showModal(month, transactions) {
        const monthStr = new Date(month + '-02').toLocaleString('en-US', { month: 'long', year: 'numeric' });
        document.getElementById('modalTitle').textContent = `Conversation & Voice AI — ${monthStr}`;
        const tbody = document.getElementById('modalTbody');
        const frag  = document.createDocumentFragment();
        transactions.forEach(tx => {
            const clean  = tx.date.replace(/(\d+)(st|nd|rd|th)/i, '$1').replace(/,/g, '');
            const parsed = new Date(clean);
            const fd = isNaN(parsed) ? tx.date : parsed.toLocaleString('en-US', {
                month:'short', day:'2-digit', year:'numeric',
                hour:'2-digit', minute:'2-digit', hour12:true
            });
            const amtMyr = showMyr ? `<div style="font-size:11px;color:var(--myr);font-weight:500;">${fmtMYR(tx.amount)}</div>` : '';
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${esc(fd)}</td>
                <td class="right">
                    <div>${fmtUSD(tx.amount)}</div>
                    ${amtMyr}
                </td>
                <td class="hide-sm" style="color:var(--gray);font-size:13px;">${esc(tx.description || '')}</td>
            `;
            frag.appendChild(tr);
        });
        tbody.innerHTML = '';
        tbody.appendChild(frag);
        document.getElementById('modal').classList.add('visible');
    }

    const modal = document.getElementById('modal');
    document.getElementById('modalClose').addEventListener('click', () => modal.classList.remove('visible'));
    modal.addEventListener('click', e => { if (e.target === modal) modal.classList.remove('visible'); });
});
</script>
</body>
</html>