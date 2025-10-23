<?php
/**
 * Попытка получить полные названия товаров из API Ozon
 */

// Конфигурация
$clientId = '1191336';
$apiKey = 'b5b8b8b8-b8b8-b8b8-b8b8-b8b8b8b8b8b8'; // Замените на реальный ключ

function getProductInfo($productIds, $clientId, $apiKey) {
    $url = 'https://api-seller.ozon.ru/v2/product/info/list';
    
    $data = [
        'product_id' => array_map('intval', $productIds),
        'sku' => []
    ];
    
    $headers = [
        'Client-Id: ' . $clientId,
        'Api-Key: ' . $apiKey,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        return json_decode($response, true);
    } else {
        return ['error' => 'HTTP ' . $httpCode, 'response' => $response];
    }
}

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

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "=== ПОЛУЧЕНИЕ НАЗВАНИЙ ТОВАРОВ ===\n\n";
    
    // Получаем товары с Product ID > 0
    $stmt = $pdo->query("
        SELECT product_id, sku, MAX(current_stock) as max_stock
        FROM inventory_data 
        WHERE product_id > 0 
        AND current_stock > 0
        GROUP BY product_id, sku
        ORDER BY max_stock DESC
        LIMIT 5
    ");
    
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $productIds = array_column($products, 'product_id');
    
    echo "Товары для получения названий:\n";
    foreach ($products as $product) {
        echo "Product ID: {$product['product_id']}, SKU: {$product['sku']}\n";
    }
    
    echo "\nПопытка получить названия из API Ozon...\n";
    
    // Попробуем получить информацию о товарах
    $result = getProductInfo($productIds, $clientId, $apiKey);
    
    if (isset($result['error'])) {
        echo "Ошибка API: {$result['error']}\n";
        echo "Ответ: {$result['response']}\n";
        
        // Создаем названия на основе SKU
        echo "\nСоздаем названия на основе SKU:\n";
        foreach ($products as $product) {
            $productName = generateProductName($product['sku']);
            echo "Product ID: {$product['product_id']}\n";
            echo "SKU: {$product['sku']}\n";
            echo "Название: {$productName}\n\n";
        }
    } else {
        echo "Успешно получены данные из API!\n";
        print_r($result);
    }
    
} catch (Exception $e) {
    echo "Ошибка: " . $e->getMessage() . "\n";
}

function generateProductName($sku) {
    // Создаем читаемое название на основе SKU
    $name = $sku;
    
    // Заменяем подчеркивания на пробелы
    $name = str_replace('_', ' ', $name);
    
    // Добавляем описательные слова
    if (strpos($name, 'Хлопья') !== false) {
        $name = "Хлопья овсяные " . $name;
    } elseif (strpos($name, 'Мука') !== false) {
        $name = "Мука без глютена " . $name;
    } elseif (strpos($name, 'Смесь') !== false) {
        $name = "Смесь для выпечки " . $name;
    } elseif (strpos($name, 'Сахарозаменитель') !== false) {
        $name = "Сахарозаменитель натуральный " . $name;
    } elseif (strpos($name, 'Разрыхлитель') !== false) {
        $name = "Разрыхлитель для теста " . $name;
    } elseif (strpos($name, 'Лапша') !== false || strpos($name, 'Вермишель') !== false) {
        $name = "Лапша рисовая органическая " . $name;
    }
    
    return $name;
}
?>