<?php
/**
 * Исправленный API для управления остатками товаров v4
 * Исправляет проблему отображения названий товаров для аналитических данных
 * 
 * Основные исправления:
 * - Улучшенный JOIN для связи inventory_data и product_names
 * - Правильная обработка товаров с product_id = 0
 * - Fallback логика для товаров без названий
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
define('DB_NAME', $_ENV['DB_NAME'] ?? 'mi_core');
define('DB_USER', $_ENV['DB_USER'] ?? 'v_admin');
define('DB_PASS', $_ENV['DB_PASSWORD'] ?? '');

class InventoryAPIFixed {
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
            default:
                $this->sendError('Unknown action');
        }
    }
    
    private function testAPI() {
        try {
            // Простая проверка подключения к БД и наличия данных
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as total_records,
                       COUNT(CASE WHEN current_stock > 0 THEN 1 END) as with_stock,
                       COUNT(DISTINCT source) as sources
                FROM inventory_data
            ");
            
            $result = $stmt->fetch();
            
            $this->sendSuccess([
                'api_working' => true,
                'database_connected' => true,
                'total_records' => (int)$result['total_records'],
                'records_with_stock' => (int)$result['with_stock'],
                'data_sources' => (int)$result['sources'],
                'test_time' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $this->sendError('API test failed: ' . $e->getMessage());
        }
    }
    
    private function runSync() {
        try {
            // Запускаем синхронизацию через новый сервис с названиями
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
                'last_sync' => $lastSync
            ]);
        } catch (Exception $e) {
            $this->sendError('Status retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getStats() {
        try {
            // Общая статистика
            $overviewStmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN current_stock > 0 THEN 1 END) as products_in_stock,
                    COUNT(DISTINCT warehouse_name) as total_warehouses,
                    COUNT(CASE WHEN source = 'Ozon_Analytics' THEN 1 END) as analytics_warehouses,
                    SUM(current_stock) as total_stock,
                    SUM(reserved_stock) as total_reserved,
                    AVG(current_stock) as avg_stock,
                    MAX(last_sync_at) as last_update
                FROM inventory_data 
                WHERE source IN ('Ozon', 'Ozon_Analytics')
            ");
            
            $overview = $overviewStmt->fetch();
            
            // Статистика по типам складов с исправленным JOIN
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
                'stock_types' => $stockTypes,
                'api_version' => 'v4_fixed'
            ]);
        } catch (Exception $e) {
            $this->sendError('Stats retrieval failed: ' . $e->getMessage());
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
                $whereClause .= " AND (i.sku LIKE ? OR i.product_id = ? OR p.product_name LIKE ?)";
                $searchParam = '%' . $search . '%';
                $params = [$searchParam, $search, $searchParam];
            }
            
            // Исправленный запрос с улучшенным JOIN
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
                    COALESCE(p.product_name, 
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as display_name
                FROM inventory_data i
                LEFT JOIN product_names p ON (
                    (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                    (i.product_id = 0 AND i.sku = p.sku)
                )
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
                LEFT JOIN product_names p ON (
                    (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                    (i.product_id = 0 AND i.sku = p.sku)
                )
                {$whereClause}
            ");
            
            $countParams = array_slice($params, 0, -2); // Убираем limit и offset
            $countStmt->execute($countParams);
            $totalCount = $countStmt->fetch()['total'];
            
            // Группируем товары по SKU для лучшего отображения
            $groupedProducts = [];
            foreach ($products as $product) {
                $key = $product['product_id'] . '_' . $product['sku'];
                if (!isset($groupedProducts[$key])) {
                    $groupedProducts[$key] = [
                        'display_id' => $product['display_id'],
                        'sku' => $product['sku'],
                        'display_name' => $product['display_name'],
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
                'offset' => $offset
            ]);
        } catch (Exception $e) {
            $this->sendError('Products retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getCriticalStock() {
        try {
            $threshold = (int)($_GET['threshold'] ?? 5);
            
            // Исправленный запрос для критических остатков
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
                    COALESCE(p.product_name, 
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as display_name
                FROM inventory_data i
                LEFT JOIN product_names p ON (
                    (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                    (i.product_id = 0 AND i.sku = p.sku)
                )
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
                    COUNT(DISTINCT CONCAT(product_id, '_', sku)) as unique_products,
                    COUNT(DISTINCT warehouse_name) as affected_warehouses,
                    SUM(current_stock) as total_critical_stock
                FROM inventory_data 
                WHERE source IN ('Ozon', 'Ozon_Analytics')
                AND current_stock > 0 
                AND current_stock < ?
            ");
            
            $statsStmt->execute([(int)$threshold]);
            $stats = $statsStmt->fetch();
            
            $this->sendSuccess([
                'critical_items' => $criticalItems,
                'stats' => $stats,
                'threshold' => (int)$threshold
            ]);
        } catch (Exception $e) {
            $this->sendError('Critical stock retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getMarketingAnalytics() {
        try {
            // Топ товаров по остаткам с исправленным JOIN
            $topProductsStmt = $this->pdo->prepare("
                SELECT 
                    COALESCE(p.product_name, 
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as product_name,
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
                    END as category
                FROM inventory_data i
                LEFT JOIN product_names p ON (
                    (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                    (i.product_id = 0 AND i.sku = p.sku)
                )
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                GROUP BY i.product_id, i.sku
                ORDER BY total_stock DESC
                LIMIT 10
            ");
            $topProductsStmt->execute();
            $topProducts = $topProductsStmt->fetchAll();
            
            // Товары требующие внимания с исправленным JOIN
            $lowStockStmt = $this->pdo->prepare("
                SELECT 
                    COALESCE(p.product_name, 
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as product_name,
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
                LEFT JOIN product_names p ON (
                    (i.product_id > 0 AND i.product_id = p.product_id AND i.sku = p.sku) OR
                    (i.product_id = 0 AND i.sku = p.sku)
                )
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                AND i.current_stock < 50
                GROUP BY i.product_id, i.sku
                ORDER BY total_stock ASC
                LIMIT 10
            ");
            $lowStockStmt->execute();
            $lowStock = $lowStockStmt->fetchAll();
            
            $this->sendSuccess([
                'top_products' => $topProducts,
                'low_stock_products' => $lowStock,
                'analysis_time' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            $this->sendError('Marketing analytics failed: ' . $e->getMessage());
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
    $api = new InventoryAPIFixed();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>