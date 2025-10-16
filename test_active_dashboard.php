<?php
/**
 * Тестовый скрипт для проверки обновленного дашборда с активными товарами
 */

echo "🧪 Тестирование дашборда активных товаров\n";
echo "==========================================\n\n";

// Тест 1: Проверка статистики активных товаров
echo "1️⃣ Тестирование статистики активных товаров...\n";
$stats_url = "http://localhost/api/inventory-v4.php?action=stats&active_only=1";
$stats_response = @file_get_contents($stats_url);

if ($stats_response) {
    $stats_data = json_decode($stats_response, true);
    if (isset($stats_data['data']['summary'])) {
        echo "✅ Статистика активных товаров получена:\n";
        foreach ($stats_data['data']['summary'] as $key => $value) {
            echo "   - $key: $value\n";
        }
    } else {
        echo "❌ Неверный формат ответа статистики\n";
    }
} else {
    echo "❌ Не удалось получить статистику активных товаров\n";
}

echo "\n";

// Тест 2: Проверка критических остатков активных товаров
echo "2️⃣ Тестирование критических остатков активных товаров...\n";
$critical_url = "http://localhost/api/inventory-v4.php?action=critical&threshold=5&active_only=1";
$critical_response = @file_get_contents($critical_url);

if ($critical_response) {
    $critical_data = json_decode($critical_response, true);
    if (isset($critical_data['data']['stats'])) {
        echo "✅ Критические остатки активных товаров получены:\n";
        $stats = $critical_data['data']['stats'];
        echo "   - Критических позиций: " . ($stats['total_critical_items'] ?? 'N/A') . "\n";
        echo "   - Уникальных товаров: " . ($stats['unique_products'] ?? 'N/A') . "\n";
        echo "   - Затронутых складов: " . ($stats['affected_warehouses'] ?? 'N/A') . "\n";
    } else {
        echo "❌ Неверный формат ответа критических остатков\n";
    }
} else {
    echo "❌ Не удалось получить критические остатки активных товаров\n";
}

echo "\n";

// Тест 3: Проверка маркетинговой аналитики активных товаров
echo "3️⃣ Тестирование маркетинговой аналитики активных товаров...\n";
$marketing_url = "http://localhost/api/inventory-v4.php?action=marketing&active_only=1";
$marketing_response = @file_get_contents($marketing_url);

if ($marketing_response) {
    $marketing_data = json_decode($marketing_response, true);
    if (isset($marketing_data['data']['stats'])) {
        echo "✅ Маркетинговая аналитика активных товаров получена:\n";
        $stats = $marketing_data['data']['stats'];
        echo "   - Общий объем запасов: " . ($stats['total_stock'] ?? 'N/A') . "\n";
        echo "   - Средний остаток: " . ($stats['avg_stock'] ?? 'N/A') . "\n";
        echo "   - Эффективность запасов: " . ($stats['stock_efficiency'] ?? 'N/A') . "%\n";
    } else {
        echo "❌ Неверный формат ответа маркетинговой аналитики\n";
    }
} else {
    echo "❌ Не удалось получить маркетинговую аналитику активных товаров\n";
}

echo "\n";

// Тест 4: Сравнение с общими данными
echo "4️⃣ Сравнение активных товаров с общими данными...\n";
$all_stats_url = "http://localhost/api/inventory-v4.php?action=stats&active_only=0";
$all_stats_response = @file_get_contents($all_stats_url);

if ($all_stats_response && $stats_response) {
    $all_data = json_decode($all_stats_response, true);
    $active_data = json_decode($stats_response, true);
    
    if (isset($all_data['data']['summary']) && isset($active_data['data']['summary'])) {
        echo "✅ Сравнение данных:\n";
        
        $all_critical = $all_data['data']['summary']['critical_stock'] ?? 0;
        $active_critical = $active_data['data']['summary']['critical_stock'] ?? 0;
        
        $all_total = $all_data['data']['summary']['total_products'] ?? 0;
        $active_total = $active_data['data']['summary']['total_products'] ?? 0;
        
        echo "   - Всего товаров: $all_total (все) vs $active_total (активные)\n";
        echo "   - Критических остатков: $all_critical (все) vs $active_critical (активные)\n";
        
        if ($all_total > 0 && $active_total > 0) {
            $reduction = round((($all_total - $active_total) / $all_total) * 100, 1);
            echo "   - Сокращение обрабатываемых товаров: {$reduction}%\n";
        }
    }
} else {
    echo "❌ Не удалось получить данные для сравнения\n";
}

echo "\n";
echo "🎯 Тестирование завершено!\n";
echo "Теперь можно открыть dashboard_inventory_v4.php в браузере для проверки интерфейса.\n";
?>