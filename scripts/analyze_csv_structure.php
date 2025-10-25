#!/usr/bin/env php
<?php
/**
 * Analyze CSV Structure from Ozon Reports
 * 
 * This script attempts to:
 * 1. Extract warehouse IDs from Analytics API
 * 2. Request CSV report using those IDs
 * 3. Analyze CSV structure in detail
 * 4. Compare with Analytics API data
 */

require_once __DIR__ . '/test_config.php';

// Ozon API Configuration
$OZON_CLIENT_ID = $_ENV['OZON_CLIENT_ID'] ?? '26100';
$OZON_API_KEY = $_ENV['OZON_API_KEY'] ?? '7e074977-e0db-4ace-ba9e-82903e088b4b';
$OZON_API_URL = 'https://api-seller.ozon.ru';

// HTTP Headers for Ozon API
$headers = [
    'Client-Id: ' . $OZON_CLIENT_ID,
    'Api-Key: ' . $OZON_API_KEY,
    'Content-Type: application/json'
];

/**
 * Print colored output for CLI
 */
function printColored($text, $color = 'white') {
    $colors = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'magenta' => "\033[35m",
        'cyan' => "\033[36m",
        'white' => "\033[37m",
        'reset' => "\033[0m"
    ];
    
    echo $colors[$color] . $text . $colors['reset'];
}

function printSuccess($message) {
    printColored("✓ ", 'green');
    echo $message . "\n";
}

function printError($message) {
    printColored("✗ ", 'red');
    echo $message . "\n";
}

function printWarning($message) {
    printColored("⚠ ", 'yellow');
    echo $message . "\n";
}

function printInfo($message) {
    printColored("ℹ ", 'blue');
    echo $message . "\n";
}

/**
 * Make HTTP request to Ozon API
 */
function makeOzonRequest($url, $data = null, $method = 'POST') {
    global $headers;
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Warehouse-ETL-CSV-Analyzer/1.0'
    ]);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data !== null) {
            if ($data instanceof stdClass) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    curl_close($ch);
    
    if ($error) {
        throw new Exception("cURL error: $error");
    }
    
    return [
        'http_code' => $httpCode,
        'body' => $response,
        'data' => json_decode($response, true)
    ];
}

/**
 * Get warehouse data from Analytics API to extract warehouse info
 */
function getWarehouseDataFromAnalytics() {
    global $OZON_API_URL;
    
    printInfo("Step 1: Getting warehouse data from Analytics API...");
    
    $url = $OZON_API_URL . '/v2/analytics/stock_on_warehouses';
    $payload = [
        'limit' => 1000,
        'offset' => 0,
        'warehouse_type' => 'ALL'
    ];
    
    try {
        $response = makeOzonRequest($url, $payload);
        
        if ($response['http_code'] === 200 && isset($response['data']['result']['rows'])) {
            $rows = $response['data']['result']['rows'];
            printSuccess("Retrieved " . count($rows) . " stock records from Analytics API");
            
            // Extract unique warehouses
            $warehouses = [];
            foreach ($rows as $row) {
                $warehouseName = $row['warehouse_name'] ?? '';
                if (!empty($warehouseName) && !isset($warehouses[$warehouseName])) {
                    $warehouses[$warehouseName] = [
                        'name' => $warehouseName,
                        'sample_sku' => $row['sku'] ?? null,
                        'sample_data' => $row
                    ];
                }
            }
            
            printInfo("Found " . count($warehouses) . " unique warehouses:");
            foreach ($warehouses as $warehouse) {
                printInfo("  - " . $warehouse['name']);
            }
            
            return [
                'warehouses' => $warehouses,
                'sample_data' => array_slice($rows, 0, 10) // First 10 records for analysis
            ];
        } else {
            printError("Failed to get Analytics API data");
            return null;
        }
    } catch (Exception $e) {
        printError("Exception during Analytics API request: " . $e->getMessage());
        return null;
    }
}

/**
 * Try to get warehouse IDs using different approaches
 */
function tryGetWarehouseIds() {
    global $OZON_API_URL;
    
    printInfo("Step 2: Attempting to get warehouse IDs...");
    
    // Try different endpoints that might return warehouse IDs
    $endpoints = [
        '/v1/warehouse/list',
        '/v2/warehouse/list',
        '/v1/analytics/warehouse/list',
        '/v2/analytics/warehouse/list'
    ];
    
    foreach ($endpoints as $endpoint) {
        printInfo("Trying endpoint: $endpoint");
        
        try {
            $url = $OZON_API_URL . $endpoint;
            $response = makeOzonRequest($url, new stdClass());
            
            if ($response['http_code'] === 200) {
                $data = $response['data'];
                printInfo("Response: " . json_encode($data, JSON_PRETTY_PRINT));
                
                if (isset($data['result']) && !empty($data['result'])) {
                    printSuccess("Found data in $endpoint");
                    return $data['result'];
                }
            }
        } catch (Exception $e) {
            printWarning("Failed $endpoint: " . $e->getMessage());
        }
    }
    
    printWarning("Could not retrieve warehouse IDs from any endpoint");
    return [];
}

/**
 * Try alternative approaches to get CSV data
 */
function tryAlternativeCSVSources($warehouseData) {
    printInfo("Step 3: Trying alternative approaches for CSV data...");
    
    // Approach 1: Try to use warehouse names as IDs
    if (!empty($warehouseData['warehouses'])) {
        printInfo("Approach 1: Using warehouse names as potential IDs...");
        
        $warehouseNames = array_keys($warehouseData['warehouses']);
        
        // Try using warehouse names directly
        $result = tryReportsAPIWithIds($warehouseNames);
        if ($result) {
            return $result;
        }
        
        // Try extracting potential IDs from warehouse names
        $potentialIds = [];
        foreach ($warehouseNames as $name) {
            // Look for numeric patterns in warehouse names
            if (preg_match('/(\d+)/', $name, $matches)) {
                $potentialIds[] = intval($matches[1]);
            }
        }
        
        if (!empty($potentialIds)) {
            printInfo("Found potential numeric IDs: " . implode(', ', $potentialIds));
            $result = tryReportsAPIWithIds($potentialIds);
            if ($result) {
                return $result;
            }
        }
    }
    
    // Approach 2: Try common warehouse ID patterns
    printInfo("Approach 2: Trying common warehouse ID patterns...");
    
    $commonIds = [
        1, 2, 3, 4, 5, // Simple sequential IDs
        1000, 2000, 3000, // Thousand-based IDs
        22341896703000, 22341896704000, 22341896705000 // Ozon-style long IDs
    ];
    
    $result = tryReportsAPIWithIds($commonIds);
    if ($result) {
        return $result;
    }
    
    printWarning("All alternative approaches failed");
    return null;
}

/**
 * Try Reports API with given IDs
 */
function tryReportsAPIWithIds($ids) {
    global $OZON_API_URL;
    
    printInfo("Trying Reports API with IDs: " . json_encode($ids));
    
    $url = $OZON_API_URL . '/v1/report/warehouse/stock';
    $payload = [
        'language' => 'DEFAULT',
        'warehouse_id' => $ids
    ];
    
    try {
        $response = makeOzonRequest($url, $payload);
        
        if ($response['http_code'] === 200 && isset($response['data']['result']['code'])) {
            $reportCode = $response['data']['result']['code'];
            printSuccess("Report requested successfully with code: $reportCode");
            
            // Wait for report and download
            $reportInfo = waitForReport($reportCode);
            if ($reportInfo && isset($reportInfo['file'])) {
                return downloadAndAnalyzeCSV($reportInfo['file']);
            }
        } else {
            printWarning("Reports API failed: " . $response['body']);
        }
    } catch (Exception $e) {
        printWarning("Exception with Reports API: " . $e->getMessage());
    }
    
    return null;
}

/**
 * Wait for report to be ready
 */
function waitForReport($reportCode, $maxWaitMinutes = 10) {
    global $OZON_API_URL;
    
    printInfo("Waiting for report $reportCode to be ready...");
    
    $startTime = time();
    $maxWaitSeconds = $maxWaitMinutes * 60;
    
    while ((time() - $startTime) < $maxWaitSeconds) {
        $url = $OZON_API_URL . '/v1/report/info';
        $payload = ['code' => $reportCode];
        
        try {
            $response = makeOzonRequest($url, $payload);
            
            printInfo("Status check response: HTTP " . $response['http_code']);
            printInfo("Response body: " . substr($response['body'], 0, 500));
            
            if ($response['http_code'] === 200 && isset($response['data']['result'])) {
                $result = $response['data']['result'];
                $status = $result['status'] ?? 'UNKNOWN';
                
                printInfo("Report status: $status");
                
                // Log full result for debugging
                if (isset($result['error']) || isset($result['message'])) {
                    printWarning("Report details: " . json_encode($result));
                }
                
                if ($status === 'SUCCESS' && isset($result['file'])) {
                    printSuccess("Report is ready!");
                    return $result;
                } elseif ($status === 'FAILED') {
                    printError("Report generation failed");
                    if (isset($result['error'])) {
                        printError("Error details: " . $result['error']);
                    }
                    return null;
                }
            } else {
                printWarning("Unexpected response format");
            }
        } catch (Exception $e) {
            printWarning("Error checking report status: " . $e->getMessage());
        }
        
        sleep(30); // Wait 30 seconds before next check
    }
    
    printError("Report generation timed out");
    return null;
}

/**
 * Download and analyze CSV file
 */
function downloadAndAnalyzeCSV($fileUrl) {
    printInfo("Step 4: Downloading and analyzing CSV...");
    
    try {
        // Download CSV
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $fileUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $csvContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($csvContent)) {
            throw new Exception("Failed to download CSV: HTTP $httpCode");
        }
        
        printSuccess("CSV downloaded successfully (" . strlen($csvContent) . " bytes)");
        
        // Save CSV for analysis
        $filename = 'warehouse_stock_report_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = __DIR__ . '/../storage/reports/' . $filename;
        file_put_contents($filepath, $csvContent);
        printSuccess("CSV saved to: $filepath");
        
        // Analyze CSV structure
        return analyzeCSVStructure($csvContent, $filepath);
        
    } catch (Exception $e) {
        printError("Exception during CSV download: " . $e->getMessage());
        return null;
    }
}

/**
 * Detailed CSV structure analysis
 */
function analyzeCSVStructure($csvContent, $filepath) {
    printInfo("Performing detailed CSV analysis...");
    
    $analysis = [
        'file_info' => [
            'size_bytes' => strlen($csvContent),
            'filepath' => $filepath
        ],
        'encoding' => null,
        'structure' => [],
        'columns' => [],
        'sample_data' => [],
        'warehouse_analysis' => [],
        'data_quality' => []
    ];
    
    // Detect encoding
    $encoding = mb_detect_encoding($csvContent, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
    $analysis['encoding'] = $encoding ?: 'Unknown';
    printInfo("Detected encoding: " . $analysis['encoding']);
    
    // Convert to UTF-8 if needed
    if ($encoding && $encoding !== 'UTF-8') {
        $csvContent = mb_convert_encoding($csvContent, 'UTF-8', $encoding);
        printInfo("Converted to UTF-8");
    }
    
    // Split into lines
    $lines = explode("\n", $csvContent);
    $totalLines = count($lines);
    $analysis['structure']['total_lines'] = $totalLines;
    printInfo("Total lines: $totalLines");
    
    if ($totalLines < 2) {
        printWarning("CSV has less than 2 lines");
        return $analysis;
    }
    
    // Detect delimiter
    $firstLine = trim($lines[0]);
    $delimiters = [';', ',', '\t', '|'];
    $delimiter = ';'; // Default for Ozon
    
    $maxCount = 0;
    foreach ($delimiters as $del) {
        $count = substr_count($firstLine, $del);
        if ($count > $maxCount) {
            $maxCount = $count;
            $delimiter = $del;
        }
    }
    
    $analysis['structure']['delimiter'] = $delimiter;
    printInfo("Detected delimiter: '$delimiter'");
    
    // Parse header
    $header = str_getcsv($firstLine, $delimiter);
    $columnCount = count($header);
    $analysis['structure']['column_count'] = $columnCount;
    $analysis['columns'] = array_map('trim', $header);
    
    printInfo("Columns ($columnCount):");
    foreach ($analysis['columns'] as $index => $column) {
        echo "  " . ($index + 1) . ". $column\n";
    }
    
    // Analyze sample data
    $sampleSize = min(10, $totalLines - 1);
    for ($i = 1; $i <= $sampleSize; $i++) {
        if (isset($lines[$i]) && !empty(trim($lines[$i]))) {
            $data = str_getcsv(trim($lines[$i]), $delimiter);
            if (count($data) === $columnCount) {
                $row = [];
                foreach ($header as $index => $column) {
                    $row[trim($column)] = isset($data[$index]) ? trim($data[$index]) : '';
                }
                $analysis['sample_data'][] = $row;
            }
        }
    }
    
    printInfo("Sample data (first " . count($analysis['sample_data']) . " rows):");
    foreach ($analysis['sample_data'] as $index => $row) {
        echo "  Row " . ($index + 1) . ":\n";
        foreach ($row as $column => $value) {
            echo "    $column: $value\n";
        }
        echo "\n";
    }
    
    // Analyze warehouse-related columns
    $warehouseColumns = [];
    $stockColumns = [];
    $skuColumns = [];
    
    foreach ($analysis['columns'] as $index => $column) {
        $columnLower = mb_strtolower($column);
        
        if (strpos($columnLower, 'склад') !== false || 
            strpos($columnLower, 'warehouse') !== false ||
            strpos($columnLower, 'рфц') !== false) {
            $warehouseColumns[] = ['index' => $index, 'name' => $column];
        }
        
        if (strpos($columnLower, 'остат') !== false || 
            strpos($columnLower, 'stock') !== false ||
            strpos($columnLower, 'доступ') !== false ||
            strpos($columnLower, 'количест') !== false) {
            $stockColumns[] = ['index' => $index, 'name' => $column];
        }
        
        if (strpos($columnLower, 'sku') !== false || 
            strpos($columnLower, 'артикул') !== false ||
            strpos($columnLower, 'код') !== false) {
            $skuColumns[] = ['index' => $index, 'name' => $column];
        }
    }
    
    $analysis['warehouse_analysis'] = [
        'warehouse_columns' => $warehouseColumns,
        'stock_columns' => $stockColumns,
        'sku_columns' => $skuColumns
    ];
    
    printSuccess("Column analysis complete:");
    
    if (!empty($warehouseColumns)) {
        printInfo("Warehouse columns:");
        foreach ($warehouseColumns as $col) {
            echo "  - " . $col['name'] . " (column " . ($col['index'] + 1) . ")\n";
        }
    }
    
    if (!empty($stockColumns)) {
        printInfo("Stock columns:");
        foreach ($stockColumns as $col) {
            echo "  - " . $col['name'] . " (column " . ($col['index'] + 1) . ")\n";
        }
    }
    
    if (!empty($skuColumns)) {
        printInfo("SKU columns:");
        foreach ($skuColumns as $col) {
            echo "  - " . $col['name'] . " (column " . ($col['index'] + 1) . ")\n";
        }
    }
    
    // Analyze unique warehouses in data
    if (!empty($warehouseColumns) && !empty($analysis['sample_data'])) {
        $warehouseCol = $warehouseColumns[0]['name'];
        $uniqueWarehouses = [];
        
        foreach ($analysis['sample_data'] as $row) {
            $warehouse = $row[$warehouseCol] ?? '';
            if (!empty($warehouse)) {
                $uniqueWarehouses[$warehouse] = ($uniqueWarehouses[$warehouse] ?? 0) + 1;
            }
        }
        
        if (!empty($uniqueWarehouses)) {
            printInfo("Warehouses found in sample data:");
            foreach ($uniqueWarehouses as $warehouse => $count) {
                echo "  - $warehouse ($count records)\n";
            }
        }
    }
    
    return $analysis;
}

/**
 * Compare CSV structure with Analytics API data
 */
function compareWithAnalyticsAPI($csvAnalysis, $analyticsData) {
    printInfo("Step 5: Comparing CSV structure with Analytics API data...");
    
    if (!$csvAnalysis || !$analyticsData) {
        printWarning("Cannot compare - missing data");
        return;
    }
    
    echo "\n";
    printColored("=== COMPARISON RESULTS ===\n", 'cyan');
    
    // Compare available fields
    $csvColumns = $csvAnalysis['columns'] ?? [];
    $analyticsFields = [];
    
    if (!empty($analyticsData['sample_data'])) {
        $analyticsFields = array_keys($analyticsData['sample_data'][0]);
    }
    
    echo "CSV Columns (" . count($csvColumns) . "):\n";
    foreach ($csvColumns as $col) {
        echo "  - $col\n";
    }
    
    echo "\nAnalytics API Fields (" . count($analyticsFields) . "):\n";
    foreach ($analyticsFields as $field) {
        echo "  - $field\n";
    }
    
    // Find common concepts
    echo "\nCommon Concepts:\n";
    $commonConcepts = [
        'SKU/Product ID' => ['csv' => [], 'api' => ['sku']],
        'Warehouse' => ['csv' => [], 'api' => ['warehouse_name']],
        'Available Stock' => ['csv' => [], 'api' => ['free_to_sell_amount']],
        'Reserved Stock' => ['csv' => [], 'api' => ['reserved_amount']],
        'Product Name' => ['csv' => [], 'api' => ['item_name']],
        'Product Code' => ['csv' => [], 'api' => ['item_code']]
    ];
    
    // Map CSV columns to concepts
    foreach ($csvColumns as $col) {
        $colLower = mb_strtolower($col);
        
        if (strpos($colLower, 'sku') !== false || strpos($colLower, 'артикул') !== false) {
            $commonConcepts['SKU/Product ID']['csv'][] = $col;
        }
        if (strpos($colLower, 'склад') !== false || strpos($colLower, 'warehouse') !== false) {
            $commonConcepts['Warehouse']['csv'][] = $col;
        }
        if (strpos($colLower, 'доступ') !== false || strpos($colLower, 'available') !== false) {
            $commonConcepts['Available Stock']['csv'][] = $col;
        }
        if (strpos($colLower, 'резерв') !== false || strpos($colLower, 'reserved') !== false) {
            $commonConcepts['Reserved Stock']['csv'][] = $col;
        }
        if (strpos($colLower, 'наименование') !== false || strpos($colLower, 'название') !== false) {
            $commonConcepts['Product Name']['csv'][] = $col;
        }
    }
    
    foreach ($commonConcepts as $concept => $mapping) {
        echo "  $concept:\n";
        echo "    CSV: " . (empty($mapping['csv']) ? 'Not found' : implode(', ', $mapping['csv'])) . "\n";
        echo "    API: " . implode(', ', $mapping['api']) . "\n";
    }
}

/**
 * Generate mapping recommendations
 */
function generateMappingRecommendations($csvAnalysis, $analyticsData) {
    printInfo("Step 6: Generating mapping recommendations...");
    
    $recommendations = [
        'primary_source' => 'analytics_api',
        'csv_fallback' => false,
        'field_mapping' => [],
        'data_quality' => [],
        'implementation_notes' => []
    ];
    
    if ($csvAnalysis) {
        $recommendations['csv_fallback'] = true;
        $recommendations['implementation_notes'][] = 'CSV reports can be used as fallback source';
        $recommendations['implementation_notes'][] = 'CSV provides additional fields not available in Analytics API';
    } else {
        $recommendations['implementation_notes'][] = 'CSV reports not accessible - use Analytics API only';
    }
    
    $recommendations['field_mapping'] = [
        'sku' => ['analytics' => 'sku', 'csv' => 'auto_detect'],
        'warehouse_name' => ['analytics' => 'warehouse_name', 'csv' => 'auto_detect'],
        'available' => ['analytics' => 'free_to_sell_amount', 'csv' => 'auto_detect'],
        'reserved' => ['analytics' => 'reserved_amount', 'csv' => 'auto_detect'],
        'promised' => ['analytics' => 'promised_amount', 'csv' => 'auto_detect'],
        'product_name' => ['analytics' => 'item_name', 'csv' => 'auto_detect'],
        'product_code' => ['analytics' => 'item_code', 'csv' => 'auto_detect']
    ];
    
    echo "\n";
    printColored("=== RECOMMENDATIONS ===\n", 'cyan');
    echo "Primary Source: " . $recommendations['primary_source'] . "\n";
    echo "CSV Fallback Available: " . ($recommendations['csv_fallback'] ? 'Yes' : 'No') . "\n";
    
    echo "\nField Mapping:\n";
    foreach ($recommendations['field_mapping'] as $field => $mapping) {
        echo "  $field:\n";
        echo "    Analytics API: " . $mapping['analytics'] . "\n";
        echo "    CSV: " . $mapping['csv'] . "\n";
    }
    
    echo "\nImplementation Notes:\n";
    foreach ($recommendations['implementation_notes'] as $note) {
        echo "  - $note\n";
    }
    
    return $recommendations;
}

/**
 * Main execution
 */
function main() {
    printColored(str_repeat("=", 70) . "\n", 'cyan');
    printColored("Ozon CSV Structure Analysis\n", 'cyan');
    printColored(str_repeat("=", 70) . "\n", 'cyan');
    echo "\n";
    
    try {
        // Step 1: Get warehouse data from Analytics API
        $analyticsData = getWarehouseDataFromAnalytics();
        
        if (!$analyticsData) {
            printError("Failed to get Analytics API data. Cannot proceed.");
            return 1;
        }
        
        echo "\n";
        
        // Step 2: Try to get warehouse IDs
        $warehouseIds = tryGetWarehouseIds();
        
        echo "\n";
        
        // Step 3: Try to get CSV data
        $csvAnalysis = tryAlternativeCSVSources($analyticsData);
        
        echo "\n";
        
        // Step 4: Compare and analyze
        compareWithAnalyticsAPI($csvAnalysis, $analyticsData);
        
        echo "\n";
        
        // Step 5: Generate recommendations
        $recommendations = generateMappingRecommendations($csvAnalysis, $analyticsData);
        
        // Save analysis results
        $results = [
            'timestamp' => date('c'),
            'analytics_data' => $analyticsData,
            'csv_analysis' => $csvAnalysis,
            'recommendations' => $recommendations
        ];
        
        $resultsFile = __DIR__ . '/../docs/csv_structure_analysis_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($resultsFile, json_encode($results, JSON_PRETTY_PRINT));
        printSuccess("Analysis results saved to: $resultsFile");
        
        echo "\n";
        printColored(str_repeat("=", 70) . "\n", 'cyan');
        printSuccess("CSV structure analysis completed!");
        printColored(str_repeat("=", 70) . "\n", 'cyan');
        
        return 0;
        
    } catch (Exception $e) {
        printError("Fatal error: " . $e->getMessage());
        return 1;
    }
}

// Run the script
$exitCode = main();
exit($exitCode);