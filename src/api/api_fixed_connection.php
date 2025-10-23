<?php
/**
 * API для аналитики складских остатков - исправленная версия
 * Прямое подключение к базе данных без зависимости от config.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Прямое подключение к базе данных
function getDatabaseConnection() {
    try {
        $dsn = "mysql:host=localhost;port=3306;dbname=mi_core;charset=utf8mb4";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, 'v_admin', 'Arbitr09102022!', $options);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }
}

// Функция для логирования ошибок
function logError($message, $context = []) {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context
    ];
    
    error_log(json_encode($logEntry));
}

// Функция для получения данных дашборда
function getInventoryDashboardData($pdo) {
    try {
        // Получаем данные из inventory_data
        $stmt = $pdo->prepare("
            SELECT 
                i.sku,
                i.warehouse_name,
                SUM(i.current_stock) as total_stock,
                SUM(i.available_stock) as available_stock,
                SUM(i.reserved_stock) as reserved_stock,
                MAX(i.last_sync_at) as last_updated,
                CASE
                    WHEN SUM(i.current_stock) <= 5 THEN 'critical'
                    WHEN SUM(i.current_stock) > 5 AND SUM(i.current_stock) <= 20 THEN 'low'
                    WHEN SUM(i.current_stock) > 100 THEN 'overstock'
                    ELSE 'normal'
                END as stock_status
            FROM inventory_data i
            WHERE i.current_stock IS NOT NULL
            GROUP BY i.sku, i.warehouse_name
            ORDER BY SUM(i.current_stock) ASC
        ");
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            return [
                'critical_stock_count' => 0,
                'low_stock_count' => 0,
                'overstock_count' => 0,
                'normal_count' => 0,
                'total_inventory_value' => 0,
                'critical_products' => [],
                'low_stock_products' => [],
                'overstock_products' => [],
                'warehouses_summary' => [],
                'recommendations' => []
            ];
        }

        // Группируем товары по статусу
        $critical_products = [];
        $low_stock_products = [];
        $overstock_products = [];
        $normal_products = [];
        $warehouses_summary = [];
        
        foreach ($products as $product) {
            $product_name = 'Товар ' . $product['sku'];
            
            // Группировка по складам
            if (!isset($warehouses_summary[$product['warehouse_name']])) {
                $warehouses_summary[$product['warehouse_name']] = [
                    'warehouse_name' => $product['warehouse_name'],
                    'total_products' => 0,
                    'total_stock' => 0,
                    'critical_count' => 0,
                    'low_count' => 0,
                    'overstock_count' => 0
                ];
            }
            
            $warehouses_summary[$product['warehouse_name']]['total_products']++;
            $warehouses_summary[$product['warehouse_name']]['total_stock'] += $product['total_stock'];
            
            $product_data = [
                'name' => $product_name,
                'sku' => $product['sku'],
                'stock' => (int)$product['total_stock'],
                'available_stock' => (int)$product['available_stock'],
                'reserved_stock' => (int)$product['reserved_stock'],
                'warehouse' => $product['warehouse_name'],
                'unit_cost' => 0,
                'last_updated' => $product['last_updated']
            ];
            
            switch ($product['stock_status']) {
                case 'critical':
                    $critical_products[] = $product_data;
                    $warehouses_summary[$product['warehouse_name']]['critical_count']++;
                    break;
                    
                case 'low':
                    $low_stock_products[] = $product_data;
                    $warehouses_summary[$product['warehouse_name']]['low_count']++;
                    break;
                    
                case 'overstock':
                    $overstock_products[] = $product_data;
                    $warehouses_summary[$product['warehouse_name']]['overstock_count']++;
                    break;
                    
                default:
                    $normal_products[] = $product_data;
            }
        }
        
        // Генерируем рекомендации
        $recommendations = [];
        
        if (count($critical_products) > 0) {
            $recommendations[] = [
                'type' => 'urgent',
                'title' => 'Срочное пополнение',
                'message' => 'У вас ' . count($critical_products) . ' товаров с критическими остатками (≤5 единиц). Требуется срочное пополнение.'
            ];
        }
        
        if (count($overstock_products) > 0) {
            $recommendations[] = [
                'type' => 'optimization',
                'title' => 'Проведение акций',
                'message' => 'У вас ' . count($overstock_products) . ' товаров с избытком (>100 единиц). Рекомендуется провести акции.'
            ];
        }
        
        if (count($low_stock_products) > 0) {
            $recommendations[] = [
                'type' => 'planning',
                'title' => 'Плановое пополнение',
                'message' => 'У вас ' . count($low_stock_products) . ' товаров с низкими остатками (6-20 единиц). Запланируйте пополнение.'
            ];
        }
        
        return [
            'critical_stock_count' => count($critical_products),
            'low_stock_count' => count($low_stock_products),
            'overstock_count' => count($overstock_products),
            'normal_count' => count($normal_products),
            'total_inventory_value' => 0,
            'critical_products' => array_slice($critical_products, 0, 10),
            'low_stock_products' => array_slice($low_stock_products, 0, 10),
            'overstock_products' => array_slice($overstock_products, 0, 10),
            'warehouses_summary' => array_values($warehouses_summary),
            'recommendations' => $recommendations
        ];
        
    } catch (Exception $e) {
        logError("Error in getInventoryDashboardData", ['error' => $e->getMessage()]);
        throw $e;
    }
}

try {
    $pdo = getDatabaseConnection();
    
    $action = $_GET['action'] ?? 'dashboard';
    
    switch ($action) {
        case 'dashboard':
            $data = getInventoryDashboardData($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $data,
                'metadata' => [
                    'generated_at' => date('Y-m-d H:i:s'),
                    'api_version' => 'fixed'
                ]
            ]);
            break;
            
        case 'critical-products':
            $data = getInventoryDashboardData($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $data['critical_products'],
                'metadata' => [
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'overstock-products':
            $data = getInventoryDashboardData($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $data['overstock_products'],
                'metadata' => [
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Неподдерживаемое действие: ' . $action
            ]);
    }
    
} catch (Exception $e) {
    logError("API Error", ['error' => $e->getMessage()]);
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Внутренняя ошибка сервера',
        'details' => $e->getMessage()
    ]);
}
?>