<?php
/**
 * Проверка загрузки данных по ТД Манхэттен
 * Период: 22.09-28.09, загрузка: 29.09 в 3:00
 */

// Подключение к базе данных
try {
    $pdo = new PDO("mysql:host=localhost;dbname=mi_core_db;charset=utf8mb4", "replenishment_user", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "=== ПРОВЕРКА ДАННЫХ ТД МАНХЭТТЕН ===\n";
    echo "Период данных: 22.09-28.09\n";
    echo "Ожидаемая загрузка: 29.09 в 3:00\n";
    echo "Источники: Wildberries, Ozon\n\n";
    
    // 1. Проверяем структуру базы данных
    echo "1. СТРУКТУРА БАЗЫ ДАННЫХ:\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $relevantTables = [];
    foreach ($tables as $table) {
        if (stripos($table, 'order') !== false || 
            stripos($table, 'sale') !== false || 
            stripos($table, 'transaction') !== false ||
            stripos($table, 'fact') !== false ||
            stripos($table, 'raw') !== false) {
            $relevantTables[] = $table;
        }
    }
    
    echo "Найдены релевантные таблицы:\n";
    foreach ($relevantTables as $table) {
        echo "- {$table}\n";
    }
    echo "\n";
    
    // 2. Проверяем fact_orders
    if (in_array('fact_orders', $tables)) {
        echo "2. ДАННЫЕ В ТАБЛИЦЕ fact_orders:\n";
        
        // Общая статистика
        $stmt = $pdo->query("
            SELECT COUNT(*) as total_orders,
                   MIN(created_at) as earliest_date,
                   MAX(created_at) as latest_date
            FROM fact_orders
        ");
        $stats = $stmt->fetch();
        
        echo "Всего заказов: {$stats['total_orders']}\n";
        echo "Самая ранняя дата: {$stats['earliest_date']}\n";
        echo "Самая поздняя дата: {$stats['latest_date']}\n\n";
        
        // Данные за нужный период
        $stmt = $pdo->query("
            SELECT 
                COALESCE(source, 'Unknown') as source,
                COUNT(*) as orders_count,
                DATE(created_at) as order_date
            FROM fact_orders 
            WHERE DATE(created_at) BETWEEN '2025-09-22' AND '2025-09-28'
            GROUP BY source, DATE(created_at)
            ORDER BY order_date DESC, source
        ");
        $periodData = $stmt->fetchAll();
        
        if (empty($periodData)) {
            echo "❌ НЕТ ДАННЫХ за период 22.09-28.09\n\n";
        } else {
            echo "✅ ДАННЫЕ ЗА ПЕРИОД 22.09-28.09:\n";
            foreach ($periodData as $row) {
                echo "  {$row['order_date']}: {$row['source']} - {$row['orders_count']} заказов\n";
            }
            echo "\n";
        }
        
        // Проверяем загрузку 29.09
        $stmt = $pdo->query("
            SELECT 
                COALESCE(source, 'Unknown') as source,
                COUNT(*) as records_loaded,
                DATE(created_at) as data_date,
                HOUR(created_at) as load_hour
            FROM fact_orders 
            WHERE DATE(created_at) = '2025-09-29'
            GROUP BY source, DATE(created_at), HOUR(created_at)
            ORDER BY load_hour
        ");
        $loadData = $stmt->fetchAll();
        
        if (empty($loadData)) {
            echo "❌ НЕТ ДАННЫХ, загруженных 29.09\n\n";
        } else {
            echo "📥 ДАННЫЕ, ЗАГРУЖЕННЫЕ 29.09:\n";
            foreach ($loadData as $row) {
                $hour = str_pad($row['load_hour'], 2, '0', STR_PAD_LEFT);
                echo "  {$hour}:00 - {$row['source']}: {$row['records_loaded']} записей\n";
            }
            echo "\n";
        }
    }
    
    // 3. Проверяем raw_events
    if (in_array('raw_events', $tables)) {
        echo "3. ДАННЫЕ В ТАБЛИЦЕ raw_events:\n";
        
        $stmt = $pdo->query("
            SELECT 
                COALESCE(event_type, 'Unknown') as event_type,
                COALESCE(source, 'Unknown') as source,
                COUNT(*) as events_count,
                DATE(created_at) as event_date
            FROM raw_events 
            WHERE DATE(created_at) BETWEEN '2025-09-22' AND '2025-09-29'
            GROUP BY event_type, source, DATE(created_at)
            ORDER BY event_date DESC
            LIMIT 20
        ");
        $events = $stmt->fetchAll();
        
        if (empty($events)) {
            echo "❌ НЕТ СОБЫТИЙ за период\n\n";
        } else {
            echo "📊 СОБЫТИЯ ЗА ПЕРИОД:\n";
            foreach ($events as $row) {
                echo "  {$row['event_date']}: {$row['event_type']} ({$row['source']}) - {$row['events_count']} событий\n";
            }
            echo "\n";
        }
    }
    
    // 4. Проверяем другие таблицы
    foreach ($relevantTables as $table) {
        if ($table !== 'fact_orders' && $table !== 'raw_events') {
            echo "4. ТАБЛИЦА {$table}:\n";
            
            try {
                $stmt = $pdo->query("DESCRIBE {$table}");
                $columns = $stmt->fetchAll();
                
                // Ищем колонки с датами
                $dateColumns = [];
                foreach ($columns as $col) {
                    if (stripos($col['Type'], 'date') !== false || 
                        stripos($col['Type'], 'timestamp') !== false ||
                        stripos($col['Field'], 'date') !== false ||
                        stripos($col['Field'], 'created') !== false ||
                        stripos($col['Field'], 'updated') !== false) {
                        $dateColumns[] = $col['Field'];
                    }
                }
                
                if (!empty($dateColumns)) {
                    $dateCol = $dateColumns[0];
                    $stmt = $pdo->query("
                        SELECT COUNT(*) as total_records,
                               MIN({$dateCol}) as earliest,
                               MAX({$dateCol}) as latest
                        FROM {$table}
                    ");
                    $tableStats = $stmt->fetch();
                    
                    echo "  Всего записей: {$tableStats['total_records']}\n";
                    echo "  Период: {$tableStats['earliest']} - {$tableStats['latest']}\n";
                } else {
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM {$table}");
                    $count = $stmt->fetch();
                    echo "  Всего записей: {$count['total']}\n";
                }
                
            } catch (Exception $e) {
                echo "  Ошибка при проверке: {$e->getMessage()}\n";
            }
            echo "\n";
        }
    }
    
    // 5. Итоговый отчет
    echo "=== ИТОГОВЫЙ ОТЧЕТ ===\n";
    
    // Проверяем наличие данных Wildberries
    $stmt = $pdo->query("
        SELECT COUNT(*) as wb_count 
        FROM fact_orders 
        WHERE (source LIKE '%wildberries%' OR source LIKE '%wb%' OR source LIKE '%вб%')
        AND DATE(created_at) BETWEEN '2025-09-22' AND '2025-09-28'
    ");
    $wbCount = $stmt->fetch()['wb_count'];
    
    // Проверяем наличие данных Ozon
    $stmt = $pdo->query("
        SELECT COUNT(*) as ozon_count 
        FROM fact_orders 
        WHERE (source LIKE '%ozon%' OR source LIKE '%озон%')
        AND DATE(created_at) BETWEEN '2025-09-22' AND '2025-09-28'
    ");
    $ozonCount = $stmt->fetch()['ozon_count'];
    
    echo "Wildberries данные (22.09-28.09): " . ($wbCount > 0 ? "✅ {$wbCount} записей" : "❌ Нет данных") . "\n";
    echo "Ozon данные (22.09-28.09): " . ($ozonCount > 0 ? "✅ {$ozonCount} записей" : "❌ Нет данных") . "\n";
    
    if ($wbCount > 0 || $ozonCount > 0) {
        echo "\n🎉 ДАННЫЕ НАЙДЕНЫ! Загрузка прошла успешно.\n";
    } else {
        echo "\n⚠️  ДАННЫЕ НЕ НАЙДЕНЫ. Возможные причины:\n";
        echo "- Загрузка еще не выполнена\n";
        echo "- Данные загружены в другую таблицу\n";
        echo "- Неправильный формат источника данных\n";
        echo "- Проблемы с ETL процессом\n";
    }
    
} catch (Exception $e) {
    echo "❌ ОШИБКА ПОДКЛЮЧЕНИЯ К БД: " . $e->getMessage() . "\n";
}
?>