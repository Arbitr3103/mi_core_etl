<?php
/**
 * Regions Endpoint
 * 
 * Provides regional sales data and top performing regions.
 * Returns regional breakdown of sales performance.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../SalesAnalyticsService.php';

/**
 * Handle regions data request
 */
function handleRegions() {
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

        // Additional parameters for regions
        $regionCode = $params['region_code'] ?? null;
        $limit = min((int)($params['limit'] ?? 10), 50); // Max 50 regions

        // Initialize analytics service
        $analyticsService = new SalesAnalyticsService();
        
        // Get regional data
        if ($regionCode) {
            // Get specific region data
            $regionData = $analyticsService->getRegionDetails(
                $regionCode,
                $validatedParams['date_from'],
                $validatedParams['date_to']
            );
            
            $response = [
                'success' => true,
                'data' => $regionData,
                'metadata' => [
                    'region_code' => $regionCode,
                    'date_from' => $validatedParams['date_from'],
                    'date_to' => $validatedParams['date_to'],
                    'generated_at' => date('c')
                ]
            ];
        } else {
            // Get top regions list
            $topRegions = $analyticsService->getTopRegions(
                $validatedParams['date_from'],
                $validatedParams['date_to'],
                $limit
            );
            
            $response = [
                'success' => true,
                'data' => [
                    'regions' => $topRegions,
                    'total_regions' => count($topRegions)
                ],
                'metadata' => [
                    'limit' => $limit,
                    'date_from' => $validatedParams['date_from'],
                    'date_to' => $validatedParams['date_to'],
                    'generated_at' => date('c')
                ]
            ];
        }

        // Log successful request
        logAnalyticsActivity('INFO', 'Regional data retrieved', [
            'region_code' => $regionCode,
            'limit' => $limit,
            'date_from' => $validatedParams['date_from'],
            'date_to' => $validatedParams['date_to']
        ]);

        // Return response
        sendAnalyticsJsonResponse($response);

    } catch (Exception $e) {
        logAnalyticsActivity('ERROR', 'Regions endpoint error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        
        sendAnalyticsErrorResponse('Failed to retrieve regional data', 500, 'PROCESSING_ERROR');
    }
}
?>