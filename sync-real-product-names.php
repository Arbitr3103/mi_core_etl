<?php
echo "🔄 СИНХРОНИЗАЦИЯ РЕАЛЬНЫХ НАЗВАНИЙ ТОВАРОВ ИЗ OZON API\n";
echo "===================================================\n\n";

// Настройки БД
$host = '127.0.0.1';
$dbname = 'mi_core_db';
$username = 'ingest_user';
$password = 'IngestUserPassword2025';

// Настройки Ozon API из .env
$ozon_client_id = "26100";
$ozon_api_key = "7e074977-e0db-4ace-ba9e-82903e088b4b";
$ozon_api_url = "https://api-seller.ozon.ru";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Подключение к БД успешно\n\n";
    
    // 1. Получаем список товаров без реальных названий
    echo "🔍 1. Ищем товары без реальных названий...\n";
    
    $stmt = $pdo->query("
        SELECT DISTINCT i.product_id
        FROM inventory_data i
        LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
        WHERE i.product_id != 0 
        AND (dp.name LIKE 'Товар Ozon ID%' OR dp.name IS NULL)
        ORDER BY i.quantity_present DESC
        LIMIT 50
    ");
    
    $products_to_sync = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $count = count($products_to_sync);
    
    echo "   Найдено товаров для синхронизации: $count\n";
    
    if ($count == 0) {
        echo "✅ Все товары уже имеют реальные названия!\n";
        exit;
    }
    
    echo "   Примеры ID: " . implode(', ', array_slice($products_to_sync, 0, 10)) . "\n\n";
    
    // 2. Запрашиваем данные из Ozon API за -3 дня
    echo "📅 2. Запрашиваем данные из Ozon API за -3 дня...\n";
    
    $date_3_days_ago = date('Y-m-d', strtotime('-3 days'));
    echo "   Дата запроса: $date_3_days_ago\n";
    
    // Подготавливаем запрос к Ozon API
    $api_headers = [
        'Client-Id: ' . $ozon_client_id,
        'Api-Key: ' . $ozon_api_key,
        'Content-Type: application/json'
    ];
    
    // 3. Получаем информацию о товарах через Ozon API
    echo "\n🔄 3. Получаем информацию о товарах...\n";
    
    $updated_count = 0;
    $batch_size = 10; // Обрабатываем по 10 товаров за раз
    
    for ($i = 0; $i < count($products_to_sync); $i += $batch_size) {
        $batch = array_slice($products_to_sync, $i, $batch_size);
        
        echo "   Обрабатываем товары " . ($i + 1) . "-" . min($i + $batch_size, $count) . " из $count\n";
        
        // Запрос информации о товарах
        $request_data = [
            'product_id' => array_map('intval', $batch),
            'sku' => []
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $ozon_api_url . '/v2/product/info');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $api_headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200 && $response) {
            $data = json_decode($response, true);
            
            if (isset($data['result']) && is_array($data['result'])) {
                foreach ($data['result'] as $product) {
                    if (isset($product['id']) && isset($product['name'])) {
                        $product_id = $product['id'];
                        $product_name = trim($product['name']);
                        
                        if (!empty($product_name) && $product_name !== 'Товар Ozon ID ' . $product_id) {
                            // Обновляем название в БД
                            $stmt = $pdo->prepare("
                                INSERT INTO dim_products (sku_ozon, name, product_name, created_at, updated_at) 
                                VALUES (?, ?, ?, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE 
                                name = VALUES(name), 
                                product_name = VALUES(product_name),
                                updated_at = NOW()
                            ");
                            
                            $stmt->execute([
                                (string)$product_id,
                                $product_name,
                                $product_name
                            ]);
                            
                            $updated_count++;
                            echo "     ✅ ID $product_id: " . mb_substr($product_name, 0, 50) . "...\n";
                        }
                    }
                }
            }
        } else {
            echo "     ⚠️ Ошибка API: HTTP $http_code\n";
            if ($response) {
                $error_data = json_decode($response, true);
                if (isset($error_data['message'])) {
                    echo "     Сообщение: " . $error_data['message'] . "\n";
                }
            }
        }
        
        // Пауза между запросами для соблюдения лимитов API
        sleep(1);
    }
    
    echo "\n✅ Обновлено товаров: $updated_count\n\n";
    
    // 4. Проверяем результат
    echo "🧪 4. Проверяем результат...\n";
    
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN dp.name NOT LIKE 'Товар Ozon ID%' AND dp.name IS NOT NULL THEN 1 END) as with_real_names
        FROM inventory_data i
        LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
        WHERE i.product_id != 0
    ");
    
    $stats = $stmt->fetch();
    $real_names_percent = round(($stats['with_real_names'] / $stats['total']) * 100, 2);
    
    echo "   Всего товаров: {$stats['total']}\n";
    echo "   С реальными названиями: {$stats['with_real_names']}\n";
    echo "   Процент реальных названий: {$real_names_percent}%\n\n";
    
    // 5. Показываем примеры обновленных товаров
    echo "📋 5. Примеры обновленных товаров:\n";
    
    $stmt = $pdo->query("
        SELECT dp.sku_ozon, dp.name, i.quantity_present
        FROM dim_products dp
        JOIN inventory_data i ON CONCAT('', i.product_id) = dp.sku_ozon
        WHERE dp.name NOT LIKE 'Товар Ozon ID%' 
        AND dp.name NOT LIKE 'Неопределенный%'
        AND i.quantity_present > 0
        ORDER BY i.quantity_present DESC
        LIMIT 10
    ");
    
    while ($row = $stmt->fetch()) {
        echo "   📦 ID {$row['sku_ozon']}: {$row['name']} (остаток: {$row['quantity_present']})\n";
    }
    
    echo "\n🎉 СИНХРОНИЗАЦИЯ ЗАВЕРШЕНА!\n";
    echo "Теперь в системе отображаются реальные названия товаров из Ozon!\n\n";
    
    echo "📋 Следующие шаги:\n";
    echo "1. Проверьте дашборд - должны появиться реальные названия товаров\n";
    echo "2. Настройте автоматический запуск этого скрипта (например, через cron)\n";
    echo "3. Для полной синхронизации увеличьте LIMIT в запросе и запустите повторно\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
?>