<?php
/**
 * Тест реального Ozon API для проверки ответов
 */

echo "🧪 Тестирование реального Ozon API\n";
echo "==================================\n\n";

// Подключаем классы
require_once 'src/classes/OzonAnalyticsAPI.php';

try {
    // Подключение к БД
    $host = '127.0.0.1';
    $dbname = 'mi_core_db';
    $username = 'mi_core_user';
    $password = 'secure_password_123';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✅ Подключение к БД установлено\n";
    
    // Создаем экземпляр API
    $clientId = '26100';
    $apiKey = '7e074977-e0db-4ace-ba9e-82903e088b4b';
    
    $ozonAPI = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);
    echo "✅ OzonAnalyticsAPI создан\n\n";
    
    // Тестируем разные периоды
    $testPeriods = [
        ['2024-10-01', '2024-10-07', 'Октябрь 2024 (1 неделя)'],
        ['2024-09-01', '2024-09-30', 'Сентябрь 2024 (весь месяц)'],
        ['2025-09-01', '2025-09-07', 'Сентябрь 2025 (1 неделя)'],
        [date('Y-m-d', strtotime('-7 days')), date('Y-m-d'), 'Последние 7 дней']
    ];
    
    foreach ($testPeriods as $index => $period) {
        $dateFrom = $period[0];
        $dateTo = $period[1];
        $description = $period[2];
        
        echo "📊 Тест " . ($index + 1) . ": $description ($dateFrom - $dateTo)\n";
        echo str_repeat("-", 60) . "\n";
        
        try {
            // Получаем данные с отладочной информацией
            $funnelData = $ozonAPI->getFunnelData($dateFrom, $dateTo, ['use_cache' => false]);
            
            if (!empty($funnelData)) {
                $firstRecord = $funnelData[0];
                
                echo "✅ Получено записей: " . count($funnelData) . "\n";
                echo "📋 Первая запись:\n";
                echo "  - Product ID: " . ($firstRecord['product_id'] ?? 'null') . "\n";
                echo "  - Views: " . $firstRecord['views'] . "\n";
                echo "  - Orders: " . $firstRecord['orders'] . "\n";
                echo "  - Revenue: " . $firstRecord['revenue'] . " руб.\n";
                
                // Проверяем отладочную информацию
                if (isset($firstRecord['debug_raw_response'])) {
                    echo "\n🔍 Сырой ответ Ozon API:\n";
                    $rawResponse = $firstRecord['debug_raw_response'];
                    
                    if (is_array($rawResponse)) {
                        echo "  Структура ответа:\n";
                        foreach ($rawResponse as $key => $value) {
                            if (is_array($value)) {
                                echo "  - $key: массив с " . count($value) . " элементами\n";
                                if ($key === 'data' && !empty($value)) {
                                    echo "    Первый элемент data:\n";
                                    $firstDataItem = $value[0];
                                    foreach ($firstDataItem as $dataKey => $dataValue) {
                                        if (is_array($dataValue)) {
                                            echo "      - $dataKey: " . json_encode($dataValue) . "\n";
                                        } else {
                                            echo "      - $dataKey: $dataValue\n";
                                        }
                                    }
                                }
                            } else {
                                echo "  - $key: $value\n";
                            }
                        }
                    } else {
                        echo "  Raw response: " . substr(json_encode($rawResponse), 0, 200) . "...\n";
                    }
                }
                
                if (isset($firstRecord['debug_request'])) {
                    echo "\n📤 Параметры запроса к Ozon API:\n";
                    $debugRequest = $firstRecord['debug_request'];
                    echo "  URL: " . $debugRequest['url'] . "\n";
                    echo "  Data: " . json_encode($debugRequest['data']) . "\n";
                }
                
            } else {
                echo "⚠️ Данные не получены\n";
            }
            
        } catch (Exception $e) {
            echo "❌ Ошибка: " . $e->getMessage() . "\n";
        }
        
        echo "\n";
        sleep(2); // Пауза между запросами
    }
    
    // Тестируем прямой запрос к Ozon API
    echo "🌐 Прямой тест Ozon API\n";
    echo "======================\n";
    
    $url = 'https://api-seller.ozon.ru/v1/analytics/data';
    $headers = [
        'Content-Type: application/json',
        'Client-Id: ' . $clientId,
        'Api-Key: ' . $apiKey
    ];
    
    $data = [
        'date_from' => '2024-10-01',
        'date_to' => '2024-10-07',
        'metrics' => ['revenue', 'ordered_units', 'hits_view_pdp'],
        'dimension' => ['sku'],
        'limit' => 10
    ];
    
    echo "📤 Отправляем запрос к: $url\n";
    echo "📋 Данные запроса: " . json_encode($data) . "\n\n";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "📥 HTTP код: $httpCode\n";
    echo "📄 Ответ: " . substr($response, 0, 500) . "\n";
    
    if ($httpCode === 200) {
        $jsonResponse = json_decode($response, true);
        if ($jsonResponse) {
            echo "✅ Валидный JSON ответ\n";
            echo "🔍 Структура ответа:\n";
            foreach ($jsonResponse as $key => $value) {
                if (is_array($value)) {
                    echo "  - $key: массив с " . count($value) . " элементами\n";
                } else {
                    echo "  - $key: $value\n";
                }
            }
        } else {
            echo "❌ Некорректный JSON в ответе\n";
        }
    } else {
        echo "❌ Ошибка HTTP: $httpCode\n";
    }
    
} catch (Exception $e) {
    echo "❌ Критическая ошибка: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
}
?>