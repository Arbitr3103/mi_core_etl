<?php
/**
 * API для управления остатками товаров с интеграцией мастер таблицы dim_products
 * 
 * Улучшения:
 * - Использование мастер таблицы dim_products для получения названий товаров
 * - Кросс-базовый JOIN между mi_core и mi_core_db
 * - Отображение полной информации о товаре: название, бренд, категория
 * - Улучшенная fallback логика
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
define('DB_USER', $_ENV['DB_USER'] ?? 'v_admin');
define('DB_PASS', $_ENV['DB_PASSWORD'] ?? '');

class InventoryAPIWithMasterTable {
    private $pdo_core;
    private $pdo_master;
    
    public function __construct() {
        try {
            // Подключение к основной базе (mi_core)
            $this->pdo_core = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=mi_core;charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                ]
            );
            
            // Подключение к базе с мастер таблицей (mi_core_db)
            $this->pdo_master = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=mi_core_db;charset=utf8mb4",
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
            default:
                $this->sendError('Unknown action');
        }
    }
    
    private function testAPI() {
        try {
            // Проверяем подключение к обеим базам
            $stmt_core = $this->pdo_core->query("
                SELECT COUNT(*) as total_records,
                       COUNT(CASE WHEN current_stock > 0 THEN 1 END) as with_stock
                FROM inventory_data
            ");
            $core_result = $stmt_core->fetch();
            
            $stmt_master = $this->pdo_master->query("
                SELECT COUNT(*) as total_products,
                       COUNT(CASE WHEN sku_ozon IS NOT NULL AND sku_ozon != '' THEN 1 END) as with_ozon_sku
                FROM dim_products
            ");
            $master_result = $stmt_master->fetch();
            
            $this->sendSuccess([
                'api_working' => true,
                'core_db_connected' => true,
                'master_db_connected' => true,
                'inventory_records' => (int)$core_result['total_records'],
                'records_with_stock' => (int)$core_result['with_stock'],
                'master_products' => (int)$master_result['total_products'],
                'products_with_ozon_sku' => (int)$master_result['with_ozon_sku'],
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
            
            $whereClause = "WHERE i.source IN ('Ozon', 'Ozon_Analytics') AND i.current_stock > 0";
            $params = [];
            
            if ($search) {
                $whereClause .= " AND (i.sku LIKE ? OR i.product_id = ? OR dp.product_name LIKE ? OR dp.brand LIKE ?)";
                $searchParam = '%' . $search . '%';
                $params = [$searchParam, $search, $searchParam, $searchParam];
            }
            
            // Создаем временную таблицу для кросс-базового JOIN
            $this->pdo_core->exec("DROP TEMPORARY TABLE IF EXISTS temp_dim_products");
            
            // Получаем данные из мастер таблицы
            $master_data = $this->pdo_master->query("
                SELECT id, sku_ozon, sku_wb, product_name, name, brand, category
                FROM dim_products 
                WHERE sku_ozon IS NOT NULL AND sku_ozon != ''
            ")->fetchAll();
            
            // Создаем временную таблицу в основной базе
            $this->pdo_core->exec("
                CREATE TEMPORARY TABLE temp_dim_products (
                    id INT,
                    sku_ozon VARCHAR(255),
                    sku_wb VARCHAR(50),
                    product_name VARCHAR(500),
                    name VARCHAR(500),
                    brand VARCHAR(255),
                    category VARCHAR(255),
                    INDEX idx_sku_ozon (sku_ozon)
                )
            ");
            
            // Заполняем временную таблицу
            if (!empty($master_data)) {
                $insert_stmt = $this->pdo_core->prepare("
                    INSERT INTO temp_dim_products 
                    (id, sku_ozon, sku_wb, product_name, name, brand, category) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($master_data as $row) {
                    $insert_stmt->execute([
                        $row['id'],
                        $row['sku_ozon'],
                        $row['sku_wb'],
                        $row['product_name'],
                        $row['name'],
                        $row['brand'],
                        $row['category']
                    ]);
                }
            }
            
            // Основной запрос с JOIN к временной таблице
            $stmt = $this->pdo_core->prepare("
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
                    dp.product_name as master_product_name,
                    dp.name as master_name,
                    dp.brand,
                    dp.category,
                    COALESCE(
                        dp.product_name,
                        dp.name,
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as display_name,
                    CASE 
                        WHEN dp.product_name IS NOT NULL THEN 'Мастер таблица'
                        WHEN i.sku REGEXP '^[0-9]+$' THEN 'Fallback (числовой)'
                        ELSE 'Fallback (текстовый)'
                    END as name_source
                FROM inventory_data i
                LEFT JOIN temp_dim_products dp ON i.sku = dp.sku_ozon
                {$whereClause}
                ORDER BY i.current_stock DESC, i.sku
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            $stmt->execute($params);
            $products = $stmt->fetchAll();
            
            // Подсчет общего количества
            $countStmt = $this->pdo_core->prepare("
                SELECT COUNT(*) as total
                FROM inventory_data i
                LEFT JOIN temp_dim_products dp ON i.sku = dp.sku_ozon
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
                        'product_name' => $product['master_product_name'],
                        'brand' => $product['brand'],
                        'category' => $product['category'],
                        'name_source' => $product['name_source'],
                        'product_id' => $product['product_id'],
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
                'master_table_used' => true
            ]);
        } catch (Exception $e) {
            $this->sendError('Products retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getStats() {
        try {
            // Получаем данные из мастер таблицы
            $master_data = $this->pdo_master->query("
                SELECT id, sku_ozon, product_name, brand, category
                FROM dim_products 
                WHERE sku_ozon IS NOT NULL AND sku_ozon != ''
            ")->fetchAll();
            
            // Создаем временную таблицу
            $this->pdo_core->exec("DROP TEMPORARY TABLE IF EXISTS temp_dim_products");
            $this->pdo_core->exec("
                CREATE TEMPORARY TABLE temp_dim_products (
                    id INT,
                    sku_ozon VARCHAR(255),
                    product_name VARCHAR(500),
                    brand VARCHAR(255),
                    category VARCHAR(255),
                    INDEX idx_sku_ozon (sku_ozon)
                )
            ");
            
            // Заполняем временную таблицу
            if (!empty($master_data)) {
                $insert_stmt = $this->pdo_core->prepare("
                    INSERT INTO temp_dim_products (id, sku_ozon, product_name, brand, category) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($master_data as $row) {
                    $insert_stmt->execute([
                        $row['id'],
                        $row['sku_ozon'],
                        $row['product_name'],
                        $row['brand'],
                        $row['category']
                    ]);
                }
            }
            
            // Общая статистика с использованием мастер таблицы
            $overviewStmt = $this->pdo_core->query("
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN i.current_stock > 0 THEN 1 END) as products_in_stock,
                    COUNT(DISTINCT i.warehouse_name) as total_warehouses,
                    COUNT(CASE WHEN i.source = 'Ozon_Analytics' THEN 1 END) as analytics_warehouses,
                    SUM(i.current_stock) as total_stock,
                    SUM(i.reserved_stock) as total_reserved,
                    AVG(i.current_stock) as avg_stock,
                    MAX(i.last_sync_at) as last_update,
                    COUNT(dp.product_name) as products_with_master_names,
                    COUNT(DISTINCT dp.brand) as unique_brands,
                    COUNT(DISTINCT dp.category) as unique_categories
                FROM inventory_data i
                LEFT JOIN temp_dim_products dp ON i.sku = dp.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
            ");
            
            $overview = $overviewStmt->fetch();
            
            // Статистика по брендам
            $brandsStmt = $this->pdo_core->query("
                SELECT 
                    dp.brand,
                    COUNT(*) as products_count,
                    SUM(i.current_stock) as total_stock
                FROM inventory_data i
                JOIN temp_dim_products dp ON i.sku = dp.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                AND dp.brand IS NOT NULL
                GROUP BY dp.brand
                ORDER BY total_stock DESC
                LIMIT 10
            ");
            
            $brands = $brandsStmt->fetchAll();
            
            // Статистика по категориям
            $categoriesStmt = $this->pdo_core->query("
                SELECT 
                    dp.category,
                    COUNT(*) as products_count,
                    SUM(i.current_stock) as total_stock
                FROM inventory_data i
                JOIN temp_dim_products dp ON i.sku = dp.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                AND dp.category IS NOT NULL
                GROUP BY dp.category
                ORDER BY total_stock DESC
                LIMIT 10
            ");
            
            $categories = $categoriesStmt->fetchAll();
            
            $this->sendSuccess([
                'overview' => $overview,
                'top_brands' => $brands,
                'top_categories' => $categories,
                'api_version' => 'v4_with_master_table',
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
    
    private function getMarketingAnalytics() {
        try {
            // Получаем данные из мастер таблицы
            $master_data = $this->pdo_master->query("
                SELECT id, sku_ozon, product_name, brand, category
                FROM dim_products 
                WHERE sku_ozon IS NOT NULL AND sku_ozon != ''
            ")->fetchAll();
            
            // Создаем временную таблицу
            $this->pdo_core->exec("DROP TEMPORARY TABLE IF EXISTS temp_dim_products");
            $this->pdo_core->exec("
                CREATE TEMPORARY TABLE temp_dim_products (
                    id INT,
                    sku_ozon VARCHAR(255),
                    product_name VARCHAR(500),
                    brand VARCHAR(255),
                    category VARCHAR(255),
                    INDEX idx_sku_ozon (sku_ozon)
                )
            ");
            
            // Заполняем временную таблицу
            if (!empty($master_data)) {
                $insert_stmt = $this->pdo_core->prepare("
                    INSERT INTO temp_dim_products (id, sku_ozon, product_name, brand, category) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($master_data as $row) {
                    $insert_stmt->execute([
                        $row['id'],
                        $row['sku_ozon'],
                        $row['product_name'],
                        $row['brand'],
                        $row['category']
                    ]);
                }
            }
            
            // Топ товаров с полной информацией
            $topProductsStmt = $this->pdo_core->query("
                SELECT 
                    COALESCE(dp.product_name, 
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as product_name,
                    dp.brand,
                    dp.category,
                    COALESCE(NULLIF(i.product_id, 0), CONCAT('AUTO_', MIN(i.id))) as display_id,
                    i.product_id,
                    i.sku as display_sku,
                    SUM(i.current_stock) as total_stock,
                    SUM(i.reserved_stock) as total_reserved,
                    COUNT(DISTINCT i.warehouse_name) as warehouses_count,
                    CASE 
                        WHEN SUM(i.current_stock) > 500 THEN 'Хит продаж'
                        WHEN SUM(i.current_stock) > 100 THEN 'Популярный'
                        WHEN SUM(i.current_stock) > 50 THEN 'Средний'
                        ELSE 'Низкий остаток'
                    END as category_by_stock
                FROM inventory_data i
                LEFT JOIN temp_dim_products dp ON i.sku = dp.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                GROUP BY i.product_id, i.sku
                ORDER BY total_stock DESC
                LIMIT 10
            ");
            $topProducts = $topProductsStmt->fetchAll();
            
            $this->sendSuccess([
                'top_products' => $topProducts,
                'analysis_time' => date('Y-m-d H:i:s'),
                'master_table_used' => true
            ]);
        } catch (Exception $e) {
            $this->sendError('Marketing analytics failed: ' . $e->getMessage());
        }
    }
    
    private function runSync() {
        try {
            // Запускаем синхронизацию
            $command = 'cd ' . dirname(__DIR__) . ' && python3 inventory_sync_service_with_names.py 2>&1';
            $output = shell_exec($command);
            
            // Получаем статистику последней синхронизации
            $stmt = $this->pdo_core->prepare("
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
            $stmt = $this->pdo_core->prepare("
                SELECT * FROM sync_logs 
                WHERE sync_type = 'inventory'
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            $lastSync = $stmt->fetch();
            
            $this->sendSuccess([
                'last_sync' => $lastSync,
                'master_table_integration' => true
            ]);
        } catch (Exception $e) {
            $this->sendError('Status retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getCriticalStock() {
        try {
            $threshold = (int)($_GET['threshold'] ?? 5);
            
            // Получаем данные из мастер таблицы
            $master_data = $this->pdo_master->query("
                SELECT id, sku_ozon, product_name, brand, category
                FROM dim_products 
                WHERE sku_ozon IS NOT NULL AND sku_ozon != ''
            ")->fetchAll();
            
            // Создаем временную таблицу
            $this->pdo_core->exec("DROP TEMPORARY TABLE IF EXISTS temp_dim_products");
            $this->pdo_core->exec("
                CREATE TEMPORARY TABLE temp_dim_products (
                    id INT,
                    sku_ozon VARCHAR(255),
                    product_name VARCHAR(500),
                    brand VARCHAR(255),
                    category VARCHAR(255),
                    INDEX idx_sku_ozon (sku_ozon)
                )
            ");
            
            // Заполняем временную таблицу
            if (!empty($master_data)) {
                $insert_stmt = $this->pdo_core->prepare("
                    INSERT INTO temp_dim_products (id, sku_ozon, product_name, brand, category) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($master_data as $row) {
                    $insert_stmt->execute([
                        $row['id'],
                        $row['sku_ozon'],
                        $row['product_name'],
                        $row['brand'],
                        $row['category']
                    ]);
                }
            }
            
            $stmt = $this->pdo_core->prepare("
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
                    dp.product_name,
                    dp.brand,
                    dp.category,
                    COALESCE(
                        dp.product_name,
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as display_name
                FROM inventory_data i
                LEFT JOIN temp_dim_products dp ON i.sku = dp.sku_ozon
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0 
                AND i.current_stock < ?
                ORDER BY i.current_stock ASC, i.sku
            ");
            
            $stmt->execute([(int)$threshold]);
            $criticalItems = $stmt->fetchAll();
            
            $this->sendSuccess([
                'critical_items' => $criticalItems,
                'threshold' => (int)$threshold,
                'master_table_used' => true
            ]);
        } catch (Exception $e) {
            $this->sendError('Critical stock retrieval failed: ' . $e->getMessage());
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
    $api = new InventoryAPIWithMasterTable();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>