<?php
/**
 * Обновление названий товаров через API Ozon для товаров без названий
 */

// Подключение к БД
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

try {
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=mi_core;charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✅ Подключение к БД успешно\n\n";
    
    // Получаем товары без названий (только числовые SKU)
    echo "🔍 Поиск товаров без названий...\n";
    
    $products_stmt = $pdo->query("
        SELECT i.sku, MAX(i.current_stock) as max_stock
        FROM inventory_data i
        LEFT JOIN product_names pn ON i.sku = pn.sku
        WHERE i.current_stock > 0 
        AND i.sku REGEXP '^[0-9]+$'
        AND pn.product_name IS NULL
        GROUP BY i.sku
        ORDER BY max_stock DESC
        LIMIT 20
    ");
    
    $products = $products_stmt->fetchAll();
    echo "Найдено " . count($products) . " товаров без названий\n\n";
    
    if (empty($products)) {
        echo "Нет товаров для обновления\n";
        exit;
    }
    
    // Настройки API Ozon
    $client_id = $_ENV['OZON_CLIENT_ID'] ?? '';
    $api_key = $_ENV['OZON_API_KEY'] ?? '';
    
    if (empty($client_id) || empty($api_key)) {
        echo "❌ Не настроены OZON_CLIENT_ID или OZON_API_KEY в .env файле\n";
        exit;
    }
    
    echo "🚀 Начинаем получение названий через API Ozon...\n";
    
    $updated_count = 0;
    $failed_count = 0;
    
    foreach ($products as $product) {
        $sku = $product['sku'];
        echo "Обрабатываем SKU: $sku... ";
        
        try {
            // Запрос к API Ozon для получения информации о товаре
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://api-seller.ozon.ru/v2/product/info',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Client-Id: ' . $client_id,
                    'Api-Key: ' . $api_key,
                    'Content-Type: application/json'
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'sku' => (int)$sku
                ])
            ]);
            
            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($http_code === 200) {
                $data = json_decode($response, true);
                
                if (isset($data['result']['name']) && !empty($data['result']['name'])) {
                    $product_name = $data['result']['name'];
                    
                    // Сохраняем в базу данных
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO product_names (sku, product_name, source, created_at) 
                        VALUES (?, ?, 'ozon_api', NOW())
                        ON DUPLICATE KEY UPDATE 
                        product_name = VALUES(product_name),
                        updated_at = NOW()
                    ");
                    
                    $insert_stmt->execute([$sku, $product_name]);
                    
                    echo "✅ " . substr($product_name, 0, 50) . (strlen($product_name) > 50 ? '...' : '') . "\n";
                    $updated_count++;
                } else {
                    echo "❌ Название не найдено в ответе API\n";
                    $failed_count++;
                }
            } else {
                echo "❌ HTTP код: $http_code\n";
                $failed_count++;
            }
            
            // Пауза между запросами для соблюдения лимитов API
            usleep(200000); // 200ms
            
        } catch (Exception $e) {
            echo "❌ Ошибка: " . $e->getMessage() . "\n";
            $failed_count++;
        }
    }
    
    echo "\n🎉 Обновление завершено!\n";
    echo "✅ Успешно обновлено: $updated_count\n";
    echo "❌ Ошибок: $failed_count\n";
    
} catch (PDOException $e) {
    echo "❌ Ошибка БД: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Общая ошибка: " . $e->getMessage() . "\n";
}
?>