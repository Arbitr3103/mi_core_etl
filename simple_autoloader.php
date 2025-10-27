<?php
/**
 * Простой автозагрузчик для Ozon ETL системы
 */

// Регистрируем автозагрузчик
spl_autoload_register(function ($class) {
    // Базовый namespace для Ozon ETL
    $prefix = 'MiCore\\ETL\\Ozon\\';
    
    // Базовая директория для namespace
    $base_dir = __DIR__ . '/src/ETL/Ozon/';
    
    // Проверяем, использует ли класс наш namespace
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    // Получаем относительное имя класса
    $relative_class = substr($class, $len);
    
    // Заменяем namespace разделители на разделители директорий
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    // Если файл существует, подключаем его
    if (file_exists($file)) {
        require $file;
    }
});

// Загружаем переменные окружения если есть .env файл
if (file_exists(__DIR__ . '/.env')) {
    $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
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

echo "Simple autoloader loaded successfully\n";