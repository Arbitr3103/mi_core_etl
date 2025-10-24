<?php
/**
 * Production конфигурация для market-mi.ru с PostgreSQL
 * Исправленная конфигурация для работы inventory-analytics.php
 */

// Конфигурация базы данных PostgreSQL
$config = [
    'database' => [
        'host' => 'localhost',
        'port' => 5432,
        'dbname' => 'mi_core_db',
        'username' => 'mi_core_user',
        'password' => 'MiCore2025SecurePass!',
        'charset' => 'utf8'
    ],
    'app' => [
        'debug' => false,
        'timezone' => 'Europe/Moscow'
    ]
];

// Функция подключения к PostgreSQL
function getDatabaseConnection() {
    global $config;
    
    try {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $config['database']['host'],
            $config['database']['port'],
            $config['database']['dbname']
        );
        
        $pdo = new PDO($dsn, $config['database']['username'], $config['database']['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        
        return $pdo;
    } catch (PDOException $e) {
        error_log("PostgreSQL connection failed: " . $e->getMessage());
        
        // Возвращаем null если подключение не удалось
        return null;
    }
}

// Функция для получения монитора производительности (заглушка)
function getPerformanceMonitor() {
    return new class {
        public function startTimer($name) { return $this; }
        public function endTimer($name, $data = []) { return []; }
        public function getMetrics() { return []; }
    };
}

// Установка временной зоны
date_default_timezone_set($config['app']['timezone']);

// Функция логирования ошибок (только если не объявлена)
if (!function_exists('logError')) {
    function logError($message, $context = []) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'message' => $message,
            'context' => $context
        ];
        
        error_log(json_encode($logEntry));
    }
}

// Функция проверки подключения к базе данных (только если не объявлена)
if (!function_exists('validateDatabaseConnection')) {
    function validateDatabaseConnection($pdo) {
        try {
            if ($pdo === null) {
                return false;
            }
            $stmt = $pdo->query("SELECT 1");
            return true;
        } catch (PDOException $e) {
            if (function_exists('logError')) {
                logError("Database connection validation failed", ['error' => $e->getMessage()]);
            }
            return false;
        }
    }
}

// Функция для проверки наличия данных в inventory (только если не объявлена)
if (!function_exists('checkInventoryDataAvailability')) {
    function checkInventoryDataAvailability($pdo) {
        try {
            if ($pdo === null) {
                return false;
            }
            
            // Проверяем существование таблицы inventory
            $stmt = $pdo->query("
                SELECT EXISTS (
                    SELECT FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = 'inventory'
                )
            ");
            $tableExists = $stmt->fetchColumn();
            
            if (!$tableExists) {
                return false;
            }
            
            // Проверяем наличие данных
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory WHERE quantity_present IS NOT NULL OR available IS NOT NULL");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['count'] > 0;
        } catch (PDOException $e) {
            if (function_exists('logError')) {
                logError("Inventory data check failed", ['error' => $e->getMessage()]);
            }
            return false;
        }
    }
}
?>