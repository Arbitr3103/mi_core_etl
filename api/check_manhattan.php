<?php
/**
 * Временный endpoint для проверки данных Manhattan
 * GET /api/check_manhattan.php
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
    $pdo = $api->db->getCoreConnection();
    
    $result = [
        'success' => true,
        'check_date' => date('Y-m-d H:i:s'),
        'period' => '2025-09-22 to 2025-09-28',
        'expected_load_time' => '2025-09-29 03:00',
        'data' => []
    ];
    
    // 1. Проверяем общую статистику fact_orders
    $stmt = $pdo->query("
        SELECT COUNT(*) as total_orders,
               MIN(created_at) as earliest_date,
               MAX(created_at) as latest_date
        FROM fact_orders
    ");
    $stats = $stmt->fetch();
    $result['data']['general_stats'] = $stats;
    
    // 2. Данные за нужный период по источникам
    $stmt = $pdo->query("
        SELECT 
            COALESCE(source, 'Unknown') as source,
            COUNT(*) as orders_count,
            DATE(created_at) as order_date
        FROM fact_orders 
        WHERE DATE(created_at) BETWEEN '2025-09-22' AND '2025-09-28'
        GROUP BY source, DATE(created_at)
        ORDER BY order_date DESC, source
    ");
    $periodData = $stmt->fetchAll();
    $result['data']['period_data'] = $periodData;
    
    // 3. Проверяем загрузку 29.09
    $stmt = $pdo->query("
        SELECT 
            COALESCE(source, 'Unknown') as source,
            COUNT(*) as records_loaded,
            DATE(created_at) as data_date,
            HOUR(created_at) as load_hour
        FROM fact_orders 
        WHERE DATE(created_at) = '2025-09-29'
        GROUP BY source, DATE(created_at), HOUR(created_at)
        ORDER BY load_hour
    ");
    $loadData = $stmt->fetchAll();
    $result['data']['load_data_29_09'] = $loadData;
    
    // 4. Специальная проверка для Wildberries и Ozon
    $stmt = $pdo->query("
        SELECT 
            CASE 
                WHEN source LIKE '%wildberries%' OR source LIKE '%wb%' OR source LIKE '%вб%' THEN 'Wildberries'
                WHEN source LIKE '%ozon%' OR source LIKE '%озон%' THEN 'Ozon'
                ELSE source
            END as platform,
            COUNT(*) as orders_count,
            MIN(DATE(created_at)) as first_date,
            MAX(DATE(created_at)) as last_date
        FROM fact_orders 
        WHERE DATE(created_at) BETWEEN '2025-09-22' AND '2025-09-28'
        GROUP BY platform
        ORDER BY orders_count DESC
    ");
    $platformData = $stmt->fetchAll();
    $result['data']['platform_summary'] = $platformData;
    
    // 5. Итоговая оценка
    $wbCount = 0;
    $ozonCount = 0;
    foreach ($platformData as $platform) {
        if ($platform['platform'] === 'Wildberries') {
            $wbCount = $platform['orders_count'];
        } elseif ($platform['platform'] === 'Ozon') {
            $ozonCount = $platform['orders_count'];
        }
    }
    
    $result['data']['summary'] = [
        'wildberries_orders' => $wbCount,
        'ozon_orders' => $ozonCount,
        'total_period_orders' => array_sum(array_column($periodData, 'orders_count')),
        'data_loaded' => ($wbCount > 0 || $ozonCount > 0),
        'load_status' => ($wbCount > 0 && $ozonCount > 0) ? 'complete' : (($wbCount > 0 || $ozonCount > 0) ? 'partial' : 'missing')
    ];
    
    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка проверки данных: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>