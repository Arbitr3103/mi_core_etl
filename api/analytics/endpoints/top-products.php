<?php
/**
 * Top Products Endpoint
 * 
 * Provides top performing products data with marketplace filtering
 * for the ЭТОНОВО brand products.
 * 
 * Requires authentication and includes security validation.
 */

if (!defined('ANALYTICS_API_VERSION')) {
    http_response_code(403);
    exit('Direct access not allowed');
}

// Apply security middleware
require_once __DIR__ . '/../middleware/security.php';

// Require authentication
require_once __DIR__ . '/../middleware/auth.php';

require_once __DIR__ . '/../SalesAnalyticsService.php';

/**
 * Handle top products requests
 */
function handleTopProducts() {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        sendAnalyticsErrorResponse('Method not allowed', 405, 'METHOD_NOT_ALLOWED');
        return;
    }
    
    try {
        // Get and validate parameters
        $params = validateTopProductsParams();
        
        // Create analytics service instance
        $analyticsService = new SalesAnalyticsService();
        
        // Fetch top products data using the service
        $data = $analyticsService->getTopProductsByMarketplace(
            $params['marketplace'],
            $params['limit'],
            $params['date_from'],
            $params['date_to']
        );
        
        // Log successful request
        logAnalyticsActivity('INFO', 'Top products data retrieved', [
            'marketplace' => $params['marketplace'],
            'limit' => $params['limit'],
            'date_from' => $params['date_from'],
            'date_to' => $params['date_to'],
            'products_count' => count($data['products'] ?? [])
        ]);
        
        sendAnalyticsJsonResponse([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ]);
        
    } catch (Exception $e) {
        logAnalyticsActivity('ERROR', 'Top products error: ' . $e->getMessage());
        sendAnalyticsErrorResponse($e->getMessage(), 400, 'TOP_PRODUCTS_ERROR');
    }
}

/**
 * Validate and sanitize top products parameters using security validator
 * @return array Validated parameters
 */
function validateTopProductsParams() {
    // Define parameter schema for validation
    $paramSchema = [
        'date_from' => [
            'type' => 'date',
            'required' => false,
            'default' => date('Y-m-d', strtotime('-30 days'))
        ],
        'date_to' => [
            'type' => 'date',
            'required' => false,
            'default' => date('Y-m-d')
        ],
        'marketplace' => [
            'type' => 'marketplace',
            'required' => false,
            'default' => 'all'
        ],
        'limit' => [
            'type' => 'limit',
            'required' => false,
            'default' => '10'
        ]
    ];
    
    // Use security validator for comprehensive validation
    $params = validateSecureInput($paramSchema);
    
    // Additional date range validation
    $dateValidation = SecurityValidator::validateDateRange($params['date_from'], $params['date_to']);
    if (!$dateValidation['valid']) {
        throw new Exception($dateValidation['error']);
    }
    
    // Ensure limit is within bounds
    $params['limit'] = max(1, min(100, (int)$params['limit']));
    
    return $params;
}
?>