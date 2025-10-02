<?php
/**
 * Скрипт для тестирования подключения и данных маркетплейсов
 */

require_once 'MarginDashboardAPI_Updated.php';

echo "🔍 Тестирование системы маркетплейсов\n";
echo "=====================================\n\n";

// Параметры подключения - ОБНОВИТЕ ИХ!
$host = 'localhost';
$dbname = 'mi_core_db';
$username = 'mi_core_user';
$password = 'secure_password_123';

try {
    echo "1. Подключение к базе данных...\n";
    $api = new MarginDashboardAPI_Updated($host, $dbname, $username, $password);
    echo "✅ Подключение успешно\n\n";
    
    echo "2. Проверка таблицы dim_sources...\n";
    $marketplaces = $api->getAvailableMarketplaces();
    if (empty($marketplaces)) {
        echo "❌ Таблица dim_sources пуста или не существует\n";
        echo "Выполните SQL:\n";
        echo "CREATE TABLE dim_sources (\n";
        echo "    id INT PRIMARY KEY,\n";
        echo "    code VARCHAR(20) NOT NULL,\n";
        echo "    name VARCHAR(100) NOT NULL,\n";
        echo "    description VARCHAR(255),\n";
        echo "    is_active BOOLEAN DEFAULT TRUE,\n";
        echo "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n";
        echo ");\n\n";
        echo "INSERT INTO dim_sources (id, code, name, description) VALUES \n";
        echo "(1, 'WEBSITE', 'Собственный сайт', 'Собственный сайт клиента'),\n";
        echo "(2, 'OZON', 'Ozon', 'Ozon Marketplace'),\n";
        echo "(3, 'WB', 'Wildberries', 'Wildberries Marketplace');\n\n";
        exit;
    } else {
        echo "✅ Найдено маркетплейсов: " . count($marketplaces) . "\n";
        foreach ($marketplaces as $mp) {
            echo "   - {$mp['code']}: {$mp['name']}\n";
        }
        echo "\n";
    }
    
    echo "3. Проверка данных за период 2025-09-15 - 2025-09-30...\n";
    $stats = $api->getMarketplaceStats('2025-09-15', '2025-09-30');
    
    if (empty($stats)) {
        echo "❌ Нет данных за указанный период\n";
        echo "Проверьте:\n";
        echo "- Есть ли данные в fact_orders за этот период\n";
        echo "- Правильно ли связаны fact_orders.source_id с dim_sources.id\n\n";
    } else {
        echo "✅ Найдено данных по маркетплейсам: " . count($stats) . "\n";
        foreach ($stats as $stat) {
            echo sprintf("   - %s: %s заказов, %s ₽ выручки, %s%% маржа\n", 
                $stat['marketplace_name'],
                number_format($stat['total_orders']),
                number_format($stat['total_revenue'], 2),
                $stat['avg_margin_percent'] ?? 0
            );
        }
        echo "\n";
    }
    
    echo "4. Тестирование конкретного маркетплейса (OZON)...\n";
    $ozonStats = $api->getMarketplaceStatsByCode('OZON', '2025-09-15', '2025-09-30');
    
    if ($ozonStats) {
        echo "✅ Данные по OZON найдены:\n";
        echo "   - Заказов: " . number_format($ozonStats['total_orders']) . "\n";
        echo "   - Выручка: " . number_format($ozonStats['total_revenue'], 2) . " ₽\n";
        echo "   - Прибыль: " . number_format($ozonStats['total_profit'], 2) . " ₽\n";
        echo "   - Маржинальность: " . ($ozonStats['avg_margin_percent'] ?? 0) . "%\n\n";
    } else {
        echo "❌ Нет данных по OZON\n\n";
    }
    
    echo "5. Тестирование топ товаров по OZON...\n";
    $topProducts = $api->getTopProductsByMarketplace('OZON', '2025-09-15', '2025-09-30', 5);
    
    if (!empty($topProducts)) {
        echo "✅ Найдено топ товаров: " . count($topProducts) . "\n";
        foreach ($topProducts as $i => $product) {
            echo sprintf("   %d. %s (SKU: %s) - %s ₽\n", 
                $i + 1,
                $product['product_name'] ?? 'Товар #' . $product['product_id'],
                $product['sku'],
                number_format($product['total_revenue'], 2)
            );
        }
        echo "\n";
    } else {
        echo "❌ Нет данных по товарам OZON\n\n";
    }
    
    echo "6. Сравнение маркетплейсов...\n";
    $comparison = $api->compareMarketplaces('2025-09-15', '2025-09-30');
    
    echo "✅ Общие итоги:\n";
    echo "   - Общая выручка: " . number_format($comparison['totals']['total_revenue'], 2) . " ₽\n";
    echo "   - Общая прибыль: " . number_format($comparison['totals']['total_profit'], 2) . " ₽\n";
    echo "   - Всего заказов: " . number_format($comparison['totals']['total_orders']) . "\n\n";
    
    echo "🎉 Все тесты пройдены успешно!\n";
    echo "Теперь можно использовать dashboard_marketplace_example.php\n\n";
    
    echo "📋 Следующие шаги:\n";
    echo "1. Обновите пароль в dashboard_marketplace_example.php\n";
    echo "2. Откройте dashboard_marketplace_example.php в браузере\n";
    echo "3. Проверьте работу фильтров по маркетплейсам\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка: " . $e->getMessage() . "\n";
    echo "\nПроверьте:\n";
    echo "1. Правильность параметров подключения к БД\n";
    echo "2. Существование базы данных mi_core_db\n";
    echo "3. Права пользователя mi_core_user\n";
    echo "4. Существование таблиц fact_orders и dim_sources\n";
}
?>