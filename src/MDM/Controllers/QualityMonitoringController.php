<?php

namespace MDM\Controllers;

use MDM\Services\DataQualityService;
use MDM\Services\DataQualityAlertService;
use MDM\Services\PerformanceMonitoringService;
use MDM\Services\DataQualityScheduler;

/**
 * Quality Monitoring Controller
 * Handles data quality monitoring dashboard and API endpoints
 */
class QualityMonitoringController
{
    private DataQualityService $qualityService;
    private DataQualityAlertService $alertService;
    private PerformanceMonitoringService $performanceService;
    private DataQualityScheduler $scheduler;

    public function __construct()
    {
        $this->qualityService = new DataQualityService();
        $this->alertService = new DataQualityAlertService();
        $this->performanceService = new PerformanceMonitoringService();
        $this->scheduler = new DataQualityScheduler();
    }

    /**
     * Display quality monitoring dashboard
     */
    public function dashboard(): void
    {
        try {
            $data = [
                'current_metrics' => $this->qualityService->getCurrentMetrics(),
                'recent_alerts' => $this->getRecentAlerts(24),
                'performance_summary' => $this->performanceService->getPerformanceSummary(24),
                'scheduler_status' => $this->scheduler->getSchedulerStatus(),
                'coverage_by_source' => $this->qualityService->getMasterDataCoverageBySource(),
                'attribute_completeness' => $this->qualityService->getAttributeCompletenessDetails(),
                'page_title' => 'Data Quality Monitoring'
            ];

            $this->renderView('quality_monitoring', $data);
        } catch (\Exception $e) {
            $this->handleError($e, 'Failed to load quality monitoring dashboard');
        }
    }

    /**
     * Get quality metrics API endpoint
     */
    public function getMetrics(): void
    {
        try {
            $metrics = $this->qualityService->getCurrentMetrics();
            $this->jsonResponse($metrics);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get quality trends API endpoint
     */
    public function getTrends(): void
    {
        try {
            $days = (int) ($_GET['days'] ?? 30);
            $trends = $this->qualityService->getQualityTrends($days);
            $this->jsonResponse($trends);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get alerts API endpoint
     */
    public function getAlerts(): void
    {
        try {
            $hours = (int) ($_GET['hours'] ?? 24);
            $alerts = $this->getRecentAlerts($hours);
            $this->jsonResponse($alerts);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get performance metrics API endpoint
     */
    public function getPerformanceMetrics(): void
    {
        try {
            $hours = (int) ($_GET['hours'] ?? 24);
            $metrics = $this->performanceService->getPerformanceSummary($hours);
            $this->jsonResponse($metrics);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get matching accuracy trends API endpoint
     */
    public function getMatchingTrends(): void
    {
        try {
            $days = (int) ($_GET['days'] ?? 30);
            $trends = $this->qualityService->getMatchingAccuracyTrends($days);
            $this->jsonResponse($trends);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Force run quality check
     */
    public function forceQualityCheck(): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->jsonError('Method not allowed', 405);
                return;
            }

            $alerts = $this->alertService->checkDataQualityAndAlert();
            $this->jsonResponse([
                'success' => true,
                'message' => 'Quality check completed',
                'alerts_generated' => count($alerts),
                'alerts' => $alerts
            ]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Update quality metrics
     */
    public function updateMetrics(): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->jsonError('Method not allowed', 405);
                return;
            }

            $this->qualityService->updateQualityMetrics();
            $this->jsonResponse([
                'success' => true,
                'message' => 'Quality metrics updated successfully'
            ]);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get data quality report
     */
    public function getReport(): void
    {
        try {
            $type = $_GET['type'] ?? 'current';
            
            switch ($type) {
                case 'weekly':
                    $report = $this->alertService->generateWeeklyReport();
                    break;
                case 'current':
                default:
                    $report = [
                        'type' => 'current',
                        'generated_at' => date('Y-m-d H:i:s'),
                        'metrics' => $this->qualityService->getCurrentMetrics(),
                        'coverage_by_source' => $this->qualityService->getMasterDataCoverageBySource(),
                        'attribute_completeness' => $this->qualityService->getAttributeCompletenessDetails(),
                        'recent_alerts' => $this->getRecentAlerts(168) // Last week
                    ];
                    break;
            }

            $this->jsonResponse($report);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get drill-down data for problematic products
     */
    public function getProblematicProducts(): void
    {
        try {
            $issue = $_GET['issue'] ?? 'unknown_brand';
            $limit = (int) ($_GET['limit'] ?? 100);
            
            $products = $this->getProblematicProductsByIssue($issue, $limit);
            $this->jsonResponse($products);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get scheduler status
     */
    public function getSchedulerStatus(): void
    {
        try {
            $status = $this->scheduler->getSchedulerStatus();
            $this->jsonResponse($status);
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Toggle scheduler check
     */
    public function toggleSchedulerCheck(): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                $this->jsonError('Method not allowed', 405);
                return;
            }

            $input = json_decode(file_get_contents('php://input'), true);
            $checkName = $input['check_name'] ?? '';
            $enabled = $input['enabled'] ?? false;

            if (empty($checkName)) {
                $this->jsonError('Check name is required', 400);
                return;
            }

            $success = $this->scheduler->toggleCheck($checkName, $enabled);
            
            if ($success) {
                $this->jsonResponse([
                    'success' => true,
                    'message' => "Check '{$checkName}' " . ($enabled ? 'enabled' : 'disabled')
                ]);
            } else {
                $this->jsonError("Check '{$checkName}' not found", 404);
            }
        } catch (\Exception $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * Get recent alerts from database
     */
    private function getRecentAlerts(int $hours): array
    {
        $config = require __DIR__ . '/../../config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);

        $sql = "
            SELECT * FROM data_quality_alerts 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            ORDER BY created_at DESC, severity DESC
            LIMIT 50
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$hours]);
        
        return $stmt->fetchAll();
    }

    /**
     * Get problematic products by issue type
     */
    private function getProblematicProductsByIssue(string $issue, int $limit): array
    {
        $config = require __DIR__ . '/../../config/database.php';
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);

        switch ($issue) {
            case 'unknown_brand':
                $sql = "
                    SELECT master_id, canonical_name, canonical_brand, canonical_category, 
                           created_at, updated_at
                    FROM master_products 
                    WHERE status = 'active' 
                      AND (canonical_brand = 'Неизвестный бренд' OR canonical_brand IS NULL OR canonical_brand = '')
                    ORDER BY updated_at DESC
                    LIMIT ?
                ";
                break;

            case 'no_category':
                $sql = "
                    SELECT master_id, canonical_name, canonical_brand, canonical_category, 
                           created_at, updated_at
                    FROM master_products 
                    WHERE status = 'active' 
                      AND (canonical_category = 'Без категории' OR canonical_category IS NULL OR canonical_category = '')
                    ORDER BY updated_at DESC
                    LIMIT ?
                ";
                break;

            case 'no_description':
                $sql = "
                    SELECT master_id, canonical_name, canonical_brand, canonical_category, 
                           description, created_at, updated_at
                    FROM master_products 
                    WHERE status = 'active' 
                      AND (description IS NULL OR description = '')
                    ORDER BY updated_at DESC
                    LIMIT ?
                ";
                break;

            case 'pending_verification':
                $sql = "
                    SELECT sm.master_id, sm.external_sku, sm.source, sm.confidence_score,
                           sm.created_at, mp.canonical_name, mp.canonical_brand
                    FROM sku_mapping sm
                    LEFT JOIN master_products mp ON sm.master_id = mp.master_id
                    WHERE sm.verification_status = 'pending'
                    ORDER BY sm.created_at DESC
                    LIMIT ?
                ";
                break;

            default:
                return [];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll();
    }

    /**
     * Render view template
     */
    private function renderView(string $template, array $data = []): void
    {
        extract($data);
        $templatePath = __DIR__ . "/../Views/{$template}.php";
        
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            throw new \Exception("Template not found: {$template}");
        }
    }

    /**
     * Send JSON response
     */
    private function jsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Send JSON error response
     */
    private function jsonError(string $message, int $statusCode = 400): void
    {
        $this->jsonResponse([
            'error' => true,
            'message' => $message
        ], $statusCode);
    }

    /**
     * Handle errors
     */
    private function handleError(\Exception $e, string $userMessage): void
    {
        error_log("Quality Monitoring Error: " . $e->getMessage());
        
        $data = [
            'error' => $userMessage,
            'page_title' => 'Error - Data Quality Monitoring'
        ];
        
        $this->renderView('error', $data);
    }
}