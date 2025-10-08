<?php
/**
 * Скрипт для анализа и исправления логики отображения товаров
 */

$pdo = new PDO('mysql:host=localhost;dbname=mi_core;charset=utf8mb4', 'v_admin', 'Arbitr09102022!');

echo "=== АНАЛИЗ ДАННЫХ В INVENTORY_DATA ===\n";

// Анализируем типы SKU
$stmt = $pdo->query("
    SELECT 
        sku,
        current_stock,
        source,
        CASE 
            WHEN sku REGEXP '^[0-9]+$' THEN 'numeric'
            WHEN LENGTH(sku) > 30 THEN 'long_text'
            ELSE 'short_text'
        END as sku_type,
        pm.product_name as master_name
    FROM inventory_data i
    LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
    WHERE i.current_stock > 0
    ORDER BY sku_type, i.current_stock DESC
");

$types = ['numeric' => 0, 'long_text' => 0, 'short_text' => 0];
$examples = ['numeric' => [], 'long_text' => [], 'short_text' => []];

while ($row = $stmt->fetch()) {
    $type = $row['sku_type'];
    $types[$type]++;
    
    if (count($examples[$type]) < 3) {
        $examples[$type][] = $row;
    }
}

echo "Статистика типов SKU:\n";
foreach ($types as $type => $count) {
    echo "  $type: $count записей\n";
}

echo "\nПримеры по типам:\n";
foreach ($examples as $type => $items) {
    echo "\n$type:\n";
    foreach ($items as $item) {
        $display = $item['master_name'] ?: ($type == 'numeric' ? 'Товар артикул ' . $item['sku'] : $item['sku']);
        echo "  SKU: " . substr($item['sku'], 0, 25) . " | Остаток: " . $item['current_stock'] . " | Отображение: " . substr($display, 0, 40) . "\n";
    }
}

echo "\n=== РЕКОМЕНДАЦИИ ПО ИСПРАВЛЕНИЮ ===\n";
echo "1. Числовые SKU (" . $types['numeric'] . " шт) - использовать мастер таблицу или fallback\n";
echo "2. Длинные текстовые названия (" . $types['long_text'] . " шт) - обрезать до разумной длины\n";
echo "3. Короткие текстовые названия (" . $types['short_text'] . " шт) - использовать как есть\n";

echo "\n=== ПРЕДЛАГАЕМАЯ ЛОГИКА ОТОБРАЖЕНИЯ ===\n";
echo "CASE\n";
echo "  WHEN pm.product_name IS NOT NULL THEN pm.product_name  -- Из мастер таблицы\n";
echo "  WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)  -- Числовые SKU\n";
echo "  WHEN LENGTH(i.sku) > 50 THEN CONCAT(SUBSTRING(i.sku, 1, 47), '...')  -- Длинные названия\n";
echo "  ELSE i.sku  -- Короткие названия как есть\n";
echo "END as display_name\n";
?>