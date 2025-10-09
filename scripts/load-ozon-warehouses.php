<?php
/**
 * Скрипт для загрузки складов Ozon
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Загружаем конфигурацию
require_once __DIR__ . '/../config.php';

echo "🏭 ЗАГРУЗКА СКЛАДОВ OZON\n";
echo str_repeat('=', 50) . "\n";

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

// Функция для выполнения запроса к Ozon API
function makeOzonRequest($endpoint, $data = []) {
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
    
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } else {
        // Для /v1/warehouse/list нужен POST с пустым телом
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
    }
    
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

// Загружаем склады
echo "\n📦 Загружаем список складов...\n";

try {
    $response = makeOzonRequest('/v1/warehouse/list');
    
    if (!isset($response['result']) || !is_array($response['result'])) {
        echo "❌ Неожиданный формат ответа API\n";
        echo "Ответ: " . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }
    
    $warehouses = $response['result'];
    echo "✅ Получено складов: " . count($warehouses) . "\n";
    
    if (empty($warehouses)) {
        echo "⚠️ Список складов пуст. Возможные причины:\n";
        echo "   - Неправильные API ключи\n";
        echo "   - У аккаунта нет складов\n";
        echo "   - Проблемы с доступом к API\n";
        exit(1);
    }
    
    // Очищаем старые данные
    echo "\n🧹 Очищаем старые данные о складах...\n";
    $pdo->exec("DELETE FROM ozon_warehouses");
    echo "✅ Старые данные удалены\n";
    
    // Подготавливаем запрос для вставки
    $stmt = $pdo->prepare("
        INSERT INTO ozon_warehouses (warehouse_id, name, is_rfbs, created_at, updated_at) 
        VALUES (?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE 
            name = VALUES(name),
            is_rfbs = VALUES(is_rfbs),
            updated_at = NOW()
    ");
    
    // Вставляем склады
    echo "\n💾 Сохраняем склады в базу данных...\n";
    $inserted = 0;
    
    foreach ($warehouses as $warehouse) {
        try {
            $warehouse_id = $warehouse['warehouse_id'] ?? null;
            $name = $warehouse['name'] ?? 'Неизвестный склад';
            $is_rfbs = isset($warehouse['is_rfbs']) ? (bool)$warehouse['is_rfbs'] : false;
            
            if (!$warehouse_id) {
                echo "⚠️ Пропускаем склад без ID: " . json_encode($warehouse) . "\n";
                continue;
            }
            
            $stmt->execute([$warehouse_id, $name, $is_rfbs ? 1 : 0]);
            $inserted++;
            
            echo "✅ Склад: $name (ID: $warehouse_id, RFBS: " . ($is_rfbs ? 'Да' : 'Нет') . ")\n";
            
        } catch (PDOException $e) {
            echo "❌ Ошибка при сохранении склада: " . $e->getMessage() . "\n";
            echo "   Данные склада: " . json_encode($warehouse) . "\n";
        }
    }
    
    echo "\n📊 РЕЗУЛЬТАТ:\n";
    echo "Всего складов получено: " . count($warehouses) . "\n";
    echo "Успешно сохранено: $inserted\n";
    
    // Проверяем результат
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_warehouses");
    $count = $stmt->fetch()['count'];
    echo "Складов в базе данных: $count\n";
    
    if ($count > 0) {
        echo "\n✅ УСПЕХ! Склады Ozon загружены\n";
        
        // Показываем примеры
        echo "\nПримеры загруженных складов:\n";
        $stmt = $pdo->query("SELECT warehouse_id, name, is_rfbs FROM ozon_warehouses LIMIT 5");
        $examples = $stmt->fetchAll();
        
        foreach ($examples as $example) {
            echo "- {$example['name']} (ID: {$example['warehouse_id']}, RFBS: " . 
                 ($example['is_rfbs'] ? 'Да' : 'Нет') . ")\n";
        }
    } else {
        echo "\n❌ ОШИБКА: Склады не были сохранены в базу данных\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка при загрузке складов: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n🎉 Загрузка складов завершена!\n";
?>