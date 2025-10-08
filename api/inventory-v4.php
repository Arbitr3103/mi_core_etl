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
        \"success\": False,
        \"api_working\": False,
            
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