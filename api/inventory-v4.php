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

// Конфигурация БД
define('DB_HOST', 'localhost');
define('DB_NAME', 'mi_core_db');
define('DB_USER', 'mi_core_user');
define('DB_PASS', 'secure_password_123');

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
            $command = 'cd /var/www/mi_core_api && python3 -c "
from inventory_sync_service_v4 import InventorySyncServiceV4
import json

service = InventorySyncServiceV4()
try:
    service.connect_to_database()
    result = service.sync_ozon_inventory_v4()
    
    output = {
        \"success\": True,
        \"source\": result.source,
        \"status\": result.status.value,
        \"records_processed\": result.records_processed,
        \"records_inserted\": result.records_inserted,
        \"records_failed\": result.records_failed,
        \"duration_seconds\": result.duration_seconds,
        \"api_requests_count\": result.api_requests_count,
        \"error_message\": result.error_message,
        \"timestamp\": result.completed_at.isoformat() if result.completed_at else None
    }
    print(json.dumps(output))
    
except Exception as e:
    output = {
        \"success\": False,
        \"error\": str(e),
        \"timestamp\": None
    }
    print(json.dumps(output))
    
finally:
    service.close_database_connection()
" 2>&1';
            
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
            $command = 'cd /var/www/mi_core_api && python3 -c "
from inventory_sync_service_v4 import InventorySyncServiceV4
import json

service = InventorySyncServiceV4()
try:
    # Тест API без БД
    result = service.get_ozon_stocks_v4(limit=5)
    
    output = {
        \"success\": True,
        \"api_working\": True,
        \"items_received\": result[\"total_items\"],
        \"has_next\": result[\"has_next\"],
        \"cursor_present\": bool(result[\"last_id\"]),
        \"message\": \"v4 API работает корректно\"
    }
    print(json.dumps(output))
    
except Exception as e:
    output = {
        \"success\": False,
        \"api_working\": False,
        \"error\": str(e),
        \"message\": \"v4 API недоступен\"
    }
    print(json.dumps(output))
" 2>&1';
            
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
            // Статистика остатков из v4 API
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_products,
                    SUM(current_stock) as total_stock,
                    SUM(reserved_stock) as total_reserved,
                    COUNT(CASE WHEN current_stock > 0 THEN 1 END) as products_in_stock,
                    AVG(current_stock) as avg_stock,
                    MAX(last_sync_at) as last_update
                FROM inventory_data 
                WHERE source = 'Ozon'
            ");
            $stmt->execute();
            $stats = $stmt->fetch();
            
            // Статистика по типам складов
            $stmt = $this->pdo->prepare("
                SELECT 
                    stock_type,
                    COUNT(*) as count,
                    SUM(current_stock) as total_stock
                FROM inventory_data 
                WHERE source = 'Ozon'
                GROUP BY stock_type
            ");
            $stmt->execute();
            $stockTypes = $stmt->fetchAll();
            
            $this->sendSuccess([
                'overview' => $stats,
                'stock_types' => $stockTypes,
                'api_version' => 'v4'
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