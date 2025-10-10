<?php
/**
 * Конфигурационный файл для MDM системы
 * 
 * Содержит настройки подключения к базе данных и другие конфигурации.
 * Все секретные данные загружаются из .env файла.
 */

// Загружаем переменные из .env файла
function loadEnvFile($path = '.env') {
    if (!file_exists($path)) {
        return;
    }
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue; // Пропускаем комментарии
        }
        
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Загружаем .env файл
loadEnvFile();

// ===================================================================
// НАСТРОЙКИ БАЗЫ ДАННЫХ
// ===================================================================
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASSWORD', getenv('DB_PASSWORD') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'mi_core');
define('DB_PORT', getenv('DB_PORT') ?: '3306');

// ===================================================================
// НАСТРОЙКИ API
// ===================================================================
define('OZON_CLIENT_ID', getenv('OZON_CLIENT_ID') ?: '');
define('OZON_API_KEY', getenv('OZON_API_KEY') ?: '');
define('WB_API_KEY', getenv('WB_API_KEY') ?: '');

// Базовые URL для API
define('OZON_API_BASE_URL', 'https://api-seller.ozon.ru');
define('WB_SUPPLIERS_API_URL', 'https://suppliers-api.wildberries.ru');
define('WB_CONTENT_API_URL', 'https://content-api.wildberries.ru');
define('WB_STATISTICS_API_URL', 'https://statistics-api.wildberries.ru');

// ===================================================================
// НАСТРОЙКИ СИСТЕМЫ
// ===================================================================
define('LOG_LEVEL', 'INFO');
define('LOG_DIR', 'logs');
define('TEMP_DIR', '/tmp/mdm_system');
define('TIMEZONE', 'Europe/Moscow');

// Устанавливаем временную зону
date_default_timezone_set(TIMEZONE);

// ===================================================================
// НАСТРОЙКИ ЗАПРОСОВ
// ===================================================================
define('REQUEST_TIMEOUT', 30);
define('MAX_RETRIES', 3);
define('OZON_REQUEST_DELAY', 0.1);
define('WB_REQUEST_DELAY', 0.5);

// ===================================================================
// ФУНКЦИИ ПРОВЕРКИ КОНФИГУРАЦИИ
// ===================================================================

/**
 * Проверяет корректность конфигурации
 * @return array Массив с ошибками и предупреждениями
 */
function validateConfig() {
    $errors = [];
    $warnings = [];
    
    // Проверяем настройки БД
    if (!DB_USER) {
        $errors[] = 'DB_USER не найден в .env файле';
    }
    
    if (!DB_PASSWORD) {
        $warnings[] = 'DB_PASSWORD не найден в .env файле';
    }
    
    // Проверяем API ключи (не критично для базовой работы)
    if (!OZON_CLIENT_ID) {
        $warnings[] = 'OZON_CLIENT_ID не найден в .env файле';
    }
    
    if (!OZON_API_KEY) {
        $warnings[] = 'OZON_API_KEY не найден в .env файле';
    }
    
    if (!WB_API_KEY) {
        $warnings[] = 'WB_API_KEY не найден в .env файле';
    }
    
    return ['errors' => $errors, 'warnings' => $warnings];
}

/**
 * Выводит статус конфигурации
 */
function printConfigStatus() {
    echo "📋 СТАТУС КОНФИГУРАЦИИ:\n";
    echo str_repeat('=', 40) . "\n";
    
    echo "DB_HOST: " . DB_HOST . "\n";
    echo "DB_NAME: " . DB_NAME . "\n";
    echo "DB_USER: " . (DB_USER ? '✅ Загружен' : '❌ Отсутствует') . "\n";
    echo "DB_PASSWORD: " . (DB_PASSWORD ? '✅ Загружен' : '❌ Отсутствует') . "\n";
    
    echo "OZON_CLIENT_ID: " . (OZON_CLIENT_ID ? '✅ Загружен' : '❌ Отсутствует') . " (" . strlen(OZON_CLIENT_ID) . " символов)\n";
    echo "OZON_API_KEY: " . (OZON_API_KEY ? '✅ Загружен' : '❌ Отсутствует') . " (" . strlen(OZON_API_KEY) . " символов)\n";
    echo "WB_API_KEY: " . (WB_API_KEY ? '✅ Загружен' : '❌ Отсутствует') . " (" . strlen(WB_API_KEY) . " символов)\n";
    
    $validation = validateConfig();
    
    if (!empty($validation['warnings'])) {
        echo "\n⚠️ ПРЕДУПРЕЖДЕНИЯ:\n";
        foreach ($validation['warnings'] as $warning) {
            echo "  - $warning\n";
        }
    }
    
    if (!empty($validation['errors'])) {
        echo "\n❌ ОШИБКИ КОНФИГУРАЦИИ:\n";
        foreach ($validation['errors'] as $error) {
            echo "  - $error\n";
        }
    } else {
        echo "\n✅ Конфигурация корректна!\n";
    }
    
    echo "\n🌐 API ENDPOINTS:\n";
    echo "Ozon API: " . OZON_API_BASE_URL . "\n";
    echo "WB Suppliers API: " . WB_SUPPLIERS_API_URL . "\n";
    echo "WB Statistics API: " . WB_STATISTICS_API_URL . "\n";
}

// ===================================================================
// ПОДКЛЮЧЕНИЕ К БАЗЕ ДАННЫХ
// ===================================================================

/**
 * Создает и возвращает PDO подключение к базе данных
 * @return PDO
 */
function getDatabaseConnection() {
    try {
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4",
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }
}

// Создаем глобальное подключение к БД (если не в CLI режиме или если требуется)
if (!isset($pdo)) {
    try {
        $pdo = getDatabaseConnection();
    } catch (Exception $e) {
        // В CLI режиме не критично, в веб-режиме - критично
        if (php_sapi_name() !== 'cli') {
            die('Database connection error: ' . $e->getMessage());
        }
    }
}

// Если файл запущен напрямую, показываем статус конфигурации
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    printConfigStatus();
}

?>