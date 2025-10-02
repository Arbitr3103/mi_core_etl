<?php
/**
 * Улучшенный дашборд с разделением по маркетплейсам
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
$viewMode = $_GET['view'] ?? 'combined';

// Получаем данные
$marketplaceStats = $api->getMarketplaceStats($startDate, $endDate);
$comparison = $api->compareMarketplaces($startDate, $endDate);

// Данные для конкретного маркетплейса
if ($selectedMarketplace) {
    $specificStats = $api->getMarketplaceStatsByCode($selectedMarketplace, $startDate, $endDate);
    $topProducts = $api->getTopProductsByMarketplace($selectedMarketplace, $startDate, $endDate, 10);
    $chartData = $api->getDailyMarginChartByMarketplace($selectedMarketplace, $startDate, $endDate);
}

// Данные для всех маркетплейсов
$ozonStats = $api->getMarketplaceStatsByCode('OZON', $startDate, $endDate);
$wbStats = $api->getMarketplaceStatsByCode('WB', $startDate, $endDate);
$ozonChart = $api->getDailyMarginChartByMarketplace('OZON', $startDate, $endDate);
$wbChart = $api->getDailyMarginChartByMarketplace('WB', $startDate, $endDate);
$ozonTopProducts = $api->getTopProductsByMarketplace('OZON', $startDate, $endDate, 5);
$wbTopProducts = $api->getTopProductsByMarketplace('WB', $startDate, $endDate, 5);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚀 Дашборд маржинальности по маркетплейсам</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f8f9fa; }
        
        .kpi-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-5px);
        }
        
        .kpi-card.ozon {
            background: linear-gradient(135deg, #0066cc 0%, #004499 100%);
        }
        
        .kpi-card.wb {
            background: linear-gradient(135deg, #8b00ff 0%, #6600cc 100%);
        }
        
        .kpi-card.website {
            background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
        }
        
        .kpi-value {
            font-size: 2rem;
            font-weight: bold;
        }
        
        .trend-up { color: #28a745; }
        .trend-down { color: #dc3545; }
        .trend-stable { color: #6c757d; }
        
        .chart-container {
            position: relative;
            height: 400px;
        }
        
        .marketplace-section {
            border: 2px solid #e9ecef;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            background: white;
        }
        
        .marketplace-section.ozon {
            border-color: #0066cc;
        }
        
        .marketplace-section.wb {
            border-color: #8b00ff;
        }
        
        .view-toggle {
            background: white;
            border-radius: 10px;
            padding: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .product-item {
            padding: 10px;
            border-left: 4px solid #007bff;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .product-item.worst {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        
        .metric-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .metric-badge.positive { background: #d4edda; color: #155724; }
        .metric-badge.negative { background: #f8d7da; color: #721c24; }
        .metric-badge.neutral { background: #e2e3e5; color: #383d41; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h1>🚀 Дашборд маржинальности по маркетплейсам</h1>
                    
                    <!-- View Toggle -->
                    <div class="view-toggle">
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="viewMode" id="combined" value="combined" <?= $viewMode === 'combined' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="combined">📊 Общий вид</label>
                            
                            <input type="radio" class="btn-check" name="viewMode" id="separated" value="separated" <?= $viewMode === 'separated' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="separated">🏪 По маркетплейсам</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="view" id="viewInput" value="<?= $viewMode ?>">
                    <div class="col-md-3">
                        <label class="form-label">📅 Период с:</label>
                        <input type="date" class="form-control" name="start_date" value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">📅 по:</label>
                        <input type="date" class="form-control" name="end_date" value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">🏪 Маркетплейс:</label>
                        <select name="marketplace" class="form-control">
                            <option value="">Все маркетплейсы</option>
                            <option value="OZON" <?= $selectedMarketplace === 'OZON' ? 'selected' : '' ?>>📦 Ozon</option>
                            <option value="WB" <?= $selectedMarketplace === 'WB' ? 'selected' : '' ?>>🛍️ Wildberries</option>
                            <option value="WEBSITE" <?= $selectedMarketplace === 'WEBSITE' ? 'selected' : '' ?>>🌐 Собственный сайт</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary d-block w-100">🔍 Применить фильтры</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Combined View -->
        <div id="combinedView" style="display: <?= $viewMode === 'combined' ? 'block' : 'none' ?>">
            <!-- Overall KPI -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="kpi-card">
                        <div class="card-body text-center">
                            <div class="kpi-value"><?= number_format($comparison['totals']['total_revenue'], 0) ?></div>
                            <div class="small">💰 Общая выручка (₽)</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card">
                        <div class="card-body text-center">
                            <div class="kpi-value"><?= number_format($comparison['totals']['total_profit'], 0) ?></div>
                            <div class="small">📈 Общая прибыль (₽)</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card">
                        <div class="card-body text-center">
                            <div class="kpi-value"><?= number_format($comparison['totals']['total_orders']) ?></div>
                            <div class="small">📦 Всего заказов</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card">
                        <div class="card-body text-center">
                            <div class="kpi-value">
                                <?= $comparison['totals']['total_orders'] > 0 ? 
                                    number_format($comparison['totals']['total_revenue'] / $comparison['totals']['total_orders'], 0) : 0 ?>
                            </div>
                            <div class="small">💳 Средний чек (₽)</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Marketplace Comparison Chart -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5>📊 Сравнение маркетплейсов по выручке</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="marketplaceComparisonChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5>🥧 Доли маркетплейсов</h5>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="marketplaceShareChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Marketplace Stats Table -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5>📋 Детальная статистика по маркетплейсам</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>🏪 Маркетплейс</th>
                                    <th>📦 Заказы</th>
                                    <th>💰 Выручка</th>
                                    <th>📈 Прибыль</th>
                                    <th>📊 Маржа</th>
                                    <th>💳 Средний чек</th>
                                    <th>📦 Товаров</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comparison['marketplaces'] as $marketplace): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($marketplace['marketplace_name']) ?></strong>
                                        <br><small class="text-muted"><?= $marketplace['revenue_share'] ?? 0 ?>% от общей выручки</small>
                                    </td>
                                    <td><?= number_format($marketplace['total_orders']) ?></td>
                                    <td><?= number_format($marketplace['total_revenue'], 2) ?> ₽</td>
                                    <td>
                                        <span class="metric-badge <?= $marketplace['total_profit'] > 0 ? 'positive' : 'negative' ?>">
                                            <?= number_format($marketplace['total_profit'], 2) ?> ₽
                                        </span>
                                    </td>
                                    <td>
                                        <span class="metric-badge <?= $marketplace['avg_margin_percent'] > 0 ? 'positive' : 'negative' ?>">
                                            <?= $marketplace['avg_margin_percent'] ?? 0 ?>%
                                        </span>
                                    </td>
                                    <td>
                                        <?= $marketplace['total_orders'] > 0 ? 
                                            number_format($marketplace['total_revenue'] / $marketplace['total_orders'], 2) : 0 ?> ₽
                                    </td>
                                    <td><?= number_format($marketplace['unique_products']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Separated View -->
        <div id="separatedView" style="display: <?= $viewMode === 'separated' ? 'block' : 'none' ?>">
            <div class="row">
                <!-- Ozon Section -->
                <div class="col-md-6">
                    <div class="marketplace-section ozon">
                        <h3>📦 Ozon</h3>
                        
                        <!-- Ozon KPI -->
                        <?php if ($ozonStats): ?>
                        <div class="row mb-3">
                            <div class="col-6">
                                <div class="kpi-card ozon">
                                    <div class="card-body text-center p-3">
                                        <div class="h5"><?= number_format($ozonStats['total_revenue'], 0) ?> ₽</div>
                                        <div class="small">💰 Выручка</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-card ozon">
                                    <div class="card-body text-center p-3">
                                        <div class="h5"><?= number_format($ozonStats['total_profit'], 0) ?> ₽</div>
                                        <div class="small">📈 Прибыль</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-card ozon">
                                    <div class="card-body text-center p-3">
                                        <div class="h5"><?= $ozonStats['avg_margin_percent'] ?? 0 ?>%</div>
                                        <div class="small">📊 Маржа</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-card ozon">
                                    <div class="card-body text-center p-3">
                                        <div class="h5"><?= number_format($ozonStats['total_orders']) ?></div>
                                        <div class="small">📦 Заказы</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Ozon Chart -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6>📈 Динамика продаж Ozon</h6>
                            </div>
                            <div class="card-body">
                                <canvas id="ozonChart" height="200"></canvas>
                            </div>
                        </div>
                        
                        <!-- Ozon Top Products -->
                        <div class="card">
                            <div class="card-header">
                                <h6>🏆 Топ-5 товаров Ozon</h6>
                            </div>
                            <div class="card-body">
                                <?php foreach (array_slice($ozonTopProducts, 0, 5) as $index => $product): ?>
                                <div class="product-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong><?= $index + 1 ?>. <?= htmlspecialchars($product['product_name'] ?? 'Товар #' . $product['product_id']) ?></strong>
                                            <br><small class="text-muted">SKU: <?= htmlspecialchars($product['sku']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div class="metric-badge positive"><?= number_format($product['total_revenue'], 0) ?> ₽</div>
                                            <br><small><?= $product['margin_percent'] ?? 0 ?>% маржа</small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">Нет данных по Ozon за выбранный период</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Wildberries Section -->
                <div class="col-md-6">
                    <div class="marketplace-section wb">
                        <h3>🛍️ Wildberries</h3>
                        
                        <!-- WB KPI -->
                        <?php if ($wbStats): ?>
                        <div class="row mb-3">
                            <div class="col-6">
                                <div class="kpi-card wb">
                                    <div class="card-body text-center p-3">
                                        <div class="h5"><?= number_format($wbStats['total_revenue'], 0) ?> ₽</div>
                                        <div class="small">💰 Выручка</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-card wb">
                                    <div class="card-body text-center p-3">
                                        <div class="h5"><?= number_format($wbStats['total_profit'], 0) ?> ₽</div>
                                        <div class="small">📈 Прибыль</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-card wb">
                                    <div class="card-body text-center p-3">
                                        <div class="h5"><?= $wbStats['avg_margin_percent'] ?? 0 ?>%</div>
                                        <div class="small">📊 Маржа</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="kpi-card wb">
                                    <div class="card-body text-center p-3">
                                        <div class="h5"><?= number_format($wbStats['total_orders']) ?></div>
                                        <div class="small">📦 Заказы</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- WB Chart -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6>📈 Динамика продаж Wildberries</h6>
                            </div>
                            <div class="card-body">
                                <canvas id="wbChart" height="200"></canvas>
                            </div>
                        </div>
                        
                        <!-- WB Top Products -->
                        <div class="card">
                            <div class="card-header">
                                <h6>🏆 Топ-5 товаров Wildberries</h6>
                            </div>
                            <div class="card-body">
                                <?php foreach (array_slice($wbTopProducts, 0, 5) as $index => $product): ?>
                                <div class="product-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <strong><?= $index + 1 ?>. <?= htmlspecialchars($product['product_name'] ?? 'Товар #' . $product['product_id']) ?></strong>
                                            <br><small class="text-muted">SKU: <?= htmlspecialchars($product['sku']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div class="metric-badge positive"><?= number_format($product['total_revenue'], 0) ?> ₽</div>
                                            <br><small><?= $product['margin_percent'] ?? 0 ?>% маржа</small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">Нет данных по Wildberries за выбранный период</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // View toggle functionality
        document.querySelectorAll('input[name="viewMode"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const combinedView = document.getElementById('combinedView');
                const separatedView = document.getElementById('separatedView');
                const viewInput = document.getElementById('viewInput');
                
                if (this.value === 'separated') {
                    combinedView.style.display = 'none';
                    separatedView.style.display = 'block';
                } else {
                    combinedView.style.display = 'block';
                    separatedView.style.display = 'none';
                }
                
                viewInput.value = this.value;
            });
        });

        // Charts
        <?php if (!empty($comparison['marketplaces'])): ?>
        // Marketplace comparison chart
        const comparisonCtx = document.getElementById('marketplaceComparisonChart').getContext('2d');
        new Chart(comparisonCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($comparison['marketplaces'], 'marketplace_name')) ?>,
                datasets: [{
                    label: 'Выручка (₽)',
                    data: <?= json_encode(array_column($comparison['marketplaces'], 'total_revenue')) ?>,
                    backgroundColor: ['#0066cc', '#8b00ff', '#28a745'],
                    borderColor: ['#004499', '#6600cc', '#1e7e34'],
                    borderWidth: 2
                }, {
                    label: 'Прибыль (₽)',
                    data: <?= json_encode(array_column($comparison['marketplaces'], 'total_profit')) ?>,
                    backgroundColor: ['rgba(0, 102, 204, 0.5)', 'rgba(139, 0, 255, 0.5)', 'rgba(40, 167, 69, 0.5)'],
                    borderColor: ['#0066cc', '#8b00ff', '#28a745'],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Сумма (₽)'
                        }
                    }
                }
            }
        });

        // Marketplace share chart
        const shareCtx = document.getElementById('marketplaceShareChart').getContext('2d');
        new Chart(shareCtx, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode(array_column($comparison['marketplaces'], 'marketplace_name')) ?>,
                datasets: [{
                    data: <?= json_encode(array_column($comparison['marketplaces'], 'total_revenue')) ?>,
                    backgroundColor: ['#0066cc', '#8b00ff', '#28a745'],
                    borderWidth: 3,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });
        <?php endif; ?>

        // Individual marketplace charts
        <?php if (!empty($ozonChart)): ?>
        const ozonCtx = document.getElementById('ozonChart').getContext('2d');
        new Chart(ozonCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($item) { 
                    return date('d.m', strtotime($item['metric_date'])); 
                }, $ozonChart)) ?>,
                datasets: [{
                    label: 'Выручка',
                    data: <?= json_encode(array_column($ozonChart, 'revenue')) ?>,
                    borderColor: '#0066cc',
                    backgroundColor: 'rgba(0, 102, 204, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        <?php endif; ?>

        <?php if (!empty($wbChart)): ?>
        const wbCtx = document.getElementById('wbChart').getContext('2d');
        new Chart(wbCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($item) { 
                    return date('d.m', strtotime($item['metric_date'])); 
                }, $wbChart)) ?>,
                datasets: [{
                    label: 'Выручка',
                    data: <?= json_encode(array_column($wbChart, 'revenue')) ?>,
                    borderColor: '#8b00ff',
                    backgroundColor: 'rgba(139, 0, 255, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>