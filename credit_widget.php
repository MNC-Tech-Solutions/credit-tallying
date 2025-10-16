<?php
// Get locationId from query parameter with validation
$targetLocationId = isset($_GET['locationId']) ? trim($_GET['locationId']) : '';
$isDemo = $targetLocationId === 'WphrMU0x3Ocd2pEpBJcH';
if ($targetLocationId !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $targetLocationId)) {
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
        <h2>Invalid subaccount ID.</h2>
    </div>
</body>
</html>
HTML;
    exit;
}

// Function to get all CSV files from a directory
function getCsvFiles($directory) {
    $csvFiles = [];
    if (!is_dir($directory)) {
        error_log("Directory not found: $directory");
        return $csvFiles;
    }
    $files = glob($directory . '/*.csv');
    if (empty($files)) {
        error_log("No CSV files found in: $directory");
    } else {
        $csvFiles = $files;
    }
    return $csvFiles;
}

// Function to read WhatsApp and Email credit limits from total_credits.csv
function getCreditLimits($filePath, $targetLocationId = null) {
    $creditLimits = [];
    if (!file_exists($filePath)) {
        error_log("Credit limits file not found: $filePath");
        return $creditLimits;
    }
    $file = fopen($filePath, 'r');
    if ($file === false) {
        error_log("Failed to open credit limits file: $filePath");
        return $creditLimits;
    }
    $header = fgetcsv($file);
    if ($header === false) {
        error_log("Failed to read header from credit limits file: $filePath");
        fclose($file);
        return $creditLimits;
    }
    $locationIdIndex = array_search('locationId', $header);
    $whatsappCreditIndex = array_search('totalAmount', $header);
    $emailCreditIndex = array_search('emailCredits', $header);
    if ($locationIdIndex === false || $whatsappCreditIndex === false || $emailCreditIndex === false) {
        error_log("Required columns (locationId, totalAmount, or emailCredits) not found in: $filePath");
        fclose($file);
        return $creditLimits;
    }
    while (($row = fgetcsv($file)) !== false) {
        if (empty($row[$locationIdIndex]) || !isset($row[$whatsappCreditIndex]) || !isset($row[$emailCreditIndex])) {
            continue;
        }
        $locationId = trim($row[$locationIdIndex]);
        if ($targetLocationId !== null && $locationId !== $targetLocationId) {
            continue;
        }
        $whatsappCredit = floatval($row[$whatsappCreditIndex]);
        $emailCredit = floatval($row[$emailCreditIndex]);
        $creditLimits[$locationId] = [
            'whatsappCredit' => $whatsappCredit,
            'emailCredit' => $emailCredit
        ];
    }
    fclose($file);
    return $creditLimits;
}

// Function to process CSV files and gather location, type, and date range data
function processCsvFiles($csvFiles, $targetLocationId = null) {
    $results = [];
    foreach ($csvFiles as $filePath) {
        if (basename($filePath) === 'total_credits.csv') {
            continue;
        }
        if (!file_exists($filePath)) {
            error_log("File not found: $filePath");
            continue;
        }
        $file = fopen($filePath, 'r');
        if ($file === false) {
            error_log("Failed to open file: $filePath");
            continue;
        }
        $header = fgetcsv($file);
        if ($header === false) {
            error_log("Failed to read header from: $filePath");
            fclose($file);
            continue;
        }
        $locationIdIndex = array_search('locationId', $header);
        $locationNameIndex = array_search('locationName', $header);
        $typeIndex = array_search('type', $header);
        $amountIndex = array_search('amount', $header);
        $dateIndex = array_search('date', $header);
        if ($locationIdIndex === false || $amountIndex === false || $typeIndex === false) {
            error_log("Required columns (locationId, type, or amount) not found in: $filePath");
            fclose($file);
            continue;
        }
        while (($row = fgetcsv($file)) !== false) {
            if (empty($row[$locationIdIndex]) || !isset($row[$amountIndex]) || empty($row[$typeIndex])) {
                continue;
            }
            $locationId = trim($row[$locationIdIndex]);
            if ($targetLocationId !== null && $locationId !== $targetLocationId) {
                continue;
            }
            $type = trim($row[$typeIndex]);
            // Only process types exactly "Emails" or containing "WhatsApp"
            if (strcasecmp($type, 'Emails') !== 0 && stripos($type, 'WhatsApp') === false) {
                continue;
            }
            $locationName = isset($locationNameIndex) && !empty($row[$locationNameIndex]) ? trim($row[$locationNameIndex]) : $locationId;
            // Set fixed amounts: 0.50 RM for WhatsApp (1 credit), 0.005 RM for Emails (1 credit)
            $amountRm = stripos($type, 'WhatsApp') !== false ? 0.50 : 0.005;
            if (!isset($results[$locationId])) {
                $results[$locationId] = [
                    'locationName' => $locationName,
                    'emailAmount' => 0,
                    'whatsappAmount' => 0,
                    'emailCount' => 0,
                    'whatsappCount' => 0,
                    'types' => [],
                    'transactionData' => []
                ];
            }
            if (!isset($results[$locationId]['types'][$type])) {
                $results[$locationId]['types'][$type] = [
                    'totalAmount' => 0,
                    'count' => 0
                ];
            }
            if (strcasecmp($type, 'Emails') === 0) {
                $results[$locationId]['emailAmount'] += $amountRm;
                $results[$locationId]['emailCount']++;
            } else {
                $results[$locationId]['whatsappAmount'] += $amountRm;
                $results[$locationId]['whatsappCount']++;
            }
            $results[$locationId]['types'][$type]['totalAmount'] += $amountRm;
            $results[$locationId]['types'][$type]['count']++;
            // Store transaction data
            if ($dateIndex !== false && !empty($row[$dateIndex])) {
                $dateStr = preg_replace('/(st|nd|rd|th)/', '', $row[$dateIndex]);
                $date = DateTime::createFromFormat('M j Y, h:i:s A', trim($dateStr));
                if ($date !== false) {
                    $dateKey = $date->format('Y-m-d H:i:s');
                    $monthlyType = strcasecmp($type, 'Emails') === 0 ? 'Emails' : 'WhatsApp';
                    if (!isset($results[$locationId]['transactionData'][$monthlyType])) {
                        $results[$locationId]['transactionData'][$monthlyType] = [];
                    }
                    $results[$locationId]['transactionData'][$monthlyType][] = [
                        'date' => $row[$dateIndex],
                        'amount' => $amountRm,
                        'originalType' => $type
                    ];
                } else {
                    error_log("Invalid date format in $filePath: " . $row[$dateIndex]);
                }
            }
        }
        fclose($file);
    }
    if (empty($results) && $targetLocationId !== null) {
        error_log("No data found for locationId: $targetLocationId");
    }
    return $results;
}

// Process the CSV files
$csvDirectory = __DIR__ . '/csv_files';
$creditLimitsFile = __DIR__ . '/total_credits.csv';
$csvFiles = getCsvFiles($csvDirectory);
$creditLimits = $isDemo ? getCreditLimits($creditLimitsFile) : getCreditLimits($creditLimitsFile, $targetLocationId);
$processedData = $isDemo ? processCsvFiles($csvFiles) : processCsvFiles($csvFiles, $targetLocationId);

if (empty($processedData) && $targetLocationId !== '') {
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
        <h2>No valid data found for this subaccount.</h2>
    </div>
</body>
</html>
HTML;
    exit;
}

// Prepare subaccount data
$subaccountData = [];
$allSubaccounts = [
    'emailAmount' => 0,
    'whatsappAmount' => 0,
    'emailUsedAmount' => 0,
    'whatsappUsedAmount' => 0,
    'emailRemainingAmount' => 0,
    'whatsappRemainingAmount' => 0,
    'emailCredit' => 0,
    'whatsappCredit' => 0,
    'emailUsedCredit' => 0,
    'whatsappUsedCredit' => 0,
    'emailRemainingCredit' => 0,
    'whatsappRemainingCredit' => 0,
    'emailPercent' => 0,
    'whatsappPercent' => 0,
    'emailMax' => 0,
    'whatsappMax' => 0,
    'types' => []
];

// Calculate totals for "All Subaccounts" (only for Demo, excluding Demo subaccount)
if ($isDemo) {
    $totalEmailUsedRm = 0;
    $totalWhatsappUsedRm = 0;
    $totalWhatsappCredit = 0;
    $totalEmailCredit = 0;
    $allTypes = [];
    foreach ($processedData as $locationId => $data) {
        if ($locationId === 'WphrMU0x3Ocd2pEpBJcH') {
            error_log("Skipping Demo subaccount (WphrMU0x3Ocd2pEpBJcH) for All Subaccounts aggregation");
            continue;
        }
        $totalEmailUsedRm += $data['emailAmount'];
        $totalWhatsappUsedRm += $data['whatsappAmount'];
        $totalWhatsappCredit += isset($creditLimits[$locationId]['whatsappCredit']) ? $creditLimits[$locationId]['whatsappCredit'] : 0;
        $totalEmailCredit += isset($creditLimits[$locationId]['emailCredit']) ? $creditLimits[$locationId]['emailCredit'] : 0;
        foreach ($data['types'] as $type => $typeData) {
            if (!isset($allTypes[$type])) {
                $allTypes[$type] = [
                    'totalAmount' => 0,
                    'count' => 0
                ];
            }
            $allTypes[$type]['totalAmount'] += $typeData['totalAmount'];
            $allTypes[$type]['count'] += $typeData['count'];
        }
    }
    $allSubaccounts['emailUsedAmount'] = $totalEmailUsedRm;
    $allSubaccounts['whatsappUsedAmount'] = $totalWhatsappUsedRm;
    $allSubaccounts['whatsappCredit'] = $totalWhatsappCredit;
    $allSubaccounts['whatsappAmount'] = $totalWhatsappCredit / 2;
    $allSubaccounts['whatsappRemainingAmount'] = max(0, $allSubaccounts['whatsappAmount'] - $allSubaccounts['whatsappUsedAmount']);
    $allSubaccounts['emailCredit'] = $totalEmailCredit;
    $allSubaccounts['emailUsedCredit'] = $totalEmailUsedRm / 0.005;
    $allSubaccounts['emailAmount'] = $totalEmailCredit * 0.005;
    $allSubaccounts['emailRemainingCredit'] = max(0, $allSubaccounts['emailCredit'] - $allSubaccounts['emailUsedCredit']);
    $allSubaccounts['emailRemainingAmount'] = $allSubaccounts['emailRemainingCredit'] * 0.005;
    $allSubaccounts['whatsappUsedCredit'] = $totalWhatsappUsedRm / 0.50;
    $allSubaccounts['whatsappRemainingCredit'] = $allSubaccounts['whatsappCredit'] - $allSubaccounts['whatsappUsedCredit'];
    $allSubaccounts['emailPercent'] = $allSubaccounts['emailAmount'] > 0 ? min(100, round($allSubaccounts['emailUsedAmount'] / $allSubaccounts['emailAmount'] * 100)) : 0;
    $allSubaccounts['whatsappPercent'] = $allSubaccounts['whatsappAmount'] > 0 ? min(100, round($allSubaccounts['whatsappUsedAmount'] / $allSubaccounts['whatsappAmount'] * 100)) : 0;
    $allSubaccounts['emailMax'] = $allSubaccounts['emailAmount'];
    $allSubaccounts['whatsappMax'] = $allSubaccounts['whatsappAmount'];
    $allSubaccounts['types'] = $allTypes;
    $subaccountData[''] = $allSubaccounts;
    error_log("All Subaccounts aggregated data (excluding Demo): " . json_encode($allSubaccounts));
}

// Calculate for individual subaccounts
foreach ($processedData as $locationId => $data) {
    $emailUsedAmountRm = $data['emailAmount'];
    $whatsappUsedAmountRm = $data['whatsappAmount'];
    $whatsappCredit = isset($creditLimits[$locationId]['whatsappCredit']) ? $creditLimits[$locationId]['whatsappCredit'] : 0;
    $emailCredit = isset($creditLimits[$locationId]['emailCredit']) ? $creditLimits[$locationId]['emailCredit'] : 0;
    $whatsappAmountRm = $whatsappCredit / 2;
    $emailAmountRm = $emailCredit * 0.005;
    $whatsappRemainingAmountRm = max(0, $whatsappAmountRm - $whatsappUsedAmountRm);
    $emailUsedCredit = $emailUsedAmountRm / 0.005;
    $emailRemainingCredit = max(0, $emailCredit - $emailUsedCredit);
    $emailRemainingAmountRm = $emailRemainingCredit * 0.005;
    $whatsappUsedCredit = $whatsappUsedAmountRm / 0.50;
    $whatsappRemainingCredit = $whatsappCredit - $whatsappUsedCredit;
    $emailPercentUsed = $emailAmountRm > 0 ? min(100, round($emailUsedAmountRm / $emailAmountRm * 100)) : 0;
    $whatsappPercentUsed = $whatsappAmountRm > 0 ? min(100, round($whatsappUsedAmountRm / $whatsappAmountRm * 100)) : 0;
    $subaccountData[$locationId] = [
        'emailAmount' => $emailAmountRm,
        'whatsappAmount' => $whatsappAmountRm,
        'emailUsedAmount' => $emailUsedAmountRm,
        'whatsappUsedAmount' => $whatsappUsedAmountRm,
        'emailRemainingAmount' => $emailRemainingAmountRm,
        'whatsappRemainingAmount' => $whatsappRemainingAmountRm,
        'emailCredit' => $emailCredit,
        'whatsappCredit' => $whatsappCredit,
        'emailUsedCredit' => $emailUsedCredit,
        'whatsappUsedCredit' => $whatsappUsedCredit,
        'emailRemainingCredit' => $emailRemainingCredit,
        'whatsappRemainingCredit' => $whatsappRemainingCredit,
        'emailPercent' => $emailPercentUsed,
        'whatsappPercent' => $whatsappPercentUsed,
        'emailMax' => $emailAmountRm,
        'whatsappMax' => $whatsappAmountRm,
        'name' => $data['locationName'],
        'types' => $data['types'],
        'transactionData' => $data['transactionData']
    ];
}
$subaccountDataJson = json_encode($subaccountData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Determine initial data to display
$initialData = $isDemo ? $subaccountData[''] : ($subaccountData[$targetLocationId] ?? null);
if (!$isDemo && !$initialData) {
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
        <h2>No valid data found for this subaccount.</h2>
    </div>
</body>
</html>
HTML;
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHL Credits Dashboard</title>
    <style>
    :root {
        --ghl-whatsapp: #25D366;
        --ghl-whatsapp-light: #4ADE80;
        --ghl-email: #3B82F6;
        --ghl-email-light: #60A5FA;
        --ghl-dark: #2d3748;
        --ghl-gray: #718096;
        --ghl-light-gray: #f7fafc;
        --ghl-border: #e2e8f0;
        --ghl-card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        --ghl-tab-active: #e2e8f0;
    }
    
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        background: #f1f5f9;
        min-height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
        padding: 20px;
    }
    
    .ghl-dashboard {
        width: 100%;
        max-width: 1200px;
        background: white;
        border-radius: 16px;
        box-shadow: var(--ghl-card-shadow);
        overflow: hidden;
    }
    
    .ghl-header {
        background: white;
        padding: 24px;
        border-bottom: 1px solid var(--ghl-border);
    }
    
    .ghl-header-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    
    .ghl-title {
        font-size: 26px;
        font-weight: 700;
        color: var(--ghl-dark);
    }
    
    .ghl-logo {
        width: 40px;
        height: 40px;
        background: var(--ghl-light-gray);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    
    .ghl-logo svg {
        width: 24px;
        height: 24px;
        color: var(--ghl-dark);
    }
    
    .ghl-content {
        padding: 24px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 24px;
    }
    
    .ghl-card {
        background: white;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        border: 1px solid var(--ghl-border);
    }
    
    .ghl-card.whatsapp {
        border-left: 4px solid var(--ghl-whatsapp);
    }
    
    .ghl-card.email {
        border-left: 4px solid var(--ghl-email);
    }
    
    .ghl-card-title {
        font-size: 20px;
        font-weight: 600;
        color: var(--ghl-dark);
        margin-bottom: 20px;
    }
    
    .ghl-tabs {
        display: flex;
        margin-bottom: 20px;
        border-bottom: 1px solid var(--ghl-border);
    }
    
    .ghl-tab {
        padding: 10px 20px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        color: var(--ghl-gray);
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .ghl-tab.active {
        color: var(--ghl-dark);
        background: var(--ghl-tab-active);
        border-bottom: 2px solid var(--ghl-whatsapp);
    }
    
    .ghl-tab.email.active {
        border-bottom: 2px solid var(--ghl-email);
    }
    
    .ghl-tab:hover {
        color: var(--ghl-dark);
    }
    
    .ghl-tab svg {
        width: 16px;
        height: 16px;
    }
    
    .ghl-metric {
        margin-bottom: 16px;
        background: var(--ghl-light-gray);
        padding: 12px;
        border-radius: 8px;
    }
    
    .ghl-metric-label {
        font-size: 13px;
        color: var(--ghl-gray);
        text-transform: uppercase;
        margin-bottom: 6px;
    }
    
    .ghl-metric-value {
        font-size: 22px;
        font-weight: 700;
        color: var(--ghl-dark);
    }
    
    .ghl-progress-section {
        margin-bottom: 20px;
        position: relative;
    }
    
    .ghl-progress-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
    }
    
    .ghl-progress-title {
        font-size: 14px;
        font-weight: 600;
        color: var(--ghl-dark);
    }
    
    .ghl-progress-percent {
        font-size: 14px;
        font-weight: 600;
        color: var(--ghl-whatsapp);
    }
    
    .ghl-progress-percent.email {
        color: var(--ghl-email);
    }
    
    .ghl-progress-bar {
        height: 10px;
        background: var(--ghl-light-gray);
        border-radius: 5px;
        overflow: hidden;
    }
    
    .ghl-progress-fill.whatsapp {
        background: linear-gradient(90deg, var(--ghl-whatsapp), var(--ghl-whatsapp-light));
    }
    
    .ghl-progress-fill.email {
        background: linear-gradient(90deg, var(--ghl-email), var(--ghl-email-light));
    }
    
    .ghl-progress-fill {
        height: 100%;
        border-radius: 5px;
        width: 0;
        transition: width 0.6s cubic-bezier(0.65, 0, 0.35, 1);
    }
    
    .ghl-progress-tooltip {
        visibility: hidden;
        position: absolute;
        top: -30px;
        left: 50%;
        transform: translateX(-50%);
        background: var(--ghl-dark);
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        white-space: nowrap;
    }
    
    .ghl-progress-section:hover .ghl-progress-tooltip {
        visibility: visible;
    }
    
    .ghl-progress-labels {
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        color: var(--ghl-gray);
        margin-top: 6px;
    }
    
    .ghl-subaccount-select, .ghl-date-input {
        width: 100%;
        padding: 12px 16px;
        border-radius: 8px;
        border: 1px solid var(--ghl-border);
        background: white;
        font-size: 16px;
        color: var(--ghl-dark);
        margin-bottom: 16px;
        cursor: pointer;
    }
    
    .ghl-date-range {
        display: flex;
        gap: 16px;
        align-items: center;
    }
    
    .ghl-date-range label {
        font-size: 14px;
        color: var(--ghl-dark);
    }
    
    .ghl-subaccount-select:focus, .ghl-date-input:focus {
        outline: none;
        border-color: var(--ghl-whatsapp);
        box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.2);
    }
    
    .ghl-subaccounts-toggle {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px;
        cursor: pointer;
        background: var(--ghl-light-gray);
        border-radius: 8px;
        margin-bottom: 16px;
    }
    
    .ghl-subaccounts-toggle svg {
        width: 18px;
        height: 18px;
        color: var(--ghl-dark);
        transition: transform 0.3s ease;
    }
    
    .ghl-subaccounts-content {
        max-height: 400px;
        overflow-y: auto;
        display: none;
    }
    
    .ghl-subaccounts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 16px;
    }
    
    .ghl-subaccount {
        padding: 12px;
        border: 1px solid var(--ghl-border);
        border-radius: 8px;
        background: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .ghl-subaccount-info {
        display: flex;
        align-items: center;
    }
    
    .ghl-subaccount-icon {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: var(--ghl-light-gray);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
    }
    
    .ghl-subaccount-icon svg {
        width: 16px;
        height: 16px;
        color: var(--ghl-dark);
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
        margin-top: 24px;
        display: none;
    }
    
    .ghl-types-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 16px;
    }
    
    .ghl-type {
        padding: 12px;
        border: 1px solid var(--ghl-border);
        border-radius: 8px;
        background: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
    }
    
    .ghl-type:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .ghl-type-info {
        display: flex;
        align-items: center;
    }
    
    .ghl-type-icon {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: var(--ghl-light-gray);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
    }
    
    .ghl-type-icon svg {
        width: 16px;
        height: 16px;
        color: var(--ghl-dark);
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
    
    .ghl-date-section {
        margin-top: 24px;
        display: none;
    }
    
    .ghl-date-types-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
        gap: 16px;
    }
    
    .ghl-date-type {
        padding: 12px;
        border: 1px solid var(--ghl-border);
        border-radius: 8px;
        background: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
    }
    
    .ghl-date-type:hover {
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
    
    .ghl-date-type-info {
        display: flex;
        align-items: center;
    }
    
    .ghl-date-type-icon {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: var(--ghl-light-gray);
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
    }
    
    .ghl-date-type-icon svg {
        width: 16px;
        height: 16px;
        color: var(--ghl-dark);
    }
    
    .ghl-date-type-name {
        font-weight: 500;
        color: var(--ghl-dark);
        margin-bottom: 2px;
    }
    
    .ghl-date-type-status {
        font-size: 12px;
        color: var(--ghl-gray);
    }
    
    .ghl-date-type-credits {
        font-weight: 600;
        color: var(--ghl-dark);
    }
    
    .ghl-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }
    
    .ghl-modal-content {
        background: white;
        border-radius: 12px;
        max-width: 600px;
        width: 90%;
        max-height: 50vh;
        overflow-y: auto;
        padding: 20px;
        box-shadow: var(--ghl-card-shadow);
        position: relative;
    }
    
    .ghl-modal-close {
        position: absolute;
        top: 12px;
        right: 12px;
        cursor: pointer;
        font-size: 20px;
        color: var(--ghl-dark);
    }
    
    .ghl-modal-title {
        font-size: 16px;
        font-weight: 600;
        color: var(--ghl-dark);
        margin-bottom: 12px;
    }
    
    .ghl-transactions-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 12px;
    }
    
    .ghl-transactions-table th,
    .ghl-transactions-table td {
        padding: 10px;
        text-align: left;
        border-bottom: 1px solid var(--ghl-border);
    }
    
    .ghl-transactions-table th {
        font-weight: 600;
        color: var(--ghl-dark);
        background: var(--ghl-light-gray);
    }
    
    .ghl-transactions-table td {
        font-size: 14px;
        color: var(--ghl-dark);
    }
    
    .ghl-error-message {
        display: none;
        text-align: center;
        padding: 0 16px;
        color: #e53e3e;
        font-weight: 500;
        background: white;
        border-radius: 8px;
    }
    
    .ghl-error-message.visible {
        display: block;
    }
    
    .ghl-progress-section.visible {
        display: block;
    }
    
    .ghl-types-section.visible {
        display: none;
    }
    
    .ghl-date-section.visible {
        display: block;
    }
    
    .ghl-modal.visible {
        display: flex;
    }
    
    .ghl-subaccounts-content.visible {
        display: block;
    }
    
    .ghl-metrics-credits, .ghl-metrics-amount {
        display: none;
    }
    
    .ghl-metrics-credits.active, .ghl-metrics-amount.active {
        display: block;
    }
    
    @media (max-width: 768px) {
        .ghl-content {
            grid-template-columns: 1fr;
        }
        .ghl-tab {
            padding: 8px 16px;
            font-size: 13px;
        }
        .ghl-metric-value {
            font-size: 20px;
        }
        .ghl-date-range {
            flex-direction: column;
            align-items: flex-start;
        }
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
            <?php if ($isDemo): ?>
            <select id="subaccountSelect" class="ghl-subaccount-select">
                <option value="">All Subaccounts</option>
                <?php
                foreach ($processedData as $locationId => $data) {
                    $locationName = htmlspecialchars($data['locationName']);
                    echo "<option value=\"{$locationId}\">{$locationName}</option>\n";
                }
                ?>
            </select>
            <?php endif; ?>
        </div>
        <div class="ghl-content">
            <!-- WhatsApp Card -->
            <div class="ghl-card whatsapp">
                <div class="ghl-card-title">WhatsApp Usage</div>
                <div class="ghl-progress-section whatsapp <?php echo !$isDemo ? 'visible' : ''; ?>">
                    <div class="ghl-progress-header">
                        <div class="ghl-progress-title">Usage Progress</div>
                        <div class="ghl-progress-percent whatsapp"><?php echo $initialData['whatsappPercent']; ?>% Used</div>
                    </div>
                    <div class="ghl-progress-bar">
                        <div class="ghl-progress-fill whatsapp" style="width: <?php echo $initialData['whatsappPercent']; ?>%;"></div>
                    </div>
                    <div class="ghl-progress-tooltip">Percentage of total credits used</div>
                    <div class="ghl-progress-labels">
                        <div>0</div>
                        <div><?php echo number_format(round($initialData['whatsappMax'] / 2), 2); ?></div>
                        <div><?php echo number_format($initialData['whatsappMax'], 2); ?></div>
                    </div>
                </div>
                <div class="ghl-tabs">
                    <div class="ghl-tab whatsapp active" data-tab="credits">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                        </svg>
                        Credits
                    </div>
                    <div class="ghl-tab whatsapp" data-tab="amount">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        Amount (RM)
                    </div>
                </div>
                <div class="ghl-metrics-credits active">
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Total Credits</div>
                        <div id="whatsappCreditTotal" class="ghl-metric-value"><?php echo number_format($initialData['whatsappCredit'], 0); ?></div>
                    </div>
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Used Credits</div>
                        <div id="whatsappCreditUsed" class="ghl-metric-value"><?php echo number_format($initialData['whatsappUsedCredit'], 0); ?></div>
                    </div>
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Remaining Credits</div>
                        <div id="whatsappCreditRemaining" class="ghl-metric-value"><?php echo number_format($initialData['whatsappRemainingCredit'], 0); ?></div>
                    </div>
                </div>
                <div class="ghl-metrics-amount">
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Total Amount (RM)</div>
                        <div id="whatsappAmountTotal" class="ghl-metric-value"><?php echo number_format($initialData['whatsappAmount'], 2); ?></div>
                    </div>
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Used Amount (RM)</div>
                        <div id="whatsappAmountUsed" class="ghl-metric-value"><?php echo number_format($initialData['whatsappUsedAmount'], 2); ?></div>
                    </div>
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Remaining Amount (RM)</div>
                        <div id="whatsappAmountRemaining" class="ghl-metric-value"><?php echo number_format($initialData['whatsappRemainingAmount'], 2); ?></div>
                    </div>
                </div>
            </div>
            <!-- Email Card -->
            <div class="ghl-card email">
                <div class="ghl-card-title">Email Usage</div>
                <div class="ghl-progress-section email <?php echo !$isDemo ? 'visible' : ''; ?>">
                    <div class="ghl-progress-header">
                        <div class="ghl-progress-title">Usage Progress</div>
                        <div class="ghl-progress-percent email"><?php echo $initialData['emailPercent']; ?>% Used</div>
                    </div>
                    <div class="ghl-progress-bar">
                        <div class="ghl-progress-fill email" style="width: <?php echo $initialData['emailPercent']; ?>%;"></div>
                    </div>
                    <div class="ghl-progress-tooltip">Percentage of total credits used</div>
                    <div class="ghl-progress-labels">
                        <div>0</div>
                        <div><?php echo number_format(round($initialData['emailMax'] / 2), 2); ?></div>
                        <div><?php echo number_format($initialData['emailMax'], 2); ?></div>
                    </div>
                </div>
                <div class="ghl-tabs">
                    <div class="ghl-tab email active" data-tab="credits">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                        </svg>
                        Credits
                    </div>
                    <div class="ghl-tab email" data-tab="amount">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        Amount (RM)
                    </div>
                </div>
                <div class="ghl-metrics-credits active">
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Total Credits</div>
                        <div id="emailCreditTotal" class="ghl-metric-value"><?php echo number_format($initialData['emailCredit'], 0); ?></div>
                    </div>
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Used Credits</div>
                        <div id="emailCreditUsed" class="ghl-metric-value"><?php echo number_format($initialData['emailUsedCredit'], 0); ?></div>
                    </div>
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Remaining Credits</div>
                        <div id="emailCreditRemaining" class="ghl-metric-value"><?php echo number_format($initialData['emailRemainingCredit'], 0); ?></div>
                    </div>
                </div>
                <div class="ghl-metrics-amount">
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Total Amount (RM)</div>
                        <div id="emailAmountTotal" class="ghl-metric-value"><?php echo number_format($initialData['emailAmount'], 2); ?></div>
                    </div>
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Used Amount (RM)</div>
                        <div id="emailAmountUsed" class="ghl-metric-value"><?php echo number_format($initialData['emailUsedAmount'], 2); ?></div>
                    </div>
                    <div class="ghl-metric">
                        <div class="ghl-metric-label">Remaining Amount (RM)</div>
                        <div id="emailAmountRemaining" class="ghl-metric-value"><?php echo number_format($initialData['emailRemainingAmount'], 2); ?></div>
                    </div>
                </div>
            </div>
            <!-- Types Section (Hidden) -->
            <div id="typesSection" class="ghl-types-section" style="grid-column: span 2;">
                <div class="ghl-card-title">Usage by Type</div>
                <div id="typesGrid" class="ghl-types-grid">
                    <?php if (!$isDemo): ?>
                    <?php
                    $typeIcons = [
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>',
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>',
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
                    ];
                    $iconIndex = 0;
                    foreach ($initialData['types'] as $type => $typeData) {
                        $totalAmount = number_format($typeData['totalAmount'], 2);
                        $count = $typeData['count'];
                        $plural = $count !== 1 ? 's' : '';
                        $icon = $typeIcons[$iconIndex % count($typeIcons)];
                        $iconIndex++;
                        echo <<<HTML
                        <div class="ghl-type">
                            <div class="ghl-type-info">
                                <div class="ghl-type-icon">
                                    {$icon}
                                </div>
                                <div>
                                    <div class="ghl-type-name">{$type}</div>
                                    <div class="ghl-type-status">{$count} transaction{$plural}</div>
                                </div>
                            </div>
                            <div class="ghl-type-credits">{$totalAmount}</div>
                        </div>
                        HTML;
                    }
                    ?>
                    <?php endif; ?>
                </div>
            </div>
            <div id="errorMessage" class="ghl-error-message" style="grid-column: span 2;"></div>
            <!-- Date Range WhatsApp & Email Section -->
            <div id="dateSection" class="ghl-date-section <?php echo !$isDemo && !empty($initialData['transactionData']) ? 'visible' : ''; ?>" style="grid-column: span 2;">
                <div class="ghl-card-title">WhatsApp & Email Transactions by Date Range</div>
                <div class="ghl-date-range">
                    <label for="startDate">Start Date:</label>
                    <input type="date" id="startDate" class="ghl-date-input">
                    <label for="endDate">End Date:</label>
                    <input type="date" id="endDate" class="ghl-date-input">
                </div>
                <div id="dateTypesGrid" class="ghl-date-types-grid"></div>
            </div>
            <!-- Subaccounts Section -->
            <?php if ($isDemo): ?>
            <div id="subaccountsToggle" class="ghl-subaccounts-toggle" style="grid-column: span 2;">
                <div class="ghl-card-title">Sub Accounts (<?php echo count($processedData); ?>)</div>
                <svg id="toggleIcon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </div>
            <div id="subaccountsContent" class="ghl-subaccounts-content" style="grid-column: span 2;">
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
                        $usedAmountRm = $data['whatsappAmount'] + $data['emailAmount'];
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
            <?php endif; ?>
        </div>
        <div id="transactionsModal" class="ghl-modal">
            <div class="ghl-modal-content">
                <span id="modalClose" class="ghl-modal-close">&times;</span>
                <div id="modalTitle" class="ghl-modal-title"></div>
                <table id="transactionsTable" class="ghl-transactions-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Amount (RM)</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsTableBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const isDemo = <?php echo json_encode($isDemo); ?>;
            const targetLocationId = '<?php echo $targetLocationId; ?>';
            const whatsappCreditTotal = document.getElementById('whatsappCreditTotal');
            const whatsappCreditUsed = document.getElementById('whatsappCreditUsed');
            const whatsappCreditRemaining = document.getElementById('whatsappCreditRemaining');
            const whatsappAmountTotal = document.getElementById('whatsappAmountTotal');
            const whatsappAmountUsed = document.getElementById('whatsappAmountUsed');
            const whatsappAmountRemaining = document.getElementById('whatsappAmountRemaining');
            const emailCreditTotal = document.getElementById('emailCreditTotal');
            const emailCreditUsed = document.getElementById('emailCreditUsed');
            const emailCreditRemaining = document.getElementById('emailCreditRemaining');
            const emailAmountTotal = document.getElementById('emailAmountTotal');
            const emailAmountUsed = document.getElementById('emailAmountUsed');
            const emailAmountRemaining = document.getElementById('emailAmountRemaining');
            const whatsappProgressSection = document.querySelector('.ghl-progress-section.whatsapp');
            const emailProgressSection = document.querySelector('.ghl-progress-section.email');
            const whatsappProgressFill = document.querySelector('.ghl-progress-fill.whatsapp');
            const emailProgressFill = document.querySelector('.ghl-progress-fill.email');
            const whatsappProgressPercent = document.querySelector('.ghl-progress-percent.whatsapp');
            const emailProgressPercent = document.querySelector('.ghl-progress-percent.email');
            const whatsappProgressLabels = document.querySelectorAll('.ghl-progress-section.whatsapp .ghl-progress-labels div');
            const emailProgressLabels = document.querySelectorAll('.ghl-progress-section.email .ghl-progress-labels div');
            const typesSection = document.getElementById('typesSection');
            const typesGrid = document.getElementById('typesGrid');
            const dateSection = document.getElementById('dateSection');
            const startDateInput = document.getElementById('startDate');
            const endDateInput = document.getElementById('endDate');
            const dateTypesGrid = document.getElementById('dateTypesGrid');
            const transactionsModal = document.getElementById('transactionsModal');
            const modalClose = document.getElementById('modalClose');
            const modalTitle = document.getElementById('modalTitle');
            const transactionsTableBody = document.getElementById('transactionsTableBody');
            const subaccountsToggle = document.getElementById('subaccountsToggle');
            const subaccountsContent = document.getElementById('subaccountsContent');
            const subaccountSelect = document.getElementById('subaccountSelect');
            const errorMessage = document.getElementById('errorMessage');
            const subaccountData = <?php echo $subaccountDataJson; ?>;
            const typeIcons = {
                'Emails': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>',
                'WhatsApp': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>'
            };

            function updateDashboard(selected) {
                console.log('Updating dashboard for selection:', selected);
                const data = subaccountData[selected];
                errorMessage.classList.toggle('visible', !data);
                const whatsappVisible = !!data && data.whatsappMax > 0;
                const emailVisible = !!data && data.emailMax > 0;
                whatsappProgressSection.classList.toggle('visible', whatsappVisible);
                emailProgressSection.classList.toggle('visible', emailVisible);
                typesSection.classList.toggle('visible', !!data && (isDemo ? selected !== '' : false));
                dateSection.classList.toggle('visible', !!data && !isDemo && Object.keys(data.transactionData || {}).length > 0);
                if (isDemo) {
                    subaccountsContent.classList.toggle('visible', selected === '');
                    subaccountsToggle.classList.toggle('visible', selected === '');
                }
                if (data) {
                    errorMessage.textContent = '';
                    whatsappCreditTotal.textContent = data.whatsappCredit.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    whatsappCreditUsed.textContent = data.whatsappUsedCredit.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    whatsappCreditRemaining.textContent = data.whatsappRemainingCredit.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    whatsappAmountTotal.textContent = data.whatsappAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    whatsappAmountUsed.textContent = data.whatsappUsedAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    whatsappAmountRemaining.textContent = data.whatsappRemainingAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    emailCreditTotal.textContent = data.emailCredit.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    emailCreditUsed.textContent = data.emailUsedCredit.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    emailCreditRemaining.textContent = data.emailRemainingCredit.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                    emailAmountTotal.textContent = data.emailAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    emailAmountUsed.textContent = data.emailUsedAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    emailAmountRemaining.textContent = data.emailRemainingAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    whatsappProgressFill.style.width = `${data.whatsappPercent}%`;
                    emailProgressFill.style.width = `${data.emailPercent}%`;
                    whatsappProgressPercent.textContent = `${data.whatsappPercent}% Used`;
                    emailProgressPercent.textContent = `${data.emailPercent}% Used`;
                    whatsappProgressLabels[0].textContent = '0';
                    whatsappProgressLabels[1].textContent = (Math.round(data.whatsappMax / 2)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    whatsappProgressLabels[2].textContent = data.whatsappMax.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    emailProgressLabels[0].textContent = '0';
                    emailProgressLabels[1].textContent = (Math.round(data.emailMax / 2)).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    emailProgressLabels[2].textContent = data.emailMax.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    setTimeout(() => {
                        whatsappProgressFill.style.width = `${data.whatsappPercent}%`;
                        emailProgressFill.style.width = `${data.emailPercent}%`;
                    }, 300);
                    typesGrid.innerHTML = '';
                    if (isDemo ? selected !== '' : false) {
                        let iconIndex = 0;
                        const typeIconsArray = Object.values(typeIcons);
                        for (const [type, typeData] of Object.entries(data.types || {})) {
                            const totalAmount = typeData.totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                            const count = typeData.count;
                            const plural = count !== 1 ? 's' : '';
                            const icon = typeIconsArray[iconIndex % typeIconsArray.length];
                            iconIndex++;
                            const typeElement = `
                                <div class="ghl-type">
                                    <div class="ghl-type-info">
                                        <div class="ghl-type-icon">
                                            ${icon}
                                        </div>
                                        <div>
                                            <div class="ghl-type-name">${type}</div>
                                            <div class="ghl-type-status">${count} transaction${plural}</div>
                                        </div>
                                    </div>
                                    <div class="ghl-type-credits">${totalAmount}</div>
                                </div>
                            `;
                            typesGrid.insertAdjacentHTML('beforeend', typeElement);
                        }
                    }
                    dateTypesGrid.innerHTML = '';
                    startDateInput.value = '';
                    endDateInput.value = '';
                } else {
                    errorMessage.textContent = 'No data available for the selected subaccount.';
                }
            }

            function showModal(type, startDate, endDate, transactions) {
                const startFormatted = startDate ? new Date(startDate).toLocaleString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : 'Start';
                const endFormatted = endDate ? new Date(endDate).toLocaleString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) : 'End';
                modalTitle.textContent = `${type} Transactions - ${startFormatted} to ${endFormatted}`;
                transactionsTableBody.innerHTML = '';
                transactions.forEach(transaction => {
                    const dateStr = transaction.date.replace(/(st|nd|rd|th)/, '');
                    const formattedDate = new Date(dateStr).toLocaleString('en-US', {
                        month: 'short',
                        day: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                    });
                    const amount = transaction.amount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    const originalType = transaction.originalType || type;
                    const row = `
                        <tr>
                            <td>${formattedDate}</td>
                            <td>${amount}</td>
                            <td>${originalType}</td>
                        </tr>
                    `;
                    transactionsTableBody.insertAdjacentHTML('beforeend', row);
                });
                transactionsModal.classList.add('visible');
            }

            // Tab switching
            document.querySelectorAll('.ghl-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    const card = tab.closest('.ghl-card');
                    const tabType = tab.dataset.tab;
                    card.querySelectorAll('.ghl-tab').forEach(t => t.classList.remove('active'));
                    card.querySelectorAll('.ghl-metrics-credits, .ghl-metrics-amount').forEach(m => m.classList.remove('active'));
                    tab.classList.add('active');
                    card.querySelector(`.ghl-metrics-${tabType}`).classList.add('active');
                });
            });

            if (!isDemo && startDateInput && endDateInput) {
                function updateDateRange() {
                    const startDate = startDateInput.value;
                    const endDate = endDateInput.value;
                    const selected = subaccountSelect ? subaccountSelect.value : targetLocationId;
                    dateTypesGrid.innerHTML = '';
                    if (startDate && endDate && new Date(startDate) <= new Date(endDate)) {
                        const types = ['WhatsApp', 'Emails'];
                        const filteredData = {};
                        types.forEach(type => {
                            const transactions = (subaccountData[selected].transactionData[type] || []).filter(transaction => {
                                const dateStr = transaction.date.replace(/(st|nd|rd|th)/, '');
                                const transactionDate = new Date(Date.parse(dateStr));
                                return transactionDate >= new Date(startDate) && transactionDate <= new Date(endDate + 'T23:59:59');
                            });
                            if (transactions.length > 0) {
                                filteredData[type] = {
                                    totalAmount: transactions.reduce((sum, t) => sum + t.amount, 0),
                                    count: transactions.length,
                                    transactions
                                };
                            }
                        });
                        types.forEach(type => {
                            if (filteredData[type]) {
                                const totalAmount = filteredData[type].totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                const count = filteredData[type].count;
                                const plural = count !== 1 ? 's' : '';
                                const icon = typeIcons[type] || typeIcons['WhatsApp'];
                                const typeElement = document.createElement('div');
                                typeElement.className = 'ghl-date-type';
                                typeElement.innerHTML = `
                                    <div class="ghl-date-type-info">
                                        <div class="ghl-date-type-icon">
                                            ${icon}
                                        </div>
                                        <div>
                                            <div class="ghl-date-type-name">${type}</div>
                                            <div class="ghl-date-type-status">${count} transaction${plural}</div>
                                        </div>
                                    </div>
                                    <div class="ghl-date-type-credits">${totalAmount}</div>
                                `;
                                typeElement.addEventListener('click', () => {
                                    showModal(type, startDate, endDate, filteredData[type].transactions);
                                });
                                dateTypesGrid.appendChild(typeElement);
                            }
                        });
                    } else if (startDate || endDate) {
                        errorMessage.textContent = 'Please select both start and end dates, and ensure start date is not after end date.';
                        errorMessage.classList.add('visible');
                    }
                }
                startDateInput.addEventListener('change', updateDateRange);
                endDateInput.addEventListener('change', updateDateRange);
                modalClose.addEventListener('click', () => {
                    transactionsModal.classList.remove('visible');
                });
                transactionsModal.addEventListener('click', (e) => {
                    if (e.target === transactionsModal) {
                        transactionsModal.classList.remove('visible');
                    }
                });
            }

            if (isDemo && subaccountSelect) {
                subaccountSelect.addEventListener('change', function(e) {
                    const selected = this.value;
                    updateDashboard(selected);
                });
            }

            if (subaccountsToggle) {
                subaccountsToggle.addEventListener('click', () => {
                    subaccountsContent.classList.toggle('visible');
                    const toggleIcon = document.getElementById('toggleIcon');
                    toggleIcon.classList.toggle('ghl-rotate');
                });
            }
        });
    </script>
</body>
</html>