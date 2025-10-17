<?php
/**
 * Продакшен конфигурация для системы пополнения
 * Автоматически сгенерировано: 2025-10-17
 */

// ============================================================================
// НАСТРОЙКИ БАЗЫ ДАННЫХ (ПРОДАКШЕН)
// ============================================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'mi_core');
define('DB_USER', 'mi_core_user');
define('DB_PASSWORD', 'your_production_password_here');
define('DB_PORT', 3306);
define('DB_CHARSET', 'utf8mb4');

define('DB_OPTIONS', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
]);

// ============================================================================
// НАСТРОЙКИ СИСТЕМЫ ПОПОЛНЕНИЯ (ПРОДАКШЕН)
// ============================================================================

define('REPLENISHMENT_ENABLED', true);
define('REPLENISHMENT_DEBUG', false);
define('REPLENISHMENT_LOG_LEVEL', 'error');

define('REPLENISHMENT_MEMORY_LIMIT', '512M');
define('REPLENISHMENT_MAX_EXECUTION_TIME', 600);
define('REPLENISHMENT_BATCH_SIZE', 100);

// ============================================================================
// НАСТРОЙКИ EMAIL (ПРОДАКШЕН)
// ============================================================================

define('EMAIL_REPORTS_ENABLED', true);
define('SMTP_ENABLED', true);
define('SMTP_HOST', 'smtp.your-domain.com');
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'reports@your-domain.com');
define('SMTP_PASSWORD', 'your_smtp_password');
define('SMTP_ENCRYPTION', 'tls');

define('REPORT_EMAIL_FROM', 'reports@your-domain.com');
define('REPORT_EMAIL_TO', 'manager@your-domain.com');

// ============================================================================
// НАСТРОЙКИ API (ПРОДАКШЕН)
// ============================================================================

define('API_ENABLED', true);
define('API_DEBUG', false);
define('API_KEY_REQUIRED', true);
define('API_KEY', 'your_secure_api_key_here');
define('API_RATE_LIMIT', 100);

// ============================================================================
// НАСТРОЙКИ ЛОГИРОВАНИЯ (ПРОДАКШЕН)
// ============================================================================

define('LOG_DIR', '/var/www/logs/replenishment');
define('LOG_FILE_CALCULATION', LOG_DIR . '/calculation.log');
define('LOG_FILE_ERROR', LOG_DIR . '/error.log');
define('LOG_FILE_API', LOG_DIR . '/api.log');

// ============================================================================
// НАСТРОЙКИ ОКРУЖЕНИЯ (ПРОДАКШЕН)
// ============================================================================

define('ENVIRONMENT', 'production');
define('DEBUG_MODE', false);

error_reporting(E_ERROR | E_WARNING | E_PARSE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', LOG_DIR . '/php_errors.log');

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
// НАСТРОЙКИ БЕЗОПАСНОСТИ (ПРОДАКШЕН)
// ============================================================================

define('SECURITY_ENABLED', true);
define('IP_WHITELIST_ENABLED', false);
define('ALLOWED_IPS', [
    '127.0.0.1',
    '::1'
]);

// ============================================================================
// НАСТРОЙКИ КЭШИРОВАНИЯ (ПРОДАКШЕН)
// ============================================================================

define('CACHE_ENABLED', true);
define('CACHE_TTL', 3600); // 1 час
define('CACHE_DIR', '/var/www/cache/replenishment');

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
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, DB_OPTIONS);
        } catch (PDOException $e) {
            logMessage("Database connection failed: " . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    return $pdo;
}

function logMessage($message, $level = 'info', $file = null) {
    // В продакшене логируем только ошибки и важные события
    if (!DEBUG_MODE && !in_array($level, ['error', 'warning', 'critical'])) {
        return;
    }
    
    $file = $file ?: LOG_FILE_CALCULATION;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    $logDir = dirname($file);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($file, $logEntry, FILE_APPEND | LOCK_EX);
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

function validateApiKey($providedKey) {
    if (!API_KEY_REQUIRED) {
        return true;
    }
    
    return hash_equals(API_KEY, $providedKey);
}

function checkIpWhitelist() {
    if (!IP_WHITELIST_ENABLED) {
        return true;
    }
    
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($clientIp, ALLOWED_IPS);
}

// Создание необходимых директорий
$directories = [LOG_DIR];
if (defined('CACHE_ENABLED') && CACHE_ENABLED) {
    $directories[] = CACHE_DIR;
}

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

logMessage("Продакшен конфигурация загружена", 'info');

?>