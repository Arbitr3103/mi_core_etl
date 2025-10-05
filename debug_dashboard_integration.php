<?php
/**
 * Диагностика интеграции дашборда с Ozon API
 */

echo "🔍 Диагностика интеграции дашборда с Ozon API\n";
echo "============================================\n\n";

// Тестируем разные варианты параметров
$testUrls = [
    // Параметры, которые использует дашборд
    'https://api.zavodprostavok.ru/api/ozon-analytics.php?action=funnel-data&start_date=2025-09-01&end_date=2025-09-28',
    
    // Правильные параметры API
    'https://api.zavodprostavok.ru/api/ozon-analytics.php?action=funnel-data&date_from=2025-09-01&date_to=2025-09-28',
    
    // Health check
    'https://api.zavodprostavok.ru/api/ozon-analytics.php?action=health',
    
    // Демографические данные
    'https://api.zavodprostavok.ru/api/ozon-analytics.php?action=demographics&date_from=2025-09-01&date_to=2025-09-28'
];

foreach ($testUrls as $index => $url) {
    echo "🧪 Тест " . ($index + 1) . ": " . basename(parse_url($url, PHP_URL_PATH)) . "\n";
    echo "🔗 URL: $url\n";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "📊 HTTP код: $httpCode\n";
    
    if ($error) {
        echo "❌ Ошибка cURL: $error\n";
    } elseif ($httpCode === 200) {
        echo "✅ Успешный ответ\n";
        
        $jsonData = json_decode($response, true);
        if ($jsonData) {
            echo "✅ Валидный JSON\n";
            
            if (isset($jsonData['success'])) {
                echo "🎯 Success: " . ($jsonData['success'] ? 'true' : 'false') . "\n";
            }
            
            if (isset($jsonData['data']) && is_array($jsonData['data'])) {
                echo "📊 Количество записей: " . count($jsonData['data']) . "\n";
                
                if (!empty($jsonData['data'][0])) {
                    $firstRecord = $jsonData['data'][0];
                    echo "💰 Первая запись:\n";
                    echo "  - Product ID: " . ($firstRecord['product_id'] ?? 'null') . "\n";
                    echo "  - Revenue: " . ($firstRecord['revenue'] ?? 0) . " руб.\n";
                    echo "  - Orders: " . ($firstRecord['orders'] ?? 0) . "\n";
                    echo "  - Views: " . ($firstRecord['views'] ?? 0) . "\n";
                }
            }
            
            if (isset($jsonData['message'])) {
                echo "💬 Сообщение: " . $jsonData['message'] . "\n";
            }
        } else {
            echo "❌ Некорректный JSON\n";
            echo "📄 Ответ: " . substr($response, 0, 200) . "...\n";
        }
    } else {
        echo "❌ Ошибка HTTP: $httpCode\n";
        echo "📄 Ответ: " . substr($response, 0, 200) . "\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n\n";
}

// Проверяем, поддерживает ли API параметры дашборда
echo "🔧 Проверка совместимости параметров дашборда\n";
echo "=============================================\n";

echo "Дашборд отправляет параметры: start_date, end_date\n";
echo "API ожидает параметры: date_from, date_to\n\n";

echo "💡 РЕШЕНИЯ:\n";
echo "===========\n";
echo "1. Изменить дашборд для отправки правильных параметров\n";
echo "2. Добавить поддержку start_date/end_date в API\n";
echo "3. Проверить JavaScript консоль дашборда на ошибки\n\n";

// Создаем тестовый HTML для проверки AJAX запросов
$testHtml = '<!DOCTYPE html>
<html>
<head>
    <title>Тест Ozon API</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <h1>Тест Ozon API</h1>
    <button onclick="testAPI()">Тест API</button>
    <div id="result"></div>
    
    <script>
    function testAPI() {
        console.log("Тестируем API...");
        
        $.ajax({
            url: "/api/ozon-analytics.php",
            method: "GET",
            data: {
                action: "funnel-data",
                date_from: "2025-09-01",
                date_to: "2025-09-28"
            },
            success: function(data) {
                console.log("Успех:", data);
                $("#result").html("<pre>" + JSON.stringify(data, null, 2) + "</pre>");
            },
            error: function(xhr, status, error) {
                console.error("Ошибка:", error);
                $("#result").html("Ошибка: " + error);
            }
        });
    }
    </script>
</body>
</html>';

file_put_contents('test_ozon_api.html', $testHtml);

echo "📄 Создан файл test_ozon_api.html для тестирования AJAX запросов\n";
echo "🌐 Откройте: https://api.zavodprostavok.ru/test_ozon_api.html\n";
echo "🔍 Проверьте консоль браузера на ошибки JavaScript\n\n";

echo "🎯 СЛЕДУЮЩИЕ ШАГИ:\n";
echo "==================\n";
echo "1. Откройте дашборд и проверьте консоль браузера (F12)\n";
echo "2. Найдите ошибки JavaScript или AJAX запросов\n";
echo "3. Проверьте, какие параметры отправляет дашборд\n";
echo "4. При необходимости исправьте параметры в дашборде\n";
?>