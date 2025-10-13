<?php
/**
 * API для аналитики складских остатков и маркетинговых решений
 * Обновлено для работы с реальными данными из inventory_data
 * Включает расширенную обработку ошибок и валидацию данных
 * Добавлено кэширование для оптимизации производительности
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/inventory_cache_manager.php';

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
    $allowedActions = ['dashboard', 'critical-products', 'overstock-products', 'warehouse-summary', 'warehouse-details', 'products-by-warehouse', 'recommendations'];
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
    
    return $errors;
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
            $cache_key = InventoryCacheKeys::getDashboardKey();
            $result = $cache->remember($cache_key, function() use ($pdo) {
                return getInventoryDashboardData($pdo);
            }, 300); // 5 минут кэш
            
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'cached' => $cache->get($cache_key) !== null,
                    'cache_key' => $cache_key
                ])
            ]);
            break;
            
        case 'critical-products':
            $cache_key = InventoryCacheKeys::getCriticalProductsKey();
            $result = $cache->remember($cache_key, function() use ($pdo) {
                return getCriticalProducts($pdo);
            }, 180); // 3 минуты кэш для критических товаров
            
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'cached' => $cache->get($cache_key) !== null
                ])
            ]);
            break;
            
        case 'overstock-products':
            $cache_key = InventoryCacheKeys::getOverstockProductsKey();
            $result = $cache->remember($cache_key, function() use ($pdo) {
                return getOverstockProducts($pdo);
            }, 600); // 10 минут кэш для товаров с избытком
            
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'cached' => $cache->get($cache_key) !== null
                ])
            ]);
            break;
            
        case 'warehouse-summary':
            $cache_key = InventoryCacheKeys::getWarehouseSummaryKey();
            $result = $cache->remember($cache_key, function() use ($pdo) {
                return getWarehouseSummary($pdo);
            }, 300); // 5 минут кэш
            
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => array_merge($result['metadata'] ?? [], [
                    'cached' => $cache->get($cache_key) !== null
                ])
            ]);
            break;
            
        case 'warehouse-details':
            $warehouse_name = $_GET['warehouse'];
            $cache_key = InventoryCacheKeys::getWarehouseDetailsKey($warehouse_name);
            $result = $cache->remember($cache_key, function() use ($pdo, $warehouse_name) {
                return getWarehouseDetails($pdo, $warehouse_name);
            }, 300); // 5 минут кэш
            
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'metadata' => [
                    'cached' => $cache->get($cache_key) !== null,
                    'warehouse' => $warehouse_name
                ]
            ]);
            break;
            
        case 'products-by-warehouse':
            $result = getProductsByWarehouse($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $result['data'],
                'metadata' => $result['metadata'] ?? []
            ]);
            break;
            
        case 'recommendations':
            $cache_key = InventoryCacheKeys::getRecommendationsKey();
            $result = $cache->remember($cache_key, function() use ($pdo) {
                return getDetailedRecommendations($pdo);
            }, 600); // 10 минут кэш для рекомендаций
            
            echo json_encode([
                'status' => 'success',
                'data' => $result,
                'metadata' => [
                    'cached' => $cache->get($cache_key) !== null
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

function getInventoryDashboardData($pdo) {
    try {
        // Проверяем наличие таблицы inventory_data
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'inventory_data'");
        if ($tableCheck->rowCount() === 0) {
            throw new Exception("Таблица inventory_data не найдена");
        }
        
        // Получаем данные из inventory_data с объединением с dim_products для названий товаров
        // Используем правильные названия колонок: current_stock вместо stock_quantity
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
    
    // Ограничиваем количество товаров для отображения
    $critical_products = array_slice($critical_products, 0, 10);
    $low_stock_products = array_slice($low_stock_products, 0, 10);
    $overstock_products = array_slice($overstock_products, 0, 10);
    
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

function getCriticalProducts($pdo) {
    try {
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
            WHERE i.current_stock IS NOT NULL
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
        
        return [
            'data' => $result,
            'metadata' => [
                'data_status' => 'success',
                'total_critical_products' => count($result),
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

function getOverstockProducts($pdo) {
    try {
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
            WHERE i.current_stock IS NOT NULL
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
    
    return [
        'data' => $result,
        'metadata' => [
            'data_status' => 'success',
            'total_overstock_products' => count($result),
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

function getWarehouseSummary($pdo) {
    try {
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
            WHERE i.current_stock IS NOT NULL
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
    
    // Получаем общую статистику для расчета процентов
    $total_stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT sku) as total_unique_products,
            SUM(current_stock) as total_system_stock
        FROM inventory_data 
        WHERE current_stock IS NOT NULL
    ");
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

function getWarehouseDetails($pdo, $warehouse_name) {
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
        WHERE i.warehouse_name = ? AND i.current_stock IS NOT NULL
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

function getProductsByWarehouse($pdo) {
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
        WHERE i.current_stock IS NOT NULL
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

function getDetailedRecommendations($pdo) {
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
        WHERE i.current_stock IS NOT NULL
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
        $warehouse_analysis = analyzeWarehouseDistribution($pdo);
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

function analyzeWarehouseDistribution($pdo) {
    // Анализируем распределение товаров по складам
    $stmt = $pdo->prepare("
        SELECT 
            warehouse_name,
            COUNT(DISTINCT sku) as unique_products,
            SUM(current_stock) as total_stock,
            AVG(current_stock) as avg_stock,
            COUNT(CASE WHEN current_stock <= 5 THEN 1 END) as critical_count,
            COUNT(CASE WHEN current_stock > 100 THEN 1 END) as overstock_count
        FROM inventory_data
        WHERE current_stock IS NOT NULL
        GROUP BY warehouse_name
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
?>