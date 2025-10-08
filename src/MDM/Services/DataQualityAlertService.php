<?php

namespace MDM\Services;

/**
 * Data Quality Alert Service for MDM System
 * Monitors data quality metrics and sends alerts when thresholds are breached
 */
class DataQualityAlertService
{
    private \PDO $pdo;
    private NotificationService $notificationService;
    private DataQualityService $dataQualityService;
    private array $thresholds;

    public function __construct()
    {
        $this->pdo = $this->getDatabaseConnection();
        $this->notificationService = new NotificationService();
        $this->dataQualityService = new DataQualityService();
        $this->loadThresholds();
    }

    /**
     * Check all data quality metrics and send alerts if needed
     */
    public function checkDataQualityAndAlert(): array
    {
        $alerts = [];
        $metrics = $this->dataQualityService->getCurrentMetrics();

        foreach ($metrics as $metricType => $metricData) {
            $alert = $this->checkMetricThresholds($metricType, $metricData);
            if ($alert) {
                $alerts[] = $alert;
                $this->sendAlert($alert);
                $this->logAlert($alert);
            }
        }

        // Check for specific data quality issues
        $specificAlerts = $this->checkSpecificDataQualityIssues();
        $alerts = array_merge($alerts, $specificAlerts);

        return $alerts;
    }

    /**
     * Check specific data quality issues
     */
    private function checkSpecificDataQualityIssues(): array
    {
        $alerts = [];

        // Check for products with "Неизвестный бренд"
        $unknownBrandCount = $this->getUnknownBrandCount();
        if ($unknownBrandCount > $this->thresholds['unknown_brand_threshold']) {
            $alerts[] = [
                'type' => 'completeness',
                'severity' => 'warning',
                'title' => 'High number of products with unknown brand',
                'description' => "Found {$unknownBrandCount} products with 'Неизвестный бренд'",
                'metric_name' => 'unknown_brand_count',
                'current_value' => $unknownBrandCount,
                'threshold_value' => $this->thresholds['unknown_brand_threshold'],
                'affected_records' => $unknownBrandCount
            ];
        }

        // Check for products without categories
        $noCategoryCount = $this->getNoCategoryCount();
        if ($noCategoryCount > $this->thresholds['no_category_threshold']) {
            $alerts[] = [
                'type' => 'completeness',
                'severity' => 'warning',
                'title' => 'High number of products without category',
                'description' => "Found {$noCategoryCount} products with 'Без категории'",
                'metric_name' => 'no_category_count',
                'current_value' => $noCategoryCount,
                'threshold_value' => $this->thresholds['no_category_threshold'],
                'affected_records' => $noCategoryCount
            ];
        }

        // Check for pending verification queue size
        $pendingCount = $this->getPendingVerificationCount();
        if ($pendingCount > $this->thresholds['pending_verification_threshold']) {
            $alerts[] = [
                'type' => 'performance',
                'severity' => $pendingCount > $this->thresholds['pending_verification_critical'] ? 'critical' : 'warning',
                'title' => 'Large pending verification queue',
                'description' => "There are {$pendingCount} items pending manual verification",
                'metric_name' => 'pending_verification_count',
                'current_value' => $pendingCount,
                'threshold_value' => $this->thresholds['pending_verification_threshold'],
                'affected_records' => $pendingCount
            ];
        }

        // Check for low matching confidence trends
        $lowConfidenceRate = $this->getLowConfidenceMatchingRate();
        if ($lowConfidenceRate > $this->thresholds['low_confidence_rate_threshold']) {
            $alerts[] = [
                'type' => 'accuracy',
                'severity' => 'warning',
                'title' => 'High rate of low-confidence matches',
                'description' => "Recent automatic matching has {$lowConfidenceRate}% low-confidence matches",
                'metric_name' => 'low_confidence_rate',
                'current_value' => $lowConfidenceRate,
                'threshold_value' => $this->thresholds['low_confidence_rate_threshold'],
                'affected_records' => null
            ];
        }

        foreach ($specificAlerts as $alert) {
            $this->sendAlert($alert);
            $this->logAlert($alert);
        }

        return $alerts;
    }

    /**
     * Check metric thresholds and create alert if needed
     */
    private function checkMetricThresholds(string $metricType, array $metricData): ?array
    {
        $overallValue = $metricData['overall'] ?? 0;
        
        // Define thresholds for each metric type
        $thresholdConfig = [
            'completeness' => ['warning' => 80, 'critical' => 60],
            'accuracy' => ['warning' => 85, 'critical' => 70],
            'consistency' => ['warning' => 90, 'critical' => 75],
            'coverage' => ['warning' => 85, 'critical' => 70],
            'freshness' => ['warning' => 70, 'critical' => 50],
            'matching_performance' => ['warning' => 80, 'critical' => 60],
            'system_performance' => ['warning' => 70, 'critical' => 50]
        ];

        if (!isset($thresholdConfig[$metricType])) {
            return null;
        }

        $thresholds = $thresholdConfig[$metricType];
        $severity = null;

        if ($overallValue < $thresholds['critical']) {
            $severity = 'critical';
        } elseif ($overallValue < $thresholds['warning']) {
            $severity = 'warning';
        }

        if ($severity) {
            return [
                'type' => $metricType,
                'severity' => $severity,
                'title' => ucfirst($metricType) . ' metric below threshold',
                'description' => "The {$metricType} metric is at {$overallValue}%, which is below the {$severity} threshold",
                'metric_name' => $metricType,
                'current_value' => $overallValue,
                'threshold_value' => $thresholds[$severity],
                'affected_records' => $metricData['total_products'] ?? $metricData['total_mappings'] ?? null
            ];
        }

        return null;
    }

    /**
     * Send alert through notification service
     */
    private function sendAlert(array $alert): void
    {
        $message = [
            'type' => 'data_quality_alert',
            'alert_type' => $alert['type'],
            'severity' => $alert['severity'],
            'title' => $alert['title'],
            'description' => $alert['description'],
            'metric_name' => $alert['metric_name'],
            'current_value' => $alert['current_value'],
            'threshold_value' => $alert['threshold_value'],
            'affected_records' => $alert['affected_records'],
            'timestamp' => date('Y-m-d H:i:s')
        ];

        $channels = $alert['severity'] === 'critical' ? ['log', 'email', 'webhook'] : ['log', 'email'];
        $this->notificationService->send($message, $alert['severity'], $channels);
    }

    /**
     * Log alert to database
     */
    private function logAlert(array $alert): void
    {
        $sql = "
            INSERT INTO data_quality_alerts (
                alert_type, severity, title, description, metric_name, 
                current_value, threshold_value, affected_records
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $alert['type'],
            $alert['severity'],
            $alert['title'],
            $alert['description'],
            $alert['metric_name'],
            $alert['current_value'],
            $alert['threshold_value'],
            $alert['affected_records']
        ]);
    }

    /**
     * Get count of products with unknown brand
     */
    private function getUnknownBrandCount(): int
    {
        $sql = "
            SELECT COUNT(*) as count 
            FROM master_products 
            WHERE status = 'active' 
              AND (canonical_brand = 'Неизвестный бренд' OR canonical_brand IS NULL OR canonical_brand = '')
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return (int) $stmt->fetch()['count'];
    }

    /**
     * Get count of products without category
     */
    private function getNoCategoryCount(): int
    {
        $sql = "
            SELECT COUNT(*) as count 
            FROM master_products 
            WHERE status = 'active' 
              AND (canonical_category = 'Без категории' OR canonical_category IS NULL OR canonical_category = '')
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return (int) $stmt->fetch()['count'];
    }

    /**
     * Get count of items pending verification
     */
    private function getPendingVerificationCount(): int
    {
        $sql = "SELECT COUNT(*) as count FROM sku_mapping WHERE verification_status = 'pending'";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return (int) $stmt->fetch()['count'];
    }

    /**
     * Get rate of low confidence matches in recent period
     */
    private function getLowConfidenceMatchingRate(): float
    {
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN confidence_score < 0.7 THEN 1 ELSE 0 END) as low_confidence
            FROM sku_mapping 
            WHERE verification_status = 'auto'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        if ($result['total'] == 0) {
            return 0;
        }

        return round($result['low_confidence'] / $result['total'] * 100, 2);
    }

    /**
     * Generate weekly data quality report
     */
    public function generateWeeklyReport(): array
    {
        $metrics = $this->dataQualityService->getCurrentMetrics();
        $trends = $this->dataQualityService->getQualityTrends(7);
        $alerts = $this->getRecentAlerts(7);

        $report = [
            'period' => 'Last 7 days',
            'generated_at' => date('Y-m-d H:i:s'),
            'summary' => [
                'total_alerts' => count($alerts),
                'critical_alerts' => count(array_filter($alerts, fn($a) => $a['severity'] === 'critical')),
                'warning_alerts' => count(array_filter($alerts, fn($a) => $a['severity'] === 'warning')),
                'overall_health_score' => $this->calculateOverallHealthScore($metrics)
            ],
            'current_metrics' => $metrics,
            'trends' => $trends,
            'alerts' => $alerts,
            'recommendations' => $this->generateRecommendations($metrics, $alerts)
        ];

        // Send weekly report
        $this->sendWeeklyReport($report);

        return $report;
    }

    /**
     * Send weekly report
     */
    private function sendWeeklyReport(array $report): void
    {
        $message = [
            'type' => 'weekly_report',
            'title' => 'Weekly Data Quality Report',
            'summary' => $report['summary'],
            'period' => $report['period'],
            'generated_at' => $report['generated_at'],
            'report_url' => '/mdm/reports/weekly' // Link to full report
        ];

        $this->notificationService->send($message, 'info', ['email']);
    }

    /**
     * Get recent alerts
     */
    private function getRecentAlerts(int $days): array
    {
        $sql = "
            SELECT * FROM data_quality_alerts 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY created_at DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$days]);
        
        return $stmt->fetchAll();
    }

    /**
     * Calculate overall health score
     */
    private function calculateOverallHealthScore(array $metrics): float
    {
        $weights = [
            'completeness' => 0.25,
            'accuracy' => 0.25,
            'consistency' => 0.20,
            'coverage' => 0.15,
            'freshness' => 0.10,
            'matching_performance' => 0.05
        ];

        $score = 0;
        foreach ($weights as $metric => $weight) {
            if (isset($metrics[$metric]['overall'])) {
                $score += $metrics[$metric]['overall'] * $weight;
            }
        }

        return round($score, 2);
    }

    /**
     * Generate recommendations based on metrics and alerts
     */
    private function generateRecommendations(array $metrics, array $alerts): array
    {
        $recommendations = [];

        // Check completeness issues
        if (isset($metrics['completeness']['overall']) && $metrics['completeness']['overall'] < 80) {
            $recommendations[] = [
                'type' => 'completeness',
                'priority' => 'high',
                'title' => 'Improve data completeness',
                'description' => 'Focus on filling missing brand and category information',
                'actions' => [
                    'Review products with "Неизвестный бренд" status',
                    'Enhance data enrichment from external sources',
                    'Implement mandatory field validation for new products'
                ]
            ];
        }

        // Check accuracy issues
        if (isset($metrics['accuracy']['overall']) && $metrics['accuracy']['overall'] < 85) {
            $recommendations[] = [
                'type' => 'accuracy',
                'priority' => 'high',
                'title' => 'Improve matching accuracy',
                'description' => 'Enhance automatic matching algorithms',
                'actions' => [
                    'Review and tune matching algorithm parameters',
                    'Increase manual verification for medium-confidence matches',
                    'Implement machine learning for better pattern recognition'
                ]
            ];
        }

        // Check pending queue
        $criticalAlerts = array_filter($alerts, fn($a) => $a['severity'] === 'critical');
        if (!empty($criticalAlerts)) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'critical',
                'title' => 'Address critical data quality issues',
                'description' => 'Immediate attention required for critical alerts',
                'actions' => [
                    'Process pending verification queue',
                    'Investigate root causes of data quality degradation',
                    'Implement additional monitoring and alerting'
                ]
            ];
        }

        return $recommendations;
    }

    /**
     * Load alert thresholds from configuration
     */
    private function loadThresholds(): void
    {
        $this->thresholds = [
            'unknown_brand_threshold' => 100,
            'no_category_threshold' => 50,
            'pending_verification_threshold' => 500,
            'pending_verification_critical' => 2000,
            'low_confidence_rate_threshold' => 30
        ];
    }

    /**
     * Update alert thresholds
     */
    public function updateThresholds(array $newThresholds): void
    {
        $this->thresholds = array_merge($this->thresholds, $newThresholds);
        
        // Save to database or configuration file
        $this->saveThresholds();
    }

    /**
     * Save thresholds to database
     */
    private function saveThresholds(): void
    {
        // Implementation to save thresholds to database or config file
        // For now, we'll just keep them in memory
    }

    /**
     * Get database connection
     */
    private function getDatabaseConnection(): \PDO
    {
        $config = require __DIR__ . '/../../config/database.php';
        
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        return new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);
    }
}