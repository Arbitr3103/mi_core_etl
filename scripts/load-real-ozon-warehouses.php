<?php
/**
 * Скрипт для загрузки РЕАЛЬНЫХ складов Ozon через Analytics API v2
 * Использует endpoint /v2/analytics/stock_on_warehouses для получения складов с остатками
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Загружаем конфигурацию
require_once __DIR__ . '/../config.php';

echo "🏭 ЗАГРУЗКА РЕАЛЬНЫХ СКЛАДОВ OZON (Analytics API v2)\n";
echo str_repeat('=', 60) . "\n";

// Проверяем API ключи
if (!OZON_CLIENT_ID || !OZON_API_KEY) {
    echo "❌ Ошибка: API ключи Ozon не настроены в .env файле\n";
    echo "Необходимо указать OZON_CLIENT_ID и OZON_API_KEY\n";
    exit(1);
}

// Подключаемся к базе данных
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    echo "✅ Подключение к базе данных успешно\n";
} catch (PDOException $e) {
    echo "❌ Ошибка подключения к базе данных: " . $e->getMessage() . "\n";
    exit(1);
}

// Функция для выполнения запроса к Ozon Analytics API v2
function makeOzonAnalyticsRequest($endpoint, $data = []) {
    $url = OZON_API_BASE_URL . $endpoint;
    
    $headers = [
        'Client-Id: ' . OZON_CLIENT_ID,
        'Api-Key: ' . OZON_API_KEY,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception("CURL Error: $error");
    }
    
    if ($httpCode !== 200) {
        throw new Exception("HTTP Error: $httpCode, Response: $response");
    }
    
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON Decode Error: " . json_last_error_msg());
    }
    
    return $decoded;
}

// Загружаем склады через Analytics API
echo "\n📊 Загружаем склады через Analytics API v2...\n";

try {
    // Используем Analytics API для получения складов с остатками
    $today = date('Y-m-d');
    $payload = [
        'date_from' => $today,
        'date_to' => $today,
        'limit' => 1000,
        'offset' => 0,
        'metrics' => [
            'free_to_sell_amount',
            'promised_amount',
            'reserved_amount'
        ],
        'dimensions' => [
            'sku',
            'warehouse'
        ]
    ];
    
    echo "Запрос к /v2/analytics/stock_on_warehouses за $today...\n";
    $response = makeOzonAnalyticsRequest('/v2/analytics/stock_on_warehouses', $payload);
    
    if (!isset($response['result']) || !isset($response['result']['rows'])) {
        echo "❌ Неожиданный формат ответа Analytics API\n";
        echo "Ответ: " . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }
    
    $rows = $response['result']['rows'];
    echo "✅ Получено записей из Analytics API: " . count($rows) . "\n";
    
    if (empty($rows)) {
        echo "⚠️ Analytics API вернул пустой список. Возможные причины:\n";
        echo "   - Нет остатков товаров на складах\n";
        echo "   - Неправильные API ключи\n";
        echo "   - Нет данных за указанную дату\n";
        exit(1);
    }
    
    // Извлекаем уникальные склады из данных
    $warehouses = [];
    foreach ($rows as $row) {
        if (isset($row['warehouse_name'])) {
            $warehouse = $row['warehouse_name'];
            if (!isset($warehouses[$warehouse])) {
                $warehouses[$warehouse] = [
                    'name' => $warehouse,
                    'products_count' => 0,
                    'total_stock' => 0
                ];
            }
            $warehouses[$warehouse]['products_count']++;
            
            // Суммируем остатки
            $free_to_sell = $row['free_to_sell_amount'] ?? 0;
            $promised = $row['promised_amount'] ?? 0;
            $reserved = $row['reserved_amount'] ?? 0;
            $warehouses[$warehouse]['total_stock'] += ($free_to_sell + $promised + $reserved);
        }
    }
    
    echo "✅ Найдено уникальных складов: " . count($warehouses) . "\n";
    
    if (empty($warehouses)) {
        echo "❌ Не удалось извлечь информацию о складах из данных\n";
        exit(1);
    }
    
    // Очищаем старые данные
    echo "\n🧹 Очищаем старые данные о складах...\n";
    $pdo->exec("DELETE FROM ozon_warehouses");
    echo "✅ Старые данные удалены\n";
    
    // Подготавливаем запрос для вставки
    $stmt = $pdo->prepare("
        INSERT INTO ozon_warehouses (id, name, is_rfbs, created_at, updated_at) 
        VALUES (?, ?, ?, NOW(), NOW())
    ");
    
    // Вставляем склады
    echo "\n💾 Сохраняем реальные склады в базу данных...\n";
    $inserted = 0;
    $warehouse_id = 1;
    
    foreach ($warehouses as $warehouse_name => $warehouse_data) {
        try {
            // Определяем тип склада (RFBS или FBO) по названию
            $is_rfbs = (stripos($warehouse_name, 'rfbs') !== false || 
                       stripos($warehouse_name, 'fbs') !== false) ? 1 : 0;
            
            $stmt->execute([$warehouse_id, $warehouse_name, $is_rfbs]);
            $inserted++;
            
            echo "✅ Склад: $warehouse_name\n";
            echo "   - ID: $warehouse_id\n";
            echo "   - Тип: " . ($is_rfbs ? 'RFBS/FBS' : 'FBO') . "\n";
            echo "   - Товаров: {$warehouse_data['products_count']}\n";
            echo "   - Общий остаток: {$warehouse_data['total_stock']}\n\n";
            
            $warehouse_id++;
            
        } catch (PDOException $e) {
            echo "❌ Ошибка при сохранении склада '$warehouse_name': " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n📊 РЕЗУЛЬТАТ:\n";
    echo "Всего складов найдено: " . count($warehouses) . "\n";
    echo "Успешно сохранено: $inserted\n";
    
    // Проверяем результат
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_warehouses");
    $count = $stmt->fetch()['count'];
    echo "Складов в базе данных: $count\n";
    
    if ($count > 0) {
        echo "\n✅ УСПЕХ! Реальные склады Ozon загружены\n";
        
        // Показываем примеры
        echo "\nЗагруженные склады:\n";
        $stmt = $pdo->query("SELECT id, name, is_rfbs FROM ozon_warehouses ORDER BY id");
        $examples = $stmt->fetchAll();
        
        foreach ($examples as $example) {
            echo "- {$example['name']} (ID: {$example['id']}, Тип: " . 
                 ($example['is_rfbs'] ? 'RFBS/FBS' : 'FBO') . ")\n";
        }
        
        echo "\n🎯 Теперь у вас есть реальные склады с остатками товаров!\n";
        echo "Маркетологи могут принимать решения на основе актуальных данных.\n";
    } else {
        echo "\n❌ ОШИБКА: Склады не были сохранены в базу данных\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка при загрузке складов: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎉 Загрузка реальных складов завершена!\n";
echo "Теперь BI система содержит актуальную информацию о складах Ozon.\n";
?>