<?php
/**
 * Performance Metrics API
 * Task 13: Comprehensive performance monitoring endpoint
 * 
 * Provides real-time performance metrics including:
 * - API response times
 * - Database query performance
 * - Memory and CPU usage
 * - System health indicators
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/classes/PerformanceTracker.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Track this API request
$tracker = PerformanceTracker::getInstance();
$tracker->trackApiRequest('/api/performance-metrics.php', $_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    $action = $_GET['action'] ?? 'current';
    $log_file = __DIR__ . '/../logs/performance_tracker.log';
    
    switch ($action) {
        case 'current':
            // Get current system metrics
            $response = [
                'status' => 'success',
                'data' => [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'cpu' => $tracker->getCpuUsage(),
                    'memory' => $tracker->getMemoryUsage(),
                    'current_request' => $tracker->getMetrics()
                ]
            ];
            break;
            
        case 'recent':
            // Get recent performance data from log
            $hours = (int)($_GET['hours'] ?? 1);
            $recent_data = getRecentPerformanceData($log_file, $hours);
            
            $response = [
                'status' => 'success',
                'data' => $recent_data,
                'metadata' => [
                    'period_hours' => $hours,
                    'total_requests' => count($recent_data)
                ]
            ];
            break;
            
        case 'summary':
            // Get performance summary
            $hours = (int)($_GET['hours'] ?? 24);
            $summary = generatePerformanceSummary($log_file, $hours);
            
            $response = [
                'status' => 'success',
                'data' => $summary,
                'metadata' => [
                    'period_hours' => $hours,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ];
            break;
            
        case 'api_response_times':
            // Get API response time statistics
            $hours = (int)($_GET['hours'] ?? 24);
            $endpoint = $_GET['endpoint'] ?? null;
            
            $response_times = getApiResponseTimes($log_file, $hours, $endpoint);
            
            $response = [
                'status' => 'success',
                'data' => $response_times,
                'metadata' => [
                    'period_hours' => $hours,
                    'endpoint_filter' => $endpoint
                ]
            ];
            break;
            
        case 'database_performance':
            // Get database query performance
            $hours = (int)($_GET['hours'] ?? 24);
            $db_performance = getDatabasePerformance($log_file, $hours);
            
            $response = [
                'status' => 'success',
                'data' => $db_performance,
                'metadata' => [
                    'period_hours' => $hours
                ]
            ];
            break;
            
        case 'resource_usage':
            // Get resource usage trends
            $hours = (int)($_GET['hours'] ?? 24);
            $interval_minutes = (int)($_GET['interval'] ?? 60);
            
            $resource_usage = getResourceUsageTrends($log_file, $hours, $interval_minutes);
            
            $response = [
                'status' => 'success',
                'data' => $resource_usage,
                'metadata' => [
                    'period_hours' => $hours,
                    'interval_minutes' => $interval_minutes
                ]
            ];
            break;
            
        case 'slow_requests':
            // Get slowest requests
            $hours = (int)($_GET['hours'] ?? 24);
            $limit = (int)($_GET['limit'] ?? 20);
            $threshold_ms = (int)($_GET['threshold'] ?? 2000);
            
            $slow_requests = getSlowRequests($log_file, $hours, $limit, $threshold_ms);
            
            $response = [
                'status' => 'success',
                'data' => $slow_requests,
                'metadata' => [
                    'period_hours' => $hours,
                    'threshold_ms' => $threshold_ms,
                    'total_slow_requests' => count($slow_requests)
                ]
            ];
            break;
            
        case 'performance_alerts':
            // Get performance alerts
            $hours = (int)($_GET['hours'] ?? 1);
            $alerts = generatePerformanceAlerts($log_file, $hours);
            
            $response = [
                'status' => 'success',
                'data' => $alerts,
                'metadata' => [
                    'period_hours' => $hours,
                    'total_alerts' => count($alerts)
                ]
            ];
            break;
            
        case 'health_check':
            // Comprehensive health check
            $health = performHealthCheck($log_file);
            
            $response = [
                'status' => 'success',
                'data' => $health,
                'metadata' => [
                    'checked_at' => date('Y-m-d H:i:s')
                ]
            ];
            break;
            
        default:
            http_response_code(400);
            $response = [
                'status' => 'error',
                'error_code' => 'INVALID_ACTION',
                'message' => 'Invalid action specified',
                'available_actions' => [
                    'current', 'recent', 'summary', 'api_response_times',
                    'database_performance', 'resource_usage', 'slow_requests',
                    'performance_alerts', 'health_check'
                ]
            ];
    }
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'INTERNAL_ERROR',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}

/**
 * Get recent performance data from log file
 */
function getRecentPerformanceData($log_file, $hours) {
    if (!file_exists($log_file)) {
        return [];
    }
    
    $cutoff_time = time() - ($hours * 3600);
    $data = [];
    
    $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_reverse($lines) as $line) {
        $entry = json_decode($line, true);
        if ($entry && strtotime($entry['timestamp']) > $cutoff_time) {
            $data[] = $entry;
        }
    }
    
    return array_reverse($data);
}

/**
 * Generate performance summary
 */
function generatePerformanceSummary($log_file, $hours) {
    $data = getRecentPerformanceData($log_file, $hours);
    
    if (empty($data)) {
        return [
            'message' => 'No performance data available',
            'total_requests' => 0
        ];
    }
    
    $response_times = array_column($data, 'total_execution_time_ms');
    $memory_usage = array_column($data, 'peak_memory_mb');
    
    $summary = [
        'total_requests' => count($data),
        'response_times' => [
            'avg_ms' => round(array_sum($response_times) / count($response_times), 2),
            'min_ms' => min($response_times),
            'max_ms' => max($response_times),
            'median_ms' => calculateMedian($response_times),
            'p95_ms' => calculatePercentile($response_times, 95),
            'p99_ms' => calculatePercentile($response_times, 99)
        ],
        'memory_usage' => [
            'avg_mb' => round(array_sum($memory_usage) / count($memory_usage), 2),
            'min_mb' => min($memory_usage),
            'max_mb' => max($memory_usage),
            'median_mb' => calculateMedian($memory_usage)
        ],
        'database_queries' => [
            'total_queries' => array_sum(array_column(array_column($data, 'database_queries'), 'count')),
            'total_slow_queries' => array_sum(array_column(array_column($data, 'database_queries'), 'slow_queries')),
            'avg_query_time_ms' => calculateAverageQueryTime($data)
        ],
        'cache_performance' => calculateCachePerformance($data),
        'performance_flags' => aggregatePerformanceFlags($data)
    ];
    
    return $summary;
}

/**
 * Get API response times
 */
function getApiResponseTimes($log_file, $hours, $endpoint_filter = null) {
    $data = getRecentPerformanceData($log_file, $hours);
    
    $endpoints = [];
    
    foreach ($data as $entry) {
        $endpoint = $entry['request']['endpoint'] ?? 'unknown';
        
        if ($endpoint_filter && $endpoint !== $endpoint_filter) {
            continue;
        }
        
        if (!isset($endpoints[$endpoint])) {
            $endpoints[$endpoint] = [
                'endpoint' => $endpoint,
                'request_count' => 0,
                'response_times' => []
            ];
        }
        
        $endpoints[$endpoint]['request_count']++;
        $endpoints[$endpoint]['response_times'][] = $entry['total_execution_time_ms'];
    }
    
    // Calculate statistics for each endpoint
    foreach ($endpoints as &$endpoint_data) {
        $times = $endpoint_data['response_times'];
        $endpoint_data['statistics'] = [
            'avg_ms' => round(array_sum($times) / count($times), 2),
            'min_ms' => min($times),
            'max_ms' => max($times),
            'median_ms' => calculateMedian($times),
            'p95_ms' => calculatePercentile($times, 95),
            'p99_ms' => calculatePercentile($times, 99)
        ];
        unset($endpoint_data['response_times']);
    }
    
    return array_values($endpoints);
}

/**
 * Get database performance metrics
 */
function getDatabasePerformance($log_file, $hours) {
    $data = getRecentPerformanceData($log_file, $hours);
    
    $total_queries = 0;
    $total_slow_queries = 0;
    $query_times = [];
    $query_hashes = [];
    
    foreach ($data as $entry) {
        $db_data = $entry['database_queries'];
        $total_queries += $db_data['count'];
        $total_slow_queries += $db_data['slow_queries'];
        
        foreach ($db_data['queries'] as $query) {
            $query_times[] = $query['execution_time_ms'];
            
            $hash = $query['query_hash'];
            if (!isset($query_hashes[$hash])) {
                $query_hashes[$hash] = [
                    'query' => $query['query'],
                    'execution_count' => 0,
                    'total_time_ms' => 0,
                    'times' => []
                ];
            }
            
            $query_hashes[$hash]['execution_count']++;
            $query_hashes[$hash]['total_time_ms'] += $query['execution_time_ms'];
            $query_hashes[$hash]['times'][] = $query['execution_time_ms'];
        }
    }
    
    // Calculate statistics for each unique query
    foreach ($query_hashes as &$query_data) {
        $times = $query_data['times'];
        $query_data['avg_time_ms'] = round($query_data['total_time_ms'] / $query_data['execution_count'], 2);
        $query_data['max_time_ms'] = max($times);
        $query_data['min_time_ms'] = min($times);
        unset($query_data['times']);
    }
    
    // Sort by total time descending
    usort($query_hashes, function($a, $b) {
        return $b['total_time_ms'] <=> $a['total_time_ms'];
    });
    
    return [
        'total_queries' => $total_queries,
        'total_slow_queries' => $total_slow_queries,
        'slow_query_percentage' => $total_queries > 0 ? round(($total_slow_queries / $total_queries) * 100, 2) : 0,
        'query_statistics' => [
            'avg_time_ms' => !empty($query_times) ? round(array_sum($query_times) / count($query_times), 2) : 0,
            'max_time_ms' => !empty($query_times) ? max($query_times) : 0,
            'median_time_ms' => !empty($query_times) ? calculateMedian($query_times) : 0
        ],
        'top_queries_by_total_time' => array_slice($query_hashes, 0, 10)
    ];
}

/**
 * Get resource usage trends
 */
function getResourceUsageTrends($log_file, $hours, $interval_minutes) {
    $data = getRecentPerformanceData($log_file, $hours);
    
    if (empty($data)) {
        return [];
    }
    
    $interval_seconds = $interval_minutes * 60;
    $cutoff_time = time() - ($hours * 3600);
    
    $trends = [];
    
    for ($time = $cutoff_time; $time < time(); $time += $interval_seconds) {
        $interval_end = $time + $interval_seconds;
        
        $interval_data = array_filter($data, function($entry) use ($time, $interval_end) {
            $entry_time = strtotime($entry['timestamp']);
            return $entry_time >= $time && $entry_time < $interval_end;
        });
        
        if (!empty($interval_data)) {
            $memory_values = array_column($interval_data, 'peak_memory_mb');
            $response_times = array_column($interval_data, 'total_execution_time_ms');
            
            // Extract CPU load
            $cpu_loads = [];
            foreach ($interval_data as $entry) {
                if (isset($entry['system_resources']['cpu']['load_1min'])) {
                    $cpu_loads[] = $entry['system_resources']['cpu']['load_1min'];
                }
            }
            
            $trends[] = [
                'timestamp' => date('Y-m-d H:i:s', $time),
                'request_count' => count($interval_data),
                'avg_response_time_ms' => round(array_sum($response_times) / count($response_times), 2),
                'avg_memory_mb' => round(array_sum($memory_values) / count($memory_values), 2),
                'max_memory_mb' => max($memory_values),
                'avg_cpu_load' => !empty($cpu_loads) ? round(array_sum($cpu_loads) / count($cpu_loads), 2) : null
            ];
        }
    }
    
    return $trends;
}

/**
 * Get slow requests
 */
function getSlowRequests($log_file, $hours, $limit, $threshold_ms) {
    $data = getRecentPerformanceData($log_file, $hours);
    
    $slow_requests = array_filter($data, function($entry) use ($threshold_ms) {
        return $entry['total_execution_time_ms'] > $threshold_ms;
    });
    
    // Sort by execution time descending
    usort($slow_requests, function($a, $b) {
        return $b['total_execution_time_ms'] <=> $a['total_execution_time_ms'];
    });
    
    return array_slice($slow_requests, 0, $limit);
}

/**
 * Generate performance alerts
 */
function generatePerformanceAlerts($log_file, $hours) {
    $data = getRecentPerformanceData($log_file, $hours);
    
    $alerts = [];
    
    foreach ($data as $entry) {
        if (!empty($entry['performance_flags'])) {
            foreach ($entry['performance_flags'] as $flag) {
                $alerts[] = [
                    'timestamp' => $entry['timestamp'],
                    'flag' => $flag,
                    'endpoint' => $entry['request']['endpoint'] ?? 'unknown',
                    'execution_time_ms' => $entry['total_execution_time_ms'],
                    'memory_mb' => $entry['peak_memory_mb'],
                    'severity' => determineSeverity($flag)
                ];
            }
        }
    }
    
    return $alerts;
}

/**
 * Perform comprehensive health check
 */
function performHealthCheck($log_file) {
    $tracker = PerformanceTracker::getInstance();
    
    $health = [
        'overall_status' => 'healthy',
        'checks' => [],
        'current_metrics' => [
            'cpu' => $tracker->getCpuUsage(),
            'memory' => $tracker->getMemoryUsage()
        ],
        'recent_performance' => generatePerformanceSummary($log_file, 1)
    ];
    
    // Check CPU load
    $cpu = $health['current_metrics']['cpu'];
    if ($cpu && isset($cpu['load_1min']) && isset($cpu['cpu_count'])) {
        $load_per_cpu = $cpu['load_1min'] / $cpu['cpu_count'];
        $health['checks']['cpu'] = [
            'status' => $load_per_cpu > 0.8 ? 'warning' : 'ok',
            'load_per_cpu' => round($load_per_cpu, 2),
            'message' => $load_per_cpu > 0.8 ? 'High CPU load detected' : 'CPU load normal'
        ];
        
        if ($load_per_cpu > 0.8) {
            $health['overall_status'] = 'degraded';
        }
    }
    
    // Check memory usage
    $memory = $health['current_metrics']['memory'];
    $health['checks']['memory'] = [
        'status' => $memory['usage_percent'] > 90 ? 'critical' : ($memory['usage_percent'] > 75 ? 'warning' : 'ok'),
        'usage_percent' => $memory['usage_percent'],
        'message' => $memory['usage_percent'] > 90 ? 'Critical memory usage' : 
                    ($memory['usage_percent'] > 75 ? 'High memory usage' : 'Memory usage normal')
    ];
    
    if ($memory['usage_percent'] > 90) {
        $health['overall_status'] = 'critical';
    } elseif ($memory['usage_percent'] > 75 && $health['overall_status'] === 'healthy') {
        $health['overall_status'] = 'degraded';
    }
    
    // Check response times
    $perf = $health['recent_performance'];
    if (isset($perf['response_times']['avg_ms'])) {
        $avg_response = $perf['response_times']['avg_ms'];
        $health['checks']['response_time'] = [
            'status' => $avg_response > 2000 ? 'warning' : 'ok',
            'avg_response_ms' => $avg_response,
            'message' => $avg_response > 2000 ? 'Slow response times detected' : 'Response times normal'
        ];
        
        if ($avg_response > 2000 && $health['overall_status'] === 'healthy') {
            $health['overall_status'] = 'degraded';
        }
    }
    
    // Check slow queries
    if (isset($perf['database_queries']['total_slow_queries'])) {
        $slow_queries = $perf['database_queries']['total_slow_queries'];
        $health['checks']['database'] = [
            'status' => $slow_queries > 5 ? 'warning' : 'ok',
            'slow_queries_count' => $slow_queries,
            'message' => $slow_queries > 5 ? 'Multiple slow queries detected' : 'Database performance normal'
        ];
        
        if ($slow_queries > 5 && $health['overall_status'] === 'healthy') {
            $health['overall_status'] = 'degraded';
        }
    }
    
    return $health;
}

/**
 * Helper functions
 */

function calculateMedian($values) {
    sort($values);
    $count = count($values);
    $middle = floor($count / 2);
    
    if ($count % 2 == 0) {
        return ($values[$middle - 1] + $values[$middle]) / 2;
    }
    
    return $values[$middle];
}

function calculatePercentile($values, $percentile) {
    sort($values);
    $index = ceil((count($values) * $percentile) / 100) - 1;
    return $values[max(0, $index)];
}

function calculateAverageQueryTime($data) {
    $total_time = 0;
    $total_queries = 0;
    
    foreach ($data as $entry) {
        $total_time += $entry['database_queries']['total_time_ms'];
        $total_queries += $entry['database_queries']['count'];
    }
    
    return $total_queries > 0 ? round($total_time / $total_queries, 2) : 0;
}

function calculateCachePerformance($data) {
    $total_hits = 0;
    $total_misses = 0;
    
    foreach ($data as $entry) {
        $total_hits += $entry['cache_operations']['hits'];
        $total_misses += $entry['cache_operations']['misses'];
    }
    
    $total_operations = $total_hits + $total_misses;
    
    return [
        'total_operations' => $total_operations,
        'hits' => $total_hits,
        'misses' => $total_misses,
        'hit_rate_percent' => $total_operations > 0 ? round(($total_hits / $total_operations) * 100, 2) : 0
    ];
}

function aggregatePerformanceFlags($data) {
    $flags = [];
    
    foreach ($data as $entry) {
        foreach ($entry['performance_flags'] ?? [] as $flag) {
            if (!isset($flags[$flag])) {
                $flags[$flag] = 0;
            }
            $flags[$flag]++;
        }
    }
    
    return $flags;
}

function determineSeverity($flag) {
    $high_severity = ['VERY_SLOW_RESPONSE', 'HIGH_MEMORY_USAGE', 'HIGH_CPU_LOAD'];
    $medium_severity = ['SLOW_RESPONSE', 'ELEVATED_MEMORY_USAGE', 'SLOW_QUERIES_DETECTED'];
    
    if (in_array($flag, $high_severity)) {
        return 'high';
    } elseif (in_array($flag, $medium_severity)) {
        return 'medium';
    }
    
    return 'low';
}
