<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$scriptDir = dirname(__FILE__);
$csvDirectory = $scriptDir . DIRECTORY_SEPARATOR . 'csv_files';

function getCsvFiles($directory) {
    if (!is_dir($directory)) return [];
    return glob($directory . '/*.csv');
}

function processCsvFiles($csvFiles) {
    $results = [];
    $allCategories = ['whatsapp', 'email', 'workflow', 'conversationAI', 'other'];
    
    foreach ($csvFiles as $filePath) {
        if (($handle = fopen($filePath, 'r')) !== false) {
            $header = fgetcsv($handle);
            if (!$header) continue;

            $colMap = [];
            foreach ($header as $idx => $col) {
                $name = strtolower(trim($col));
                if (in_array($name, ['locationid', 'location id'])) $colMap['locId'] = $idx;
                if (in_array($name, ['type', 'transaction type'])) $colMap['type'] = $idx;
                if ($name === 'amount') $colMap['amount'] = $idx;
                if ($name === 'description') $colMap['desc'] = $idx;
                if (in_array($name, ['date', 'activity date'])) $colMap['date'] = $idx;
            }

            while (($row = fgetcsv($handle)) !== false) {
                $rawDate = $row[$colMap['date']] ?? 'Unknown';
                $cleanDate = preg_replace('/(\d+)(st|nd|rd|th)/', '$1', $rawDate);
                $timestamp = strtotime($cleanDate);
                
                $sortKey = $timestamp ? date('Y-m', $timestamp) : '0000-00';
                $displayMonth = $timestamp ? date('F Y', $timestamp) : 'Unknown Date';
                
                $locId = trim($row[$colMap['locId']] ?? 'Unknown');
                $amount = floatval($row[$colMap['amount']] ?? 0);
                $type = trim($row[$colMap['type']] ?? 'Other');
                $desc = strtolower($row[$colMap['desc']] ?? '');

                $category = 'other';
                $creditCount = $amount; 
                if (stripos($type, 'WhatsApp') !== false || stripos($desc, 'whatsapp') !== false) {
                    $category = 'whatsapp'; $creditCount = $amount / 0.50;
                } elseif (stripos($type, 'Email') !== false || stripos($desc, 'email') !== false) {
                    $category = 'email'; $creditCount = $amount / 0.005;
                } elseif (stripos($type, 'Workflow') !== false || stripos($desc, 'workflow') !== false) {
                    $category = 'workflow';
                } elseif (stripos($type, 'Conversation and Voice AI') !== false || stripos($desc, 'conversationai') !== false) {
                    $category = 'conversationAI';
                }

                if (!isset($results[$sortKey])) {
                    $results[$sortKey] = [
                        'name' => $displayMonth, 
                        'locations' => [],
                        'totals' => ['amount' => 0, 'credits' => 0, 'txns' => 0]
                    ];
                }

                if (!isset($results[$sortKey]['locations'][$locId])) {
                    $results[$sortKey]['locations'][$locId] = [
                        'categories' => array_fill_keys($allCategories, ['amount' => 0, 'count' => 0, 'credits' => 0]),
                        'totalAmount' => 0
                    ];
                }

                $results[$sortKey]['locations'][$locId]['categories'][$category]['amount'] += $amount;
                $results[$sortKey]['locations'][$locId]['categories'][$category]['count']++;
                $results[$sortKey]['locations'][$locId]['categories'][$category]['credits'] += $creditCount;
                $results[$sortKey]['locations'][$locId]['totalAmount'] += $amount;
                
                $results[$sortKey]['totals']['amount'] += $amount;
                $results[$sortKey]['totals']['credits'] += $creditCount;
                $results[$sortKey]['totals']['txns'] += 1;
            }
            fclose($handle);
        }
    }
    krsort($results);
    return $results;
}

$data = processCsvFiles(getCsvFiles($csvDirectory));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Billing Summary</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: 'Inter', sans-serif; color: #334155; }
        .navbar { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 0.75rem 0; }
        .nav-brand { font-weight: 700; color: #0f172a; letter-spacing: -0.02em; }
        
        .stat-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem; }
        .stat-label { font-size: 0.7rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.25rem; }
        .stat-value { font-size: 1.25rem; font-weight: 700; color: #0f172a; }

        .card-container { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-top: 1.5rem; }
        .table thead th { background: #f8fafc; color: #64748b; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; padding: 1rem; border-bottom: 1px solid #e2e8f0; }
        .location-row { background: #f1f5f9; font-weight: 600; color: #1e293b; }
        
        .dot { height: 8px; width: 8px; border-radius: 50%; display: inline-block; margin-right: 10px; }
        .dot-whatsapp { background-color: #22c55e; }
        .dot-email { background-color: #3b82f6; }
        .dot-workflow { background-color: #a855f7; }
        .dot-conversationAI { background-color: #f59e0b; }
        .dot-other { background-color: #94a3b8; }

        .month-section { display: none; }
        .month-section.active { display: block; animation: fadeIn 0.3s ease; }
        
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        /* Filter Styles */
        .filter-label { font-size: 0.7rem; font-weight: 700; color: #94a3b8; margin-right: 8px; }
        .form-select-custom { border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem; font-weight: 500; padding: 0.4rem 2rem 0.4rem 0.75rem; }
    </style>
</head>
<body>

<nav class="navbar sticky-top">
    <div class="container d-flex flex-wrap justify-content-between align-items-center">
        <span class="nav-brand fs-5">Analytics</span>
        
        <div class="d-flex gap-3 align-items-center">
            <div class="d-flex align-items-center">
                <span class="filter-label">MONTH</span>
                <select id="monthSelector" class="form-select-custom shadow-sm">
                    <?php foreach ($data as $key => $content): ?>
                        <option value="section-<?= $key ?>"><?= htmlspecialchars($content['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="d-flex align-items-center">
                <span class="filter-label">CATEGORY</span>
                <select id="categorySelector" class="form-select-custom shadow-sm">
                    <option value="all">All Categories</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="email">Email</option>
                    <option value="workflow">Workflow</option>
                    <option value="conversationAI">Voice AI</option>
                    <option value="other">Other</option>
                </select>
            </div>
        </div>
    </div>
</nav>

<div class="container py-4">
    <?php foreach ($data as $key => $content): ?>
        <div id="section-<?= $key ?>" class="month-section" 
             data-total-rm="<?= $content['totals']['amount'] ?>" 
             data-total-credits="<?= $content['totals']['credits'] ?>" 
             data-total-txns="<?= $content['totals']['txns'] ?>">
            
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="stat-card shadow-sm">
                        <div class="stat-label">Total Spend</div>
                        <div class="stat-value" id="val-rm-<?= $key ?>">RM <?= number_format($content['totals']['amount'], 2) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card shadow-sm">
                        <div class="stat-label">Credit Consumption</div>
                        <div class="stat-value" id="val-credits-<?= $key ?>"><?= number_format($content['totals']['credits'], 0) ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-card shadow-sm">
                        <div class="stat-label">Transactions</div>
                        <div class="stat-value" id="val-txns-<?= $key ?>"><?= number_format($content['totals']['txns']) ?></div>
                    </div>
                </div>
            </div>

            <div class="card-container shadow-sm">
                <table class="table mb-0 align-middle">
                    <thead>
                        <tr>
                            <th style="width: 45%">Location / Category</th>
                            <th>Credits</th>
                            <th>Count</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($content['locations'] as $locId => $locData): ?>
                            <tr class="location-row" data-loc-id="<?= htmlspecialchars($locId) ?>">
                                <td colspan="3"><?= htmlspecialchars($locId) ?></td>
                                <td class="text-end" data-loc-total-cell>RM <?= number_format($locData['totalAmount'], 2) ?></td>
                            </tr>
                            <?php foreach ($locData['categories'] as $cat => $cData): ?>
                                <tr class="cat-row cat-type-<?= $cat ?>" 
                                    data-cat="<?= $cat ?>" 
                                    data-rm="<?= $cData['amount'] ?>" 
                                    data-credits="<?= $cData['credits'] ?>" 
                                    data-txns="<?= $cData['count'] ?>"
                                    style="<?= $cData['count'] == 0 ? 'display:none;' : '' ?>">
                                    <td class="ps-5">
                                        <div class="d-flex align-items-center small fw-500">
                                            <span class="dot dot-<?= $cat ?>"></span>
                                            <?= ($cat === 'conversationAI') ? 'Voice AI' : ucfirst($cat) ?>
                                        </div>
                                    </td>
                                    <td class="text-muted small"><?= number_format($cData['credits'], 2) ?></td>
                                    <td class="text-muted small"><?= $cData['count'] ?> txns</td>
                                    <td class="text-end fw-600 small">RM <?= number_format($cData['amount'], 2) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
    const monthSelector = document.getElementById('monthSelector');
    const categorySelector = document.getElementById('categorySelector');

    function applyFilters() {
        const selectedMonthId = monthSelector.value;
        const selectedCat = categorySelector.value;

        // 1. Handle Month Visibility
        document.querySelectorAll('.month-section').forEach(el => el.classList.remove('active'));
        const currentMonth = document.getElementById(selectedMonthId);
        if (!currentMonth) return;
        currentMonth.classList.add('active');

        // 2. Handle Category Row Visibility & Recalculate Totals
        let pageTotalRM = 0;
        let pageTotalCredits = 0;
        let pageTotalTxns = 0;

        // We only care about rows inside the ACTIVE month
        const rows = currentMonth.querySelectorAll('.cat-row');
        const locHeaders = currentMonth.querySelectorAll('.location-row');

        locHeaders.forEach(header => {
            header.style.display = 'none'; // Default hide, show if category matches
            let locTotal = 0;
            
            // Find all category rows belonging to this location
            let sibling = header.nextElementSibling;
            while (sibling && sibling.classList.contains('cat-row')) {
                const catType = sibling.getAttribute('data-cat');
                const txnAmount = parseFloat(sibling.getAttribute('data-txns'));
                
                const matchesCat = (selectedCat === 'all' || selectedCat === catType);
                const hasData = txnAmount > 0;

                if (matchesCat && hasData) {
                    sibling.style.display = 'table-row';
                    header.style.display = 'table-row';
                    
                    // Add to totals
                    const rm = parseFloat(sibling.getAttribute('data-rm'));
                    const creds = parseFloat(sibling.getAttribute('data-credits'));
                    
                    locTotal += rm;
                    pageTotalRM += rm;
                    pageTotalCredits += creds;
                    pageTotalTxns += txnAmount;
                } else {
                    sibling.style.display = 'none';
                }
                sibling = sibling.nextElementSibling;
            }
            // Update Location Header Total
            header.querySelector('[data-loc-total-cell]').innerText = 'RM ' + locTotal.toLocaleString(undefined, {minimumFractionDigits: 2});
        });

        // 3. Update Summary Cards
        const key = selectedMonthId.replace('section-', '');
        document.getElementById('val-rm-' + key).innerText = 'RM ' + pageTotalRM.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('val-credits-' + key).innerText = pageTotalCredits.toLocaleString(undefined, {maximumFractionDigits: 0});
        document.getElementById('val-txns-' + key).innerText = pageTotalTxns.toLocaleString() + ' logs';
    }

    monthSelector.addEventListener('change', applyFilters);
    categorySelector.addEventListener('change', applyFilters);

    // Initial run
    window.onload = applyFilters;
</script>

</body>
</html>