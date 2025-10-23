<?php
/**
 * Скрипт загрузки данных Ozon Analytics
 * 
 * Этот скрипт можно интегрировать в существующий cron-скрипт
 * или запускать отдельно для загрузки данных из Ozon API
 */

echo "🚀 Загрузка данных Ozon Analytics\n";
echo "================================\n\n";

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
    echo "✅ Ozon API инициализирован\n";
    
    // Определяем период для загрузки данных
    $dateTo = date('Y-m-d'); // Сегодня
    $dateFrom = date('Y-m-d', strtotime('-7 days')); // Неделя назад
    
    echo "📅 Период загрузки: $dateFrom - $dateTo\n\n";
    
    // Загружаем данные воронки продаж
    echo "📊 Загрузка данных воронки продаж...\n";
    try {
        $funnelData = $ozonAPI->getFunnelData($dateFrom, $dateTo, ['use_cache' => false]);
        
        if (!empty($funnelData)) {
            $recordCount = count($funnelData);
            echo "✅ Загружено записей воронки: $recordCount\n";
            
            // Показываем статистику
            $totalRevenue = 0;
            $totalOrders = 0;
            $totalViews = 0;
            
            foreach ($funnelData as $record) {
                $totalRevenue += $record['revenue'] ?? 0;
                $totalOrders += $record['orders'] ?? 0;
                $totalViews += $record['views'] ?? 0;
            }
            
            echo "  💰 Общая выручка: " . number_format($totalRevenue, 2) . " руб.\n";
            echo "  📦 Общее количество заказов: " . number_format($totalOrders) . "\n";
            echo "  👀 Общее количество просмотров: " . number_format($totalViews) . "\n";
            
            if ($totalViews > 0) {
                $overallConversion = round(($totalOrders / $totalViews) * 100, 2);
                echo "  📈 Общая конверсия: $overallConversion%\n";
            }
        } else {
            echo "⚠️ Данные воронки не получены\n";
        }
    } catch (Exception $e) {
        echo "❌ Ошибка загрузки данных воронки: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // Загружаем демографические данные
    echo "👥 Загрузка демографических данных...\n";
    try {
        $demographicsData = $ozonAPI->getDemographics($dateFrom, $dateTo, ['use_cache' => false]);
        
        if (!empty($demographicsData)) {
            $recordCount = count($demographicsData);
            echo "✅ Загружено демографических записей: $recordCount\n";
        } else {
            echo "⚠️ Демографические данные не получены\n";
        }
    } catch (Exception $e) {
        echo "❌ Ошибка загрузки демографических данных: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // Очищаем старый кэш
    echo "🧹 Очистка устаревшего кэша...\n";
    try {
        $cache = new OzonDataCache($pdo);
        $deletedCount = $cache->cleanupExpiredCache(604800); // 7 дней
        echo "✅ Удалено устаревших записей: $deletedCount\n";
    } catch (Exception $e) {
        echo "❌ Ошибка очистки кэша: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // Показываем итоговую статистику
    echo "📈 Итоговая статистика базы данных:\n";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_funnel_data");
        $funnelCount = $stmt->fetchColumn();
        echo "  📊 Записей воронки в БД: $funnelCount\n";
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_demographics");
        $demographicsCount = $stmt->fetchColumn();
        echo "  👥 Демографических записей в БД: $demographicsCount\n";
        
        // Последнее обновление
        $stmt = $pdo->query("SELECT MAX(cached_at) as last_update FROM ozon_funnel_data");
        $lastUpdate = $stmt->fetchColumn();
        if ($lastUpdate) {
            echo "  🕒 Последнее обновление: $lastUpdate\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Ошибка получения статистики: " . $e->getMessage() . "\n";
    }
    
    echo "\n🎉 Загрузка данных завершена успешно!\n";
    
} catch (Exception $e) {
    echo "❌ Критическая ошибка: " . $e->getMessage() . "\n";
    echo "Трассировка: " . $e->getTraceAsString() . "\n";
    exit(1);
}

// Функция для интеграции в существующий скрипт
function loadOzonAnalyticsData($pdo = null) {
    try {
        // Если PDO не передан, создаем подключение
        if (!$pdo) {
            $host = '127.0.0.1';
            $dbname = 'mi_core_db';
            $username = 'mi_core_user';
            $password = 'secure_password_123';
            
            $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        }
        
        // Создаем экземпляр Ozon API
        $clientId = '26100';
        $apiKey = '7e074977-e0db-4ace-ba9e-82903e088b4b';
        
        $ozonAPI = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);
        
        // Загружаем данные за последнюю неделю
        $dateTo = date('Y-m-d');
        $dateFrom = date('Y-m-d', strtotime('-7 days'));
        
        // Загружаем данные воронки
        $funnelData = $ozonAPI->getFunnelData($dateFrom, $dateTo, ['use_cache' => false]);
        
        // Загружаем демографические данные
        $demographicsData = $ozonAPI->getDemographics($dateFrom, $dateTo, ['use_cache' => false]);
        
        // Очищаем старый кэш
        $cache = new OzonDataCache($pdo);
        $cache->cleanupExpiredCache(604800); // 7 дней
        
        return [
            'success' => true,
            'funnel_records' => count($funnelData ?? []),
            'demographics_records' => count($demographicsData ?? []),
            'period' => "$dateFrom - $dateTo"
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>