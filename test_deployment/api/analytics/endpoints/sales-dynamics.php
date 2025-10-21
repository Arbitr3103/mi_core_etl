<?php
/**
 * Sales Dynamics Endpoint
 * 
 * Provides sales trends and growth rates over time periods
 * for the ЭТОНОВО brand products.
 */

if (!defined('ANALYTICS_API_VERSION')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

require_once __DIR__ . '/../SalesAnalyticsService.php';

/**
 * Handle sales dynamics requests
 */
function handleSalesDynamics() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendAnalyticsErrorResponse('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
        return;
    }
    
    try {
        // Get and validate parameters
        $params = validateSalesDynamicsParams();
        
        // Create analytics service instance
        $analyticsService = new SalesAnalyticsService();
        
        // Fetch sales dynamics data using the service
        $data = $analyticsService->getSalesDynamics(
            $params['period'],
            $params['date_from'],
            $params['date_to'],
            $params['marketplace']
        );
        
        // Log successful request
        logAnalyticsActivity('INFO', 'Sales dynamics data retrieved', [
            'period' => $params['period'],
            'marketplace' => $params['marketplace'],
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'periods_count' => count($data['dynamics'] ?? [])
        ]);
        
        sendAnalyticsJsonResponse([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        logAnalyticsActivity('ERROR', 'Sales dynamics error: ' . $e->getMessage());
        sendAnalyticsErrorResponse($e->getMessage(), 400, 'SALES_DYNAMICS_ERROR');
    }
}

/**
 * Validate and sanitize sales dynamics parameters
 * @return array Validated parameters
 */
function validateSalesDynamicsParams() {
    $params = [];
    
    // Period parameter
    $period = $_GET['period'] ?? 'month';
    if (!in_array($period, ['month', 'week', 'day'])) {
        throw new Exception('Invalid period. Use: month, week, or day');
    }
    $params['period'] = $period;
    
    // Date from parameter
    $dateFrom = $_GET['date_from'] ?? null;
    if ($dateFrom) {
        if (!validateAnalyticsInput('date', $dateFrom)) {
            throw new Exception('Invalid date_from format. Use YYYY-MM-DD');
        }
        $params['date_from'] = $dateFrom;
    } else {
        // Set default based on period
        if ($period === 'month') {
            $params['date_from'] = date('Y-m-d', strtotime('-6 months'));
        } elseif ($period === 'week') {
            $params['date_from'] = date('Y-m-d', strtotime('-12 weeks'));
        } else {
            $params['date_from'] = date('Y-m-d', strtotime('-30 days'));
        }
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
    
    // Check maximum date range based on period
    $daysDiff = (strtotime($params['date_to']) - strtotime($params['date_from'])) / (24 * 60 * 60);
    $maxDays = ANALYTICS_MAX_DATE_RANGE_DAYS;
    
    // Adjust max days based on period for performance
    if ($period === 'day' && $daysDiff > 90) {
        throw new Exception('For daily period, maximum date range is 90 days');
    } elseif ($period === 'week' && $daysDiff > 365) {
        throw new Exception('For weekly period, maximum date range is 1 year');
    } elseif ($daysDiff > $maxDays) {
        throw new Exception('Date range cannot exceed ' . $maxDays . ' days');
    }
    
    // Marketplace filter
    $marketplace = $_GET['marketplace'] ?? 'all';
    if (!validateAnalyticsInput('marketplace', $marketplace)) {
        throw new Exception('Invalid marketplace. Use: ozon, wildberries, or all');
    }
    $params['marketplace'] = $marketplace;
    
    return $params;
}
?>