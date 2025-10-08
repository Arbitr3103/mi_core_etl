<?php
/**
 * Enhanced Dashboard API with MDM Integration
 * Provides analytics data enhanced with master data
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Метод не поддерживается'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Load environment configuration
function loadEnvConfig() {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $value = trim($value, '"\'');
                $_ENV[trim($key)] = $value;
            }
        }
    }
}

loadEnvConfig();

try {
    // Database connection
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=mi_core;charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    // Include MDM services
    require_once __DIR__ . '/../src/MDM/Services/DashboardIntegrationService.php';
    
    $dashboardService = new MDM\Services\DashboardIntegrationService($pdo);
    
    $action = $_GET['action'] ?? 'summary';
    
    switch ($action) {
        case 'summary':
            handleSummaryRequest($dashboardService);
            break;
            
        case 'inventory':
            handleInventoryRequest($dashboardService);
            break;
            
        case 'brands':
            handleBrandsAnalyticsRequest($dashboardService);
            break;
            
        case 'categories':
            handleCategoriesAnalyticsRequest($dashboardService);
            break;
            
        case 'quality':
            handleDataQualityRequest($dashboardService);
            break;
            
        case 'critical':
            handleCriticalStockRequest($dashboardService);
            break;
            
        case 'trends':
            handleTrendsRequest($dashboardService);
            break;
            
        case 'top-products':
            handleTopProductsRequest($dashboardService);
            break;
            
        case 'marketing':
            handleMarketingAnalyticsRequest($dashboardService);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Неизвестное действие'
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * Handle dashboard summary request
 */
function handleSummaryRequest($dashboardService) {
    try {
        $summary = $dashboardService->getDashboardSummary();
        
        echo json_encode([
            'success' => true,
            'data' => $summary,
            'metadata' => [
                'mdm_enabled' => true,
                'generated_at' => date('Y-m-d H:i:s'),
                'api_version' => '2.0-mdm'
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении сводки дашборда: ' . $e->getMessage());
    }
}

/**
 * Handle enhanced inventory request
 */
function handleInventoryRequest($dashboardService) {
    try {
        $filters = [
            'source' => $_GET['source'] ?? null,
            'brand' => $_GET['brand'] ?? null,
            'category' => $_GET['category'] ?? null,
            'min_stock' => $_GET['min_stock'] ?? null,
            'max_stock' => $_GET['max_stock'] ?? null,
            'limit' => min((int)($_GET['limit'] ?? 100), 1000),
            'offset' => (int)($_GET['offset'] ?? 0)
        ];
        
        $inventory = $dashboardService->getEnhancedInventoryData($filters);
        
        // Calculate summary statistics
        $totalStock = array_sum(array_column($inventory, 'current_stock'));
        $totalReserved = array_sum(array_column($inventory, 'reserved_stock'));
        $masterDataCount = count(array_filter($inventory, function($item) {
            return $item['data_quality_level'] === 'master_data';
        }));
        
        echo json_encode([
            'success' => true,
            'data' => [
                'inventory' => $inventory,
                'summary' => [
                    'total_items' => count($inventory),
                    'total_stock' => $totalStock,
                    'total_reserved' => $totalReserved,
                    'master_data_coverage' => count($inventory) > 0 ? 
                        round(($masterDataCount / count($inventory)) * 100, 2) : 0,
                    'filters_applied' => array_filter($filters)
                ]
            ],
            'metadata' => [
                'mdm_enhanced' => true,
                'filters' => $filters
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении данных инвентаря: ' . $e->getMessage());
    }
}

/**
 * Handle brands analytics request
 */
function handleBrandsAnalyticsRequest($dashboardService) {
    try {
        $brands = $dashboardService->getEnhancedBrandAnalytics();
        
        // Calculate additional metrics
        $totalBrands = count($brands);
        $totalStock = array_sum(array_column($brands, 'total_stock'));
        $totalProducts = array_sum(array_column($brands, 'master_products_count'));
        
        echo json_encode([
            'success' => true,
            'data' => [
                'brands' => $brands,
                'summary' => [
                    'total_brands' => $totalBrands,
                    'total_master_products' => $totalProducts,
                    'total_stock_across_brands' => $totalStock,
                    'avg_products_per_brand' => $totalBrands > 0 ? round($totalProducts / $totalBrands, 2) : 0,
                    'top_brand_by_stock' => !empty($brands) ? $brands[0]['brand_name'] : null
                ]
            ],
            'metadata' => [
                'analysis_type' => 'brand_analytics',
                'mdm_enhanced' => true
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении аналитики брендов: ' . $e->getMessage());
    }
}

/**
 * Handle categories analytics request
 */
function handleCategoriesAnalyticsRequest($dashboardService) {
    try {
        $categories = $dashboardService->getEnhancedCategoryAnalytics();
        
        // Calculate additional metrics
        $totalCategories = count($categories);
        $totalStock = array_sum(array_column($categories, 'total_stock'));
        $totalProducts = array_sum(array_column($categories, 'master_products_count'));
        
        echo json_encode([
            'success' => true,
            'data' => [
                'categories' => $categories,
                'summary' => [
                    'total_categories' => $totalCategories,
                    'total_master_products' => $totalProducts,
                    'total_stock_across_categories' => $totalStock,
                    'avg_products_per_category' => $totalCategories > 0 ? round($totalProducts / $totalCategories, 2) : 0,
                    'top_category_by_stock' => !empty($categories) ? $categories[0]['category_name'] : null
                ]
            ],
            'metadata' => [
                'analysis_type' => 'category_analytics',
                'mdm_enhanced' => true
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении аналитики категорий: ' . $e->getMessage());
    }
}

/**
 * Handle data quality request
 */
function handleDataQualityRequest($dashboardService) {
    try {
        $qualityMetrics = $dashboardService->getDataQualityMetrics();
        
        // Calculate overall quality score
        $completeness = $qualityMetrics['completeness'];
        $totalProducts = (int)$completeness['total_master_products'];
        
        $overallQualityScore = 0;
        if ($totalProducts > 0) {
            $brandScore = ($completeness['products_with_brand'] / $totalProducts) * 100;
            $categoryScore = ($completeness['products_with_category'] / $totalProducts) * 100;
            $descriptionScore = ($completeness['products_with_description'] / $totalProducts) * 100;
            
            $overallQualityScore = round(($brandScore + $categoryScore + $descriptionScore) / 3, 2);
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'quality_metrics' => $qualityMetrics,
                'overall_quality_score' => $overallQualityScore,
                'quality_grade' => getQualityGrade($overallQualityScore)
            ],
            'metadata' => [
                'analysis_type' => 'data_quality',
                'calculation_method' => 'weighted_average'
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении метрик качества данных: ' . $e->getMessage());
    }
}

/**
 * Handle critical stock request
 */
function handleCriticalStockRequest($dashboardService) {
    try {
        $threshold = (int)($_GET['threshold'] ?? 10);
        $criticalStock = $dashboardService->getCriticalStockAlerts($threshold);
        
        // Group by alert type
        $alertGroups = [];
        foreach ($criticalStock as $item) {
            $alertType = $item['alert_type'];
            if (!isset($alertGroups[$alertType])) {
                $alertGroups[$alertType] = [];
            }
            $alertGroups[$alertType][] = $item;
        }
        
        // Calculate priority statistics
        $priorityStats = [];
        for ($i = 1; $i <= 5; $i++) {
            $priorityStats[$i] = count(array_filter($criticalStock, function($item) use ($i) {
                return $item['priority'] == $i;
            }));
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'critical_items' => $criticalStock,
                'alert_groups' => $alertGroups,
                'summary' => [
                    'total_critical_items' => count($criticalStock),
                    'threshold_used' => $threshold,
                    'priority_distribution' => $priorityStats,
                    'most_critical_count' => $priorityStats[1] ?? 0
                ]
            ],
            'metadata' => [
                'analysis_type' => 'critical_stock_alerts',
                'threshold' => $threshold
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении критических остатков: ' . $e->getMessage());
    }
}

/**
 * Handle trends request
 */
function handleTrendsRequest($dashboardService) {
    try {
        $days = min((int)($_GET['days'] ?? 30), 90);
        $trends = $dashboardService->getPerformanceTrends($days);
        
        // Group trends by metric name
        $trendsByMetric = [];
        foreach ($trends as $trend) {
            $metricName = $trend['metric_name'];
            if (!isset($trendsByMetric[$metricName])) {
                $trendsByMetric[$metricName] = [];
            }
            $trendsByMetric[$metricName][] = $trend;
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'trends' => $trends,
                'trends_by_metric' => $trendsByMetric,
                'period_days' => $days
            ],
            'metadata' => [
                'analysis_type' => 'performance_trends',
                'period_days' => $days
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении трендов: ' . $e->getMessage());
    }
}

/**
 * Handle top products request
 */
function handleTopProductsRequest($dashboardService) {
    try {
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $topProducts = $dashboardService->getTopPerformingProducts($limit);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'top_products' => $topProducts,
                'summary' => [
                    'products_analyzed' => count($topProducts),
                    'limit_applied' => $limit
                ]
            ],
            'metadata' => [
                'analysis_type' => 'top_performing_products',
                'ranking_criteria' => 'demand_and_stock_levels'
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении топ товаров: ' . $e->getMessage());
    }
}

/**
 * Handle marketing analytics request (enhanced version of existing marketing endpoint)
 */
function handleMarketingAnalyticsRequest($dashboardService) {
    try {
        // Get enhanced data using master data
        $brands = $dashboardService->getEnhancedBrandAnalytics();
        $categories = $dashboardService->getEnhancedCategoryAnalytics();
        $topProducts = $dashboardService->getTopPerformingProducts(15);
        $criticalStock = $dashboardService->getCriticalStockAlerts(15);
        
        // Marketing-specific insights
        $marketingInsights = [
            'high_demand_low_stock' => array_filter($criticalStock, function($item) {
                return $item['alert_type'] === 'critical_with_demand' || $item['alert_type'] === 'out_of_stock_with_demand';
            }),
            'overstocked_items' => array_filter($criticalStock, function($item) {
                return $item['demand_status'] === 'overstocked';
            }),
            'top_brands_by_demand' => array_slice(array_filter($brands, function($brand) {
                return $brand['skus_with_demand'] > 0;
            }), 0, 5),
            'underperforming_categories' => array_filter($categories, function($category) {
                return $category['demand_percentage'] < 20;
            })
        ];
        
        echo json_encode([
            'success' => true,
            'data' => [
                'brand_performance' => $brands,
                'category_performance' => $categories,
                'top_products' => $topProducts,
                'marketing_insights' => $marketingInsights,
                'summary' => [
                    'total_brands_analyzed' => count($brands),
                    'total_categories_analyzed' => count($categories),
                    'high_priority_alerts' => count($marketingInsights['high_demand_low_stock']),
                    'overstocked_items_count' => count($marketingInsights['overstocked_items'])
                ]
            ],
            'metadata' => [
                'analysis_type' => 'marketing_analytics_enhanced',
                'mdm_enhanced' => true,
                'focus' => 'demand_analysis_and_stock_optimization'
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        throw new Exception('Ошибка при получении маркетинговой аналитики: ' . $e->getMessage());
    }
}

/**
 * Get quality grade based on score
 */
function getQualityGrade($score) {
    if ($score >= 90) return 'A';
    if ($score >= 80) return 'B';
    if ($score >= 70) return 'C';
    if ($score >= 60) return 'D';
    return 'F';
}
?>