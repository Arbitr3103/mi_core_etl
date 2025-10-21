<?php
/**
 * Fixed Adapter for replenishment API
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? 'unknown';

try {
    // Подключаемся напрямую к базе данных (как в основном API)
    $pdo = new PDO(
        'mysql:host=localhost;dbname=mi_core_db;charset=utf8mb4',
        'dashboard_user',
        'dashboard_prod_2025',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    if ($action === 'recommendations') {
        // Получаем рекомендации
        $stmt = $pdo->query('SELECT * FROM replenishment_recommendations ORDER BY priority DESC, ads DESC LIMIT 50');
        $recommendations = $stmt->fetchAll();
        
        echo json_encode([
            'status' => 'success',
            'data' => $recommendations
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Неподдерживаемое действие: ' . $action
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>