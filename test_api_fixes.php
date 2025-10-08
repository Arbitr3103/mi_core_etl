<?php
/**
 * Тест исправлений API для маркетинговой аналитики и критических остатков
 */

echo "🧪 Тестирование исправлений API...\n\n";

// Тест маркетинговой аналитики
echo "1. Тестирование маркетинговой аналитики:\n";
$marketing_url = "http://localhost/api/inventory-v4.php?action=marketing";
$marketing_response = @file_get_contents($marketing_url);

if ($marketing_response) {
    $marketing_data = json_decode($marketing_response, true);
    if ($marketing_data && $marketing_data['success']) {
        echo "   ✅ Маркетинговая аналитика работает\n";
        echo "   📊 Найдено товаров: " . count($marketing_data['data']['top_products'] ?? []) . "\n";
        echo "   🎯 Товаров требующих внимания: " . count($marketing_data['data']['attention_products'] ?? []) . "\n";
        echo "   📈 Категорий: " . count($marketing_data['data']['category_analysis'] ?? []) . "\n";
        echo "   🏷️ Брендов: " . count($marketing_data['data']['brand_analysis'] ?? []) . "\n";
    } else {
        echo "   ❌ Ошибка в маркетинговой аналитике: " . ($marketing_data['error'] ?? 'Unknown error') . "\n";
    }
} else {
    echo "   ❌ Не удалось подключиться к API маркетинговой аналитики\n";
}

echo "\n";

// Тест критических остатков
echo "2. Тестирование критических остатков:\n";
$critical_url = "http://localhost/api/inventory-v4.php?action=critical&threshold=10";
$critical_response = @file_get_contents($critical_url);

if ($critical_response) {
    $critical_data = json_decode($critical_response, true);
    if ($critical_data && $critical_data['success']) {
        echo "   ✅ Критические остатки работают\n";
        echo "   ⚠️ Критических позиций: " . count($critical_data['data']['critical_items'] ?? []) . "\n";
        echo "   🏢 Затронутых складов: " . ($critical_data['data']['stats']['affected_warehouses'] ?? 0) . "\n";
        echo "   📊 Анализ складов: " . count($critical_data['data']['warehouse_analysis'] ?? []) . "\n";
        
        // Проверяем, что есть правильные поля
        if (!empty($critical_data['data']['critical_items'])) {
            $first_item = $critical_data['data']['critical_items'][0];
            if (isset($first_item['warehouse_display_name']) && isset($first_item['urgency_level'])) {
                echo "   ✅ Поля складов и приоритетов корректны\n";
            } else {
                echo "   ⚠️ Некоторые поля могут отсутствовать\n";
            }
        }
    } else {
        echo "   ❌ Ошибка в критических остатках: " . ($critical_data['error'] ?? 'Unknown error') . "\n";
    }
} else {
    echo "   ❌ Не удалось подключиться к API критических остатков\n";
}

echo "\n";

// Тест базовой функциональности
echo "3. Тестирование базовой функциональности:\n";
$test_url = "http://localhost/api/inventory-v4.php?action=test";
$test_response = @file_get_contents($test_url);

if ($test_response) {
    $test_data = json_decode($test_response, true);
    if ($test_data && $test_data['success']) {
        echo "   ✅ Базовая функциональность работает\n";
        echo "   📦 Записей в inventory_data: " . ($test_data['data']['inventory_records'] ?? 0) . "\n";
        echo "   🏷️ Записей в product_master: " . ($test_data['data']['master_products'] ?? 0) . "\n";
    } else {
        echo "   ❌ Ошибка в базовой функциональности\n";
    }
} else {
    echo "   ❌ Не удалось подключиться к базовому API\n";
}

echo "\n🎉 Тестирование завершено!\n";
?>