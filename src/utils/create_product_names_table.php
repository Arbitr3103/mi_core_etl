<?php
/**
 * Создание таблицы с полными названиями товаров
 */

// Конфигурация БД
function loadEnvConfig() {
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $value = trim($value, '"\'');
                $_ENV[trim($key)] = $value;
            }
        }
    }
}

loadEnvConfig();

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'mi_core');
define('DB_USER', $_ENV['DB_USER'] ?? 'v_admin');
define('DB_PASS', $_ENV['DB_PASSWORD'] ?? 'Arbitr09102022!');

function generateProductName($sku) {
    // Создаем читаемое название на основе SKU
    $name = $sku;
    
    // Заменяем подчеркивания на пробелы
    $name = str_replace('_', ' ', $name);
    
    // Улучшаем названия
    if (strpos($name, 'Хлопья') !== false) {
        if (strpos($name, 'НТВ') !== false) {
            $name = str_replace('НТВ', 'не требующие варки', $name);
        }
        $name = "Хлопья овсяные ЭТОНОВО " . $name;
    } elseif (strpos($name, 'Мука') !== false) {
        $name = "Мука без глютена ЭТОНОВО " . $name;
    } elseif (strpos($name, 'Смесь') !== false) {
        $name = "Смесь для выпечки ЭТОНОВО " . $name;
    } elseif (strpos($name, 'Сахарозаменитель') !== false) {
        $name = str_replace('коричн', 'коричневый', $name);
        $name = str_replace('белый', 'белый эритрит', $name);
        $name = "Сахарозаменитель ЭТОНОВО " . $name;
    } elseif (strpos($name, 'Разрыхлитель') !== false) {
        $name = "Разрыхлитель для теста ЭТОНОВО " . $name;
    } elseif (strpos($name, 'Лапша') !== false || strpos($name, 'Вермишель') !== false) {
        $name = str_replace('рисовая', 'рисовая органическая', $name);
        $name = str_replace('марант', 'марантовая', $name);
        $name = "Лапша ЭТОНОВО " . $name;
    } elseif (strpos($name, 'Булочка') !== false) {
        $name = "Смесь для выпечки ЭТОНОВО " . $name;
    } elseif (strpos($name, 'Фруктовый') !== false || strpos($name, 'Йогурт') !== false || strpos($name, 'Ассорти') !== false) {
        $name = "Конфеты ирис SOLENTO " . $name;
    } elseif (strpos($name, 'Ирис') !== false) {
        $name = "Конфеты ирис SOLENTO " . $name;
    } elseif (strpos($name, 'Гранола') !== false || strpos($name, 'Мюсли') !== false) {
        $name = "Готовый завтрак ЭТОНОВО " . $name;
    }
    
    // Очищаем дубли
    $words = explode(' ', $name);
    $words = array_unique($words);
    $name = implode(' ', $words);
    
    return $name;
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "=== СОЗДАНИЕ ТАБЛИЦЫ НАЗВАНИЙ ТОВАРОВ ===\n\n";
    
    // Создаем таблицу
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_names (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id BIGINT NOT NULL,
            sku VARCHAR(255) NOT NULL,
            product_name TEXT NOT NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'generated',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_product (product_id, sku),
            INDEX idx_product_id (product_id),
            INDEX idx_sku (sku)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    echo "✅ Таблица product_names создана\n";
    
    // Получаем все уникальные товары
    $stmt = $pdo->query("
        SELECT DISTINCT product_id, sku, source
        FROM inventory_data 
        WHERE product_id > 0
        ORDER BY product_id, sku
    ");
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Найдено " . count($products) . " уникальных товаров\n";
    
    // Вставляем названия
    $insertStmt = $pdo->prepare("
        INSERT INTO product_names (product_id, sku, product_name, source)
        VALUES (?, ?, ?, 'generated')
        ON DUPLICATE KEY UPDATE 
            product_name = VALUES(product_name),
            updated_at = CURRENT_TIMESTAMP
    ");
    
    $count = 0;
    foreach ($products as $product) {
        $productName = generateProductName($product['sku']);
        $insertStmt->execute([
            $product['product_id'],
            $product['sku'],
            $productName
        ]);
        $count++;
        
        if ($count <= 10) {
            echo sprintf("ID: %d, SKU: %s\n", $product['product_id'], $product['sku']);
            echo sprintf("Название: %s\n\n", $productName);
        }
    }
    
    echo "✅ Обработано $count товаров\n";
    
    // Проверяем результат
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM product_names");
    $result = $stmt->fetch();
    echo "✅ В таблице product_names: {$result['count']} записей\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
?>