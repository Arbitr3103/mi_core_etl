<?php

/**
 * Quality Monitoring Dashboard Entry Point
 * Routes requests to appropriate controllers and handles API endpoints
 */

require_once __DIR__ . '/Controllers/QualityMonitoringController.php';

use MDM\Controllers\QualityMonitoringController;

// Enable error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set content type for JSON responses
if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    header('Content-Type: application/json');
}

try {
    $controller = new QualityMonitoringController();
    
    // Parse the request URI
    $requestUri = $_SERVER['REQUEST_URI'];
    $path = parse_url($requestUri, PHP_URL_PATH);
    
    // Remove base path if present
    $basePath = '/mdm';
    if (strpos($path, $basePath) === 0) {
        $path = substr($path, strlen($basePath));
    }
    
    // Route the request
    switch ($path) {
        case '/quality-monitoring':
        case '/quality':
            $controller->dashboard();
            break;
            
        case '/api/metrics':
            $controller->getMetrics();
            break;
            
        case '/api/quality-trends':
            $controller->getTrends();
            break;
            
        case '/api/alerts':
            $controller->getAlerts();
            break;
            
        case '/api/performance-metrics':
            $controller->getPerformanceMetrics();
            break;
            
        case '/api/matching-trends':
            $controller->getMatchingTrends();
            break;
            
        case '/api/force-quality-check':
            $controller->forceQualityCheck();
            break;
            
        case '/api/update-metrics':
            $controller->updateMetrics();
            break;
            
        case '/api/report':
            $controller->getReport();
            break;
            
        case '/api/problematic-products':
            $controller->getProblematicProducts();
            break;
            
        case '/api/scheduler/status':
            $controller->getSchedulerStatus();
            break;
            
        case '/api/scheduler/toggle':
            $controller->toggleSchedulerCheck();
            break;
            
        default:
            // Default to dashboard
            if (empty($path) || $path === '/') {
                $controller->dashboard();
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Endpoint not found']);
            }
            break;
    }
    
} catch (Exception $e) {
    error_log("Quality Monitoring Error: " . $e->getMessage());
    
    if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
        http_response_code(500);
        echo json_encode([
            'error' => true,
            'message' => 'Internal server error',
            'details' => $e->getMessage()
        ]);
    } else {
        // Show error page for web requests
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Ошибка - Мониторинг качества данных</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    background-color: #f8fafc;
                    color: #1e293b;
                    padding: 40px 20px;
                    text-align: center;
                }
                .error-container {
                    max-width: 600px;
                    margin: 0 auto;
                    background: white;
                    padding: 40px;
                    border-radius: 12px;
                    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
                }
                .error-title {
                    font-size: 2rem;
                    font-weight: 700;
                    color: #ef4444;
                    margin-bottom: 20px;
                }
                .error-message {
                    font-size: 1.1rem;
                    color: #64748b;
                    margin-bottom: 30px;
                }
                .error-details {
                    background: #f1f5f9;
                    padding: 20px;
                    border-radius: 8px;
                    text-align: left;
                    font-family: monospace;
                    font-size: 0.9rem;
                    color: #475569;
                    margin-bottom: 30px;
                }
                .back-button {
                    display: inline-block;
                    padding: 12px 24px;
                    background-color: #2563eb;
                    color: white;
                    text-decoration: none;
                    border-radius: 6px;
                    font-weight: 500;
                    transition: background-color 0.2s;
                }
                .back-button:hover {
                    background-color: #1d4ed8;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-title">Ошибка системы</div>
                <div class="error-message">
                    Произошла ошибка при загрузке панели мониторинга качества данных.
                </div>
                <div class="error-details">
                    <?= htmlspecialchars($e->getMessage()) ?>
                </div>
                <a href="/mdm" class="back-button">Вернуться к главной</a>
            </div>
        </body>
        </html>
        <?php
    }
}
?>