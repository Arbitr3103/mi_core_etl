<?php
/**
 * Тест улучшенных названий в API
 */

echo "🧪 Тестирование улучшенных названий товаров...\n\n";

// Тест маркетинговой аналитики
echo "1. Тестирование маркетинговой аналитики:\n";
$marketing_url = "http://localhost/api/inventory-v4.php?action=marketing";
$marketing_response = @file_get_contents($marketing_url);

if ($marketing_response) {
    $marketing_data = json_decode($marketing_response, true);
    if ($marketing_data && $marketing_data['success']) {
        echo "   ✅ Маркетинговая аналитика работает\n";
        
        $attention_products = $marketing_data['data']['attention_products'] ?? [];
        echo "   🎯 Товаров требующих внимания: " . count($attention_products) . "\n";
        
        if (!empty($attention_products)) {
            echo "   📝 Примеры названий:\n";
            foreach (array_slice($attention_products, 0, 3) as $product) {
                echo "      - " . $product['product_name'] . " (SKU: " . $product['sku'] . ")\n";
            }
        }
        
        $top_products = $marketing_data['data']['top_products'] ?? [];
        echo "   🏆 Топ товаров: " . count($top_products) . "\n";
        
        if (!empty($top_products)) {
            echo "   📝 Примеры топ товаров:\n";
            foreach (array_slice($top_products, 0, 3) as $product) {
                echo "      - " . $product['product_name'] . " (остаток: " . $product['total_stock'] . ")\n";
            }
        }
        
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
        
        $critical_items = $critical_data['data']['critical_items'] ?? [];
        echo "   ⚠️ Критических позиций: " . count($critical_items) . "\n";
        
        if (!empty($critical_items)) {
            echo "   📝 Примеры критических товаров:\n";
            foreach (array_slice($critical_items, 0, 3) as $item) {
                echo "      - " . $item['display_name'] . " (остаток: " . $item['current_stock'] . ")\n";
            }
        }
        
    } else {
        echo "   ❌ Ошибка в критических остатках: " . ($critical_data['error'] ?? 'Unknown error') . "\n";
    }
} else {
    echo "   ❌ Не удалось подключиться к API критических остатков\n";
}

echo "\n🎉 Тестирование завершено!\n";
?>