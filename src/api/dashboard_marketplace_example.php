<?php
/**
 * Пример дашборда с разделением по маркетплейсам
 */

require_once 'MarginDashboardAPI_Updated.php';

// Подключение к базе данных
try {
    $api = new MarginDashboardAPI_Updated('localhost', 'mi_core_db', 'mi_core_user', 'secure_password_123');
} catch (Exception $e) {
    die("Ошибка подключения: " . $e->getMessage());
}

// Параметры периода
$startDate = $_GET['start_date'] ?? '2025-09-15';
$endDate = $_GET['end_date'] ?? '2025-09-30';
$selectedMarketplace = $_GET['marketplace'] ?? null;

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд маржинальности по маркетплейсам</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; }
        .header { background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .filters { display: flex; gap: 15px; align-items: center; margin-bottom: 20px; }
        .filters input, .filters select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .filters button { padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        .filters button:hover { background: #0056b3; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-card h3 { margin: 0 0 15px 0; color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        .marketplace-item { padding: 10px; border-left: 4px solid #007bff; margin-bottom: 10px; background: #f8f9fa; }
        .marketplace-item.ozon { border-left-color: #0066cc; }
        .marketplace-item.wb { border-left-color: #8b00ff; }
        .marketplace-item.website { border-left-color: #28a745; }
        .metric { display: flex; justify-content: space-between; margin-bottom: 5px; }
        .metric-label { font-weight: bold; }
        .metric-value { color: #007bff; }
        .chart-container { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .products-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .products-table th, .products-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        .products-table th { background: #f8f9fa; font-weight: bold; }
        .products-table tr:hover { background: #f8f9fa; }
        .positive { color: #28a745; }
        .negative { color: #dc3545; }
        .neutral { color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📊 Дашборд маржинальности по маркетплейсам</h1>
            
            <form method="GET" class="filters">
                <label>
                    Период с:
                    <input type="date" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                </label>
                <label>
                    по:
                    <input type="date" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                </label>
                <label>
                    Маркетплейс:
                    <select name="marketplace">
                        <option value="">Все маркетплейсы</option>
                        <option value="OZON" <?= $selectedMarketplace === 'OZON' ? 'selected' : '' ?>>Ozon</option>
                        <option value="WB" <?= $selectedMarketplace === 'WB' ? 'selected' : '' ?>>Wildberries</option>
                        <option value="WEBSITE" <?= $selectedMarketplace === 'WEBSITE' ? 'selected' : '' ?>>Собственный сайт</option>
                    </select>
                </label>
                <button type="submit">Применить фильтры</button>
            </form>
        </div>

        <?php
        // Получаем данные
        if ($selectedMarketplace) {
            $marketplaceStats = [$api->getMarketplaceStatsByCode($selectedMarketplace, $startDate, $endDate)];
            $topProducts = $api->getTopProductsByMarketplace($selectedMarketplace, $startDate, $endDate, 10);
        } else {
            $marketplaceStats = $api->getMarketplaceStats($startDate, $endDate);
            $topProducts = [];
        }
        
        $comparison = $api->compareMarketplaces($startDate, $endDate);
        ?>

        <div class="stats-grid">
            <!-- Общая статистика -->
            <div class="stat-card">
                <h3>📈 Общая статистика</h3>
                <div class="metric">
                    <span class="metric-label">Общая выручка:</span>
                    <span class="metric-value"><?= number_format($comparison['totals']['total_revenue'], 2) ?> ₽</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Общая прибыль:</span>
                    <span class="metric-value"><?= number_format($comparison['totals']['total_profit'], 2) ?> ₽</span>
                </div>
                <div class="metric">
                    <span class="metric-label">Всего заказов:</span>
                    <span class="metric-value"><?= number_format($comparison['totals']['total_orders']) ?></span>
                </div>
                <div class="metric">
                    <span class="metric-label">Период:</span>
                    <span class="metric-value"><?= $startDate ?> - <?= $endDate ?></span>
                </div>
            </div>

            <!-- Статистика по маркетплейсам -->
            <div class="stat-card">
                <h3>🏪 Статистика по маркетплейсам</h3>
                <?php foreach ($comparison['marketplaces'] as $marketplace): ?>
                    <div class="marketplace-item <?= strtolower($marketplace['marketplace_code']) ?>">
                        <strong><?= htmlspecialchars($marketplace['marketplace_name']) ?></strong>
                        <div class="metric">
                            <span class="metric-label">Выручка:</span>
                            <span class="metric-value">
                                <?= number_format($marketplace['total_revenue'], 2) ?> ₽ 
                                (<?= $marketplace['revenue_share'] ?? 0 ?>%)
                            </span>
                        </div>
                        <div class="metric">
                            <span class="metric-label">Прибыль:</span>
                            <span class="metric-value <?= $marketplace['total_profit'] > 0 ? 'positive' : 'negative' ?>">
                                <?= number_format($marketplace['total_profit'], 2) ?> ₽
                            </span>
                        </div>
                        <div class="metric">
                            <span class="metric-label">Маржинальность:</span>
                            <span class="metric-value <?= $marketplace['avg_margin_percent'] > 0 ? 'positive' : 'negative' ?>">
                                <?= $marketplace['avg_margin_percent'] ?? 0 ?>%
                            </span>
                        </div>
                        <div class="metric">
                            <span class="metric-label">Заказов:</span>
                            <span class="metric-value">
                                <?= number_format($marketplace['total_orders']) ?> 
                                (<?= $marketplace['orders_share'] ?? 0 ?>%)
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Сравнение маркетплейсов -->
            <div class="stat-card">
                <h3>⚖️ Сравнение эффективности</h3>
                <?php 
                $sortedByMargin = $comparison['marketplaces'];
                usort($sortedByMargin, function($a, $b) {
                    return ($b['avg_margin_percent'] ?? 0) <=> ($a['avg_margin_percent'] ?? 0);
                });
                ?>
                <p><strong>Лучшая маржинальность:</strong></p>
                <?php foreach (array_slice($sortedByMargin, 0, 3) as $index => $marketplace): ?>
                    <div class="metric">
                        <span class="metric-label"><?= $index + 1 ?>. <?= htmlspecialchars($marketplace['marketplace_name']) ?>:</span>
                        <span class="metric-value <?= $marketplace['avg_margin_percent'] > 0 ? 'positive' : 'negative' ?>">
                            <?= $marketplace['avg_margin_percent'] ?? 0 ?>%
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($selectedMarketplace && !empty($topProducts)): ?>
        <!-- Топ товаров для выбранного маркетплейса -->
        <div class="chart-container">
            <h3>🏆 Топ товаров - <?= htmlspecialchars($selectedMarketplace) ?></h3>
            <table class="products-table">
                <thead>
                    <tr>
                        <th>Товар</th>
                        <th>SKU</th>
                        <th>Заказов</th>
                        <th>Количество</th>
                        <th>Выручка</th>
                        <th>Прибыль</th>
                        <th>Маржинальность</th>
                        <th>Средняя цена</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topProducts as $product): ?>
                    <tr>
                        <td><?= htmlspecialchars($product['product_name'] ?? 'Товар #' . $product['product_id']) ?></td>
                        <td><?= htmlspecialchars($product['sku']) ?></td>
                        <td><?= number_format($product['orders_count']) ?></td>
                        <td><?= number_format($product['total_qty']) ?></td>
                        <td><?= number_format($product['total_revenue'], 2) ?> ₽</td>
                        <td class="<?= $product['total_profit'] > 0 ? 'positive' : 'negative' ?>">
                            <?= number_format($product['total_profit'], 2) ?> ₽
                        </td>
                        <td class="<?= $product['margin_percent'] > 0 ? 'positive' : 'negative' ?>">
                            <?= $product['margin_percent'] ?? 0 ?>%
                        </td>
                        <td><?= number_format($product['avg_price'], 2) ?> ₽</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Детальная таблица по всем маркетплейсам -->
        <div class="chart-container">
            <h3>📋 Детальная статистика по маркетплейсам</h3>
            <table class="products-table">
                <thead>
                    <tr>
                        <th>Маркетплейс</th>
                        <th>Заказов</th>
                        <th>Выручка</th>
                        <th>Себестоимость</th>
                        <th>Комиссия</th>
                        <th>Доставка</th>
                        <th>Прочие расходы</th>
                        <th>Прибыль</th>
                        <th>Маржинальность</th>
                        <th>Уникальных товаров</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($comparison['marketplaces'] as $marketplace): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($marketplace['marketplace_name']) ?></strong></td>
                        <td><?= number_format($marketplace['total_orders']) ?></td>
                        <td><?= number_format($marketplace['total_revenue'], 2) ?> ₽</td>
                        <td><?= number_format($marketplace['total_cogs'], 2) ?> ₽</td>
                        <td><?= number_format($marketplace['total_commission'], 2) ?> ₽</td>
                        <td><?= number_format($marketplace['total_shipping'], 2) ?> ₽</td>
                        <td><?= number_format($marketplace['total_other_expenses'], 2) ?> ₽</td>
                        <td class="<?= $marketplace['total_profit'] > 0 ? 'positive' : 'negative' ?>">
                            <?= number_format($marketplace['total_profit'], 2) ?> ₽
                        </td>
                        <td class="<?= $marketplace['avg_margin_percent'] > 0 ? 'positive' : 'negative' ?>">
                            <?= $marketplace['avg_margin_percent'] ?? 0 ?>%
                        </td>
                        <td><?= number_format($marketplace['unique_products']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="chart-container">
            <h3>ℹ️ Информация о системе</h3>
            <p><strong>Статус:</strong> ✅ Система работает с разделением по маркетплейсам</p>
            <p><strong>Источники данных:</strong> fact_orders + dim_sources</p>
            <p><strong>Доступные маркетплейсы:</strong></p>
            <ul>
                <?php foreach ($api->getAvailableMarketplaces() as $mp): ?>
                <li><strong><?= htmlspecialchars($mp['code']) ?></strong> - <?= htmlspecialchars($mp['name']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</body>
</html>