<?php
/**
use MDM\ETL\Config\ETLConfigManager;
 * Применение миграции для фильтрации активных товаров
 * 
 * Этот скрипт:
 * 1. Применяет миграцию базы данных для отслеживания активности товаров
 * 2. Инициализирует конфигурацию для фильтрации активных товаров
 * 3. Обновляет существующие ETL скрипты
 */

require_once 'config.php';

echo "🚀 Применение миграции для фильтрации активных товаров\n";
echo "====================================================\n\n";

try {
    $pdo = getDatabaseConnection();
    
    // Шаг 1: Применение миграции базы данных
    echo "📊 Шаг 1: Применение миграции базы данных...\n";
    
    $migrationFile = 'src/ETL/Database/migrations/add_activity_tracking_to_extracted_data.sql';
    
    if (!file_exists($migrationFile)) {
        throw new Exception("Файл миграции не найден: $migrationFile");
    }
    
    $migrationSQL = file_get_contents($migrationFile);
    
    // Разбиваем SQL на отдельные команды
    $statements = array_filter(
        array_map('trim', explode(';', $migrationSQL)),
        function($stmt) {
            return !empty($stmt) && 
                   !preg_match('/^\s*--/', $stmt) && 
                   !preg_match('/^\s*DELIMITER/', $stmt);
        }
    );
    
    $executedStatements = 0;
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $executedStatements++;
            
            // Определяем тип операции для вывода
            if (preg_match('/^\s*ALTER\s+TABLE\s+(\w+)/i', $statement, $matches)) {
                echo "  ✅ Изменена таблица: {$matches[1]}\n";
            } elseif (preg_match('/^\s*CREATE\s+TABLE\s+.*?(\w+)/i', $statement, $matches)) {
                echo "  ✅ Создана таблица: {$matches[1]}\n";
            } elseif (preg_match('/^\s*CREATE\s+INDEX\s+(\w+)/i', $statement, $matches)) {
                echo "  ✅ Создан индекс: {$matches[1]}\n";
            } elseif (preg_match('/^\s*CREATE.*?VIEW\s+(\w+)/i', $statement, $matches)) {
                echo "  ✅ Создано представление: {$matches[1]}\n";
            } elseif (preg_match('/^\s*CREATE.*?PROCEDURE\s+(\w+)/i', $statement, $matches)) {
                echo "  ✅ Создана процедура: {$matches[1]}\n";
            } elseif (preg_match('/^\s*INSERT\s+INTO\s+(\w+)/i', $statement, $matches)) {
                echo "  ✅ Добавлены данные в таблицу: {$matches[1]}\n";
            } else {
                echo "  ✅ Выполнена SQL команда\n";
            }
            
        } catch (Exception $e) {
            // Игнорируем ошибки "уже существует"
            if (strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "  ⚠️ Пропущено (уже существует)\n";
                continue;
            }
            
            echo "  ❌ Ошибка выполнения SQL: " . $e->getMessage() . "\n";
            echo "  SQL: " . substr($statement, 0, 100) . "...\n";
        }
    }
    
    echo "✅ Миграция базы данных завершена. Выполнено команд: $executedStatements\n\n";
    
    // Шаг 2: Инициализация конфигурации
    echo "⚙️ Шаг 2: Инициализация конфигурации...\n";
    
    $configScript = 'src/ETL/Config/init_active_product_config.php';
    
    if (file_exists($configScript)) {
        echo "Запуск скрипта инициализации конфигурации...\n";
        include $configScript;
    } else {
        echo "⚠️ Скрипт инициализации конфигурации не найден: $configScript\n";
    }
    
    echo "\n";
    
    // Шаг 3: Проверка конфигурации
    echo "🔍 Шаг 3: Проверка конфигурации...\n";
    
    require_once 'src/ETL/Config/ETLConfigManager.php';
    
    $configManager = new ETLConfigManager($pdo);
    $ozonConfig = $configManager->getOzonConfig();
    
    echo "Настройки фильтрации активных товаров:\n";
    echo "  - Фильтрация активных товаров: " . ($ozonConfig['filter_active_only'] ? 'включена' : 'отключена') . "\n";
    echo "  - Интервал проверки активности: " . $ozonConfig['activity_check_interval'] . " сек\n";
    echo "  - Минимальные остатки: " . $ozonConfig['activity_checker']['stock_threshold'] . "\n";
    echo "  - Требуемая видимость: " . $ozonConfig['activity_checker']['required_visibility'] . "\n";
    echo "  - Проверка цен: " . ($ozonConfig['activity_checker']['check_pricing'] ? 'включена' : 'отключена') . "\n";
    
    // Шаг 4: Проверка структуры базы данных
    echo "\n🗄️ Шаг 4: Проверка структуры базы данных...\n";
    
    // Проверяем новые поля в etl_extracted_data
    $stmt = $pdo->query("DESCRIBE etl_extracted_data");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $requiredColumns = ['is_active', 'activity_checked_at', 'activity_reason'];
    $missingColumns = array_diff($requiredColumns, $columns);
    
    if (empty($missingColumns)) {
        echo "  ✅ Все необходимые поля добавлены в etl_extracted_data\n";
    } else {
        echo "  ❌ Отсутствуют поля в etl_extracted_data: " . implode(', ', $missingColumns) . "\n";
    }
    
    // Проверяем новые таблицы
    $requiredTables = [
        'etl_product_activity_log',
        'etl_activity_monitoring'
    ];
    
    foreach ($requiredTables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        
        if ($stmt->rowCount() > 0) {
            echo "  ✅ Таблица $table создана\n";
        } else {
            echo "  ❌ Таблица $table не найдена\n";
        }
    }
    
    // Проверяем представление
    $stmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW' AND Tables_in_" . DB_NAME . " = 'v_etl_active_products_stats'");
    if ($stmt->rowCount() > 0) {
        echo "  ✅ Представление v_etl_active_products_stats создано\n";
    } else {
        echo "  ❌ Представление v_etl_active_products_stats не найдено\n";
    }
    
    // Проверяем процедуру
    $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Name = 'UpdateActivityMonitoringStats'");
    if ($stmt->rowCount() > 0) {
        echo "  ✅ Процедура UpdateActivityMonitoringStats создана\n";
    } else {
        echo "  ❌ Процедура UpdateActivityMonitoringStats не найдена\n";
    }
    
    echo "\n🎉 Миграция успешно завершена!\n\n";
    
    echo "📋 Следующие шаги:\n";
    echo "1. Проверьте настройки ETL:\n";
    echo "   php etl_cli.php config ozon\n\n";
    echo "2. Запустите тестовое извлечение данных:\n";
    echo "   php etl_cli.php run ozon --limit=10\n\n";
    echo "3. Проверьте статистику активных товаров:\n";
    echo "   SELECT * FROM v_etl_active_products_stats;\n\n";
    echo "4. При необходимости настройте email для уведомлений:\n";
    echo "   php etl_cli.php config scheduler activity_notification_email your@email.com\n\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка применения миграции: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
    exit(1);
}