<?php
/**
 * Marketplace Comparison Endpoint
 * 
 * Provides comparison data between Ozon and Wildberries marketplaces
 * for the ЭТОНОВО brand products.
 * 
 * Requires authentication via API key.
 */

if (!defined('ANALYTICS_API_VERSION')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

// Require authentication for this endpoint
require_once __DIR__ . '/../middleware/auth.php';

require_once __DIR__ . '/../SalesAnalyticsService.php';

/**
 * Handle marketplace comparison requests
 */
function handleMarketplaceComparison() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendAnalyticsErrorResponse('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
        return;
    }
    
    try {
        // Get and validate parameters
        $params = validateMarketplaceComparisonParams();
        
        // Create analytics service instance
        $analyticsService = new SalesAnalyticsService();
        
        // Fetch marketplace comparison data using the service
        $data = $analyticsService->getMarketplaceComparison(
            $params['date_from'],
            $params['date_to']
        );
        
        // Log successful request
        logAnalyticsActivity('INFO', 'Marketplace comparison data retrieved', [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'total_revenue' => $data['summary']['total_revenue'] ?? 0,
            'total_orders' => $data['summary']['total_orders'] ?? 0
        ]);
        
        sendAnalyticsJsonResponse([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        logAnalyticsActivity('ERROR', 'Marketplace comparison error: ' . $e->getMessage());
        sendAnalyticsErrorResponse($e->getMessage(), 400, 'COMPARISON_ERROR');
    }
}

/**
 * Validate and sanitize marketplace comparison parameters
 * @return array Validated parameters
 */
function validateMarketplaceComparisonParams() {
    $params = [];
    
    // Date from parameter
    $dateFrom = $_GET['date_from'] ?? null;
    if ($dateFrom) {
        if (!validateAnalyticsInput('date', $dateFrom)) {
            throw new Exception('Invalid date_from format. Use YYYY-MM-DD');
        }
        $params['date_from'] = $dateFrom;
    } else {
        // Default to 30 days ago
        $params['date_from'] = date('Y-m-d', strtotime('-30 days'));
    }
    
    // Date to parameter
    $dateTo = $_GET['date_to'] ?? null;
    if ($dateTo) {
        if (!validateAnalyticsInput('date', $dateTo)) {
            throw new Exception('Invalid date_to format. Use YYYY-MM-DD');
        }
        $params['date_to'] = $dateTo;
    } else {
        // Default to today
        $params['date_to'] = date('Y-m-d');
    }
    
    // Validate date range
    if (strtotime($params['date_from']) > strtotime($params['date_to'])) {
        throw new Exception('date_from cannot be greater than date_to');
    }
    
    // Check maximum date range
    $daysDiff = (strtotime($params['date_to']) - strtotime($params['date_from'])) / (24 * 60 * 60);
    if ($daysDiff > ANALYTICS_MAX_DATE_RANGE_DAYS) {
        throw new Exception('Date range cannot exceed ' . ANALYTICS_MAX_DATE_RANGE_DAYS . ' days');
    }
    
    return $params;
}


?>