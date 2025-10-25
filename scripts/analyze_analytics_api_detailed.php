#!/usr/bin/env php
<?php
/**
 * Detailed Analysis of Ozon Analytics API
 * 
 * This script performs comprehensive analysis of Analytics API data structure
 * to understand all available fields and create mapping for ETL implementation
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
function makeOzonRequest($url, $data = null) {
    global $headers;
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_USERAGENT => 'Warehouse-ETL-Analytics-Analyzer/1.0'
    ]);
    
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
 * Get comprehensive Analytics API data
 */
function getComprehensiveAnalyticsData() {
    global $OZON_API_URL;
    
    printInfo("Fetching comprehensive Analytics API data...");
    
    $url = $OZON_API_URL . '/v2/analytics/stock_on_warehouses';
    
    $allData = [];
    $offset = 0;
    $limit = 1000;
    $totalFetched = 0;
    
    do {
        $payload = [
            'limit' => $limit,
            'offset' => $offset,
            'warehouse_type' => 'ALL'
        ];
        
        printInfo("Fetching batch: offset=$offset, limit=$limit");
        
        try {
            $response = makeOzonRequest($url, $payload);
            
            if ($response['http_code'] === 200 && isset($response['data']['result']['rows'])) {
                $rows = $response['data']['result']['rows'];
                $batchSize = count($rows);
                
                printSuccess("Fetched $batchSize records");
                
                $allData = array_merge($allData, $rows);
                $totalFetched += $batchSize;
                $offset += $limit;
                
                // If we got less than limit, we've reached the end
                if ($batchSize < $limit) {
                    break;
                }
                
                // Safety limit to avoid infinite loops
                if ($totalFetched >= 10000) {
                    printWarning("Reached safety limit of 10,000 records");
                    break;
                }
                
            } else {
                printError("Failed to fetch batch at offset $offset");
                printError("HTTP Code: " . $response['http_code']);
                printError("Response: " . substr($response['body'], 0, 300));
                break;
            }
        } catch (Exception $e) {
            printError("Exception during batch fetch: " . $e->getMessage());
            break;
        }
        
        // Small delay to be respectful to API
        usleep(100000); // 0.1 second
        
    } while (true);
    
    printSuccess("Total records fetched: $totalFetched");
    
    return $allData;
}

/**
 * Analyze field structure and data types
 */
function analyzeFieldStructure($data) {
    printInfo("Analyzing field structure...");
    
    if (empty($data)) {
        printWarning("No data to analyze");
        return [];
    }
    
    $fieldAnalysis = [];
    $sampleSize = min(1000, count($data));
    
    // Analyze first record to get field names
    $firstRecord = $data[0];
    foreach ($firstRecord as $field => $value) {
        $fieldAnalysis[$field] = [
            'type' => gettype($value),
            'sample_values' => [],
            'null_count' => 0,
            'unique_values' => [],
            'min_length' => null,
            'max_length' => null,
            'numeric_stats' => null
        ];
    }
    
    // Analyze sample of records
    for ($i = 0; $i < $sampleSize; $i++) {
        $record = $data[$i];
        
        foreach ($fieldAnalysis as $field => &$analysis) {
            $value = $record[$field] ?? null;
            
            if ($value === null || $value === '') {
                $analysis['null_count']++;
                continue;
            }
            
            // Collect sample values
            if (count($analysis['sample_values']) < 10) {
                $analysis['sample_values'][] = $value;
            }
            
            // Track unique values for small sets
            if (count($analysis['unique_values']) < 100) {
                $analysis['unique_values'][$value] = ($analysis['unique_values'][$value] ?? 0) + 1;
            }
            
            // String length analysis
            if (is_string($value)) {
                $length = mb_strlen($value);
                $analysis['min_length'] = $analysis['min_length'] === null ? $length : min($analysis['min_length'], $length);
                $analysis['max_length'] = $analysis['max_length'] === null ? $length : max($analysis['max_length'], $length);
            }
            
            // Numeric analysis
            if (is_numeric($value)) {
                if ($analysis['numeric_stats'] === null) {
                    $analysis['numeric_stats'] = ['min' => $value, 'max' => $value, 'sum' => 0, 'count' => 0];
                }
                $analysis['numeric_stats']['min'] = min($analysis['numeric_stats']['min'], $value);
                $analysis['numeric_stats']['max'] = max($analysis['numeric_stats']['max'], $value);
                $analysis['numeric_stats']['sum'] += $value;
                $analysis['numeric_stats']['count']++;
            }
        }
    }
    
    // Calculate averages
    foreach ($fieldAnalysis as $field => &$analysis) {
        if ($analysis['numeric_stats'] && $analysis['numeric_stats']['count'] > 0) {
            $analysis['numeric_stats']['avg'] = $analysis['numeric_stats']['sum'] / $analysis['numeric_stats']['count'];
        }
        
        $analysis['unique_count'] = count($analysis['unique_values']);
        $analysis['null_percentage'] = ($analysis['null_count'] / $sampleSize) * 100;
    }
    
    return $fieldAnalysis;
}

/**
 * Analyze warehouse distribution
 */
function analyzeWarehouseDistribution($data) {
    printInfo("Analyzing warehouse distribution...");
    
    $warehouseStats = [];
    
    foreach ($data as $record) {
        $warehouse = $record['warehouse_name'] ?? 'UNKNOWN';
        
        if (!isset($warehouseStats[$warehouse])) {
            $warehouseStats[$warehouse] = [
                'name' => $warehouse,
                'total_records' => 0,
                'unique_skus' => [],
                'total_stock' => 0,
                'total_reserved' => 0,
                'total_promised' => 0,
                'sample_records' => []
            ];
        }
        
        $stats = &$warehouseStats[$warehouse];
        $stats['total_records']++;
        
        // Track unique SKUs
        $sku = $record['sku'] ?? null;
        if ($sku) {
            $stats['unique_skus'][$sku] = true;
        }
        
        // Sum stock amounts
        $stats['total_stock'] += intval($record['free_to_sell_amount'] ?? 0);
        $stats['total_reserved'] += intval($record['reserved_amount'] ?? 0);
        $stats['total_promised'] += intval($record['promised_amount'] ?? 0);
        
        // Keep sample records
        if (count($stats['sample_records']) < 3) {
            $stats['sample_records'][] = $record;
        }
    }
    
    // Convert unique SKUs to count
    foreach ($warehouseStats as &$stats) {
        $stats['unique_sku_count'] = count($stats['unique_skus']);
        unset($stats['unique_skus']); // Remove to save memory
    }
    
    // Sort by total records
    uasort($warehouseStats, function($a, $b) {
        return $b['total_records'] - $a['total_records'];
    });
    
    return $warehouseStats;
}

/**
 * Generate ETL mapping recommendations
 */
function generateETLMapping($fieldAnalysis, $warehouseStats) {
    printInfo("Generating ETL mapping recommendations...");
    
    $mapping = [
        'source_api' => 'analytics_api',
        'endpoint' => '/v2/analytics/stock_on_warehouses',
        'pagination' => [
            'method' => 'offset_limit',
            'max_limit' => 1000,
            'recommended_batch_size' => 1000
        ],
        'field_mapping' => [],
        'data_quality' => [],
        'warehouse_info' => [
            'total_warehouses' => count($warehouseStats),
            'warehouse_names' => array_keys($warehouseStats)
        ],
        'recommendations' => []
    ];
    
    // Map fields to inventory table structure
    $inventoryFieldMapping = [
        'sku' => [
            'source_field' => 'sku',
            'target_field' => 'sku',
            'data_type' => 'integer',
            'required' => true,
            'description' => 'Product SKU identifier'
        ],
        'warehouse_name' => [
            'source_field' => 'warehouse_name',
            'target_field' => 'warehouse_name',
            'data_type' => 'varchar(255)',
            'required' => true,
            'description' => 'Warehouse name (needs normalization)',
            'normalization_needed' => true
        ],
        'available' => [
            'source_field' => 'free_to_sell_amount',
            'target_field' => 'available',
            'data_type' => 'integer',
            'required' => true,
            'description' => 'Available stock for sale'
        ],
        'reserved' => [
            'source_field' => 'reserved_amount',
            'target_field' => 'reserved',
            'data_type' => 'integer',
            'required' => true,
            'description' => 'Reserved stock'
        ],
        'promised' => [
            'source_field' => 'promised_amount',
            'target_field' => 'promised_amount',
            'data_type' => 'integer',
            'required' => false,
            'description' => 'Promised stock amount'
        ],
        'product_name' => [
            'source_field' => 'item_name',
            'target_field' => 'product_name',
            'data_type' => 'text',
            'required' => false,
            'description' => 'Product name'
        ],
        'product_code' => [
            'source_field' => 'item_code',
            'target_field' => 'product_code',
            'data_type' => 'varchar(100)',
            'required' => false,
            'description' => 'Product code/article'
        ]
    ];
    
    $mapping['field_mapping'] = $inventoryFieldMapping;
    
    // Data quality assessment
    foreach ($fieldAnalysis as $field => $analysis) {
        $quality = [
            'field' => $field,
            'completeness' => 100 - $analysis['null_percentage'],
            'data_type' => $analysis['type'],
            'unique_values' => $analysis['unique_count']
        ];
        
        if ($analysis['null_percentage'] > 10) {
            $quality['issues'][] = "High null percentage: {$analysis['null_percentage']}%";
        }
        
        if ($field === 'warehouse_name' && $analysis['unique_count'] > 50) {
            $quality['issues'][] = "Many unique warehouse names - normalization needed";
        }
        
        $mapping['data_quality'][$field] = $quality;
    }
    
    // Generate recommendations
    $mapping['recommendations'] = [
        'Use Analytics API as primary source - no CSV parsing needed',
        'Implement pagination with 1000 records per batch',
        'Add warehouse name normalization (РФЦ, МРФЦ variations)',
        'Schedule sync every 2-4 hours for fresh data',
        'Monitor API rate limits and implement retry logic',
        'Use batch processing for database updates',
        'Add data validation for negative stock values',
        'Implement incremental updates based on changed records'
    ];
    
    return $mapping;
}

/**
 * Save analysis results
 */
function saveAnalysisResults($data, $fieldAnalysis, $warehouseStats, $etlMapping) {
    $results = [
        'analysis_timestamp' => date('c'),
        'total_records_analyzed' => count($data),
        'field_analysis' => $fieldAnalysis,
        'warehouse_statistics' => $warehouseStats,
        'etl_mapping' => $etlMapping,
        'sample_records' => array_slice($data, 0, 5)
    ];
    
    $filename = 'analytics_api_detailed_analysis_' . date('Y-m-d_H-i-s') . '.json';
    $filepath = __DIR__ . '/../docs/' . $filename;
    
    file_put_contents($filepath, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    return $filepath;
}

/**
 * Print analysis summary
 */
function printAnalysisSummary($fieldAnalysis, $warehouseStats, $etlMapping) {
    echo "\n";
    printColored("=== ANALYTICS API DETAILED ANALYSIS SUMMARY ===\n", 'cyan');
    
    // Field Analysis Summary
    echo "Field Analysis:\n";
    foreach ($fieldAnalysis as $field => $analysis) {
        $completeness = 100 - $analysis['null_percentage'];
        $color = $completeness >= 95 ? 'green' : ($completeness >= 80 ? 'yellow' : 'red');
        
        printColored("  $field: ", 'white');
        printColored(sprintf("%.1f%% complete", $completeness), $color);
        echo " | Type: {$analysis['type']} | Unique: {$analysis['unique_count']}\n";
        
        if (!empty($analysis['sample_values'])) {
            echo "    Samples: " . implode(', ', array_slice($analysis['sample_values'], 0, 3)) . "\n";
        }
    }
    
    echo "\n";
    
    // Warehouse Statistics
    echo "Warehouse Statistics:\n";
    echo "  Total warehouses: " . count($warehouseStats) . "\n";
    echo "  Top 10 warehouses by record count:\n";
    
    $counter = 0;
    foreach ($warehouseStats as $warehouse => $stats) {
        if ($counter >= 10) break;
        
        echo sprintf("    %2d. %-30s | Records: %4d | SKUs: %3d | Stock: %6d\n",
            $counter + 1,
            substr($warehouse, 0, 30),
            $stats['total_records'],
            $stats['unique_sku_count'],
            $stats['total_stock']
        );
        
        $counter++;
    }
    
    echo "\n";
    
    // ETL Recommendations
    echo "ETL Implementation Recommendations:\n";
    foreach ($etlMapping['recommendations'] as $index => $recommendation) {
        echo "  " . ($index + 1) . ". $recommendation\n";
    }
    
    echo "\n";
    printColored("=== END SUMMARY ===\n", 'cyan');
}

/**
 * Main execution
 */
function main() {
    printColored(str_repeat("=", 70) . "\n", 'cyan');
    printColored("Ozon Analytics API Detailed Analysis\n", 'cyan');
    printColored(str_repeat("=", 70) . "\n", 'cyan');
    echo "\n";
    
    try {
        // Step 1: Fetch comprehensive data
        $data = getComprehensiveAnalyticsData();
        
        if (empty($data)) {
            printError("No data retrieved from Analytics API");
            return 1;
        }
        
        echo "\n";
        
        // Step 2: Analyze field structure
        $fieldAnalysis = analyzeFieldStructure($data);
        
        echo "\n";
        
        // Step 3: Analyze warehouse distribution
        $warehouseStats = analyzeWarehouseDistribution($data);
        
        echo "\n";
        
        // Step 4: Generate ETL mapping
        $etlMapping = generateETLMapping($fieldAnalysis, $warehouseStats);
        
        echo "\n";
        
        // Step 5: Save results
        $resultsFile = saveAnalysisResults($data, $fieldAnalysis, $warehouseStats, $etlMapping);
        printSuccess("Analysis results saved to: $resultsFile");
        
        echo "\n";
        
        // Step 6: Print summary
        printAnalysisSummary($fieldAnalysis, $warehouseStats, $etlMapping);
        
        echo "\n";
        printColored(str_repeat("=", 70) . "\n", 'cyan');
        printSuccess("Detailed Analytics API analysis completed!");
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