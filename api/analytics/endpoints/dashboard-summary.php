<?php
/**
 * Dashboard Summary Endpoint
 * 
 * Provides aggregated KPI data for the dashboard overview.
 * Returns total revenue, orders, regions, and average order value.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../SalesAnalyticsService.php';

/**
 * Handle dashboard summary request
 */
function handleDashboardSummary() {
    try {
        // Validate request method
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            sendAnalyticsErrorResponse('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
            return;
        }

        // Get and validate parameters
        $params = getAnalyticsRequestParams();
        $validatedParams = validateAnalyticsDateRange($params);
        
        if ($validatedParams['error']) {
            sendAnalyticsErrorResponse($validatedParams['message'], 400, 'INVALID_PARAMETERS');
            return;
        }

        // Initialize analytics service
        $analyticsService = new SalesAnalyticsService();
        
        // Get dashboard summary data
        $summaryData = $analyticsService->getDashboardSummary(
            $validatedParams['date_from'],
            $validatedParams['date_to'],
            $validatedParams['marketplace']
        );

        // Log successful request
        logAnalyticsActivity('INFO', 'Dashboard summary data retrieved', [
            'date_from' => $validatedParams['date_from'],
            'date_to' => $validatedParams['date_to'],
            'marketplace' => $validatedParams['marketplace'],
            'total_revenue' => $summaryData['total_revenue'] ?? 0
        ]);

        // Return response
        sendAnalyticsJsonResponse([
            'success' => true,
            'data' => $summaryData,
            'metadata' => [
                'date_from' => $validatedParams['date_from'],
                'date_to' => $validatedParams['date_to'],
                'marketplace' => $validatedParams['marketplace'],
                'generated_at' => date('c')
            ]
        ]);

    } catch (Exception $e) {
        logAnalyticsActivity('ERROR', 'Dashboard summary error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        
        sendAnalyticsErrorResponse('Failed to retrieve dashboard summary', 500, 'PROCESSING_ERROR');
    }
}
?>