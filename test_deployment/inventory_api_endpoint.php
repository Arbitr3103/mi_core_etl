<?php
/**
 * API endpoint для работы с остатками товаров
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once 'InventoryAPI_Fixed.php';

try {
    // Подключение к базе данных
    $api = new InventoryAPI_Fixed('localhost', 'mi_core_db', 'mi_core_user', 'secure_password_123');
    
    $action = $_GET['action'] ?? 'inventory';
    
    switch ($action) {
        case 'inventory':
            // Получить остатки с фильтрацией
            $response = $api->getInventoryAPIResponse($_GET);
            break;
            
        case 'summary':
            // Сводная статистика
            $response = [
                'success' => true,
                'data' => $api->getInventorySummary()
            ];
            break;
            
        case 'warehouses':
            // Список складов
            $marketplace = $_GET['marketplace'] ?? null;
            $response = [
                'success' => true,
                'data' => $api->getWarehouses($marketplace)
            ];
            break;
            
        case 'warehouse_stats':
            // Статистика по складам
            $marketplace = $_GET['marketplace'] ?? null;
            if (!$marketplace) {
                throw new Exception('Marketplace parameter is required');
            }
            $response = [
                'success' => true,
                'data' => $api->getWarehouseStats($marketplace)
            ];
            break;
            
        case 'top_products':
            // Топ товары по остаткам
            $marketplace = $_GET['marketplace'] ?? null;
            $limit = intval($_GET['limit'] ?? 10);
            $response = [
                'success' => true,
                'data' => $api->getTopProductsByStock($marketplace, $limit)
            ];
            break;
            
        case 'critical_stock':
            // Критические остатки
            $marketplace = $_GET['marketplace'] ?? null;
            $threshold = intval($_GET['threshold'] ?? 5);
            $response = [
                'success' => true,
                'data' => $api->getCriticalStock($marketplace, $threshold)
            ];
            break;
            
        case 'export_csv':
            // Экспорт в CSV
            $marketplace = $_GET['marketplace'] ?? null;
            $warehouse = $_GET['warehouse'] ?? null;
            $search = $_GET['search'] ?? null;
            $stockLevel = $_GET['stock_level'] ?? null;
            
            $export = $api->exportInventoryToCSV($marketplace, $warehouse, $search, $stockLevel);
            
            if ($export['success']) {
                // Отправляем файл на скачивание
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
                header('Content-Length: ' . filesize($export['filepath']));
                
                readfile($export['filepath']);
                unlink($export['filepath']); // Удаляем временный файл
                exit;
            } else {
                $response = $export;
            }
            break;
            
        case 'clear_cache':
            // Очистка кэша
            $api->clearCache();
            $response = [
                'success' => true,
                'message' => 'Cache cleared successfully'
            ];
            break;
            
        case 'performance_test':
            // Тест производительности
            $startTime = microtime(true);
            $summary = $api->getInventorySummary();
            $summaryTime = microtime(true) - $startTime;
            
            $startTime = microtime(true);
            $inventory = $api->getInventoryByMarketplace(null, null, null, null, 100, 0);
            $inventoryTime = microtime(true) - $startTime;
            
            $response = [
                'success' => true,
                'performance' => [
                    'summary_query_time' => round($summaryTime * 1000, 2) . ' ms',
                    'inventory_query_time' => round($inventoryTime * 1000, 2) . ' ms',
                    'total_records' => count($inventory),
                    'cache_enabled' => true
                ]
            ];
            break;
            
        case 'export_summary_csv':
            // Экспорт сводки в CSV
            $export = $api->exportSummaryToCSV();
            
            if ($export['success']) {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
                header('Content-Length: ' . filesize($export['filepath']));
                
                readfile($export['filepath']);
                unlink($export['filepath']);
                exit;
            } else {
                $response = $export;
            }
            break;
            
        default:
            throw new Exception('Unknown action: ' . $action);
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>