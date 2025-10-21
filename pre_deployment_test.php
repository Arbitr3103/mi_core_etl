<?php
/**
 * Pre-deployment Test Script
 * Проверяет готовность системы к развертыванию
 */

echo "🧪 Запуск предварительных тестов...\n\n";

$tests = [];
$passed = 0;
$failed = 0;

// Тест 1: Проверка файлов
echo "1️⃣ Проверка файловой структуры...\n";
$requiredFiles = [
    'api/analytics/config.php',
    'api/analytics/SalesAnalyticsService.php',
    'api/analytics/DatabaseConnectionPool.php',
    'html/regional-dashboard/index.html',
    'migrations/add_regional_analytics_schema.sql',
    'deploy_production_database.php',
    'deploy_web_application.php'
];

foreach ($requiredFiles as $file) {
    if (file_exists($file)) {
        echo "   ✅ {$file}\n";
        $passed++;
    } else {
        echo "   ❌ {$file} - НЕ НАЙДЕН\n";
        $failed++;
    }
}

// Тест 2: Проверка синтаксиса PHP файлов
echo "\n2️⃣ Проверка синтаксиса PHP файлов...\n";
$phpFiles = [
    'api/analytics/config.php',
    'api/analytics/SalesAnalyticsService.php',
    'api/analytics/DatabaseConnectionPool.php',
    'deploy_production_database.php',
    'deploy_web_application.php'
];

foreach ($phpFiles as $file) {
    if (file_exists($file)) {
        $output = shell_exec("php -l {$file} 2>&1");
        if (strpos($output, 'No syntax errors') !== false) {
            echo "   ✅ {$file}\n";
            $passed++;
        } else {
            echo "   ❌ {$file} - СИНТАКСИЧЕСКАЯ ОШИБКА\n";
            echo "      {$output}\n";
            $failed++;
        }
    }
}

// Тест 3: Проверка конфигурации
echo "\n3️⃣ Проверка конфигурации...\n";
try {
    require_once 'api/analytics/config.php';
    
    if (defined('ANALYTICS_API_VERSION')) {
        echo "   ✅ API версия определена: " . ANALYTICS_API_VERSION . "\n";
        $passed++;
    } else {
        echo "   ❌ API версия не определена\n";
        $failed++;
    }
    
    if (defined('ANALYTICS_CACHE_TTL')) {
        echo "   ✅ Настройки кэша определены\n";
        $passed++;
    } else {
        echo "   ❌ Настройки кэша не определены\n";
        $failed++;
    }
    
} catch (Exception $e) {
    echo "   ❌ Ошибка загрузки конфигурации: " . $e->getMessage() . "\n";
    $failed++;
}

// Тест 4: Проверка SQL миграций
echo "\n4️⃣ Проверка SQL миграций...\n";
$migrationFile = 'migrations/add_regional_analytics_schema.sql';
if (file_exists($migrationFile)) {
    $sql = file_get_contents($migrationFile);
    
    // Проверяем наличие ключевых таблиц
    $requiredTables = ['ozon_regional_sales', 'regions', 'regional_analytics_cache'];
    foreach ($requiredTables as $table) {
        if (strpos($sql, "CREATE TABLE IF NOT EXISTS {$table}") !== false) {
            echo "   ✅ Таблица {$table} определена\n";
            $passed++;
        } else {
            echo "   ❌ Таблица {$table} не найдена в миграции\n";
            $failed++;
        }
    }
    
    // Проверяем представления
    if (strpos($sql, 'CREATE OR REPLACE VIEW') !== false) {
        echo "   ✅ SQL представления определены\n";
        $passed++;
    } else {
        echo "   ❌ SQL представления не найдены\n";
        $failed++;
    }
} else {
    echo "   ❌ Файл миграции не найден\n";
    $failed++;
}

// Тест 5: Проверка HTML/CSS/JS
echo "\n5️⃣ Проверка фронтенд файлов...\n";
$frontendFiles = [
    'html/regional-dashboard/index.html',
    'html/regional-dashboard/css/dashboard.css',
    'html/regional-dashboard/css/integration.css'
];

foreach ($frontendFiles as $file) {
    if (file_exists($file)) {
        $size = filesize($file);
        if ($size > 0) {
            echo "   ✅ {$file} ({$size} байт)\n";
            $passed++;
        } else {
            echo "   ❌ {$file} - пустой файл\n";
            $failed++;
        }
    } else {
        echo "   ❌ {$file} - не найден\n";
        $failed++;
    }
}

// Тест 6: Проверка прав доступа к скриптам
echo "\n6️⃣ Проверка прав доступа...\n";
$executableFiles = [
    'deploy_regional_analytics.sh'
];

foreach ($executableFiles as $file) {
    if (file_exists($file)) {
        if (is_executable($file)) {
            echo "   ✅ {$file} - исполняемый\n";
            $passed++;
        } else {
            echo "   ⚠️  {$file} - не исполняемый (будет исправлено)\n";
            chmod($file, 0755);
            $passed++;
        }
    } else {
        echo "   ❌ {$file} - не найден\n";
        $failed++;
    }
}

// Итоговый результат
echo "\n" . str_repeat("=", 50) . "\n";
echo "📊 РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ:\n";
echo "✅ Пройдено: {$passed}\n";
echo "❌ Провалено: {$failed}\n";

$total = $passed + $failed;
$successRate = $total > 0 ? round(($passed / $total) * 100, 1) : 0;
echo "📈 Успешность: {$successRate}%\n";

if ($failed === 0) {
    echo "\n🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ! Система готова к развертыванию.\n";
    echo "💡 Следующий шаг: запустите deploy_regional_analytics.sh\n";
    exit(0);
} else if ($successRate >= 80) {
    echo "\n⚠️  БОЛЬШИНСТВО ТЕСТОВ ПРОЙДЕНО. Можно продолжать с осторожностью.\n";
    echo "🔧 Рекомендуется исправить ошибки перед развертыванием.\n";
    exit(1);
} else {
    echo "\n🚨 КРИТИЧЕСКИЕ ОШИБКИ! Развертывание не рекомендуется.\n";
    echo "🛠️  Необходимо исправить ошибки перед продолжением.\n";
    exit(2);
}
?>