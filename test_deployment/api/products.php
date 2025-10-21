<?php
/**
 * API endpoint для фильтрации товаров с поддержкой фильтра по стране
 * GET /api/products-filter.php?brand_id={brandId}&model_id={modelId}&year={year}&country_id={countryId}&limit={limit}&offset={offset}
 * 
 * Также доступен как /api/products.php
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
    // Собираем параметры фильтрации
    $filters = [
        'brand_id' => isset($_GET['brand_id']) && $_GET['brand_id'] !== '' ? $_GET['brand_id'] : null,
        'model_id' => isset($_GET['model_id']) && $_GET['model_id'] !== '' ? $_GET['model_id'] : null,
        'year' => isset($_GET['year']) && $_GET['year'] !== '' ? $_GET['year'] : null,
        'country_id' => isset($_GET['country_id']) && $_GET['country_id'] !== '' ? $_GET['country_id'] : null,
        'limit' => isset($_GET['limit']) && $_GET['limit'] !== '' ? (int)$_GET['limit'] : 100,
        'offset' => isset($_GET['offset']) && $_GET['offset'] !== '' ? (int)$_GET['offset'] : 0
    ];
    
    $api = new CountryFilterAPI();
    
    // Валидация параметров
    $validation = $api->validateFilters($filters);
    if (!$validation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Ошибки валидации параметров: ' . implode(', ', $validation['errors']),
            'error_type' => 'validation_error'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Валидация существования записей в БД
    $existenceValidation = $api->validateFilterExistence($filters);
    if (!$existenceValidation['valid']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Ошибки валидации данных: ' . implode(', ', $existenceValidation['errors']),
            'error_type' => 'data_validation_error'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Выполняем фильтрацию
    $result = $api->filterProducts($filters);
    
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