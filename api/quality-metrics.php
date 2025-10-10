<?php
/**
 * Quality Metrics API Endpoint
 * 
 * Provides real-time quality metrics for the dashboard
 * Requirements: 8.3, 4.3
 */

require_once '../config.php';
require_once '../src/DataQualityMonitor.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

try {
    // Initialize monitor
    $monitor = new DataQualityMonitor($pdo);
    
    // Get action from request
    $action = $_GET['action'] ?? 'metrics';
    
    switch ($action) {
        case 'metrics':
            // Get comprehensive quality metrics
            $metrics = $monitor->getQualityMetrics();
            $recentAlerts = $monitor->getRecentAlerts(5);
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'metrics' => $metrics,
                    'recent_alerts' => $recentAlerts
                ],
                'timestamp' => date('c')
            ]);
            break;
            
        case 'check':
            // Run quality checks and return results
            $result = $monitor->runQualityChecks();
            
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'timestamp' => date('c')
            ]);
            break;
            
        case 'alerts':
            // Get recent alerts
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $alerts = $monitor->getRecentAlerts($limit);
            
            echo json_encode([
                'status' => 'success',
                'data' => $alerts,
                'timestamp' => date('c')
            ]);
            break;
            
        case 'alert-stats':
            // Get alert statistics
            $stats = $monitor->getAlertStats();
            
            echo json_encode([
                'status' => 'success',
                'data' => $stats,
                'timestamp' => date('c')
            ]);
            break;
            
        case 'sync-status':
            // Get sync status only
            $stmt = $pdo->query("
                SELECT 
                    sync_status,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM product_cross_reference), 2) as percentage
                FROM product_cross_reference
                GROUP BY sync_status
            ");
            
            $status = [];
            foreach ($stmt->fetchAll() as $row) {
                $status[$row['sync_status']] = [
                    'count' => (int)$row['count'],
                    'percentage' => (float)$row['percentage']
                ];
            }
            
            echo json_encode([
                'status' => 'success',
                'data' => $status,
                'timestamp' => date('c')
            ]);
            break;
            
        case 'health':
            // Quick health check
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN sync_status = 'failed' THEN 1 END) as failed,
                    ROUND(COUNT(CASE WHEN sync_status = 'failed' THEN 1 END) * 100.0 / COUNT(*), 2) as failed_percentage
                FROM product_cross_reference
            ");
            $health = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $healthStatus = 'healthy';
            if ($health['failed_percentage'] > 10) {
                $healthStatus = 'unhealthy';
            } elseif ($health['failed_percentage'] > 5) {
                $healthStatus = 'degraded';
            }
            
            echo json_encode([
                'status' => 'success',
                'data' => [
                    'health_status' => $healthStatus,
                    'total_products' => (int)$health['total'],
                    'failed_products' => (int)$health['failed'],
                    'failed_percentage' => (float)$health['failed_percentage']
                ],
                'timestamp' => date('c')
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid action',
                'available_actions' => ['metrics', 'check', 'alerts', 'alert-stats', 'sync-status', 'health']
            ]);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
