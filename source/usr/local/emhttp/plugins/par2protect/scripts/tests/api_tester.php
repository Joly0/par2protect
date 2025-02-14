<?php
/**
 * PAR2Protect API Tester
 * 
 * A flexible script to test various PAR2Protect API endpoints with CSRF token support.
 * 
 * Usage:
 * php api_tester.php [endpoint] [method] [data_json]
 * 
 * Examples:
 * php api_tester.php queue GET
 * php api_tester.php queue POST '{"operation_type":"protect","parameters":{"path":"/mnt/cache/backup","redundancy":10}}'
 * php api_tester.php settings GET
 */

// Default values
$endpoint = $argv[1] ?? 'queue';
$method = $argv[2] ?? 'GET';
$dataJson = $argv[3] ?? '{}';

// Configuration
$baseUrl = 'http://localhost';
$apiUrl = $baseUrl . '/plugins/par2protect/api/v1/index.php';
$varIniPath = '/var/local/emhttp/var.ini';

echo "API Tester for PAR2Protect\n";
echo "-------------------------\n";
echo "Endpoint: $endpoint\n";
echo "Method: $method\n";
echo "API URL: $apiUrl?endpoint=$endpoint\n";
echo "Data: $dataJson\n\n";

// Read the CSRF token from var.ini
echo "Reading CSRF token from $varIniPath...\n";

// Read the var.ini file
if (!file_exists($varIniPath)) {
    die("Error: $varIniPath does not exist. Make sure you're running this on an Unraid server.\n");
}

$varIni = parse_ini_file($varIniPath);
if ($varIni === false) {
    die("Error: Failed to parse $varIniPath\n");
}

// Extract CSRF token
if (isset($varIni['csrf_token'])) {
    $csrfToken = trim($varIni['csrf_token'], '"');
    echo "CSRF token: $csrfToken\n\n";
} else {
    die("Error: csrf_token not found in $varIniPath\n");
}

// Parse the JSON data
$data = json_decode($dataJson, true);
if (json_last_error() !== JSON_ERROR_NONE && !empty($dataJson)) {
    die("Invalid JSON data: " . json_last_error_msg() . "\n");
}

if (!is_array($data)) {
    $data = [];
}

// Add CSRF token to the data for POST/PUT requests
if (in_array($method, ['POST', 'PUT'])) {
    $data['csrf_token'] = $csrfToken;
}

// Set up cURL
$url = $apiUrl . '?endpoint=' . $endpoint . '&csrf_token=' . urlencode($csrfToken);
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

// Set headers and data for POST/PUT requests
if (in_array($method, ['POST', 'PUT'])) {
    // Use form data instead of JSON
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
}

// Execute request
echo "Making $method request to $url...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

// Display results
echo "HTTP Status Code: $httpCode\n";
echo "Content Type: $contentType\n\n";
echo "Response:\n";
if ($response) {
    // Try to parse as JSON if it looks like JSON
    if (strpos($contentType, 'application/json') !== false || 
        (substr($response, 0, 1) == '{' && substr($response, -1) == '}') ||
        (substr($response, 0, 1) == '[' && substr($response, -1) == ']')) {
        
        $jsonResponse = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            echo json_encode($jsonResponse, JSON_PRETTY_PRINT) . "\n";
        } else {
            echo $response . "\n";
        }
    } else {
        // If it's HTML, just show a snippet
        if (strpos($contentType, 'text/html') !== false) {
            echo "HTML response received. First 500 characters:\n";
            echo substr($response, 0, 500) . "...\n";
        } else {
            echo $response . "\n";
        }
    }
} else {
    echo "No response received.\n";
}