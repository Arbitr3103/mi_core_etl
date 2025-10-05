<?php
/**
 * API endpoint для получения списка всех марок автомобилей
 * GET /api/brands.php
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
    
    // Получаем все марки из базы данных
    $sql = "SELECT DISTINCT 
                id,
                name
            FROM brands 
            WHERE name IS NOT NULL AND name != '' 
            ORDER BY name ASC";
    
    $stmt = $api->db->getCoreConnection()->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll();
    
    if (empty($results)) {
        echo json_encode([
            'success' => true,
            'data' => [],
            'message' => 'В системе не найдено марок автомобилей'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    $brands = array_map(function($row) {
        return [
            'id' => (int)$row['id'],
            'name' => $row['name']
        ];
    }, $results);
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $brands
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>