<?php
/**
 * Marketplace Share Endpoint
 * 
 * Provides marketplace share statistics and performance metrics
 * for the ЭТОНОВО brand products.
 */

if (!defined('ANALYTICS_API_VERSION')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

require_once __DIR__ . '/../SalesAnalyticsService.php';

/**
 * Handle marketplace share requests
 */
function handleMarketplaceShare() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendAnalyticsErrorResponse('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
        return;
    }
    
    try {
        // Get and validate parameters
        $params = validateMarketplaceShareParams();
        
        // Create analytics service instance
        $analyticsService = new SalesAnalyticsService();
        
        // Fetch marketplace share data using the service
        $data = $analyticsService->getMarketplaceShare(
            $params['date_from'],
            $params['date_to']
        );
        
        // Log successful request
        logAnalyticsActivity('INFO', 'Marketplace share data retrieved', [
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'total_revenue' => $data['summary']['total_revenue'] ?? 0,
            'marketplaces_count' => $data['summary']['marketplaces_count'] ?? 0
        ]);
        
        sendAnalyticsJsonResponse([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        logAnalyticsActivity('ERROR', 'Marketplace share error: ' . $e->getMessage());
        sendAnalyticsErrorResponse($e->getMessage(), 400, 'MARKETPLACE_SHARE_ERROR');
    }
}

/**
 * Validate and sanitize marketplace share parameters
 * @return array Validated parameters
 */
function validateMarketplaceShareParams() {
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