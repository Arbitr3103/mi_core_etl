<?php
/**
 * API endpoint для получения списка годов выпуска автомобилей
 * GET /api/years.php?brand_id={brandId}&model_id={modelId} - годы для конкретной марки/модели
 * GET /api/years.php - все доступные годы
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
    $modelId = isset($_GET['model_id']) && $_GET['model_id'] !== '' ? $_GET['model_id'] : null;
    
    // Валидация параметров
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
    }
    
    if ($modelId !== null) {
        if (!is_numeric($modelId) || (int)$modelId <= 0 || (int)$modelId > 999999) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Некорректный model_id: должен быть положительным числом',
                'error_type' => 'invalid_parameter'
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }
    
    // Формируем SQL запрос для получения годов из car_specifications
    $sql = "SELECT DISTINCT 
                year_start,
                year_end
            FROM car_specifications cs";
    
    $params = [];
    $conditions = [];
    
    if ($modelId !== null) {
        $conditions[] = "cs.car_model_id = :model_id";
        $params['model_id'] = (int)$modelId;
    } elseif ($brandId !== null) {
        $sql .= " INNER JOIN car_models cm ON cs.car_model_id = cm.id";
        $conditions[] = "cm.brand_id = :brand_id";
        $params['brand_id'] = (int)$brandId;
    }
    
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(' AND ', $conditions);
    }
    
    $sql .= " ORDER BY year_start ASC, year_end ASC";
    
    $stmt = $api->db->getCoreConnection()->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll();
    
    if (empty($results)) {
        $message = 'Не найдено годов выпуска для указанных параметров';
        echo json_encode([
            'success' => true,
            'data' => [],
            'message' => $message
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    // Собираем все уникальные годы из диапазонов
    $years = [];
    $currentYear = (int)date('Y');
    
    foreach ($results as $row) {
        $yearStart = $row['year_start'] ? (int)$row['year_start'] : 1990;
        $yearEnd = $row['year_end'] ? (int)$row['year_end'] : $currentYear;
        
        // Ограничиваем разумными пределами
        $yearStart = max(1990, min($yearStart, $currentYear + 2));
        $yearEnd = max($yearStart, min($yearEnd, $currentYear + 2));
        
        for ($year = $yearStart; $year <= $yearEnd; $year++) {
            if (!in_array($year, $years)) {
                $years[] = $year;
            }
        }
    }
    
    // Сортируем годы по убыванию (новые сначала)
    rsort($years);
    
    // Ограничиваем количество годов
    $years = array_slice($years, 0, 50);
    
    // Форматируем результат
    $formattedYears = array_map(function($year) {
        return [
            'id' => $year,
            'name' => (string)$year,
            'value' => $year
        ];
    }, $years);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $formattedYears,
        'filters_applied' => [
            'brand_id' => $brandId ? (int)$brandId : null,
            'model_id' => $modelId ? (int)$modelId : null
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