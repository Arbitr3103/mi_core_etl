<?php
/**
 * Тест API endpoint на сервере
 */

echo "🌐 Тестирование API endpoint на сервере\n";
echo "======================================\n\n";

$baseUrl = 'https://api.zavodprostavok.ru';
$endpoints = [
    '/api/ozon-analytics.php?action=health',
    '/api/ozon-analytics.php?action=funnel-data&date_from=2025-09-01&date_to=2025-09-28',
    '/src/api/ozon-analytics.php?action=health' // Проверим и старый путь
];

foreach ($endpoints as $endpoint) {
    $url = $baseUrl . $endpoint;
    echo "🔗 Тестируем: $url\n";
    
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
        
        // Проверяем JSON
        $jsonData = json_decode($response, true);
        if ($jsonData) {
            echo "✅ Валидный JSON\n";
            echo "📋 Ключи ответа: " . implode(', ', array_keys($jsonData)) . "\n";
            
            if (isset($jsonData['success'])) {
                echo "🎯 Success: " . ($jsonData['success'] ? 'true' : 'false') . "\n";
            }
            
            if (isset($jsonData['data']) && is_array($jsonData['data'])) {
                echo "📊 Количество записей: " . count($jsonData['data']) . "\n";
            }
        } else {
            echo "❌ Некорректный JSON\n";
            echo "📄 Ответ: " . substr($response, 0, 200) . "...\n";
        }
    } elseif ($httpCode === 404) {
        echo "❌ Файл не найден (404)\n";
    } elseif ($httpCode === 405) {
        echo "⚠️ Метод не разрешен (405) - возможно, нужен POST\n";
    } else {
        echo "❌ Ошибка HTTP: $httpCode\n";
        echo "📄 Ответ: " . substr($response, 0, 200) . "\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n\n";
}

// Проверяем структуру файлов на сервере
echo "📁 Проверка структуры файлов:\n";
echo "============================\n";

$filesToCheck = [
    'src/api/ozon-analytics.php',
    'api/ozon-analytics.php',
    'src/classes/OzonAnalyticsAPI.php'
];

foreach ($filesToCheck as $file) {
    if (file_exists($file)) {
        echo "✅ $file - найден\n";
        echo "   Размер: " . filesize($file) . " байт\n";
        echo "   Права: " . substr(sprintf('%o', fileperms($file)), -4) . "\n";
    } else {
        echo "❌ $file - НЕ НАЙДЕН\n";
    }
}

echo "\n💡 Рекомендации:\n";
echo "================\n";
echo "1. Если API доступен по /api/ozon-analytics.php - используйте этот путь\n";
echo "2. Если получаете 405 ошибку - проверьте метод запроса (GET/POST)\n";
echo "3. Если 404 - проверьте правильность пути к файлу\n";
echo "4. Запустите: php test_real_ozon_api.php для проверки Ozon API\n";
?>