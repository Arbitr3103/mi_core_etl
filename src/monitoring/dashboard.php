<?php
/**
 * Ozon ETL System Monitoring Dashboard - Web Interface
 * 
 * Веб-интерфейс для мониторинга системы ETL процессов Ozon Stock Reports.
 * Предоставляет real-time данные о состоянии системы, процессах и метриках.
 * 
 * Usage:
 *   http://domain.com/monitoring/dashboard.php
 *   http://domain.com/monitoring/dashboard.php?api=1 (JSON API)
 *   http://domain.com/monitoring/dashboard.php?export=prometheus
 * 
 * @version 1.0
 * @author Manhattan System
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Define paths
define('ROOT_DIR', dirname(dirname(__DIR__)));
define('SRC_DIR', ROOT_DIR . '/src');

try {
    // Include required files
    require_once SRC_DIR . '/config/database.php';
    require_once SRC_DIR . '/classes/OzonETLMonitor.php';
    require_once SRC_DIR . '/monitoring/SystemMonitoringDashboard.php';
    
    // Initialize database connection
    $config = include SRC_DIR . '/config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    
    // Initialize components
    $monitor = new OzonETLMonitor($pdo);
    $dashboard = new SystemMonitoringDashboard($pdo, $monitor);
    
    // Handle different request types
    if (isset($_GET['api']) && $_GET['api'] === '1') {
        // JSON API response
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        
        $data = $dashboard->getDashboardDataAPI();
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } elseif (isset($_GET['export'])) {
        // Export metrics in specified format
        $format = $_GET['export'];
        $allowedFormats = ['json', 'prometheus', 'csv'];
        
        if (!in_array($format, $allowedFormats)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid export format. Allowed: ' . implode(', ', $allowedFormats)]);
            exit;
        }
        
        // Set appropriate content type
        switch ($format) {
            case 'prometheus':
                header('Content-Type: text/plain');
                break;
            case 'csv':
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="ozon_etl_metrics_' . date('Y-m-d_H-i-s') . '.csv"');
                break;
            default:
                header('Content-Type: application/json');
        }
        
        echo $dashboard->exportMetrics($format);
        
    } else {
        // HTML dashboard
        echo $dashboard->generateDashboard();
    }
    
} catch (Exception $e) {
    // Handle errors gracefully
    if (isset($_GET['api']) || isset($_GET['export'])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'error' => 'Dashboard initialization failed',
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        // HTML error page
        echo '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Error</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background: #f5f5f5; }
        .error-container { 
            background: white; 
            padding: 2rem; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 600px;
            margin: 0 auto;
        }
        .error-title { color: #dc3545; font-size: 1.5rem; margin-bottom: 1rem; }
        .error-message { background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; }
        .error-details { margin-top: 1rem; font-size: 0.9rem; color: #6c757d; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-title">Dashboard Error</div>
        <div class="error-message">
            Failed to initialize monitoring dashboard: ' . htmlspecialchars($e->getMessage()) . '
        </div>
        <div class="error-details">
            <strong>Timestamp:</strong> ' . date('Y-m-d H:i:s') . '<br>
            <strong>File:</strong> ' . htmlspecialchars($e->getFile()) . '<br>
            <strong>Line:</strong> ' . $e->getLine() . '
        </div>
    </div>
</body>
</html>';
    }
    
    // Log the error
    error_log("[Ozon ETL Dashboard] ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}