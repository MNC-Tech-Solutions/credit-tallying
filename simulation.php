<?php
// Handle CORS for webhook requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// File to store webhook data
$dataFile = 'webhook_data.json';

// Check for POST request (webhook from GHL)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify Authorization header
    $headers = getallheaders();
    $expectedToken = 'Bearer 2975631feba9047abb00649c31c08b8f';
    if (!isset($headers['Authorization']) || $headers['Authorization'] !== $expectedToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Parse the incoming JSON data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    // Load existing data
    $existingData = [];
    if (file_exists($dataFile)) {
        $existingData = json_decode(file_get_contents($dataFile), true);
        if (!is_array($existingData)) {
            $existingData = [];
        }
    }

    // Append new data
    $existingData[] = [
        'send_date' => $data['send_date'] ?? 'N/A',
        'message' => $data['message'] ?? 'N/A'
    ];

    // Save updated data
    file_put_contents($dataFile, json_encode($existingData));
    http_response_code(200);
    echo json_encode(['status' => 'success']);
    exit;
}

// Serve the data for GET requests (for the frontend)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($dataFile)) {
        $data = file_get_contents($dataFile);
        header('Content-Type: application/json');
        echo $data;
        exit;
    } else {
        echo json_encode([]);
        exit;
    }
}

// Serve the HTML page with Bootstrap table
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webhook Data Display</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h2>Webhook Data from GHL</h2>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Send Date</th>
                    <th>Message</th>
                </tr>
            </thead>
            <tbody id="dataTable">
                <!-- Data will be inserted here -->
            </tbody>
        </table>
    </div>

    <script>
        // Function to display data in the table
        function displayData(data) {
            const tableBody = document.getElementById('dataTable');
            tableBody.innerHTML = ''; // Clear existing rows
            data.forEach(item => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${item.send_date || 'N/A'}</td>
                    <td>${item.message || 'N/A'}</td>
                `;
                tableBody.appendChild(row);
            });
        }

        // Fetch data from the server
        document.addEventListener('DOMContentLoaded', () => {
            fetch('/widget/simulation.php', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (Array.isArray(data)) {
                    displayData(data);
                }
            })
            .catch(error => console.error('Error fetching webhook data:', error));
        });
    </script>
</body>
</html>