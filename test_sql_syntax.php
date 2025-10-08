<?php
/**
 * Тест SQL синтаксиса для критических остатков
 */

// Подключение к БД (используем те же настройки что и в API)
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
    
    // Тест SQL запроса для анализа складов
    echo "🧪 Тестирование SQL запроса для анализа складов...\n";
    
    $warehouse_analysis_stmt = $pdo->prepare("
        SELECT 
            CASE 
                WHEN i.warehouse_name IS NOT NULL AND i.warehouse_name != '' THEN i.warehouse_name
                WHEN i.source = 'Ozon_Analytics' THEN CONCAT('Аналитика: ', COALESCE(i.stock_type, 'Неизвестный тип'))
                WHEN i.source = 'Ozon' THEN CONCAT('Основной: ', COALESCE(i.stock_type, 'Общий склад'))
                ELSE CONCAT('Неопределенный: ', COALESCE(i.source, 'Unknown'))
            END as warehouse_display_name,
            i.source,
            i.stock_type,
            COUNT(*) as critical_items_count,
            COUNT(DISTINCT i.sku) as unique_products,
            SUM(i.current_stock) as total_stock,
            SUM(i.reserved_stock) as total_reserved,
            AVG(i.current_stock) as avg_stock
        FROM inventory_data i
        WHERE i.current_stock > 0 AND i.current_stock < ?
        GROUP BY 
            i.warehouse_name,
            i.source,
            i.stock_type
        ORDER BY critical_items_count DESC
        LIMIT 5
    ");
    
    $warehouse_analysis_stmt->execute([10]);
    $results = $warehouse_analysis_stmt->fetchAll();
    
    echo "✅ SQL запрос выполнен успешно\n";
    echo "📊 Найдено складов: " . count($results) . "\n\n";
    
    if (!empty($results)) {
        echo "Примеры результатов:\n";
        foreach (array_slice($results, 0, 3) as $i => $row) {
            echo ($i + 1) . ". " . $row['warehouse_display_name'] . " (" . $row['source'] . ")\n";
            echo "   Критических позиций: " . $row['critical_items_count'] . "\n";
            echo "   Уникальных товаров: " . $row['unique_products'] . "\n\n";
        }
    }
    
} catch (PDOException $e) {
    echo "❌ Ошибка БД: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Общая ошибка: " . $e->getMessage() . "\n";
}
?>