<?php
/**
 * Проверка здоровья MDM системы для продакшена
 */

header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'checks' => []
];

// Проверка базы данных
try {
    require_once 'config.php';
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
    $stmt = $pdo->query("SELECT COUNT(*) FROM dim_products");
    $count = $stmt->fetchColumn();
    
    $health['checks']['database'] = [
        'status' => 'healthy',
        'products_count' => $count,
        'response_time_ms' => 0
    ];
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['database'] = [
        'status' => 'unhealthy',
        'error' => $e->getMessage()
    ];
}

// Проверка API
$start = microtime(true);
try {
    ob_start();
    include 'api/analytics.php';
    $output = ob_get_clean();
    $response_time = round((microtime(true) - $start) * 1000);
    
    $health['checks']['api'] = [
        'status' => 'healthy',
        'response_time_ms' => $response_time
    ];
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['api'] = [
        'status' => 'unhealthy',
        'error' => $e->getMessage()
    ];
}

echo json_encode($health, JSON_PRETTY_PRINT);
?>
