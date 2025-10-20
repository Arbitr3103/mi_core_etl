<?php
/**
 * Локальная конфигурация для тестирования системы пополнения
 * Автоматически сгенерировано: 2025-10-17
 */

// ============================================================================
// НАСТРОЙКИ БАЗЫ ДАННЫХ
// ============================================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'mi_core');
define('DB_USER', 'replenishment_user');
define('DB_PASSWORD', 'secure_password_123');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
]);

// ============================================================================
// НАСТРОЙКИ СИСТЕМЫ ПОПОЛНЕНИЯ
// ============================================================================

define('REPLENISHMENT_ENABLED', true);
define('REPLENISHMENT_DEBUG', true);
define('REPLENISHMENT_LOG_LEVEL', 'debug');

define('REPLENISHMENT_MEMORY_LIMIT', '256M');
define('REPLENISHMENT_MAX_EXECUTION_TIME', 300);
define('REPLENISHMENT_BATCH_SIZE', 50);

// ============================================================================
// НАСТРОЙКИ EMAIL (ОТКЛЮЧЕНЫ ДЛЯ ЛОКАЛЬНОГО ТЕСТИРОВАНИЯ)
// ============================================================================

define('EMAIL_REPORTS_ENABLED', false);
define('SMTP_ENABLED', false);

// ============================================================================
// НАСТРОЙКИ API (УПРОЩЕНЫ ДЛЯ ЛОКАЛЬНОГО ТЕСТИРОВАНИЯ)
// ============================================================================

define('API_ENABLED', true);
define('API_DEBUG', true);
define('API_KEY_REQUIRED', false);
define('API_RATE_LIMIT', 1000);

// ============================================================================
// НАСТРОЙКИ ЛОГИРОВАНИЯ
// ============================================================================

define('LOG_DIR', __DIR__ . '/logs/replenishment');
define('LOG_FILE_CALCULATION', LOG_DIR . '/calculation.log');
define('LOG_FILE_ERROR', LOG_DIR . '/error.log');
define('LOG_FILE_API', LOG_DIR . '/api.log');

// ============================================================================
// НАСТРОЙКИ ОКРУЖЕНИЯ
// ============================================================================

define('ENVIRONMENT', 'development');
define('DEBUG_MODE', true);

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

ini_set('memory_limit', REPLENISHMENT_MEMORY_LIMIT);
ini_set('max_execution_time', REPLENISHMENT_MAX_EXECUTION_TIME);

date_default_timezone_set('Europe/Moscow');

// ============================================================================
// ПАРАМЕТРЫ РАСЧЕТА ПО УМОЛЧАНИЮ
// ============================================================================

define('DEFAULT_REPLENISHMENT_DAYS', 14);
define('DEFAULT_SAFETY_DAYS', 7);
define('DEFAULT_ANALYSIS_DAYS', 30);
define('DEFAULT_MIN_ADS_THRESHOLD', 0.1);
define('DEFAULT_MAX_RECOMMENDATION_QUANTITY', 10000);

// ============================================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// ============================================================================

function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );
        
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, DB_OPTIONS);
    }
    
    return $pdo;
}

function logMessage($message, $level = 'info', $file = null) {
    $file = $file ?: LOG_FILE_CALCULATION;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    $logDir = dirname($file);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($file, $logEntry, FILE_APPEND | LOCK_EX);
    
    if (DEBUG_MODE) {
        echo $logEntry;
    }
}

function getConfigParameter($name, $default = null) {
    static $cache = [];
    
    if (isset($cache[$name])) {
        return $cache[$name];
    }
    
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("
            SELECT parameter_value, parameter_type 
            FROM replenishment_config 
            WHERE parameter_name = ? AND is_active = 1
        ");
        $stmt->execute([$name]);
        $result = $stmt->fetch();
        
        if ($result) {
            $value = $result['parameter_value'];
            
            switch ($result['parameter_type']) {
                case 'int':
                    $value = (int)$value;
                    break;
                case 'float':
                    $value = (float)$value;
                    break;
                case 'boolean':
                    $value = $value === 'true';
                    break;
            }
            
            $cache[$name] = $value;
            return $value;
        }
    } catch (Exception $e) {
        logMessage("Failed to get config parameter $name: " . $e->getMessage(), 'error');
    }
    
    return $default;
}

// Создание директории для логов
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

logMessage("Локальная конфигурация загружена", 'debug');

?>