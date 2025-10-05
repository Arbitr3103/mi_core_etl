<?php
/**
 * API endpoint для получения списка моделей автомобилей
 * GET /api/models.php?brand_id={brandId} - модели для конкретной марки
 * GET /api/models.php - все модели
 */

require_once '../CountryFilterAPI.php';

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

try {
    $api = new CountryFilterAPI();
    $brandId = isset($_GET['brand_id']) && $_GET['brand_id'] !== '' ? $_GET['brand_id'] : null;
    
    // Валидация brand_id если указан
    if ($brandId !== null) {
        if (!is_numeric($brandId) || (int)$brandId <= 0 || (int)$brandId > 999999) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Некорректный brand_id: должен быть положительным числом',
                'error_type' => 'invalid_parameter'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
        
        // Проверяем существование марки
        $checkStmt = $api->db->getCoreConnection()->prepare("SELECT COUNT(*) as count FROM brands WHERE id = ?");
        $checkStmt->execute([(int)$brandId]);
        if ($checkStmt->fetch()['count'] == 0) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'Марка с указанным ID не найдена',
                'error_type' => 'not_found'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }
    
    // Формируем SQL запрос
    if ($brandId !== null) {
        // Модели для конкретной марки
        $sql = "SELECT DISTINCT 
                    cm.id,
                    cm.name,
                    b.name as brand_name
                FROM car_models cm
                INNER JOIN brands b ON cm.brand_id = b.id
                WHERE cm.brand_id = :brand_id 
                  AND cm.name IS NOT NULL AND cm.name != '' 
                ORDER BY cm.name ASC";
        
        $stmt = $api->db->getCoreConnection()->prepare($sql);
        $stmt->execute(['brand_id' => (int)$brandId]);
    } else {
        // Все модели
        $sql = "SELECT DISTINCT 
                    cm.id,
                    cm.name,
                    b.name as brand_name
                FROM car_models cm
                INNER JOIN brands b ON cm.brand_id = b.id
                WHERE cm.name IS NOT NULL AND cm.name != '' 
                ORDER BY b.name ASC, cm.name ASC
                LIMIT 1000";
        
        $stmt = $api->db->getCoreConnection()->prepare($sql);
        $stmt->execute();
    }
    
    $results = $stmt->fetchAll();
    
    if (empty($results)) {
        $message = $brandId !== null 
            ? 'Для данной марки не найдено моделей автомобилей'
            : 'В системе не найдено моделей автомобилей';
            
        echo json_encode([
            'success' => true,
            'data' => [],
            'message' => $message
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    $models = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'brand_name' => $row['brand_name']
        ];
    }, $results);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $models,
        'filters_applied' => [
            'brand_id' => $brandId ? (int)$brandId : null
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>