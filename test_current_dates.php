<?php
/**
 * Тест с текущими датами для Ozon API
 */

echo "🧪 Тест Ozon API с текущими датами\n";
echo "==================================\n\n";

// Параметры API
$clientId = '26100';
$apiKey = '7e074977-e0db-4ace-ba9e-82903e088b4b';
$baseUrl = 'https://api-seller.ozon.ru';

// Тестируем с правильными датами (date_to > date_from)
$testPeriods = [
    [date('Y-m-d', strtotime('-7 days')), date('Y-m-d', strtotime('-1 day')), 'Последние 7 дней'],
    [date('Y-m-d', strtotime('-14 days')), date('Y-m-d', strtotime('-7 days')), 'Неделя назад'],
    [date('Y-m-d', strtotime('-30 days')), date('Y-m-d', strtotime('-1 day')), 'Последние 30 дней'],
    ['2025-09-01', '2025-09-02', 'Сентябрь 2025 (2 дня)'],
    ['2025-09-01', '2025-09-08', 'Сентябрь 2025 (неделя)']
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
    
    echo "📤 Запрос: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    
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
            echo "📊 Полный ответ:\n";
            echo json_encode($jsonResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            
            // Анализируем структуру
            if (isset($jsonResponse['result']) && is_array($jsonResponse['result'])) {
                echo "\n💡 Найден массив 'result' с " . count($jsonResponse['result']) . " элементами\n";
                
                foreach ($jsonResponse['result'] as $resultIndex => $resultItem) {
                    echo "  Элемент $resultIndex: " . json_encode($resultItem, JSON_UNESCAPED_UNICODE) . "\n";
                }
            }
            
            if (isset($jsonResponse['data']) && is_array($jsonResponse['data'])) {
                echo "\n💡 Найден массив 'data' с " . count($jsonResponse['data']) . " элементами\n";
            }
            
        } else {
            echo "❌ Некорректный JSON\n";
            echo "📄 Ответ: " . $result['response'] . "\n";
        }
    } else {
        echo "❌ Ошибка HTTP: " . $result['http_code'] . "\n";
        echo "📄 Ответ: " . $result['response'] . "\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n\n";
    sleep(2);
}

// Тест с минимальными параметрами
echo "🔬 Тест с минимальными параметрами\n";
echo "=================================\n";

$minimalData = [
    'date_from' => date('Y-m-d', strtotime('-7 days')),
    'date_to' => date('Y-m-d', strtotime('-1 day')),
    'metrics' => ['revenue'],
    'dimension' => ['sku'],
    'limit' => 1
];

echo "📤 Минимальный запрос: " . json_encode($minimalData, JSON_UNESCAPED_UNICODE) . "\n\n";

$result = makeOzonRequest($baseUrl . '/v1/analytics/data', $minimalData, [
    'Content-Type: application/json',
    'Client-Id: ' . $clientId,
    'Api-Key: ' . $apiKey
]);

echo "📥 HTTP код: " . $result['http_code'] . "\n";
echo "📄 Полный ответ: " . $result['response'] . "\n";

echo "\n🎯 ВЫВОДЫ:\n";
echo "=========\n";
echo "1. Ozon API требует date_to > date_from (не равно!)\n";
echo "2. API возвращает 'result' вместо 'data'\n";
echo "3. Нужно исправить код обработки ответа\n";
echo "4. Возможно, нет данных за тестируемые периоды\n";
?>