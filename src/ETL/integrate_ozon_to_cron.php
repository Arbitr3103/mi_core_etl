<?php
/**
 * Интеграция загрузки данных Ozon в существующий cron-скрипт
 * 
 * Добавьте этот код в ваш существующий скрипт, который запускается каждый понедельник в 3:00
 */

echo "🔄 Интеграция Ozon Analytics в cron-скрипт\n";
echo "==========================================\n\n";

// Подключаем функцию загрузки данных Ozon
require_once 'load_ozon_data.php';

/**
 * Функция для добавления в существующий cron-скрипт
 * Вызывайте эту функцию в вашем основном скрипте обновления данных
 */
function updateOzonAnalyticsData($existingPDO = null) {
    echo "📊 Обновление данных Ozon Analytics...\n";
    
    $startTime = microtime(true);
    
    try {
        // Используем существующее подключение к БД или создаем новое
        $result = loadOzonAnalyticsData($existingPDO);
        
        $endTime = microtime(true);
        $executionTime = round($endTime - $startTime, 2);
        
        if ($result['success']) {
            echo "✅ Ozon Analytics обновлен успешно за {$executionTime}с\n";
            echo "   📊 Записей воронки: {$result['funnel_records']}\n";
            echo "   👥 Демографических записей: {$result['demographics_records']}\n";
            echo "   📅 Период: {$result['period']}\n";
            
            // Логируем успешное обновление
            error_log("Ozon Analytics updated successfully: {$result['funnel_records']} funnel records, {$result['demographics_records']} demographics records");
            
            return true;
        } else {
            echo "❌ Ошибка обновления Ozon Analytics: {$result['error']}\n";
            
            // Логируем ошибку
            error_log("Ozon Analytics update failed: {$result['error']}");
            
            return false;
        }
        
    } catch (Exception $e) {
        $endTime = microtime(true);
        $executionTime = round($endTime - $startTime, 2);
        
        echo "❌ Критическая ошибка Ozon Analytics за {$executionTime}с: " . $e->getMessage() . "\n";
        
        // Логируем критическую ошибку
        error_log("Ozon Analytics critical error: " . $e->getMessage());
        
        return false;
    }
}

/**
 * Пример интеграции в существующий cron-скрипт
 */
function exampleCronIntegration() {
    echo "📋 Пример интеграции в существующий cron-скрипт:\n";
    echo "================================================\n\n";
    
    echo "<?php\n";
    echo "// Ваш существующий cron-скрипт\n";
    echo "echo \"Начало еженедельного обновления данных...\\n\";\n\n";
    
    echo "// Подключение к БД (ваш существующий код)\n";
    echo "\$pdo = new PDO(\$dsn, \$username, \$password);\n\n";
    
    echo "// Ваши существующие обновления данных\n";
    echo "updateMarketplaceData(\$pdo);\n";
    echo "updateProductData(\$pdo);\n";
    echo "// ... другие обновления ...\n\n";
    
    echo "// ДОБАВЬТЕ ЭТУ СТРОКУ для обновления Ozon Analytics\n";
    echo "require_once 'integrate_ozon_to_cron.php';\n";
    echo "updateOzonAnalyticsData(\$pdo);\n\n";
    
    echo "echo \"Еженедельное обновление завершено\\n\";\n";
    echo "?>\n\n";
    
    echo "💡 Или просто добавьте в ваш существующий скрипт:\n";
    echo "   require_once 'integrate_ozon_to_cron.php';\n";
    echo "   updateOzonAnalyticsData(\$yourExistingPDO);\n\n";
}

// Показываем пример интеграции
exampleCronIntegration();

/**
 * Функция для тестирования интеграции
 */
function testOzonIntegration() {
    echo "🧪 Тестирование интеграции Ozon Analytics...\n";
    echo "============================================\n\n";
    
    // Тестируем обновление данных
    $success = updateOzonAnalyticsData();
    
    if ($success) {
        echo "✅ Тест интеграции прошел успешно!\n";
        echo "Теперь можно добавить эту функцию в ваш cron-скрипт.\n\n";
    } else {
        echo "❌ Тест интеграции не прошел.\n";
        echo "Проверьте настройки API и подключение к БД.\n\n";
    }
    
    return $success;
}

// Если скрипт запущен напрямую, выполняем тест
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    testOzonIntegration();
}
?>