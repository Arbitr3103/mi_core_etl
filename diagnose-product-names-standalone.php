<?php
echo "🔍 ДИАГНОСТИКА ПРОБЛЕМЫ С НАЗВАНИЯМИ ТОВАРОВ\n";
echo "==========================================\n\n";

// Попробуем найти конфигурацию из API файла
$api_file = '/var/www/html/api/inventory-v4.php';
if (file_exists($api_file)) {
    echo "✅ Найден API файл: $api_file\n";
    $api_content = file_get_contents($api_file);
    
    // Извлекаем настройки подключения из API файла
    if (preg_match('/\$host\s*=\s*[\'"]([^\'"]+)[\'"]/', $api_content, $matches)) {
        $host = $matches[1];
        echo "   host: $host\n";
    }
    if (preg_match('/\$dbname\s*=\s*[\'"]([^\'"]+)[\'"]/', $api_content, $matches)) {
        $dbname = $matches[1];
        echo "   dbname: $dbname\n";
    }
    if (preg_match('/\$username\s*=\s*[\'"]([^\'"]+)[\'"]/', $api_content, $matches)) {
        $username = $matches[1];
        echo "   username: $username\n";
    }
    if (preg_match('/\$password\s*=\s*[\'"]([^\'"]+)[\'"]/', $api_content, $matches)) {
        $password = $matches[1];
        echo "   password: [скрыт]\n";
    }
} else {
    echo "❌ API файл не найден: $api_file\n";
    
    // Попробуем стандартные настройки
    $host = 'localhost';
    $dbname = 'mi_core';
    $username = 'root';
    $password = '';
    
    echo "   Используем стандартные настройки:\n";
    echo "   host: $host\n";
    echo "   dbname: $dbname\n";
    echo "   username: $username\n";
}

echo "\n";

// Если не удалось извлечь настройки, попросим ввести вручную
if (!isset($host) || !isset($dbname) || !isset($username)) {
    echo "❌ Не удалось извлечь настройки БД из API файла\n";
    echo "   Проверьте настройки подключения вручную:\n\n";
    
    echo "1. Посмотрите настройки в API файле:\n";
    echo "   head -30 /var/www/html/api/inventory-v4.php | grep -E '(host|dbname|username|password)'\n\n";
    
    echo "2. Или проверьте config.php:\n";
    echo "   find /var/www -name 'config.php' -type f\n";
    echo "   cat /var/www/html/config.php\n\n";
    
    exit;
}

try {
    echo "🔗 Подключение к БД...\n";
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Подключение к БД успешно\n\n";
    
    // 1. Проверяем существование таблицы dim_products
    echo "📋 1. Проверяем таблицу dim_products:\n";
    $stmt = $pdo->query("SHOW TABLES LIKE 'dim_products'");
    if ($stmt->rowCount() == 0) {
        echo "❌ КРИТИЧЕСКАЯ ПРОБЛЕМА: Таблица dim_products НЕ СУЩЕСТВУЕТ!\n";
        echo "   Это объясняет почему нет названий товаров.\n\n";
        
        // Проверяем есть ли product_master
        $stmt = $pdo->query("SHOW TABLES LIKE 'product_master'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Таблица product_master существует\n";
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM product_master");
            $count = $stmt->fetch()['count'];
            echo "   Записей в product_master: $count\n\n";
            
            if ($count > 0) {
                echo "🔧 РЕШЕНИЕ: Создать dim_products из product_master\n";
                echo "   Выполните команды:\n\n";
                
                echo "CREATE TABLE dim_products (\n";
                echo "    id INT AUTO_INCREMENT PRIMARY KEY,\n";
                echo "    sku_ozon VARCHAR(255) UNIQUE,\n";
                echo "    sku_wb VARCHAR(50),\n";
                echo "    barcode VARCHAR(255),\n";
                echo "    product_name VARCHAR(500),\n";
                echo "    name VARCHAR(500),\n";
                echo "    brand VARCHAR(255),\n";
                echo "    category VARCHAR(255),\n";
                echo "    cost_price DECIMAL(10,2),\n";
                echo "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n";
                echo "    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n";
                echo ");\n\n";
                
                echo "INSERT INTO dim_products (sku_ozon, name, product_name)\n";
                echo "SELECT sku_ozon, name, product_name FROM product_master;\n\n";
            }
        } else {
            echo "❌ Таблица product_master тоже не существует\n";
            echo "   Нужно создать dim_products и заполнить данными вручную\n\n";
        }
        
        exit;
    }
    
    echo "✅ Таблица dim_products существует\n";
    
    // 2. Проверяем количество записей
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM dim_products");
    $count = $stmt->fetch()['count'];
    echo "   Количество записей: $count\n\n";
    
    if ($count == 0) {
        echo "❌ ПРОБЛЕМА: Таблица dim_products ПУСТАЯ!\n";
        echo "   Это объясняет отсутствие названий товаров.\n\n";
        exit;
    }
    
    // 3. Проверяем первые записи
    echo "📊 2. Первые 5 записей из dim_products:\n";
    $stmt = $pdo->query("SELECT sku_ozon, name, product_name FROM dim_products LIMIT 5");
    while ($row = $stmt->fetch()) {
        echo "   sku_ozon: '{$row['sku_ozon']}'\n";
        echo "   name: " . ($row['name'] ?: 'NULL') . "\n";
        echo "   product_name: " . ($row['product_name'] ?: 'NULL') . "\n\n";
    }
    
    // 4. Проверяем конкретные товары из API
    echo "🎯 3. Проверяем конкретные товары из API:\n";
    $test_products = [596534196, 1191462296, 19573935];
    
    foreach ($test_products as $product_id) {
        echo "   Товар ID: $product_id\n";
        
        // Проверяем есть ли в dim_products
        $stmt = $pdo->prepare("SELECT name, product_name FROM dim_products WHERE sku_ozon = ?");
        $stmt->execute([(string)$product_id]);
        $product = $stmt->fetch();
        
        if ($product) {
            echo "   ✅ НАЙДЕН в dim_products\n";
            echo "   name: " . ($product['name'] ?: 'NULL') . "\n";
            echo "   product_name: " . ($product['product_name'] ?: 'NULL') . "\n";
        } else {
            echo "   ❌ НЕ НАЙДЕН в dim_products\n";
        }
        echo "\n";
    }
    
    // 5. Статистика совпадений
    echo "📈 4. Статистика совпадений с inventory_data:\n";
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_inventory,
            COUNT(dp.sku_ozon) as matched_products
        FROM inventory_data i
        LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
    ");
    
    $stats = $stmt->fetch();
    echo "   Всего записей в inventory: {$stats['total_inventory']}\n";
    echo "   Найдено совпадений: {$stats['matched_products']}\n";
    
    $match_percent = round(($stats['matched_products'] / $stats['total_inventory']) * 100, 2);
    echo "   Процент совпадений: {$match_percent}%\n\n";
    
    if ($match_percent < 10) {
        echo "❌ ПРОБЛЕМА: Очень низкий процент совпадений!\n";
        echo "   Возможные причины:\n";
        echo "   - Разные форматы ID в таблицах\n";
        echo "   - Отсутствуют нужные товары в dim_products\n\n";
        
        // Показываем примеры product_id из inventory_data
        echo "📋 Примеры product_id из inventory_data:\n";
        $stmt = $pdo->query("SELECT DISTINCT product_id FROM inventory_data LIMIT 10");
        while ($row = $stmt->fetch()) {
            echo "   {$row['product_id']}\n";
        }
        echo "\n";
        
        // Показываем примеры sku_ozon из dim_products
        echo "📋 Примеры sku_ozon из dim_products:\n";
        $stmt = $pdo->query("SELECT DISTINCT sku_ozon FROM dim_products LIMIT 10");
        while ($row = $stmt->fetch()) {
            echo "   '{$row['sku_ozon']}'\n";
        }
    } else {
        echo "✅ Хороший процент совпадений!\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
?>