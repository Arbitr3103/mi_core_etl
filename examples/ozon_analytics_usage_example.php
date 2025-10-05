<?php
/**
 * Пример использования класса OzonAnalyticsAPI
 * 
 * Демонстрирует основные сценарии использования API для получения
 * аналитических данных Ozon
 */

require_once '../src/classes/OzonAnalyticsAPI.php';

// Загружаем конфигурацию из переменных окружения
$clientId = $_ENV['OZON_CLIENT_ID'] ?? 'your_client_id_here';
$apiKey = $_ENV['OZON_API_KEY'] ?? 'your_api_key_here';

// Настройки подключения к БД (опционально)
$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'mi_core_db';
$dbUser = $_ENV['DB_USER'] ?? 'your_db_user';
$dbPassword = $_ENV['DB_PASSWORD'] ?? 'your_db_password';

echo "📊 ПРИМЕР ИСПОЛЬЗОВАНИЯ OzonAnalyticsAPI\n";
echo str_repeat("=", 50) . "\n\n";

try {
    // Подключение к базе данных (опционально)
    $pdo = null;
    try {
        $pdo = new PDO(
            "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
            $dbUser,
            $dbPassword,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        echo "✅ Подключение к базе данных установлено\n";
    } catch (PDOException $e) {
        echo "⚠️ Работаем без подключения к БД: " . $e->getMessage() . "\n";
    }
    
    // Создание экземпляра API
    $ozonAPI = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);
    echo "✅ Экземпляр OzonAnalyticsAPI создан\n\n";
    
    // 1. Тестирование подключения
    echo "1️⃣ Тестирование подключения к API:\n";
    $connectionTest = $ozonAPI->testConnection();
    
    if ($connectionTest['success']) {
        echo "✅ " . $connectionTest['message'] . "\n";
        echo "   Токен получен: " . ($connectionTest['token_received'] ? 'Да' : 'Нет') . "\n";
        echo "   Истекает: " . $connectionTest['token_expiry'] . "\n";
    } else {
        echo "❌ " . $connectionTest['message'] . "\n";
        echo "   Код ошибки: " . $connectionTest['error_code'] . "\n";
        echo "   Тип ошибки: " . $connectionTest['error_type'] . "\n";
        
        // Если аутентификация не прошла, показываем пример без реальных запросов
        echo "\n⚠️ Продолжаем с демонстрационными данными...\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n";
    
    // 2. Получение данных воронки продаж
    echo "2️⃣ Получение данных воронки продаж:\n";
    
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
    $dateTo = date('Y-m-d');
    
    echo "Период: $dateFrom - $dateTo\n";
    
    try {
        // Получение данных с кэшированием
        $funnelData = $ozonAPI->getFunnelData($dateFrom, $dateTo, [
            'product_id' => '123456789',  // Пример ID товара
            'campaign_id' => 'camp_001',  // Пример ID кампании
            'use_cache' => true           // Использовать кэш
        ]);
        
        echo "✅ Данные воронки получены (" . count($funnelData) . " записей)\n";
        
        if (!empty($funnelData)) {
            $sample = $funnelData[0];
            echo "   Пример данных:\n";
            echo "   - Просмотры: " . number_format($sample['views']) . "\n";
            echo "   - Добавления в корзину: " . number_format($sample['cart_additions']) . "\n";
            echo "   - Заказы: " . number_format($sample['orders']) . "\n";
            echo "   - Конверсия просмотры->корзина: " . $sample['conversion_view_to_cart'] . "%\n";
            echo "   - Конверсия корзина->заказ: " . $sample['conversion_cart_to_order'] . "%\n";
            echo "   - Конверсия общая: " . $sample['conversion_overall'] . "%\n";
            echo "   - Кэшировано: " . $sample['cached_at'] . "\n";
        }
        
        // Получение агрегированных данных
        echo "\n   📊 Агрегированная статистика:\n";
        $aggregated = $ozonAPI->getAggregatedFunnelData($dateFrom, $dateTo, [
            'product_id' => '123456789'
        ]);
        
        echo "   - Всего просмотров: " . number_format($aggregated['total_views']) . "\n";
        echo "   - Всего добавлений в корзину: " . number_format($aggregated['total_cart_additions']) . "\n";
        echo "   - Всего заказов: " . number_format($aggregated['total_orders']) . "\n";
        echo "   - Средняя конверсия: " . $aggregated['avg_conversion_overall'] . "%\n";
        echo "   - Рассчитанная конверсия: " . $aggregated['calculated_conversion_overall'] . "%\n";
        
    } catch (OzonAPIException $e) {
        echo "❌ Ошибка получения данных воронки: " . $e->getMessage() . "\n";
        echo "   Рекомендация: " . $e->getRecommendation() . "\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n";
    
    // 3. Получение демографических данных
    echo "3️⃣ Получение демографических данных:\n";
    
    try {
        $demographicsData = $ozonAPI->getDemographics($dateFrom, $dateTo);
        
        echo "✅ Демографические данные получены (" . count($demographicsData) . " записей)\n";
        
        if (!empty($demographicsData)) {
            $sample = $demographicsData[0];
            echo "   Пример данных:\n";
            echo "   - Возрастная группа: " . ($sample['age_group'] ?? 'Не указано') . "\n";
            echo "   - Пол: " . ($sample['gender'] ?? 'Не указано') . "\n";
            echo "   - Регион: " . ($sample['region'] ?? 'Не указано') . "\n";
            echo "   - Количество заказов: " . $sample['orders_count'] . "\n";
            echo "   - Выручка: " . number_format($sample['revenue'], 2) . " руб.\n";
        }
        
    } catch (OzonAPIException $e) {
        echo "❌ Ошибка получения демографических данных: " . $e->getMessage() . "\n";
        echo "   Рекомендация: " . $e->getRecommendation() . "\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n";
    
    // 4. Получение данных рекламных кампаний
    echo "4️⃣ Получение данных рекламных кампаний:\n";
    
    try {
        $campaignData = $ozonAPI->getCampaignData($dateFrom, $dateTo);
        
        echo "✅ Данные кампаний получены (" . count($campaignData) . " записей)\n";
        
        if (!empty($campaignData)) {
            $sample = $campaignData[0];
            echo "   Пример данных:\n";
            echo "   - ID кампании: " . ($sample['campaign_id'] ?? 'Не указано') . "\n";
            echo "   - Название: " . ($sample['campaign_name'] ?? 'Не указано') . "\n";
            echo "   - Показы: " . number_format($sample['impressions']) . "\n";
            echo "   - Клики: " . number_format($sample['clicks']) . "\n";
            echo "   - Расходы: " . number_format($sample['spend'], 2) . " руб.\n";
            echo "   - CTR: " . $sample['ctr'] . "%\n";
            echo "   - ROAS: " . $sample['roas'] . "\n";
        }
        
    } catch (OzonAPIException $e) {
        echo "❌ Ошибка получения данных кампаний: " . $e->getMessage() . "\n";
        echo "   Рекомендация: " . $e->getRecommendation() . "\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n";
    
    // 5. Экспорт данных
    echo "5️⃣ Экспорт данных:\n";
    
    try {
        // Экспорт в JSON
        $jsonData = $ozonAPI->exportData('funnel', 'json', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
        
        echo "✅ Данные экспортированы в JSON (" . strlen($jsonData) . " символов)\n";
        
        // Экспорт в CSV
        $csvFile = $ozonAPI->exportData('funnel', 'csv', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
        
        echo "✅ Данные экспортированы в CSV: " . basename($csvFile) . "\n";
        
    } catch (Exception $e) {
        echo "❌ Ошибка экспорта данных: " . $e->getMessage() . "\n";
    }
    
    echo "\n" . str_repeat("-", 50) . "\n";
    
    // 6. Статистика API
    echo "6️⃣ Статистика использования API:\n";
    
    $stats = $ozonAPI->getApiStats();
    echo "Client ID: " . $stats['client_id'] . "\n";
    echo "Токен валиден: " . ($stats['token_valid'] ? 'Да' : 'Нет') . "\n";
    echo "Истечение токена: " . ($stats['token_expiry'] ?? 'Не установлено') . "\n";
    echo "Последний запрос: " . ($stats['last_request_time'] ?? 'Не выполнялся') . "\n";
    
} catch (Exception $e) {
    echo "❌ Критическая ошибка: " . $e->getMessage() . "\n";
    
    if ($e instanceof OzonAPIException) {
        echo "Тип ошибки: " . $e->getErrorType() . "\n";
        echo "Критическая: " . ($e->isCritical() ? 'Да' : 'Нет') . "\n";
        echo "Рекомендация: " . $e->getRecommendation() . "\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "🎯 ПРИМЕР ЗАВЕРШЕН\n";
echo "\nДля использования в продакшене:\n";
echo "1. Установите корректные OZON_CLIENT_ID и OZON_API_KEY\n";
echo "2. Настройте подключение к базе данных\n";
echo "3. Обработайте исключения в соответствии с логикой приложения\n";
echo "4. Настройте логирование для мониторинга API запросов\n";
echo str_repeat("=", 50) . "\n";

?>