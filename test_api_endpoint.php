<?php
/**
 * Тест API endpoint для проверки получения данных
 */

// Симулируем GET запрос к API
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['action'] = 'funnel-data';
$_GET['date_from'] = '2024-01-01';
$_GET['date_to'] = '2024-01-31';

echo "🧪 Тестируем API endpoint для получения данных воронки\n";
echo "====================================================\n\n";

echo "Параметры запроса:\n";
echo "- Метод: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "- Действие: " . $_GET['action'] . "\n";
echo "- Дата от: " . $_GET['date_from'] . "\n";
echo "- Дата до: " . $_GET['date_to'] . "\n\n";

echo "Включаем API endpoint...\n";

// Перехватываем вывод
ob_start();

try {
    // Включаем API файл
    include 'src/api/ozon-analytics.php';
} catch (Exception $e) {
    echo "❌ Ошибка при выполнении API: " . $e->getMessage() . "\n";
}

$output = ob_get_clean();

echo "📤 Ответ API:\n";
echo $output . "\n";

// Проверяем, является ли ответ валидным JSON
$jsonData = json_decode($output, true);
if ($jsonData) {
    echo "✅ Ответ является валидным JSON\n";
    echo "Успех: " . ($jsonData['success'] ? 'true' : 'false') . "\n";
    echo "Сообщение: " . ($jsonData['message'] ?? 'нет') . "\n";
    echo "Количество записей: " . (is_array($jsonData['data']) ? count($jsonData['data']) : 0) . "\n";
    
    if (!empty($jsonData['data'][0]['debug_raw_response'])) {
        echo "\n🔍 Отладочная информация найдена в ответе\n";
    }
} else {
    echo "❌ Ответ не является валидным JSON\n";
}
?>