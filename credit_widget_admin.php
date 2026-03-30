<!-- see all acc -->

<?php
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
function getCreditLimits($filePath) {
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

    $locationIdIndex    = false;
    $whatsappCreditIndex = false;
    $emailCreditIndex   = false;

    foreach ($header as $index => $column) {
        $column = strtolower(trim($column));
        if ($column === 'locationid') $locationIdIndex = $index;
        if ($column === 'totalamount' || $column === 'whatsappcredits') $whatsappCreditIndex = $index;
        if ($column === 'emailcredits') $emailCreditIndex = $index;
    }

    if ($locationIdIndex === false) {
        error_log("Required column (locationId) not found in: $filePath");
        fclose($file);
        return $creditLimits;
    }

    while (($row = fgetcsv($file)) !== false) {
        if (empty($row[$locationIdIndex])) continue;
        $locationId = trim($row[$locationIdIndex]);

        $whatsappCredit = $whatsappCreditIndex !== false && isset($row[$whatsappCreditIndex]) ? floatval($row[$whatsappCreditIndex]) : 0;
        $emailCredit    = $emailCreditIndex !== false && isset($row[$emailCreditIndex]) ? floatval($row[$emailCreditIndex]) : 0;

        $creditLimits[$locationId] = [
            'whatsappCredit' => $whatsappCredit,
            'emailCredit'    => $emailCredit
        ];
    }
    fclose($file);
    return $creditLimits;
}

// Function to process CSV files and gather location, type, and monthly data
function processCsvFiles($csvFiles) {
    $results = [];
    foreach ($csvFiles as $filePath) {
        if (basename($filePath) === 'total_credits.csv') continue;
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

        $locationIdIndex   = false;
        $locationNameIndex = false;
        $typeIndex         = false;
        $amountIndex       = false;
        $dateIndex         = false;
        $descriptionIndex  = false;

        foreach ($header as $index => $column) {
            $column = strtolower(trim($column));
            if ($column === 'locationid' || $column === 'location id') $locationIdIndex = $index;
            if ($column === 'locationname' || $column === 'location name') $locationNameIndex = $index;
            if ($column === 'type' || $column === 'transaction type') $typeIndex = $index;
            if ($column === 'amount') $amountIndex = $index;
            if ($column === 'date' || $column === 'activity date') $dateIndex = $index;
            if ($column === 'description') $descriptionIndex = $index;
        }

        if ($locationIdIndex === false || $amountIndex === false || $typeIndex === false) {
            error_log("Required columns (locationId, type, or amount) not found in: $filePath");
            fclose($file);
            continue;
        }

        while (($row = fgetcsv($file)) !== false) {
            if (empty($row[$locationIdIndex]) || !isset($row[$amountIndex]) || empty($row[$typeIndex])) continue;
            $locationId  = trim($row[$locationIdIndex]);
            $type        = trim($row[$typeIndex]);
            $description = $descriptionIndex !== false && isset($row[$descriptionIndex]) ? strtolower(trim($row[$descriptionIndex])) : '';

            if (stripos($type, 'WhatsApp') !== false || stripos($description, 'whatsapp') !== false) {
                $category    = 'whatsapp';
                $creditAmount = 0.50;
            } elseif (stripos($type, 'Emails') !== false || stripos($description, 'emails') !== false) {
                $category    = 'email';
                $creditAmount = 0.005;
            } else {
                continue;
            }

            $locationName = $locationNameIndex !== false && !empty($row[$locationNameIndex]) ? trim($row[$locationNameIndex]) : $locationId;

            if (!isset($results[$locationId])) {
                $results[$locationId] = [
                    'locationName'   => $locationName,
                    'emailAmount'    => 0,
                    'whatsappAmount' => 0,
                    'emailCount'     => 0,
                    'whatsappCount'  => 0,
                    'types'          => [],
                    'monthlyData'    => []
                ];
            }

            if (!isset($results[$locationId]['types'][$type])) {
                $results[$locationId]['types'][$type] = ['totalAmount' => 0, 'count' => 0, 'category' => $category];
            }

            if ($category === 'email') {
                $results[$locationId]['emailAmount'] += $creditAmount;
                $results[$locationId]['emailCount']++;
            } elseif ($category === 'whatsapp') {
                $results[$locationId]['whatsappAmount'] += $creditAmount;
                $results[$locationId]['whatsappCount']++;
            }

            $results[$locationId]['types'][$type]['totalAmount'] += $creditAmount;
            $results[$locationId]['types'][$type]['count']++;

            if ($dateIndex !== false && !empty($row[$dateIndex])) {
                $dateStr = preg_replace('/(st|nd|rd|th)/', '', $row[$dateIndex]);
                $date    = DateTime::createFromFormat('M j Y, h:i:s A', trim($dateStr));
                if ($date !== false) {
                    $monthKey = $date->format('Y-m');
                    if (!isset($results[$locationId]['monthlyData'][$monthKey])) {
                        $results[$locationId]['monthlyData'][$monthKey] = ['types' => []];
                    }
                    if (!isset($results[$locationId]['monthlyData'][$monthKey]['types'][$category])) {
                        $results[$locationId]['monthlyData'][$monthKey]['types'][$category] = [
                            'totalAmount' => 0, 'count' => 0, 'transactions' => []
                        ];
                    }
                    $results[$locationId]['monthlyData'][$monthKey]['types'][$category]['totalAmount'] += $creditAmount;
                    $results[$locationId]['monthlyData'][$monthKey]['types'][$category]['count']++;
                    $results[$locationId]['monthlyData'][$monthKey]['types'][$category]['transactions'][] = [
                        'date'         => $row[$dateIndex],
                        'amount'       => $creditAmount,
                        'originalType' => $type,
                        'description'  => $descriptionIndex !== false && isset($row[$descriptionIndex]) ? $row[$descriptionIndex] : ''
                    ];
                } else {
                    error_log("Invalid date format in $filePath: " . $row[$dateIndex]);
                }
            }
        }
        fclose($file);
    }
    return $results;
}

// Process the CSV files
$csvDirectory    = __DIR__ . '/csv_files';
$creditLimitsFile = __DIR__ . '/total_credits.csv';
$csvFiles        = getCsvFiles($csvDirectory);
$creditLimits    = getCreditLimits($creditLimitsFile);
$processedData   = processCsvFiles($csvFiles);

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
        <h2>No valid data found.</h2>
    </div>
</body>
</html>
HTML;
    exit;
}

// Build subaccount data
$subaccountData = [];

// Aggregate "All Subaccounts" totals
$totalEmailUsedRm    = 0;
$totalWhatsappUsedRm = 0;
$totalWhatsappCredit = 0;
$totalEmailCredit    = 0;
$allTypes            = [];

foreach ($processedData as $locationId => $data) {
    $totalEmailUsedRm    += $data['emailAmount'];
    $totalWhatsappUsedRm += $data['whatsappAmount'];
    $totalWhatsappCredit += isset($creditLimits[$locationId]['whatsappCredit']) ? $creditLimits[$locationId]['whatsappCredit'] : 0;
    $totalEmailCredit    += isset($creditLimits[$locationId]['emailCredit']) ? $creditLimits[$locationId]['emailCredit'] : 0;

    foreach ($data['types'] as $type => $typeData) {
        if (!isset($allTypes[$type])) {
            $allTypes[$type] = ['totalAmount' => 0, 'count' => 0, 'category' => $typeData['category']];
        }
        $allTypes[$type]['totalAmount'] += $typeData['totalAmount'];
        $allTypes[$type]['count']       += $typeData['count'];
    }
}

$whatsappAmountAll        = $totalWhatsappCredit / 2;
$emailAmountAll           = $totalEmailCredit * 0.005;
$whatsappUsedCreditAll    = $totalWhatsappUsedRm / 0.50;
$emailUsedCreditAll       = $totalEmailUsedRm / 0.005;
$whatsappRemainingAll     = max(0, $whatsappAmountAll - $totalWhatsappUsedRm);
$emailRemainingAll        = max(0, $emailAmountAll - $totalEmailUsedRm);
$whatsappRemainingCredit  = $totalWhatsappCredit - $whatsappUsedCreditAll;
$emailRemainingCredit     = max(0, $totalEmailCredit - $emailUsedCreditAll);

$subaccountData[''] = [
    'emailAmount'             => $emailAmountAll,
    'whatsappAmount'          => $whatsappAmountAll,
    'emailUsedAmount'         => $totalEmailUsedRm,
    'whatsappUsedAmount'      => $totalWhatsappUsedRm,
    'emailRemainingAmount'    => $emailRemainingAll,
    'whatsappRemainingAmount' => $whatsappRemainingAll,
    'emailCredit'             => $totalEmailCredit,
    'whatsappCredit'          => $totalWhatsappCredit,
    'emailUsedCredit'         => $emailUsedCreditAll,
    'whatsappUsedCredit'      => $whatsappUsedCreditAll,
    'emailRemainingCredit'    => $emailRemainingCredit,
    'whatsappRemainingCredit' => $whatsappRemainingCredit,
    'emailPercent'            => $emailAmountAll > 0 ? min(100, round($totalEmailUsedRm / $emailAmountAll * 100)) : 0,
    'whatsappPercent'         => $whatsappAmountAll > 0 ? min(100, round($totalWhatsappUsedRm / $whatsappAmountAll * 100)) : 0,
    'emailMax'                => $emailAmountAll,
    'whatsappMax'             => $whatsappAmountAll,
    'types'                   => $allTypes
];

// Individual subaccounts
foreach ($processedData as $locationId => $data) {
    $emailUsedAmountRm    = $data['emailAmount'];
    $whatsappUsedAmountRm = $data['whatsappAmount'];
    $whatsappCredit       = isset($creditLimits[$locationId]['whatsappCredit']) ? $creditLimits[$locationId]['whatsappCredit'] : 0;
    $emailCredit          = isset($creditLimits[$locationId]['emailCredit']) ? $creditLimits[$locationId]['emailCredit'] : 0;
    $whatsappAmountRm     = $whatsappCredit / 2;
    $emailAmountRm        = $emailCredit * 0.005;
    $emailUsedCredit      = $emailUsedAmountRm / 0.005;
    $whatsappUsedCredit   = $whatsappUsedAmountRm / 0.50;

    $subaccountData[$locationId] = [
        'emailAmount'             => $emailAmountRm,
        'whatsappAmount'          => $whatsappAmountRm,
        'emailUsedAmount'         => $emailUsedAmountRm,
        'whatsappUsedAmount'      => $whatsappUsedAmountRm,
        'emailRemainingAmount'    => max(0, $emailAmountRm - $emailUsedAmountRm),
        'whatsappRemainingAmount' => max(0, $whatsappAmountRm - $whatsappUsedAmountRm),
        'emailCredit'             => $emailCredit,
        'whatsappCredit'          => $whatsappCredit,
        'emailUsedCredit'         => $emailUsedCredit,
        'whatsappUsedCredit'      => $whatsappUsedCredit,
        'emailRemainingCredit'    => max(0, $emailCredit - $emailUsedCredit),
        'whatsappRemainingCredit' => $whatsappCredit - $whatsappUsedCredit,
        'emailPercent'            => $emailAmountRm > 0 ? min(100, round($emailUsedAmountRm / $emailAmountRm * 100)) : 0,
        'whatsappPercent'         => $whatsappAmountRm > 0 ? min(100, round($whatsappUsedAmountRm / $whatsappAmountRm * 100)) : 0,
        'emailMax'                => $emailAmountRm,
        'whatsappMax'             => $whatsappAmountRm,
        'name'                    => $data['locationName'],
        'types'                   => $data['types'],
        'monthlyData'             => $data['monthlyData']
    ];
}

$initialData       = $subaccountData[''];
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

    * { margin: 0; padding: 0; box-sizing: border-box; }

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

    .ghl-title { font-size: 26px; font-weight: 700; color: var(--ghl-dark); }

    .ghl-logo {
        width: 40px; height: 40px;
        background: var(--ghl-light-gray);
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
    }

    .ghl-logo svg { width: 24px; height: 24px; color: var(--ghl-dark); }

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

    .ghl-card.whatsapp { border-left: 4px solid var(--ghl-whatsapp); }
    .ghl-card.email    { border-left: 4px solid var(--ghl-email); }

    .ghl-card-title { font-size: 20px; font-weight: 600; color: var(--ghl-dark); margin-bottom: 20px; }

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

    .ghl-tab.active          { color: var(--ghl-dark); background: var(--ghl-tab-active); }
    .ghl-tab.whatsapp.active { border-bottom: 2px solid var(--ghl-whatsapp); }
    .ghl-tab.email.active    { border-bottom: 2px solid var(--ghl-email); }
    .ghl-tab:hover           { color: var(--ghl-dark); }
    .ghl-tab svg             { width: 16px; height: 16px; }

    .ghl-metric {
        margin-bottom: 16px;
        background: var(--ghl-light-gray);
        padding: 12px;
        border-radius: 8px;
    }

    .ghl-metric-label { font-size: 13px; color: var(--ghl-gray); text-transform: uppercase; margin-bottom: 6px; }
    .ghl-metric-value { font-size: 22px; font-weight: 700; color: var(--ghl-dark); }

    .ghl-progress-section  { margin-bottom: 20px; position: relative; }
    .ghl-progress-header   { display: flex; justify-content: space-between; margin-bottom: 8px; }
    .ghl-progress-title    { font-size: 14px; font-weight: 600; color: var(--ghl-dark); }
    .ghl-progress-percent  { font-size: 14px; font-weight: 600; }
    .ghl-progress-percent.whatsapp { color: var(--ghl-whatsapp); }
    .ghl-progress-percent.email    { color: var(--ghl-email); }

    .ghl-progress-bar { height: 10px; background: var(--ghl-light-gray); border-radius: 5px; overflow: hidden; }

    .ghl-progress-fill {
        height: 100%; border-radius: 5px; width: 0;
        transition: width 0.6s cubic-bezier(0.65, 0, 0.35, 1);
    }

    .ghl-progress-fill.whatsapp { background: linear-gradient(90deg, var(--ghl-whatsapp), var(--ghl-whatsapp-light)); }
    .ghl-progress-fill.email    { background: linear-gradient(90deg, var(--ghl-email), var(--ghl-email-light)); }

    .ghl-progress-tooltip {
        visibility: hidden;
        position: absolute;
        top: -30px; left: 50%;
        transform: translateX(-50%);
        background: var(--ghl-dark);
        color: white;
        padding: 6px 12px;
        border-radius: 6px;
        font-size: 12px;
        white-space: nowrap;
    }

    .ghl-progress-section:hover .ghl-progress-tooltip { visibility: visible; }

    .ghl-progress-labels {
        display: flex;
        justify-content: space-between;
        font-size: 12px;
        color: var(--ghl-gray);
        margin-top: 6px;
    }

    .ghl-subaccount-select {
        width: 100%;
        padding: 12px 16px;
        border-radius: 8px;
        border: 1px solid var(--ghl-border);
        background: white;
        font-size: 16px;
        color: var(--ghl-dark);
        margin-bottom: 16px;
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%232d3748'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 16px center;
        background-size: 20px;
    }

    .ghl-subaccount-select:focus {
        outline: none;
        border-color: var(--ghl-whatsapp);
        box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.2);
    }

    .ghl-subaccount-select option { color: var(--ghl-dark); background: white; }

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

    .ghl-subaccounts-toggle svg { width: 18px; height: 18px; color: var(--ghl-dark); transition: transform 0.3s ease; }

    .ghl-subaccounts-content         { max-height: 400px; overflow-y: auto; display: none; }
    .ghl-subaccounts-content.visible  { display: block; }

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

    .ghl-subaccount-info  { display: flex; align-items: center; }

    .ghl-subaccount-icon {
        width: 32px; height: 32px;
        border-radius: 6px;
        background: var(--ghl-light-gray);
        display: flex; align-items: center; justify-content: center;
        margin-right: 12px;
    }

    .ghl-subaccount-icon svg { width: 16px; height: 16px; color: var(--ghl-dark); }
    .ghl-subaccount-name     { font-weight: 500; color: var(--ghl-dark); margin-bottom: 2px; }
    .ghl-subaccount-status   { font-size: 12px; color: var(--ghl-gray); }
    .ghl-subaccount-credits  { font-weight: 600; color: var(--ghl-dark); }

    .ghl-rotate { transform: rotate(180deg); }

    .ghl-types-section         { margin-top: 24px; display: none; }
    .ghl-types-section.visible { display: block; }

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

    .ghl-type:hover { box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); }

    .ghl-type-info { display: flex; align-items: center; }

    .ghl-type-icon {
        width: 32px; height: 32px;
        border-radius: 6px;
        background: var(--ghl-light-gray);
        display: flex; align-items: center; justify-content: center;
        margin-right: 12px;
    }

    .ghl-type-icon svg { width: 16px; height: 16px; color: var(--ghl-dark); }
    .ghl-type-name     { font-weight: 500; color: var(--ghl-dark); margin-bottom: 2px; }
    .ghl-type-status   { font-size: 12px; color: var(--ghl-gray); }
    .ghl-type-credits  { font-weight: 600; color: var(--ghl-dark); }

    .ghl-error-message {
        display: none;
        text-align: center;
        padding: 16px;
        color: #e53e3e;
        font-weight: 500;
        background: white;
        border-radius: 8px;
        margin-bottom: 16px;
    }

    .ghl-error-message.visible { display: block; }

    .ghl-progress-section.visible { display: block; }

    .ghl-metrics-credits, .ghl-metrics-amount         { display: none; }
    .ghl-metrics-credits.active, .ghl-metrics-amount.active { display: block; }

    .ghl-modal {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }

    .ghl-modal.visible { display: flex; }

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

    .ghl-modal-close { position: absolute; top: 12px; right: 12px; cursor: pointer; font-size: 20px; color: var(--ghl-dark); }
    .ghl-modal-title { font-size: 16px; font-weight: 600; color: var(--ghl-dark); margin-bottom: 12px; }

    .ghl-transactions-table { width: 100%; border-collapse: collapse; margin-top: 12px; }

    .ghl-transactions-table th,
    .ghl-transactions-table td { padding: 10px; text-align: left; border-bottom: 1px solid var(--ghl-border); }

    .ghl-transactions-table th { font-weight: 600; color: var(--ghl-dark); background: var(--ghl-light-gray); }
    .ghl-transactions-table td { font-size: 14px; color: var(--ghl-dark); }

    @media (max-width: 768px) {
        .ghl-content  { grid-template-columns: 1fr; }
        .ghl-tab      { padding: 8px 16px; font-size: 13px; }
        .ghl-metric-value { font-size: 20px; }
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
                <?php foreach ($processedData as $locationId => $data): ?>
                    <option value="<?php echo $locationId; ?>"><?php echo htmlspecialchars($data['locationName']); ?></option>
                <?php endforeach; ?>
            </select>
            <div id="errorMessage" class="ghl-error-message"></div>
        </div>
        <div class="ghl-content">
            <!-- WhatsApp Card -->
            <div class="ghl-card whatsapp">
                <div class="ghl-card-title">WhatsApp Usage</div>
                <div class="ghl-progress-section whatsapp visible">
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
                <div class="ghl-progress-section email visible">
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
            <!-- Types Section -->
            <div id="typesSection" class="ghl-types-section" style="grid-column: span 2;">
                <div class="ghl-card-title">Usage by Type</div>
                <div id="typesGrid" class="ghl-types-grid"></div>
            </div>
            <!-- Subaccounts Section -->
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
                    foreach ($processedData as $locationId => $data):
                        $locationName        = htmlspecialchars($data['locationName']);
                        $usedAmountRm        = $data['whatsappAmount'] + $data['emailAmount'];
                        $usedAmountFormatted = number_format($usedAmountRm, 2);
                        $status              = $usedAmountRm > 0 ? 'Active' : 'Inactive';
                        $icon                = $icons[$iconIndex % count($icons)];
                        $iconIndex++;
                    ?>
                    <div class="ghl-subaccount">
                        <div class="ghl-subaccount-info">
                            <div class="ghl-subaccount-icon"><?php echo $icon; ?></div>
                            <div>
                                <div class="ghl-subaccount-name"><?php echo $locationName; ?></div>
                                <div class="ghl-subaccount-status"><?php echo $status; ?> &bull; <?php echo $usedAmountFormatted; ?> RM used</div>
                            </div>
                        </div>
                        <div class="ghl-subaccount-credits"><?php echo $usedAmountFormatted; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <!-- Transactions Modal -->
        <div id="transactionsModal" class="ghl-modal">
            <div class="ghl-modal-content">
                <span id="modalClose" class="ghl-modal-close">&times;</span>
                <div id="modalTitle" class="ghl-modal-title"></div>
                <table class="ghl-transactions-table">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
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
            const subaccountData = <?php echo $subaccountDataJson; ?>;
            const typeIcons = {
                whatsapp: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>',
                email:    '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>'
            };

            function fmt2(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
            function fmt0(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); }

            function updateDashboard(selected) {
                const data = subaccountData[selected];
                document.getElementById('errorMessage').classList.toggle('visible', !data);
                if (!data) {
                    document.getElementById('errorMessage').textContent = 'No data available for the selected subaccount.';
                    return;
                }

                const isAll = selected === '';

                // Progress bars visibility
                document.querySelector('.ghl-progress-section.whatsapp').classList.toggle('visible', data.whatsappMax > 0);
                document.querySelector('.ghl-progress-section.email').classList.toggle('visible', data.emailMax > 0);

                // Types section: show only when a specific subaccount is selected
                document.getElementById('typesSection').classList.toggle('visible', !isAll);

                // Subaccounts list: show only on "All"
                document.getElementById('subaccountsContent').classList.toggle('visible', isAll && document.getElementById('subaccountsContent').classList.contains('visible'));
                document.getElementById('subaccountsToggle').style.display = isAll ? '' : 'none';

                // Metrics
                document.getElementById('whatsappCreditTotal').textContent     = fmt0(data.whatsappCredit);
                document.getElementById('whatsappCreditUsed').textContent      = fmt0(data.whatsappUsedCredit);
                document.getElementById('whatsappCreditRemaining').textContent = fmt0(data.whatsappRemainingCredit);
                document.getElementById('whatsappAmountTotal').textContent     = fmt2(data.whatsappAmount);
                document.getElementById('whatsappAmountUsed').textContent      = fmt2(data.whatsappUsedAmount);
                document.getElementById('whatsappAmountRemaining').textContent = fmt2(data.whatsappRemainingAmount);

                document.getElementById('emailCreditTotal').textContent        = fmt0(data.emailCredit);
                document.getElementById('emailCreditUsed').textContent         = fmt0(data.emailUsedCredit);
                document.getElementById('emailCreditRemaining').textContent    = fmt0(data.emailRemainingCredit);
                document.getElementById('emailAmountTotal').textContent        = fmt2(data.emailAmount);
                document.getElementById('emailAmountUsed').textContent         = fmt2(data.emailUsedAmount);
                document.getElementById('emailAmountRemaining').textContent    = fmt2(data.emailRemainingAmount);

                // Progress bars
                document.querySelector('.ghl-progress-fill.whatsapp').style.width = `${data.whatsappPercent}%`;
                document.querySelector('.ghl-progress-fill.email').style.width    = `${data.emailPercent}%`;
                document.querySelector('.ghl-progress-percent.whatsapp').textContent = `${data.whatsappPercent}% Used`;
                document.querySelector('.ghl-progress-percent.email').textContent    = `${data.emailPercent}% Used`;

                // Progress labels
                const wLabels = document.querySelectorAll('.ghl-progress-section.whatsapp .ghl-progress-labels div');
                wLabels[1].textContent = fmt2(Math.round(data.whatsappMax / 2));
                wLabels[2].textContent = fmt2(data.whatsappMax);

                const eLabels = document.querySelectorAll('.ghl-progress-section.email .ghl-progress-labels div');
                eLabels[1].textContent = fmt2(Math.round(data.emailMax / 2));
                eLabels[2].textContent = fmt2(data.emailMax);

                // Types grid
                const typesGrid = document.getElementById('typesGrid');
                typesGrid.innerHTML = '';
                if (!isAll) {
                    const iconArr = Object.values(typeIcons);
                    let i = 0;
                    for (const [type, typeData] of Object.entries(data.types || {})) {
                        const icon = iconArr[i++ % iconArr.length];
                        typesGrid.insertAdjacentHTML('beforeend', `
                            <div class="ghl-type">
                                <div class="ghl-type-info">
                                    <div class="ghl-type-icon">${icon}</div>
                                    <div>
                                        <div class="ghl-type-name">${type}</div>
                                        <div class="ghl-type-status">${typeData.count} transaction${typeData.count !== 1 ? 's' : ''}</div>
                                    </div>
                                </div>
                                <div class="ghl-type-credits">${fmt2(typeData.totalAmount)}</div>
                            </div>
                        `);
                    }
                }
            }

            // Tab switching
            document.querySelectorAll('.ghl-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    const card    = tab.closest('.ghl-card');
                    const tabType = tab.dataset.tab;
                    card.querySelectorAll('.ghl-tab').forEach(t => t.classList.remove('active'));
                    card.querySelectorAll('.ghl-metrics-credits, .ghl-metrics-amount').forEach(m => m.classList.remove('active'));
                    tab.classList.add('active');
                    card.querySelector(`.ghl-metrics-${tabType}`).classList.add('active');
                });
            });

            // Subaccount select
            document.getElementById('subaccountSelect').addEventListener('change', function() {
                updateDashboard(this.value);
            });

            // Subaccounts toggle
            document.getElementById('subaccountsToggle').addEventListener('click', () => {
                document.getElementById('subaccountsContent').classList.toggle('visible');
                document.getElementById('toggleIcon').classList.toggle('ghl-rotate');
            });

            // Modal close
            document.getElementById('modalClose').addEventListener('click', () => {
                document.getElementById('transactionsModal').classList.remove('visible');
            });
            document.getElementById('transactionsModal').addEventListener('click', e => {
                if (e.target === document.getElementById('transactionsModal')) {
                    document.getElementById('transactionsModal').classList.remove('visible');
                }
            });
        });
    </script>
</body>
</html>