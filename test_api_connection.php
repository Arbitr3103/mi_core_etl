<?php
/**
 * Тест подключения к API и базе данных
 */

// Включаем отображение ошибок для диагностики
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔍 Диагностика MDM системы</h2>";
echo "<hr>";

// Проверяем config.php
echo "<h3>1. Проверка конфигурации</h3>";
if (file_exists('config.php')) {
    echo "✅ config.php найден<br>";
    require_once 'config.php';
    
    echo "DB_HOST: " . DB_HOST . "<br>";
    echo "DB_NAME: " . DB_NAME . "<br>";
    echo "DB_USER: " . DB_USER . "<br>";
    echo "DB_PASSWORD: " . (DB_PASSWORD ? '✅ Установлен' : '❌ Отсутствует') . "<br>";
} else {
    echo "❌ config.php не найден<br>";
}

echo "<hr>";

// Проверяем подключение к базе данных
echo "<h3>2. Проверка подключения к базе данных</h3>";
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ Подключение к базе данных успешно<br>";
    
    // Проверяем таблицы
    $tables = ['product_cross_reference', 'dim_products', 'quality_alerts'];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table LIMIT 1");
            $result = $stmt->fetch();
            echo "✅ Таблица $table: " . $result['count'] . " записей<br>";
        } catch (Exception $e) {
            echo "❌ Таблица $table: " . $e->getMessage() . "<br>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка подключения к базе данных: " . $e->getMessage() . "<br>";
}

echo "<hr>";

// Проверяем API файлы
echo "<h3>3. Проверка API файлов</h3>";
$apiFiles = [
    'api/quality-metrics.php',
    'src/DataQualityMonitor.php'
];

foreach ($apiFiles as $file) {
    if (file_exists($file)) {
        echo "✅ $file найден<br>";
    } else {
        echo "❌ $file не найден<br>";
    }
}

echo "<hr>";

// Тестируем API напрямую
echo "<h3>4. Тест API качества данных</h3>";
try {
    if (file_exists('src/DataQualityMonitor.php')) {
        require_once 'src/DataQualityMonitor.php';
        
        $monitor = new DataQualityMonitor($pdo);
        $metrics = $monitor->getQualityMetrics();
        
        echo "✅ API работает<br>";
        echo "<pre>";
        print_r($metrics);
        echo "</pre>";
    } else {
        echo "❌ DataQualityMonitor.php не найден<br>";
    }
} catch (Exception $e) {
    echo "❌ Ошибка API: " . $e->getMessage() . "<br>";
    echo "Детали: " . $e->getTraceAsString() . "<br>";
}

echo "<hr>";

// Проверяем структуру директорий
echo "<h3>5. Структура файлов</h3>";
$dirs = ['.', 'api', 'src', 'html'];
foreach ($dirs as $dir) {
    echo "<strong>$dir/</strong><br>";
    if (is_dir($dir)) {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                echo "&nbsp;&nbsp;- $file<br>";
            }
        }
    }
    echo "<br>";
}

?>