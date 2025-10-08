<?php

namespace MDM\Controllers;

use MDM\Services\DataQualityService;
use MDM\Services\StatisticsService;

/**
 * Main Dashboard Controller for MDM System
 * Handles the main dashboard display with statistics and quality metrics
 */
class DashboardController
{
    private DataQualityService $dataQualityService;
    private StatisticsService $statisticsService;

    public function __construct()
    {
        $this->dataQualityService = new DataQualityService();
        $this->statisticsService = new StatisticsService();
    }

    /**
     * Display the main dashboard
     */
    public function index(): void
    {
        try {
            $dashboardData = $this->getDashboardData();
            $this->renderDashboard($dashboardData);
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Get dashboard data via AJAX
     */
    public function getDashboardDataAjax(): void
    {
        header('Content-Type: application/json');
        
        try {
            $data = $this->getDashboardData();
            echo json_encode(['success' => true, 'data' => $data]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Collect all dashboard data
     */
    private function getDashboardData(): array
    {
        return [
            'statistics' => $this->statisticsService->getOverallStatistics(),
            'quality_metrics' => $this->dataQualityService->getCurrentMetrics(),
            'recent_activity' => $this->statisticsService->getRecentActivity(),
            'pending_items' => $this->statisticsService->getPendingItemsCount(),
            'system_health' => $this->getSystemHealth()
        ];
    }

    /**
     * Get system health indicators
     */
    private function getSystemHealth(): array
    {
        return [
            'etl_status' => $this->statisticsService->getETLStatus(),
            'database_status' => $this->checkDatabaseHealth(),
            'last_sync' => $this->statisticsService->getLastSyncTime()
        ];
    }

    /**
     * Check database connectivity and health
     */
    private function checkDatabaseHealth(): array
    {
        try {
            $pdo = $this->getDatabaseConnection();
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM master_products");
            $result = $stmt->fetch();
            
            return [
                'status' => 'healthy',
                'master_products_count' => $result['count']
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Render the dashboard view
     */
    private function renderDashboard(array $data): void
    {
        $pageTitle = 'MDM Dashboard';
        $cssFiles = [
            '/src/MDM/assets/css/dashboard.css',
            '/src/MDM/assets/css/widgets.css'
        ];
        $jsFiles = [
            '/src/MDM/assets/js/dashboard.js',
            '/src/MDM/assets/js/widgets.js'
        ];

        include __DIR__ . '/../Views/dashboard.php';
    }

    /**
     * Handle errors and display error page
     */
    private function handleError(Exception $e): void
    {
        error_log("Dashboard Error: " . $e->getMessage());
        
        $errorData = [
            'message' => 'Ошибка загрузки дашборда',
            'details' => $e->getMessage()
        ];
        
        include __DIR__ . '/../Views/error.php';
    }

    /**
     * Get database connection
     */
    private function getDatabaseConnection(): \PDO
    {
        // This should be injected via dependency injection in a real application
        $config = require __DIR__ . '/../../config/database.php';
        
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        return new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);
    }
}