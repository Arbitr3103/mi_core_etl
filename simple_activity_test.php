<?php
/**
 * Простой тест фильтра активности товаров
 */

echo "🧪 Простой тест фильтра активности товаров\n";
echo "=" . str_repeat("=", 50) . "\n\n";

// Загружаем конфигурацию базы данных
$_ENV['APP_ENV'] = 'production';
require_once __DIR__ . '/config/production_db_override.php';
require_once __DIR__ . '/config/production.php';

try {
    // Подключаемся к базе данных
    $pdo = getProductionPgConnection();
    echo "✅ Подключение к базе данных успешно\n\n";
    
    // Тестируем логику фильтрации
    function testActivityFilter($pdo, $filter) {
        echo "📊 Тестируем фильтр: '$filter'\n";
        
        // Определяем условие фильтрации как в API
        switch ($filter) {
            case 'active':
                $condition = " AND i.current_stock > 0 ";
                $description = "товары с остатками > 0";
                break;
            case 'inactive':
                $condition = " AND i.current_stock = 0 ";
                $description = "товары с остатками = 0";
                break;
            case 'all':
                $condition = " ";
                $description = "все товары";
                break;
            default:
                $condition = " AND i.current_stock > 0 ";
                $description = "товары с остатками > 0 (по умолчанию)";
        }
        
        echo "   Описание: $description\n";
        echo "   SQL условие: '$condition'\n";
        
        // Выполняем запрос для получения статистики
        $sql = "
            SELECT 
                COUNT(DISTINCT i.sku) as total_products,
                COUNT(CASE WHEN i.current_stock <= 5 THEN 1 END) as critical_count,
                COUNT(CASE WHEN i.current_stock > 5 AND i.current_stock <= 20 THEN 1 END) as low_count,
                COUNT(CASE WHEN i.current_stock > 100 THEN 1 END) as overstock_count,
                SUM(i.current_stock) as total_stock
            FROM inventory_data i
            WHERE i.current_stock IS NOT NULL $condition
        ";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo "   📈 Результаты:\n";
            echo "      - Всего товаров: " . $result['total_products'] . "\n";
            echo "      - Критические (≤5): " . $result['critical_count'] . "\n";
            echo "      - Низкие остатки (6-20): " . $result['low_count'] . "\n";
            echo "      - Избыток (>100): " . $result['overstock_count'] . "\n";
            echo "      - Общий остаток: " . $result['total_stock'] . "\n";
            
            return $result;
            
        } catch (PDOException $e) {
            echo "   ❌ Ошибка запроса: " . $e->getMessage() . "\n";
            return null;
        }
        
        echo "\n";
    }
    
    // Получаем общую статистику активности
    echo "🎯 Общая статистика активности товаров:\n";
    $activity_sql = "
        SELECT 
            COUNT(CASE WHEN i.current_stock > 0 THEN 1 END) as active_count,
            COUNT(CASE WHEN i.current_stock = 0 THEN 1 END) as inactive_count,
            COUNT(*) as total_count
        FROM inventory_data i
        WHERE i.current_stock IS NOT NULL
    ";
    
    $stmt = $pdo->prepare($activity_sql);
    $stmt->execute();
    $activity_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "   - Активные товары (остатки > 0): " . $activity_stats['active_count'] . "\n";
    echo "   - Неактивные товары (остатки = 0): " . $activity_stats['inactive_count'] . "\n";
    echo "   - Всего товаров: " . $activity_stats['total_count'] . "\n";
    
    if ($activity_stats['total_count'] > 0) {
        $active_percentage = round(($activity_stats['active_count'] / $activity_stats['total_count']) * 100, 2);
        $inactive_percentage = round(($activity_stats['inactive_count'] / $activity_stats['total_count']) * 100, 2);
        echo "   - % активных: $active_percentage%\n";
        echo "   - % неактивных: $inactive_percentage%\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n\n";
    
    // Тестируем каждый фильтр
    $active_result = testActivityFilter($pdo, 'active');
    echo str_repeat("-", 30) . "\n";
    
    $inactive_result = testActivityFilter($pdo, 'inactive');
    echo str_repeat("-", 30) . "\n";
    
    $all_result = testActivityFilter($pdo, 'all');
    echo str_repeat("-", 30) . "\n";
    
    // Проверяем логику
    echo "🔍 Проверка логики фильтрации:\n";
    
    if ($active_result && $inactive_result && $all_result) {
        $sum_active_inactive = $active_result['total_products'] + $inactive_result['total_products'];
        $all_products = $all_result['total_products'];
        
        if ($sum_active_inactive == $all_products) {
            echo "   ✅ Логика корректна: активные + неактивные = все товары ($sum_active_inactive = $all_products)\n";
        } else {
            echo "   ❌ Ошибка логики: активные + неактивные ≠ все товары ($sum_active_inactive ≠ $all_products)\n";
        }
        
        // Проверяем, что активные товары действительно имеют остатки > 0
        $check_active_sql = "
            SELECT COUNT(*) as count 
            FROM inventory_data i 
            WHERE i.current_stock IS NOT NULL AND i.current_stock > 0
        ";
        $stmt = $pdo->prepare($check_active_sql);
        $stmt->execute();
        $check_active = $stmt->fetchColumn();
        
        if ($check_active == $active_result['total_products']) {
            echo "   ✅ Фильтр 'active' работает корректно\n";
        } else {
            echo "   ❌ Фильтр 'active' работает некорректно ($check_active ≠ {$active_result['total_products']})\n";
        }
        
        // Проверяем, что неактивные товары действительно имеют остатки = 0
        $check_inactive_sql = "
            SELECT COUNT(*) as count 
            FROM inventory_data i 
            WHERE i.current_stock IS NOT NULL AND i.current_stock = 0
        ";
        $stmt = $pdo->prepare($check_inactive_sql);
        $stmt->execute();
        $check_inactive = $stmt->fetchColumn();
        
        if ($check_inactive == $inactive_result['total_products']) {
            echo "   ✅ Фильтр 'inactive' работает корректно\n";
        } else {
            echo "   ❌ Фильтр 'inactive' работает некорректно ($check_inactive ≠ {$inactive_result['total_products']})\n";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}

echo "\n🏁 Тестирование завершено!\n";
?>