<?php
require_once 'config.php';

echo "🔍 ПРОВЕРКА ТАБЛИЦЫ dim_products НА СЕРВЕРЕ\n";
echo "=========================================\n\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Проверяем существование таблицы
    $stmt = $pdo->query("SHOW TABLES LIKE 'dim_products'");
    if ($stmt->rowCount() == 0) {
        echo "❌ КРИТИЧЕСКАЯ ПРОБЛЕМА: Таблица dim_products НЕ СУЩЕСТВУЕТ!\n";
        echo "   Это объясняет почему нет названий товаров.\n\n";
        
        echo "🔧 РЕШЕНИЕ: Создать таблицу dim_products\n";
        echo "   Выполните команды:\n";
        echo "   1. Создать таблицу\n";
        echo "   2. Заполнить данными из product_master (если есть)\n\n";
        
        // Проверяем есть ли product_master
        $stmt = $pdo->query("SHOW TABLES LIKE 'product_master'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Таблица product_master существует\n";
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM product_master");
            $count = $stmt->fetch()['count'];
            echo "   Записей в product_master: $count\n\n";
            
            if ($count > 0) {
                echo "📋 Первые 5 записей из product_master:\n";
                $stmt = $pdo->query("SELECT * FROM product_master LIMIT 5");
                while ($row = $stmt->fetch()) {
                    echo "   ID: {$row['id']}\n";
                    foreach ($row as $key => $value) {
                        if ($key != 'id' && $value) {
                            echo "   $key: $value\n";
                        }
                    }
                    echo "\n";
                }
            }
        } else {
            echo "❌ Таблица product_master тоже не существует\n";
            echo "   Нужно создать dim_products и заполнить данными вручную\n\n";
        }
        
        exit;
    }
    
    echo "✅ Таблица dim_products существует\n\n";
    
    // Проверяем количество записей
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM dim_products");
    $count = $stmt->fetch()['count'];
    echo "📊 Количество записей в dim_products: $count\n\n";
    
    if ($count == 0) {
        echo "❌ ПРОБЛЕМА: Таблица dim_products ПУСТАЯ!\n";
        echo "   Это объясняет отсутствие названий товаров.\n\n";
        exit;
    }
    
    // Показываем структуру таблицы
    echo "🏗️ Структура таблицы dim_products:\n";
    $stmt = $pdo->query("DESCRIBE dim_products");
    while ($row = $stmt->fetch()) {
        echo "   {$row['Field']}: {$row['Type']} ({$row['Null']}, {$row['Key']})\n";
    }
    echo "\n";
    
    // Показываем первые записи
    echo "📋 Первые 10 записей:\n";
    $stmt = $pdo->query("SELECT sku_ozon, name, product_name FROM dim_products LIMIT 10");
    while ($row = $stmt->fetch()) {
        echo "   sku_ozon: '{$row['sku_ozon']}'\n";
        echo "   name: " . ($row['name'] ?: 'NULL') . "\n";
        echo "   product_name: " . ($row['product_name'] ?: 'NULL') . "\n\n";
    }
    
    // Проверяем типы данных в sku_ozon
    echo "🔍 Анализ поля sku_ozon:\n";
    $stmt = $pdo->query("
        SELECT 
            sku_ozon,
            LENGTH(sku_ozon) as length,
            sku_ozon REGEXP '^[0-9]+$' as is_numeric
        FROM dim_products 
        LIMIT 10
    ");
    
    while ($row = $stmt->fetch()) {
        echo "   '{$row['sku_ozon']}' (длина: {$row['length']}, числовой: " . ($row['is_numeric'] ? 'да' : 'нет') . ")\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
?>