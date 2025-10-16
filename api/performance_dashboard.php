<?php
/**
 * Performance Dashboard API
 * Task 7.2: Create performance dashboard for monitoring system health
 * 
 * This API provides endpoints for viewing performance metrics, trends, and alerts.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/performance_monitor.php';
require_once __DIR__ . '/database_query_optimizer.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    $action = $_GET['action'] ?? 'dashboard';
    $monitor = getPerformanceMonitor();
    $pdo = getDatabaseConnection();
    $optimizer = new DatabaseQueryOptimizer($pdo);
    
    switch ($action) {
        case 'dashboard':
            // Main dashboard data
            $hours = (int)($_GET['hours'] ?? 24);
            
            $dashboard_data = [
                'summary' => $monitor->getPerformanceSummary($hours),
                'trends' => $monitor->getPerformanceTrends($hours, 60), // 1-hour intervals
                'alerts' => $monitor->generatePerformanceAlerts(),
                'slowest_operations' => $monitor->getSlowestOperations(10, $hours),
                'memory_intensive_operations' => $monitor->getMemoryIntensiveOperations(10, $hours),
                'database_stats' => $optimizer->getDatabaseStatistics(),
                'system_info' => [
                    'php_version' => PHP_VERSION,
                    'memory_limit' => ini_get('memory_limit'),
                    'max_execution_time' => ini_get('max_execution_time'),
                    'current_memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'peak_memory_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2)
                ]
            ];
            
            echo json_encode([
                'status' => 'success',
                'data' => $dashboard_data,
                'metadata' => [
                    'period_hours' => $hours,
                    'generated_at' => date('Y-m-d H:i:s'),
                    'monitoring_enabled' => $monitor->isEnabled()
                ]
            ]);
            break;
            
        case 'performance_summary':
            $hours = (int)($_GET['hours'] ?? 24);
            $summary = $monitor->getPerformanceSummary($hours);
            
            echo json_encode([
                'status' => 'success',
                'data' => $summary,
                'metadata' => [
                    'period_hours' => $hours,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'performance_trends':
            $hours = (int)($_GET['hours'] ?? 24);
            $interval = (int)($_GET['interval'] ?? 60); // minutes
            $trends = $monitor->getPerformanceTrends($hours, $interval);
            
            echo json_encode([
                'status' => 'success',
                'data' => $trends,
                'metadata' => [
                    'period_hours' => $hours,
                    'interval_minutes' => $interval,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'alerts':
            $thresholds = [];
            if (isset($_GET['max_execution_time_ms'])) {
                $thresholds['max_execution_time_ms'] = (int)$_GET['max_execution_time_ms'];
            }
            if (isset($_GET['max_memory_usage_mb'])) {
                $thresholds['max_memory_usage_mb'] = (int)$_GET['max_memory_usage_mb'];
            }
            
            $alerts = $monitor->generatePerformanceAlerts($thresholds);
            
            echo json_encode([
                'status' => 'success',
                'data' => $alerts,
                'metadata' => [
                    'thresholds_used' => $thresholds,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'slowest_operations':
            $limit = (int)($_GET['limit'] ?? 10);
            $hours = (int)($_GET['hours'] ?? 24);
            $slowest = $monitor->getSlowestOperations($limit, $hours);
            
            echo json_encode([
                'status' => 'success',
                'data' => $slowest,
                'metadata' => [
                    'limit' => $limit,
                    'period_hours' => $hours,
                    'total_operations' => count($slowest),
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'memory_intensive':
            $limit = (int)($_GET['limit'] ?? 10);
            $hours = (int)($_GET['hours'] ?? 24);
            $memory_intensive = $monitor->getMemoryIntensiveOperations($limit, $hours);
            
            echo json_encode([
                'status' => 'success',
                'data' => $memory_intensive,
                'metadata' => [
                    'limit' => $limit,
                    'period_hours' => $hours,
                    'total_operations' => count($memory_intensive),
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'database_performance':
            $performance_analysis = $optimizer->analyzeQueryPerformance();
            $database_stats = $optimizer->getDatabaseStatistics();
            $recommendations = $optimizer->generateOptimizationRecommendations();
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'query_performance' => $performance_analysis,
                    'database_statistics' => $database_stats,
                    'optimization_recommendations' => $recommendations
                ],
                'metadata' => [
                    'generated_at' => date('Y-m-d H:i:s'),
                    'total_queries_analyzed' => count($performance_analysis)
                ]
            ]);
            break;
            
        case 'optimize_database':
            // Run database optimizations
            $results = [];
            
            // Create indexes
            $index_results = $optimizer->createOptimizedIndexes();
            $results['index_creation'] = $index_results;
            
            // Optimize table statistics
            $stats_results = $optimizer->optimizeTableStatistics();
            $results['table_optimization'] = $stats_results;
            
            // Get updated performance analysis
            $performance_analysis = $optimizer->analyzeQueryPerformance();
            $results['performance_analysis'] = $performance_analysis;
            
            echo json_encode([
                'status' => 'success',
                'data' => $results,
                'metadata' => [
                    'optimization_completed_at' => date('Y-m-d H:i:s'),
                    'indexes_created' => $index_results['total_created'],
                    'tables_optimized' => count($stats_results)
                ]
            ]);
            break;
            
        case 'export_metrics':
            $hours = (int)($_GET['hours'] ?? 24);
            $format = $_GET['format'] ?? 'json';
            
            $export_data = $monitor->exportMetrics($hours);
            
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="performance_metrics_' . date('Y-m-d_H-i-s') . '.csv"');
                
                // Convert to CSV
                $output = fopen('php://output', 'w');
                
                // Headers
                fputcsv($output, ['Timestamp', 'Type', 'Operation', 'Execution Time (ms)', 'Memory Usage (MB)', 'Additional Data']);
                
                // Data rows
                foreach ($export_data['metrics'] as $metric) {
                    fputcsv($output, [
                        $metric['timestamp'] ?? '',
                        $metric['type'] ?? '',
                        $metric['operation'] ?? $metric['name'] ?? '',
                        $metric['execution_time_ms'] ?? '',
                        $metric['memory_usage_mb'] ?? '',
                        json_encode($metric['additional_data'] ?? [])
                    ]);
                }
                
                fclose($output);
            } else {
                echo json_encode([
                    'status' => 'success',
                    'data' => $export_data,
                    'metadata' => [
                        'export_format' => $format,
                        'generated_at' => date('Y-m-d H:i:s')
                    ]
                ]);
            }
            break;
            
        case 'clear_old_metrics':
            $hours = (int)($_GET['hours'] ?? 168); // 7 days default
            $remaining_count = $monitor->clearOldMetrics($hours);
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'remaining_metrics_count' => $remaining_count,
                    'cleared_older_than_hours' => $hours
                ],
                'metadata' => [
                    'cleared_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'system_health':
            // Comprehensive system health check
            $health_data = [
                'performance_summary' => $monitor->getPerformanceSummary(1), // Last hour
                'alerts' => $monitor->generatePerformanceAlerts(),
                'database_health' => [
                    'statistics' => $optimizer->getDatabaseStatistics(),
                    'query_performance' => $optimizer->analyzeQueryPerformance()
                ],
                'system_resources' => [
                    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                    'memory_limit' => ini_get('memory_limit'),
                    'php_version' => PHP_VERSION
                ],
                'cache_status' => [
                    'inventory_cache_enabled' => class_exists('InventoryCacheManager'),
                    'performance_monitoring_enabled' => $monitor->isEnabled()
                ]
            ];
            
            // Calculate overall health score
            $health_score = calculateSystemHealthScore($health_data);
            
            echo json_encode([
                'status' => 'success',
                'data' => $health_data,
                'health_score' => $health_score,
                'metadata' => [
                    'health_check_completed_at' => date('Y-m-d H:i:s'),
                    'score_explanation' => 'Health score from 0-100 based on performance metrics, alerts, and system resources'
                ]
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'error_code' => 'INVALID_ACTION',
                'message' => 'Invalid action specified',
                'available_actions' => [
                    'dashboard',
                    'performance_summary',
                    'performance_trends',
                    'alerts',
                    'slowest_operations',
                    'memory_intensive',
                    'database_performance',
                    'optimize_database',
                    'export_metrics',
                    'clear_old_metrics',
                    'system_health'
                ]
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'INTERNAL_ERROR',
        'message' => 'Internal server error',
        'details' => $e->getMessage()
    ]);
}

/**
 * Calculate overall system health score
 */
function calculateSystemHealthScore($health_data) {
    $score = 100;
    
    // Deduct points for alerts
    if (isset($health_data['alerts']['total_alerts'])) {
        $score -= min($health_data['alerts']['total_alerts'] * 10, 50); // Max 50 points deduction
    }
    
    // Deduct points for high severity alerts
    if (isset($health_data['alerts']['high_severity_count'])) {
        $score -= $health_data['alerts']['high_severity_count'] * 15;
    }
    
    // Deduct points for poor query performance
    if (isset($health_data['database_health']['query_performance'])) {
        $poor_queries = 0;
        foreach ($health_data['database_health']['query_performance'] as $query => $perf) {
            if (isset($perf['performance_rating']) && $perf['performance_rating'] === 'poor') {
                $poor_queries++;
            }
        }
        $score -= $poor_queries * 5;
    }
    
    // Deduct points for high memory usage
    $memory_usage_mb = $health_data['system_resources']['memory_usage_mb'] ?? 0;
    if ($memory_usage_mb > 256) {
        $score -= min(($memory_usage_mb - 256) / 10, 20); // Max 20 points deduction
    }
    
    // Ensure score is between 0 and 100
    return max(0, min(100, round($score)));
}
?>