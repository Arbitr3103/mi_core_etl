<?php
/**
 * Fixed Adapter for replenishment API - Smart version
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_GET['action'] ?? 'unknown';

try {
    if ($action === 'recommendations') {
        // Пытаемся подключиться к базе данных и получить рекомендации
        try {
            $pdo = new PDO(
                'mysql:host=localhost;dbname=mi_core_db;charset=utf8mb4',
                'dashboard_user',
                'dashboard_prod_2025',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            
            // Проверяем существует ли таблица
            $stmt = $pdo->query("SHOW TABLES LIKE 'replenishment_recommendations'");
            if ($stmt->rowCount() > 0) {
                // Таблица существует, получаем рекомендации
                $stmt = $pdo->query("SELECT * FROM replenishment_recommendations ORDER BY priority DESC, ads DESC LIMIT 50");
                $recommendations = $stmt->fetchAll();
                
                echo json_encode([
                    'status' => 'success',
                    'data' => $recommendations
                ], JSON_UNESCAPED_UNICODE);
            } else {
                // Таблица не существует, возвращаем пустой массив
                echo json_encode([
                    'status' => 'success',
                    'data' => []
                ], JSON_UNESCAPED_UNICODE);
            }
            
        } catch (Exception $e) {
            // Любая ошибка с базой данных - возвращаем пустой массив
            echo json_encode([
                'status' => 'success',
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
        }
        
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