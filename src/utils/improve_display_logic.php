<?php
/**
 * Улучшение логики отображения товаров
 * Добавляем более понятные названия для товаров без названий
 */

// Подключение к БД
function loadEnvConfig() {
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $value = trim($value, '"\'');
                $_ENV[trim($key)] = $value;
            }
        }
    }
}

loadEnvConfig();

try {
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=mi_core;charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✅ Подключение к БД успешно\n\n";
    
    // Создаем улучшенные названия для товаров на основе их характеристик
    echo "🔧 Создаем улучшенные названия товаров...\n";
    
    // Получаем товары без названий и анализируем их
    $products_stmt = $pdo->query("
        SELECT 
            i.sku,
            i.current_stock,
            i.reserved_stock,
            i.source,
            i.stock_type,
            pm.category,
            pm.brand
        FROM inventory_data i
        LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
        LEFT JOIN product_names pn ON i.sku = pn.sku
        WHERE i.current_stock > 0 
        AND i.sku REGEXP '^[0-9]+$'
        AND (pn.product_name IS NULL OR pm.product_name LIKE 'Товар артикул %')
        ORDER BY i.current_stock DESC
        LIMIT 50
    ");
    
    $products = $products_stmt->fetchAll();
    echo "Найдено " . count($products) . " товаров для улучшения\n\n";
    
    $updated_count = 0;
    
    foreach ($products as $product) {
        $sku = $product['sku'];
        $category = $product['category'] ?? '';
        $brand = $product['brand'] ?? '';
        $stock = $product['current_stock'];
        $reserved = $product['reserved_stock'];
        $source = $product['source'];
        
        // Создаем улучшенное название на основе доступной информации
        $improved_name = '';
        
        if (!empty($brand) && $brand !== 'Неизвестный бренд') {
            $improved_name = $brand . ' ';
        }
        
        if (!empty($category) && $category !== 'Без категории') {
            $improved_name .= $category . ' ';
        }
        
        // Добавляем информацию о популярности
        if ($reserved > 0) {
            $improved_name .= '(Популярный товар) ';
        } elseif ($stock > 100) {
            $improved_name .= '(Товар в наличии) ';
        }
        
        $improved_name .= "SKU: $sku";
        
        // Если название получилось слишком простым, добавляем больше контекста
        if (empty($brand) && empty($category)) {
            if ($reserved > 0) {
                $improved_name = "Популярный товар (есть заказы) - SKU: $sku";
            } elseif ($stock > 100) {
                $improved_name = "Товар с высокими остатками - SKU: $sku";
            } elseif ($stock < 10) {
                $improved_name = "Товар с низкими остатками - SKU: $sku";
            } else {
                $improved_name = "Товар в ассортименте - SKU: $sku";
            }
        }
        
        // Сохраняем улучшенное название
        try {
            $insert_stmt = $pdo->prepare("
                INSERT INTO product_names (sku, product_name, product_id, source, created_at) 
                VALUES (?, ?, 0, 'improved_logic', NOW())
                ON DUPLICATE KEY UPDATE 
                product_name = VALUES(product_name),
                updated_at = NOW()
            ");
            
            $insert_stmt->execute([$sku, $improved_name]);
            
            echo "✅ SKU $sku: " . substr($improved_name, 0, 60) . (strlen($improved_name) > 60 ? '...' : '') . "\n";
            $updated_count++;
            
        } catch (Exception $e) {
            echo "❌ Ошибка для SKU $sku: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 Улучшение завершено!\n";
    echo "✅ Успешно обновлено: $updated_count товаров\n";
    
    // Проверяем результат
    echo "\n📊 Проверяем результат:\n";
    $check_stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_with_names,
            COUNT(CASE WHEN pn.source = 'improved_logic' THEN 1 END) as improved_names
        FROM inventory_data i
        LEFT JOIN product_names pn ON i.sku = pn.sku
        WHERE i.current_stock > 0 AND pn.product_name IS NOT NULL
    ");
    $check_result = $check_stmt->fetch();
    
    echo "Всего товаров с названиями: " . $check_result['total_with_names'] . "\n";
    echo "Из них улучшенных названий: " . $check_result['improved_names'] . "\n";
    
} catch (PDOException $e) {
    echo "❌ Ошибка БД: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Общая ошибка: " . $e->getMessage() . "\n";
}
?>