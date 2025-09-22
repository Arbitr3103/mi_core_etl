<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Дашборд маржинальности</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .kpi-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            margin-bottom: 20px;
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
    </style>
</head>
<body>
    <?php
    require_once 'MarginDashboardAPI.php';
    
    // Настройки подключения к БД
    $api = new MarginDashboardAPI('localhost', 'mi_core_db', 'username', 'password');
    
    // Параметры периода
    $startDate = $_GET['start_date'] ?? date('Y-m-01');
    $endDate = $_GET['end_date'] ?? date('Y-m-d');
    $clientId = $_GET['client_id'] ?? null;
    
    // Получаем данные
    try {
        $kpiMetrics = $api->getKPIMetrics($startDate, $endDate, $clientId);
        $chartData = $api->getDailyMarginChart($startDate, $endDate, $clientId);
        $costBreakdown = $api->getCostBreakdown($startDate, $endDate, $clientId);
        $topDays = $api->getTopMarginDays($startDate, $endDate, 5, $clientId);
        $clients = $api->getClients();
        $trend = $api->getMarginTrend($startDate, $endDate, $clientId);
        
        $dataLoaded = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
        $dataLoaded = false;
    }
    ?>

    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">📊 Дашборд маржинальности</h1>
                
                <?php if (!$dataLoaded): ?>
                    <div class="alert alert-danger">
                        <strong>Ошибка:</strong> <?= htmlspecialchars($error) ?>
                    </div>
                <?php else: ?>
                
                <!-- Фильтры -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Начальная дата</label>
                                <input type="date" class="form-control" name="start_date" value="<?= $startDate ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Конечная дата</label>
                                <input type="date" class="form-control" name="end_date" value="<?= $endDate ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Клиент</label>
                                <select class="form-control" name="client_id">
                                    <option value="">Все клиенты</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['id'] ?>" <?= $clientId == $client['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($client['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary d-block w-100">Применить</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- KPI метрики -->
                <div class="row mb-4">
                    <?php foreach ($kpiMetrics as $key => $metric): ?>
                        <div class="col-md-2">
                            <div class="card kpi-card">
                                <div class="card-body text-center">
                                    <div class="kpi-value">
                                        <?= $metric['value'] ?>
                                        <?= $metric['format'] === 'percent' ? '%' : '' ?>
                                        <?= $metric['format'] === 'currency' ? ' ₽' : '' ?>
                                    </div>
                                    <div class="small"><?= $metric['label'] ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Тренд маржинальности -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="alert alert-info">
                            <strong>Тренд маржинальности:</strong>
                            <span class="trend-<?= $trend['trend'] ?>">
                                <?php
                                switch($trend['trend']) {
                                    case 'up': echo '📈 Рост'; break;
                                    case 'down': echo '📉 Снижение'; break;
                                    default: echo '➡️ Стабильно'; break;
                                }
                                ?>
                                <?= $trend['change'] != 0 ? ' (' . ($trend['change'] > 0 ? '+' : '') . $trend['change'] . '%)' : '' ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Графики -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5>📈 Динамика маржинальности</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="marginChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5>🥧 Структура расходов</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="costChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Топ дней и детализация -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>🏆 Топ дней по маржинальности</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Дата</th>
                                                <th>Выручка</th>
                                                <th>Прибыль</th>
                                                <th>Маржа</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($topDays as $day): ?>
                                                <tr>
                                                    <td><?= date('d.m.Y', strtotime($day['metric_date'])) ?></td>
                                                    <td><?= number_format($day['revenue'], 0) ?> ₽</td>
                                                    <td><?= number_format($day['profit'], 0) ?> ₽</td>
                                                    <td>
                                                        <span class="badge bg-<?= $day['margin_percent'] > 20 ? 'success' : ($day['margin_percent'] > 10 ? 'warning' : 'danger') ?>">
                                                            <?= $day['margin_percent'] ?>%
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5>💰 Детализация расходов</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6">
                                        <strong>Себестоимость:</strong><br>
                                        <?= number_format($costBreakdown['cogs'], 2) ?> ₽ 
                                        <small class="text-muted">(<?= $costBreakdown['cogs_percent'] ?>%)</small>
                                    </div>
                                    <div class="col-6">
                                        <strong>Комиссии:</strong><br>
                                        <?= number_format($costBreakdown['commission'], 2) ?> ₽ 
                                        <small class="text-muted">(<?= $costBreakdown['commission_percent'] ?>%)</small>
                                    </div>
                                </div>
                                <hr>
                                <div class="row">
                                    <div class="col-6">
                                        <strong>Логистика:</strong><br>
                                        <?= number_format($costBreakdown['shipping'], 2) ?> ₽ 
                                        <small class="text-muted">(<?= $costBreakdown['shipping_percent'] ?>%)</small>
                                    </div>
                                    <div class="col-6">
                                        <strong>Прочие расходы:</strong><br>
                                        <?= number_format($costBreakdown['other_expenses'], 2) ?> ₽ 
                                        <small class="text-muted">(<?= $costBreakdown['other_expenses_percent'] ?>%)</small>
                                    </div>
                                </div>
                                <hr>
                                <div class="text-center">
                                    <strong class="text-success">Чистая прибыль:</strong><br>
                                    <h4 class="text-success"><?= number_format($costBreakdown['profit'], 2) ?> ₽</h4>
                                    <small class="text-muted">(<?= $costBreakdown['profit_percent'] ?>% от выручки)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        <?php if ($dataLoaded): ?>
        // График динамики маржинальности
        const marginCtx = document.getElementById('marginChart').getContext('2d');
        const marginChart = new Chart(marginCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_map(function($item) { 
                    return date('d.m', strtotime($item['metric_date'])); 
                }, $chartData)) ?>,
                datasets: [{
                    label: 'Выручка (₽)',
                    data: <?= json_encode(array_column($chartData, 'revenue')) ?>,
                    borderColor: 'rgb(75, 192, 192)',
                    backgroundColor: 'rgba(75, 192, 192, 0.1)',
                    yAxisID: 'y',
                    tension: 0.1
                }, {
                    label: 'Прибыль (₽)',
                    data: <?= json_encode(array_column($chartData, 'profit')) ?>,
                    borderColor: 'rgb(255, 99, 132)',
                    backgroundColor: 'rgba(255, 99, 132, 0.1)',
                    yAxisID: 'y',
                    tension: 0.1
                }, {
                    label: 'Маржинальность (%)',
                    data: <?= json_encode(array_column($chartData, 'margin_percent')) ?>,
                    borderColor: 'rgb(54, 162, 235)',
                    backgroundColor: 'rgba(54, 162, 235, 0.1)',
                    yAxisID: 'y1',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Сумма (₽)'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Маржинальность (%)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'Динамика выручки, прибыли и маржинальности'
                    }
                }
            }
        });

        // График структуры расходов
        const costCtx = document.getElementById('costChart').getContext('2d');
        const costChart = new Chart(costCtx, {
            type: 'doughnut',
            data: {
                labels: ['Себестоимость', 'Комиссии', 'Логистика', 'Прочие расходы', 'Прибыль'],
                datasets: [{
                    data: [
                        <?= $costBreakdown['cogs'] ?>,
                        <?= $costBreakdown['commission'] ?>,
                        <?= $costBreakdown['shipping'] ?>,
                        <?= $costBreakdown['other_expenses'] ?>,
                        <?= $costBreakdown['profit'] ?>
                    ],
                    backgroundColor: [
                        '#FF6384',
                        '#36A2EB',
                        '#FFCE56',
                        '#FF9F40',
                        '#4BC0C0'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `${label}: ${value.toLocaleString('ru-RU')} ₽ (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>