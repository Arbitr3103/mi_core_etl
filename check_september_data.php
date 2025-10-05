<?php
/**
 * Быстрая проверка данных за сентябрь 2025
 */

echo "🔍 Проверка данных Ozon за сентябрь 2025\n";
echo "=======================================\n\n";

try {
    // Подключение к базе данных
    $host = '127.0.0.1';
    $dbname = 'mi_core_db';
    $username = 'mi_core_user';
    $password = 'secure_password_123';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✅ Подключение к БД установлено\n\n";
    
    // Проверяем данные за сентябрь 2025
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM ozon_funnel_data 
        WHERE date_from >= '2025-09-01' AND date_to <= '2025-09-28'
    ");
    $funnelCount = $stmt->fetchColumn();
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as count 
        FROM ozon_demographics 
        WHERE date_from >= '2025-09-01' AND date_to <= '2025-09-28'
    ");
    $demographicsCount = $stmt->fetchColumn();
    
    echo "📊 Статистика данных за сентябрь 2025:\n";
    echo "=====================================\n";
    echo "📈 Записей воронки: $funnelCount\n";
    echo "👥 Демографических записей: $demographicsCount\n\n";
    
    if ($funnelCount > 0) {
        echo "✅ Данные воронки найдены!\n";
        
        // Показываем примеры данных
        $stmt = $pdo->query("
            SELECT 
                date_from, date_to, product_id, views, orders, revenue, cached_at
            FROM ozon_funnel_data 
            WHERE date_from >= '2025-09-01' AND date_to <= '2025-09-28'
            ORDER BY cached_at DESC 
            LIMIT 3
        ");
        $samples = $stmt->fetchAll();
        
        echo "\n📋 Примеры записей:\n";
        foreach ($samples as $index => $record) {
            echo "  " . ($index + 1) . ". Product: " . ($record['product_id'] ?? 'null') . 
                 ", Period: {$record['date_from']} - {$record['date_to']}" .
                 ", Views: " . number_format($record['views']) .
                 ", Orders: " . number_format($record['orders']) .
                 ", Revenue: " . number_format($record['revenue'], 2) . " руб.\n";
        }
        
        // Агрегированная статистика
        $stmt = $pdo->query("
            SELECT 
                SUM(views) as total_views,
                SUM(orders) as total_orders,
                SUM(revenue) as total_revenue
            FROM ozon_funnel_data 
            WHERE date_from >= '2025-09-01' AND date_to <= '2025-09-28'
        ");
        $totals = $stmt->fetch();
        
        echo "\n💰 Итоговые показатели за сентябрь:\n";
        echo "  👀 Всего просмотров: " . number_format($totals['total_views']) . "\n";
        echo "  📦 Всего заказов: " . number_format($totals['total_orders']) . "\n";
        echo "  💵 Общая выручка: " . number_format($totals['total_revenue'], 2) . " руб.\n";
        
        if ($totals['total_views'] > 0) {
            $conversion = round(($totals['total_orders'] / $totals['total_views']) * 100, 2);
            echo "  📈 Общая конверсия: $conversion%\n";
        }
        
    } else {
        echo "⚠️ Данные воронки за сентябрь 2025 не найдены\n";
        echo "Запустите: php load_ozon_september_2025.php\n";
    }
    
    if ($demographicsCount > 0) {
        echo "\n✅ Демографические данные найдены!\n";
    } else {
        echo "\n⚠️ Демографические данные за сентябрь 2025 не найдены\n";
    }
    
    // Проверяем последнее обновление
    $stmt = $pdo->query("
        SELECT MAX(cached_at) as last_update 
        FROM ozon_funnel_data 
        WHERE date_from >= '2025-09-01'
    ");
    $lastUpdate = $stmt->fetchColumn();
    
    if ($lastUpdate) {
        echo "\n🕒 Последнее обновление данных: $lastUpdate\n";
    }
    
    echo "\n";
    
    // Проверяем API
    echo "🔗 Тест API для сентябрьских данных:\n";
    echo "===================================\n";
    
    $testUrl = "src/api/ozon-analytics.php?action=funnel-data&date_from=2025-09-01&date_to=2025-09-28";
    echo "URL для тестирования: $testUrl\n";
    
    // Если есть данные, рекомендуем проверить дашборд
    if ($funnelCount > 0) {
        echo "\n🎯 РЕКОМЕНДАЦИИ:\n";
        echo "===============\n";
        echo "✅ Данные загружены - можно проверять дашборд\n";
        echo "🌐 Откройте дашборд и установите период: 01.09.2025 - 28.09.2025\n";
        echo "📊 Дашборд должен отображать реальные данные Ozon\n";
    } else {
        echo "\n🚀 СЛЕДУЮЩИЕ ШАГИ:\n";
        echo "=================\n";
        echo "1. Запустите: php load_ozon_september_2025.php\n";
        echo "2. Дождитесь завершения загрузки данных\n";
        echo "3. Проверьте дашборд\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
}
?>