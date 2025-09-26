<?php
/**
 * Endpoint для получения стран по модели
 */

require_once 'CountryFilterAPI.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

try {
    $api = new CountryFilterAPI();
    
    if (!isset($_GET['model_id'])) {
        echo json_encode([
            'success' => false, 
            'error' => 'Не указан ID модели'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $result = $api->getCountriesByModel($_GET['model_id']);
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>