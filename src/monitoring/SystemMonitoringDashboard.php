<?php
/**
 * SystemMonitoringDashboard Class - Система мониторинга ETL процессов
 * 
 * Предоставляет веб-интерфейс для мониторинга состояния ETL процессов,
 * отображения метрик производительности и исторических трендов.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class SystemMonitoringDashboard {
    
    private $pdo;
    private $monitor;
    private $config;
    
    /**
     * Конструктор
     * 
     * @param PDO $pdo - подключение к базе данных
     * @param OzonETLMonitor $monitor - экземпляр монитора ETL
     * @param array $config - конфигурация дашборда
     */
    public function __construct(PDO $pdo, OzonETLMonitor $monitor, array $config = []) {
        $this->pdo = $pdo;
        $this->monitor = $monitor;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }
    
    /**
     * Получение конфигурации по умолчанию
     */
    private function getDefaultConfig(): array {
        return [
            'refresh_interval_seconds' => 30,
            'chart_data_points' => 24, // 24 часа по умолчанию
            'alert_display_limit' => 50,
            'process_display_limit' => 100,
            'enable_real_time' => true,
            'theme' => 'light'
        ];
    }
    
    /**
     * Генерация HTML дашборда
     * 
     * @return string HTML код дашборда
     */
    public function generateDashboard(): string {
        $dashboardData = $this->getDashboardData();
        
        $html = $this->generateHTMLHeader();
        $html .= $this->generateDashboardBody($dashboardData);
        $html .= $this->generateHTMLFooter();
        
        return $html;
    }
    
    /**
     * Получение данных для дашборда
     */
    private function getDashboardData(): array {
        return [
            'overview' => $this->getSystemOverview(),
            'current_processes' => $this->getCurrentProcesses(),
            'recent_alerts' => $this->getRecentAlerts(),
            'performance_metrics' => $this->getPerformanceMetrics(),
            'health_status' => $this->monitor->performHealthCheck(),
            'historical_trends' => $this->getHistoricalTrends(),
            'system_resources' => $this->getSystemResources()
        ];
    }
    
    /**
     * Получение обзора системы
     */
    private function getSystemOverview(): array {
        try {
            $sql = "SELECT 
                        COUNT(CASE WHEN status = 'running' THEN 1 END) as running_processes,
                        COUNT(CASE WHEN status = 'completed' AND DATE(completed_at) = CURDATE() THEN 1 END) as completed_today,
                        COUNT(CASE WHEN status IN ('failed', 'timeout', 'stalled') AND DATE(started_at) = CURDATE() THEN 1 END) as failed_today,
                        AVG(CASE WHEN status = 'completed' AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN duration_seconds END) as avg_duration_24h
                    FROM ozon_etl_monitoring";
            
            $stmt = $this->pdo->query($sql);
            $overview = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Добавляем статистику по уведомлениям
            $alertsStmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total_alerts_24h,
                    COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_alerts_24h
                FROM ozon_etl_alerts 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $alertsData = $alertsStmt->fetch(PDO::FETCH_ASSOC);
            
            return array_merge($overview, $alertsData);
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Получение текущих процессов
     */
    private function getCurrentProcesses(): array {
        try {
            $sql = "SELECT 
                        etl_id,
                        process_type,
                        status,
                        started_at,
                        last_heartbeat,
                        TIMESTAMPDIFF(MINUTE, started_at, NOW()) as running_minutes,
                        TIMESTAMPDIFF(MINUTE, last_heartbeat, NOW()) as minutes_since_heartbeat,
                        memory_usage_mb,
                        cpu_usage_percent,
                        hostname
                    FROM ozon_etl_monitoring 
                    WHERE status IN ('running', 'stalled', 'timeout')
                    ORDER BY started_at DESC
                    LIMIT :limit";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['limit' => $this->config['process_display_limit']]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Получение недавних уведомлений
     */
    private function getRecentAlerts(): array {
        try {
            $sql = "SELECT 
                        alert_type,
                        severity,
                        title,
                        message,
                        etl_id,
                        created_at,
                        is_resolved,
                        resolved_at
                    FROM ozon_etl_alerts 
                    ORDER BY created_at DESC
                    LIMIT :limit";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['limit' => $this->config['alert_display_limit']]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Получение метрик производительности
     */
    private function getPerformanceMetrics(): array {
        try {
            $sql = "SELECT 
                        DATE(started_at) as date,
                        COUNT(*) as total_runs,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_runs,
                        AVG(duration_seconds) as avg_duration,
                        MAX(duration_seconds) as max_duration,
                        AVG(memory_usage_mb) as avg_memory_usage,
                        MAX(memory_usage_mb) as max_memory_usage
                    FROM ozon_etl_monitoring 
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    AND status IN ('completed', 'failed', 'timeout')
                    GROUP BY DATE(started_at)
                    ORDER BY date DESC";
            
            $stmt = $this->pdo->query($sql);
            $metrics = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Вычисляем процент успешности
            foreach ($metrics as &$metric) {
                $metric['success_rate'] = $metric['total_runs'] > 0 
                    ? round(($metric['successful_runs'] / $metric['total_runs']) * 100, 2)
                    : 0;
                $metric['avg_duration_minutes'] = round($metric['avg_duration'] / 60, 2);
                $metric['max_duration_minutes'] = round($metric['max_duration'] / 60, 2);
            }
            
            return $metrics;
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Получение исторических трендов
     */
    private function getHistoricalTrends(): array {
        try {
            // Тренды по часам за последние 24 часа
            $sql = "SELECT 
                        DATE_FORMAT(started_at, '%Y-%m-%d %H:00:00') as hour,
                        COUNT(*) as total_runs,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_runs,
                        AVG(duration_seconds) as avg_duration
                    FROM ozon_etl_monitoring 
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    GROUP BY DATE_FORMAT(started_at, '%Y-%m-%d %H:00:00')
                    ORDER BY hour";
            
            $stmt = $this->pdo->query($sql);
            $hourlyTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Тренды по дням за последние 30 дней
            $sql = "SELECT 
                        DATE(started_at) as date,
                        COUNT(*) as total_runs,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_runs,
                        AVG(duration_seconds) as avg_duration
                    FROM ozon_etl_monitoring 
                    WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY DATE(started_at)
                    ORDER BY date";
            
            $stmt = $this->pdo->query($sql);
            $dailyTrends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'hourly' => $hourlyTrends,
                'daily' => $dailyTrends
            ];
            
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * Получение информации о системных ресурсах
     */
    private function getSystemResources(): array {
        $resources = [];
        
        try {
            // Использование памяти
            $resources['memory'] = [
                'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
                'peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'limit' => ini_get('memory_limit')
            ];
            
            // Загрузка системы
            if (function_exists('sys_getloadavg')) {
                $load = sys_getloadavg();
                $resources['system_load'] = [
                    '1min' => round($load[0], 2),
                    '5min' => round($load[1], 2),
                    '15min' => round($load[2], 2)
                ];
            }
            
            // Информация о диске
            $resources['disk'] = [
                'free_space_gb' => round(disk_free_space('.') / 1024 / 1024 / 1024, 2),
                'total_space_gb' => round(disk_total_space('.') / 1024 / 1024 / 1024, 2)
            ];
            
            // Статистика базы данных
            $stmt = $this->pdo->query("SHOW TABLE STATUS");
            $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $totalSize = 0;
            $totalRows = 0;
            foreach ($tables as $table) {
                if (strpos($table['Name'], 'ozon_') === 0) {
                    $totalSize += $table['Data_length'] + $table['Index_length'];
                    $totalRows += $table['Rows'];
                }
            }
            
            $resources['database'] = [
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'total_rows' => $totalRows
            ];
            
        } catch (Exception $e) {
            $resources['error'] = $e->getMessage();
        }
        
        return $resources;
    }
    
    /**
     * Генерация заголовка HTML
     */
    private function generateHTMLHeader(): string {
        return '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ozon ETL System Monitoring Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        .header .subtitle {
            opacity: 0.9;
            margin-top: 0.5rem;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border: 1px solid #e1e5e9;
        }
        
        .card h3 {
            color: #2c3e50;
            margin-bottom: 1rem;
            font-size: 1.2rem;
            font-weight: 600;
        }
        
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
        }
        
        .metric {
            text-align: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 6px;
        }
        
        .metric-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .metric-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 0.5rem;
        }
        
        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 8px;
        }
        
        .status-healthy { background-color: #28a745; }
        .status-warning { background-color: #ffc107; }
        .status-error { background-color: #dc3545; }
        .status-running { background-color: #007bff; }
        
        .process-list, .alert-list {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .process-item, .alert-item {
            padding: 0.75rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .process-item:last-child, .alert-item:last-child {
            border-bottom: none;
        }
        
        .process-info, .alert-info {
            flex: 1;
        }
        
        .process-meta, .alert-meta {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .severity-critical { color: #dc3545; font-weight: bold; }
        .severity-high { color: #fd7e14; font-weight: bold; }
        .severity-medium { color: #ffc107; }
        .severity-low { color: #6c757d; }
        
        .chart-container {
            height: 300px;
            margin-top: 1rem;
        }
        
        .refresh-info {
            text-align: center;
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 2rem;
        }
        
        .auto-refresh {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 6px;
            border: 1px solid #f5c6cb;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .metric-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>';
    }
    
    /**
     * Генерация тела дашборда
     */
    private function generateDashboardBody(array $data): string {
        $html = '<div class="header">
            <h1>Ozon ETL System Monitoring</h1>
            <div class="subtitle">Real-time monitoring dashboard for warehouse stock reports ETL processes</div>
        </div>
        
        <div class="container">';
        
        // Обзор системы
        $html .= $this->generateOverviewSection($data['overview']);
        
        // Статус здоровья системы
        $html .= $this->generateHealthStatusSection($data['health_status']);
        
        // Текущие процессы
        $html .= $this->generateCurrentProcessesSection($data['current_processes']);
        
        // Недавние уведомления
        $html .= $this->generateAlertsSection($data['recent_alerts']);
        
        // Метрики производительности
        $html .= $this->generatePerformanceSection($data['performance_metrics']);
        
        // Системные ресурсы
        $html .= $this->generateResourcesSection($data['system_resources']);
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Генерация секции обзора
     */
    private function generateOverviewSection(array $overview): string {
        if (isset($overview['error'])) {
            return '<div class="card"><div class="error-message">Error loading overview: ' . htmlspecialchars($overview['error']) . '</div></div>';
        }
        
        $html = '<div class="card">
            <h3>System Overview</h3>
            <div class="metric-grid">
                <div class="metric">
                    <div class="metric-value">' . ($overview['running_processes'] ?? 0) . '</div>
                    <div class="metric-label">Running Processes</div>
                </div>
                <div class="metric">
                    <div class="metric-value">' . ($overview['completed_today'] ?? 0) . '</div>
                    <div class="metric-label">Completed Today</div>
                </div>
                <div class="metric">
                    <div class="metric-value">' . ($overview['failed_today'] ?? 0) . '</div>
                    <div class="metric-label">Failed Today</div>
                </div>
                <div class="metric">
                    <div class="metric-value">' . round(($overview['avg_duration_24h'] ?? 0) / 60, 1) . 'm</div>
                    <div class="metric-label">Avg Duration (24h)</div>
                </div>
                <div class="metric">
                    <div class="metric-value">' . ($overview['total_alerts_24h'] ?? 0) . '</div>
                    <div class="metric-label">Alerts (24h)</div>
                </div>
                <div class="metric">
                    <div class="metric-value">' . ($overview['critical_alerts_24h'] ?? 0) . '</div>
                    <div class="metric-label">Critical Alerts</div>
                </div>
            </div>
        </div>';
        
        return $html;
    }
    
    /**
     * Генерация секции статуса здоровья
     */
    private function generateHealthStatusSection(array $healthStatus): string {
        $statusClass = 'status-' . ($healthStatus['overall_status'] ?? 'error');
        
        $html = '<div class="card">
            <h3>
                <span class="status-indicator ' . $statusClass . '"></span>
                System Health Status
            </h3>';
        
        if (isset($healthStatus['checks'])) {
            foreach ($healthStatus['checks'] as $checkName => $check) {
                $checkStatusClass = 'status-' . ($check['status'] === 'pass' ? 'healthy' : 
                    ($check['status'] === 'fail' ? 'error' : 'warning'));
                
                $html .= '<div style="margin-bottom: 0.5rem;">
                    <span class="status-indicator ' . $checkStatusClass . '"></span>
                    <strong>' . htmlspecialchars($check['name'] ?? $checkName) . '</strong>';
                
                if (isset($check['error'])) {
                    $html .= ' - <span style="color: #dc3545;">' . htmlspecialchars($check['error']) . '</span>';
                } elseif (isset($check['issues']) && !empty($check['issues'])) {
                    $html .= ' - ' . htmlspecialchars(implode(', ', $check['issues']));
                }
                
                $html .= '</div>';
            }
        }
        
        if (isset($healthStatus['recommendations']) && !empty($healthStatus['recommendations'])) {
            $html .= '<div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #e9ecef;">
                <strong>Recommendations:</strong>
                <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">';
            
            foreach ($healthStatus['recommendations'] as $recommendation) {
                $html .= '<li>' . htmlspecialchars($recommendation) . '</li>';
            }
            
            $html .= '</ul></div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Генерация секции текущих процессов
     */
    private function generateCurrentProcessesSection(array $processes): string {
        $html = '<div class="card">
            <h3>Current Processes</h3>
            <div class="process-list">';
        
        if (isset($processes['error'])) {
            $html .= '<div class="error-message">Error loading processes: ' . htmlspecialchars($processes['error']) . '</div>';
        } elseif (empty($processes)) {
            $html .= '<div style="text-align: center; color: #6c757d; padding: 2rem;">No active processes</div>';
        } else {
            foreach ($processes as $process) {
                $statusClass = 'status-' . ($process['status'] === 'running' ? 'running' : 
                    ($process['status'] === 'stalled' ? 'warning' : 'error'));
                
                $html .= '<div class="process-item">
                    <div class="process-info">
                        <div>
                            <span class="status-indicator ' . $statusClass . '"></span>
                            <strong>' . htmlspecialchars($process['etl_id']) . '</strong>
                            <span style="color: #6c757d;">(' . htmlspecialchars($process['process_type']) . ')</span>
                        </div>
                        <div class="process-meta">
                            Started: ' . htmlspecialchars($process['started_at']) . ' | 
                            Running: ' . $process['running_minutes'] . 'm | 
                            Last heartbeat: ' . $process['minutes_since_heartbeat'] . 'm ago';
                
                if ($process['memory_usage_mb']) {
                    $html .= ' | Memory: ' . round($process['memory_usage_mb'], 1) . 'MB';
                }
                
                if ($process['hostname']) {
                    $html .= ' | Host: ' . htmlspecialchars($process['hostname']);
                }
                
                $html .= '</div>
                    </div>
                </div>';
            }
        }
        
        $html .= '</div></div>';
        
        return $html;
    }
    
    /**
     * Генерация секции уведомлений
     */
    private function generateAlertsSection(array $alerts): string {
        $html = '<div class="card">
            <h3>Recent Alerts</h3>
            <div class="alert-list">';
        
        if (isset($alerts['error'])) {
            $html .= '<div class="error-message">Error loading alerts: ' . htmlspecialchars($alerts['error']) . '</div>';
        } elseif (empty($alerts)) {
            $html .= '<div style="text-align: center; color: #6c757d; padding: 2rem;">No recent alerts</div>';
        } else {
            foreach ($alerts as $alert) {
                $severityClass = 'severity-' . $alert['severity'];
                $resolvedStatus = $alert['is_resolved'] ? ' (Resolved)' : '';
                
                $html .= '<div class="alert-item">
                    <div class="alert-info">
                        <div>
                            <strong class="' . $severityClass . '">' . htmlspecialchars($alert['title']) . '</strong>
                            ' . $resolvedStatus . '
                        </div>
                        <div style="margin: 0.25rem 0;">' . htmlspecialchars($alert['message']) . '</div>
                        <div class="alert-meta">
                            Type: ' . htmlspecialchars($alert['alert_type']) . ' | 
                            Created: ' . htmlspecialchars($alert['created_at']);
                
                if ($alert['etl_id']) {
                    $html .= ' | ETL: ' . htmlspecialchars($alert['etl_id']);
                }
                
                $html .= '</div>
                    </div>
                </div>';
            }
        }
        
        $html .= '</div></div>';
        
        return $html;
    }
    
    /**
     * Генерация секции производительности
     */
    private function generatePerformanceSection(array $metrics): string {
        $html = '<div class="card">
            <h3>Performance Metrics (Last 7 Days)</h3>';
        
        if (isset($metrics['error'])) {
            $html .= '<div class="error-message">Error loading metrics: ' . htmlspecialchars($metrics['error']) . '</div>';
        } elseif (empty($metrics)) {
            $html .= '<div style="text-align: center; color: #6c757d; padding: 2rem;">No performance data available</div>';
        } else {
            $html .= '<div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f8f9fa;">
                            <th style="padding: 0.75rem; text-align: left; border-bottom: 1px solid #dee2e6;">Date</th>
                            <th style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6;">Total Runs</th>
                            <th style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6;">Success Rate</th>
                            <th style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6;">Avg Duration</th>
                            <th style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6;">Max Duration</th>
                            <th style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6;">Avg Memory</th>
                        </tr>
                    </thead>
                    <tbody>';
            
            foreach ($metrics as $metric) {
                $successRateColor = $metric['success_rate'] >= 90 ? '#28a745' : 
                    ($metric['success_rate'] >= 70 ? '#ffc107' : '#dc3545');
                
                $html .= '<tr>
                    <td style="padding: 0.75rem; border-bottom: 1px solid #dee2e6;">' . htmlspecialchars($metric['date']) . '</td>
                    <td style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6;">' . $metric['total_runs'] . '</td>
                    <td style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6; color: ' . $successRateColor . '; font-weight: bold;">' . $metric['success_rate'] . '%</td>
                    <td style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6;">' . $metric['avg_duration_minutes'] . 'm</td>
                    <td style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6;">' . $metric['max_duration_minutes'] . 'm</td>
                    <td style="padding: 0.75rem; text-align: center; border-bottom: 1px solid #dee2e6;">' . round($metric['avg_memory_usage'] ?? 0, 1) . 'MB</td>
                </tr>';
            }
            
            $html .= '</tbody></table></div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Генерация секции системных ресурсов
     */
    private function generateResourcesSection(array $resources): string {
        $html = '<div class="card">
            <h3>System Resources</h3>';
        
        if (isset($resources['error'])) {
            $html .= '<div class="error-message">Error loading resources: ' . htmlspecialchars($resources['error']) . '</div>';
        } else {
            $html .= '<div class="metric-grid">';
            
            // Память
            if (isset($resources['memory'])) {
                $html .= '<div class="metric">
                    <div class="metric-value">' . $resources['memory']['current_mb'] . 'MB</div>
                    <div class="metric-label">Current Memory</div>
                </div>
                <div class="metric">
                    <div class="metric-value">' . $resources['memory']['peak_mb'] . 'MB</div>
                    <div class="metric-label">Peak Memory</div>
                </div>';
            }
            
            // Загрузка системы
            if (isset($resources['system_load'])) {
                $html .= '<div class="metric">
                    <div class="metric-value">' . $resources['system_load']['1min'] . '</div>
                    <div class="metric-label">Load Average (1m)</div>
                </div>';
            }
            
            // Диск
            if (isset($resources['disk'])) {
                $html .= '<div class="metric">
                    <div class="metric-value">' . $resources['disk']['free_space_gb'] . 'GB</div>
                    <div class="metric-label">Free Disk Space</div>
                </div>';
            }
            
            // База данных
            if (isset($resources['database'])) {
                $html .= '<div class="metric">
                    <div class="metric-value">' . $resources['database']['total_size_mb'] . 'MB</div>
                    <div class="metric-label">DB Size</div>
                </div>
                <div class="metric">
                    <div class="metric-value">' . number_format($resources['database']['total_rows']) . '</div>
                    <div class="metric-label">DB Rows</div>
                </div>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Генерация подвала HTML
     */
    private function generateHTMLFooter(): string {
        $refreshInterval = $this->config['refresh_interval_seconds'];
        
        return '<div class="refresh-info">
            <div class="auto-refresh">Dashboard auto-refreshes every ' . $refreshInterval . ' seconds</div>
            <div>Last updated: ' . date('Y-m-d H:i:s') . '</div>
        </div>

        <script>
            // Auto-refresh functionality
            setTimeout(function() {
                window.location.reload();
            }, ' . ($refreshInterval * 1000) . ');
            
            // Add real-time clock
            function updateClock() {
                const now = new Date();
                const timeString = now.toLocaleString();
                document.querySelector(".refresh-info div:last-child").textContent = "Last updated: " + timeString;
            }
            
            setInterval(updateClock, 1000);
        </script>
    </body>
</html>';
    }
    
    /**
     * Получение данных для API (JSON формат)
     * 
     * @return array данные дашборда в формате JSON
     */
    public function getDashboardDataAPI(): array {
        return $this->getDashboardData();
    }
    
    /**
     * Экспорт метрик в формате для внешних систем мониторинга
     * 
     * @param string $format формат экспорта (prometheus, json, csv)
     * @return string данные в указанном формате
     */
    public function exportMetrics(string $format = 'json'): string {
        $data = $this->getDashboardData();
        
        switch ($format) {
            case 'prometheus':
                return $this->exportPrometheusMetrics($data);
            case 'csv':
                return $this->exportCSVMetrics($data);
            default:
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
    }
    
    /**
     * Экспорт метрик в формате Prometheus
     */
    private function exportPrometheusMetrics(array $data): string {
        $metrics = [];
        
        // Основные метрики
        if (isset($data['overview'])) {
            $metrics[] = '# HELP ozon_etl_running_processes Number of currently running ETL processes';
            $metrics[] = '# TYPE ozon_etl_running_processes gauge';
            $metrics[] = 'ozon_etl_running_processes ' . ($data['overview']['running_processes'] ?? 0);
            
            $metrics[] = '# HELP ozon_etl_completed_today Number of ETL processes completed today';
            $metrics[] = '# TYPE ozon_etl_completed_today counter';
            $metrics[] = 'ozon_etl_completed_today ' . ($data['overview']['completed_today'] ?? 0);
            
            $metrics[] = '# HELP ozon_etl_failed_today Number of ETL processes failed today';
            $metrics[] = '# TYPE ozon_etl_failed_today counter';
            $metrics[] = 'ozon_etl_failed_today ' . ($data['overview']['failed_today'] ?? 0);
        }
        
        // Статус здоровья
        if (isset($data['health_status']['overall_status'])) {
            $healthValue = $data['health_status']['overall_status'] === 'healthy' ? 1 : 0;
            $metrics[] = '# HELP ozon_etl_system_healthy System health status (1=healthy, 0=unhealthy)';
            $metrics[] = '# TYPE ozon_etl_system_healthy gauge';
            $metrics[] = 'ozon_etl_system_healthy ' . $healthValue;
        }
        
        return implode("\n", $metrics) . "\n";
    }
    
    /**
     * Экспорт метрик в формате CSV
     */
    private function exportCSVMetrics(array $data): string {
        $csv = "metric_name,value,timestamp\n";
        $timestamp = date('Y-m-d H:i:s');
        
        if (isset($data['overview'])) {
            $csv .= "running_processes," . ($data['overview']['running_processes'] ?? 0) . ",$timestamp\n";
            $csv .= "completed_today," . ($data['overview']['completed_today'] ?? 0) . ",$timestamp\n";
            $csv .= "failed_today," . ($data['overview']['failed_today'] ?? 0) . ",$timestamp\n";
        }
        
        return $csv;
    }
}