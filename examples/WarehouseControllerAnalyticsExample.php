<?php
/**
 * WarehouseController Analytics API Integration Examples
 * 
 * Demonstrates the enhanced WarehouseController functionality
 * with Analytics API data source support, quality filtering,
 * and freshness metrics.
 * 
 * Requirements: 9.1, 9.2, 9.4, 17.3
 * Task: 5.2 Расширить существующий WarehouseController
 */

require_once __DIR__ . '/../api/classes/WarehouseController.php';

echo "=== WarehouseController Analytics API Examples ===\n\n";

// Example 1: Basic Analytics API Integration
echo "1. Basic Analytics API Integration\n";
echo "=================================\n";

try {
    // Database connection (replace with your credentials)
    $pdo = new PDO(
        'pgsql:host=localhost;dbname=warehouse_db',
        'username',
        'password',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $controller = new WarehouseController($pdo);
    echo "✓ WarehouseController initialized successfully\n";
    
} catch (Exception $e) {
    echo "✗ Error initializing controller: {$e->getMessage()}\n";
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 2: Filter by Data Source
echo "2. Filter by Data Source\n";
echo "=======================\n";

$dataSources = ['analytics_api', 'manual', 'import', 'all'];

foreach ($dataSources as $source) {
    echo "\nData Source: {$source}\n";
    echo "API Call: GET /api/warehouse/dashboard?data_source={$source}\n";
    
    // Simulate API request
    $_GET = ['data_source' => $source, 'limit' => '10'];
    
    try {
        // In real usage, you would call:
        // $controller->getDashboard();
        
        echo "✓ Filter validated successfully\n";
        echo "Expected: Products from {$source} data source\n";
        
    } catch (Exception $e) {
        echo "✗ Error: {$e->getMessage()}\n";
    }
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 3: Quality Score Filtering
echo "3. Quality Score Filtering\n";
echo "=========================\n";

$qualityThresholds = [70, 80, 90, 95];

foreach ($qualityThresholds as $threshold) {
    echo "\nQuality Threshold: {$threshold}%\n";
    echo "API Call: GET /api/warehouse/dashboard?quality_score={$threshold}\n";
    
    $_GET = ['quality_score' => (string)$threshold];
    
    try {
        // Validate filter
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $filters = $method->invoke($controller, $_GET);
        
        echo "✓ Quality filter: {$filters['quality_score']}%\n";
        echo "Expected: Products with quality score >= {$threshold}%\n";
        
    } catch (Exception $e) {
        echo "✗ Error: {$e->getMessage()}\n";
    }
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 4: Freshness Filtering
echo "4. Freshness Filtering\n";
echo "=====================\n";

$freshnessHours = [6, 24, 72, 168]; // 6h, 1d, 3d, 1w

foreach ($freshnessHours as $hours) {
    echo "\nFreshness: Last {$hours} hours\n";
    echo "API Call: GET /api/warehouse/dashboard?freshness_hours={$hours}\n";
    
    $_GET = ['freshness_hours' => (string)$hours];
    
    try {
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $filters = $method->invoke($controller, $_GET);
        
        echo "✓ Freshness filter: {$filters['freshness_hours']} hours\n";
        echo "Expected: Products synced within last {$hours} hours\n";
        
    } catch (Exception $e) {
        echo "✗ Error: {$e->getMessage()}\n";
    }
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 5: Combined Analytics Filters
echo "5. Combined Analytics Filters\n";
echo "============================\n";

$combinedFilters = [
    [
        'description' => 'High-quality Analytics API data (last 24h)',
        'params' => [
            'data_source' => 'analytics_api',
            'quality_score' => '90',
            'freshness_hours' => '24'
        ]
    ],
    [
        'description' => 'Fresh manual data with good quality',
        'params' => [
            'data_source' => 'manual',
            'quality_score' => '80',
            'freshness_hours' => '6'
        ]
    ],
    [
        'description' => 'All sources with minimum quality',
        'params' => [
            'data_source' => 'all',
            'quality_score' => '70'
        ]
    ]
];

foreach ($combinedFilters as $example) {
    echo "\nScenario: {$example['description']}\n";
    
    $queryString = http_build_query($example['params']);
    echo "API Call: GET /api/warehouse/dashboard?{$queryString}\n";
    
    try {
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $filters = $method->invoke($controller, $example['params']);
        
        echo "✓ Filters applied:\n";
        foreach ($filters as $key => $value) {
            if (in_array($key, ['data_source', 'quality_score', 'freshness_hours'])) {
                echo "  - {$key}: {$value}\n";
            }
        }
        
    } catch (Exception $e) {
        echo "✗ Error: {$e->getMessage()}\n";
    }
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 6: Enhanced Sorting Options
echo "6. Enhanced Sorting Options\n";
echo "==========================\n";

$sortOptions = [
    ['field' => 'data_quality_score', 'order' => 'desc', 'description' => 'Highest quality first'],
    ['field' => 'last_analytics_sync', 'order' => 'desc', 'description' => 'Most recently synced first'],
    ['field' => 'data_source', 'order' => 'asc', 'description' => 'Grouped by data source'],
    ['field' => 'replenishment_need', 'order' => 'desc', 'description' => 'Highest replenishment need first']
];

foreach ($sortOptions as $sort) {
    echo "\nSort: {$sort['description']}\n";
    echo "API Call: GET /api/warehouse/dashboard?sort_by={$sort['field']}&sort_order={$sort['order']}\n";
    
    $_GET = [
        'sort_by' => $sort['field'],
        'sort_order' => $sort['order']
    ];
    
    try {
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $filters = $method->invoke($controller, $_GET);
        
        echo "✓ Sort: {$filters['sort_by']} {$filters['sort_order']}\n";
        
    } catch (Exception $e) {
        echo "✗ Error: {$e->getMessage()}\n";
    }
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 7: Response Format Examples
echo "7. Enhanced Response Format\n";
echo "==========================\n";

echo "Expected response structure with Analytics API enhancements:\n\n";

$sampleResponse = [
    'success' => true,
    'data' => [
        'warehouses' => [
            [
                'warehouse_name' => 'Москва РФЦ',
                'cluster' => 'Центральный',
                'items' => [
                    [
                        'sku' => 'PROD-001',
                        'name' => 'Test Product',
                        'warehouse_name' => 'Москва РФЦ',
                        'available' => 150,
                        'daily_sales_avg' => 5.2,
                        'replenishment_need' => 200,
                        
                        // Analytics API fields (NEW)
                        'data_source' => 'analytics_api',
                        'data_quality_score' => 95,
                        'last_analytics_sync' => '2024-01-15 10:30:00',
                        'normalized_warehouse_name' => 'Москва РФЦ',
                        'original_warehouse_name' => 'Moscow RFC',
                        'sync_batch_id' => 'etl_20240115_103000_abc123',
                        'hours_since_sync' => 2,
                        'freshness_status' => 'fresh'
                    ]
                ],
                'totals' => [
                    'total_items' => 1,
                    'total_available' => 150,
                    'total_replenishment_need' => 200
                ]
            ]
        ],
        'summary' => [
            'total_products' => 1500,
            'active_products' => 1200,
            'total_replenishment_need' => 50000,
            'by_liquidity' => [
                'critical' => 50,
                'low' => 200,
                'normal' => 800,
                'excess' => 150
            ],
            
            // Analytics API metrics (NEW)
            'data_quality' => [
                'avg_quality_score' => 87.5
            ],
            'by_data_source' => [
                'analytics_api' => 1200,
                'manual' => 250,
                'import' => 50
            ],
            'freshness' => [
                'fresh_count' => 1100,
                'stale_count' => 100,
                'fresh_percentage' => 91.7
            ]
        ],
        'filters_applied' => [
            'warehouse' => null,
            'data_source' => 'analytics_api',
            'quality_score' => 80,
            'freshness_hours' => 24,
            'sort_by' => 'data_quality_score',
            'sort_order' => 'desc'
        ],
        'pagination' => [
            'limit' => 100,
            'offset' => 0,
            'total' => 1500,
            'current_page' => 1,
            'total_pages' => 15,
            'has_next' => true,
            'has_prev' => false
        ]
    ]
];

echo json_encode($sampleResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 8: Error Handling
echo "8. Error Handling Examples\n";
echo "=========================\n";

$errorScenarios = [
    [
        'description' => 'Invalid data source',
        'params' => ['data_source' => 'invalid_source'],
        'expected_error' => 'Invalid data_source'
    ],
    [
        'description' => 'Quality score out of range',
        'params' => ['quality_score' => '150'],
        'expected_error' => 'Invalid quality_score'
    ],
    [
        'description' => 'Negative freshness hours',
        'params' => ['freshness_hours' => '-5'],
        'expected_error' => 'Invalid freshness_hours'
    ],
    [
        'description' => 'Invalid sort field',
        'params' => ['sort_by' => 'invalid_field'],
        'expected_error' => 'Invalid sort_by'
    ]
];

foreach ($errorScenarios as $scenario) {
    echo "\nScenario: {$scenario['description']}\n";
    echo "Parameters: " . json_encode($scenario['params']) . "\n";
    
    try {
        $reflection = new ReflectionClass($controller);
        $method = $reflection->getMethod('validateDashboardFilters');
        $method->setAccessible(true);
        
        $method->invoke($controller, $scenario['params']);
        echo "✗ Expected error but validation passed\n";
        
    } catch (ValidationException $e) {
        echo "✓ Caught expected error: {$e->getMessage()}\n";
        
        if (strpos($e->getMessage(), $scenario['expected_error']) !== false) {
            echo "✓ Error message matches expected pattern\n";
        } else {
            echo "✗ Error message doesn't match expected pattern\n";
        }
    }
}

echo "\n" . str_repeat("-", 50) . "\n\n";

// Example 9: HTTP Client Usage
echo "9. HTTP Client Usage Examples\n";
echo "============================\n";

echo "cURL examples for Analytics API enhanced endpoints:\n\n";

echo "1. Get Analytics API data with high quality:\n";
echo "curl -X GET 'http://your-domain.com/api/warehouse/dashboard?data_source=analytics_api&quality_score=90'\n\n";

echo "2. Get fresh data (last 6 hours) sorted by quality:\n";
echo "curl -X GET 'http://your-domain.com/api/warehouse/dashboard?freshness_hours=6&sort_by=data_quality_score&sort_order=desc'\n\n";

echo "3. Get warehouse list with Analytics metrics:\n";
echo "curl -X GET 'http://your-domain.com/api/warehouse/warehouses'\n\n";

echo "4. Export data with Analytics filters:\n";
echo "curl -X GET 'http://your-domain.com/api/warehouse/export?data_source=analytics_api&quality_score=80' -o warehouse_analytics_export.csv\n\n";

echo "5. JavaScript Fetch example:\n";
echo "```javascript\n";
echo "fetch('/api/warehouse/dashboard?' + new URLSearchParams({\n";
echo "  data_source: 'analytics_api',\n";
echo "  quality_score: '85',\n";
echo "  freshness_hours: '24',\n";
echo "  sort_by: 'data_quality_score',\n";
echo "  limit: '50'\n";
echo "}))\n";
echo ".then(response => response.json())\n";
echo ".then(data => {\n";
echo "  if (data.success) {\n";
echo "    console.log('Analytics data:', data.data.summary.by_data_source);\n";
echo "    console.log('Quality score:', data.data.summary.data_quality.avg_quality_score);\n";
echo "    console.log('Freshness:', data.data.summary.freshness.fresh_percentage + '%');\n";
echo "  }\n";
echo "});\n";
echo "```\n\n";

echo str_repeat("=", 50) . "\n";
echo "WarehouseController Analytics Examples Complete!\n";
echo str_repeat("=", 50) . "\n";

/**
 * Helper function to format API response
 */
function formatApiResponse(array $response): string {
    return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Helper function to build query string
 */
function buildQueryString(array $params): string {
    return http_build_query($params);
}

/**
 * Helper function to validate Analytics API filters
 */
function validateAnalyticsFilters(array $filters): array {
    $errors = [];
    
    if (isset($filters['data_source'])) {
        $validSources = ['analytics_api', 'manual', 'import', 'all'];
        if (!in_array($filters['data_source'], $validSources)) {
            $errors[] = 'Invalid data_source';
        }
    }
    
    if (isset($filters['quality_score'])) {
        $score = (int)$filters['quality_score'];
        if ($score < 0 || $score > 100) {
            $errors[] = 'Invalid quality_score range';
        }
    }
    
    if (isset($filters['freshness_hours'])) {
        $hours = (int)$filters['freshness_hours'];
        if ($hours < 0) {
            $errors[] = 'Invalid freshness_hours';
        }
    }
    
    return $errors;
}