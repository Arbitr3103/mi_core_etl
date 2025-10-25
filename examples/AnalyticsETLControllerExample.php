<?php
/**
 * AnalyticsETLController Usage Examples
 * 
 * Demonstrates how to use the AnalyticsETLController API endpoints
 * for managing Analytics ETL processes through HTTP requests.
 * 
 * Requirements: 8.1, 8.2, 8.3, 8.4
 * Task: 5.1 Создать AnalyticsETLController
 */

require_once __DIR__ . '/../src/api/controllers/AnalyticsETLController.php';

echo "=== AnalyticsETLController API Examples ===\n\n";

// Example 1: Initialize Controller
echo "1. Controller Initialization\n";
echo "============================\n";

try {
    $config = [
        'log_file' => __DIR__ . '/../logs/analytics_etl_controller_example.log',
        'analytics_api' => [
            'base_url' => 'https://api.analytics.example.com',
            'api_key' => 'your_api_key_here',
            'timeout' => 30,
            'rate_limit' => 100
        ],
        'data_validator' => [
            'enable_anomaly_detection' => true,
            'quality_thresholds' => [
                'completeness' => 0.95,
                'accuracy' => 0.90,
                'consistency' => 0.85
            ]
        ],
        'warehouse_normalizer' => [
            'fuzzy_threshold' => 0.8,
            'enable_learning' => true
        ],
        'analytics_etl' => [
            'load_batch_size' => 1000,
            'min_quality_score' => 80.0,
            'enable_audit_logging' => true
        ]
    ];
    
    $controller = new AnalyticsETLController($config);
    echo "✓ AnalyticsETLController initialized successfully\n";
    
} catch (Exception $e) {
    echo "✗ Error initializing controller: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 2: GET /api/warehouse/analytics-status
echo "2. Get Analytics Status\n";
echo "======================\n";

try {
    echo "Making GET request to /api/warehouse/analytics-status...\n";
    
    $response = $controller->handleRequest('GET', '/api/warehouse/analytics-status');
    
    echo "Response:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    if ($response['status'] === 'success') {
        $data = $response['data'];
        echo "\nStatus Summary:\n";
        echo "- ETL Status: " . ($data['etl_status']['status'] ?? 'unknown') . "\n";
        echo "- Current Batch: " . ($data['etl_status']['current_batch_id'] ?? 'none') . "\n";
        echo "- Database: " . ($data['system_info']['database_status'] ?? 'unknown') . "\n";
        echo "- ETL Service: " . ($data['system_info']['etl_service_status'] ?? 'unknown') . "\n";
        
        if (isset($data['recent_activity']['total_runs'])) {
            echo "- Recent Runs (24h): " . $data['recent_activity']['total_runs'] . "\n";
            echo "- Success Rate: " . 
                round(($data['recent_activity']['successful_runs'] / max(1, $data['recent_activity']['total_runs'])) * 100, 1) . "%\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 3: POST /api/warehouse/trigger-analytics-etl
echo "3. Trigger Analytics ETL\n";
echo "=======================\n";

try {
    echo "Making POST request to trigger ETL...\n";
    
    $etlParams = [
        'etl_type' => AnalyticsETL::TYPE_MANUAL_SYNC,
        'options' => [
            'filters' => [
                'warehouse_names' => ['Москва РФЦ', 'СПб МРФЦ'],
                'categories' => ['electronics']
            ],
            'batch_size' => 500
        ]
    ];
    
    echo "ETL Parameters:\n";
    echo json_encode($etlParams, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    $response = $controller->handleRequest('POST', '/api/warehouse/trigger-analytics-etl', $etlParams);
    
    echo "\nResponse:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    if ($response['status'] === 'success' && isset($response['data']['etl_result'])) {
        $result = $response['data']['etl_result'];
        echo "\nETL Execution Summary:\n";
        echo "- Batch ID: {$result['batch_id']}\n";
        echo "- Status: {$result['status']}\n";
        echo "- Records Processed: {$result['records_processed']}\n";
        echo "- Records Inserted: {$result['records_inserted']}\n";
        echo "- Records Updated: {$result['records_updated']}\n";
        echo "- Execution Time: {$result['execution_time_ms']}ms\n";
        echo "- Quality Score: {$result['quality_score']}%\n";
        echo "- Success: " . ($result['is_successful'] ? 'Yes' : 'No') . "\n";
        
        if ($result['error_message']) {
            echo "- Error: {$result['error_message']}\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 4: GET /api/warehouse/data-quality
echo "4. Get Data Quality Metrics\n";
echo "==========================\n";

try {
    echo "Making GET request for data quality metrics...\n";
    
    $qualityParams = [
        'timeframe' => '7d',
        'source' => 'analytics_api'
    ];
    
    echo "Parameters: " . json_encode($qualityParams) . "\n";
    
    $response = $controller->handleRequest('GET', '/api/warehouse/data-quality', $qualityParams);
    
    echo "\nResponse:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    if ($response['status'] === 'success' && isset($response['data'])) {
        $data = $response['data'];
        
        echo "\nData Quality Summary:\n";
        
        if (isset($data['quality_metrics'])) {
            $qm = $data['quality_metrics'];
            echo "- Average Quality Score: " . round($qm['avg_quality_score'] ?? 0, 2) . "%\n";
            echo "- High Quality Records: " . ($qm['high_quality_records'] ?? 0) . "\n";
            echo "- Low Quality Records: " . ($qm['low_quality_records'] ?? 0) . "\n";
        }
        
        if (isset($data['freshness_metrics'])) {
            $fm = $data['freshness_metrics'];
            echo "- Average Hours Since Sync: " . round($fm['avg_hours_since_sync'] ?? 0, 1) . "h\n";
            echo "- Fresh Records: " . ($fm['fresh_records'] ?? 0) . "\n";
            echo "- Stale Records: " . ($fm['stale_records'] ?? 0) . "\n";
        }
        
        if (isset($data['completeness_metrics']['completeness_percentages'])) {
            $cp = $data['completeness_metrics']['completeness_percentages'];
            echo "- SKU Completeness: {$cp['sku']}%\n";
            echo "- Warehouse Name Completeness: {$cp['warehouse_name']}%\n";
            echo "- Product Name Completeness: {$cp['product_name']}%\n";
            echo "- Price Completeness: {$cp['price']}%\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 5: GET /api/warehouse/etl-history
echo "5. Get ETL History\n";
echo "=================\n";

try {
    echo "Making GET request for ETL history...\n";
    
    $historyParams = [
        'limit' => 10,
        'offset' => 0,
        'days' => 30,
        'status' => 'completed'
    ];
    
    echo "Parameters: " . json_encode($historyParams) . "\n";
    
    $response = $controller->handleRequest('GET', '/api/warehouse/etl-history', $historyParams);
    
    echo "\nResponse:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    if ($response['status'] === 'success' && isset($response['data'])) {
        $data = $response['data'];
        
        echo "\nETL History Summary:\n";
        
        if (isset($data['pagination'])) {
            $p = $data['pagination'];
            echo "- Total Records: {$p['total']}\n";
            echo "- Showing: " . ($p['offset'] + 1) . "-" . min($p['offset'] + $p['limit'], $p['total']) . "\n";
            echo "- Has More: " . ($p['has_more'] ? 'Yes' : 'No') . "\n";
        }
        
        if (isset($data['summary_stats'])) {
            $ss = $data['summary_stats'];
            echo "- Total Runs: " . ($ss['total_runs'] ?? 0) . "\n";
            echo "- Success Rate: " . ($ss['success_rate'] ?? 0) . "%\n";
            echo "- Average Execution Time: " . round(($ss['avg_execution_time_ms'] ?? 0) / 1000, 2) . "s\n";
            echo "- Total Records Processed: " . ($ss['total_records_processed'] ?? 0) . "\n";
        }
        
        if (isset($data['history']) && !empty($data['history'])) {
            echo "\nRecent ETL Runs:\n";
            foreach (array_slice($data['history'], 0, 5) as $run) {
                echo "- {$run['started_at']}: {$run['status']} ({$run['records_processed']} records, " . 
                     round($run['execution_time_ms'] / 1000, 2) . "s)\n";
            }
        }
    }
    
} catch (Exception $e) {
    echo "✗ Error: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 6: Different ETL Types
echo "6. Different ETL Types\n";
echo "=====================\n";

$etlTypes = [
    AnalyticsETL::TYPE_FULL_SYNC => 'Full synchronization of all data',
    AnalyticsETL::TYPE_INCREMENTAL_SYNC => 'Incremental sync (changes only)',
    AnalyticsETL::TYPE_MANUAL_SYNC => 'Manual sync with custom parameters',
    AnalyticsETL::TYPE_VALIDATION_ONLY => 'Validation only (no data load)'
];

foreach ($etlTypes as $type => $description) {
    echo "\nETL Type: {$type}\n";
    echo "Description: {$description}\n";
    
    $params = [
        'etl_type' => $type,
        'options' => []
    ];
    
    try {
        // For demo purposes, we'll just show the request structure
        echo "Request: POST /api/warehouse/trigger-analytics-etl\n";
        echo "Body: " . json_encode($params) . "\n";
        
        // In a real scenario, you would make the actual request:
        // $response = $controller->handleRequest('POST', '/api/warehouse/trigger-analytics-etl', $params);
        
    } catch (Exception $e) {
        echo "Error: {$e->getMessage()}\n";
    }
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 7: Error Handling
echo "7. Error Handling Examples\n";
echo "=========================\n";

$errorScenarios = [
    [
        'description' => 'Invalid endpoint',
        'method' => 'GET',
        'endpoint' => '/api/warehouse/invalid-endpoint',
        'params' => []
    ],
    [
        'description' => 'Invalid HTTP method',
        'method' => 'DELETE',
        'endpoint' => '/api/warehouse/analytics-status',
        'params' => []
    ],
    [
        'description' => 'Invalid ETL type',
        'method' => 'POST',
        'endpoint' => '/api/warehouse/trigger-analytics-etl',
        'params' => ['etl_type' => 'invalid_type']
    ],
    [
        'description' => 'Invalid parameters',
        'method' => 'GET',
        'endpoint' => '/api/warehouse/etl-history',
        'params' => ['limit' => -10, 'offset' => -5]
    ]
];

foreach ($errorScenarios as $scenario) {
    echo "\nScenario: {$scenario['description']}\n";
    echo "Request: {$scenario['method']} {$scenario['endpoint']}\n";
    
    if (!empty($scenario['params'])) {
        echo "Params: " . json_encode($scenario['params']) . "\n";
    }
    
    try {
        $response = $controller->handleRequest(
            $scenario['method'],
            $scenario['endpoint'],
            $scenario['params']
        );
        
        echo "Response: {$response['status']}\n";
        if ($response['status'] === 'error') {
            echo "Error: {$response['message']} (Code: {$response['code']})\n";
        }
        
    } catch (Exception $e) {
        echo "Exception: {$e->getMessage()}\n";
    }
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 8: HTTP Client Usage (cURL examples)
echo "8. HTTP Client Usage Examples\n";
echo "=============================\n";

echo "Example cURL commands for API usage:\n\n";

echo "1. Get Analytics Status:\n";
echo "curl -X GET 'http://your-domain.com/api/warehouse/analytics-status'\n\n";

echo "2. Trigger ETL Process:\n";
echo "curl -X POST 'http://your-domain.com/api/warehouse/trigger-analytics-etl' \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -d '{\n";
echo "    \"etl_type\": \"manual_sync\",\n";
echo "    \"options\": {\n";
echo "      \"filters\": {\n";
echo "        \"warehouse_names\": [\"Москва РФЦ\"],\n";
echo "        \"categories\": [\"electronics\"]\n";
echo "      }\n";
echo "    }\n";
echo "  }'\n\n";

echo "3. Get Data Quality Metrics:\n";
echo "curl -X GET 'http://your-domain.com/api/warehouse/data-quality?timeframe=7d&source=analytics_api'\n\n";

echo "4. Get ETL History:\n";
echo "curl -X GET 'http://your-domain.com/api/warehouse/etl-history?limit=20&offset=0&days=30&status=completed'\n\n";

echo "5. JavaScript Fetch Example:\n";
echo "```javascript\n";
echo "// Trigger ETL\n";
echo "fetch('/api/warehouse/trigger-analytics-etl', {\n";
echo "  method: 'POST',\n";
echo "  headers: {\n";
echo "    'Content-Type': 'application/json'\n";
echo "  },\n";
echo "  body: JSON.stringify({\n";
echo "    etl_type: 'incremental_sync',\n";
echo "    options: {}\n";
echo "  })\n";
echo "})\n";
echo ".then(response => response.json())\n";
echo ".then(data => {\n";
echo "  if (data.status === 'success') {\n";
echo "    console.log('ETL triggered:', data.data.etl_result);\n";
echo "  } else {\n";
echo "    console.error('ETL failed:', data.message);\n";
echo "  }\n";
echo "});\n";
echo "```\n\n";

echo str_repeat("=", 50) . "\n";
echo "AnalyticsETLController Examples Complete!\n";
echo str_repeat("=", 50) . "\n";

/**
 * Helper function to format response for display
 */
function formatResponse(array $response): string {
    return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Helper function to simulate HTTP request
 */
function simulateHttpRequest(string $method, string $url, array $data = []): array {
    return [
        'method' => $method,
        'url' => $url,
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Helper function to format execution time
 */
function formatExecutionTime(int $milliseconds): string {
    if ($milliseconds < 1000) {
        return "{$milliseconds}ms";
    } elseif ($milliseconds < 60000) {
        return round($milliseconds / 1000, 2) . "s";
    } else {
        $minutes = floor($milliseconds / 60000);
        $seconds = round(($milliseconds % 60000) / 1000, 2);
        return "{$minutes}m {$seconds}s";
    }
}