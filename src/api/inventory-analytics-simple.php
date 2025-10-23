<?php
/**
 * API для аналитики складских остатков и маркетинговых решений
 * Упрощенная версия без кэш-менеджера для быстрого деплоя
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Функция для логирования ошибок
function logError($message, $context = []) {
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context,
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
    ];
    
    $logFile = __DIR__ . '/../logs/inventory_api_errors.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
}

// Функция для валидации подключения к базе данных
function validateDatabaseConnection($pdo) {
    try {
        $stmt = $pdo->query("SELECT 1");
        return true;
    } catch (PDOException $e) {
        logError("Database connection validation failed", ['error' => $e->getMessage()]);
        return false;
    }
}

// Функция для проверки наличия данных в inventory_data
function checkInventoryDataAvailability($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_data WHERE current_stock IS NOT NULL");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)$result['count'] > 0;
    } catch (PDOException $e) {
        logError("Failed to check inventory_data availability", ['error' => $e->getMessage()]);
        return false;
    }
}

try {
    // Получаем подключение к базе данных
    $pdo = getDatabaseConnection();
    
    // Валидируем подключение к базе данных
    if (!validateDatabaseConnection($pdo)) {
        throw new Exception("Не удалось подключиться к базе данных");
    }
    
    // Проверяем наличие данных в inventory_data
    if (!checkInventoryDataAvailability($pdo)) {
        http_response_code(503);
        echo json_encode([
            'status' => 'error',
            'error_code' => 'NO_INVENTORY_DATA',
            'message' => 'Нет данных о складских остатках',
            'details' => 'В таблице inventory_data отсутствуют данные или все значения current_stock равны NULL'
        ]);
        exit;
    }
    
    $action = $_GET['action'] ?? 'dashboard';
    
    switch ($action) {
        case 'dashboard':
            $result = getInventoryDashboardData($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => $result['metadata'] ?? []
            ]);
            break;
            
        case 'critical-products':
            $result = getCriticalProducts($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => $result['metadata'] ?? []
            ]);
            break;
            
        case 'overstock-products':
            $result = getOverstockProducts($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => $result['metadata'] ?? []
            ]);
            break;
            
        case 'warehouse-summary':
            $result = getWarehouseSummary($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => $result['metadata'] ?? []
            ]);
            break;
            
        case 'recommendations':
            $result = getDetailedRecommendations($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'metadata' => []
            ]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'error_code' => 'INVALID_ACTION',
                'message' => 'Недопустимое действие',
                'available_actions' => ['dashboard', 'critical-products', 'overstock-products', 'warehouse-summary', 'recommendations']
            ]);
    }
    
} catch (PDOException $e) {
    logError("Database error", [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'action' => $_GET['action'] ?? 'unknown'
    ]);
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'DATABASE_ERROR',
        'message' => 'Ошибка базы данных'
    ]);
} catch (Exception $e) {
    logError("General error", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'action' => $_GET['action'] ?? 'unknown'
    ]);
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error_code' => 'INTERNAL_ERROR',
        'message' => 'Внутренняя ошибка сервера'
    ]);
}

function getInventoryDashboardData($pdo) {
    try {
        // Получаем данные из inventory_data с объединением с dim_products для названий товаров
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
                    WHEN SUM(i.current_stock) <= 20 THEN 'low'
                    WHEN SUM(i.current_stock) > 100 THEN 'overstock'
                    ELSE 'normal'
                END as stock_status
            FROM inventory_data i
            WHERE i.current_stock IS NOT NULL
            GROUP BY i.sku, i.warehouse_name
            ORDER BY 
                CASE 
                    WHEN SUM(i.current_stock) <= 5 THEN 1
                    WHEN SUM(i.current_stock) <= 20 THEN 2
                    WHEN SUM(i.current_stock) > 100 THEN 3
                    ELSE 4
                END,
                SUM(i.current_stock) ASC
            LIMIT 1000
        ");
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            return [
                'data' => [
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
                ],
                'metadata' => [
                    'data_status' => 'empty',
                    'message' => 'Нет данных о складских остатках'
                ]
            ];
        }
    
        // Группируем товары по статусу
        $critical_products = [];
        $low_stock_products = [];
        $overstock_products = [];
        $normal_products = [];
        
        $total_inventory_value = 0;
        $warehouses_summary = [];
        
        foreach ($products as $product) {
            $product_name = 'Товар ' . $product['sku']; // Упрощенное название
            $unit_cost = 0; // Упрощенная стоимость
                
            $stock_value = $product['total_stock'] * $unit_cost;
            $total_inventory_value += $stock_value;
            
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
                'unit_cost' => $unit_cost,
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
        
        // Ограничиваем количество товаров для отображения
        $critical_products = array_slice($critical_products, 0, 10);
        $low_stock_products = array_slice($low_stock_products, 0, 10);
        $overstock_products = array_slice($overstock_products, 0, 10);
        
        // Генерируем рекомендации
        $recommendations = [];
        
        if (count($critical_products) > 0) {
            $recommendations[] = [
                'type' => 'urgent',
                'title' => 'Срочное пополнение',
                'message' => 'У вас ' . count($critical_products) . ' товаров с критическими остатками (≤5 единиц). Требуется срочное пополнение.',
                'action' => 'replenish_critical'
            ];
        }
        
        if (count($overstock_products) > 0) {
            $recommendations[] = [
                'type' => 'optimization',
                'title' => 'Проведение акций',
                'message' => 'У вас ' . count($overstock_products) . ' товаров с избытком (>100 единиц). Рекомендуется провести акции.',
                'action' => 'create_promotions'
            ];
        }
        
        if (count($low_stock_products) > 0) {
            $recommendations[] = [
                'type' => 'planning',
                'title' => 'Плановое пополнение',
                'message' => 'У вас ' . count($low_stock_products) . ' товаров с низкими остатками (6-20 единиц). Запланируйте пополнение.',
                'action' => 'plan_replenishment'
            ];
        }
        
        return [
            'data' => [
                'critical_stock_count' => count($critical_products),
                'low_stock_count' => count($low_stock_products),
                'overstock_count' => count($overstock_products),
                'normal_count' => count($normal_products),
                'total_inventory_value' => $total_inventory_value,
                'critical_products' => $critical_products,
                'low_stock_products' => $low_stock_products,
                'overstock_products' => $overstock_products,
                'warehouses_summary' => array_values($warehouses_summary),
                'recommendations' => $recommendations,
                'summary' => [
                    'total_products' => count($products),
                    'needs_attention' => count($critical_products) + count($low_stock_products),
                    'optimization_opportunity' => count($overstock_products),
                    'last_updated' => date('Y-m-d H:i:s')
                ]
            ],
            'metadata' => [
                'data_status' => 'success',
                'version' => 'simple'
            ]
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getInventoryDashboardData", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function getCriticalProducts($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                i.sku,
                i.warehouse_name,
                SUM(i.current_stock) as total_stock,
                SUM(i.available_stock) as available_stock,
                SUM(i.reserved_stock) as reserved_stock,
                MAX(i.last_sync_at) as last_updated
            FROM inventory_data i
            WHERE i.current_stock IS NOT NULL
            GROUP BY i.sku, i.warehouse_name
            HAVING SUM(i.current_stock) <= 5
            ORDER BY SUM(i.current_stock) ASC, i.sku
            LIMIT 50
        ");
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($products as $product) {
            $result[] = [
                'name' => 'Товар ' . $product['sku'],
                'sku' => $product['sku'],
                'stock' => (int)$product['total_stock'],
                'available_stock' => (int)$product['available_stock'],
                'reserved_stock' => (int)$product['reserved_stock'],
                'warehouse' => $product['warehouse_name'],
                'unit_cost' => 0,
                'last_updated' => $product['last_updated'],
                'urgency' => $product['total_stock'] == 0 ? 'out_of_stock' : 'critical'
            ];
        }
        
        return [
            'data' => $result,
            'metadata' => [
                'data_status' => 'success',
                'total_critical_products' => count($result)
            ]
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getCriticalProducts", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function getOverstockProducts($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                i.sku,
                i.warehouse_name,
                SUM(i.current_stock) as total_stock,
                SUM(i.available_stock) as available_stock,
                SUM(i.reserved_stock) as reserved_stock,
                MAX(i.last_sync_at) as last_updated,
                (SUM(i.current_stock) - 100) as excess_stock
            FROM inventory_data i
            WHERE i.current_stock IS NOT NULL
            GROUP BY i.sku, i.warehouse_name
            HAVING SUM(i.current_stock) > 100
            ORDER BY SUM(i.current_stock) DESC, i.sku
            LIMIT 50
        ");
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($products as $product) {
            $result[] = [
                'name' => 'Товар ' . $product['sku'],
                'sku' => $product['sku'],
                'stock' => (int)$product['total_stock'],
                'available_stock' => (int)$product['available_stock'],
                'reserved_stock' => (int)$product['reserved_stock'],
                'warehouse' => $product['warehouse_name'],
                'unit_cost' => 0,
                'excess_stock' => (int)$product['excess_stock'],
                'excess_value' => 0,
                'last_updated' => $product['last_updated']
            ];
        }
        
        return [
            'data' => $result,
            'metadata' => [
                'data_status' => 'success',
                'total_overstock_products' => count($result)
            ]
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getOverstockProducts", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function getWarehouseSummary($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                i.warehouse_name,
                COUNT(DISTINCT i.sku) as total_products,
                SUM(i.current_stock) as total_stock,
                SUM(CASE WHEN i.current_stock <= 5 THEN 1 ELSE 0 END) as critical_count,
                SUM(CASE WHEN i.current_stock > 5 AND i.current_stock <= 20 THEN 1 ELSE 0 END) as low_count,
                SUM(CASE WHEN i.current_stock > 100 THEN 1 ELSE 0 END) as overstock_count
            FROM inventory_data i
            WHERE i.current_stock IS NOT NULL
            GROUP BY i.warehouse_name
            ORDER BY total_stock DESC
        ");
        
        $stmt->execute();
        $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'data' => $warehouses,
            'metadata' => [
                'data_status' => 'success',
                'total_warehouses' => count($warehouses)
            ]
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getWarehouseSummary", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function getDetailedRecommendations($pdo) {
    try {
        // Получаем статистику для рекомендаций
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN current_stock <= 5 THEN 1 ELSE 0 END) as critical_count,
                SUM(CASE WHEN current_stock > 5 AND current_stock <= 20 THEN 1 ELSE 0 END) as low_count,
                SUM(CASE WHEN current_stock > 100 THEN 1 ELSE 0 END) as overstock_count
            FROM inventory_data
            WHERE current_stock IS NOT NULL
        ");
        
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $recommendations = [];
        
        if ($stats['critical_count'] > 0) {
            $recommendations[] = [
                'type' => 'urgent',
                'title' => 'Срочное пополнение',
                'message' => 'У вас ' . $stats['critical_count'] . ' товаров с критическими остатками (≤5 единиц). Требуется срочное пополнение.',
                'action' => 'replenish_critical',
                'priority' => 1
            ];
        }
        
        if ($stats['overstock_count'] > 0) {
            $recommendations[] = [
                'type' => 'optimization',
                'title' => 'Проведение акций',
                'message' => 'У вас ' . $stats['overstock_count'] . ' товаров с избытком (>100 единиц). Рекомендуется провести акции.',
                'action' => 'create_promotions',
                'priority' => 2
            ];
        }
        
        if ($stats['low_count'] > 0) {
            $recommendations[] = [
                'type' => 'planning',
                'title' => 'Плановое пополнение',
                'message' => 'У вас ' . $stats['low_count'] . ' товаров с низкими остатками (6-20 единиц). Запланируйте пополнение.',
                'action' => 'plan_replenishment',
                'priority' => 3
            ];
        }
        
        return $recommendations;
        
    } catch (PDOException $e) {
        logError("Database error in getDetailedRecommendations", ['error' => $e->getMessage()]);
        throw $e;
    }
}
?>