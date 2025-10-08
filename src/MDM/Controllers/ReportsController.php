<?php

namespace MDM\Controllers;

use MDM\Services\ReportsService;
use MDM\Services\DataQualityService;

/**
 * Reports Controller for MDM System
 * Handles data quality reports and analytics
 */
class ReportsController
{
    private ReportsService $reportsService;
    private DataQualityService $dataQualityService;

    public function __construct()
    {
        $this->reportsService = new ReportsService();
        $this->dataQualityService = new DataQualityService();
    }

    /**
     * Display reports interface
     */
    public function index(): void
    {
        try {
            $reportSummary = $this->reportsService->getReportSummary();
            $this->renderReportsInterface($reportSummary);
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Generate coverage report
     */
    public function coverageReport(): void
    {
        header('Content-Type: application/json');
        
        try {
            $report = $this->reportsService->generateCoverageReport();
            echo json_encode(['success' => true, 'data' => $report]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Generate incomplete data report
     */
    public function incompleteDataReport(): void
    {
        header('Content-Type: application/json');
        
        try {
            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 50);
            
            $report = $this->reportsService->generateIncompleteDataReport($page, $limit);
            echo json_encode(['success' => true, 'data' => $report]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Generate problematic products report
     */
    public function problematicProductsReport(): void
    {
        header('Content-Type: application/json');
        
        try {
            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 50);
            $type = $_GET['type'] ?? 'unknown_brand';
            
            $report = $this->reportsService->generateProblematicProductsReport($type, $page, $limit);
            echo json_encode(['success' => true, 'data' => $report]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Generate quality trends report
     */
    public function qualityTrendsReport(): void
    {
        header('Content-Type: application/json');
        
        try {
            $days = (int) ($_GET['days'] ?? 30);
            
            $report = $this->reportsService->generateQualityTrendsReport($days);
            echo json_encode(['success' => true, 'data' => $report]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Generate source analysis report
     */
    public function sourceAnalysisReport(): void
    {
        header('Content-Type: application/json');
        
        try {
            $report = $this->reportsService->generateSourceAnalysisReport();
            echo json_encode(['success' => true, 'data' => $report]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Export report
     */
    public function exportReport(): void
    {
        try {
            $reportType = $_GET['report_type'] ?? 'coverage';
            $format = $_GET['format'] ?? 'csv';
            
            $result = $this->reportsService->exportReport($reportType, $format);
            
            // Set appropriate headers for download
            $filename = $reportType . '_report_' . date('Y-m-d_H-i-s') . '.' . $format;
            
            switch ($format) {
                case 'csv':
                    header('Content-Type: text/csv');
                    break;
                case 'xlsx':
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    break;
                case 'json':
                    header('Content-Type: application/json');
                    break;
                case 'pdf':
                    header('Content-Type: application/pdf');
                    break;
            }
            
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $result;
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Generate scheduled report
     */
    public function generateScheduledReport(): void
    {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $reportType = $input['report_type'] ?? 'quality_summary';
            $schedule = $input['schedule'] ?? 'weekly';
            $recipients = $input['recipients'] ?? [];
            
            $result = $this->reportsService->scheduleReport($reportType, $schedule, $recipients);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get report history
     */
    public function getReportHistory(): void
    {
        header('Content-Type: application/json');
        
        try {
            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 20);
            
            $history = $this->reportsService->getReportHistory($page, $limit);
            echo json_encode(['success' => true, 'data' => $history]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Render reports interface
     */
    private function renderReportsInterface(array $reportSummary): void
    {
        $pageTitle = 'Отчеты о качестве данных - MDM System';
        $cssFiles = [
            '/src/MDM/assets/css/dashboard.css',
            '/src/MDM/assets/css/reports.css'
        ];
        $jsFiles = [
            '/src/MDM/assets/js/reports.js'
        ];

        include __DIR__ . '/../Views/reports.php';
    }

    /**
     * Handle errors and display error page
     */
    private function handleError(Exception $e): void
    {
        error_log("Reports Error: " . $e->getMessage());
        
        $errorData = [
            'message' => 'Ошибка генерации отчетов',
            'details' => $e->getMessage()
        ];
        
        include __DIR__ . '/../Views/error.php';
    }
}