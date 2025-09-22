<?php
// Get locationId from query parameter with validation
$targetLocationId = isset($_GET['locationId']) ? trim($_GET['locationId']) : '';
$isDemo = $targetLocationId === 'WphrMU0x3Ocd2pEpBJcH';
$isSJ360 = $targetLocationId === 'BXuCudh2EKUEmv1gC4ai';
$isSpecial = $isDemo || $isSJ360;
if ($targetLocationId !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $targetLocationId)) {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHL Credits Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="text-center p-6 bg-white rounded-lg shadow-lg">
        <h2 class="text-2xl font-bold text-red-600">Invalid subaccount ID.</h2>
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

// Function to read credit limits from total_credits.csv
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

// Function to process CSV files and gather location, type, and monthly data
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
        if ($locationIdIndex === false || $typeIndex === false) {
            error_log("Required columns (locationId or type) not found in: $filePath");
            fclose($file);
            continue;
        }
        while (($row = fgetcsv($file)) !== false) {
            if (empty($row[$locationIdIndex]) || empty($row[$typeIndex])) {
                continue;
            }
            $locationId = trim($row[$locationIdIndex]);
            if ($targetLocationId !== null && $locationId !== $targetLocationId) {
                continue;
            }
            $type = trim($row[$typeIndex]);
            // Only process type exactly "Workflow - Premium Features"
            if (strcasecmp($type, 'Workflow - Premium Features') !== 0) {
                continue;
            }
            $locationName = isset($locationNameIndex) && !empty($row[$locationNameIndex]) ? trim($row[$locationNameIndex]) : $locationId;
            // Set amounts and count transactions
            $amount = isset($row[$amountIndex]) ? floatval($row[$amountIndex]) : 1;
            if (!isset($results[$locationId])) {
                $results[$locationId] = [
                    'locationName' => $locationName,
                    'types' => [],
                    'monthlyData' => []
                ];
            }
            if (!isset($results[$locationId]['types'][$type])) {
                $results[$locationId]['types'][$type] = [
                    'totalAmount' => 0,
                    'count' => 0
                ];
            }
            $results[$locationId]['types'][$type]['totalAmount'] += $amount;
            $results[$locationId]['types'][$type]['count'] += 1; // Increment count for each transaction
            // Process monthly data
            if ($dateIndex !== false && !empty($row[$dateIndex])) {
                $dateStr = preg_replace('/(st|nd|rd|th)/', '', $row[$dateIndex]);
                $date = DateTime::createFromFormat('M j Y, h:i:s A', trim($dateStr));
                if ($date !== false) {
                    $monthKey = $date->format('Y-m');
                    if (!isset($results[$locationId]['monthlyData'][$monthKey])) {
                        $results[$locationId]['monthlyData'][$monthKey] = [
                            'types' => []
                        ];
                    }
                    $monthlyType = 'Premium';
                    if (!isset($results[$locationId]['monthlyData'][$monthKey]['types'][$monthlyType])) {
                        $results[$locationId]['monthlyData'][$monthKey]['types'][$monthlyType] = [
                            'totalAmount' => 0,
                            'count' => 0,
                            'transactions' => []
                        ];
                    }
                    $results[$locationId]['monthlyData'][$monthKey]['types'][$monthlyType]['totalAmount'] += $amount;
                    $results[$locationId]['monthlyData'][$monthKey]['types'][$monthlyType]['count'] += 1;
                    $results[$locationId]['monthlyData'][$monthKey]['types'][$monthlyType]['transactions'][] = [
                        'date' => $row[$dateIndex],
                        'amount' => $amount,
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
$creditLimits = getCreditLimits($creditLimitsFile);
$processedData = processCsvFiles($csvFiles);

// Prepare subaccount data
$subaccountData = [];
$allSubaccounts = [
    'types' => [],
    'monthlyData' => []
];

// Calculate totals for "All Subaccounts" (excluding Demo subaccount)
$allTypes = [];
$allMonthlyData = [];
foreach ($processedData as $locationId => $data) {
    if ($locationId === 'WphrMU0x3Ocd2pEpBJcH') {
        error_log("Skipping Demo subaccount (WphrMU0x3Ocd2pEpBJcH) for All Subaccounts aggregation");
        continue;
    }
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
    foreach ($data['monthlyData'] as $month => $monthData) {
        if (!isset($allMonthlyData[$month])) {
            $allMonthlyData[$month] = ['types' => []];
        }
        foreach ($monthData['types'] as $monthlyType => $typeData) {
            if (!isset($allMonthlyData[$month]['types'][$monthlyType])) {
                $allMonthlyData[$month]['types'][$monthlyType] = [
                    'totalAmount' => 0,
                    'count' => 0,
                    'transactions' => []
                ];
            }
            $allMonthlyData[$month]['types'][$monthlyType]['totalAmount'] += $typeData['totalAmount'];
            $allMonthlyData[$month]['types'][$monthlyType]['count'] += $typeData['count'];
            $allMonthlyData[$month]['types'][$monthlyType]['transactions'] = array_merge(
                $allMonthlyData[$month]['types'][$monthlyType]['transactions'],
                $typeData['transactions']
            );
        }
    }
}
$allSubaccounts['types'] = $allTypes;
$allSubaccounts['monthlyData'] = $allMonthlyData;
$subaccountData[''] = $allSubaccounts;
error_log("All Subaccounts aggregated data (excluding Demo): " . json_encode($allSubaccounts));

// Calculate for individual subaccounts
foreach ($processedData as $locationId => $data) {
    $subaccountData[$locationId] = [
        'name' => $data['locationName'],
        'types' => $data['types'],
        'monthlyData' => $data['monthlyData']
    ];
}
$subaccountDataJson = json_encode($subaccountData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

// Determine initial data to display
$initialData = $allSubaccounts;
if (empty($initialData['types']) && empty($initialData['monthlyData'])) {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHL Credits Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div class="text-center p-6 bg-white rounded-lg shadow-lg">
        <h2 class="text-2xl font-bold text-red-600">No valid data found for All Subaccounts.</h2>
    </div>
</body>
</html>
HTML;
    exit;
}

// Collect all months across all subaccounts for the dropdown
$allMonths = [];
foreach ($processedData as $locationId => $data) {
    if ($locationId === 'WphrMU0x3Ocd2pEpBJcH') {
        continue; // Skip Demo subaccount
    }
    foreach (array_keys($data['monthlyData']) as $month) {
        $allMonths[$month] = true;
    }
}
$allMonths = array_keys($allMonths);
sort($allMonths);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GHL Credits Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .ghl-animate {
            transition: all 0.3s ease;
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
        .ghl-modal.visible {
            display: flex;
        }
        .ghl-types-section {
            display: none;
        }
        .ghl-types-section.visible {
            display: block;
        }
        .ghl-monthly-section {
            display: none;
        }
        .ghl-monthly-section.visible {
            display: block;
        }
        .ghl-subaccount:hover, .ghl-type:hover, .ghl-monthly-type:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body class="bg-gray-50 flex items-center justify-center min-h-screen p-4 font-sans">
    <div class="w-full max-w-5xl bg-white rounded-2xl shadow-2xl overflow-hidden ghl-animate">
        <div class="p-6 border-b border-gray-200">
            <div class="flex justify-between items-center mb-4">
                <h1 class="text-3xl font-bold text-gray-800">Workflow Premium Actions Dashboard</h1>
                <div class="w-10 h-10 bg-gray-100 rounded-lg flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-gray-600">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
            </div>
            <div id="errorMessage" class="hidden text-center p-4 text-red-600 font-semibold bg-red-50 rounded-lg mt-4">No data available.</div>
        </div>
        <div class="p-6 grid gap-6">
            <!-- Types Section -->
            <div id="typesSection" class="ghl-types-section visible">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Usage by Type</h2>
                <div id="typesGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php
                    $typeIcons = [
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-5 h-5 text-gray-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
                    ];
                    $iconIndex = 0;
                    foreach ($initialData['types'] as $type => $typeData) {
                        $totalAmount = number_format($typeData['totalAmount'], 2);
                        $count = $typeData['count'];
                        $plural = $count !== 1 ? 's' : '';
                        $icon = $typeIcons[$iconIndex % count($typeIcons)];
                        $iconIndex++;
                        echo <<<HTML
<div class="ghl-type bg-white p-4 border border-gray-200 rounded-lg flex justify-between items-center ghl-animate">
    <div class="flex items-center">
        <div class="w-8 h-8 bg-gray-100 rounded-md flex items-center justify-center mr-3">
            {$icon}
        </div>
        <div>
            <div class="font-medium text-gray-800">{$type}</div>
            <div class="text-sm text-gray-500">{$count} transaction{$plural}</div>
        </div>
    </div>
    <div class="font-semibold text-gray-800">{$totalAmount}</div>
</div>
HTML;
                    }
                    ?>
                </div>
            </div>
            
            <!-- Subaccounts List -->
            <div id="subaccountsList" class="ghl-subaccounts-list">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Subaccounts (<?php echo count($processedData); ?>)</h2>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php
                    $icons = [
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-5 h-5 text-gray-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>',
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-5 h-5 text-gray-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>',
                        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-5 h-5 text-gray-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
                    ];
                    $iconIndex = 0;
                    foreach ($processedData as $locationId => $data) {
                        if ($locationId === 'WphrMU0x3Ocd2pEpBJcH') {
                            continue; // Skip Demo subaccount
                        }
                        $locationName = htmlspecialchars($data['locationName']);
                        $totalAmount = array_sum(array_map(function($typeData) { return $typeData['totalAmount']; }, $data['types']));
                        $totalCount = array_sum(array_map(function($typeData) { return $typeData['count']; }, $data['types']));
                        $totalAmountFormatted = number_format($totalAmount, 2);
                        $status = $totalAmount > 0 ? 'Active' : 'Inactive';
                        $plural = $totalCount !== 1 ? 's' : '';
                        $icon = $icons[$iconIndex % count($icons)];
                        $iconIndex++;
                        echo <<<HTML
                            <div class="ghl-subaccount bg-white p-4 border border-gray-200 rounded-lg flex justify-between items-center ghl-animate">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 bg-gray-100 rounded-md flex items-center justify-center mr-3">
                                        {$icon}
                                    </div>
                                    <div>
                                        <div class="font-medium text-gray-800">{$locationName}</div>
                                        <div class="text-sm text-gray-500">{$status} • {$totalCount} transaction{$plural}</div>
                                    </div>
                                </div>
                                <div class="font-semibold text-gray-800">{$totalAmountFormatted}</div>
                            </div>
                            HTML;
                    }
                    ?>
                </div>
            </div>

            <!-- Monthly Section -->
            <div id="monthlySection" class="ghl-monthly-section <?php echo !empty($allMonths) ? 'visible' : ''; ?>">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Transactions by Month</h2>
                <select id="monthlySelect" class="w-full p-3 border border-gray-300 rounded-lg bg-white text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500 ghl-animate">
                    <option value="">Select a month</option>
                    <?php
                    foreach ($allMonths as $month) {
                        $monthName = DateTime::createFromFormat('Y-m', $month)->format('F Y');
                        echo "<option value=\"{$month}\">{$monthName}</option>\n";
                    }
                    ?>
                </select>
                <div id="monthlyTypesGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-4"></div>
            </div>
        </div>
        <div id="transactionsModal" class="ghl-modal">
            <div class="bg-white rounded-2xl p-6 max-w-lg w-full max-h-[50vh] overflow-y-auto shadow-2xl relative ghl-animate">
                <span id="modalClose" class="absolute top-4 right-4 text-2xl text-gray-600 cursor-pointer hover:text-gray-800">&times;</span>
                <h3 id="modalTitle" class="text-lg font-semibold text-gray-800 mb-4"></h3>
                <table id="transactionsTable" class="w-full border-collapse">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="p-3 text-left font-semibold text-gray-800">Date & Time</th>
                            <th class="p-3 text-left font-semibold text-gray-800">Amount</th>
                            <th class="p-3 text-left font-semibold text-gray-800">Type</th>
                        </tr>
                    </thead>
                    <tbody id="transactionsTableBody" class="text-gray-700"></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const typesSection = document.getElementById('typesSection');
            const typesGrid = document.getElementById('typesGrid');
            const monthlySection = document.getElementById('monthlySection');
            const monthlySelect = document.getElementById('monthlySelect');
            const monthlyTypesGrid = document.getElementById('monthlyTypesGrid');
            const transactionsModal = document.getElementById('transactionsModal');
            const modalClose = document.getElementById('modalClose');
            const modalTitle = document.getElementById('modalTitle');
            const transactionsTableBody = document.getElementById('transactionsTableBody');
            const errorMessage = document.getElementById('errorMessage');
            const subaccountData = <?php echo $subaccountDataJson; ?>;
            const typeIcons = {
                'Premium': '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-5 h-5 text-gray-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
            };
            const subaccountIcons = [
                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-5 h-5 text-gray-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg>',
                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-5 h-5 text-gray-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>',
                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-5 h-5 text-gray-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>'
            ];

            function updateDashboard() {
                const data = subaccountData[''];
                errorMessage.classList.toggle('hidden', !!data);
                typesSection.classList.toggle('visible', !!data);
                monthlySection.classList.toggle('visible', !!data && Object.keys(subaccountData).length > 1);
                if (data) {
                    errorMessage.textContent = '';
                    typesGrid.innerHTML = '';
                    let iconIndex = 0;
                    const typeIconsArray = Object.values(typeIcons);
                    for (const [type, typeData] of Object.entries(data.types || {})) {
                        const totalAmount = typeData.totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        const count = typeData.count;
                        const plural = count !== 1 ? 's' : '';
                        const icon = typeIconsArray[iconIndex % typeIconsArray.length];
                        iconIndex++;
                        const typeElement = `
                            <div class="ghl-type bg-white p-4 border border-gray-200 rounded-lg flex justify-between items-center ghl-animate">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 bg-gray-100 rounded-md flex items-center justify-center mr-3">
                                        ${icon}
                                    </div>
                                    <div>
                                        <div class="font-medium text-gray-800">${type}</div>
                                        <div class="text-sm text-gray-500">${count} transaction${plural}</div>
                                    </div>
                                </div>
                                <div class="font-semibold text-gray-800">${totalAmount}</div>
                            </div>
                        `;
                        typesGrid.insertAdjacentHTML('beforeend', typeElement);
                    }
                    monthlyTypesGrid.innerHTML = '';
                    monthlySelect.value = '';
                } else {
                    errorMessage.textContent = 'No data available for All Subaccounts.';
                }
            }

            function showModal(subaccountName, type, month, transactions) {
                modalTitle.textContent = `${type} Transactions - ${subaccountName} - ${new Date(`${month}-01`).toLocaleString('en-US', { month: 'long', year: 'numeric' })}`;
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
                        <tr class="border-b border-gray-200">
                            <td class="p-3">${formattedDate}</td>
                            <td class="p-3">${amount}</td>
                            <td class="p-3">${originalType}</td>
                        </tr>
                    `;
                    transactionsTableBody.insertAdjacentHTML('beforeend', row);
                });
                transactionsModal.classList.add('visible');
            }

            if (monthlySelect) {
                monthlySelect.addEventListener('change', function(e) {
                    const selectedMonth = this.value;
                    monthlyTypesGrid.innerHTML = '';
                    if (selectedMonth) {
                        let iconIndex = 0;
                        Object.entries(subaccountData).forEach(([locationId, data]) => {
                            if (locationId === '' || locationId === 'WphrMU0x3Ocd2pEpBJcH') {
                                return; // Skip aggregated data and Demo subaccount
                            }
                            const monthlyData = data.monthlyData?.[selectedMonth]?.types?.['Premium'];
                            if (monthlyData) {
                                const subaccountName = data.name;
                                const totalAmount = monthlyData.totalAmount.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                const count = monthlyData.count;
                                const plural = count !== 1 ? 's' : '';
                                const icon = subaccountIcons[iconIndex % subaccountIcons.length];
                                iconIndex++;
                                const typeElement = document.createElement('div');
                                typeElement.className = 'ghl-monthly-type bg-white p-4 border border-gray-200 rounded-lg flex justify-between items-center ghl-animate';
                                typeElement.innerHTML = `
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 bg-gray-100 rounded-md flex items-center justify-center mr-3">
                                            ${icon}
                                        </div>
                                        <div>
                                            <div class="font-medium text-gray-800">${subaccountName}</div>
                                            <div class="text-sm text-gray-500">${count} transaction${plural}</div>
                                        </div>
                                    </div>
                                    <div class="font-semibold text-gray-800">${totalAmount}</div>
                                `;
                                typeElement.addEventListener('click', () => {
                                    showModal(subaccountName, 'Premium', selectedMonth, monthlyData.transactions);
                                });
                                monthlyTypesGrid.appendChild(typeElement);
                            }
                        });
                    }
                });
                modalClose.addEventListener('click', () => {
                    transactionsModal.classList.remove('visible');
                });
                transactionsModal.addEventListener('click', (e) => {
                    if (e.target === transactionsModal) {
                        transactionsModal.classList.remove('visible');
                    }
                });
            }

            // Initial dashboard update
            updateDashboard();
        });
    </script>
</body>
</html>