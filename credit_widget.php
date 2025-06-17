<?php

// Function to get the latest USD to RM exchange rate (placeholder)
function getUsdToRmExchangeRate() {
    // In a real application, use an API like ExchangeRate-API, Alpha Vantage, or others
    // Example: $response = file_get_contents('https://api.exchangerate-api.com/v4/latest/USD');
    // $data = json_decode($response, true);
    // return $data['rates']['MYR'];
    
    // For now, return a sample rate (update with real API call)
    return 4.35; // Sample USD to MYR rate as of recent data
}

// Function to get all CSV files from a directory
function getCsvFiles($directory) {
    $csvFiles = [];
    if (!is_dir($directory)) {
        echo "Directory not found: $directory<br>";
        return $csvFiles;
    }
    $files = glob($directory . '/*.csv');
    if (empty($files)) {
        echo "No CSV files found in: $directory<br>";
    } else {
        $csvFiles = $files;
    }
    return $csvFiles;
}

// Function to process CSV files and gather location and type data
function processCsvFiles($csvFiles) {
    $results = [];
    $exchangeRate = getUsdToRmExchangeRate(); // Get exchange rate
    
    foreach ($csvFiles as $filePath) {
        if (!file_exists($filePath)) {
            echo "File not found: $filePath<br>";
            continue;
        }

        $file = fopen($filePath, 'r');
        if ($file === false) {
            echo "Failed to open file: $filePath<br>";
            continue;
        }

        $header = fgetcsv($file);
        if ($header === false) {
            echo "Failed to read header from: $filePath<br>";
            fclose($file);
            continue;
        }

        $locationIdIndex = array_search('locationId', $header);
        $locationNameIndex = array_search('locationName', $header);
        $typeIndex = array_search('type', $header);
        $amountIndex = array_search('amount', $header);

        if ($locationIdIndex === false || $amountIndex === false || $typeIndex === false) {
            echo "Required columns (locationId, type, or amount) not found in: $filePath<br>";
            fclose($file);
            continue;
        }

        while (($row = fgetcsv($file)) !== false) {
            if (empty($row[$locationIdIndex]) || !isset($row[$amountIndex]) || empty($row[$typeIndex])) {
                continue;
            }

            $locationId = trim($row[$locationIdIndex]);
            $locationName = isset($locationNameIndex) && !empty($row[$locationNameIndex]) ? trim($row[$locationNameIndex]) : $locationId;
            $type = trim($row[$typeIndex]);
            $amountUsd = floatval($row[$amountIndex]); // Amount in USD
            $amountRm = $amountUsd * $exchangeRate; // Convert to RM

            if (!isset($results[$locationId])) {
                $results[$locationId] = [
                    'locationName' => $locationName,
                    'totalAmount' => 0,
                    'count' => 0,
                    'types' => []
                ];
            }

            if (!isset($results[$locationId]['types'][$type])) {
                $results[$locationId]['types'][$type] = [
                    'totalAmount' => 0,
                    'count' => 0
                ];
            }

            $results[$locationId]['totalAmount'] += $amountRm;
            $results[$locationId]['count']++;
            $results[$locationId]['types'][$type]['totalAmount'] += $amountRm;
            $results[$locationId]['types'][$type]['count']++;
        }

        fclose($file);
    }

    return $results;
}

// Process the CSV files
$csvDirectory = __DIR__ . '/csv_files'; 
$csvFiles = getCsvFiles($csvDirectory);
$processedData = processCsvFiles($csvFiles);

if (empty($processedData)) {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHL Credits Dashboard</title>
</head>
<body>
    <div style="text-align: center; padding: 20px;">
        <h2>No valid data processed from the CSV files.</h2>
    </div>
</body>
</html>
HTML;
    exit;
}

// Prepare subaccount data
$defaultTotalAmountRm = 2412; // Hardcoded total amount in RM
$exchangeRate = getUsdToRmExchangeRate(); // Get exchange rate for calculations
$subaccountData = [];
$allSubaccounts = [
    'totalAmount' => 0,
    'usedAmount' => 0,
    'remainingAmount' => 0,
    'totalCredit' => 0,
    'usedCredit' => 0,
    'remainingCredit' => 0,
    'percent' => 0,
    'max' => 0
];

// Calculate totals for "All Subaccounts"
$totalUsedRm = 0;
foreach ($processedData as $locationId => $data) {
    $totalUsedRm += $data['totalAmount'];
}
$totalAmountAllRm = $defaultTotalAmountRm * count($processedData); // Total for all subaccounts
$allSubaccounts['usedAmount'] = $totalUsedRm;
$allSubaccounts['totalAmount'] = $totalAmountAllRm;
$allSubaccounts['remainingAmount'] = $totalAmountAllRm - $totalUsedRm;
$allSubaccounts['totalCredit'] = $allSubaccounts['totalAmount'] * 2;
$allSubaccounts['usedCredit'] = $allSubaccounts['usedAmount'] * 2;
$allSubaccounts['remainingCredit'] = $allSubaccounts['remainingAmount'] * 2;
$allSubaccounts['percent'] = $allSubaccounts['totalAmount'] > 0 ? min(100, round(($allSubaccounts['usedAmount'] / $allSubaccounts['totalAmount']) * 100)) : 0;
$allSubaccounts['max'] = $allSubaccounts['totalAmount'];
$subaccountData[''] = $allSubaccounts;

// Calculate for individual subaccounts
foreach ($processedData as $locationId => $data) {
    $usedAmountRm = $data['totalAmount']; // Already in RM from processCsvFiles
    $totalAmountRm = $defaultTotalAmountRm;
    $remainingAmountRm = max(0, $totalAmountRm - $usedAmountRm);
    $percentUsed = $totalAmountRm > 0 ? min(100, round(($usedAmountRm / $totalAmountRm) * 100)) : 0;

    $subaccountData[$locationId] = [
        'totalAmount' => $totalAmountRm,
        'usedAmount' => $usedAmountRm,
        'remainingAmount' => $remainingAmountRm,
        'totalCredit' => $totalAmountRm * 2,
        'usedCredit' => $usedAmountRm * 2,
        'remainingCredit' => $remainingAmountRm * 2,
        'percent' => $percentUsed,
        'max' => $totalAmountRm,
        'name' => $data['locationName'],
        'types' => $data['types']
    ];
}

// Convert subaccountData to JSON for JavaScript
$subaccountDataJson = json_encode($subaccountData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHL Credits Dashboard</title>
    <style>
    :root {
        --ghl-primary: #ff99cc;
        --ghl-primary-light: #ffc1e0;
        --ghl-dark: #2d3748;
        --ghl-gray: #718096;
        --ghl-light-gray: #f7fafc;
        --ghl-border: #e2e8f0;
        --ghl-card-shadow: 0 10px 30px rgba(255, 153, 204, 0.15);
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background: linear-gradient(135deg, #fff5f8 0%, #fff0f5 100%);
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }
    
    .ghl-dashboard {
        width: 100%;
        max-width: 800px;
        background: white;
        border-radius: 16px;
        box-shadow: var(--ghl-card-shadow);
        overflow: hidden;
        transform: translateY(0);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    
    .ghl-dashboard:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 40px rgba(255, 153, 204, 0.2);
    }
    
    .ghl-header {
        background: linear-gradient(135deg, var(--ghl-primary) 0%, var(--ghl-primary-light) 100%);
        color: white;
        padding: 24px;
        position: relative;
        overflow: hidden;
    }
    
    .ghl-header::after {
        content: '';
        position: absolute;
        top: -50px;
        right: -50px;
        width: 150px;
        height: 150px;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 50%;
    }
    
    .ghl-header-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        position: relative;
        z-index: 2;
    }
    
    .ghl-title {
        font-size: 20px;
        font-weight: 700;
    }
    
    .ghl-logo {
        width: 36px;
        height: 36px;
        background: white;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .ghl-logo svg {
        width: 20px;
        height: 20px;
        color: var(--ghl-primary);
    }
    
    .ghl-amount-summary {
        display: flex;
        justify-content: space-between;
        margin-top: 16px;
        position: relative;
        z-index: 2;
        display: none; /* Hidden by default */
        padding: 16px;
        border-radius: 8px;
    }

    .ghl-credit-summary {
        display: flex;
        justify-content: space-between;
        margin-top: 16px;
        position: relative;
        z-index: 2;
        display: none; /* Hidden by default */
        padding: 16px;
        border-radius: 8px;
    }
    
    .ghl-credit-total {
        text-align: center;
        flex: 1;
    }
    
    .ghl-credit-number {
        font-size: 32px;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 10px;
        color: white;
    }
    
    .ghl-credit-label {
        font-size: 14px;
        font-weight: 500;
        color: white;
        text-transform: uppercase;
    }
    
    .ghl-amount-total {
        text-align: center;
        flex: 1;
    }
    
    .ghl-amount-number {
        font-size: 32px;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 10px;
        color: white;
    }
    
    .ghl-amount-label {
        font-size: 14px;
        font-weight: 500;
        color: white;
        text-transform: uppercase;
    }
    
    .ghl-content {
        padding: 24px;
    }
    
    .ghl-progress-section {
        margin-bottom: 18px;
        display: none; /* Hidden by default */
        padding: 10px;
        border-radius: 8px;
    }
    
    .ghl-progress-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 12px;
    }
    
    .ghl-section-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--ghl-dark);
        margin-bottom: 20px
    }
    
    .ghl-progress-percent {
        font-size: 14px;
        font-weight: 600;
        color: var(--ghl-primary);
    }
    
    .ghl-progress-bar {
        height: 10px;
        background: var(--ghl-light-gray);
        border-radius: 5px;
        overflow: hidden;
        margin-bottom: 8px;
    }
    
    .ghl-progress-fill {
        height: 100%;
        border-radius: 5px;
        background: var(--ghl-primary); /* Fallback solid color */
        background: linear-gradient(90deg, var(--ghl-primary), var(--ghl-primary-light)); /* Gradient */
        width: 0;
        transition: width 0.6s cubic-bezier(0.65, 0, 0.35, 1);
    }
    
    .ghl-progress-labels {
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        color: var(--ghl-gray);
    }
    
    .ghl-subaccount-select {
        width: 100%; /* Already set, but ensuring consistency */
        max-width: 100%; /* Prevent exceeding parent width */
        padding: 18px 24px;
        border-radius: 14px;
        border: none;
        background: linear-gradient(135deg, rgba(255, 255, 255, 0.35), rgba(255, 255, 255, 0.25));
        font-size: 16px;
        font-weight: 600;
        color: white;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23ffffff'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 24px center;
        background-size: 24px;
        box-shadow: 0 6px 14px rgba(255, 153, 204, 0.2);
        transition: transform 0.3s ease, box-shadow 0.3s ease, background 0.3s ease; /* Separate transitions */
        backdrop-filter: blur(6px);
        position: relative;
        text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        z-index: 2;
        box-sizing: border-box; /* Ensure padding doesn't affect width */
    }
    
    .ghl-subaccount-select:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 24px rgba(255, 153, 204, 0.3);
        background: linear-gradient(135deg, rgba(255, 255, 252, 0.45), rgba(255, 255, 255, 0.35));
    }
    
    .ghl-subaccount-select:focus {
        outline: none;
        box-shadow: 0 0 0 4px rgba(255, 153, 204, 0.3);
    }
    
    .ghl-subaccount-select option {
        color: var(--ghl-dark);
        background: white;
        font-weight: 500;
        max-width: 100%; /* Prevent option text from forcing wider dropdown */
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    
    .ghl-subaccounts-toggle {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 12px 0;
        user-select: none;
    }
    
    .ghl-subaccounts-toggle:hover {
        background-color: rgba(255, 153, 204, 0.05);
    }
    
    .ghl-subaccounts-toggle svg {
        width: 18px;
        height: 18px;
        color: var(--ghl-primary);
        transition: transform 0.3s ease;
    }
    
    .ghl-subaccounts-content {
        max-height: 500px;
        overflow-y: auto;
    }
    
    .ghl-subaccounts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 12px;
    }
    
    .ghl-subaccount {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px;
        border: 1px solid var(--ghl-border);
        border-radius: 8px;
        background: var(--ghl-light-gray);
    }
    
    .ghl-subaccount-info {
        display: flex;
        align-items: center;
    }
    
    .ghl-subaccount-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
    }
    
    .ghl-subaccount-icon svg {
        width: 18px;
        height: 18px;
        color: var(--ghl-primary);
    }
    
    .ghl-subaccount-name {
        font-weight: 500;
        color: var(--ghl-dark);
        margin-bottom: 2px;
    }
    
    .ghl-subaccount-status {
        font-size: 12px;
        color: var(--ghl-gray);
    }
    
    .ghl-subaccount-credits {
        font-weight: 600;
        color: var(--ghl-dark);
    }
    
    .ghl-rotate {
        transform: rotate(180deg);
    }

    .ghl-types-section {
        margin-bottom: 24px;
        display: none;
        padding: 10px;
        border-radius: 8px;
    }

    .ghl-types-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 12px;
    }

    .ghl-type {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px;
        border: 1px solid var(--ghl-border);
        border-radius: 8px;
        background: var(--ghl-light-gray);
    }

    .ghl-type-info {
        display: flex;
        align-items: center;
    }

    .ghl-type-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: white;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
    }

    .ghl-type-icon svg {
        width: 18px;
        height: 18px;
        color: var(--ghl-primary);
    }

    .ghl-type-name {
        font-weight: 500;
        color: var(--ghl-dark);
        margin-bottom: 2px;
    }

    .ghl-type-status {
        font-size: 12px;
        color: var(--ghl-gray);
    }

    .ghl-type-credits {
        font-weight: 600;
        color: var(--ghl-dark);
    }

    .sparkle {
        position: absolute;
        width: var(--size);
        height: var(--size);
        background: var(--color);
        border-radius: 50%;
        pointer-events: none;
        animation: sparkle 1s ease-out forwards;
        box-shadow: 0 0 12px var(--color), 0 0 24px var(--color);
        z-index: 3;
    }
    
    @keyframes sparkle {
        0% {
            transform: scale(0.5) rotate(0deg);
            opacity: 1;
        }
        50% {
            transform: scale(1.2) rotate(180deg);
            opacity: 0.8;
        }
        100% {
            transform: scale(0.8) rotate(360deg) translate(var(--tx), var(--ty));
            opacity: 0;
        }
    }

    /* Class to show hidden sections */
    .ghl-credit-summary.visible {
        display: flex; /* For credit summary */
    }

    .ghl-amount-summary.visible {
        display: flex; /* For amount summary */
    }

    .ghl-progress-section.visible {
        display: block; /* For progress section */
    }

    .ghl-types-section.visible {
        display: block; /* For types section */
    }

    /* Class to hide subaccounts section */
    .ghl-subaccounts-toggle.hidden,
    .ghl-subaccounts-content.hidden {
        display: none;
    }
</style>
</head>
<body>
    <div class="ghl-dashboard">
        <div class="ghl-header">
            <div class="ghl-header-top">
                <div class="ghl-title">Credits Dashboard</div>
                <div class="ghl-logo">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>
        
            <select id="subaccountSelect" class="ghl-subaccount-select">
                <option value="">All Subaccounts</option>
                <?php
                foreach ($processedData as $locationId => $data) {
                    $locationName = htmlspecialchars($data['locationName']);
                    echo "<option value=\"{$locationId}\">{$locationName}</option>\n";
                }
                ?>
            </select>
            
            <div class="ghl-amount-summary">
                <div class="ghl-amount-total">
                    <div id="subaccountAmountTotal" class="ghl-amount-number"><?php echo number_format($subaccountData['']['totalAmount'], 2); ?></div>
                    <div class="ghl-amount-label">Total Amount (RM)</div>
                </div>
                <div class="ghl-amount-total">
                    <div id="subaccountAmountUsed" class="ghl-amount-number"><?php echo number_format($subaccountData['']['usedAmount'], 2); ?></div>
                    <div class="ghl-amount-label">Used Amount (RM)</div>
                </div>
                <div class="ghl-amount-total">
                    <div id="subaccountAmountRemaining" class="ghl-amount-number"><?php echo number_format($subaccountData['']['remainingAmount'], 2); ?></div>
                    <div class="ghl-amount-label">Remaining Amount (RM)</div>
                </div>
            </div>

            <div class="ghl-credit-summary">
                <div class="ghl-credit-total">
                    <div id="subaccountCreditTotal" class="ghl-credit-number"><?php echo number_format($subaccountData['']['totalCredit']); ?></div>
                    <div class="ghl-credit-label">Total Credit</div>
                </div>
                <div class="ghl-credit-total">
                    <div id="subaccountCreditUsed" class="ghl-credit-number"><?php echo number_format($subaccountData['']['usedCredit']); ?></div>
                    <div class="ghl-credit-label">Used Credit</div>
                </div>
                <div class="ghl-credit-total">
                    <div id="subaccountCreditRemaining" class="ghl-credit-number"><?php echo number_format($subaccountData['']['remainingCredit']); ?></div>
                    <div class="ghl-credit-label">Remaining Credit</div>
                </div>
            </div>
        </div>
        
        <div class="ghl-content">
            <div class="ghl-progress-section">
                <div class="ghl-progress-header">
                    <div class="ghl-section-title">Amount Usage</div>
                    <div class="ghl-progress-percent"><?php echo $subaccountData['']['percent']; ?>% Used</div>
                </div>
                <div class="ghl-progress-bar">
                    <div class="ghl-progress-fill"></div>
                </div>
                <div class="ghl-progress-labels">
                    <div>0</div>
                    <div><?php echo number_format(round($subaccountData['']['max'] / 2), 2); ?></div>
                    <div><?php echo number_format($subaccountData['']['max'], 2); ?></div>
                </div>
            </div>

            <div id="typesSection" class="ghl-types-section">
                <div class="ghl-section-title">Amount Usage by Type</div>
                <div id="typesGrid" class="ghl-types-grid">
                    <!-- Type items will be populated dynamically -->
                </div>
            </div>
                        
            <div id="subaccountsToggle" class="ghl-subaccounts-toggle">
                <div class="ghl-section-title">Sub Accounts (<?php echo count($processedData); ?>)</div>
            </div>
            
            <div id="subaccountsContent" class="ghl-subaccounts-content">
                <div class="ghl-subaccounts-grid">
                    <?php
                    $icons = [
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>',
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>',
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
                    ];
                    $iconIndex = 0;

                    foreach ($processedData as $locationId => $data) {
                        $locationName = htmlspecialchars($data['locationName']);
                        $usedAmountRm = $data['totalAmount'];
                        $usedAmountFormatted = number_format($usedAmountRm, 2);
                        $status = $usedAmountRm > 0 ? 'Active' : 'Inactive';
                        $icon = $icons[$iconIndex % count($icons)];
                        $iconIndex++;

                        echo <<<HTML
<div class="ghl-subaccount">
    <div class="ghl-subaccount-info">
        <div class="ghl-subaccount-icon">
            {$icon}
        </div>
        <div>
            <div class="ghl-subaccount-name">{$locationName}</div>
            <div class="ghl-subaccount-status">{$status} • {$usedAmountFormatted} RM used</div>
        </div>
    </div>
    <div class="ghl-subaccount-credits">{$usedAmountFormatted}</div>
</div>
HTML;
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Elements to show/hide 
            const creditSummary = document.querySelector('.ghl-credit-summary');  
            const amountSummary = document.querySelector('.ghl-amount-summary');  
            const progressSection = document.querySelector('.ghl-progress-section');
            const typesSection = document.getElementById('typesSection');
            const typesGrid = document.getElementById('typesGrid');
            const subaccountsToggle = document.getElementById('subaccountsToggle');
            const subaccountsContent = document.getElementById('subaccountsContent');
            
            // Elements for updating values
            const subaccountAmountTotal = document.getElementById('subaccountAmountTotal');
            const subaccountAmountUsed = document.getElementById('subaccountAmountUsed');
            const subaccountAmountRemaining = document.getElementById('subaccountAmountRemaining');
            const subaccountCreditTotal = document.getElementById('subaccountCreditTotal');
            const subaccountCreditUsed = document.getElementById('subaccountCreditUsed');
            const subaccountCreditRemaining = document.getElementById('subaccountCreditRemaining');
            const progressFill = document.querySelector('.ghl-progress-fill');
            const progressPercent = document.querySelector('.ghl-progress-percent');
            const progressLabels = document.querySelectorAll('.ghl-progress-labels div');
            
            // Subaccount selection
            const subaccountSelect = document.getElementById('subaccountSelect');
            const subaccountData = <?php echo $subaccountDataJson; ?>;
            
            // Type icons
            const typeIcons = [
                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>',
                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
            ];

            subaccountSelect.addEventListener('change', function(e) {
                const selected = this.value;
                const data = subaccountData[selected];

                // Reset sections
                creditSummary.classList.remove('visible');
                amountSummary.classList.remove('visible');
                progressSection.classList.remove('visible');
                typesSection.classList.remove('visible');
                subaccountsToggle.classList.remove('hidden');
                subaccountsContent.classList.remove('hidden');

                if (selected === '') {
                    // Show subaccounts section for "All Subaccounts"
                    subaccountsToggle.classList.remove('hidden');
                    subaccountsContent.classList.remove('hidden');
                } else {
                    // Show summaries, progress, and types section for specific subaccount
                    creditSummary.classList.add('visible');
                    amountSummary.classList.add('visible');
                    progressSection.classList.add('visible');
                    typesSection.classList.add('visible');
                    // Hide subaccounts section
                    subaccountsToggle.classList.add('hidden');
                    subaccountsContent.classList.add('hidden');

                    // Update amount summary
                    subaccountAmountTotal.textContent = data.totalAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    subaccountAmountUsed.textContent = data.usedAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    subaccountAmountRemaining.textContent = data.remainingAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                    // Update credit summary
                    subaccountCreditTotal.textContent = data.totalCredit.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    subaccountCreditUsed.textContent = data.usedCredit.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    subaccountCreditRemaining.textContent = data.remainingCredit.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });

                    // Update progress section
                    progressFill.style.width = `${data.percent}%`;
                    progressPercent.textContent = `${data.percent}% Used`;
                    progressLabels[0].textContent = '0';
                    progressLabels[1].textContent = (Math.round(data.max / 2)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    progressLabels[2].textContent = data.max.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });

                    // Animate progress bar
                    setTimeout(() => {
                        progressFill.style.width = `${data.percent}%`;
                    }, 300);

                    // Populate types grid
                    typesGrid.innerHTML = '';
                    let iconIndex = 0;
                    for (const [type, typeData] of Object.entries(data.types)) {
                        const totalAmount = typeData.totalAmount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        const count = typeData.count;
                        const icon = typeIcons[iconIndex % typeIcons.length];
                        iconIndex++;
                        const typeElement = `
                            <div class="ghl-type">
                                <div class="ghl-type-info">
                                    <div class="ghl-type-icon">
                                        ${icon}
                                    </div>
                                    <div>
                                        <div class="ghl-type-name">${type}</div>
                                        <div class="ghl-type-status">${count} transaction${count !== 1 ? 's' : ''}</div>
                                    </div>
                                </div>
                                <div class="ghl-type-credits">${totalAmount} </div>
                            </div>
                        `;
                        typesGrid.insertAdjacentHTML('beforeend', typeElement);
                    }
                }

                // Sparkle animation
                const rect = this.getBoundingClientRect();
                const x = rect.width / 2;
                const y = rect.height / 2;
                
                const colors = ['#ffffff', '#fff0f5', '#ffc1e0', '#ff99cc'];
                for (let i = 0; i < 15; i++) {
                    const sparkle = document.createElement('div');
                    sparkle.className = 'sparkle';
                    const angle = Math.random() * Math.PI * 2;
                    const distance = 40 + Math.random() * 40;
                    const size = 5 + Math.random() * 8;
                    const color = colors[Math.floor(Math.random() * colors.length)];
                    
                    sparkle.style.left = `${x}px`;
                    sparkle.style.top = `${y}px`;
                    sparkle.style.setProperty('--size', `${size}px`);
                    sparkle.style.setProperty('--color', color);
                    sparkle.style.setProperty('--tx', `${Math.cos(angle) * distance}px`);
                    sparkle.style.setProperty('--ty', `${Math.sin(angle) * distance}px`);
                    sparkle.style.animationDelay = `${i * 0.05}s`;
                    
                    this.appendChild(sparkle);
                    
                    setTimeout(() => {
                        sparkle.remove();
                    }, 1000);
                }
            });
        });
    </script>
</body>
</html>