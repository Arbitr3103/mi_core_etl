<?php
/**
 * Script to analyze sales data structure in PostgreSQL
 * Part of task 2.1: Проанализировать структуру данных продаж и создать базовые запросы
 */

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    echo "=== АНАЛИЗ СТРУКТУРЫ ДАННЫХ ПРОДАЖ ===\n\n";
    
    // 1. Find all tables in the database
    echo "1. Поиск всех таблиц в базе данных:\n";
    $stmt = $pdo->prepare("
        SELECT table_name, table_type
        FROM information_schema.tables 
        WHERE table_schema = 'public'
        ORDER BY table_name
    ");
    $stmt->execute();
    $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Найдено таблиц: " . count($tables) . "\n";
    foreach ($tables as $table) {
        echo "  - {$table['table_name']} ({$table['table_type']})\n";
    }
    echo "\n";
    
    // 2. Look for sales-related tables
    echo "2. Поиск таблиц связанных с продажами:\n";
    $sales_keywords = ['order', 'sale', 'transaction', 'purchase', 'revenue', 'sold', 'analytics'];
    $sales_tables = [];
    
    foreach ($tables as $table) {
        $table_name = strtolower($table['table_name']);
        foreach ($sales_keywords as $keyword) {
            if (strpos($table_name, $keyword) !== false) {
                $sales_tables[] = $table['table_name'];
                echo "  ✅ {$table['table_name']} - потенциальная таблица продаж\n";
                break;
            }
        }
    }
    
    if (empty($sales_tables)) {
        echo "  ⚠️ Таблицы с явными названиями продаж не найдены\n";
        echo "  Проверим все таблицы на наличие полей связанных с продажами...\n\n";
        
        // 3. Check all tables for sales-related columns
        echo "3. Анализ всех таблиц на наличие полей продаж:\n";
        $sales_columns = ['quantity_sold', 'sold_quantity', 'sales_amount', 'revenue', 'order_date', 'sale_date', 'sold_at', 'purchase_date'];
        
        foreach ($tables as $table) {
            $table_name = $table['table_name'];
            
            // Get columns for this table
            $stmt = $pdo->prepare("
                SELECT column_name, data_type, is_nullable
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND table_name = ?
                ORDER BY ordinal_position
            ");
            $stmt->execute([$table_name]);
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $has_sales_columns = false;
            $found_columns = [];
            
            foreach ($columns as $col) {
                $col_name = strtolower($col['column_name']);
                foreach ($sales_columns as $sales_col) {
                    if (strpos($col_name, str_replace('_', '', $sales_col)) !== false || 
                        strpos($col_name, $sales_col) !== false) {
                        $has_sales_columns = true;
                        $found_columns[] = $col['column_name'];
                    }
                }
                
                // Also check for date columns that might indicate sales
                if (strpos($col_name, 'date') !== false || strpos($col_name, 'time') !== false) {
                    if ($col['data_type'] === 'timestamp with time zone' || $col['data_type'] === 'date') {
                        $found_columns[] = $col['column_name'] . ' (date field)';
                    }
                }
            }
            
            if ($has_sales_columns || !empty($found_columns)) {
                echo "  📊 $table_name:\n";
                foreach ($found_columns as $found_col) {
                    echo "    - $found_col\n";
                }
                $sales_tables[] = $table_name;
            }
        }
    }
    
    echo "\n";
    
    // 4. Detailed analysis of potential sales tables
    if (!empty($sales_tables)) {
        echo "4. Детальный анализ потенциальных таблиц продаж:\n";
        
        foreach (array_unique($sales_tables) as $table_name) {
            echo "\n--- Анализ таблицы: $table_name ---\n";
            
            // Get table structure
            $stmt = $pdo->prepare("
                SELECT 
                    column_name,
                    data_type,
                    is_nullable,
                    column_default
                FROM information_schema.columns 
                WHERE table_schema = 'public' 
                AND table_name = ?
                ORDER BY ordinal_position
            ");
            $stmt->execute([$table_name]);
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            printf("%-25s %-20s %-10s\n", "COLUMN", "TYPE", "NULLABLE");
            echo str_repeat("-", 60) . "\n";
            
            foreach ($columns as $col) {
                printf("%-25s %-20s %-10s\n", 
                    $col['column_name'], 
                    $col['data_type'], 
                    $col['is_nullable']
                );
            }
            
            // Get record count
            try {
                $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM \"$table_name\"");
                $stmt->execute();
                $count = $stmt->fetch(PDO::FETCH_ASSOC);
                echo "\nКоличество записей: {$count['count']}\n";
                
                // Sample data if table has records
                if ($count['count'] > 0) {
                    echo "\nПример данных (первые 3 записи):\n";
                    $stmt = $pdo->prepare("SELECT * FROM \"$table_name\" LIMIT 3");
                    $stmt->execute();
                    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($samples)) {
                        $headers = array_keys($samples[0]);
                        foreach ($headers as $header) {
                            printf("%-15s ", substr($header, 0, 14));
                        }
                        echo "\n" . str_repeat("-", count($headers) * 16) . "\n";
                        
                        foreach ($samples as $row) {
                            foreach ($row as $value) {
                                printf("%-15s ", substr($value ?: 'NULL', 0, 14));
                            }
                            echo "\n";
                        }
                    }
                }
            } catch (Exception $e) {
                echo "Ошибка при анализе данных: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // 5. Check inventory table for any sales-related fields
    echo "\n5. Проверка таблицы inventory на наличие полей связанных с продажами:\n";
    $stmt = $pdo->prepare("
        SELECT 
            column_name,
            data_type,
            is_nullable
        FROM information_schema.columns 
        WHERE table_schema = 'public' 
        AND table_name = 'inventory'
        ORDER BY ordinal_position
    ");
    $stmt->execute();
    $inventory_columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $sales_related_in_inventory = [];
    foreach ($inventory_columns as $col) {
        $col_name = strtolower($col['column_name']);
        if (strpos($col_name, 'sold') !== false || 
            strpos($col_name, 'sale') !== false || 
            strpos($col_name, 'revenue') !== false ||
            strpos($col_name, 'updated') !== false ||
            strpos($col_name, 'created') !== false) {
            $sales_related_in_inventory[] = $col;
        }
    }
    
    if (!empty($sales_related_in_inventory)) {
        echo "Найдены поля в inventory, которые могут быть связаны с продажами:\n";
        foreach ($sales_related_in_inventory as $col) {
            echo "  - {$col['column_name']} ({$col['data_type']})\n";
        }
    } else {
        echo "В таблице inventory не найдено полей напрямую связанных с продажами.\n";
    }
    
    // 6. Recommendations for next steps
    echo "\n6. Рекомендации для следующих шагов:\n";
    
    if (!empty($sales_tables)) {
        echo "✅ Найдены потенциальные таблицы с данными продаж:\n";
        foreach (array_unique($sales_tables) as $table) {
            echo "   - $table\n";
        }
        echo "\nРекомендуется:\n";
        echo "1. Детально изучить структуру найденных таблиц\n";
        echo "2. Найти связи между таблицами продаж и inventory\n";
        echo "3. Создать запросы для извлечения данных продаж за последний месяц\n";
    } else {
        echo "⚠️ Таблицы с данными продаж не найдены.\n";
        echo "\nВозможные варианты:\n";
        echo "1. Данные продаж хранятся в другой базе данных\n";
        echo "2. Данные продаж получаются через API\n";
        echo "3. Нужно создать механизм сбора данных продаж\n";
        echo "4. Использовать поле updated_at в inventory как индикатор активности\n";
    }
    
    echo "\n=== АНАЛИЗ ЗАВЕРШЕН ===\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>