<?php
/**
 * Скрипт диагностики проблем с загрузкой данных Ozon Analytics
 * 
 * Проверяет:
 * - Подключение к БД
 * - Существование таблиц
 * - API endpoints
 * - JavaScript ошибки
 * - Настройки Ozon
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "🔍 ДИАГНОСТИКА ПРОБЛЕМ ЗАГРУЗКИ OZON ANALYTICS\n";
echo str_repeat("=", 60) . "\n\n";

// 1. Проверка подключения к БД
echo "1️⃣ Проверка подключения к базе данных...\n";

$dbConfig = [
    'host' => 'localhost',
    'dbname' => 'mi_core_db',
    'username' => 'mi_core_user',
    'password' => 'secure_password_123'
];

try {
    $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    echo "✅ Подключение к БД успешно\n";
} catch (PDOException $e) {
    echo "❌ Ошибка подключения к БД: " . $e->getMessage() . "\n";
    exit(1);
}

// 2. Проверка таблиц Ozon Analytics
echo "\n2️⃣ Проверка таблиц Ozon Analytics...\n";

$requiredTables = [
    'ozon_api_settings',
    'ozon_funnel_data',
    'ozon_demographics',
    'ozon_campaigns'
];

$existingTables = [];
foreach ($requiredTables as $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    if ($stmt->fetch()) {
        echo "✅ Таблица $table существует\n";
        $existingTables[] = $table;
    } else {
        echo "❌ Таблица $table НЕ СУЩЕСТВУЕТ\n";
    }
}

// 3. Проверка данных в таблицах
echo "\n3️⃣ Проверка данных в таблицах...\n";

foreach ($existingTables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
        $result = $stmt->fetch();
        echo "📊 Таблица $table: {$result['count']} записей\n";
        
        if ($result['count'] > 0) {
            // Показываем последние записи
            $stmt = $pdo->query("SELECT * FROM $table ORDER BY id DESC LIMIT 1");
            $lastRecord = $stmt->fetch();
            if ($lastRecord) {
                echo "   Последняя запись: " . date('Y-m-d H:i:s', strtotime($lastRecord['cached_at'] ?? $lastRecord['created_at'] ?? 'now')) . "\n";
            }
        }
    } catch (PDOException $e) {
        echo "❌ Ошибка проверки таблицы $table: " . $e->getMessage() . "\n";
    }
}

// 4. Проверка настроек API Ozon
echo "\n4️⃣ Проверка настроек API Ozon...\n";

if (in_array('ozon_api_settings', $existingTables)) {
    $stmt = $pdo->query("SELECT client_id, is_active, created_at FROM ozon_api_settings ORDER BY id DESC LIMIT 1");
    $settings = $stmt->fetch();
    
    if ($settings) {
        echo "✅ Настройки API найдены\n";
        echo "   Client ID: " . substr($settings['client_id'], 0, 10) . "...\n";
        echo "   Активно: " . ($settings['is_active'] ? 'Да' : 'Нет') . "\n";
        echo "   Создано: " . $settings['created_at'] . "\n";
    } else {
        echo "❌ Настройки API НЕ НАЙДЕНЫ\n";
        echo "💡 Необходимо настроить API ключи в разделе '⚙️ Настройки Ozon'\n";
    }
} else {
    echo "❌ Таблица настроек не существует\n";
}

// 5. Проверка файлов классов
echo "\n5️⃣ Проверка файлов классов...\n";

$requiredFiles = [
    'src/classes/OzonAnalyticsAPI.php',
    'src/classes/OzonDataCache.php',
    'src/api/ozon-analytics.php',
    'src/js/OzonAnalyticsIntegration.js',
    'src/js/OzonFunnelChart.js'
];

foreach ($requiredFiles as $file) {
    if (file_exists($file)) {
        echo "✅ Файл $file существует\n";
    } else {
        echo "❌ Файл $file НЕ НАЙДЕН\n";
    }
}

// 6. Тест API endpoint
echo "\n6️⃣ Тест API endpoint...\n";

if (file_exists('src/api/ozon-analytics.php')) {
    // Тестируем health check
    $healthUrl = 'http://localhost/src/api/ozon-analytics.php?action=health';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $healthUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        echo "✅ API endpoint отвечает (HTTP $httpCode)\n";
        $data = json_decode($response, true);
        if ($data && isset($data['status'])) {
            echo "   Статус: {$data['status']}\n";
        }
    } else {
        echo "❌ API endpoint не отвечает (HTTP $httpCode)\n";
        echo "   Ответ: $response\n";
    }
} else {
    echo "❌ API endpoint файл не найден\n";
}

// 7. Проверка логов ошибок
echo "\n7️⃣ Проверка логов ошибок...\n";

$logFiles = [
    '/var/log/apache2/error.log',
    '/var/log/nginx/error.log',
    '/var/log/php8.1-fpm.log'
];

foreach ($logFiles as $logFile) {
    if (file_exists($logFile) && is_readable($logFile)) {
        echo "📋 Проверяем $logFile...\n";
        
        // Ищем ошибки связанные с Ozon за последние 10 минут
        $command = "tail -100 $logFile | grep -i 'ozon\\|analytics' | tail -5";
        $output = shell_exec($command);
        
        if (!empty($output)) {
            echo "⚠️  Найдены ошибки связанные с Ozon:\n";
            echo $output . "\n";
        } else {
            echo "✅ Ошибок связанных с Ozon не найдено\n";
        }
    }
}

// 8. Рекомендации по устранению
echo "\n8️⃣ РЕКОМЕНДАЦИИ ПО УСТРАНЕНИЮ ПРОБЛЕМ:\n";
echo str_repeat("-", 50) . "\n";

if (!in_array('ozon_api_settings', $existingTables)) {
    echo "🔧 1. Применить миграции базы данных:\n";
    echo "   ./apply_ozon_analytics_migration.sh\n\n";
}

$stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_api_settings WHERE is_active = 1");
$activeSettings = $stmt->fetch();

if ($activeSettings['count'] == 0) {
    echo "🔧 2. Настроить API ключи Ozon:\n";
    echo "   - Перейти в дашборд → '⚙️ Настройки Ozon'\n";
    echo "   - Ввести Client ID и API Key\n";
    echo "   - Нажать 'Тестировать подключение'\n";
    echo "   - Сохранить настройки\n\n";
}

if (!file_exists('src/classes/OzonAnalyticsAPI.php')) {
    echo "🔧 3. Проверить развертывание файлов:\n";
    echo "   ./deploy_safe.sh\n\n";
}

echo "🔧 4. Проверить консоль браузера на JavaScript ошибки:\n";
echo "   - Открыть Developer Tools (F12)\n";
echo "   - Перейти на вкладку Console\n";
echo "   - Обновить страницу и проверить ошибки\n\n";

echo "🔧 5. Проверить сетевые запросы:\n";
echo "   - Developer Tools → Network\n";
echo "   - Обновить страницу\n";
echo "   - Проверить запросы к /src/api/ozon-analytics.php\n\n";

echo "📞 Если проблема не решена:\n";
echo "   - Проверить логи веб-сервера\n";
echo "   - Убедиться что PHP-FPM перезапущен\n";
echo "   - Проверить права доступа к файлам\n";

echo "\n" . str_repeat("=", 60) . "\n";
echo "🏁 ДИАГНОСТИКА ЗАВЕРШЕНА\n";
?>