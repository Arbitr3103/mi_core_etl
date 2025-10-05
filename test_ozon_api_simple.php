<?php
/**
 * Простой тест Ozon API без зависимостей
 */

echo "🧪 Простой тест Ozon API\n";
echo "========================\n\n";

// Параметры API
$clientId = '26100';
$apiKey = '7e074977-e0db-4ace-ba9e-82903e088b4b';
$baseUrl = 'https://api-seller.ozon.ru';

// Тестируем разные периоды
$testPeriods = [
    ['2024-10-01', '2024-10-07', 'Октябрь 2024 (1 неделя)'],
    ['2024-09-01', '2024-09-30', 'Сентябрь 2024 (весь месяц)'],
    ['2024-08-01', '2024-08-31', 'Август 2024 (весь месяц)'],
    ['2025-09-01', '2025-09-07', 'Сентябрь 2025 (1 неделя)']
];

function makeOzonRequest($url, $data, $headers) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    return [
        'response' => $response,
        'http_code' => $httpCode,
        'error' => $error
    ];
}

foreach ($testPeriods as $index => $period) {
    $dateFrom = $period[0];
    $dateTo = $period[1];
    $description = $period[2];
    
    echo "📊 Тест " . ($index + 1) . ": $description ($dateFrom - $dateTo)\n";
    echo str_repeat("-", 60) . "\n";
    
    // Подготавливаем запрос
    $url = $baseUrl . '/v1/analytics/data';
    $headers = [
        'Content-Type: application/json',
        'Client-Id: ' . $clientId,
        'Api-Key: ' . $apiKey
    ];
    
    $data = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'metrics' => ['revenue', 'ordered_units', 'hits_view_pdp'],
        'dimension' => ['sku'],
        'sort' => [
            [
                'key' => 'revenue',
                'order' => 'DESC'
            ]
        ],
        'limit' => 10
    ];
    
    echo "📤 URL: $url\n";
    echo "📋 Запрос: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    
    // Выполняем запрос
    $result = makeOzonRequest($url, $data, $headers);
    
    echo "📥 HTTP код: " . $result['http_code'] . "\n";
    
    if ($result['error']) {
        echo "❌ Ошибка cURL: " . $result['error'] . "\n";
    } elseif ($result['http_code'] === 200) {
        echo "✅ Успешный ответ\n";
        
        $jsonResponse = json_decode($result['response'], true);
        if ($jsonResponse) {
            echo "✅ Валидный JSON\n";
            echo "📊 Структура ответа:\n";
            
            foreach ($jsonResponse as $key => $value) {
                if (is_array($value)) {
                    echo "  - $key: массив с " . count($value) . " элементами\n";
                    
                    if ($key === 'data' && !empty($value)) {
                        echo "    📋 Первый элемент data:\n";
                        $firstItem = $value[0];
                        foreach ($firstItem as $itemKey => $itemValue) {
                            if (is_array($itemValue)) {
                                echo "      - $itemKey: " . json_encode($itemValue, JSON_UNESCAPED_UNICODE) . "\n";
                            } else {
                                echo "      - $itemKey: $itemValue\n";
                            }
                        }
                    }
                } else {
                    echo "  - $key: $value\n";
                }
            }
            
            // Анализируем данные
            if (isset($jsonResponse['data']) && !empty($jsonResponse['data'])) {
                echo "\n💰 Анализ данных:\n";
                $totalRevenue = 0;
                $totalOrders = 0;
                $totalViews = 0;
                
                foreach ($jsonResponse['data'] as $item) {
                    if (isset($item['metrics']) && is_array($item['metrics'])) {
                        $totalRevenue += $item['metrics'][0] ?? 0; // revenue
                        $totalOrders += $item['metrics'][1] ?? 0;  // ordered_units
                        $totalViews += $item['metrics'][2] ?? 0;   // hits_view_pdp
                    }
                }
                
                echo "  💵 Общая выручка: " . number_format($totalRevenue, 2) . " руб.\n";
                echo "  📦 Общие заказы: " . number_format($totalOrders) . "\n";
                echo "  👀 Общие просмотры: " . number_format($totalViews) . "\n";
                
                if ($totalViews > 0) {
                    $conversion = round(($totalOrders / $totalViews) * 100, 2);
                    echo "  📈 Конверсия: $conversion%\n";
                }
            } else {
                echo "\n⚠️ Данные пустые или отсутствуют\n";
            }
            
        } else {
            echo "❌ Некорректный JSON\n";
            echo "📄 Ответ: " . substr($result['response'], 0, 500) . "\n";
        }
    } else {
        echo "❌ Ошибка HTTP: " . $result['http_code'] . "\n";
        echo "📄 Ответ: " . substr($result['response'], 0, 500) . "\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n\n";
    sleep(2); // Пауза между запросами
}

// Дополнительный тест с другими метриками
echo "🔬 Дополнительный тест с расширенными метриками\n";
echo "==============================================\n";

$extendedData = [
    'date_from' => '2024-09-01',
    'date_to' => '2024-09-30',
    'metrics' => [
        'revenue',
        'ordered_units', 
        'hits_view_pdp',
        'hits_view_search',
        'hits_tocart_pdp',
        'session_view_pdp'
    ],
    'dimension' => ['sku'],
    'limit' => 5
];

echo "📤 Расширенный запрос: " . json_encode($extendedData, JSON_UNESCAPED_UNICODE) . "\n\n";

$result = makeOzonRequest($baseUrl . '/v1/analytics/data', $extendedData, [
    'Content-Type: application/json',
    'Client-Id: ' . $clientId,
    'Api-Key: ' . $apiKey
]);

echo "📥 HTTP код: " . $result['http_code'] . "\n";
echo "📄 Ответ: " . substr($result['response'], 0, 1000) . "\n";

echo "\n🎯 ВЫВОДЫ:\n";
echo "=========\n";
echo "1. Проверьте, какие периоды возвращают данные\n";
echo "2. Обратите внимание на структуру ответа Ozon API\n";
echo "3. Если все периоды пустые - возможно, нет доступа к данным\n";
echo "4. Если есть данные - используйте эти периоды для тестирования дашборда\n";
?>