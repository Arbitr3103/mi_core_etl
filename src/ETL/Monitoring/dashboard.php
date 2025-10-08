<?php
/**
 * ETL Monitoring Dashboard
 * Веб-интерфейс для мониторинга ETL процессов
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/ETLDashboardController.php';

use MDM\ETL\Monitoring\ETLDashboardController;

// Инициализация
try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $dashboardController = new ETLDashboardController($pdo);
    
} catch (Exception $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Обработка AJAX запросов
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_GET['action']) {
            case 'dashboard_data':
                echo json_encode($dashboardController->getDashboardData());
                break;
                
            case 'task_details':
                $sessionId = $_GET['session_id'] ?? '';
                $details = $dashboardController->getTaskDetails($sessionId);
                echo json_encode($details ?: ['error' => 'Task not found']);
                break;
                
            case 'task_history':
                $page = (int)($_GET['page'] ?? 1);
                $filters = $_GET['filters'] ?? [];
                echo json_encode($dashboardController->getTaskHistoryPaginated($filters, $page));
                break;
                
            case 'analytics':
                $days = (int)($_GET['days'] ?? 7);
                echo json_encode($dashboardController->getAnalyticsData($days));
                break;
                
            default:
                echo json_encode(['error' => 'Unknown action']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Получаем начальные данные для страницы
$dashboardData = $dashboardController->getDashboardData();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ETL Monitoring Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .status-badge {
            font-size: 0.8em;
            padding: 0.25em 0.5em;
        }
        .status-running { background-color: #007bff; }
        .status-success { background-color: #28a745; }
        .status-error { background-color: #dc3545; }
        .status-cancelled { background-color: #6c757d; }
        
        .health-healthy { color: #28a745; }
        .health-warning { color: #ffc107; }
        .health-unhealthy { color: #dc3545; }
        
        .metric-card {
            transition: transform 0.2s;
        }
        .metric-card:hover {
            transform: translateY(-2px);
        }
        
        .progress-thin {
            height: 4px;
        }
        
        .alert-item {
            border-left: 4px solid;
            margin-bottom: 0.5rem;
        }
        .alert-error { border-left-color: #dc3545; }
        .alert-warning { border-left-color: #ffc107; }
        .alert-info { border-left-color: #17a2b8; }
        
        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 0.85em;
            margin-bottom: 0.25rem;
            padding: 0.25rem;
            border-radius: 3px;
        }
        .log-error { background-color: #f8d7da; }
        .log-warning { background-color: #fff3cd; }
        .log-info { background-color: #d1ecf1; }
        
        .refresh-indicator {
            display: none;
            color: #007bff;
        }
        
        .task-row {
            cursor: pointer;
        }
        .task-row:hover {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <i class="fas fa-tachometer-alt"></i> ETL Monitoring
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text">
                    <i class="fas fa-sync-alt refresh-indicator" id="refreshIndicator"></i>
                    Последнее обновление: <span id="lastUpdate"><?= date('H:i:s') ?></span>
                </span>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-3">
        <!-- Быстрая статистика -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <h5 class="card-title text-muted">Задач за 24ч</h5>
                        <h2 class="text-primary" id="tasks24h"><?= $dashboardData['quick_stats']['total_tasks_24h'] ?? 0 ?></h2>
                        <small class="text-muted">
                            <i class="fas fa-arrow-up text-success"></i>
                            <span id="tasksChange">+0%</span> от вчера
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <h5 class="card-title text-muted">Процент успеха</h5>
                        <h2 class="text-success" id="successRate"><?= $dashboardData['quick_stats']['success_rate_24h'] ?? 0 ?>%</h2>
                        <small class="text-muted">
                            <i class="fas fa-arrow-up text-success"></i>
                            <span id="successRateChange">+0%</span> от недели
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <h5 class="card-title text-muted">Активных задач</h5>
                        <h2 class="text-info" id="activeTasks"><?= $dashboardData['quick_stats']['running_tasks'] ?? 0 ?></h2>
                        <small class="text-muted">
                            <i class="fas fa-clock"></i>
                            Выполняется сейчас
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <h5 class="card-title text-muted">Среднее время</h5>
                        <h2 class="text-warning" id="avgDuration"><?= round(($dashboardData['quick_stats']['avg_duration_24h'] ?? 0) / 60, 1) ?>м</h2>
                        <small class="text-muted">
                            <i class="fas fa-stopwatch"></i>
                            За 24 часа
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Активные задачи -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-tasks"></i> Активные задачи
                        </h5>
                        <button class="btn btn-sm btn-outline-primary" onclick="refreshActiveTasks()">
                            <i class="fas fa-sync-alt"></i> Обновить
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="activeTasksList">
                            <?php if (empty($dashboardData['active_tasks'])): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-check-circle fa-3x mb-3"></i>
                                    <p>Нет активных задач</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($dashboardData['active_tasks'] as $task): ?>
                                    <div class="task-row border-bottom py-2" onclick="showTaskDetails('<?= $task['session_id'] ?>')">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?= htmlspecialchars($task['task_type']) ?></strong>
                                                <small class="text-muted">(<?= htmlspecialchars($task['task_id']) ?>)</small>
                                                <br>
                                                <small class="text-muted">
                                                    <?= $task['current_step'] ? htmlspecialchars($task['current_step']) : 'Выполняется...' ?>
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge status-badge status-<?= $task['status'] ?>">
                                                    <?= ucfirst($task['status']) ?>
                                                </span>
                                                <br>
                                                <small class="text-muted"><?= $task['running_seconds'] ?>с</small>
                                            </div>
                                        </div>
                                        <?php if ($task['progress_percent'] > 0): ?>
                                            <div class="progress progress-thin mt-2">
                                                <div class="progress-bar" style="width: <?= $task['progress_percent'] ?>%"></div>
                                            </div>
                                            <small class="text-muted">
                                                <?= number_format($task['records_processed']) ?> / <?= number_format($task['records_total']) ?>
                                                (<?= $task['progress_percent'] ?>%)
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- График производительности -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line"></i> Производительность за неделю
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="performanceChart" height="100"></canvas>
                    </div>
                </div>
            </div>

            <!-- Боковая панель -->
            <div class="col-md-4">
                <!-- Состояние системы -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-heartbeat"></i> Состояние системы
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span>Общий статус:</span>
                            <span class="health-<?= $dashboardData['system_health']['overall_status'] ?? 'unknown' ?>">
                                <i class="fas fa-circle"></i>
                                <?= ucfirst($dashboardData['system_health']['overall_status'] ?? 'unknown') ?>
                            </span>
                        </div>
                        
                        <?php if (!empty($dashboardData['system_health']['components'])): ?>
                            <?php foreach ($dashboardData['system_health']['components'] as $component => $status): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <small><?= ucfirst(str_replace('_', ' ', $component)) ?>:</small>
                                    <small class="health-<?= $status ?>">
                                        <i class="fas fa-circle"></i> <?= ucfirst($status) ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if (!empty($dashboardData['system_health']['issues'])): ?>
                            <hr>
                            <h6 class="text-warning">Проблемы:</h6>
                            <?php foreach ($dashboardData['system_health']['issues'] as $issue): ?>
                                <small class="text-muted d-block">• <?= htmlspecialchars($issue) ?></small>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Алерты -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-exclamation-triangle"></i> Алерты
                        </h5>
                    </div>
                    <div class="card-body">
                        <div id="alertsList">
                            <?php if (empty($dashboardData['system_alerts'])): ?>
                                <div class="text-center text-muted">
                                    <i class="fas fa-check-circle text-success"></i>
                                    <p class="mb-0">Нет активных алертов</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($dashboardData['system_alerts'] as $alert): ?>
                                    <div class="alert-item alert-<?= $alert['type'] ?> p-2 mb-2 bg-light">
                                        <small class="fw-bold"><?= htmlspecialchars($alert['message']) ?></small>
                                        <br>
                                        <small class="text-muted">
                                            Серьезность: <?= $alert['severity'] ?>
                                        </small>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Последние задачи -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-history"></i> Последние задачи
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($dashboardData['recent_tasks'])): ?>
                            <p class="text-muted text-center">Нет данных</p>
                        <?php else: ?>
                            <?php foreach (array_slice($dashboardData['recent_tasks'], 0, 5) as $task): ?>
                                <div class="task-row border-bottom py-2" onclick="showTaskDetails('<?= $task['session_id'] ?>')">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <small class="fw-bold"><?= htmlspecialchars($task['task_type']) ?></small>
                                            <br>
                                            <small class="text-muted">
                                                <?= date('H:i', strtotime($task['started_at'])) ?>
                                            </small>
                                        </div>
                                        <span class="badge status-badge status-<?= $task['status'] ?>">
                                            <?= ucfirst($task['status']) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно деталей задачи -->
    <div class="modal fade" id="taskDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Детали задачи</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="taskDetailsContent">
                    <div class="text-center">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Загрузка...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Глобальные переменные
        let refreshInterval;
        let performanceChart;

        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            initializePerformanceChart();
            startAutoRefresh();
        });

        // Автоматическое обновление данных
        function startAutoRefresh() {
            refreshInterval = setInterval(function() {
                refreshDashboardData();
            }, 30000); // Обновление каждые 30 секунд
        }

        // Обновление данных dashboard
        function refreshDashboardData() {
            document.getElementById('refreshIndicator').style.display = 'inline';
            
            fetch('?action=dashboard_data')
                .then(response => response.json())
                .then(data => {
                    updateQuickStats(data.quick_stats);
                    updateActiveTasks(data.active_tasks);
                    updateSystemHealth(data.system_health);
                    updateAlerts(data.system_alerts);
                    updateLastUpdateTime();
                })
                .catch(error => {
                    console.error('Ошибка обновления данных:', error);
                })
                .finally(() => {
                    document.getElementById('refreshIndicator').style.display = 'none';
                });
        }

        // Обновление быстрой статистики
        function updateQuickStats(stats) {
            if (stats) {
                document.getElementById('tasks24h').textContent = stats.total_tasks_24h || 0;
                document.getElementById('successRate').textContent = (stats.success_rate_24h || 0) + '%';
                document.getElementById('activeTasks').textContent = stats.running_tasks || 0;
                document.getElementById('avgDuration').textContent = Math.round((stats.avg_duration_24h || 0) / 60 * 10) / 10 + 'м';
            }
        }

        // Обновление списка активных задач
        function updateActiveTasks(tasks) {
            const container = document.getElementById('activeTasksList');
            
            if (!tasks || tasks.length === 0) {
                container.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-check-circle fa-3x mb-3"></i>
                        <p>Нет активных задач</p>
                    </div>
                `;
                return;
            }

            let html = '';
            tasks.forEach(task => {
                html += `
                    <div class="task-row border-bottom py-2" onclick="showTaskDetails('${task.session_id}')">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>${escapeHtml(task.task_type)}</strong>
                                <small class="text-muted">(${escapeHtml(task.task_id)})</small>
                                <br>
                                <small class="text-muted">
                                    ${task.current_step ? escapeHtml(task.current_step) : 'Выполняется...'}
                                </small>
                            </div>
                            <div class="text-end">
                                <span class="badge status-badge status-${task.status}">
                                    ${task.status.charAt(0).toUpperCase() + task.status.slice(1)}
                                </span>
                                <br>
                                <small class="text-muted">${task.running_seconds}с</small>
                            </div>
                        </div>
                `;
                
                if (task.progress_percent > 0) {
                    html += `
                        <div class="progress progress-thin mt-2">
                            <div class="progress-bar" style="width: ${task.progress_percent}%"></div>
                        </div>
                        <small class="text-muted">
                            ${numberFormat(task.records_processed)} / ${numberFormat(task.records_total)}
                            (${task.progress_percent}%)
                        </small>
                    `;
                }
                
                html += '</div>';
            });
            
            container.innerHTML = html;
        }

        // Обновление состояния системы
        function updateSystemHealth(health) {
            // Реализация обновления состояния системы
        }

        // Обновление алертов
        function updateAlerts(alerts) {
            // Реализация обновления алертов
        }

        // Показать детали задачи
        function showTaskDetails(sessionId) {
            const modal = new bootstrap.Modal(document.getElementById('taskDetailsModal'));
            const content = document.getElementById('taskDetailsContent');
            
            content.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                </div>
            `;
            
            modal.show();
            
            fetch(`?action=task_details&session_id=${sessionId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        content.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
                        return;
                    }
                    
                    content.innerHTML = generateTaskDetailsHTML(data);
                })
                .catch(error => {
                    content.innerHTML = `<div class="alert alert-danger">Ошибка загрузки: ${error.message}</div>`;
                });
        }

        // Генерация HTML для деталей задачи
        function generateTaskDetailsHTML(task) {
            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Основная информация</h6>
                        <table class="table table-sm">
                            <tr><td>ID сессии:</td><td><code>${task.session_id}</code></td></tr>
                            <tr><td>ID задачи:</td><td><code>${task.task_id}</code></td></tr>
                            <tr><td>Тип:</td><td>${task.task_type}</td></tr>
                            <tr><td>Статус:</td><td><span class="badge status-badge status-${task.status}">${task.status}</span></td></tr>
                            <tr><td>Начало:</td><td>${formatDateTime(task.started_at)}</td></tr>
                            ${task.finished_at ? `<tr><td>Завершение:</td><td>${formatDateTime(task.finished_at)}</td></tr>` : ''}
                            ${task.duration_seconds ? `<tr><td>Длительность:</td><td>${Math.round(task.duration_seconds / 60 * 10) / 10} мин</td></tr>` : ''}
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6>Прогресс</h6>
                        <table class="table table-sm">
                            <tr><td>Обработано:</td><td>${numberFormat(task.records_processed)}</td></tr>
                            <tr><td>Всего:</td><td>${numberFormat(task.records_total)}</td></tr>
                            <tr><td>Прогресс:</td><td>${task.progress_percent}%</td></tr>
                            ${task.current_step ? `<tr><td>Текущий шаг:</td><td>${task.current_step}</td></tr>` : ''}
                        </table>
                    </div>
                </div>
            `;

            if (task.logs && task.logs.length > 0) {
                html += `
                    <h6 class="mt-3">Логи</h6>
                    <div style="max-height: 300px; overflow-y: auto;">
                `;
                
                task.logs.slice(0, 20).forEach(log => {
                    html += `
                        <div class="log-entry log-${log.level.toLowerCase()}">
                            <small class="text-muted">${formatDateTime(log.created_at)}</small>
                            <strong>[${log.level}]</strong>
                            ${escapeHtml(log.message)}
                        </div>
                    `;
                });
                
                html += '</div>';
            }

            return html;
        }

        // Инициализация графика производительности
        function initializePerformanceChart() {
            const ctx = document.getElementById('performanceChart').getContext('2d');
            
            // Получаем данные для графика
            fetch('?action=analytics&days=7')
                .then(response => response.json())
                .then(data => {
                    const trends = data.performance_trends || [];
                    
                    performanceChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: trends.map(t => formatDate(t.date)),
                            datasets: [{
                                label: 'Среднее время выполнения (мин)',
                                data: trends.map(t => Math.round(t.avg_duration / 60 * 10) / 10),
                                borderColor: 'rgb(75, 192, 192)',
                                backgroundColor: 'rgba(75, 192, 192, 0.1)',
                                tension: 0.1
                            }, {
                                label: 'Количество задач',
                                data: trends.map(t => t.task_count),
                                borderColor: 'rgb(255, 99, 132)',
                                backgroundColor: 'rgba(255, 99, 132, 0.1)',
                                yAxisID: 'y1',
                                tension: 0.1
                            }]
                        },
                        options: {
                            responsive: true,
                            scales: {
                                y: {
                                    type: 'linear',
                                    display: true,
                                    position: 'left',
                                    title: {
                                        display: true,
                                        text: 'Время (мин)'
                                    }
                                },
                                y1: {
                                    type: 'linear',
                                    display: true,
                                    position: 'right',
                                    title: {
                                        display: true,
                                        text: 'Количество задач'
                                    },
                                    grid: {
                                        drawOnChartArea: false,
                                    },
                                }
                            }
                        }
                    });
                })
                .catch(error => {
                    console.error('Ошибка загрузки данных графика:', error);
                });
        }

        // Обновление активных задач
        function refreshActiveTasks() {
            fetch('?action=dashboard_data')
                .then(response => response.json())
                .then(data => {
                    updateActiveTasks(data.active_tasks);
                })
                .catch(error => {
                    console.error('Ошибка обновления активных задач:', error);
                });
        }

        // Обновление времени последнего обновления
        function updateLastUpdateTime() {
            document.getElementById('lastUpdate').textContent = new Date().toLocaleTimeString();
        }

        // Вспомогательные функции
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function numberFormat(num) {
            return new Intl.NumberFormat().format(num);
        }

        function formatDateTime(dateStr) {
            return new Date(dateStr).toLocaleString();
        }

        function formatDate(dateStr) {
            return new Date(dateStr).toLocaleDateString();
        }

        // Остановка автообновления при закрытии страницы
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>
</body>
</html>