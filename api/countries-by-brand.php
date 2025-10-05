<?php
/**
 * API endpoint для получения стран по марке автомобиля
 * GET /api/countries-by-brand.php?brand_id={brandId}
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
    if (!isset($_GET['brand_id']) || $_GET['brand_id'] === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Не указан параметр brand_id',
            'error_type' => 'missing_parameter'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $brandId = $_GET['brand_id'];
    
    // Валидация параметра
    if (!is_numeric($brandId) || (int)$brandId <= 0 || (int)$brandId > 999999) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Некорректный brand_id: должен быть положительным числом',
            'error_type' => 'invalid_parameter'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    $api = new CountryFilterAPI();
    $result = $api->getCountriesByBrand($brandId);
    
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