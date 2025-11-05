<?php

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define the path to the CSV files directory relative to this script
$scriptDir = dirname(__FILE__); // Get the directory where this script is located
$csvDirectory = $scriptDir . '\csv_files'; // Go up one level and into csv_files

// Debug information
echo "Script location: " . $scriptDir . "<br>";
echo "Looking for CSV files in: " . $csvDirectory . "<br>";
echo "Directory exists: " . (is_dir($csvDirectory) ? "Yes" : "No") . "<br>";

// Function to check for new data
function checkNewData($directory) {
    $flagFile = $directory . '/.new_data';
    if (file_exists($flagFile)) {
        echo "Found flag file: " . $flagFile . "<br>";
        $newFile = trim(file_get_contents($flagFile));
        echo "New file from flag: " . $newFile . "<br>";
        unlink($flagFile); // Remove the flag file
        return $newFile;
    }
    return null;
}

// Function to get all CSV files from a directory
function getCsvFiles($directory) {
    $csvFiles = [];
    
    // Check if directory exists
    if (!is_dir($directory)) {
        echo "Directory not found: $directory<br>";
        return $csvFiles;
    }

    // Check for new data
    $newFile = checkNewData($directory);
    if ($newFile !== null) {
        $fullPath = $directory . '/' . $newFile;
        echo "Checking for file: " . $fullPath . "<br>";
        if (file_exists($fullPath)) {
            echo "Found new file: " . $fullPath . "<br>";
            return [$fullPath];
        }
    }

    // If no new data, scan directory for all CSV files
    $files = glob($directory . '/*.csv');
    if (empty($files)) {
        echo "No CSV files found in: $directory<br>";
    } else {
        echo "Found " . count($files) . " CSV files:<br>";
        foreach ($files as $file) {
            echo "- " . basename($file) . "<br>";
        }
        $csvFiles = $files;
    }

    return $csvFiles;
}

// Function to process CSV files and count amounts grouped by locationId and type
function processCsvFiles($csvFiles) {
    $results = [];
    
    // Iterate through each CSV file
    foreach ($csvFiles as $filePath) {
        if (!file_exists($filePath)) {
            echo "File not found: $filePath<br>";
            continue;
        }

        // Open the CSV file
        $file = fopen($filePath, 'r');
        if ($file === false) {
            echo "Failed to open file: $filePath<br>";
            continue;
        }

        // Read the header
        $header = fgetcsv($file);
        if ($header === false) {
            echo "Failed to read header from: $filePath<br>";
            fclose($file);
            continue;
        }

        // Map header columns - handle both old and new formats
        $locationIdIndex = false;
        $typeIndex = false;
        $amountIndex = false;
        $descriptionIndex = false;
        
        foreach ($header as $index => $column) {
            $column = strtolower(trim($column));
            if ($column === 'locationid' || $column === 'location id') $locationIdIndex = $index;
            if ($column === 'type' || $column === 'transaction type') $typeIndex = $index;
            if ($column === 'amount') $amountIndex = $index;
            if ($column === 'description') $descriptionIndex = $index;
        }

        if ($locationIdIndex === false || $typeIndex === false || $amountIndex === false) {
            echo "Required columns (locationId, type, or amount) not found in: $filePath<br>";
            fclose($file);
            continue;
        }

        // Process the data
        while (($row = fgetcsv($file)) !== false) {
            if (empty($row[$locationIdIndex]) || empty($row[$typeIndex]) || !isset($row[$amountIndex])) {
                continue;
            }

            $locationId = trim($row[$locationIdIndex]);
            $type = trim($row[$typeIndex]);
            $amount = floatval($row[$amountIndex]);
            
            // Determine category based on type and description
            $category = 'other';
            $description = $descriptionIndex !== false && isset($row[$descriptionIndex]) ? strtolower(trim($row[$descriptionIndex])) : '';
            
            if (stripos($type, 'WhatsApp') !== false || stripos($description, 'whatsapp') !== false) {
                $category = 'whatsapp';
                // Convert to credits: 0.50 RM per WhatsApp credit
                $creditAmount = $amount;
                $creditCount = $amount / 0.50;
            } elseif (stripos($type, 'Email') !== false || stripos($description, 'email') !== false) {
                $category = 'email';
                // Convert to credits: 0.005 RM per Email credit
                $creditAmount = $amount;
                $creditCount = $amount / 0.005;
            } elseif (stripos($type, 'Workflow') !== false || stripos($description, 'workflow') !== false) {
                $category = 'workflow';
                // Workflow uses actual amounts
                $creditAmount = $amount;
                $creditCount = $amount; // 1 credit = 1 RM for workflows
            } else {
                // Skip or categorize as other
                $category = 'other';
                $creditAmount = $amount;
                $creditCount = 1;
            }

            if (!isset($results[$locationId])) {
                $results[$locationId] = [
                    'categories' => [
                        'whatsapp' => ['amount' => 0, 'count' => 0, 'credits' => 0],
                        'email' => ['amount' => 0, 'count' => 0, 'credits' => 0],
                        'workflow' => ['amount' => 0, 'count' => 0, 'credits' => 0],
                        'other' => ['amount' => 0, 'count' => 0, 'credits' => 0]
                    ],
                    'types' => [],
                    'totalAmount' => 0,
                    'totalCredits' => 0
                ];
            }

            // Update category totals
            $results[$locationId]['categories'][$category]['amount'] += $amount;
            $results[$locationId]['categories'][$category]['count']++;
            $results[$locationId]['categories'][$category]['credits'] += $creditCount;

            // Update type totals
            if (!isset($results[$locationId]['types'][$type])) {
                $results[$locationId]['types'][$type] = [
                    'amount' => 0,
                    'count' => 0,
                    'category' => $category
                ];
            }

            $results[$locationId]['types'][$type]['amount'] += $amount;
            $results[$locationId]['types'][$type]['count']++;
            
            // Update overall totals
            $results[$locationId]['totalAmount'] += $amount;
            $results[$locationId]['totalCredits'] += $creditCount;
        }

        fclose($file);
    }

    return $results;
}

// Function to display results in a Bootstrap table
function displayResults($results) {
    // Start HTML output with Bootstrap
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Processing Results</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <style>
        body {
            padding: 20px;
            background-color: #f8f9fa;
        }
        .container {
            max-width: 1200px;
        }
        h2 {
            margin-bottom: 20px;
            color: #343a40;
        }
        .sub-table {
            margin-left: 20px;
        }
        .location-header {
            background-color: #e9ecef;
            font-weight: bold;
        }
        .category-whatsapp { background-color: #d1fae5 !important; }
        .category-email { background-color: #dbeafe !important; }
        .category-workflow { background-color: #f3e8ff !important; }
        .category-other { background-color: #fef3c7 !important; }
        .table-hover tbody tr:hover {
            background-color: rgba(0,0,0,.075);
        }
        .badge {
            font-size: 0.75em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>CSV Processing Results - Credit Usage Analysis</h2>
        
        <!-- Summary Cards -->
        <div class="row mb-4">
HTML;

    // Calculate overall totals for cards
    $overallTotalAmount = 0;
    $overallTotalCredits = 0;
    $categoryTotals = [
        'whatsapp' => ['amount' => 0, 'credits' => 0],
        'email' => ['amount' => 0, 'credits' => 0],
        'workflow' => ['amount' => 0, 'credits' => 0],
        'other' => ['amount' => 0, 'credits' => 0]
    ];

    foreach ($results as $locationData) {
        $overallTotalAmount += $locationData['totalAmount'];
        $overallTotalCredits += $locationData['totalCredits'];
        
        foreach ($locationData['categories'] as $category => $data) {
            $categoryTotals[$category]['amount'] += $data['amount'];
            $categoryTotals[$category]['credits'] += $data['credits'];
        }
    }

    // Display summary cards
    $cardColors = [
        'whatsapp' => 'success',
        'email' => 'primary', 
        'workflow' => 'info',
        'other' => 'warning',
        'total' => 'dark'
    ];

    $cardData = [
        'whatsapp' => ['title' => 'WhatsApp', 'amount' => $categoryTotals['whatsapp']['amount'], 'credits' => $categoryTotals['whatsapp']['credits']],
        'email' => ['title' => 'Email', 'amount' => $categoryTotals['email']['amount'], 'credits' => $categoryTotals['email']['credits']],
        'workflow' => ['title' => 'Workflow', 'amount' => $categoryTotals['workflow']['amount'], 'credits' => $categoryTotals['workflow']['credits']],
        'other' => ['title' => 'Other', 'amount' => $categoryTotals['other']['amount'], 'credits' => $categoryTotals['other']['credits']],
        'total' => ['title' => 'Total', 'amount' => $overallTotalAmount, 'credits' => $overallTotalCredits]
    ];

    foreach ($cardData as $category => $data) {
        $color = $cardColors[$category];
        echo <<<HTML
            <div class="col-md">
                <div class="card text-white bg-$color mb-3">
                    <div class="card-header">{$data['title']}</div>
                    <div class="card-body">
                        <h5 class="card-title">RM {$data['amount']}</h5>
                        <p class="card-text">{$data['credits']} credits</p>
                    </div>
                </div>
            </div>
HTML;
    }

    echo <<<HTML
        </div>

        <!-- Detailed Table -->
        <table class="table table-striped table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th scope="col">Location ID</th>
                    <th scope="col">Category</th>
                    <th scope="col">Type</th>
                    <th scope="col">Amount (RM)</th>
                    <th scope="col">Credits</th>
                    <th scope="col">Transaction Count</th>
                </tr>
            </thead>
            <tbody>
HTML;

    // Display each location and its data
    foreach ($results as $locationId => $locationData) {
        $firstRow = true;
        
        // Display location header row
        printf(
            "<tr class=\"location-header\"><td><strong>%s</strong></td><td colspan=\"2\"><strong>LOCATION TOTAL</strong></td><td><strong>%.2f</strong></td><td><strong>%.0f</strong></td><td></td></tr>\n",
            htmlspecialchars($locationId),
            $locationData['totalAmount'],
            $locationData['totalCredits']
        );

        // Display categories
        foreach ($locationData['categories'] as $category => $categoryData) {
            if ($categoryData['count'] > 0) {
                $categoryName = ucfirst($category);
                printf(
                    "<tr class=\"category-$category\"><td></td><td><strong>%s</strong></td><td></td><td>%.2f</td><td>%.0f</td><td>%d</td></tr>\n",
                    $categoryName,
                    $categoryData['amount'],
                    $categoryData['credits'],
                    $categoryData['count']
                );
            }
        }

        // Display individual types
        foreach ($locationData['types'] as $type => $typeData) {
            $categoryClass = 'category-' . $typeData['category'];
            printf(
                "<tr class=\"$categoryClass\"><td></td><td></td><td>%s <span class=\"badge bg-secondary\">%s</span></td><td>%.2f</td><td>%.0f</td><td>%d</td></tr>\n",
                htmlspecialchars($type),
                ucfirst($typeData['category']),
                $typeData['amount'],
                $typeData['amount'] / ($typeData['category'] === 'email' ? 0.005 : ($typeData['category'] === 'whatsapp' ? 0.50 : 1)),
                $typeData['count']
            );
        }
    }

    // Close HTML
    echo <<<HTML
            </tbody>
        </table>
        
        <!-- Credit Conversion Info -->
        <div class="alert alert-info mt-4">
            <h5>Credit Conversion Rates:</h5>
            <ul class="mb-0">
                <li><strong>WhatsApp:</strong> 1 credit = RM 0.50</li>
                <li><strong>Email:</strong> 1 credit = RM 0.005</li>
                <li><strong>Workflow:</strong> 1 credit = RM 1.00</li>
            </ul>
        </div>
    </div>
    <!-- Bootstrap JS (optional, for interactive features) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
HTML;
}

// Main execution
try {
    // Get all CSV files from the directory
    $csvFiles = getCsvFiles($csvDirectory);

    if (empty($csvFiles)) {
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Processing Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body>
    <div class="container mt-4">
        <div class="alert alert-warning" role="alert">
            No CSV files to process.
        </div>
    </div>
</body>
</html>
HTML;
        exit;
    }

    // Process the CSV files
    $results = processCsvFiles($csvFiles);

    if (empty($results)) {
        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Processing Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body>
    <div class="container mt-4">
        <div class="alert alert-warning" role="alert">
            No valid data processed from the CSV files.
        </div>
    </div>
</body>
</html>
HTML;
        exit;
    }

    // Display the results
    displayResults($results);

} catch (Exception $e) {
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSV Processing Results</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
</head>
<body>
    <div class="container mt-4">
        <div class="alert alert-danger" role="alert">
            An error occurred: {$e->getMessage()}
        </div>
    </div>
</body>
</html>
HTML;
}

?>