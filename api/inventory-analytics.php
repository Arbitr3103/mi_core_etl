<?php
/**
 * API для аналитики складских остатков и маркетинговых решений
 * Обновлено для работы с реальными данными из inventory_data
 * Включает расширенную обработку ошибок и валидацию данных
 * Добавлено кэширование для оптимизации производительности
 * Task 7.1 & 7.2: Интегрирована система мониторинга производительности
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/inventory_cache_manager.php';
require_once __DIR__ . '/performance_monitor.php';

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

// Функция для валидации входных параметров
function validateInput($action, $params = []) {
    $errors = [];
    
    // Валидация action
    $allowedActions = ['dashboard', 'critical-products', 'low-stock-products', 'overstock-products', 'warehouse-summary', 'warehouse-details', 'products-by-warehouse', 'recommendations', 'activity_stats', 'inactive_products', 'activity_changes'];
    if (!in_array($action, $allowedActions)) {
        $errors[] = "Недопустимое действие: $action";
    }
    
    // Специфичная валидация для warehouse-details
    if ($action === 'warehouse-details') {
        if (empty($params['warehouse'])) {
            $errors[] = "Не указано название склада";
        } elseif (strlen($params['warehouse']) > 100) {
            $errors[] = "Название склада слишком длинное";
        }
    }
    
    // Валидация параметра active_only
    if (isset($params['active_only']) && !in_array($params['active_only'], ['true', 'false', '1', '0'])) {
        $errors[] = "Параметр active_only должен быть true/false или 1/0";
    }
    
    // Валидация параметра limit (только 'all' или числовые значения)
    if (isset($params['limit'])) {
        if ($params['limit'] !== 'all' && !is_numeric($params['limit'])) {
            $errors[] = "Параметр limit должен быть 'all' или числовым значением";
        } elseif (is_numeric($params['limit']) && (int)$params['limit'] < 1) {
            $errors[] = "Параметр limit должен быть положительным числом";
        } elseif (is_numeric($params['limit']) && (int)$params['limit'] > 1000) {
            $errors[] = "Параметр limit не может превышать 1000";
        }
    }
    
    return $errors;
}

// Функция для получения условия фильтрации активных товаров
function getActiveProductsFilter($params = []) {
    // По умолчанию фильтруем только активные товары
    $activeOnly = $params['active_only'] ?? 'true';
    
    if (in_array($activeOnly, ['true', '1'])) {
        return " AND dp.is_active = 1 AND dp.is_active IS NOT NULL ";
    }
    
    return " "; // Возвращаем пустую строку если фильтрация отключена
}

try {
    // Инициализируем мониторинг производительности
    $performance_monitor = getPerformanceMonitor();
    $performance_monitor->startTimer('api_request_total');
    
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
            'details' => 'В таблице inventory_data отсутствуют данные или все значения current_stock равны NULL',
            'suggestions' => [
                'Запустите синхронизацию данных со складской системой',
                'Проверьте корректность импорта данных',
                'Обратитесь к администратору системы'
            ]
        ]);
        exit;
    }
    
    $action = $_GET['action'] ?? 'dashboard';
    $params = $_GET;
    
    // Валидируем входные параметры
    $validationErrors = validateInput($action, $params);
    if (!empty($validationErrors)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'error_code' => 'VALIDATION_ERROR',
            'message' => 'Ошибка валидации параметров',
            'errors' => $validationErrors
        ]);
        exit;
    }
    
    // Получаем кэш-менеджер
    $cache = getInventoryCacheManager();
    
    switch ($action) {
        case 'dashboard':
            $performance_monitor->startTimer('dashboard_request');
            
            $limit = $params['limit'] ?? '10';
            $cache_key = InventoryCacheKeys::getDashboardKey() . '_' . ($params['active_only'] ?? 'true') . '_limit_' . $limit;
            $result = $cache->remember($cache_key, function() use ($pdo, $params, $performance_monitor) {
                $performance_monitor->startTimer('dashboard_data_generation');
                $data = getInventoryDashboardData($pdo, $params);
                $performance_monitor->endTimer('dashboard_data_generation');
                return $data;
            }, 300); // 5 минут кэш
            
            $dashboard_metrics = $performance_monitor->endTimer('dashboard_request', [
                'cache_hit' => $cache->get($cache_key) !== null,
                'active_only' => $params['active_only'] ?? 'true',
                'limit' => $limit,
                'result_count' => count($result['data']['critical_products'] ?? [])
            ]);
            
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'cached' => $cache->get($cache_key) !== null,
                    'cache_key' => $cache_key,
                    'active_only' => $params['active_only'] ?? 'true',
                    'limit' => $limit,
                    'performance' => [
                        'execution_time_ms' => $dashboard_metrics['execution_time_ms'] ?? null,
                        'memory_used_mb' => $dashboard_metrics['memory_used_mb'] ?? null
                    ]
                ])
            ]);
            break;
            
        case 'critical-products':
            $limit = $params['limit'] ?? '10';
            $cache_key = InventoryCacheKeys::getCriticalProductsKey() . '_' . ($params['active_only'] ?? 'true') . '_limit_' . $limit;
            $result = $cache->remember($cache_key, function() use ($pdo, $params) {
                return getCriticalProducts($pdo, $params);
            }, 180); // 3 минуты кэш для критических товаров
            
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'cached' => $cache->get($cache_key) !== null,
                    'active_only' => $params['active_only'] ?? 'true',
                    'limit' => $limit
                ])
            ]);
            break;
            
        case 'overstock-products':
            $limit = $params['limit'] ?? '10';
            $cache_key = InventoryCacheKeys::getOverstockProductsKey() . '_' . ($params['active_only'] ?? 'true') . '_limit_' . $limit;
            $result = $cache->remember($cache_key, function() use ($pdo, $params) {
                return getOverstockProducts($pdo, $params);
            }, 600); // 10 минут кэш для товаров с избытком
            
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'cached' => $cache->get($cache_key) !== null,
                    'active_only' => $params['active_only'] ?? 'true',
                    'limit' => $limit
                ])
            ]);
            break;
            
        case 'low-stock-products':
            $limit = $params['limit'] ?? '10';
            $cache_key = 'low_stock_products_' . ($params['active_only'] ?? 'true') . '_limit_' . $limit;
            $result = $cache->remember($cache_key, function() use ($pdo, $params) {
                return getLowStockProducts($pdo, $params);
            }, 300); // 5 минут кэш для товаров с низкими остатками
            
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'cached' => $cache->get($cache_key) !== null,
                    'active_only' => $params['active_only'] ?? 'true',
                    'limit' => $limit
                ])
            ]);
            break;
            
        case 'warehouse-summary':
            $cache_key = InventoryCacheKeys::getWarehouseSummaryKey() . '_' . ($params['active_only'] ?? 'true');
            $result = $cache->remember($cache_key, function() use ($pdo, $params) {
                return getWarehouseSummary($pdo, $params);
            }, 300); // 5 минут кэш
            
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'cached' => $cache->get($cache_key) !== null,
                    'active_only' => $params['active_only'] ?? 'true'
                ])
            ]);
            break;
            
        case 'warehouse-details':
            $warehouse_name = $_GET['warehouse'];
            $cache_key = InventoryCacheKeys::getWarehouseDetailsKey($warehouse_name) . '_' . ($params['active_only'] ?? 'true');
            $result = $cache->remember($cache_key, function() use ($pdo, $warehouse_name, $params) {
                return getWarehouseDetails($pdo, $warehouse_name, $params);
            }, 300); // 5 минут кэш
            
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'metadata' => [
                    'cached' => $cache->get($cache_key) !== null,
                    'warehouse' => $warehouse_name,
                    'active_only' => $params['active_only'] ?? 'true'
                ]
            ]);
            break;
            
        case 'products-by-warehouse':
            $result = getProductsByWarehouse($pdo, $params);
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'metadata' => [
                    'active_only' => $params['active_only'] ?? 'true'
                ]
            ]);
            break;
            
        case 'recommendations':
            $cache_key = InventoryCacheKeys::getRecommendationsKey() . '_' . ($params['active_only'] ?? 'true');
            $result = $cache->remember($cache_key, function() use ($pdo, $params) {
                return getDetailedRecommendations($pdo, $params);
            }, 600); // 10 минут кэш для рекомендаций
            
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'metadata' => [
                    'cached' => $cache->get($cache_key) !== null,
                    'active_only' => $params['active_only'] ?? 'true'
                ]
            ]);
            break;
            
        case 'activity_stats':
            $result = getActivityStats($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'metadata' => [
                    'endpoint' => 'activity_stats',
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'inactive_products':
            $result = getInactiveProducts($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'metadata' => [
                    'endpoint' => 'inactive_products',
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'activity_changes':
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d');
            $result = getActivityChanges($pdo, $dateFrom, $dateTo);
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'metadata' => [
                    'endpoint' => 'activity_changes',
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
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
        'message' => 'Ошибка базы данных',
        'details' => 'Произошла ошибка при выполнении запроса к базе данных',
        'suggestions' => [
            'Проверьте подключение к базе данных',
            'Убедитесь, что все необходимые таблицы существуют',
            'Обратитесь к администратору системы'
        ]
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
        'message' => 'Внутренняя ошибка сервера',
        'details' => 'Произошла непредвиденная ошибка при обработке запроса',
        'suggestions' => [
            'Попробуйте обновить страницу',
            'Проверьте корректность запроса',
            'Обратитесь к администратору системы'
        ]
    ]);
}

function getInventoryDashboardData($pdo, $params = []) {
    try {
        // Проверяем наличие таблицы inventory_data
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'inventory_data'");
        if ($tableCheck->rowCount() === 0) {
            throw new Exception("Таблица inventory_data не найдена");
        }
        
        // Получаем условие фильтрации активных товаров
        $activeFilter = getActiveProductsFilter($params);
        
        // Получаем данные из inventory_data с объединением с dim_products для названий товаров
        // Используем правильные названия колонок: current_stock вместо stock_quantity
        // Добавляем фильтрацию по активным товарам
        $stmt = $pdo->prepare("
            SELECT 
                i.sku,
                i.warehouse_name,
                SUM(i.current_stock) as total_stock,
                SUM(i.available_stock) as available_stock,
                SUM(i.reserved_stock) as reserved_stock,
                MAX(i.last_sync_at) as last_updated,
                -- Классификация товаров по уровням остатков согласно требованиям
                CASE
                    WHEN SUM(i.current_stock) <= 5 THEN 'critical'
                    WHEN SUM(i.current_stock) <= 20 THEN 'low'
                    WHEN SUM(i.current_stock) > 100 THEN 'overstock'
                    ELSE 'normal'
                END as stock_status
            FROM inventory_data i
            LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
            WHERE i.current_stock IS NOT NULL " . $activeFilter . "
            GROUP BY i.sku, i.warehouse_name
            ORDER BY 
                CASE 
                    WHEN SUM(i.current_stock) <= 5 THEN 1
                    WHEN SUM(i.current_stock) <= 20 THEN 2
                    WHEN SUM(i.current_stock) > 100 THEN 3
                    ELSE 4
                END,
                SUM(i.current_stock) ASC
        ");
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Проверяем, есть ли данные
        if (empty($products)) {
            logError("No products found in inventory_data", ['query_executed' => true]);
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
                    'recommendations' => [],
                    'summary' => [
                        'total_products' => 0,
                        'needs_attention' => 0,
                        'optimization_opportunity' => 0,
                        'last_updated' => date('Y-m-d H:i:s')
                    ]
                ],
                'metadata' => [
                    'data_status' => 'empty',
                    'message' => 'Нет данных о складских остатках',
                    'suggestions' => [
                        'Запустите синхронизацию данных со складской системой',
                        'Проверьте корректность импорта данных'
                    ]
                ]
            ];
        }
    
        // Получаем названия товаров и цены отдельно (с кросс-референсами)
        $product_info = [];
        $missing_names_count = 0;
        
        if (!empty($products)) {
            $skus = array_unique(array_column($products, 'sku'));
            
            // Для каждого SKU ищем информацию
            foreach ($skus as $sku) {
                $product_name = null;
                $unit_cost = 0;
                
                try {
                    // Сначала ищем в dim_products
                    $info_stmt = $pdo->prepare("
                        SELECT 
                            COALESCE(product_name, name) as product_name,
                            COALESCE(cost_price, 0) as unit_cost
                        FROM dim_products 
                        WHERE sku_ozon = ? OR sku_wb = ? OR name = ?
                        LIMIT 1
                    ");
                    $info_stmt->execute([$sku, $sku, $sku]);
                    $info = $info_stmt->fetch();
                    
                    if ($info && !empty($info['product_name'])) {
                        $product_name = $info['product_name'];
                        $unit_cost = (float)$info['unit_cost'];
                    } else {
                        // Ищем в кросс-референсах
                        $cross_ref_stmt = $pdo->prepare("
                            SELECT 
                                scr.product_name,
                                COALESCE(dp.cost_price, 0) as unit_cost
                            FROM sku_cross_reference scr
                            LEFT JOIN dim_products dp ON scr.numeric_sku = dp.sku_ozon
                            WHERE scr.text_sku = ?
                            LIMIT 1
                        ");
                        $cross_ref_stmt->execute([$sku]);
                        $cross_ref = $cross_ref_stmt->fetch();
                        
                        if ($cross_ref && !empty($cross_ref['product_name'])) {
                            $product_name = $cross_ref['product_name'];
                            $unit_cost = (float)$cross_ref['unit_cost'];
                        }
                    }
                } catch (PDOException $e) {
                    logError("Error fetching product info for SKU: $sku", ['error' => $e->getMessage()]);
                }
                
                // Fallback для отсутствующих названий товаров (требование 3.2)
                if (empty($product_name)) {
                    $product_name = 'Товар ' . $sku;
                    $missing_names_count++;
                }
                
                $product_info[$sku] = [
                    'name' => $product_name,
                    'unit_cost' => $unit_cost,
                    'has_name' => !empty($product_name) && $product_name !== 'Товар ' . $sku
                ];
            }
        }
    
    // Группируем товары по статусу
    $critical_products = [];
    $low_stock_products = [];
    $overstock_products = [];
    $normal_products = [];
    
    $total_inventory_value = 0;
    $warehouses_summary = [];
    
    foreach ($products as $product) {
        $product_name = isset($product_info[$product['sku']]) ? 
            $product_info[$product['sku']]['name'] : 
            'Товар ' . $product['sku'];
        $unit_cost = isset($product_info[$product['sku']]) ? 
            $product_info[$product['sku']]['unit_cost'] : 
            0;
            
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
    
    // Сохраняем полные счетчики до применения лимита
    $full_critical_count = count($critical_products);
    $full_low_stock_count = count($low_stock_products);
    $full_overstock_count = count($overstock_products);
    $full_normal_count = count($normal_products);
    
    // Применяем лимит для отображения товаров
    $limit = $params['limit'] ?? '10';
    if ($limit !== 'all') {
        $limit_num = (int)$limit;
        $critical_products = array_slice($critical_products, 0, $limit_num);
        $low_stock_products = array_slice($low_stock_products, 0, $limit_num);
        $overstock_products = array_slice($overstock_products, 0, $limit_num);
    }
    // Если limit='all', показываем все товары без ограничений
    
    // Генерируем рекомендации согласно требованиям
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
                'critical_stock_count' => $full_critical_count,
                'low_stock_count' => $full_low_stock_count,
                'overstock_count' => $full_overstock_count,
                'normal_count' => $full_normal_count,
                'total_inventory_value' => $total_inventory_value,
                'critical_products' => [
                    'count' => $full_critical_count,
                    'items' => $critical_products
                ],
                'low_stock_products' => [
                    'count' => $full_low_stock_count,
                    'items' => $low_stock_products
                ],
                'overstock_products' => [
                    'count' => $full_overstock_count,
                    'items' => $overstock_products
                ],
                'warehouses_summary' => array_values($warehouses_summary),
                'recommendations' => $recommendations,
                'summary' => [
                    'total_products' => count($products),
                    'needs_attention' => $full_critical_count + $full_low_stock_count,
                    'optimization_opportunity' => $full_overstock_count,
                    'last_updated' => date('Y-m-d H:i:s')
                ]
            ],
            'metadata' => [
                'data_status' => 'success',
                'data_quality' => [
                    'total_skus' => count($skus ?? []),
                    'missing_names_count' => $missing_names_count,
                    'missing_names_percentage' => count($skus ?? []) > 0 ? round(($missing_names_count / count($skus)) * 100, 1) : 0
                ],
                'warnings' => $missing_names_count > 0 ? [
                    "Для $missing_names_count товаров отсутствуют названия в справочнике"
                ] : [],
                'suggestions' => $missing_names_count > 0 ? [
                    'Обновите справочник товаров (dim_products)',
                    'Проверьте корректность SKU в системе'
                ] : []
            ]
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getInventoryDashboardData", ['error' => $e->getMessage()]);
        throw $e;
    } catch (Exception $e) {
        logError("General error in getInventoryDashboardData", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function getCriticalProducts($pdo, $params = []) {
    try {
        // Получаем условие фильтрации активных товаров
        $activeFilter = getActiveProductsFilter($params);
        
        // Получаем товары с критическими остатками (≤5 единиц) согласно требованиям
        $stmt = $pdo->prepare("
            SELECT 
                i.sku,
                i.warehouse_name,
                SUM(i.current_stock) as total_stock,
                SUM(i.available_stock) as available_stock,
                SUM(i.reserved_stock) as reserved_stock,
                MAX(i.last_sync_at) as last_updated
            FROM inventory_data i
            LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
            WHERE i.current_stock IS NOT NULL " . $activeFilter . "
            GROUP BY i.sku, i.warehouse_name
            HAVING SUM(i.current_stock) <= 5
            ORDER BY SUM(i.current_stock) ASC, i.sku
        ");
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            return [
                'data' => [],
                'metadata' => [
                    'data_status' => 'empty',
                    'message' => 'Нет товаров с критическими остатками',
                    'info' => 'Это хорошая новость - все товары имеют достаточные остатки'
                ]
            ];
        }
    
        // Получаем названия товаров с улучшенной обработкой ошибок
        $product_info = [];
        $missing_names_count = 0;
        
        if (!empty($products)) {
            $skus = array_unique(array_column($products, 'sku'));
            
            foreach ($skus as $sku) {
                $product_name = null;
                $unit_cost = 0;
                
                try {
                    // Ищем в dim_products
                    $info_stmt = $pdo->prepare("
                        SELECT 
                            COALESCE(product_name, name) as product_name,
                            COALESCE(cost_price, 0) as unit_cost
                        FROM dim_products 
                        WHERE sku_ozon = ? OR sku_wb = ? OR name = ?
                        LIMIT 1
                    ");
                    $info_stmt->execute([$sku, $sku, $sku]);
                    $info = $info_stmt->fetch();
                    
                    if ($info && !empty($info['product_name'])) {
                        $product_name = $info['product_name'];
                        $unit_cost = (float)$info['unit_cost'];
                    }
                } catch (PDOException $e) {
                    logError("Error fetching product info for critical SKU: $sku", ['error' => $e->getMessage()]);
                }
                
                // Fallback для отсутствующих названий товаров
                if (empty($product_name)) {
                    $product_name = 'Товар ' . $sku;
                    $missing_names_count++;
                }
                
                $product_info[$sku] = [
                    'name' => $product_name,
                    'unit_cost' => $unit_cost
                ];
            }
        }
        
        $result = [];
        foreach ($products as $product) {
            $product_name = $product_info[$product['sku']]['name'] ?? 'Товар ' . $product['sku'];
            $unit_cost = $product_info[$product['sku']]['unit_cost'] ?? 0;
                
            $result[] = [
                'name' => $product_name,
                'sku' => $product['sku'],
                'stock' => (int)$product['total_stock'],
                'available_stock' => (int)$product['available_stock'],
                'reserved_stock' => (int)$product['reserved_stock'],
                'warehouse' => $product['warehouse_name'],
                'unit_cost' => $unit_cost,
                'last_updated' => $product['last_updated'],
                'urgency' => $product['total_stock'] == 0 ? 'out_of_stock' : 'critical'
            ];
        }
        
        // Сохраняем полный счетчик до применения лимита
        $total_critical_products = count($result);
        
        // Применяем лимит для отображения товаров
        $limit = $params['limit'] ?? '10';
        if ($limit !== 'all') {
            $limit_num = (int)$limit;
            $result = array_slice($result, 0, $limit_num);
        }
        
        return [
            'data' => [
                'count' => $total_critical_products,
                'items' => $result
            ],
            'metadata' => [
                'data_status' => 'success',
                'total_critical_products' => $total_critical_products,
                'displayed_products' => count($result),
                'limit' => $limit,
                'data_quality' => [
                    'missing_names_count' => $missing_names_count,
                    'missing_names_percentage' => count($skus ?? []) > 0 ? round(($missing_names_count / count($skus)) * 100, 1) : 0
                ],
                'warnings' => $missing_names_count > 0 ? [
                    "Для $missing_names_count критических товаров отсутствуют названия"
                ] : []
            ]
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getCriticalProducts", ['error' => $e->getMessage()]);
        throw $e;
    } catch (Exception $e) {
        logError("General error in getCriticalProducts", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function getOverstockProducts($pdo, $params = []) {
    try {
        // Получаем условие фильтрации активных товаров
        $activeFilter = getActiveProductsFilter($params);
        
        // Получаем товары с избытком (>100 единиц) согласно требованиям
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
            LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
            WHERE i.current_stock IS NOT NULL " . $activeFilter . "
            GROUP BY i.sku, i.warehouse_name
            HAVING SUM(i.current_stock) > 100
            ORDER BY SUM(i.current_stock) DESC, i.sku
        ");
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            return [
                'data' => [],
                'metadata' => [
                    'data_status' => 'empty',
                    'message' => 'Нет товаров с избыточными остатками',
                    'info' => 'Все товары имеют оптимальные остатки (≤100 единиц)'
                ]
            ];
        }
    
    // Получаем названия товаров
    $product_info = [];
    if (!empty($products)) {
        $skus = array_unique(array_column($products, 'sku'));
        $sku_placeholders = str_repeat('?,', count($skus) - 1) . '?';
        
        $info_stmt = $pdo->prepare("
            SELECT 
                sku_ozon,
                sku_wb,
                COALESCE(product_name, name) as product_name,
                COALESCE(cost_price, 0) as unit_cost
            FROM dim_products 
            WHERE sku_ozon IN ($sku_placeholders) OR sku_wb IN ($sku_placeholders)
        ");
        $info_stmt->execute(array_merge($skus, $skus));
        $info_results = $info_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($info_results as $info) {
            if ($info['sku_ozon']) {
                $product_info[$info['sku_ozon']] = [
                    'name' => $info['product_name'] ?: 'Товар ' . $info['sku_ozon'],
                    'unit_cost' => (float)$info['unit_cost']
                ];
            }
            if ($info['sku_wb']) {
                $product_info[$info['sku_wb']] = [
                    'name' => $info['product_name'] ?: 'Товар ' . $info['sku_wb'],
                    'unit_cost' => (float)$info['unit_cost']
                ];
            }
        }
    }
    
    $result = [];
    foreach ($products as $product) {
        $product_name = isset($product_info[$product['sku']]) ? 
            $product_info[$product['sku']]['name'] : 
            'Товар ' . $product['sku'];
        $unit_cost = isset($product_info[$product['sku']]) ? 
            $product_info[$product['sku']]['unit_cost'] : 
            0;
        $excess_value = $product['excess_stock'] * $unit_cost;
        
        $result[] = [
            'name' => $product_name,
            'sku' => $product['sku'],
            'stock' => (int)$product['total_stock'],
            'available_stock' => (int)$product['available_stock'],
            'reserved_stock' => (int)$product['reserved_stock'],
            'warehouse' => $product['warehouse_name'],
            'unit_cost' => $unit_cost,
            'excess_stock' => (int)$product['excess_stock'],
            'excess_value' => $excess_value,
            'last_updated' => $product['last_updated']
        ];
    }
    
    // Сохраняем полный счетчик до применения лимита
    $total_overstock_products = count($result);
    
    // Применяем лимит для отображения товаров
    $limit = $params['limit'] ?? '10';
    if ($limit !== 'all') {
        $limit_num = (int)$limit;
        $result = array_slice($result, 0, $limit_num);
    }
    
    return [
        'data' => [
            'count' => $total_overstock_products,
            'items' => $result
        ],
        'metadata' => [
            'data_status' => 'success',
            'total_overstock_products' => $total_overstock_products,
            'displayed_products' => count($result),
            'limit' => $limit,
            'last_updated' => date('Y-m-d H:i:s')
        ]
    ];
    
    } catch (PDOException $e) {
        logError("Database error in getOverstockProducts", ['error' => $e->getMessage()]);
        throw $e;
    } catch (Exception $e) {
        logError("General error in getOverstockProducts", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function getLowStockProducts($pdo, $params = []) {
    try {
        // Получаем условие фильтрации активных товаров
        $activeFilter = getActiveProductsFilter($params);
        
        // Получаем товары с низкими остатками (6-20 единиц) согласно требованиям
        $stmt = $pdo->prepare("
            SELECT 
                i.sku,
                i.warehouse_name,
                SUM(i.current_stock) as total_stock,
                SUM(i.available_stock) as available_stock,
                SUM(i.reserved_stock) as reserved_stock,
                MAX(i.last_sync_at) as last_updated
            FROM inventory_data i
            LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
            WHERE i.current_stock IS NOT NULL " . $activeFilter . "
            GROUP BY i.sku, i.warehouse_name
            HAVING SUM(i.current_stock) > 5 AND SUM(i.current_stock) <= 20
            ORDER BY SUM(i.current_stock) ASC, i.sku
        ");
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($products)) {
            return [
                'data' => [
                    'count' => 0,
                    'items' => []
                ],
                'metadata' => [
                    'data_status' => 'empty',
                    'message' => 'Нет товаров с низкими остатками',
                    'info' => 'Все товары имеют достаточные остатки или находятся в критическом состоянии'
                ]
            ];
        }
    
        // Получаем названия товаров с улучшенной обработкой ошибок
        $product_info = [];
        $missing_names_count = 0;
        
        if (!empty($products)) {
            $skus = array_unique(array_column($products, 'sku'));
            
            foreach ($skus as $sku) {
                $product_name = null;
                $unit_cost = 0;
                
                try {
                    // Ищем в dim_products
                    $info_stmt = $pdo->prepare("
                        SELECT 
                            COALESCE(product_name, name) as product_name,
                            COALESCE(cost_price, 0) as unit_cost
                        FROM dim_products 
                        WHERE sku_ozon = ? OR sku_wb = ? OR name = ?
                        LIMIT 1
                    ");
                    $info_stmt->execute([$sku, $sku, $sku]);
                    $info = $info_stmt->fetch();
                    
                    if ($info && !empty($info['product_name'])) {
                        $product_name = $info['product_name'];
                        $unit_cost = (float)$info['unit_cost'];
                    }
                } catch (PDOException $e) {
                    logError("Error fetching product info for low stock SKU: $sku", ['error' => $e->getMessage()]);
                }
                
                // Fallback для отсутствующих названий товаров
                if (empty($product_name)) {
                    $product_name = 'Товар ' . $sku;
                    $missing_names_count++;
                }
                
                $product_info[$sku] = [
                    'name' => $product_name,
                    'unit_cost' => $unit_cost
                ];
            }
        }
        
        $result = [];
        foreach ($products as $product) {
            $product_name = $product_info[$product['sku']]['name'] ?? 'Товар ' . $product['sku'];
            $unit_cost = $product_info[$product['sku']]['unit_cost'] ?? 0;
                
            $result[] = [
                'name' => $product_name,
                'sku' => $product['sku'],
                'stock' => (int)$product['total_stock'],
                'available_stock' => (int)$product['available_stock'],
                'reserved_stock' => (int)$product['reserved_stock'],
                'warehouse' => $product['warehouse_name'],
                'unit_cost' => $unit_cost,
                'last_updated' => $product['last_updated'],
                'status' => 'low_stock'
            ];
        }
        
        // Сохраняем полный счетчик до применения лимита
        $total_low_stock_products = count($result);
        
        // Применяем лимит для отображения товаров
        $limit = $params['limit'] ?? '10';
        if ($limit !== 'all') {
            $limit_num = (int)$limit;
            $result = array_slice($result, 0, $limit_num);
        }
        
        return [
            'data' => [
                'count' => $total_low_stock_products,
                'items' => $result
            ],
            'metadata' => [
                'data_status' => 'success',
                'total_low_stock_products' => $total_low_stock_products,
                'displayed_products' => count($result),
                'limit' => $limit,
                'data_quality' => [
                    'missing_names_count' => $missing_names_count,
                    'missing_names_percentage' => count($skus ?? []) > 0 ? round(($missing_names_count / count($skus)) * 100, 1) : 0
                ],
                'warnings' => $missing_names_count > 0 ? [
                    "Для $missing_names_count товаров с низкими остатками отсутствуют названия"
                ] : []
            ]
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getLowStockProducts", ['error' => $e->getMessage()]);
        throw $e;
    } catch (Exception $e) {
        logError("General error in getLowStockProducts", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function getWarehouseSummary($pdo, $params = []) {
    try {
        // Получаем условие фильтрации активных товаров
        $activeFilter = getActiveProductsFilter($params);
        
        // Получаем расширенную сводку по складам с агрегацией остатков
        $stmt = $pdo->prepare("
            SELECT 
                i.warehouse_name,
                COUNT(DISTINCT i.sku) as total_products,
                SUM(i.current_stock) as total_stock,
                SUM(i.available_stock) as total_available,
                SUM(i.reserved_stock) as total_reserved,
                COUNT(CASE WHEN i.current_stock <= 5 THEN 1 END) as critical_count,
                COUNT(CASE WHEN i.current_stock > 5 AND i.current_stock <= 20 THEN 1 END) as low_count,
                COUNT(CASE WHEN i.current_stock > 100 THEN 1 END) as overstock_count,
                MAX(i.last_sync_at) as last_updated,
                AVG(i.current_stock) as avg_stock_per_product,
                MIN(i.current_stock) as min_stock,
                MAX(i.current_stock) as max_stock
            FROM inventory_data i
            LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
            WHERE i.current_stock IS NOT NULL " . $activeFilter . "
            GROUP BY i.warehouse_name
            ORDER BY total_stock DESC, i.warehouse_name
        ");
        
        $stmt->execute();
        $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($warehouses)) {
            return [
                'data' => [],
                'metadata' => [
                    'data_status' => 'empty',
                    'message' => 'Нет данных по складам',
                    'suggestions' => [
                        'Проверьте наличие данных в inventory_data',
                        'Убедитесь, что поле warehouse_name заполнено'
                    ]
                ]
            ];
        }
    
    // Получаем общую статистику для расчета процентов с учетом активных товаров
    $total_stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT i.sku) as total_unique_products,
            SUM(i.current_stock) as total_system_stock
        FROM inventory_data i
        LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
        WHERE i.current_stock IS NOT NULL " . $activeFilter . "
    ");
    $total_stmt->execute();
    $totals = $total_stmt->fetch();
    
    $result = [];
    foreach ($warehouses as $warehouse) {
        $normal_count = (int)$warehouse['total_products'] - (int)$warehouse['critical_count'] - (int)$warehouse['low_count'] - (int)$warehouse['overstock_count'];
        $stock_percentage = $totals['total_system_stock'] > 0 ? round(($warehouse['total_stock'] / $totals['total_system_stock']) * 100, 2) : 0;
        
        $result[] = [
            'warehouse_name' => $warehouse['warehouse_name'],
            'total_products' => (int)$warehouse['total_products'],
            'total_stock' => (int)$warehouse['total_stock'],
            'total_available' => (int)$warehouse['total_available'],
            'total_reserved' => (int)$warehouse['total_reserved'],
            'critical_count' => (int)$warehouse['critical_count'],
            'low_count' => (int)$warehouse['low_count'],
            'overstock_count' => (int)$warehouse['overstock_count'],
            'normal_count' => $normal_count,
            'last_updated' => $warehouse['last_updated'],
            'avg_stock_per_product' => round((float)$warehouse['avg_stock_per_product'], 2),
            'min_stock' => (int)$warehouse['min_stock'],
            'max_stock' => (int)$warehouse['max_stock'],
            'stock_percentage' => $stock_percentage,
            'status' => determineWarehouseStatus($warehouse)
        ];
    }
    
    return [
        'data' => $result,
        'metadata' => [
            'data_status' => 'success',
            'total_warehouses' => count($result),
            'last_updated' => date('Y-m-d H:i:s')
        ]
    ];
    
    } catch (PDOException $e) {
        logError("Database error in getWarehouseSummary", ['error' => $e->getMessage()]);
        throw $e;
    } catch (Exception $e) {
        logError("General error in getWarehouseSummary", ['error' => $e->getMessage()]);
        throw $e;
    }
}

function getWarehouseDetails($pdo, $warehouse_name, $params = []) {
    // Получаем условие фильтрации активных товаров
    $activeFilter = getActiveProductsFilter($params);
    
    // Получаем детальную информацию по конкретному складу
    $stmt = $pdo->prepare("
        SELECT 
            i.sku,
            i.current_stock,
            i.available_stock,
            i.reserved_stock,
            i.last_sync_at,
            CASE
                WHEN i.current_stock <= 5 THEN 'critical'
                WHEN i.current_stock <= 20 THEN 'low'
                WHEN i.current_stock > 100 THEN 'overstock'
                ELSE 'normal'
            END as stock_status
        FROM inventory_data i
        LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
        WHERE i.warehouse_name = ? AND i.current_stock IS NOT NULL " . $activeFilter . "
        ORDER BY 
            CASE 
                WHEN i.current_stock <= 5 THEN 1
                WHEN i.current_stock <= 20 THEN 2
                WHEN i.current_stock > 100 THEN 3
                ELSE 4
            END,
            i.current_stock ASC
    ");
    
    $stmt->execute([$warehouse_name]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Получаем названия товаров
    $product_info = [];
    if (!empty($products)) {
        $skus = array_unique(array_column($products, 'sku'));
        
        foreach ($skus as $sku) {
            // Ищем в dim_products
            $info_stmt = $pdo->prepare("
                SELECT 
                    COALESCE(product_name, name) as product_name,
                    COALESCE(cost_price, 0) as unit_cost
                FROM dim_products 
                WHERE sku_ozon = ? OR sku_wb = ? OR name = ?
                LIMIT 1
            ");
            $info_stmt->execute([$sku, $sku, $sku]);
            $info = $info_stmt->fetch();
            
            if ($info) {
                $product_info[$sku] = [
                    'name' => $info['product_name'] ?: 'Товар ' . $sku,
                    'unit_cost' => (float)$info['unit_cost']
                ];
            } else {
                // Ищем в кросс-референсах
                $cross_ref_stmt = $pdo->prepare("
                    SELECT 
                        scr.product_name,
                        COALESCE(dp.cost_price, 0) as unit_cost
                    FROM sku_cross_reference scr
                    LEFT JOIN dim_products dp ON scr.numeric_sku = dp.sku_ozon
                    WHERE scr.text_sku = ?
                    LIMIT 1
                ");
                $cross_ref_stmt->execute([$sku]);
                $cross_ref = $cross_ref_stmt->fetch();
                
                if ($cross_ref) {
                    $product_info[$sku] = [
                        'name' => $cross_ref['product_name'] ?: 'Товар ' . $sku,
                        'unit_cost' => (float)$cross_ref['unit_cost']
                    ];
                } else {
                    $product_info[$sku] = [
                        'name' => 'Товар ' . $sku,
                        'unit_cost' => 0
                    ];
                }
            }
        }
    }
    
    // Группируем товары по статусу
    $grouped_products = [
        'critical' => [],
        'low' => [],
        'overstock' => [],
        'normal' => []
    ];
    
    $total_value = 0;
    
    foreach ($products as $product) {
        $sku = $product['sku'];
        $product_name = $product_info[$sku]['name'] ?? 'Товар ' . $sku;
        $unit_cost = $product_info[$sku]['unit_cost'] ?? 0;
        $value = $product['current_stock'] * $unit_cost;
        $total_value += $value;
        
        $product_data = [
            'sku' => $sku,
            'name' => $product_name,
            'current_stock' => (int)$product['current_stock'],
            'available_stock' => (int)$product['available_stock'],
            'reserved_stock' => (int)$product['reserved_stock'],
            'unit_cost' => $unit_cost,
            'total_value' => $value,
            'last_sync_at' => $product['last_sync_at']
        ];
        
        $grouped_products[$product['stock_status']][] = $product_data;
    }
    
    return [
        'warehouse_name' => $warehouse_name,
        'total_products' => count($products),
        'total_value' => $total_value,
        'products_by_status' => $grouped_products,
        'summary' => [
            'critical_count' => count($grouped_products['critical']),
            'low_count' => count($grouped_products['low']),
            'overstock_count' => count($grouped_products['overstock']),
            'normal_count' => count($grouped_products['normal'])
        ]
    ];
}

function getProductsByWarehouse($pdo, $params = []) {
    // Получаем условие фильтрации активных товаров
    $activeFilter = getActiveProductsFilter($params);
    
    // Получаем агрегированные данные по товарам с разбивкой по складам
    $stmt = $pdo->prepare("
        SELECT 
            i.sku,
            i.warehouse_name,
            SUM(i.current_stock) as total_stock,
            SUM(i.available_stock) as available_stock,
            SUM(i.reserved_stock) as reserved_stock,
            MAX(i.last_sync_at) as last_updated
        FROM inventory_data i
        LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
        WHERE i.current_stock IS NOT NULL " . $activeFilter . "
        GROUP BY i.sku, i.warehouse_name
        ORDER BY i.sku, total_stock DESC
    ");
    
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Группируем по SKU
    $products_by_sku = [];
    $skus = [];
    
    foreach ($data as $row) {
        $sku = $row['sku'];
        $skus[] = $sku;
        
        if (!isset($products_by_sku[$sku])) {
            $products_by_sku[$sku] = [
                'sku' => $sku,
                'warehouses' => [],
                'total_across_warehouses' => 0,
                'warehouse_count' => 0
            ];
        }
        
        $products_by_sku[$sku]['warehouses'][] = [
            'warehouse_name' => $row['warehouse_name'],
            'stock' => (int)$row['total_stock'],
            'available' => (int)$row['available_stock'],
            'reserved' => (int)$row['reserved_stock'],
            'last_updated' => $row['last_updated']
        ];
        
        $products_by_sku[$sku]['total_across_warehouses'] += (int)$row['total_stock'];
        $products_by_sku[$sku]['warehouse_count']++;
    }
    
    // Получаем названия товаров
    $unique_skus = array_unique($skus);
    $product_names = [];
    
    foreach ($unique_skus as $sku) {
        $info_stmt = $pdo->prepare("
            SELECT COALESCE(product_name, name) as product_name
            FROM dim_products 
            WHERE sku_ozon = ? OR sku_wb = ? OR name = ?
            LIMIT 1
        ");
        $info_stmt->execute([$sku, $sku, $sku]);
        $info = $info_stmt->fetch();
        
        if ($info) {
            $product_names[$sku] = $info['product_name'];
        } else {
            // Проверяем кросс-референсы
            $cross_ref_stmt = $pdo->prepare("
                SELECT product_name
                FROM sku_cross_reference
                WHERE text_sku = ?
                LIMIT 1
            ");
            $cross_ref_stmt->execute([$sku]);
            $cross_ref = $cross_ref_stmt->fetch();
            
            $product_names[$sku] = $cross_ref ? $cross_ref['product_name'] : 'Товар ' . $sku;
        }
    }
    
    // Добавляем названия к результатам
    foreach ($products_by_sku as $sku => &$product) {
        $product['name'] = $product_names[$sku];
    }
    
    return array_values($products_by_sku);
}

function determineWarehouseStatus($warehouse) {
    $critical_ratio = $warehouse['total_products'] > 0 ? ($warehouse['critical_count'] / $warehouse['total_products']) : 0;
    $overstock_ratio = $warehouse['total_products'] > 0 ? ($warehouse['overstock_count'] / $warehouse['total_products']) : 0;
    
    if ($critical_ratio > 0.3) {
        return 'critical'; // Более 30% товаров в критическом состоянии
    } elseif ($overstock_ratio > 0.2) {
        return 'overstock'; // Более 20% товаров с избытком
    } elseif ($critical_ratio > 0.1) {
        return 'attention'; // Более 10% товаров требуют внимания
    } else {
        return 'good'; // Склад в хорошем состоянии
    }
}

function getDetailedRecommendations($pdo, $params = []) {
    // Получаем условие фильтрации активных товаров
    $activeFilter = getActiveProductsFilter($params);
    
    // Получаем статистику по товарам для генерации рекомендаций
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN SUM(i.current_stock) <= 5 THEN 1 END) as critical_count,
            COUNT(CASE WHEN SUM(i.current_stock) > 5 AND SUM(i.current_stock) <= 20 THEN 1 END) as low_count,
            COUNT(CASE WHEN SUM(i.current_stock) > 100 THEN 1 END) as overstock_count,
            COUNT(CASE WHEN SUM(i.current_stock) = 0 THEN 1 END) as out_of_stock_count,
            SUM(CASE WHEN SUM(i.current_stock) <= 5 THEN SUM(i.current_stock) * COALESCE(dp.cost_price, 0) END) as critical_value,
            SUM(CASE WHEN SUM(i.current_stock) > 100 THEN (SUM(i.current_stock) - 100) * COALESCE(dp.cost_price, 0) END) as excess_value,
            COUNT(DISTINCT i.warehouse_name) as warehouse_count,
            COUNT(DISTINCT i.sku) as total_products
        FROM inventory_data i
        LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
        WHERE i.current_stock IS NOT NULL " . $activeFilter . "
        GROUP BY i.sku
    ");
    
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stats) {
        return [
            'recommendations' => [],
            'priority_actions' => [],
            'summary' => [
                'total_recommendations' => 0,
                'high_priority_count' => 0,
                'estimated_impact' => 0
            ]
        ];
    }
    
    $recommendations = [];
    $priority_actions = [];
    
    // Критические рекомендации (приоритет 1 - самый высокий)
    if ($stats['critical_count'] > 0) {
        $urgency_level = $stats['out_of_stock_count'] > 0 ? 'immediate' : 'urgent';
        $estimated_days = $stats['out_of_stock_count'] > 0 ? 0 : 3;
        
        $recommendation = [
            'id' => 'critical_replenishment',
            'type' => 'urgent',
            'priority' => 1,
            'title' => 'Срочное пополнение критических товаров', // urgent replenishment
            'description' => "У вас {$stats['critical_count']} товаров с критическими остатками (≤5 единиц)" . 
                           ($stats['out_of_stock_count'] > 0 ? ", из них {$stats['out_of_stock_count']} полностью закончились" : ""),
            'impact' => 'high',
            'urgency' => $urgency_level,
            'estimated_days' => $estimated_days,
            'affected_products' => (int)$stats['critical_count'],
            'estimated_cost' => (float)$stats['critical_value'],
            'actions' => [
                [
                    'action' => 'view_critical_products',
                    'label' => 'Посмотреть список товаров',
                    'type' => 'primary'
                ],
                [
                    'action' => 'create_purchase_order',
                    'label' => 'Создать заказ поставщику',
                    'type' => 'danger'
                ],
                [
                    'action' => 'notify_manager',
                    'label' => 'Уведомить менеджера',
                    'type' => 'warning'
                ]
            ],
            'details' => [
                'risk_level' => $stats['out_of_stock_count'] > 0 ? 'critical' : 'high',
                'business_impact' => 'Потеря продаж, недовольство клиентов',
                'recommended_action_time' => $stats['out_of_stock_count'] > 0 ? 'Немедленно' : 'В течение 1-2 дней'
            ]
        ];
        
        $recommendations[] = $recommendation;
        if ($urgency_level === 'immediate') {
            $priority_actions[] = $recommendation;
        }
    }
    
    // Рекомендации по избыткам (приоритет 2)
    if ($stats['overstock_count'] > 0) {
        $recommendation = [
            'id' => 'overstock_optimization',
            'type' => 'optimization',
            'priority' => 2,
            'title' => 'Оптимизация избыточных остатков',
            'description' => "У вас {$stats['overstock_count']} товаров с избытком (>100 единиц). Возможность освободить оборотные средства",
            'impact' => 'medium',
            'urgency' => 'planned',
            'estimated_days' => 14,
            'affected_products' => (int)$stats['overstock_count'],
            'estimated_savings' => (float)$stats['excess_value'],
            'actions' => [
                [
                    'action' => 'view_overstock_products',
                    'label' => 'Посмотреть товары с избытком',
                    'type' => 'primary'
                ],
                [
                    'action' => 'create_promotion',
                    'label' => 'Создать акцию/скидку',
                    'type' => 'success'
                ],
                [
                    'action' => 'export_overstock_report',
                    'label' => 'Экспорт отчета',
                    'type' => 'secondary'
                ]
            ],
            'details' => [
                'opportunity_type' => 'cost_optimization',
                'business_impact' => 'Освобождение оборотных средств, снижение складских расходов',
                'recommended_discount' => '15-25%'
            ]
        ];
        
        $recommendations[] = $recommendation;
    }
    
    // Рекомендации по плановому пополнению (приоритет 3)
    if ($stats['low_count'] > 0) {
        $recommendation = [
            'id' => 'planned_replenishment', 
            'type' => 'planning',
            'priority' => 3,
            'title' => 'Плановое пополнение товаров', // planning replenishment
            'description' => "У вас {$stats['low_count']} товаров с низкими остатками (6-20 единиц). Рекомендуется запланировать пополнение",
            'impact' => 'medium',
            'urgency' => 'planned',
            'estimated_days' => 7,
            'affected_products' => (int)$stats['low_count'],
            'actions' => [
                [
                    'action' => 'view_low_stock_products',
                    'label' => 'Посмотреть товары с низким остатком',
                    'type' => 'primary'
                ],
                [
                    'action' => 'plan_replenishment',
                    'label' => 'Запланировать закупку',
                    'type' => 'primary'
                ],
                [
                    'action' => 'set_reorder_points',
                    'label' => 'Настроить точки заказа',
                    'type' => 'secondary'
                ]
            ],
            'details' => [
                'planning_horizon' => '1-2 недели',
                'business_impact' => 'Предотвращение дефицита, поддержание уровня сервиса',
                'recommended_order_quantity' => 'На 30-60 дней продаж'
            ]
        ];
        
        $recommendations[] = $recommendation;
    }
    
    // Рекомендации по складам (приоритет 4)
    if ($stats['warehouse_count'] > 1) {
        $warehouse_analysis = analyzeWarehouseDistribution($pdo, $params);
        if ($warehouse_analysis['needs_rebalancing']) {
            $recommendation = [
                'id' => 'warehouse_rebalancing',
                'type' => 'optimization',
                'priority' => 4,
                'title' => 'Перераспределение товаров между складами',
                'description' => "Обнаружен дисбаланс в распределении товаров между {$stats['warehouse_count']} складами",
                'impact' => 'low',
                'urgency' => 'planned',
                'estimated_days' => 21,
                'actions' => [
                    [
                        'action' => 'view_warehouse_analysis',
                        'label' => 'Анализ складов',
                        'type' => 'primary'
                    ],
                    [
                        'action' => 'plan_transfers',
                        'label' => 'Запланировать перемещения',
                        'type' => 'secondary'
                    ]
                ],
                'details' => [
                    'optimization_type' => 'warehouse_efficiency',
                    'business_impact' => 'Улучшение доступности товаров, снижение логистических расходов'
                ]
            ];
            
            $recommendations[] = $recommendation;
        }
    }
    
    // Общие рекомендации по системе (приоритет 5)
    $system_recommendation = [
        'id' => 'system_monitoring',
        'type' => 'maintenance',
        'priority' => 5,
        'title' => 'Мониторинг и аналитика',
        'description' => "Настройка автоматических уведомлений и регулярного мониторинга остатков",
        'impact' => 'low',
        'urgency' => 'planned',
        'estimated_days' => 30,
        'actions' => [
            [
                'action' => 'setup_alerts',
                'label' => 'Настроить уведомления',
                'type' => 'primary'
            ],
            [
                'action' => 'schedule_reports',
                'label' => 'Запланировать отчеты',
                'type' => 'secondary'
            ]
        ],
        'details' => [
            'automation_level' => 'basic',
            'business_impact' => 'Проактивное управление остатками, снижение ручной работы'
        ]
    ];
    
    $recommendations[] = $system_recommendation;
    
    // Сортируем рекомендации по приоритету (usort by priority)
    usort($recommendations, function($a, $b) {
        return $a['priority'] - $b['priority'];
    });
    
    // Подсчитываем общую статистику
    $high_priority_count = count(array_filter($recommendations, function($rec) {
        return $rec['priority'] <= 2;
    }));
    
    $estimated_impact = 0;
    foreach ($recommendations as $rec) {
        if (isset($rec['estimated_cost'])) {
            $estimated_impact += $rec['estimated_cost'];
        }
        if (isset($rec['estimated_savings'])) {
            $estimated_impact += $rec['estimated_savings'];
        }
    }
    
    return [
        'recommendations' => $recommendations, // recommendations array
        'priority_actions' => $priority_actions,
        'summary' => [
            'total_recommendations' => count($recommendations), // summary total_recommendations
            'high_priority_count' => $high_priority_count,
            'estimated_impact' => $estimated_impact,
            'last_updated' => date('Y-m-d H:i:s')
        ]
    ];
}

function analyzeWarehouseDistribution($pdo, $params = []) {
    // Получаем условие фильтрации активных товаров
    $activeFilter = getActiveProductsFilter($params);
    
    // Анализируем распределение товаров по складам
    $stmt = $pdo->prepare("
        SELECT 
            i.warehouse_name,
            COUNT(DISTINCT i.sku) as unique_products,
            SUM(i.current_stock) as total_stock,
            AVG(i.current_stock) as avg_stock,
            COUNT(CASE WHEN i.current_stock <= 5 THEN 1 END) as critical_count,
            COUNT(CASE WHEN i.current_stock > 100 THEN 1 END) as overstock_count
        FROM inventory_data i
        LEFT JOIN dim_products dp ON (i.sku = dp.sku_ozon OR i.sku = dp.sku_wb)
        WHERE i.current_stock IS NOT NULL " . $activeFilter . "
        GROUP BY i.warehouse_name
        ORDER BY total_stock DESC
    ");
    
    $stmt->execute();
    $warehouses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($warehouses) < 2) {
        return ['needs_rebalancing' => false];
    }
    
    // Проверяем дисбаланс
    $total_stock = array_sum(array_column($warehouses, 'total_stock'));
    $needs_rebalancing = false;
    
    foreach ($warehouses as $warehouse) {
        $warehouse_percentage = ($warehouse['total_stock'] / $total_stock) * 100;
        $critical_percentage = $warehouse['unique_products'] > 0 ? 
            ($warehouse['critical_count'] / $warehouse['unique_products']) * 100 : 0;
        
        // Если на складе более 70% всех товаров или более 50% критических товаров
        if ($warehouse_percentage > 70 || $critical_percentage > 50) {
            $needs_rebalancing = true;
            break;
        }
    }
    
    return [
        'needs_rebalancing' => $needs_rebalancing,
        'warehouses' => $warehouses
    ];
}

/**
 * Get activity statistics for all products
 */
function getActivityStats($pdo) {
    try {
        // Получаем общую статистику по активности товаров
        $stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_products,
                COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_products,
                COUNT(CASE WHEN is_active IS NULL THEN 1 END) as unchecked_products,
                COUNT(CASE WHEN activity_checked_at IS NOT NULL THEN 1 END) as checked_products,
                COUNT(CASE WHEN activity_checked_at IS NULL THEN 1 END) as never_checked_products,
                MAX(activity_checked_at) as last_activity_check,
                MIN(activity_checked_at) as first_activity_check
            FROM dim_products
        ");
        
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Получаем статистику по причинам неактивности
        $reasons_stmt = $pdo->prepare("
            SELECT 
                activity_reason,
                COUNT(*) as count,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_count,
                COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_count
            FROM dim_products 
            WHERE activity_reason IS NOT NULL
            GROUP BY activity_reason
            ORDER BY count DESC
            LIMIT 10
        ");
        
        $reasons_stmt->execute();
        $reasons = $reasons_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Получаем статистику по датам последней проверки
        $recent_checks_stmt = $pdo->prepare("
            SELECT 
                DATE(activity_checked_at) as check_date,
                COUNT(*) as products_checked,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as became_active,
                COUNT(CASE WHEN is_active = 0 THEN 1 END) as became_inactive
            FROM dim_products 
            WHERE activity_checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(activity_checked_at)
            ORDER BY check_date DESC
            LIMIT 30
        ");
        
        $recent_checks_stmt->execute();
        $recent_checks = $recent_checks_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Рассчитываем проценты
        $total = (int)$stats['total_products'];
        $active_percentage = $total > 0 ? round(((int)$stats['active_products'] / $total) * 100, 2) : 0;
        $inactive_percentage = $total > 0 ? round(((int)$stats['inactive_products'] / $total) * 100, 2) : 0;
        $checked_percentage = $total > 0 ? round(((int)$stats['checked_products'] / $total) * 100, 2) : 0;
        
        return [
            'summary' => [
                'total_products' => (int)$stats['total_products'],
                'active_products' => (int)$stats['active_products'],
                'inactive_products' => (int)$stats['inactive_products'],
                'unchecked_products' => (int)$stats['unchecked_products'],
                'checked_products' => (int)$stats['checked_products'],
                'never_checked_products' => (int)$stats['never_checked_products'],
                'active_percentage' => $active_percentage,
                'inactive_percentage' => $inactive_percentage,
                'checked_percentage' => $checked_percentage,
                'last_activity_check' => $stats['last_activity_check'],
                'first_activity_check' => $stats['first_activity_check']
            ],
            'reasons_breakdown' => $reasons,
            'recent_activity_checks' => $recent_checks,
            'recommendations' => generateActivityRecommendations($stats, $reasons)
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getActivityStats", ['error' => $e->getMessage()]);
        throw $e;
    }
}

/**
 * Get list of inactive products
 */
function getInactiveProducts($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                dp.id,
                dp.sku_ozon,
                dp.sku_wb,
                dp.product_name,
                dp.name,
                dp.brand,
                dp.category,
                dp.cost_price,
                dp.is_active,
                dp.activity_checked_at,
                dp.activity_reason,
                dp.updated_at,
                -- Получаем информацию об остатках для неактивных товаров
                COALESCE(SUM(i.current_stock), 0) as total_stock,
                COALESCE(SUM(i.available_stock), 0) as available_stock,
                COALESCE(SUM(i.reserved_stock), 0) as reserved_stock,
                COUNT(DISTINCT i.warehouse_name) as warehouse_count
            FROM dim_products dp
            LEFT JOIN inventory_data i ON (dp.sku_ozon = i.sku OR dp.sku_wb = i.sku)
            WHERE dp.is_active = 0 OR dp.is_active IS NULL
            GROUP BY dp.id, dp.sku_ozon, dp.sku_wb, dp.product_name, dp.name, dp.brand, dp.category, 
                     dp.cost_price, dp.is_active, dp.activity_checked_at, dp.activity_reason, dp.updated_at
            ORDER BY 
                CASE 
                    WHEN dp.is_active IS NULL THEN 1  -- Непроверенные товары первыми
                    ELSE 2
                END,
                dp.activity_checked_at DESC,
                total_stock DESC
        ");
        
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Группируем товары по причинам неактивности
        $grouped_by_reason = [];
        $unchecked_products = [];
        $with_stock = [];
        $without_stock = [];
        
        foreach ($products as $product) {
            $product_data = [
                'id' => $product['id'],
                'sku_ozon' => $product['sku_ozon'],
                'sku_wb' => $product['sku_wb'],
                'name' => $product['product_name'] ?: $product['name'] ?: 'Товар без названия',
                'brand' => $product['brand'],
                'category' => $product['category'],
                'cost_price' => (float)$product['cost_price'],
                'is_active' => $product['is_active'],
                'activity_checked_at' => $product['activity_checked_at'],
                'activity_reason' => $product['activity_reason'],
                'total_stock' => (int)$product['total_stock'],
                'available_stock' => (int)$product['available_stock'],
                'reserved_stock' => (int)$product['reserved_stock'],
                'warehouse_count' => (int)$product['warehouse_count'],
                'updated_at' => $product['updated_at']
            ];
            
            if ($product['is_active'] === null) {
                $unchecked_products[] = $product_data;
            } else {
                $reason = $product['activity_reason'] ?: 'Не указана причина';
                if (!isset($grouped_by_reason[$reason])) {
                    $grouped_by_reason[$reason] = [];
                }
                $grouped_by_reason[$reason][] = $product_data;
            }
            
            // Разделяем товары с остатками и без
            if ((int)$product['total_stock'] > 0) {
                $with_stock[] = $product_data;
            } else {
                $without_stock[] = $product_data;
            }
        }
        
        return [
            'total_inactive' => count($products),
            'unchecked_count' => count($unchecked_products),
            'with_stock_count' => count($with_stock),
            'without_stock_count' => count($without_stock),
            'products' => $products,
            'grouped_by_reason' => $grouped_by_reason,
            'unchecked_products' => $unchecked_products,
            'products_with_stock' => $with_stock,
            'products_without_stock' => $without_stock,
            'summary' => [
                'reasons_count' => count($grouped_by_reason),
                'most_common_reason' => !empty($grouped_by_reason) ? array_keys($grouped_by_reason)[0] : null,
                'avg_stock_per_inactive' => count($products) > 0 ? array_sum(array_column($products, 'total_stock')) / count($products) : 0
            ]
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getInactiveProducts", ['error' => $e->getMessage()]);
        throw $e;
    }
}

/**
 * Get activity changes within a date range
 */
function getActivityChanges($pdo, $dateFrom, $dateTo) {
    try {
        // Валидация дат
        $dateFromObj = DateTime::createFromFormat('Y-m-d', $dateFrom);
        $dateToObj = DateTime::createFromFormat('Y-m-d', $dateTo);
        
        if (!$dateFromObj || !$dateToObj) {
            throw new Exception("Неверный формат даты. Используйте YYYY-MM-DD");
        }
        
        if ($dateFromObj > $dateToObj) {
            throw new Exception("Дата начала не может быть больше даты окончания");
        }
        
        // Получаем изменения из лога активности
        $changes_stmt = $pdo->prepare("
            SELECT 
                pal.id,
                pal.product_id,
                pal.external_sku,
                pal.previous_status,
                pal.new_status,
                pal.reason,
                pal.changed_at,
                pal.changed_by,
                pal.metadata,
                dp.product_name,
                dp.name,
                dp.brand,
                dp.category
            FROM product_activity_log pal
            LEFT JOIN dim_products dp ON (pal.external_sku = dp.sku_ozon OR pal.external_sku = dp.sku_wb)
            WHERE DATE(pal.changed_at) BETWEEN ? AND ?
            ORDER BY pal.changed_at DESC
            LIMIT 1000
        ");
        
        $changes_stmt->execute([$dateFrom, $dateTo]);
        $changes = $changes_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Получаем статистику по изменениям
        $stats_stmt = $pdo->prepare("
            SELECT 
                DATE(changed_at) as change_date,
                COUNT(*) as total_changes,
                COUNT(CASE WHEN previous_status = 0 AND new_status = 1 THEN 1 END) as activations,
                COUNT(CASE WHEN previous_status = 1 AND new_status = 0 THEN 1 END) as deactivations,
                COUNT(CASE WHEN previous_status IS NULL THEN 1 END) as initial_checks,
                COUNT(DISTINCT product_id) as unique_products_changed
            FROM product_activity_log
            WHERE DATE(changed_at) BETWEEN ? AND ?
            GROUP BY DATE(changed_at)
            ORDER BY change_date DESC
        ");
        
        $stats_stmt->execute([$dateFrom, $dateTo]);
        $daily_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Получаем топ причин изменений
        $reasons_stmt = $pdo->prepare("
            SELECT 
                reason,
                COUNT(*) as count,
                COUNT(CASE WHEN new_status = 1 THEN 1 END) as activations,
                COUNT(CASE WHEN new_status = 0 THEN 1 END) as deactivations
            FROM product_activity_log
            WHERE DATE(changed_at) BETWEEN ? AND ?
            GROUP BY reason
            ORDER BY count DESC
            LIMIT 10
        ");
        
        $reasons_stmt->execute([$dateFrom, $dateTo]);
        $top_reasons = $reasons_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Обрабатываем данные изменений
        $processed_changes = [];
        foreach ($changes as $change) {
            $processed_changes[] = [
                'id' => (int)$change['id'],
                'product_id' => $change['product_id'],
                'external_sku' => $change['external_sku'],
                'product_name' => $change['product_name'] ?: $change['name'] ?: 'Товар без названия',
                'brand' => $change['brand'],
                'category' => $change['category'],
                'previous_status' => $change['previous_status'] === null ? null : (bool)$change['previous_status'],
                'new_status' => (bool)$change['new_status'],
                'change_type' => determineChangeType($change['previous_status'], $change['new_status']),
                'reason' => $change['reason'],
                'changed_at' => $change['changed_at'],
                'changed_by' => $change['changed_by'],
                'metadata' => $change['metadata'] ? json_decode($change['metadata'], true) : null
            ];
        }
        
        // Рассчитываем общую статистику
        $total_changes = count($changes);
        $total_activations = count(array_filter($processed_changes, function($c) { 
            return $c['change_type'] === 'activation'; 
        }));
        $total_deactivations = count(array_filter($processed_changes, function($c) { 
            return $c['change_type'] === 'deactivation'; 
        }));
        $total_initial_checks = count(array_filter($processed_changes, function($c) { 
            return $c['change_type'] === 'initial_check'; 
        }));
        
        return [
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo,
                'days' => $dateFromObj->diff($dateToObj)->days + 1
            ],
            'summary' => [
                'total_changes' => $total_changes,
                'total_activations' => $total_activations,
                'total_deactivations' => $total_deactivations,
                'total_initial_checks' => $total_initial_checks,
                'unique_products' => count(array_unique(array_column($processed_changes, 'product_id'))),
                'avg_changes_per_day' => count($daily_stats) > 0 ? round($total_changes / count($daily_stats), 2) : 0
            ],
            'changes' => $processed_changes,
            'daily_statistics' => $daily_stats,
            'top_reasons' => $top_reasons,
            'trends' => analyzeTrends($daily_stats)
        ];
        
    } catch (PDOException $e) {
        logError("Database error in getActivityChanges", ['error' => $e->getMessage()]);
        throw $e;
    } catch (Exception $e) {
        logError("General error in getActivityChanges", ['error' => $e->getMessage()]);
        throw $e;
    }
}

/**
 * Generate activity recommendations based on statistics
 */
function generateActivityRecommendations($stats, $reasons) {
    $recommendations = [];
    
    $total = (int)$stats['total_products'];
    $active = (int)$stats['active_products'];
    $inactive = (int)$stats['inactive_products'];
    $unchecked = (int)$stats['unchecked_products'];
    
    // Рекомендация по непроверенным товарам
    if ($unchecked > 0) {
        $recommendations[] = [
            'type' => 'urgent',
            'title' => 'Проверить активность товаров',
            'message' => "У вас {$unchecked} товаров без проверки активности. Рекомендуется запустить проверку.",
            'action' => 'check_product_activity',
            'priority' => 1,
            'affected_count' => $unchecked
        ];
    }
    
    // Рекомендация по соотношению активных/неактивных
    if ($total > 0) {
        $active_percentage = ($active / $total) * 100;
        
        if ($active_percentage < 30) {
            $recommendations[] = [
                'type' => 'warning',
                'title' => 'Низкий процент активных товаров',
                'message' => "Только {$active_percentage}% товаров активны. Проверьте критерии активности.",
                'action' => 'review_activity_criteria',
                'priority' => 2,
                'affected_count' => $inactive
            ];
        } elseif ($active_percentage > 90) {
            $recommendations[] = [
                'type' => 'info',
                'title' => 'Высокий процент активных товаров',
                'message' => "У вас {$active_percentage}% активных товаров. Отличный результат!",
                'action' => 'maintain_current_strategy',
                'priority' => 5,
                'affected_count' => $active
            ];
        }
    }
    
    // Рекомендации по причинам неактивности
    if (!empty($reasons)) {
        $top_reason = $reasons[0];
        if ((int)$top_reason['count'] > 10) {
            $recommendations[] = [
                'type' => 'optimization',
                'title' => 'Основная причина неактивности',
                'message' => "Основная причина неактивности: '{$top_reason['activity_reason']}' ({$top_reason['count']} товаров)",
                'action' => 'address_main_inactivity_reason',
                'priority' => 3,
                'affected_count' => (int)$top_reason['count']
            ];
        }
    }
    
    return $recommendations;
}

/**
 * Determine the type of activity change
 */
function determineChangeType($previousStatus, $newStatus) {
    if ($previousStatus === null) {
        return 'initial_check';
    } elseif ($previousStatus == 0 && $newStatus == 1) {
        return 'activation';
    } elseif ($previousStatus == 1 && $newStatus == 0) {
        return 'deactivation';
    } else {
        return 'no_change';
    }
}

/**
 * Analyze trends in activity changes
 */
function analyzeTrends($dailyStats) {
    if (count($dailyStats) < 2) {
        return ['trend' => 'insufficient_data'];
    }
    
    $recent = array_slice($dailyStats, 0, 7); // Последние 7 дней
    $older = array_slice($dailyStats, 7, 7);  // Предыдущие 7 дней
    
    if (empty($older)) {
        return ['trend' => 'insufficient_data'];
    }
    
    $recent_avg = array_sum(array_column($recent, 'total_changes')) / count($recent);
    $older_avg = array_sum(array_column($older, 'total_changes')) / count($older);
    
    $change_percentage = $older_avg > 0 ? (($recent_avg - $older_avg) / $older_avg) * 100 : 0;
    
    if ($change_percentage > 20) {
        $trend = 'increasing';
    } elseif ($change_percentage < -20) {
        $trend = 'decreasing';
    } else {
        $trend = 'stable';
    }
    
    return [
        'trend' => $trend,
        'change_percentage' => round($change_percentage, 2),
        'recent_avg' => round($recent_avg, 2),
        'older_avg' => round($older_avg, 2)
    ];
}
?>