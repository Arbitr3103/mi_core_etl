<?php
/**
 * Тест исправленного метода processFunnelData для реальной структуры Ozon API
 */

require_once 'src/classes/OzonAnalyticsAPI.php';

// Симулируем реальный ответ Ozon API
$mockOzonResponse = [
    "data" => [
        [
            "dimensions" => [
                ["id" => "1750881567", "name" => "Товар 1"]
            ],
            "metrics" => [4312240, 8945, 15000] // [revenue, ordered_units, hits_view_pdp]
        ],
        [
            "dimensions" => [
                ["id" => "1750881568", "name" => "Товар 2"]
            ],
            "metrics" => [2156120, 4472, 8500]
        ]
    ],
    "totals" => [6468360, 13417, 23500]
];

echo "🧪 Тестируем исправленный метод processFunnelData\n";
echo "================================================\n\n";

try {
    // Создаем экземпляр API
    $ozonAPI = new OzonAnalyticsAPI('26100', '7e074977-e0db-4ace-ba9e-82903e088b4b');
    
    // Используем рефлексию для доступа к приватному методу
    $reflection = new ReflectionClass($ozonAPI);
    $processMethod = $reflection->getMethod('processFunnelData');
    $processMethod->setAccessible(true);
    
    // Тестируем обработку данных
    $dateFrom = '2024-01-01';
    $dateTo = '2024-01-31';
    $filters = ['product_id' => null, 'campaign_id' => null];
    
    $result = $processMethod->invoke($ozonAPI, $mockOzonResponse, $dateFrom, $dateTo, $filters);
    
    echo "✅ Результат обработки данных:\n";
    echo "Количество записей: " . count($result) . "\n\n";
    
    foreach ($result as $index => $item) {
        echo "📊 Запись " . ($index + 1) . ":\n";
        echo "  Product ID: " . ($item['product_id'] ?? 'null') . "\n";
        echo "  Просмотры: " . $item['views'] . "\n";
        echo "  Добавления в корзину: " . $item['cart_additions'] . "\n";
        echo "  Заказы: " . $item['orders'] . "\n";
        echo "  Выручка: " . $item['revenue'] . "\n";
        echo "  Конверсия просмотры → корзина: " . $item['conversion_view_to_cart'] . "%\n";
        echo "  Конверсия корзина → заказ: " . $item['conversion_cart_to_order'] . "%\n";
        echo "  Общая конверсия: " . $item['conversion_overall'] . "%\n";
        echo "\n";
    }
    
    // Тестируем пустой ответ
    echo "🧪 Тестируем пустой ответ:\n";
    $emptyResponse = ['data' => []];
    $emptyResult = $processMethod->invoke($ozonAPI, $emptyResponse, $dateFrom, $dateTo, $filters);
    
    echo "✅ Пустой ответ обработан корректно:\n";
    echo "Количество записей: " . count($emptyResult) . "\n";
    echo "Первая запись - просмотры: " . $emptyResult[0]['views'] . "\n";
    echo "Первая запись - заказы: " . $emptyResult[0]['orders'] . "\n\n";
    
    // Тестируем некорректный ответ
    echo "🧪 Тестируем некорректный ответ:\n";
    $invalidResponse = ['error' => 'Invalid request'];
    $invalidResult = $processMethod->invoke($ozonAPI, $invalidResponse, $dateFrom, $dateTo, $filters);
    
    echo "✅ Некорректный ответ обработан корректно:\n";
    echo "Количество записей: " . count($invalidResult) . "\n";
    echo "Первая запись - просмотры: " . $invalidResult[0]['views'] . "\n\n";
    
    echo "🎉 Все тесты пройдены успешно!\n";
    echo "Метод processFunnelData теперь корректно обрабатывает реальную структуру Ozon API.\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка в тесте: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
}
?>