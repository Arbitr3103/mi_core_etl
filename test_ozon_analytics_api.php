<?php
/**
 * Test script for Ozon Analytics API endpoints
 * 
 * Tests all the implemented endpoints to ensure they work correctly
 */

echo "🧪 Testing Ozon Analytics API Endpoints\n";
echo "=" . str_repeat("=", 50) . "\n\n";

// Test configuration
$baseUrl = 'http://localhost/src/api/ozon-analytics.php';
$testDateFrom = '2025-01-01';
$testDateTo = '2025-01-10';

/**
 * Make HTTP request
 */
function makeRequest($url, $method = 'GET', $data = null) {
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    
    if ($method === 'POST' && $data) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($response === false) {
        return ['error' => $error, 'http_code' => 0];
    }
    
    return [
        'response' => json_decode($response, true),
        'http_code' => $httpCode,
        'raw_response' => $response
    ];
}

/**
 * Test endpoint
 */
function testEndpoint($name, $url, $method = 'GET', $data = null, $expectedCode = 200) {
    echo "Testing {$name}...\n";
    
    $result = makeRequest($url, $method, $data);
    
    if (isset($result['error'])) {
        echo "❌ CURL Error: {$result['error']}\n\n";
        return false;
    }
    
    $httpCode = $result['http_code'];
    $response = $result['response'];
    
    if ($httpCode !== $expectedCode) {
        echo "❌ HTTP Code: Expected {$expectedCode}, got {$httpCode}\n";
        echo "Response: " . $result['raw_response'] . "\n\n";
        return false;
    }
    
    if (!$response) {
        echo "❌ Invalid JSON response\n";
        echo "Raw response: " . $result['raw_response'] . "\n\n";
        return false;
    }
    
    if (!isset($response['success'])) {
        echo "❌ Response missing 'success' field\n";
        echo "Response: " . json_encode($response, JSON_PRETTY_PRINT) . "\n\n";
        return false;
    }
    
    echo "✅ Success: HTTP {$httpCode}\n";
    echo "Message: " . ($response['message'] ?? 'No message') . "\n";
    
    if (isset($response['data']) && is_array($response['data'])) {
        echo "Data items: " . count($response['data']) . "\n";
    }
    
    echo "\n";
    return true;
}

// Test 1: Health check
echo "1. Health Check\n";
echo "-" . str_repeat("-", 20) . "\n";
testEndpoint(
    'Health Check',
    $baseUrl . '?action=health'
);

// Test 2: Funnel data
echo "2. Funnel Data\n";
echo "-" . str_repeat("-", 20) . "\n";
testEndpoint(
    'Funnel Data',
    $baseUrl . '?action=funnel-data&date_from=' . $testDateFrom . '&date_to=' . $testDateTo
);

// Test 3: Demographics
echo "3. Demographics\n";
echo "-" . str_repeat("-", 20) . "\n";
testEndpoint(
    'Demographics',
    $baseUrl . '?action=demographics&date_from=' . $testDateFrom . '&date_to=' . $testDateTo
);

// Test 4: Campaigns
echo "4. Campaigns\n";
echo "-" . str_repeat("-", 20) . "\n";
testEndpoint(
    'Campaigns',
    $baseUrl . '?action=campaigns&date_from=' . $testDateFrom . '&date_to=' . $testDateTo
);

// Test 5: Export data (JSON)
echo "5. Export Data (JSON)\n";
echo "-" . str_repeat("-", 20) . "\n";
testEndpoint(
    'Export JSON',
    $baseUrl . '?action=export-data',
    'POST',
    [
        'data_type' => 'funnel',
        'format' => 'json',
        'date_from' => $testDateFrom,
        'date_to' => $testDateTo
    ]
);

// Test 6: Invalid endpoint
echo "6. Invalid Endpoint\n";
echo "-" . str_repeat("-", 20) . "\n";
testEndpoint(
    'Invalid Endpoint',
    $baseUrl . '?action=invalid',
    'GET',
    null,
    404
);

// Test 7: Missing parameters
echo "7. Missing Parameters\n";
echo "-" . str_repeat("-", 20) . "\n";
testEndpoint(
    'Missing Date Parameters',
    $baseUrl . '?action=funnel-data',
    'GET',
    null,
    400
);

echo "🏁 Testing completed!\n";
echo "=" . str_repeat("=", 50) . "\n";
?>