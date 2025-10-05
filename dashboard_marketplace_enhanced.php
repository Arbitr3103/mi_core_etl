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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>🚀 Дашборд маржинальности по маркетплейсам</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="src/js/OzonFunnelChart.js"></script>
    <script src="src/js/OzonAnalyticsIntegration.js"></script>
    <script src="src/js/OzonDemographics.js"></script>
    <script src="src/js/OzonExportManager.js"></script>
    <script src="src/js/OzonSecurityIntegration.js"></script>
    <style>
        body { 
            background: #f8f9fa; 
            overflow-x: hidden;
        }
        
        .container-fluid {
            max-width: 100vw;
            overflow-x: hidden;
        }
        
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
        
        .bg-purple { background-color: #8b00ff !important; }
        
        .table-responsive {
            max-width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        
        .table th { 
            font-weight: 600; 
            font-size: 0.8rem;
            white-space: nowrap;
            min-width: 80px;
        }
        
        .table td {
            vertical-align: middle;
            font-size: 0.8rem;
            white-space: nowrap;
            min-width: 80px;
        }
        
        /* Mobile optimizations */
        @media (max-width: 768px) {
            .container-fluid {
                padding-left: 10px;
                padding-right: 10px;
            }
            
            .card {
                margin-bottom: 15px;
            }
            
            .table th, .table td {
                font-size: 0.7rem;
                padding: 0.4rem;
                min-width: 60px;
            }
            
            .kpi-card .kpi-value {
                font-size: 1.5rem;
            }
            
            .kpi-card .small {
                font-size: 0.7rem;
            }
            
            .btn-group {
                flex-wrap: wrap;
            }
            
            .btn-group .btn {
                font-size: 0.8rem;
                padding: 0.4rem 0.8rem;
            }
            
            .modal-dialog {
                margin: 0.5rem;
            }
        }
        
        /* Extra small devices */
        @media (max-width: 576px) {
            .table th, .table td {
                font-size: 0.65rem;
                padding: 0.3rem;
                min-width: 50px;
            }
            
            .row.g-3 > * {
                margin-bottom: 0.5rem;
            }
            
            .view-toggle .btn-group {
                width: 100%;
            }
            
            .view-toggle .btn {
                flex: 1;
                font-size: 0.7rem;
            }
        }
        
        .table code {
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        
        .spinner-border {
            color: #007bff;
        }
        
        .nav-tabs .nav-link {
            font-weight: 500;
        }
        
        .nav-tabs .nav-link.active {
            background-color: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .sortable {
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
        }
        
        .sortable:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .sort-icon {
            font-size: 0.8rem;
            margin-left: 5px;
        }
        
        .inventory-row:hover {
            background-color: rgba(0, 123, 255, 0.1);
        }
        
        .modal-body .card {
            border: 1px solid #e9ecef;
        }
        
        .modal-body .table-sm td {
            padding: 0.25rem 0.5rem;
            border: none;
        }
        
        .modal-body .table-sm td:first-child {
            width: 40%;
        }
        
        /* Ozon Analytics Styles */
        .kpi-value.updating {
            opacity: 0.6;
            transform: scale(0.95);
            transition: all 0.3s ease;
        }
        
        .chart-container {
            position: relative;
            min-height: 300px;
        }
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
                            
                            <input type="radio" class="btn-check" name="viewMode" id="inventory" value="inventory" <?= $viewMode === 'inventory' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="inventory">📦 Остатки товаров</label>
                            
                            <input type="radio" class="btn-check" name="viewMode" id="ozon-analytics" value="ozon-analytics" <?= $viewMode === 'ozon-analytics' ? 'checked' : '' ?>>
                            <label class="btn btn-outline-primary" for="ozon-analytics">📊 Аналитика Ozon</label>
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

        <!-- Inventory View -->
        <div id="inventoryView" style="display: <?= $viewMode === 'inventory' ? 'block' : 'none' ?>">
            <!-- Inventory Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">🏪 Маркетплейс:</label>
                            <select id="inventoryMarketplace" class="form-control">
                                <option value="">Все маркетплейсы</option>
                                <option value="Ozon">📦 Ozon</option>
                                <option value="Wildberries">🛍️ Wildberries</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">🏭 Склад:</label>
                            <select id="inventoryWarehouse" class="form-control">
                                <option value="">Все склады</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">⚠️ Уровень остатков:</label>
                            <select id="inventoryStockLevel" class="form-control">
                                <option value="">Все уровни</option>
                                <option value="critical">🔴 Критические (≤5)</option>
                                <option value="low">🟡 Низкие (≤20)</option>
                                <option value="medium">🟠 Средние (≤50)</option>
                                <option value="good">🟢 Хорошие (>50)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">🔍 Поиск:</label>
                            <input type="text" id="inventorySearch" class="form-control" placeholder="SKU или название товара">
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <button type="button" id="applyInventoryFilters" class="btn btn-primary">🔍 Применить фильтры</button>
                            <button type="button" id="resetInventoryFilters" class="btn btn-outline-secondary">🔄 Сбросить</button>
                        </div>
                        <div class="col-md-6 text-end">
                            <button type="button" id="exportInventoryCSV" class="btn btn-success">📤 Экспорт CSV</button>
                            <button type="button" id="refreshInventory" class="btn btn-outline-primary">🔄 Обновить</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Inventory Summary KPI -->
            <div id="inventorySummaryKPI" class="row mb-4">
                <!-- KPI cards will be loaded here -->
            </div>

            <!-- Inventory Tabs -->
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="inventoryTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="all-inventory-tab" data-bs-toggle="tab" data-bs-target="#all-inventory" type="button" role="tab">
                                📋 Все остатки
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="ozon-inventory-tab" data-bs-toggle="tab" data-bs-target="#ozon-inventory" type="button" role="tab">
                                📦 Ozon
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="wb-inventory-tab" data-bs-toggle="tab" data-bs-target="#wb-inventory" type="button" role="tab">
                                🛍️ Wildberries
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="critical-inventory-tab" data-bs-toggle="tab" data-bs-target="#critical-inventory" type="button" role="tab">
                                ⚠️ Критические остатки
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="inventoryTabContent">
                        <!-- All Inventory Tab -->
                        <div class="tab-pane fade show active" id="all-inventory" role="tabpanel">
                            <div id="allInventoryTable">
                                <div class="text-center py-4">
                                    <div class="spinner-border" role="status">
                                        <span class="visually-hidden">Загрузка...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Ozon Inventory Tab -->
                        <div class="tab-pane fade" id="ozon-inventory" role="tabpanel">
                            <div id="ozonInventoryTable">
                                <div class="text-center py-4">
                                    <div class="spinner-border" role="status">
                                        <span class="visually-hidden">Загрузка...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Wildberries Inventory Tab -->
                        <div class="tab-pane fade" id="wb-inventory" role="tabpanel">
                            <div id="wbInventoryTable">
                                <div class="text-center py-4">
                                    <div class="spinner-border" role="status">
                                        <span class="visually-hidden">Загрузка...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Critical Inventory Tab -->
                        <div class="tab-pane fade" id="critical-inventory" role="tabpanel">
                            <div id="criticalInventoryTable">
                                <div class="text-center py-4">
                                    <div class="spinner-border" role="status">
                                        <span class="visually-hidden">Загрузка...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ozon Analytics View -->
        <div id="ozonAnalyticsView" style="display: <?= $viewMode === 'ozon-analytics' ? 'block' : 'none' ?>">
            <!-- Analytics Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">📅 Период с:</label>
                            <input type="date" id="analyticsStartDate" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">📅 по:</label>
                            <input type="date" id="analyticsEndDate" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">📦 Товар:</label>
                            <select id="analyticsProduct" class="form-control">
                                <option value="">Все товары</option>
                                <!-- Options will be loaded dynamically -->
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">📢 Кампания:</label>
                            <select id="analyticsCampaign" class="form-control">
                                <option value="">Все кампании</option>
                                <!-- Options will be loaded dynamically -->
                            </select>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <button type="button" id="applyAnalyticsFilters" class="btn btn-primary">🔍 Применить фильтры</button>
                            <button type="button" id="resetAnalyticsFilters" class="btn btn-outline-secondary">🔄 Сбросить</button>
                        </div>
                        <div class="col-md-6 text-end">
                            <a href="src/ozon_settings.php" class="btn btn-outline-secondary" data-permission="manage_settings">⚙️ Настройки API</a>
                            <button type="button" id="exportAnalyticsData" class="btn btn-success" data-permission="export_data" data-rate-limit="export_data">📤 Экспорт данных</button>
                            <button type="button" id="refreshAnalytics" class="btn btn-outline-primary" data-permission="view_funnel" data-rate-limit="view_funnel">🔄 Обновить</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analytics KPI -->
            <div id="analyticsKPI" class="row mb-4">
                <div class="col-md-3">
                    <div class="kpi-card ozon">
                        <div class="card-body text-center">
                            <div class="kpi-value" id="totalViews">-</div>
                            <div class="small">👁️ Просмотры</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card ozon">
                        <div class="card-body text-center">
                            <div class="kpi-value" id="totalCartAdditions">-</div>
                            <div class="small">🛒 В корзину</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card ozon">
                        <div class="card-body text-center">
                            <div class="kpi-value" id="totalOrders">-</div>
                            <div class="small">📦 Заказы</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card ozon">
                        <div class="card-body text-center">
                            <div class="kpi-value" id="overallConversion">-</div>
                            <div class="small">📈 Конверсия</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Analytics Tabs -->
            <div class="card">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="analyticsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="funnel-tab" data-bs-toggle="tab" data-bs-target="#funnel-analytics" type="button" role="tab">
                                🔄 Воронка продаж
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="demographics-tab" data-bs-toggle="tab" data-bs-target="#demographics-analytics" type="button" role="tab">
                                👥 Демография
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="campaigns-tab" data-bs-toggle="tab" data-bs-target="#campaigns-analytics" type="button" role="tab">
                                📢 Кампании
                            </button>
                        </li>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="analyticsTabContent">
                        <!-- Funnel Analytics Tab -->
                        <div class="tab-pane fade show active" id="funnel-analytics" role="tabpanel">
                            <div class="row">
                                <div class="col-12">
                                    <div class="card">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <h6 class="mb-0">🔄 Воронка продаж Ozon</h6>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-outline-success" data-quick-export="funnel" data-original-text="📄 CSV">
                                                    📄 CSV
                                                </button>
                                                <button type="button" class="btn btn-outline-info" onclick="window.ozonExportManager.quickExport('funnel', 'json')">
                                                    📋 JSON
                                                </button>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <div id="ozonFunnelChart" class="chart-container" style="height: 500px;">
                                                <div class="text-center py-4">
                                                    <div class="spinner-border" role="status">
                                                        <span class="visually-hidden">Загрузка...</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Demographics Analytics Tab -->
                        <div class="tab-pane fade" id="demographics-analytics" role="tabpanel">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">👥 Демографические данные</h6>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-success" data-quick-export="demographics" data-original-text="📄 CSV">
                                            📄 CSV
                                        </button>
                                        <button type="button" class="btn btn-outline-info" onclick="window.ozonExportManager.quickExport('demographics', 'json')">
                                            📋 JSON
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div id="demographicsContainer">
                                        <!-- Demographics dashboard will be initialized here -->
                                        <div class="text-center py-4">
                                            <div class="spinner-border" role="status">
                                                <span class="visually-hidden">Загрузка демографических данных...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Campaigns Analytics Tab -->
                        <div class="tab-pane fade" id="campaigns-analytics" role="tabpanel">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">📢 Эффективность рекламных кампаний</h6>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-success" data-quick-export="campaigns" data-original-text="📄 CSV">
                                            📄 CSV
                                        </button>
                                        <button type="button" class="btn btn-outline-info" onclick="window.ozonExportManager.quickExport('campaigns', 'json')">
                                            📋 JSON
                                        </button>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div id="campaignsTable">
                                        <div class="text-center py-4">
                                            <div class="spinner-border" role="status">
                                                <span class="visually-hidden">Загрузка...</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
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

    <!-- Product Detail Modal -->
    <div class="modal fade" id="productDetailModal" tabindex="-1" aria-labelledby="productDetailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="productDetailModalLabel">📦 Детали товара</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="productDetailContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
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
                const inventoryView = document.getElementById('inventoryView');
                const ozonAnalyticsView = document.getElementById('ozonAnalyticsView');
                const viewInput = document.getElementById('viewInput');
                
                // Hide all views
                combinedView.style.display = 'none';
                separatedView.style.display = 'none';
                inventoryView.style.display = 'none';
                ozonAnalyticsView.style.display = 'none';
                
                // Show selected view
                if (this.value === 'separated') {
                    separatedView.style.display = 'block';
                } else if (this.value === 'inventory') {
                    inventoryView.style.display = 'block';
                    loadInventoryData();
                } else if (this.value === 'ozon-analytics') {
                    ozonAnalyticsView.style.display = 'block';
                    loadOzonAnalyticsData();
                } else {
                    combinedView.style.display = 'block';
                }
                
                viewInput.value = this.value;
            });
        });

        // Inventory functionality
        let currentInventoryFilters = {};
        
        function loadInventoryData() {
            loadInventorySummary();
            loadWarehouses();
            loadInventoryTable('all');
        }
        
        function loadInventorySummary() {
            fetch('inventory_api_endpoint.php?action=summary')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderInventorySummaryKPI(data.data);
                    }
                })
                .catch(error => console.error('Error loading inventory summary:', error));
        }
        
        function renderInventorySummaryKPI(summaryData) {
            const container = document.getElementById('inventorySummaryKPI');
            
            let totalProducts = 0;
            let totalQuantity = 0;
            let totalValue = 0;
            let criticalItems = 0;
            
            summaryData.forEach(item => {
                totalProducts += parseInt(item.total_products);
                totalQuantity += parseInt(item.total_quantity);
                totalValue += parseFloat(item.total_inventory_value);
                criticalItems += parseInt(item.critical_items);
            });
            
            container.innerHTML = `
                <div class="col-md-3">
                    <div class="kpi-card">
                        <div class="card-body text-center">
                            <div class="kpi-value">${totalProducts.toLocaleString()}</div>
                            <div class="small">📦 Всего товаров</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card">
                        <div class="card-body text-center">
                            <div class="kpi-value">${totalQuantity.toLocaleString()}</div>
                            <div class="small">📊 Общее количество</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card">
                        <div class="card-body text-center">
                            <div class="kpi-value">${totalValue.toLocaleString()} ₽</div>
                            <div class="small">💰 Стоимость остатков</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="kpi-card ${criticalItems > 0 ? 'bg-danger' : ''}">
                        <div class="card-body text-center">
                            <div class="kpi-value">${criticalItems}</div>
                            <div class="small">⚠️ Критические остатки</div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        function loadWarehouses(marketplace = '') {
            const url = marketplace ? `inventory_api_endpoint.php?action=warehouses&marketplace=${marketplace}` : 'inventory_api_endpoint.php?action=warehouses';
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const warehouseSelect = document.getElementById('inventoryWarehouse');
                        warehouseSelect.innerHTML = '<option value="">Все склады</option>';
                        
                        data.data.forEach(warehouse => {
                            warehouseSelect.innerHTML += `<option value="${warehouse}">${warehouse}</option>`;
                        });
                    }
                })
                .catch(error => console.error('Error loading warehouses:', error));
        }
        
        function loadInventoryTable(type, page = 1) {
            const filters = getCurrentInventoryFilters();
            let marketplace = '';
            let containerId = '';
            
            switch(type) {
                case 'ozon':
                    marketplace = 'Ozon';
                    containerId = 'ozonInventoryTable';
                    break;
                case 'wb':
                    marketplace = 'Wildberries';
                    containerId = 'wbInventoryTable';
                    break;
                case 'critical':
                    containerId = 'criticalInventoryTable';
                    break;
                default:
                    containerId = 'allInventoryTable';
            }
            
            const params = new URLSearchParams({
                action: type === 'critical' ? 'critical_stock' : 'inventory',
                page: page,
                limit: 50,
                ...filters
            });
            
            if (marketplace) {
                params.append('marketplace', marketplace);
            }
            
            fetch(`inventory_api_endpoint.php?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderInventoryTable(data, containerId, type, page);
                    }
                })
                .catch(error => console.error('Error loading inventory table:', error));
        }
        
        function getCurrentInventoryFilters() {
            return {
                marketplace: document.getElementById('inventoryMarketplace').value,
                warehouse: document.getElementById('inventoryWarehouse').value,
                stock_level: document.getElementById('inventoryStockLevel').value,
                search: document.getElementById('inventorySearch').value
            };
        }
        
        function renderInventoryTable(data, containerId, type, currentPage) {
            const container = document.getElementById(containerId);
            
            if (type === 'critical') {
                // Render critical stock table
                let tableHTML = `
                    <div class="table-responsive">
                        <table class="table table-hover sortable-table">
                            <thead class="table-danger">
                                <tr>
                                    <th class="sortable" data-sort="product_name">
                                        Товар <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="display_sku">
                                        SKU <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="source">
                                        Маркетплейс <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="warehouse_name">
                                        Склад <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="quantity">
                                        Остаток <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="reserved_quantity">
                                        Зарезервировано <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="available_quantity">
                                        Доступно <i class="sort-icon">↕️</i>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                data.data.forEach(item => {
                    const stockBadge = getStockLevelBadge(item.quantity);
                    tableHTML += `
                        <tr>
                            <td>
                                <strong>${item.product_name || 'Товар #' + item.product_id}</strong>
                            </td>
                            <td><code>${item.display_sku}</code></td>
                            <td><span class="badge bg-primary">${item.source}</span></td>
                            <td>${item.warehouse_name}</td>
                            <td>${stockBadge} ${item.quantity}</td>
                            <td>${item.reserved_quantity}</td>
                            <td><strong>${item.available_quantity}</strong></td>
                        </tr>
                    `;
                });
                
                tableHTML += '</tbody></table></div>';
                container.innerHTML = tableHTML;
            } else {
                // Render regular inventory table
                let tableHTML = `
                    <div class="table-responsive">
                        <table class="table table-hover sortable-table">
                            <thead class="table-dark">
                                <tr>
                                    <th class="sortable" data-sort="product_name">
                                        Товар <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="display_sku">
                                        SKU <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="marketplace">
                                        Маркетплейс <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="warehouse_name">
                                        Склад <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="storage_type">
                                        Тип хранения <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="quantity">
                                        Остаток <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="reserved_quantity">
                                        Зарезервировано <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="available_quantity">
                                        Доступно <i class="sort-icon">↕️</i>
                                    </th>
                                    <th class="sortable" data-sort="inventory_value">
                                        Стоимость <i class="sort-icon">↕️</i>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                data.data.forEach(item => {
                    const stockBadge = getStockLevelBadge(item.quantity);
                    const marketplaceBadge = item.marketplace === 'Ozon' ? 'bg-primary' : 'bg-purple';
                    
                    tableHTML += `
                        <tr class="inventory-row" data-product='${JSON.stringify(item)}'>
                            <td>
                                <strong>${item.product_name || 'Товар #' + item.product_id}</strong>
                                <br><small class="text-muted">ID: ${item.product_id}</small>
                            </td>
                            <td><code>${item.display_sku}</code></td>
                            <td><span class="badge ${marketplaceBadge}">${item.marketplace}</span></td>
                            <td>${item.warehouse_name}</td>
                            <td><span class="badge bg-secondary">${item.storage_type}</span></td>
                            <td>${stockBadge} ${item.quantity}</td>
                            <td>${item.reserved_quantity}</td>
                            <td><strong>${item.available_quantity}</strong></td>
                            <td>
                                ${parseFloat(item.inventory_value).toLocaleString()} ₽
                                <br><small class="text-muted">${parseFloat(item.cost_price || 0).toLocaleString()} ₽/шт</small>
                            </td>
                        </tr>
                    `;
                });
                
                tableHTML += '</tbody></table></div>';
                
                // Add pagination if available
                if (data.pagination && data.pagination.pages > 1) {
                    tableHTML += renderPagination(data.pagination, type);
                }
                
                container.innerHTML = tableHTML;
                
                // Add click handlers for product rows
                container.querySelectorAll('.inventory-row').forEach(row => {
                    row.style.cursor = 'pointer';
                    row.addEventListener('click', function() {
                        const productData = JSON.parse(this.getAttribute('data-product'));
                        showProductDetail(productData);
                    });
                });
                
                // Add sorting functionality
                container.querySelectorAll('.sortable').forEach(header => {
                    header.style.cursor = 'pointer';
                    header.addEventListener('click', function() {
                        const sortField = this.getAttribute('data-sort');
                        sortTable(container.querySelector('tbody'), sortField);
                        updateSortIcons(container, this);
                    });
                });
            }
        }
        
        function showProductDetail(product) {
            const modalContent = document.getElementById('productDetailContent');
            const modalTitle = document.getElementById('productDetailModalLabel');
            
            modalTitle.textContent = `📦 ${product.product_name || 'Товар #' + product.product_id}`;
            
            const stockLevel = getStockLevelText(product.quantity);
            const lastUpdated = product.last_updated ? new Date(product.last_updated).toLocaleString('ru-RU') : 'Не указано';
            
            modalContent.innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>📋 Основная информация</h6>
                        <table class="table table-sm">
                            <tr><td><strong>ID товара:</strong></td><td>${product.product_id}</td></tr>
                            <tr><td><strong>Название:</strong></td><td>${product.product_name || 'Не указано'}</td></tr>
                            <tr><td><strong>SKU:</strong></td><td><code>${product.display_sku}</code></td></tr>
                            <tr><td><strong>SKU Ozon:</strong></td><td><code>${product.sku_ozon || 'Не указано'}</code></td></tr>
                            <tr><td><strong>SKU WB:</strong></td><td><code>${product.sku_wb || 'Не указано'}</code></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>🏪 Информация о складе</h6>
                        <table class="table table-sm">
                            <tr><td><strong>Маркетплейс:</strong></td><td><span class="badge ${product.marketplace === 'Ozon' ? 'bg-primary' : 'bg-purple'}">${product.marketplace}</span></td></tr>
                            <tr><td><strong>Склад:</strong></td><td>${product.warehouse_name}</td></tr>
                            <tr><td><strong>Тип хранения:</strong></td><td><span class="badge bg-secondary">${product.storage_type}</span></td></tr>
                            <tr><td><strong>Обновлено:</strong></td><td>${lastUpdated}</td></tr>
                        </table>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-6">
                        <h6>📊 Остатки</h6>
                        <div class="card">
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-4">
                                        <div class="h4 text-primary">${product.quantity}</div>
                                        <small>Всего</small>
                                    </div>
                                    <div class="col-4">
                                        <div class="h4 text-warning">${product.reserved_quantity}</div>
                                        <small>Зарезервировано</small>
                                    </div>
                                    <div class="col-4">
                                        <div class="h4 text-success">${product.available_quantity}</div>
                                        <small>Доступно</small>
                                    </div>
                                </div>
                                <div class="mt-3 text-center">
                                    <span class="badge ${getStockLevelBadgeClass(product.quantity)} fs-6">${stockLevel}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6>💰 Стоимость</h6>
                        <div class="card">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-12">
                                        <div class="d-flex justify-content-between">
                                            <span>Себестоимость за единицу:</span>
                                            <strong>${parseFloat(product.cost_price || 0).toLocaleString()} ₽</strong>
                                        </div>
                                        <div class="d-flex justify-content-between mt-2">
                                            <span>Общая стоимость остатков:</span>
                                            <strong class="text-success">${parseFloat(product.inventory_value).toLocaleString()} ₽</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            const modal = new bootstrap.Modal(document.getElementById('productDetailModal'));
            modal.show();
        }
        
        function getStockLevelText(quantity) {
            if (quantity <= 5) return 'Критический уровень';
            if (quantity <= 20) return 'Низкий уровень';
            if (quantity <= 50) return 'Средний уровень';
            return 'Хороший уровень';
        }
        
        function getStockLevelBadgeClass(quantity) {
            if (quantity <= 5) return 'bg-danger';
            if (quantity <= 20) return 'bg-warning';
            if (quantity <= 50) return 'bg-info';
            return 'bg-success';
        }
        
        function sortTable(tbody, field) {
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            rows.sort((a, b) => {
                const aData = JSON.parse(a.getAttribute('data-product'));
                const bData = JSON.parse(b.getAttribute('data-product'));
                
                let aValue = aData[field];
                let bValue = bData[field];
                
                // Handle numeric fields
                if (['quantity', 'reserved_quantity', 'available_quantity', 'inventory_value', 'cost_price'].includes(field)) {
                    aValue = parseFloat(aValue) || 0;
                    bValue = parseFloat(bValue) || 0;
                    return bValue - aValue; // Descending for numbers
                }
                
                // Handle string fields
                aValue = (aValue || '').toString().toLowerCase();
                bValue = (bValue || '').toString().toLowerCase();
                
                return aValue.localeCompare(bValue);
            });
            
            // Clear tbody and append sorted rows
            tbody.innerHTML = '';
            rows.forEach(row => tbody.appendChild(row));
        }
        
        function updateSortIcons(container, clickedHeader) {
            // Reset all sort icons
            container.querySelectorAll('.sort-icon').forEach(icon => {
                icon.textContent = '↕️';
            });
            
            // Update clicked header icon
            const icon = clickedHeader.querySelector('.sort-icon');
            icon.textContent = '🔽';
        }
        
        function getStockLevelBadge(quantity) {
            if (quantity <= 5) return '<span class="badge bg-danger">🔴</span>';
            if (quantity <= 20) return '<span class="badge bg-warning">🟡</span>';
            if (quantity <= 50) return '<span class="badge bg-info">🟠</span>';
            return '<span class="badge bg-success">🟢</span>';
        }
        
        function renderPagination(pagination, type) {
            let paginationHTML = '<nav class="mt-3"><ul class="pagination justify-content-center">';
            
            // Previous button
            if (pagination.page > 1) {
                paginationHTML += `<li class="page-item"><a class="page-link" href="#" onclick="loadInventoryTable('${type}', ${pagination.page - 1})">Предыдущая</a></li>`;
            }
            
            // Page numbers
            const startPage = Math.max(1, pagination.page - 2);
            const endPage = Math.min(pagination.pages, pagination.page + 2);
            
            for (let i = startPage; i <= endPage; i++) {
                const activeClass = i === pagination.page ? 'active' : '';
                paginationHTML += `<li class="page-item ${activeClass}"><a class="page-link" href="#" onclick="loadInventoryTable('${type}', ${i})">${i}</a></li>`;
            }
            
            // Next button
            if (pagination.page < pagination.pages) {
                paginationHTML += `<li class="page-item"><a class="page-link" href="#" onclick="loadInventoryTable('${type}', ${pagination.page + 1})">Следующая</a></li>`;
            }
            
            paginationHTML += '</ul></nav>';
            return paginationHTML;
        }
        
        // Event listeners for inventory
        document.addEventListener('DOMContentLoaded', function() {
            // Apply filters button
            document.getElementById('applyInventoryFilters')?.addEventListener('click', function() {
                currentInventoryFilters = getCurrentInventoryFilters();
                loadInventoryTable('all');
                loadInventoryTable('ozon');
                loadInventoryTable('wb');
                loadInventoryTable('critical');
            });
            
            // Reset filters button
            document.getElementById('resetInventoryFilters')?.addEventListener('click', function() {
                document.getElementById('inventoryMarketplace').value = '';
                document.getElementById('inventoryWarehouse').value = '';
                document.getElementById('inventoryStockLevel').value = '';
                document.getElementById('inventorySearch').value = '';
                currentInventoryFilters = {};
                loadInventoryData();
            });
            
            // Marketplace change - reload warehouses
            document.getElementById('inventoryMarketplace')?.addEventListener('change', function() {
                loadWarehouses(this.value);
            });
            
            // Export CSV button
            document.getElementById('exportInventoryCSV')?.addEventListener('click', function() {
                const filters = getCurrentInventoryFilters();
                const params = new URLSearchParams({
                    action: 'export_csv',
                    ...filters
                });
                
                window.open(`inventory_api_endpoint.php?${params}`, '_blank');
            });
            
            // Refresh button
            document.getElementById('refreshInventory')?.addEventListener('click', function() {
                loadInventoryData();
            });
            
            // Tab change events
            document.querySelectorAll('#inventoryTabs button[data-bs-toggle="tab"]').forEach(tab => {
                tab.addEventListener('shown.bs.tab', function(event) {
                    const targetId = event.target.getAttribute('data-bs-target');
                    
                    switch(targetId) {
                        case '#all-inventory':
                            loadInventoryTable('all');
                            break;
                        case '#ozon-inventory':
                            loadInventoryTable('ozon');
                            break;
                        case '#wb-inventory':
                            loadInventoryTable('wb');
                            break;
                        case '#critical-inventory':
                            loadInventoryTable('critical');
                            break;
                    }
                });
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

        // Ozon Analytics functionality
        let currentAnalyticsFilters = {};
        let funnelChart = null;
        
        function loadOzonAnalyticsData() {
            loadAnalyticsFilters();
            loadFunnelData();
            // Demographics data loading is now handled by the OzonDemographics component
            loadCampaignsData();
        }
        
        function loadAnalyticsFilters() {
            // Load products for filter
            fetch('src/api/ozon-analytics.php?action=products')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const productSelect = document.getElementById('analyticsProduct');
                        productSelect.innerHTML = '<option value="">Все товары</option>';
                        
                        data.data.forEach(product => {
                            productSelect.innerHTML += `<option value="${product.id}">${product.name}</option>`;
                        });
                    }
                })
                .catch(error => console.error('Error loading products:', error));
            
            // Load campaigns for filter
            fetch('src/api/ozon-analytics.php?action=campaigns')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const campaignSelect = document.getElementById('analyticsCampaign');
                        campaignSelect.innerHTML = '<option value="">Все кампании</option>';
                        
                        data.data.forEach(campaign => {
                            campaignSelect.innerHTML += `<option value="${campaign.id}">${campaign.name}</option>`;
                        });
                    }
                })
                .catch(error => console.error('Error loading campaigns:', error));
        }
        
        function loadFunnelData() {
            // The OzonAnalyticsIntegration class now handles funnel data loading
            if (window.ozonAnalytics) {
                window.ozonAnalytics.loadFunnelData();
            }
        }
        
        // Demographics data loading is now handled by the OzonDemographics component
        
        function loadCampaignsData() {
            const filters = getCurrentAnalyticsFilters();
            const params = new URLSearchParams(filters);
            
            fetch(`src/api/ozon-analytics.php?action=campaigns-data&${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderCampaignsTable(data.data);
                    }
                })
                .catch(error => console.error('Error loading campaigns data:', error));
        }
        
        function getCurrentAnalyticsFilters() {
            return {
                start_date: document.getElementById('analyticsStartDate').value,
                end_date: document.getElementById('analyticsEndDate').value,
                product_id: document.getElementById('analyticsProduct').value,
                campaign_id: document.getElementById('analyticsCampaign').value
            };
        }
        
        function updateAnalyticsKPI(data) {
            document.getElementById('totalViews').textContent = data.total_views ? data.total_views.toLocaleString() : '-';
            document.getElementById('totalCartAdditions').textContent = data.total_cart_additions ? data.total_cart_additions.toLocaleString() : '-';
            document.getElementById('totalOrders').textContent = data.total_orders ? data.total_orders.toLocaleString() : '-';
            document.getElementById('overallConversion').textContent = data.overall_conversion ? data.overall_conversion + '%' : '-';
        }
        
        function renderFunnelChart(data) {
            // The OzonFunnelChart class now handles funnel chart rendering
            if (window.ozonAnalytics && window.ozonAnalytics.funnelChart) {
                window.ozonAnalytics.funnelChart.renderFunnel(data);
            }
        }
        
        function renderConversionMetrics(data) {
            // Conversion metrics are now handled by the OzonFunnelChart component
            // This function is kept for backward compatibility
            const totals = data.totals || data;
            const container = document.getElementById('conversionMetrics');
            
            if (container) {
                container.innerHTML = `
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Просмотры → Корзина:</span>
                            <strong>${(totals.conversion_view_to_cart || 0).toFixed(1)}%</strong>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Корзина → Заказ:</span>
                            <strong>${(totals.conversion_cart_to_order || 0).toFixed(1)}%</strong>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span>Общая конверсия:</span>
                            <strong>${(totals.conversion_overall || 0).toFixed(1)}%</strong>
                        </div>
                    </div>
                `;
            }
        }
        
        // Age, gender, and region chart rendering is now handled by the OzonDemographics component
        
        function renderCampaignsTable(campaignsData) {
            const container = document.getElementById('campaignsTable');
            
            let html = '<div class="table-responsive"><table class="table table-hover">';
            html += `
                <thead class="table-dark">
                    <tr>
                        <th>Кампания</th>
                        <th>Показы</th>
                        <th>Клики</th>
                        <th>CTR</th>
                        <th>Расходы</th>
                        <th>Заказы</th>
                        <th>Выручка</th>
                        <th>ROAS</th>
                    </tr>
                </thead>
                <tbody>
            `;
            
            campaignsData.forEach(campaign => {
                html += `
                    <tr>
                        <td><strong>${campaign.campaign_name}</strong></td>
                        <td>${campaign.impressions.toLocaleString()}</td>
                        <td>${campaign.clicks.toLocaleString()}</td>
                        <td>${campaign.ctr}%</td>
                        <td>${campaign.spend.toLocaleString()} ₽</td>
                        <td>${campaign.orders}</td>
                        <td>${campaign.revenue.toLocaleString()} ₽</td>
                        <td>${campaign.roas}</td>
                    </tr>
                `;
            });
            
            html += '</tbody></table></div>';
            container.innerHTML = html;
        }
        
        // Global variables for analytics components
        let ozonDemographics = null;
        
        // Event listeners for Ozon Analytics
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize demographics dashboard when the tab is shown
            const demographicsTab = document.getElementById('demographics-tab');
            if (demographicsTab) {
                demographicsTab.addEventListener('shown.bs.tab', function() {
                    if (!ozonDemographics) {
                        ozonDemographics = new OzonDemographics({
                            containerId: 'demographicsContainer',
                            apiBaseUrl: '/src/api/ozon-analytics.php',
                            autoRefresh: false
                        });
                    }
                });
            }
            
            // Apply filters button
            document.getElementById('applyAnalyticsFilters')?.addEventListener('click', function() {
                loadFunnelData();
                if (ozonDemographics) {
                    ozonDemographics.loadDemographicsData(true);
                }
                loadCampaignsData();
            });
            
            // Reset filters button
            document.getElementById('resetAnalyticsFilters')?.addEventListener('click', function() {
                document.getElementById('analyticsStartDate').value = '<?= $startDate ?>';
                document.getElementById('analyticsEndDate').value = '<?= $endDate ?>';
                document.getElementById('analyticsProduct').value = '';
                document.getElementById('analyticsCampaign').value = '';
                
                loadFunnelData();
                if (ozonDemographics) {
                    ozonDemographics.loadDemographicsData(true);
                }
                loadCampaignsData();
            });
            
            // Export data button
            document.getElementById('exportAnalyticsData')?.addEventListener('click', function() {
                const filters = getCurrentAnalyticsFilters();
                const params = new URLSearchParams(filters);
                
                fetch(`src/api/ozon-analytics.php?action=export&${params}`)
                    .then(response => response.blob())
                    .then(blob => {
                        const url = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.style.display = 'none';
                        a.href = url;
                        a.download = `ozon_analytics_${new Date().toISOString().split('T')[0]}.csv`;
                        document.body.appendChild(a);
                        a.click();
                        window.URL.revokeObjectURL(url);
                    })
                    .catch(error => console.error('Error exporting data:', error));
            });
            
            // Refresh button
            document.getElementById('refreshAnalytics')?.addEventListener('click', function() {
                loadOzonAnalyticsData();
                if (ozonDemographics) {
                    ozonDemographics.loadDemographicsData(true);
                }
            });
        });
    </script>
</body>
</html>