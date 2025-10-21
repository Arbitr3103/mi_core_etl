<?php
/**
 * API endpoint для Ozon v4 синхронизации остатков
 * 
 * Endpoints:
 * GET /api/inventory-v4.php?action=sync - запуск синхронизации
 * GET /api/inventory-v4.php?action=status - статус последней синхронизации
 * GET /api/inventory-v4.php?action=stats - статистика остатков
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

// Конфигурация БД из .env файла
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
define('DB_PASS', $_ENV['DB_PASSWORD'] ?? 'Arbitr09102022!');

class InventoryV4API {
    private $pdo;
    
    public function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            $this->sendError('Database connection failed: ' . $e->getMessage());
        }
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? 'status';
        
        switch ($action) {
            case 'sync':
                return $this->runSync();
            case 'status':
                return $this->getStatus();
            case 'stats':
                return $this->getStats();
            case 'test':
                return $this->testV4API();
            case 'products':
                return $this->getProductDetails();
            case 'critical':
                return $this->getCriticalStock();
            case 'marketing':
                return $this->getMarketingAnalytics();
            default:
                $this->sendError('Unknown action: ' . $action);
        }
    }
    
    private function runSync() {
        try {
            // Запускаем Python скрипт синхронизации v4
            $command = 'cd ' . dirname(__DIR__) . ' && python3 web_inventory_sync_v4.py sync 2>&1';
            
            $output = shell_exec($command);
            $result = json_decode($output, true);
            
            if ($result && isset($result['success'])) {
                // Сохраняем результат в БД
                $this->saveSyncResult($result);
                $this->sendSuccess($result);
            } else {
                $this->sendError('Sync failed: ' . $output);
            }
            
        } catch (Exception $e) {
            $this->sendError('Sync execution failed: ' . $e->getMessage());
        }
    }
    
    private function testV4API() {
        try {
            $command = 'cd ' . dirname(__DIR__) . ' && python3 web_inventory_sync_v4.py test 2>&1';
            
            $output = shell_exec($command);
            $result = json_decode($output, true);
            
            if ($result) {
                $this->sendSuccess($result);
            } else {
                $this->sendError('API test failed: ' . $output);
            }
            
        } catch (Exception $e) {
            $this->sendError('API test execution failed: ' . $e->getMessage());
        }
    }
    
    private function getStatus() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM inventory_sync_log 
                WHERE source = 'Ozon_v4' 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            $lastSync = $stmt->fetch();
            
            if ($lastSync) {
                $this->sendSuccess([
                    'last_sync' => $lastSync,
                    'status' => 'available'
                ]);
            } else {
                $this->sendSuccess([
                    'last_sync' => null,
                    'status' => 'no_history',
                    'message' => 'Синхронизация v4 API еще не запускалась'
                ]);
            }
            
        } catch (Exception $e) {
            $this->sendError('Status check failed: ' . $e->getMessage());
        }
    }
    
    private function getStats() {
        try {
            // Статистика остатков из v4 API (включая аналитические данные)
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_products,
                    SUM(current_stock) as total_stock,
                    SUM(reserved_stock) as total_reserved,
                    COUNT(CASE WHEN current_stock > 0 THEN 1 END) as products_in_stock,
                    AVG(current_stock) as avg_stock,
                    MAX(last_sync_at) as last_update
                FROM inventory_data 
                WHERE source IN ('Ozon', 'Ozon_Analytics')
            ");
            $stmt->execute();
            $stats = $stmt->fetch();
            
            // Статистика по типам складов (включая аналитические)
            $stmt = $this->pdo->prepare("
                SELECT 
                    CASE 
                        WHEN source = 'Ozon_Analytics' THEN CONCAT(warehouse_name, ' (', stock_type, ')')
                        ELSE CONCAT(warehouse_name, ' (', stock_type, ')')
                    END as stock_type,
                    COUNT(*) as count,
                    SUM(current_stock) as total_stock,
                    source
                FROM inventory_data 
                WHERE source IN ('Ozon', 'Ozon_Analytics')
                GROUP BY source, warehouse_name, stock_type
                ORDER BY total_stock DESC
                LIMIT 10
            ");
            $stmt->execute();
            $stockTypes = $stmt->fetchAll();
            
            // Статистика по складам
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT warehouse_name) as total_warehouses,
                    COUNT(DISTINCT CASE WHEN source = 'Ozon_Analytics' THEN warehouse_name END) as analytics_warehouses
                FROM inventory_data 
                WHERE source IN ('Ozon', 'Ozon_Analytics')
            ");
            $stmt->execute();
            $warehouseStats = $stmt->fetch();
            
            $this->sendSuccess([
                'overview' => array_merge($stats, $warehouseStats),
                'stock_types' => $stockTypes,
                'api_version' => 'v4 + Analytics'
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Stats retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getProductDetails() {
        try {
            $limit = $_GET['limit'] ?? 50;
            $offset = $_GET['offset'] ?? 0;
            $search = $_GET['search'] ?? '';
            
            // Базовый запрос для получения товаров с остатками
            $whereClause = "WHERE source IN ('Ozon', 'Ozon_Analytics')";
            $params = [];
            
            if (!empty($search)) {
                $whereClause .= " AND (sku LIKE ? OR product_id LIKE ?)";
                $searchParam = "%{$search}%";
                $params = [$searchParam, $searchParam];
            }
            
            // Получаем товары с группировкой по складам
            $sql = "
                SELECT 
                    product_id,
                    sku,
                    warehouse_name,
                    stock_type,
                    current_stock,
                    reserved_stock,
                    source,
                    last_sync_at
                FROM inventory_data 
                {$whereClause}
                ORDER BY sku, warehouse_name
                LIMIT {$offset}, {$limit}
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();
            
            // Группируем по товарам
            $groupedProducts = [];
            foreach ($products as $product) {
                $key = $product['product_id'] . '_' . $product['sku'];
                if (!isset($groupedProducts[$key])) {
                    $groupedProducts[$key] = [
                        'product_id' => $product['product_id'],
                        'sku' => $product['sku'],
                        'total_stock' => 0,
                        'total_reserved' => 0,
                        'warehouses' => []
                    ];
                }
                
                $groupedProducts[$key]['warehouses'][] = [
                    'warehouse_name' => $product['warehouse_name'],
                    'stock_type' => $product['stock_type'],
                    'current_stock' => (int)$product['current_stock'],
                    'reserved_stock' => (int)$product['reserved_stock'],
                    'source' => $product['source'],
                    'last_sync_at' => $product['last_sync_at']
                ];
                
                $groupedProducts[$key]['total_stock'] += (int)$product['current_stock'];
                $groupedProducts[$key]['total_reserved'] += (int)$product['reserved_stock'];
            }
            
            // Получаем общее количество товаров
            $countStmt = $this->pdo->prepare("
                SELECT COUNT(DISTINCT CONCAT(product_id, '_', sku)) as total
                FROM inventory_data 
                {$whereClause}
            ");
            
            $countParams = [];
            if (!empty($search)) {
                $countParams = [$searchParam, $searchParam];
            }
            $countStmt->execute($countParams);
            $totalCount = $countStmt->fetch()['total'];
            
            $this->sendSuccess([
                'products' => array_values($groupedProducts),
                'total_count' => (int)$totalCount,
                'limit' => (int)$limit,
                'offset' => (int)$offset
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Product details retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function getCriticalStock() {
        try {
            $threshold = $_GET['threshold'] ?? 5;
            
            // Получаем товары с критическими остатками
            $stmt = $this->pdo->prepare("
                SELECT 
                    i.product_id,
                    i.sku,
                    i.warehouse_name,
                    i.stock_type,
                    i.current_stock,
                    i.reserved_stock,
                    i.source,
                    i.last_sync_at,
                    -- ПРАВИЛЬНАЯ ЛОГИКА: ID всегда числовой, SKU всегда оригинальный, название всегда полное
                    COALESCE(NULLIF(i.product_id, 0), CONCAT('AUTO_', i.id)) as display_id,
                    i.sku as display_sku,
                    COALESCE(p.product_name, 
                        CASE 
                            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                            ELSE i.sku
                        END
                    ) as display_name
                FROM inventory_data i
                LEFT JOIN product_names p ON i.product_id = p.product_id AND i.sku = p.sku
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
            // 1. Топ товаров по остаткам (хиты продаж)
            $topProductsStmt = $this->pdo->prepare("
                SELECT 
                    CASE 
                        WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Артикул: ', i.sku)
                        ELSE COALESCE(p.product_name, i.sku)
                    END as product_name,
                    CASE 
                        WHEN i.sku REGEXP '^[0-9]+$' THEN i.sku
                        ELSE COALESCE(NULLIF(i.product_id, 0), 'N/A')
                    END as display_id,
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
                LEFT JOIN product_names p ON i.product_id = p.product_id AND i.sku = p.sku
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                GROUP BY i.product_id, i.sku
                ORDER BY total_stock DESC
                LIMIT 10
            ");
            $topProductsStmt->execute();
            $topProducts = $topProductsStmt->fetchAll();
            
            // 2. Товары требующие внимания (заканчиваются)
            $lowStockStmt = $this->pdo->prepare("
                SELECT 
                    CASE 
                        WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Артикул: ', i.sku)
                        ELSE COALESCE(p.product_name, i.sku)
                    END as product_name,
                    CASE 
                        WHEN i.sku REGEXP '^[0-9]+$' THEN i.sku
                        ELSE COALESCE(NULLIF(i.product_id, 0), 'N/A')
                    END as display_id,
                    i.product_id,
                    i.sku as display_sku,
                    SUM(i.current_stock) as total_stock,
                    COUNT(DISTINCT i.warehouse_name) as warehouses_count,
                    CASE 
                        WHEN SUM(i.current_stock) < 10 THEN 'Критический'
                        WHEN SUM(i.current_stock) < 50 THEN 'Низкий'
                        ELSE 'Требует внимания'
                    END as urgency
                FROM inventory_data i
                LEFT JOIN product_names p ON i.product_id = p.product_id AND i.sku = p.sku
                WHERE i.source IN ('Ozon', 'Ozon_Analytics')
                AND i.current_stock > 0
                GROUP BY i.product_id, i.sku
                HAVING total_stock < 100
                ORDER BY total_stock ASC
                LIMIT 8
            ");
            $lowStockStmt->execute();
            $lowStockProducts = $lowStockStmt->fetchAll();
            
            // 3. Анализ по складам (где больше всего товаров)
            $warehouseAnalysisStmt = $this->pdo->prepare("
                SELECT 
                    warehouse_name,
                    source,
                    COUNT(DISTINCT CONCAT(product_id, '_', sku)) as unique_products,
                    SUM(current_stock) as total_stock,
                    AVG(current_stock) as avg_stock_per_product,
                    CASE 
                        WHEN source = 'Ozon' THEN 'Основной склад'
                        ELSE 'Региональный'
                    END as warehouse_type
                FROM inventory_data 
                WHERE source IN ('Ozon', 'Ozon_Analytics')
                AND current_stock > 0
                GROUP BY warehouse_name, source
                ORDER BY total_stock DESC
                LIMIT 8
            ");
            $warehouseAnalysisStmt->execute();
            $warehouseAnalysis = $warehouseAnalysisStmt->fetchAll();
            
            // 4. Общая статистика для маркетинга
            $overallStatsStmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT CONCAT(product_id, '_', sku)) as total_unique_products,
                    SUM(current_stock) as total_inventory_value,
                    COUNT(CASE WHEN current_stock > 100 THEN 1 END) as high_stock_items,
                    COUNT(CASE WHEN current_stock BETWEEN 10 AND 100 THEN 1 END) as medium_stock_items,
                    COUNT(CASE WHEN current_stock < 10 AND current_stock > 0 THEN 1 END) as low_stock_items,
                    ROUND(AVG(current_stock), 2) as avg_stock_per_item
                FROM inventory_data 
                WHERE source IN ('Ozon', 'Ozon_Analytics')
                AND current_stock > 0
            ");
            $overallStatsStmt->execute();
            $overallStats = $overallStatsStmt->fetch();
            
            $this->sendSuccess([
                'top_products' => $topProducts,
                'low_stock_products' => $lowStockProducts,
                'warehouse_analysis' => $warehouseAnalysis,
                'overall_stats' => $overallStats,
                'generated_at' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Marketing analytics retrieval failed: ' . $e->getMessage());
        }
    }
    
    private function saveSyncResult($result) {
        try {
            // Создаем таблицу логов если не существует
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS inventory_sync_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    source VARCHAR(50) NOT NULL,
                    status VARCHAR(20) NOT NULL,
                    records_processed INT DEFAULT 0,
                    records_inserted INT DEFAULT 0,
                    records_failed INT DEFAULT 0,
                    duration_seconds INT DEFAULT 0,
                    api_requests_count INT DEFAULT 0,
                    error_message TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_source_created (source, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            $stmt = $this->pdo->prepare("
                INSERT INTO inventory_sync_log 
                (source, status, records_processed, records_inserted, records_failed, 
                 duration_seconds, api_requests_count, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $result['source'] ?? 'Ozon_v4',
                $result['status'] ?? 'unknown',
                $result['records_processed'] ?? 0,
                $result['records_inserted'] ?? 0,
                $result['records_failed'] ?? 0,
                $result['duration_seconds'] ?? 0,
                $result['api_requests_count'] ?? 0,
                $result['error_message'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log('Failed to save sync result: ' . $e->getMessage());
        }
    }
    
    private function sendSuccess($data) {
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        exit;
    }
    
    private function sendError($message) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ]);
        exit;
    }
}

// Обработка запроса
try {
    $api = new InventoryV4API();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'API initialization failed: ' . $e->getMessage(),
        'timestamp' => date('c')
    ]);
}
?>