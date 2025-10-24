<?php
/**
 * Script to analyze inventory table structure in PostgreSQL
 * Part of task 1.2: Проанализировать структуру таблицы inventory
 */

include 'config/database_postgresql.php';

try {
    // Get database connection
    $pdo = getDatabaseConnection();
    
    if (!$pdo) {
        throw new Exception("Failed to establish database connection");
    }
    
    echo "=== АНАЛИЗ СТРУКТУРЫ ТАБЛИЦЫ INVENTORY ===\n\n";
    
    // 1. Check if inventory table exists
    echo "1. Проверка существования таблицы inventory:\n";
    $stmt = $pdo->prepare("
        SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_name = 'inventory'
        ) as table_exists
    ");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result['table_exists']) {
        echo "❌ Таблица inventory не найдена!\n";
        exit(1);
    }
    echo "✅ Таблица inventory существует\n\n";
    
    // 2. Get table structure (columns, types, constraints)
    echo "2. Структура таблицы inventory:\n";
    $stmt = $pdo->prepare("
        SELECT 
            column_name,
            data_type,
            is_nullable,
            column_default,
            character_maximum_length,
            numeric_precision,
            numeric_scale
        FROM information_schema.columns 
        WHERE table_schema = 'public' 
        AND table_name = 'inventory'
        ORDER BY ordinal_position
    ");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    printf("%-25s %-15s %-10s %-20s\n", "COLUMN", "TYPE", "NULLABLE", "DEFAULT");
    echo str_repeat("-", 80) . "\n";
    
    $stock_fields = [];
    foreach ($columns as $col) {
        $type = $col['data_type'];
        if ($col['character_maximum_length']) {
            $type .= "({$col['character_maximum_length']})";
        } elseif ($col['numeric_precision']) {
            $type .= "({$col['numeric_precision']},{$col['numeric_scale']})";
        }
        
        printf("%-25s %-15s %-10s %-20s\n", 
            $col['column_name'], 
            $type, 
            $col['is_nullable'], 
            $col['column_default'] ?: 'NULL'
        );
        
        // Identify stock-related fields
        if (in_array($col['column_name'], ['quantity_present', 'available', 'preparing_for_sale', 'in_requests', 'in_transit', 'reserved'])) {
            $stock_fields[] = $col['column_name'];
        }
    }
    
    echo "\n3. Найденные поля остатков:\n";
    if (empty($stock_fields)) {
        echo "❌ Поля остатков не найдены!\n";
    } else {
        foreach ($stock_fields as $field) {
            echo "✅ $field\n";
        }
    }
    
    // 4. Check for indexes
    echo "\n4. Индексы таблицы inventory:\n";
    $stmt = $pdo->prepare("
        SELECT 
            indexname,
            indexdef
        FROM pg_indexes 
        WHERE tablename = 'inventory' 
        AND schemaname = 'public'
    ");
    $stmt->execute();
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($indexes)) {
        echo "⚠️ Индексы не найдены\n";
    } else {
        foreach ($indexes as $index) {
            echo "📋 {$index['indexname']}: {$index['indexdef']}\n";
        }
    }
    
    // 5. Sample data analysis
    echo "\n5. Анализ данных в таблице:\n";
    
    // Count total records
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_records FROM inventory");
    $stmt->execute();
    $total = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Всего записей: {$total['total_records']}\n";
    
    if ($total['total_records'] > 0) {
        // Check for NULL values in stock fields
        echo "\nПроверка NULL значений в полях остатков:\n";
        foreach ($stock_fields as $field) {
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(*) as total,
                    COUNT($field) as not_null,
                    COUNT(*) - COUNT($field) as null_count
                FROM inventory
            ");
            $stmt->execute();
            $nulls = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "  $field: {$nulls['not_null']} не NULL, {$nulls['null_count']} NULL\n";
        }
        
        // Sample records
        echo "\n6. Примеры записей (первые 5):\n";
        $fields_str = implode(', ', array_merge(['id', 'product_id', 'warehouse_name'], $stock_fields));
        $stmt = $pdo->prepare("
            SELECT $fields_str
            FROM inventory 
            LIMIT 5
        ");
        $stmt->execute();
        $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($samples)) {
            // Print header
            $headers = array_keys($samples[0]);
            foreach ($headers as $header) {
                printf("%-15s ", $header);
            }
            echo "\n" . str_repeat("-", count($headers) * 16) . "\n";
            
            // Print data
            foreach ($samples as $row) {
                foreach ($row as $value) {
                    printf("%-15s ", $value ?: 'NULL');
                }
                echo "\n";
            }
        }
    }
    
    echo "\n=== АНАЛИЗ ЗАВЕРШЕН ===\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}
?>