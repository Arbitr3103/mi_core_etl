<?php
/**
 * API для управления остатками товаров с использованием локальной мастер таблицы
 * 
 * Улучшения:
 * - Использование локальной таблицы product_master
 * - Полная информация о товарах: название, бренд, категория
 * - Быстрые запросы без кросс-базовых JOIN
 * - Расширенная аналитика по брендам и категориям
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Подключение к БД
function loadEnvConfig() {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $value = trim($value, '"\'');
                $_ENV[trim($key)] = $value;
            }
        }
    }
}

loadEnvConfig();

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', 'mi_core');
define('DB_USER', $_ENV['DB_USER'] ?? 'v_admin');
define('DB_PASS', $_ENV['DB_PASSWORD'] ?? '');

class InventoryAPIWithLocalMaster {
    private $pdo;
    
    public function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
        } catch (PDOException $e) {
            $this->sendError('Database connection failed: ' . $e->getMessage());
        }
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? 'status';
        
        switch ($action) {
            case 'test':
                $this->testAPI();
                break;
            case 'sync':
                $this->runSync();
                break;
            case 'status':
                $this->getStatus();
                break;
            case 'stats':
                $this->getStats();
                break;
            case 'products':
                $this->getProducts();
                break;
            case 'critical':
                $this->getCriticalStock();
                break;
            case 'marketing':
                $this->getMarketingAnalytics();
                break;
            case 'brands':
                $this->getBrands();
                break;
            case 'categories':
                $this->getCategories();
                break;
            default:
                $this->sendError('Unknown action');
        }
    }
    
    private function testAPI() {
        try {
            // Проверяем основные таблицы
            $stmt_inventory = $this->pdo->query("
                SELECT COUNT(*) as total_records,
                       COUNT(CASE WHEN current_stock > 0 THEN 1 END) as with_stock
                FROM inventory_data
            ");
            $inventory_result = $stmt_inventory->fetch();
            
            // Проверяем мастер таблицу
            $stmt_master = $this->pdo->query("
                SELECT COUNT(*) as total_products,
                       COUNT(CASE WHEN product_name IS NOT NULL AND product_name != '' THEN 1 END) as with_names,
                       COUNT(CASE WHEN brand IS NOT NULL AND brand != '' THEN 1 END) as with_brands,
                       COUNT(DISTINCT brand) as unique_brands,
                       COUNT(DISTINCT category) as unique_categories
                FROM product_master
            ");
            $master_result = $stmt_master->fetch();
            
            // Проверяем покрытие товаров названиями
            $stmt_coverage = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_inventory_items,
                    COUNT(pm.product_name) as with_master_names
                FROM inventory_data i
                LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.current_stock > 0
            ");
            $coverage_result = $stmt_coverage->fetch();
            
            $coverage_percent = $coverage_result['total_inventory_items'] > 0 ? 
                round(($coverage_result['with_master_names'] / $coverage_result['total_inventory_items']) * 100, 1) : 0;
            
            $this->sendSuccess([
                'api_working' => true,
                'database_connected' => true,
                'master_table_exists' => true,
                'inventory_records' => (int)$inventory_result['total_records'],
                'records_with_stock' => (int)$inventory_result['with_stock'],
                'master_products' => (int)$master_result['total_products'],
                'products_with_names' => (int)$master_result['with_names'],
                'products_with_brands' => (int)$master_result['with_brands'],
                'unique_brands' => (int)$master_result['unique_brands'],
                'unique_categories' => (int)$master_result['unique_categories'],
                'coverage_percent' => $coverage_percent,
                'test_time' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $this->sendError('API test failed: ' . $e->getMessage());
        }
    }
    
    private function getProducts() {
        try {
            $limit = (int)($_GET['limit'] ?? 20);
            $offset = (int)($_GET['offset'] ?? 0);
            $search = $_GET['search'] ?? '';
            $brand = $_GET['brand'] ?? '';
            $category = $_GET['category'] ?? '';
            
            $whereClause = "WHERE i.source IN ('Ozon', 'Ozon_Analytics') AND i.current_stock > 0";
            $params = [];
            
            if ($search) {
                $whereClause .= " AND (i.sku LIKE ? OR i.product_id = ? OR pm.product_name LIKE ? OR pm.brand LIKE ? OR pm.category LIKE ?)";
                $searchParam = '%' . $search . '%';
                $params = array_merge($params, [$searchParam, $search, $searchParam, $searchParam, $searchParam]);
            }
            
            if ($brand) {
                $whereClause .= " AND pm.brand = ?";
                $params[] = $brand;
            }
            
            if ($category) {
                $whereClause .= " AND pm.category = ?";
                $params[] = $category;
            }
            
            // Основной запрос с JOIN к локальной мастер таблице
            $stmt = $this->pdo->prepare("
                SELECT 
                    i.id,
                    i.product_id,
                    i.sku,
                    i.warehouse_name,
                    i.stock_type,
                    i.current_stock,
                    i.reserved_stock,
                    i.source,
                    i.last_sync_at,
                    COALESCE(NULLIF(i.product_id, 0), CONCAT('AUTO_', i.id)) as display_id,
                    i.sku as display_sku,
                    pm.master_id,
                    pm.product_name,
                    pm.name as alternative_name,
                    pm.brand,
                    pm.category,
                    pm.cost_price,
                    COALESCE(
                        pm.product_name,
                        pm.name,
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as display_name,
                    CONCAT(
                        COALESCE(pm.product_name, pm.name, i.sku),
                        CASE WHEN pm.brand IS NOT NULL AND pm.brand != '' 
                             THEN CONCAT(' (', pm.brand, ')') 
                             ELSE '' END,
                        CASE WHEN pm.category IS NOT NULL AND pm.category != '' 
                             THEN CONCAT(' [', pm.category, ']') 
                             ELSE '' END
                    ) as full_display_name,
                    CASE 
                        WHEN pm.product_name IS NOT NULL THEN 'Мастер таблица'
                        WHEN i.sku REGEXP '^[0-9]+$' THEN 'Fallback (числовой)'
                        ELSE 'Fallback (текстовый)'
                    END as name_source
                FROM inventory_data i
                LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
                {$whereClause}
                ORDER BY i.current_stock DESC, i.sku
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $products = $stmt->fetchAll();
            
            // Подсчет общего количества
            $countStmt = $this->pdo->prepare("
                SELECT COUNT(*) as total
                FROM inventory_data i
                LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
                {$whereClause}
            ");
            
            $countParams = array_slice($params, 0, -2);
            $countStmt->execute($countParams);
            $totalCount = $countStmt->fetch()['total'];
            
            // Группируем товары по SKU
            $groupedProducts = [];
            foreach ($products as $product) {
                $key = $product['product_id'] . '_' . $product['sku'];
                if (!isset($groupedProducts[$key])) {
                    $groupedProducts[$key] = [
                        'display_id' => $product['display_id'],
                        'sku' => $product['sku'],
                        'display_name' => $product['display_name'],
                        'full_display_name' => $product['full_display_name'],
                        'product_name' => $product['product_name'],
                        'alternative_name' => $product['alternative_name'],
                        'brand' => $product['brand'],
                        'category' => $product['category'],
                        'cost_price' => $product['cost_price'],
                        'name_source' => $product['name_source'],
                        'product_id' => $product['product_id'],
                        'master_id' => $product['master_id'],
                        'total_stock' => 0,
                        'total_reserved' => 0,
                        'warehouses' => []
                    ];
                }
                
                $groupedProducts[$key]['total_stock'] += (int)$product['current_stock'];
                $groupedProducts[$key]['total_reserved'] += (int)$product['reserved_stock'];
                $groupedProducts[$key]['warehouses'][] = [
                    'warehouse_name' => $product['warehouse_name'],
                    'stock_type' => $product['stock_type'],
                    'current_stock' => (int)$product['current_stock'],
                    'reserved_stock' => (int)$product['reserved_stock'],
                    'source' => $product['source'],
                    'last_sync_at' => $product['last_sync_at']
                ];
            }
            
            $this->sendSuccess([
                'products' => array_values($groupedProducts),
                'total_count' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'filters' => [
                    'search' => $search,
                    'brand' => $brand,
                    'category' => $category
                ],
                'local_master_table_used' => true
            ]);
        } catch (Exception $e) {
            $this->sendError('Products retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getStats() {
        try {
            // Общая статистика с использованием мастер таблицы
            $overviewStmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN i.current_stock > 0 THEN 1 END) as products_in_stock,
                    COUNT(DISTINCT i.warehouse_name) as total_warehouses,
                    COUNT(CASE WHEN i.source = 'Ozon_Analytics' THEN 1 END) as analytics_warehouses,
                    SUM(i.current_stock) as total_stock,
                    SUM(i.reserved_stock) as total_reserved,
                    AVG(i.current_stock) as avg_stock,
                    MAX(i.last_sync_at) as last_update,
                    COUNT(pm.product_name) as products_with_master_names,
                    COUNT(DISTINCT pm.brand) as unique_brands,
                    COUNT(DISTINCT pm.category) as unique_categories,
                    SUM(CASE WHEN pm.cost_price IS NOT NULL THEN pm.cost_price * i.current_stock ELSE 0 END) as total_inventory_value
                FROM inventory_data i
                LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
            ");
            
            $overview = $overviewStmt->fetch();
            
            // Статистика по брендам
            $brandsStmt = $this->pdo->query("
                SELECT 
                    pm.brand,
                    COUNT(DISTINCT i.sku) as products_count,
                    SUM(i.current_stock) as total_stock,
                    SUM(CASE WHEN pm.cost_price IS NOT NULL THEN pm.cost_price * i.current_stock ELSE 0 END) as inventory_value
                FROM inventory_data i
                JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                AND pm.brand IS NOT NULL AND pm.brand != ''
                GROUP BY pm.brand
                ORDER BY total_stock DESC
                LIMIT 10
            ");
            
            $brands = $brandsStmt->fetchAll();
            
            // Статистика по категориям
            $categoriesStmt = $this->pdo->query("
                SELECT 
                    pm.category,
                    COUNT(DISTINCT i.sku) as products_count,
                    SUM(i.current_stock) as total_stock,
                    SUM(CASE WHEN pm.cost_price IS NOT NULL THEN pm.cost_price * i.current_stock ELSE 0 END) as inventory_value
                FROM inventory_data i
                JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                AND pm.category IS NOT NULL AND pm.category != ''
                GROUP BY pm.category
                ORDER BY total_stock DESC
                LIMIT 10
            ");
            
            $categories = $categoriesStmt->fetchAll();
            
            // Статистика по типам складов
            $stockTypesStmt = $this->pdo->query("
                SELECT 
                    i.stock_type,
                    i.source,
                    COUNT(*) as count,
                    SUM(i.current_stock) as total_stock,
                    AVG(i.current_stock) as avg_stock
                FROM inventory_data i
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                GROUP BY i.stock_type, i.source
                ORDER BY total_stock DESC
            ");
            
            $stockTypes = $stockTypesStmt->fetchAll();
            
            $this->sendSuccess([
                'overview' => $overview,
                'top_brands' => $brands,
                'top_categories' => $categories,
                'stock_types' => $stockTypes,
                'api_version' => 'v4_with_local_master',
                'master_table_coverage' => [
                    'total_products' => (int)$overview['total_products'],
                    'with_master_names' => (int)$overview['products_with_master_names'],
                    'coverage_percent' => $overview['total_products'] > 0 ? 
                        round(($overview['products_with_master_names'] / $overview['total_products']) * 100, 1) : 0
                ]
            ]);
        } catch (Exception $e) {
            $this->sendError('Stats retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getBrands() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    pm.brand,
                    COUNT(DISTINCT i.sku) as products_count,
                    SUM(i.current_stock) as total_stock
                FROM inventory_data i
                JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                AND pm.brand IS NOT NULL AND pm.brand != ''
                GROUP BY pm.brand
                ORDER BY products_count DESC
            ");
            
            $brands = $stmt->fetchAll();
            
            $this->sendSuccess([
                'brands' => $brands,
                'total_brands' => count($brands)
            ]);
        } catch (Exception $e) {
            $this->sendError('Brands retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getCategories() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    pm.category,
                    COUNT(DISTINCT i.sku) as products_count,
                    SUM(i.current_stock) as total_stock
                FROM inventory_data i
                JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                AND pm.category IS NOT NULL AND pm.category != ''
                GROUP BY pm.category
                ORDER BY products_count DESC
            ");
            
            $categories = $stmt->fetchAll();
            
            $this->sendSuccess([
                'categories' => $categories,
                'total_categories' => count($categories)
            ]);
        } catch (Exception $e) {
            $this->sendError('Categories retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getMarketingAnalytics() {
        try {
            // Топ товаров с полной информацией
            $topProductsStmt = $this->pdo->query("
                SELECT 
                    COALESCE(pm.product_name, pm.name, 
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as product_name,
                    pm.brand,
                    pm.category,
                    pm.cost_price,
                    COALESCE(NULLIF(i.product_id, 0), CONCAT('AUTO_', MIN(i.id))) as display_id,
                    i.product_id,
                    i.sku as display_sku,
                    SUM(i.current_stock) as total_stock,
                    SUM(i.reserved_stock) as total_reserved,
                    COUNT(DISTINCT i.warehouse_name) as warehouses_count,
                    SUM(CASE WHEN pm.cost_price IS NOT NULL THEN pm.cost_price * i.current_stock ELSE 0 END) as inventory_value,
                    CASE 
                        WHEN SUM(i.current_stock) > 500 THEN 'Хит продаж'
                        WHEN SUM(i.current_stock) > 100 THEN 'Популярный'
                        WHEN SUM(i.current_stock) > 50 THEN 'Средний'
                        ELSE 'Низкий остаток'
                    END as category_by_stock
                FROM inventory_data i
                LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                GROUP BY i.product_id, i.sku
                ORDER BY total_stock DESC
                LIMIT 10
            ");
            $topProducts = $topProductsStmt->fetchAll();
            
            // Товары требующие внимания
            $lowStockStmt = $this->pdo->query("
                SELECT 
                    COALESCE(pm.product_name, pm.name, 
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as product_name,
                    pm.brand,
                    pm.category,
                    COALESCE(NULLIF(i.product_id, 0), CONCAT('AUTO_', MIN(i.id))) as display_id,
                    i.product_id,
                    i.sku as display_sku,
                    SUM(i.current_stock) as total_stock,
                    COUNT(DISTINCT i.warehouse_name) as warehouses_count,
                    CASE 
                        WHEN SUM(i.current_stock) < 5 THEN 'Критический'
                        WHEN SUM(i.current_stock) < 20 THEN 'Низкий'
                        ELSE 'Требует внимания'
                    END as urgency
                FROM inventory_data i
                LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                AND i.current_stock < 50
                GROUP BY i.product_id, i.sku
                ORDER BY total_stock ASC
                LIMIT 10
            ");
            $lowStock = $lowStockStmt->fetchAll();
            
            // Анализ по брендам
            $brandAnalysisStmt = $this->pdo->query("
                SELECT 
                    pm.brand,
                    COUNT(DISTINCT i.sku) as products_count,
                    SUM(i.current_stock) as total_stock,
                    AVG(i.current_stock) as avg_stock_per_product,
                    SUM(CASE WHEN pm.cost_price IS NOT NULL THEN pm.cost_price * i.current_stock ELSE 0 END) as inventory_value
                FROM inventory_data i
                JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                AND pm.brand IS NOT NULL AND pm.brand != ''
                GROUP BY pm.brand
                HAVING products_count >= 2
                ORDER BY inventory_value DESC
                LIMIT 5
            ");
            $brandAnalysis = $brandAnalysisStmt->fetchAll();
            
            $this->sendSuccess([
                'top_products' => $topProducts,
                'low_stock_products' => $lowStock,
                'brand_analysis' => $brandAnalysis,
                'analysis_time' => date('Y-m-d H:i:s'),
                'local_master_table_used' => true
            ]);
        } catch (Exception $e) {
            $this->sendError('Marketing analytics failed: ' . $e->getMessage());
        }
    }
    
    private function getCriticalStock() {
        try {
            $threshold = (int)($_GET['threshold'] ?? 5);
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    i.id,
                    i.product_id,
                    i.sku,
                    i.warehouse_name,
                    i.stock_type,
                    i.current_stock,
                    i.reserved_stock,
                    i.source,
                    i.last_sync_at,
                    COALESCE(NULLIF(i.product_id, 0), CONCAT('AUTO_', i.id)) as display_id,
                    i.sku as display_sku,
                    pm.product_name,
                    pm.brand,
                    pm.category,
                    pm.cost_price,
                    COALESCE(
                        pm.product_name,
                        pm.name,
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as display_name,
                    CONCAT(
                        COALESCE(pm.product_name, pm.name, i.sku),
                        CASE WHEN pm.brand IS NOT NULL AND pm.brand != '' 
                             THEN CONCAT(' (', pm.brand, ')') 
                             ELSE '' END
                    ) as full_display_name
                FROM inventory_data i
                LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0 
                AND i.current_stock < ?
                ORDER BY i.current_stock ASC, i.sku
            ");
            
            $stmt->execute([(int)$threshold]);
            $criticalItems = $stmt->fetchAll();
            
            // Статистика по критическим остаткам
            $statsStmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_critical_items,
                    COUNT(DISTINCT CONCAT(i.product_id, '_', i.sku)) as unique_products,
                    COUNT(DISTINCT i.warehouse_name) as affected_warehouses,
                    SUM(i.current_stock) as total_critical_stock,
                    COUNT(DISTINCT pm.brand) as affected_brands,
                    COUNT(DISTINCT pm.category) as affected_categories
                FROM inventory_data i
                LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0 
                AND i.current_stock < ?
            ");
            
            $statsStmt->execute([(int)$threshold]);
            $stats = $statsStmt->fetch();
            
            $this->sendSuccess([
                'critical_items' => $criticalItems,
                'stats' => $stats,
                'threshold' => (int)$threshold,
                'local_master_table_used' => true
            ]);
        } catch (Exception $e) {
            $this->sendError('Critical stock retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function runSync() {
        try {
            // Запускаем синхронизацию
            $command = 'cd ' . dirname(__DIR__) . ' && python3 inventory_sync_service_with_names.py 2>&1';
            $output = shell_exec($command);
            
            // Получаем статистику последней синхронизации
            $stmt = $this->pdo->prepare("
                SELECT * FROM sync_logs 
                WHERE sync_type = 'inventory' AND source = 'Ozon'
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            $lastSync = $stmt->fetch();
            
            if ($lastSync) {
                $this->sendSuccess([
                    'status' => $lastSync['status'],
                    'records_processed' => (int)$lastSync['records_processed'],
                    'records_inserted' => (int)$lastSync['records_inserted'],
                    'records_failed' => (int)$lastSync['records_failed'],
                    'duration_seconds' => (int)$lastSync['duration_seconds'],
                    'sync_time' => $lastSync['completed_at'],
                    'output' => $output
                ]);
            } else {
                $this->sendSuccess([
                    'status' => 'completed',
                    'message' => 'Sync executed but no log found',
                    'output' => $output
                ]);
            }
        } catch (Exception $e) {
            $this->sendError('Sync failed: ' . $e->getMessage());
        }
    }
    
    private function getStatus() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM sync_logs 
                WHERE sync_type = 'inventory'
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            $lastSync = $stmt->fetch();
            
            $this->sendSuccess([
                'last_sync' => $lastSync,
                'local_master_table_integration' => true
            ]);
        } catch (Exception $e) {
            $this->sendError('Status retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function sendSuccess($data) {
        echo json_encode([
            'success' => true,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    private function sendError($message) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $message
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

// Запуск API
try {
    $api = new InventoryAPIWithLocalMaster();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>