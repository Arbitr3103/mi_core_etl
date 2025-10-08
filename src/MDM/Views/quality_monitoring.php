<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?></title>
    <link rel="stylesheet" href="assets/css/quality_monitoring.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/date-fns@2.29.3/index.min.js"></script>
</head>
<body>
    <div class="container">
        <header class="header">
            <h1>Мониторинг качества данных MDM</h1>
            <div class="header-actions">
                <button id="refresh-btn" class="btn btn-primary">Обновить</button>
                <button id="force-check-btn" class="btn btn-secondary">Проверить качество</button>
                <button id="export-report-btn" class="btn btn-outline">Экспорт отчета</button>
            </div>
        </header>

        <!-- Overall Health Score -->
        <div class="health-score-section">
            <div class="health-score-card">
                <h2>Общий показатель качества</h2>
                <div class="health-score" id="overall-health-score">
                    <?= round(array_sum(array_column($current_metrics, 'overall')) / count($current_metrics), 1) ?>%
                </div>
                <div class="health-status" id="health-status">
                    <?php 
                    $overallScore = round(array_sum(array_column($current_metrics, 'overall')) / count($current_metrics), 1);
                    if ($overallScore >= 90) echo '<span class="status-excellent">Отличное</span>';
                    elseif ($overallScore >= 80) echo '<span class="status-good">Хорошее</span>';
                    elseif ($overallScore >= 70) echo '<span class="status-warning">Требует внимания</span>';
                    else echo '<span class="status-critical">Критическое</span>';
                    ?>
                </div>
            </div>
        </div>

        <!-- Key Metrics Cards -->
        <div class="metrics-grid">
            <?php foreach ($current_metrics as $metricName => $metricData): ?>
            <div class="metric-card" data-metric="<?= $metricName ?>">
                <div class="metric-header">
                    <h3><?= $this->getMetricDisplayName($metricName) ?></h3>
                    <span class="metric-value"><?= round($metricData['overall'], 1) ?>%</span>
                </div>
                <div class="metric-progress">
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $metricData['overall'] ?>%"></div>
                    </div>
                </div>
                <div class="metric-details">
                    <?php if (isset($metricData['total_products'])): ?>
                        <span>Всего товаров: <?= number_format($metricData['total_products']) ?></span>
                    <?php endif; ?>
                    <?php if (isset($metricData['total_mappings'])): ?>
                        <span>Всего сопоставлений: <?= number_format($metricData['total_mappings']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Charts Section -->
        <div class="charts-section">
            <div class="chart-container">
                <h3>Тренды качества данных</h3>
                <canvas id="quality-trends-chart"></canvas>
            </div>
            <div class="chart-container">
                <h3>Точность автоматического сопоставления</h3>
                <canvas id="matching-accuracy-chart"></canvas>
            </div>
        </div>

        <!-- Coverage by Source -->
        <div class="coverage-section">
            <h3>Покрытие мастер-данными по источникам</h3>
            <div class="coverage-table">
                <table>
                    <thead>
                        <tr>
                            <th>Источник</th>
                            <th>Всего SKU</th>
                            <th>Сопоставлено</th>
                            <th>Покрытие</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coverage_by_source as $source): ?>
                        <tr>
                            <td><?= htmlspecialchars($source['source']) ?></td>
                            <td><?= number_format($source['total_skus']) ?></td>
                            <td><?= number_format($source['mapped_skus']) ?></td>
                            <td>
                                <div class="coverage-bar">
                                    <div class="coverage-fill" style="width: <?= $source['coverage_percentage'] ?>%"></div>
                                    <span><?= $source['coverage_percentage'] ?>%</span>
                                </div>
                            </td>
                            <td>
                                <?php if ($source['coverage_percentage'] >= 90): ?>
                                    <span class="status-badge status-excellent">Отлично</span>
                                <?php elseif ($source['coverage_percentage'] >= 80): ?>
                                    <span class="status-badge status-good">Хорошо</span>
                                <?php elseif ($source['coverage_percentage'] >= 70): ?>
                                    <span class="status-badge status-warning">Внимание</span>
                                <?php else: ?>
                                    <span class="status-badge status-critical">Критично</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Attribute Completeness -->
        <div class="completeness-section">
            <h3>Полнота заполнения атрибутов</h3>
            <div class="completeness-grid">
                <?php foreach ($attribute_completeness as $attr): ?>
                <div class="completeness-card">
                    <h4><?= $this->getAttributeDisplayName($attr['attribute_name']) ?></h4>
                    <div class="completeness-chart">
                        <div class="donut-chart" data-percentage="<?= $attr['completeness_percentage'] ?>">
                            <span class="percentage"><?= $attr['completeness_percentage'] ?>%</span>
                        </div>
                    </div>
                    <div class="completeness-stats">
                        <span>Заполнено: <?= number_format($attr['filled_count']) ?></span>
                        <span>Всего: <?= number_format($attr['total_products']) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Alerts -->
        <div class="alerts-section">
            <h3>Последние уведомления</h3>
            <div class="alerts-container" id="alerts-container">
                <?php if (empty($recent_alerts)): ?>
                    <div class="no-alerts">
                        <p>Нет активных уведомлений</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($recent_alerts as $alert): ?>
                    <div class="alert-item alert-<?= $alert['severity'] ?>">
                        <div class="alert-header">
                            <span class="alert-type"><?= $this->getAlertTypeDisplayName($alert['alert_type']) ?></span>
                            <span class="alert-time"><?= date('d.m.Y H:i', strtotime($alert['created_at'])) ?></span>
                        </div>
                        <div class="alert-title"><?= htmlspecialchars($alert['title']) ?></div>
                        <div class="alert-description"><?= htmlspecialchars($alert['description']) ?></div>
                        <?php if ($alert['affected_records']): ?>
                            <div class="alert-affected">Затронуто записей: <?= number_format($alert['affected_records']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Problematic Products Drill-down -->
        <div class="drill-down-section">
            <h3>Проблемные товары</h3>
            <div class="drill-down-tabs">
                <button class="tab-btn active" data-issue="unknown_brand">Неизвестный бренд</button>
                <button class="tab-btn" data-issue="no_category">Без категории</button>
                <button class="tab-btn" data-issue="no_description">Без описания</button>
                <button class="tab-btn" data-issue="pending_verification">Ожидают проверки</button>
            </div>
            <div class="drill-down-content" id="drill-down-content">
                <!-- Content will be loaded dynamically -->
            </div>
        </div>

        <!-- Scheduler Status -->
        <div class="scheduler-section">
            <h3>Статус планировщика</h3>
            <div class="scheduler-table">
                <table>
                    <thead>
                        <tr>
                            <th>Проверка</th>
                            <th>Статус</th>
                            <th>Интервал</th>
                            <th>Последний запуск</th>
                            <th>Результат</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scheduler_status as $status): ?>
                        <tr>
                            <td><?= htmlspecialchars($status['description'] ?? $status['check_name']) ?></td>
                            <td>
                                <label class="toggle-switch">
                                    <input type="checkbox" <?= $status['enabled'] ? 'checked' : '' ?> 
                                           data-check="<?= $status['check_name'] ?>">
                                    <span class="toggle-slider"></span>
                                </label>
                            </td>
                            <td><?= gmdate('H:i:s', $status['interval_seconds']) ?></td>
                            <td><?= $status['last_run'] ? date('d.m.Y H:i', strtotime($status['last_run'])) : 'Никогда' ?></td>
                            <td>
                                <?php if ($status['last_execution_status']): ?>
                                    <span class="status-badge status-<?= $status['last_execution_status'] ?>">
                                        <?= ucfirst($status['last_execution_status']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="status-badge status-unknown">Неизвестно</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline" 
                                        onclick="forceRunCheck('<?= $status['check_name'] ?>')">
                                    Запустить
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="assets/js/quality_monitoring.js"></script>
    <script>
        // Initialize dashboard with data
        const dashboardData = {
            currentMetrics: <?= json_encode($current_metrics) ?>,
            recentAlerts: <?= json_encode($recent_alerts) ?>,
            performanceSummary: <?= json_encode($performance_summary) ?>,
            schedulerStatus: <?= json_encode($scheduler_status) ?>
        };
        
        // Initialize dashboard
        document.addEventListener('DOMContentLoaded', function() {
            initializeQualityMonitoringDashboard(dashboardData);
        });
    </script>
</body>
</html>

<?php
// Helper methods for display names
function getMetricDisplayName($metricName) {
    $names = [
        'completeness' => 'Полнота данных',
        'accuracy' => 'Точность',
        'consistency' => 'Согласованность',
        'coverage' => 'Покрытие',
        'freshness' => 'Актуальность',
        'matching_performance' => 'Производительность сопоставления',
        'system_performance' => 'Производительность системы'
    ];
    return $names[$metricName] ?? ucfirst($metricName);
}

function getAttributeDisplayName($attributeName) {
    $names = [
        'canonical_name' => 'Название',
        'canonical_brand' => 'Бренд',
        'canonical_category' => 'Категория',
        'description' => 'Описание',
        'attributes' => 'Дополнительные атрибуты'
    ];
    return $names[$attributeName] ?? ucfirst($attributeName);
}

function getAlertTypeDisplayName($alertType) {
    $names = [
        'completeness' => 'Полнота',
        'accuracy' => 'Точность',
        'consistency' => 'Согласованность',
        'coverage' => 'Покрытие',
        'performance' => 'Производительность'
    ];
    return $names[$alertType] ?? ucfirst($alertType);
}
?>