<?php
/**
 * API endpoint для получения стран по модели автомобиля
 * GET /api/countries-by-model.php?model_id={modelId}
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
    if (!isset($_GET['model_id']) || $_GET['model_id'] === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не указан параметр model_id',
            'error_type' => 'missing_parameter'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $modelId = $_GET['model_id'];
    
    // Валидация параметра
    if (!is_numeric($modelId) || (int)$modelId <= 0 || (int)$modelId > 999999) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Некорректный model_id: должен быть положительным числом',
            'error_type' => 'invalid_parameter'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $api = new CountryFilterAPI();
    $result = $api->getCountriesByModel($modelId);
    
    if ($result['success']) {
        http_response_code(200);
    } else {
        http_response_code(400);
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>