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

        // Find index of relevant columns
        $locationIdIndex = array_search('locationId', $header);
        $typeIndex = array_search('type', $header);
        $amountIndex = array_search('amount', $header);

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

            if (!isset($results[$locationId])) {
                $results[$locationId] = [
                    'types' => [],
                    'totalAmount' => 0
                ];
            }

            if (!isset($results[$locationId]['types'][$type])) {
                $results[$locationId]['types'][$type] = [
                    'amount' => 0,
                    'count' => 0
                ];
            }

            $results[$locationId]['types'][$type]['amount'] += $amount;
            $results[$locationId]['types'][$type]['count']++;
            $results[$locationId]['totalAmount'] += $amount;
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
            max-width: 1000px;
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
    </style>
</head>
<body>
    <div class="container">
        <h2>CSV Processing Results</h2>
        <table class="table table-striped table-bordered table-hover">
            <thead class="table-dark">
                <tr>
                    <th scope="col">Location ID</th>
                    <th scope="col">Type</th>
                    <th scope="col">Total Amount</th>
                    <th scope="col">Transaction Count</th>
                </tr>
            </thead>
            <tbody>
HTML;

    // Track overall totals
    $overallTotalAmount = 0;
    $overallTotalCount = 0;

    // Display each location and its types
    foreach ($results as $locationId => $types) {
        $locationTotalAmount = 0;
        $locationTotalCount = 0;

        // Calculate totals for the location
        foreach ($types['types'] as $type => $data) {
            $locationTotalAmount += $data['amount'];
            $locationTotalCount += $data['count'];
        }

        // Display location header row
        printf(
            "<tr class=\"location-header\"><td>%s</td><td></td><td>%.2f</td><td>%d</td></tr>\n",
            htmlspecialchars($locationId),
            $locationTotalAmount,
            $locationTotalCount
        );

        // Display each type under the location
        foreach ($types['types'] as $type => $data) {
            printf(
                "<tr><td></td><td>%s</td><td>%.2f</td><td>%d</td></tr>\n",
                htmlspecialchars($type),
                $data['amount'],
                $data['count']
            );
        }

        $overallTotalAmount += $locationTotalAmount;
        $overallTotalCount += $locationTotalCount;
    }

    // Display total row
    printf(
        "<tr class=\"table-primary\"><td><strong>TOTAL</strong></td><td></td><td><strong>%.2f</strong></td><td><strong>%d</strong></td></tr>\n",
        $overallTotalAmount,
        $overallTotalCount
    );

    // Close HTML
    echo <<<HTML
            </tbody>
        </table>
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