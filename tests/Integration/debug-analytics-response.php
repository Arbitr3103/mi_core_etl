<?php
/**
 * Отладка ответа Analytics API v2 для понимания структуры данных
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

echo "🔍 ОТЛАДКА ОТВЕТА ANALYTICS API V2\n";
echo str_repeat('=', 50) . "\n";

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['code' => $httpCode, 'response' => $response];
}

$today = date('Y-m-d');
$payload = [
    'date_from' => $today,
    'date_to' => $today,
    'limit' => 5, // Только 5 записей для отладки
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

echo "Запрос к /v2/analytics/stock_on_warehouses:\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$result = makeOzonAnalyticsRequest('/v2/analytics/stock_on_warehouses', $payload);

echo "HTTP Code: {$result['code']}\n";
echo "Ответ:\n";
echo $result['response'] . "\n\n";

if ($result['code'] === 200) {
    $data = json_decode($result['response'], true);
    if ($data && isset($data['result']['rows'])) {
        echo "Структура первой записи:\n";
        if (!empty($data['result']['rows'])) {
            $first_row = $data['result']['rows'][0];
            echo json_encode($first_row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
            
            echo "\nИзвлечение данных:\n";
            if (isset($first_row['dimensions'])) {
                echo "SKU: " . ($first_row['dimensions']['sku'] ?? 'НЕТ') . "\n";
                echo "Warehouse: " . ($first_row['dimensions']['warehouse'] ?? 'НЕТ') . "\n";
            }
            if (isset($first_row['metrics'])) {
                echo "Free to sell: " . ($first_row['metrics']['free_to_sell_amount'] ?? 'НЕТ') . "\n";
                echo "Promised: " . ($first_row['metrics']['promised_amount'] ?? 'НЕТ') . "\n";
                echo "Reserved: " . ($first_row['metrics']['reserved_amount'] ?? 'НЕТ') . "\n";
            }
        }
    }
}
?>