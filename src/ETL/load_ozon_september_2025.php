<?php
/**
 * Скрипт загрузки реальных данных Ozon за сентябрь 2025
 * Период: 01.09.2025 - 28.09.2025
 */

echo "📊 Загрузка данных Ozon за сентябрь 2025\n";
echo "=======================================\n";
echo "Период: 01.09.2025 - 28.09.2025\n\n";

// Подключаем необходимые классы
require_once 'src/classes/OzonDataCache.php';
require_once 'src/classes/OzonAnalyticsAPI.php';

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
    
    echo "✅ Подключение к БД установлено\n";
    
    // Создаем экземпляр Ozon API
    $clientId = '26100';
    $apiKey = '7e074977-e0db-4ace-ba9e-82903e088b4b';
    
    $ozonAPI = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);
    echo "✅ Ozon API инициализирован\n\n";
    
    // Определяем период загрузки - сентябрь 2025
    $dateFrom = '2025-09-01';
    $dateTo = '2025-09-28';
    
    echo "📅 Загружаем данные за период: $dateFrom - $dateTo\n\n";
    
    // Очищаем существующие данные за этот период
    echo "🧹 Очистка существующих данных за сентябрь 2025...\n";
    
    $stmt = $pdo->prepare("DELETE FROM ozon_funnel_data WHERE date_from >= ? AND date_to <= ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $deletedFunnel = $stmt->rowCount();
    
    $stmt = $pdo->prepare("DELETE FROM ozon_demographics WHERE date_from >= ? AND date_to <= ?");
    $stmt->execute([$dateFrom, $dateTo]);
    $deletedDemo = $stmt->rowCount();
    
    echo "✅ Удалено записей воронки: $deletedFunnel\n";
    echo "✅ Удалено демографических записей: $deletedDemo\n\n";
    
    // Загружаем данные по неделям для лучшей детализации
    $weekPeriods = [
        ['2025-09-01', '2025-09-07'],  // 1-я неделя
        ['2025-09-08', '2025-09-14'],  // 2-я неделя
        ['2025-09-15', '2025-09-21'],  // 3-я неделя
        ['2025-09-22', '2025-09-28']   // 4-я неделя
    ];
    
    $totalFunnelRecords = 0;
    $totalDemographicsRecords = 0;
    $totalRevenue = 0;
    $totalOrders = 0;
    $totalViews = 0;
    
    foreach ($weekPeriods as $index => $period) {
        $weekFrom = $period[0];
        $weekTo = $period[1];
        $weekNum = $index + 1;
        
        echo "📊 Загрузка данных за {$weekNum}-ю неделю ($weekFrom - $weekTo)...\n";
        
        try {
            // Загружаем данные воронки продаж
            echo "  🔄 Получение данных воронки...\n";
            $funnelData = $ozonAPI->getFunnelData($weekFrom, $weekTo, ['use_cache' => false]);
            
            if (!empty($funnelData)) {
                $weekFunnelCount = count($funnelData);
                $totalFunnelRecords += $weekFunnelCount;
                
                echo "  ✅ Загружено записей воронки: $weekFunnelCount\n";
                
                // Подсчитываем статистику за неделю
                $weekRevenue = 0;
                $weekOrders = 0;
                $weekViews = 0;
                
                foreach ($funnelData as $record) {
                    $weekRevenue += $record['revenue'] ?? 0;
                    $weekOrders += $record['orders'] ?? 0;
                    $weekViews += $record['views'] ?? 0;
                }
                
                $totalRevenue += $weekRevenue;
                $totalOrders += $weekOrders;
                $totalViews += $weekViews;
                
                echo "    💰 Выручка за неделю: " . number_format($weekRevenue, 2) . " руб.\n";
                echo "    📦 Заказов за неделю: " . number_format($weekOrders) . "\n";
                echo "    👀 Просмотров за неделю: " . number_format($weekViews) . "\n";
                
                if ($weekViews > 0) {
                    $weekConversion = round(($weekOrders / $weekViews) * 100, 2);
                    echo "    📈 Конверсия за неделю: $weekConversion%\n";
                }
            } else {
                echo "  ⚠️ Данные воронки за {$weekNum}-ю неделю не получены\n";
            }
            
            // Загружаем демографические данные
            echo "  🔄 Получение демографических данных...\n";
            $demographicsData = $ozonAPI->getDemographics($weekFrom, $weekTo, ['use_cache' => false]);
            
            if (!empty($demographicsData)) {
                $weekDemoCount = count($demographicsData);
                $totalDemographicsRecords += $weekDemoCount;
                echo "  ✅ Загружено демографических записей: $weekDemoCount\n";
            } else {
                echo "  ⚠️ Демографические данные за {$weekNum}-ю неделю не получены\n";
            }
            
            echo "\n";
            
            // Небольшая пауза между запросами для соблюдения rate limit
            sleep(2);
            
        } catch (Exception $e) {
            echo "  ❌ Ошибка загрузки данных за {$weekNum}-ю неделю: " . $e->getMessage() . "\n\n";
            continue;
        }
    }
    
    // Дополнительно загружаем данные за весь месяц для общей статистики
    echo "📊 Загрузка сводных данных за весь сентябрь...\n";
    try {
        $monthlyFunnelData = $ozonAPI->getFunnelData($dateFrom, $dateTo, ['use_cache' => false]);
        if (!empty($monthlyFunnelData)) {
            echo "✅ Загружены сводные данные за месяц\n";
        }
        
        $monthlyDemographicsData = $ozonAPI->getDemographics($dateFrom, $dateTo, ['use_cache' => false]);
        if (!empty($monthlyDemographicsData)) {
            echo "✅ Загружены сводные демографические данные за месяц\n";
        }
    } catch (Exception $e) {
        echo "⚠️ Ошибка загрузки сводных данных: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // Очищаем старый кэш
    echo "🧹 Очистка устаревшего кэша...\n";
    try {
        $cache = new OzonDataCache($pdo);
        $deletedCount = $cache->cleanupExpiredCache(2592000); // 30 дней
        echo "✅ Удалено устаревших записей: $deletedCount\n";
    } catch (Exception $e) {
        echo "❌ Ошибка очистки кэша: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // Показываем итоговую статистику
    echo "📈 ИТОГОВАЯ СТАТИСТИКА ЗА СЕНТЯБРЬ 2025:\n";
    echo "=======================================\n";
    
    // Статистика из базы данных
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_funnel_data WHERE date_from >= '2025-09-01' AND date_to <= '2025-09-28'");
    $dbFunnelCount = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_demographics WHERE date_from >= '2025-09-01' AND date_to <= '2025-09-28'");
    $dbDemographicsCount = $stmt->fetchColumn();
    
    echo "📊 Записей воронки в БД: $dbFunnelCount\n";
    echo "👥 Демографических записей в БД: $dbDemographicsCount\n";
    
    // Агрегированная статистика
    $stmt = $pdo->query("
        SELECT 
            SUM(views) as total_views,
            SUM(orders) as total_orders,
            SUM(revenue) as total_revenue,
            AVG(conversion_overall) as avg_conversion
        FROM ozon_funnel_data 
        WHERE date_from >= '2025-09-01' AND date_to <= '2025-09-28'
    ");
    $stats = $stmt->fetch();
    
    if ($stats && $stats['total_views'] > 0) {
        echo "\n💰 ФИНАНСОВЫЕ ПОКАЗАТЕЛИ:\n";
        echo "  💵 Общая выручка: " . number_format($stats['total_revenue'], 2) . " руб.\n";
        echo "  📦 Общее количество заказов: " . number_format($stats['total_orders']) . "\n";
        echo "  👀 Общее количество просмотров: " . number_format($stats['total_views']) . "\n";
        echo "  📈 Средняя конверсия: " . round($stats['avg_conversion'], 2) . "%\n";
        
        // Дополнительные метрики
        $avgOrderValue = $stats['total_orders'] > 0 ? $stats['total_revenue'] / $stats['total_orders'] : 0;
        echo "  💳 Средний чек: " . number_format($avgOrderValue, 2) . " руб.\n";
        
        $revenuePerView = $stats['total_views'] > 0 ? $stats['total_revenue'] / $stats['total_views'] : 0;
        echo "  👁️ Выручка с просмотра: " . number_format($revenuePerView, 2) . " руб.\n";
    }
    
    // Топ товары по выручке
    echo "\n🏆 ТОП-5 ТОВАРОВ ПО ВЫРУЧКЕ:\n";
    $stmt = $pdo->query("
        SELECT 
            product_id,
            SUM(revenue) as total_revenue,
            SUM(orders) as total_orders,
            SUM(views) as total_views
        FROM ozon_funnel_data 
        WHERE date_from >= '2025-09-01' AND date_to <= '2025-09-28'
          AND product_id IS NOT NULL
        GROUP BY product_id
        ORDER BY total_revenue DESC
        LIMIT 5
    ");
    
    $topProducts = $stmt->fetchAll();
    foreach ($topProducts as $index => $product) {
        $rank = $index + 1;
        echo "  $rank. Product ID: {$product['product_id']}\n";
        echo "     💰 Выручка: " . number_format($product['total_revenue'], 2) . " руб.\n";
        echo "     📦 Заказы: " . number_format($product['total_orders']) . "\n";
        echo "     👀 Просмотры: " . number_format($product['total_views']) . "\n";
        echo "\n";
    }
    
    // Последнее обновление
    $stmt = $pdo->query("SELECT MAX(cached_at) as last_update FROM ozon_funnel_data WHERE date_from >= '2025-09-01'");
    $lastUpdate = $stmt->fetchColumn();
    if ($lastUpdate) {
        echo "🕒 Последнее обновление: $lastUpdate\n";
    }
    
    echo "\n🎉 Загрузка данных за сентябрь 2025 завершена успешно!\n";
    echo "Теперь дашборд должен отображать реальные данные Ozon.\n\n";
    
    echo "🔗 Для проверки API откройте:\n";
    echo "http://your-domain/src/api/ozon-analytics.php?action=funnel-data&date_from=2025-09-01&date_to=2025-09-28\n";
    
} catch (Exception $e) {
    echo "❌ Критическая ошибка: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
    exit(1);
}
?>