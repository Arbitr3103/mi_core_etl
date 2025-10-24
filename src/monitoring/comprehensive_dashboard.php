<?php
/**
 * Comprehensive Monitoring Dashboard - Ozon Stock Reports
 * 
 * Объединенный дашборд для мониторинга всех аспектов системы:
 * - Системное здоровье и ETL процессы
 * - Бизнес-метрики и критические остатки
 * - Производительность и оптимизация
 * 
 * @version 1.0
 * @author Manhattan System
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Define paths
define('ROOT_DIR', dirname(dirname(__DIR__)));
define('SRC_DIR', ROOT_DIR . '/src');

try {
    // Include required files
    require_once SRC_DIR . '/config/database.php';
    require_once SRC_DIR . '/classes/OzonETLMonitor.php';
    require_once SRC_DIR . '/classes/BusinessMetricsMonitor.php';
    require_once SRC_DIR . '/classes/PerformanceMonitor.php';
    require_once SRC_DIR . '/monitoring/SystemMonitoringDashboard.php';
    
    // Initialize database connection
    $config = include SRC_DIR . '/config/database.php';
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
    
    // Initialize monitoring components
    $etlMonitor = new OzonETLMonitor($pdo);
    $businessMonitor = new BusinessMetricsMonitor($pdo);
    $performanceMonitor = new PerformanceMonitor($pdo);
    $systemDashboard = new SystemMonitoringDashboard($pdo, $etlMonitor);
    
    // Handle different request types
    if (isset($_GET['api']) && $_GET['api'] === '1') {
        // JSON API response
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, must-revalidate');
        
        $data = getComprehensiveDashboardData($etlMonitor, $businessMonitor, $performanceMonitor);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } else {
        // HTML dashboard
        echo generateComprehensiveDashboard($etlMonitor, $businessMonitor, $performanceMonitor, $systemDashboard);
    }
    
} catch (Exception $e) {
    // Handle errors gracefully
    if (isset($_GET['api'])) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'error' => 'Comprehensive dashboard initialization failed',
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        echo generateErrorPage($e);
    }
    
    // Log the error
    error_log("[Comprehensive Dashboard] ERROR: " . $e->getMessage());
}

/**
 * Получение данных для комплексного дашборда
 */
function getComprehensiveDashboardData($etlMonitor, $businessMonitor, $performanceMonitor) {
    $startTime = microtime(true);
    
    $data = [
        'timestamp' => date('Y-m-d H:i:s'),
        'system_health' => $etlMonitor->performHealthCheck(),
        'business_metrics' => $businessMonitor->performBusinessMetricsAnalysis(),
        'performance_analysis' => $performanceMonitor->performComprehensivePerformanceAnalysis(),
        'critical_alerts' => getCriticalAlerts($etlMonitor, $businessMonitor, $performanceMonitor),
        'system_overview' => getSystemOverview($etlMonitor, $businessMonitor, $performanceMonitor)
    ];
    
    $data['response_time_ms'] = round((microtime(true) - $startTime) * 1000, 2);
    
    return $data;
}

/**
 * Получение критических уведомлений из всех систем
 */
function getCriticalAlerts($etlMonitor, $businessMonitor, $performanceMonitor) {
    $alerts = [
        'system_alerts' => [],
        'business_alerts' => [],
        'performance_alerts' => [],
        'total_critical' => 0,
        'total_warnings' => 0
    ];
    
    try {
        // Системные уведомления
        $systemHealth = $etlMonitor->performHealthCheck();
        if (isset($systemHealth['alerts'])) {
            $alerts['system_alerts'] = $systemHealth['alerts'];
        }
        
        // Бизнес уведомления
        $businessAnalysis = $businessMonitor->performBusinessMetricsAnalysis();
        if (isset($businessAnalysis['alerts'])) {
            $alerts['business_alerts'] = $businessAnalysis['alerts'];
        }
        
        // Уведомления о производительности
        $performanceAlerts = $performanceMonitor->getActivePerformanceAlerts(20);
        $alerts['performance_alerts'] = $performanceAlerts;
        
        // Подсчет критических и предупреждающих уведомлений
        $allAlerts = array_merge(
            $alerts['system_alerts'],
            $alerts['business_alerts'],
            $alerts['performance_alerts']
        );
        
        foreach ($allAlerts as $alert) {
            $severity = $alert['severity'] ?? 'medium';
            if (in_array($severity, ['critical', 'high'])) {
                $alerts['total_critical']++;
            } else {
                $alerts['total_warnings']++;
            }
        }
        
    } catch (Exception $e) {
        $alerts['error'] = $e->getMessage();
    }
    
    return $alerts;
}

/**
 * Получение общего обзора системы
 */
function getSystemOverview($etlMonitor, $businessMonitor, $performanceMonitor) {
    $overview = [
        'overall_status' => 'healthy',
        'system_score' => 100,
        'key_metrics' => [],
        'recommendations' => []
    ];
    
    try {
        // Системные метрики
        $systemHealth = $etlMonitor->performHealthCheck();
        $overview['key_metrics']['system_health'] = $systemHealth['overall_status'] ?? 'unknown';
        
        // Бизнес метрики
        $businessAnalysis = $businessMonitor->performBusinessMetricsAnalysis();
        if (isset($businessAnalysis['metrics']['stock_levels'])) {
            $overview['key_metrics']['critical_stock_count'] = $businessAnalysis['metrics']['stock_levels']['critical_stock_count'] ?? 0;
        }
        
        // Метрики производительности
        $performanceStats = $performanceMonitor->getPerformanceStatistics(7);
        if (isset($performanceStats['summary'])) {
            $overview['key_metrics']['avg_execution_time'] = $performanceStats['summary']['avg_duration_minutes'] ?? 0;
        }
        
        // Определение общего статуса и оценки
        $statusScore = calculateOverallScore($systemHealth, $businessAnalysis, $performanceStats);
        $overview['system_score'] = $statusScore['score'];
        $overview['overall_status'] = $statusScore['status'];
        
        // Сбор рекомендаций
        $overview['recommendations'] = array_merge(
            $systemHealth['recommendations'] ?? [],
            $businessAnalysis['recommendations'] ?? [],
            getTopPerformanceRecommendations($performanceMonitor)
        );
        
    } catch (Exception $e) {
        $overview['error'] = $e->getMessage();
    }
    
    return $overview;
}

/**
 * Вычисление общей оценки системы
 */
function calculateOverallScore($systemHealth, $businessAnalysis, $performanceStats) {
    $score = 100;
    $status = 'healthy';
    
    // Снижение оценки за системные проблемы
    if (isset($systemHealth['overall_status'])) {
        switch ($systemHealth['overall_status']) {
            case 'error':
                $score -= 40;
                $status = 'critical';
                break;
            case 'warning':
                $score -= 20;
                if ($status === 'healthy') $status = 'warning';
                break;
        }
    }
    
    // Снижение оценки за бизнес проблемы
    if (isset($businessAnalysis['metrics']['stock_levels']['critical_stock_count'])) {
        $criticalCount = $businessAnalysis['metrics']['stock_levels']['critical_stock_count'];
        if ($criticalCount > 50) {
            $score -= 30;
            $status = 'critical';
        } elseif ($criticalCount > 20) {
            $score -= 15;
            if ($status === 'healthy') $status = 'warning';
        }
    }
    
    // Снижение оценки за проблемы производительности
    if (isset($performanceStats['summary']['avg_duration_minutes'])) {
        $avgDuration = $performanceStats['summary']['avg_duration_minutes'];
        if ($avgDuration > 90) {
            $score -= 25;
            if ($status !== 'critical') $status = 'warning';
        }
    }
    
    $score = max(0, $score);
    
    if ($score >= 80) {
        $status = $status === 'critical' ? 'warning' : 'healthy';
    } elseif ($score >= 60) {
        $status = $status === 'critical' ? 'critical' : 'warning';
    } else {
        $status = 'critical';
    }
    
    return ['score' => $score, 'status' => $status];
}

/**
 * Получение топ рекомендаций по производительности
 */
function getTopPerformanceRecommendations($performanceMonitor) {
    try {
        $recommendations = $performanceMonitor->getOptimizationRecommendations('pending', 5);
        return array_map(function($rec) {
            return $rec['title'] . ': ' . $rec['description'];
        }, $recommendations);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Генерация HTML дашборда
 */
function generateComprehensiveDashboard($etlMonitor, $businessMonitor, $performanceMonitor, $systemDashboard) {
    $data = getComprehensiveDashboardData($etlMonitor, $businessMonitor, $performanceMonitor);
    
    $html = '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprehensive Monitoring Dashboard - Ozon Stock Reports</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #333;
            line-height: 1.6;
            min-height: 100vh;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            color: white;
            padding: 1.5rem 2rem;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .header .subtitle {
            opacity: 0.9;
            font-size: 1.1rem;
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .status-banner {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .status-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .status-indicator {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
        }
        
        .status-healthy { background: linear-gradient(135deg, #4CAF50, #45a049); }
        .status-warning { background: linear-gradient(135deg, #FF9800, #f57c00); }
        .status-critical { background: linear-gradient(135deg, #f44336, #d32f2f); }
        
        .status-details h2 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .status-score {
            font-size: 3rem;
            font-weight: bold;
            color: #667eea;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .card h3 {
            color: #2c3e50;
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-icon {
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 0.9rem;
        }
        
        .icon-system { background: #3498db; }
        .icon-business { background: #e74c3c; }
        .icon-performance { background: #f39c12; }
        .icon-alerts { background: #9b59b6; }
        
        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .metric {
            text-align: center;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .metric-value {
            font-size: 1.8rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 0.25rem;
        }
        
        .metric-label {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .alert-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .alert-item {
            padding: 1rem;
            border-left: 4px solid #dc3545;
            background: #fff5f5;
            margin-bottom: 0.5rem;
            border-radius: 4px;
        }
        
        .alert-item.warning {
            border-left-color: #ffc107;
            background: #fffbf0;
        }
        
        .alert-title {
            font-weight: bold;
            margin-bottom: 0.25rem;
        }
        
        .alert-message {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .recommendations-list {
            list-style: none;
        }
        
        .recommendations-list li {
            padding: 0.75rem;
            background: #e3f2fd;
            margin-bottom: 0.5rem;
            border-radius: 6px;
            border-left: 3px solid #2196f3;
        }
        
        .refresh-info {
            text-align: center;
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
            margin-top: 2rem;
        }
        
        .auto-refresh {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
            
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .status-banner {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Comprehensive Monitoring Dashboard</h1>
        <div class="subtitle">Real-time monitoring for Ozon Stock Reports ETL System</div>
    </div>
    
    <div class="container">';
    
    // Status banner
    $overallStatus = $data['system_overview']['overall_status'];
    $systemScore = $data['system_overview']['system_score'];
    $statusClass = 'status-' . $overallStatus;
    
    $html .= '<div class="status-banner">
        <div class="status-info">
            <div class="status-indicator ' . $statusClass . '">' . strtoupper(substr($overallStatus, 0, 1)) . '</div>
            <div class="status-details">
                <h2>System Status: ' . ucfirst($overallStatus) . '</h2>
                <div>Overall system health and performance monitoring</div>
            </div>
        </div>
        <div class="status-score">' . $systemScore . '</div>
    </div>';
    
    // Main dashboard grid
    $html .= '<div class="dashboard-grid">';
    
    // System Health Card
    $html .= generateSystemHealthCard($data['system_health']);
    
    // Business Metrics Card
    $html .= generateBusinessMetricsCard($data['business_metrics']);
    
    // Performance Card
    $html .= generatePerformanceCard($data['performance_analysis']);
    
    // Critical Alerts Card
    $html .= generateCriticalAlertsCard($data['critical_alerts']);
    
    $html .= '</div>';
    
    // Recommendations section
    if (!empty($data['system_overview']['recommendations'])) {
        $html .= '<div class="card">
            <h3><span class="card-icon" style="background: #27ae60;">💡</span>System Recommendations</h3>
            <ul class="recommendations-list">';
        
        foreach (array_slice($data['system_overview']['recommendations'], 0, 10) as $recommendation) {
            $html .= '<li>' . htmlspecialchars($recommendation) . '</li>';
        }
        
        $html .= '</ul></div>';
    }
    
    $html .= '</div>
    
    <div class="refresh-info">
        <div class="auto-refresh">Dashboard auto-refreshes every 30 seconds</div>
        <div>Last updated: ' . date('Y-m-d H:i:s') . ' | Response time: ' . ($data['response_time_ms'] ?? 0) . 'ms</div>
    </div>

    <script>
        // Auto-refresh functionality
        setTimeout(function() {
            window.location.reload();
        }, 30000);
        
        // Add real-time clock
        function updateClock() {
            const now = new Date();
            const timeString = now.toLocaleString();
            const refreshInfo = document.querySelector(".refresh-info div:last-child");
            if (refreshInfo) {
                refreshInfo.textContent = refreshInfo.textContent.replace(/Last updated: [^|]+/, "Last updated: " + timeString);
            }
        }
        
        setInterval(updateClock, 1000);
    </script>
</body>
</html>';
    
    return $html;
}

/**
 * Генерация карточки системного здоровья
 */
function generateSystemHealthCard($systemHealth) {
    $statusClass = 'status-' . ($systemHealth['overall_status'] ?? 'error');
    
    $html = '<div class="card">
        <h3><span class="card-icon icon-system">🖥️</span>System Health</h3>
        <div class="metric-grid">
            <div class="metric">
                <div class="metric-value">
                    <span class="status-indicator ' . $statusClass . '" style="width: 20px; height: 20px; font-size: 0.8rem; margin-right: 0.5rem;"></span>
                    ' . ucfirst($systemHealth['overall_status'] ?? 'Unknown') . '
                </div>
                <div class="metric-label">Overall Status</div>
            </div>
        </div>';
    
    if (isset($systemHealth['checks'])) {
        $html .= '<div style="margin-top: 1rem;">';
        foreach ($systemHealth['checks'] as $checkName => $check) {
            $checkStatus = $check['status'] === 'pass' ? '✅' : ($check['status'] === 'fail' ? '❌' : '⚠️');
            $html .= '<div style="margin-bottom: 0.5rem;">
                ' . $checkStatus . ' <strong>' . htmlspecialchars($check['name'] ?? $checkName) . '</strong>';
            
            if (isset($check['error'])) {
                $html .= ' - <span style="color: #dc3545;">' . htmlspecialchars($check['error']) . '</span>';
            }
            
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Генерация карточки бизнес-метрик
 */
function generateBusinessMetricsCard($businessMetrics) {
    $html = '<div class="card">
        <h3><span class="card-icon icon-business">📊</span>Business Metrics</h3>';
    
    if (isset($businessMetrics['metrics']['stock_levels'])) {
        $stockLevels = $businessMetrics['metrics']['stock_levels'];
        
        $html .= '<div class="metric-grid">
            <div class="metric">
                <div class="metric-value">' . ($stockLevels['critical_stock_count'] ?? 0) . '</div>
                <div class="metric-label">Critical Stock</div>
            </div>
            <div class="metric">
                <div class="metric-value">' . ($stockLevels['zero_stock_count'] ?? 0) . '</div>
                <div class="metric-label">Zero Stock</div>
            </div>
            <div class="metric">
                <div class="metric-value">' . ($stockLevels['total_products'] ?? 0) . '</div>
                <div class="metric-label">Total Products</div>
            </div>
        </div>';
    }
    
    if (isset($businessMetrics['metrics']['data_freshness']['overall_freshness_score'])) {
        $freshnessScore = $businessMetrics['metrics']['data_freshness']['overall_freshness_score'];
        $html .= '<div style="margin-top: 1rem;">
            <strong>Data Freshness:</strong> ' . $freshnessScore . '%
        </div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Генерация карточки производительности
 */
function generatePerformanceCard($performanceAnalysis) {
    $html = '<div class="card">
        <h3><span class="card-icon icon-performance">⚡</span>Performance</h3>';
    
    if (isset($performanceAnalysis['statistics']['summary'])) {
        $summary = $performanceAnalysis['statistics']['summary'];
        
        $html .= '<div class="metric-grid">
            <div class="metric">
                <div class="metric-value">' . round($summary['avg_duration_minutes'] ?? 0, 1) . 'm</div>
                <div class="metric-label">Avg Duration</div>
            </div>
            <div class="metric">
                <div class="metric-value">' . round($summary['avg_memory_usage_mb'] ?? 0, 0) . 'MB</div>
                <div class="metric-label">Avg Memory</div>
            </div>
            <div class="metric">
                <div class="metric-value">' . ($summary['total_executions'] ?? 0) . '</div>
                <div class="metric-label">Total Runs</div>
            </div>
        </div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

/**
 * Генерация карточки критических уведомлений
 */
function generateCriticalAlertsCard($criticalAlerts) {
    $html = '<div class="card">
        <h3><span class="card-icon icon-alerts">🚨</span>Critical Alerts</h3>
        <div class="metric-grid">
            <div class="metric">
                <div class="metric-value">' . $criticalAlerts['total_critical'] . '</div>
                <div class="metric-label">Critical</div>
            </div>
            <div class="metric">
                <div class="metric-value">' . $criticalAlerts['total_warnings'] . '</div>
                <div class="metric-label">Warnings</div>
            </div>
        </div>';
    
    $html .= '<div class="alert-list">';
    
    // Показываем последние критические уведомления
    $allAlerts = array_merge(
        $criticalAlerts['system_alerts'] ?? [],
        $criticalAlerts['business_alerts'] ?? [],
        array_slice($criticalAlerts['performance_alerts'] ?? [], 0, 5)
    );
    
    if (empty($allAlerts)) {
        $html .= '<div style="text-align: center; color: #6c757d; padding: 2rem;">No critical alerts</div>';
    } else {
        foreach (array_slice($allAlerts, 0, 10) as $alert) {
            $severity = $alert['severity'] ?? 'medium';
            $alertClass = in_array($severity, ['critical', 'high']) ? 'critical' : 'warning';
            
            $html .= '<div class="alert-item ' . $alertClass . '">
                <div class="alert-title">' . htmlspecialchars($alert['title'] ?? $alert['alert_type'] ?? 'Alert') . '</div>
                <div class="alert-message">' . htmlspecialchars($alert['message'] ?? $alert['alert_message'] ?? 'No details available') . '</div>
            </div>';
        }
    }
    
    $html .= '</div></div>';
    
    return $html;
}

/**
 * Генерация страницы ошибки
 */
function generateErrorPage($exception) {
    return '<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Error</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; background: #f5f5f5; }
        .error-container { 
            background: white; 
            padding: 2rem; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            max-width: 600px;
            margin: 0 auto;
        }
        .error-title { color: #dc3545; font-size: 1.5rem; margin-bottom: 1rem; }
        .error-message { background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 4px; }
        .error-details { margin-top: 1rem; font-size: 0.9rem; color: #6c757d; }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-title">Comprehensive Dashboard Error</div>
        <div class="error-message">
            Failed to initialize comprehensive monitoring dashboard: ' . htmlspecialchars($exception->getMessage()) . '
        </div>
        <div class="error-details">
            <strong>Timestamp:</strong> ' . date('Y-m-d H:i:s') . '<br>
            <strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . '<br>
            <strong>Line:</strong> ' . $exception->getLine() . '
        </div>
    </div>
</body>
</html>';
}