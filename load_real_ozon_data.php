<?php
/**
 * Загрузка реальных данных от Ozon API в базу данных
 */

// Подключение к базе данных
try {
    $pdo = new PDO(
        'pgsql:host=localhost;dbname=mi_core_db;port=5432',
        'mi_core_user',
        'mi_core_2024_secure',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    echo "✅ Подключение к базе данных успешно\n";
} catch (Exception $e) {
    echo "❌ Ошибка подключения к базе: " . $e->getMessage() . "\n";
    exit(1);
}

// Настройки Ozon API
$clientId = '26100';
$apiKey = '7e074977-e0db-4ace-ba9e-82903e088b4b';

// Получение данных от Ozon Analytics API
echo "📥 Получение данных от Ozon Analytics API...\n";

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => 'https://api-seller.ozon.ru/v2/analytics/stock_on_warehouses',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Client-Id: ' . $clientId,
        'Api-Key: ' . $apiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'date_from' => date('Y-m-d', strtotime('-7 days')),
        'date_to' => date('Y-m-d'),
        'limit' => 1000,
        'offset' => 0
    ])
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($httpCode !== 200) {
    echo "❌ Ошибка API: HTTP $httpCode\n";
    echo "Response: $response\n";
    exit(1);
}

$data = json_decode($response, true);
if (!$data || !isset($data['result']['rows'])) {
    echo "❌ Неверный формат ответа API\n";
    exit(1);
}

$rows = $data['result']['rows'];
echo "✅ Получено " . count($rows) . " записей от Ozon API\n";

// Очистка старых данных
echo "🧹 Очистка старых данных...\n";
$pdo->exec("DELETE FROM inventory WHERE source = 'ozon'");

// Подготовка данных для вставки
$insertedCount = 0;
$skuToProductId = [];

foreach ($rows as $row) {
    try {
        // Создание или получение product_id
        $sku = $row['sku'];
        $itemName = $row['item_name'];
        $itemCode = $row['item_code'] ?? '';
        
        if (!isset($skuToProductId[$sku])) {
            // Проверяем, есть ли уже такой товар
            $stmt = $pdo->prepare("SELECT id FROM dim_products WHERE sku_ozon = ?");
            $stmt->execute([$sku]);
            $existingProduct = $stmt->fetch();
            
            if ($existingProduct) {
                $skuToProductId[$sku] = $existingProduct['id'];
            } else {
                // Создаем новый товар
                $stmt = $pdo->prepare("
                    INSERT INTO dim_products (sku_ozon, product_name, barcode) 
                    VALUES (?, ?, ?) 
                    RETURNING id
                ");
                $stmt->execute([$sku, $itemName, $itemCode]);
                $newProduct = $stmt->fetch();
                $skuToProductId[$sku] = $newProduct['id'];
            }
        }
        
        $productId = $skuToProductId[$sku];
        $warehouseName = $row['warehouse_name'];
        $quantityPresent = ($row['free_to_sell_amount'] ?? 0) + ($row['promised_amount'] ?? 0);
        $quantityReserved = $row['reserved_amount'] ?? 0;
        
        // Вставка записи в inventory
        $stmt = $pdo->prepare("
            INSERT INTO inventory (
                product_id, 
                warehouse_name, 
                stock_type,
                quantity_present, 
                quantity_reserved, 
                source, 
                data_source,
                normalized_warehouse_name,
                updated_at
            ) VALUES (?, ?, 'fbs', ?, ?, 'ozon', 'ozon_analytics', ?, NOW())
        ");
        
        $normalizedWarehouse = normalizeWarehouseName($warehouseName);
        
        $stmt->execute([
            $productId,
            $warehouseName,
            $quantityPresent,
            $quantityReserved,
            $normalizedWarehouse
        ]);
        
        $insertedCount++;
        
    } catch (Exception $e) {
        echo "⚠️ Ошибка при обработке записи: " . $e->getMessage() . "\n";
        continue;
    }
}

echo "✅ Загружено $insertedCount записей в базу данных\n";

// Статистика
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT warehouse_name) as warehouses,
        COUNT(DISTINCT product_id) as products,
        SUM(quantity_present) as total_stock
    FROM inventory 
    WHERE source = 'ozon'
");
$stats = $stmt->fetch();

echo "\n📊 Статистика загруженных данных:\n";
echo "   Складов: " . $stats['warehouses'] . "\n";
echo "   Товаров: " . $stats['products'] . "\n";
echo "   Общий остаток: " . number_format($stats['total_stock']) . " единиц\n";

// Топ складов
echo "\n🏪 Топ складов по остаткам:\n";
$stmt = $pdo->query("
    SELECT 
        normalized_warehouse_name,
        COUNT(*) as items,
        SUM(quantity_present) as total_stock
    FROM inventory 
    WHERE source = 'ozon'
    GROUP BY normalized_warehouse_name
    ORDER BY total_stock DESC
    LIMIT 5
");

while ($warehouse = $stmt->fetch()) {
    echo "   " . $warehouse['normalized_warehouse_name'] . ": " . 
         number_format($warehouse['total_stock']) . " единиц (" . 
         $warehouse['items'] . " позиций)\n";
}

echo "\n🎉 Загрузка реальных данных завершена!\n";
echo "Теперь откройте дашборд: http://localhost:8081/warehouse_manager_dashboard.html\n";

/**
 * Нормализация названий складов
 */
function normalizeWarehouseName($name) {
    $name = trim($name);
    
    // Замены для стандартизации
    $replacements = [
        'Санкт_Петербург_РФЦ' => 'СПб_РФЦ',
        'Екатеринбург_РФЦ_НОВЫЙ' => 'Екатеринбург_РФЦ',
        'Новосибирск_РФЦ_НОВЫЙ' => 'Новосибирск_РФЦ',
        'Казань_РФЦ_НОВЫЙ' => 'Казань_РФЦ',
        'Ростов_на_Дону_РФЦ' => 'Ростов_РФЦ'
    ];
    
    return $replacements[$name] ?? $name;
}
?>