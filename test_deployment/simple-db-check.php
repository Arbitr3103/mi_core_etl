<?php
echo "🔍 ПРОСТАЯ ПРОВЕРКА БД\n";
echo "====================\n\n";

// Попробуем разные способы подключения
$configs = [
    // Конфигурация 1: Стандартная
    [
        'host' => 'localhost',
        'dbname' => 'mi_core',
        'username' => 'root',
        'password' => '',
        'name' => 'Стандартная (root без пароля)'
    ],
    // Конфигурация 2: С паролем
    [
        'host' => 'localhost', 
        'dbname' => 'mi_core',
        'username' => 'root',
        'password' => 'password',
        'name' => 'С паролем root'
    ],
    // Конфигурация 3: Пользователь ingest_user
    [
        'host' => 'localhost',
        'dbname' => 'mi_core', 
        'username' => 'ingest_user',
        'password' => 'ingest_password',
        'name' => 'ingest_user'
    ],
    // Конфигурация 4: mi_core_db
    [
        'host' => 'localhost',
        'dbname' => 'mi_core_db',
        'username' => 'root', 
        'password' => '',
        'name' => 'mi_core_db база'
    ]
];

foreach ($configs as $config) {
    echo "🔗 Пробуем: {$config['name']}\n";
    echo "   host: {$config['host']}\n";
    echo "   dbname: {$config['dbname']}\n";
    echo "   username: {$config['username']}\n";
    
    try {
        $pdo = new PDO(
            "mysql:host={$config['host']};dbname={$config['dbname']};charset=utf8mb4", 
            $config['username'], 
            $config['password']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "   ✅ ПОДКЛЮЧЕНИЕ УСПЕШНО!\n\n";
        
        // Проверяем таблицы
        echo "📋 Доступные таблицы:\n";
        $stmt = $pdo->query("SHOW TABLES");
        $tables = [];
        while ($row = $stmt->fetch()) {
            $table = array_values($row)[0];
            $tables[] = $table;
            echo "   - $table\n";
        }
        echo "\n";
        
        // Проверяем dim_products
        if (in_array('dim_products', $tables)) {
            echo "✅ Таблица dim_products НАЙДЕНА\n";
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM dim_products");
            $count = $stmt->fetch()['count'];
            echo "   Записей: $count\n";
            
            if ($count > 0) {
                echo "   Первые 3 записи:\n";
                $stmt = $pdo->query("SELECT sku_ozon, name, product_name FROM dim_products LIMIT 3");
                while ($row = $stmt->fetch()) {
                    echo "     sku_ozon: '{$row['sku_ozon']}', name: " . ($row['name'] ?: 'NULL') . "\n";
                }
            }
        } else {
            echo "❌ Таблица dim_products НЕ НАЙДЕНА\n";
        }
        echo "\n";
        
        // Проверяем inventory_data
        if (in_array('inventory_data', $tables)) {
            echo "✅ Таблица inventory_data НАЙДЕНА\n";
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_data");
            $count = $stmt->fetch()['count'];
            echo "   Записей: $count\n";
            
            if ($count > 0) {
                echo "   Первые 3 product_id:\n";
                $stmt = $pdo->query("SELECT DISTINCT product_id FROM inventory_data LIMIT 3");
                while ($row = $stmt->fetch()) {
                    echo "     {$row['product_id']}\n";
                }
            }
        } else {
            echo "❌ Таблица inventory_data НЕ НАЙДЕНА\n";
        }
        echo "\n";
        
        // Если обе таблицы есть, проверяем JOIN
        if (in_array('dim_products', $tables) && in_array('inventory_data', $tables)) {
            echo "🔗 Проверяем JOIN между таблицами:\n";
            $stmt = $pdo->query("
                SELECT 
                    COUNT(*) as total,
                    COUNT(dp.sku_ozon) as matched
                FROM inventory_data i
                LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
                LIMIT 1
            ");
            $result = $stmt->fetch();
            $percent = $result['total'] > 0 ? round(($result['matched'] / $result['total']) * 100, 2) : 0;
            echo "   Всего записей: {$result['total']}\n";
            echo "   Совпадений: {$result['matched']}\n";
            echo "   Процент: {$percent}%\n";
            
            if ($percent < 10) {
                echo "   ❌ ПРОБЛЕМА: Мало совпадений!\n";
            } else {
                echo "   ✅ Хорошие совпадения\n";
            }
        }
        
        echo "\n" . str_repeat("=", 50) . "\n\n";
        
        // Если нашли рабочую конфигурацию, останавливаемся
        break;
        
    } catch (Exception $e) {
        echo "   ❌ Ошибка: " . $e->getMessage() . "\n\n";
        continue;
    }
}
?>