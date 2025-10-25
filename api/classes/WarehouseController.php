<?php
/**
 * Warehouse Controller
 * 
 * Controller for warehouse dashboard API endpoints.
 * Handles requests for dashboard data, exports, and filter options.
 * 
 * Requirements: 1, 2, 9, 10
 */

require_once __DIR__ . '/WarehouseService.php';

class WarehouseController {
    
    private $service;
    
    /**
     * Constructor
     * @param PDO $pdo Database connection
     */
    public function __construct($pdo) {
        $this->service = new WarehouseService($pdo);
    }
    
    /**
     * Get dashboard data
     * 
     * Handles GET /api/warehouse/dashboard
     * Returns warehouse dashboard data with applied filters.
     * Enhanced to support Analytics API data integration.
     * 
     * Requirements: 1.1, 1.2, 1.3, 2.1, 2.2, 2.3, 2.4, 9.1, 9.2, 9.3, 9.4, 17.3
     * 
     * Query Parameters:
     * - warehouse (optional): Filter by warehouse name
     * - cluster (optional): Filter by cluster
     * - liquidity_status (optional): Filter by liquidity status (critical, low, normal, excess)
     * - active_only (optional, default: true): Show only active products
     * - has_replenishment_need (optional): Show only products with replenishment need
     * - data_source (optional): Filter by data source (analytics_api, manual, import, all)
     * - quality_score (optional): Minimum quality score (0-100)
     * - freshness_hours (optional): Maximum hours since last sync
     * - sort_by (optional, default: replenishment_need): Field to sort by
     * - sort_order (optional, default: desc): Sort direction (asc/desc)
     * - limit (optional, default: 100): Number of items per page
     * - offset (optional, default: 0): Pagination offset
     * 
     * @return void Outputs JSON response
     */
    public function getDashboard() {
        try {
            // Validate and extract query parameters
            $filters = $this->validateDashboardFilters($_GET);
            
            // Get dashboard data from service
            $result = $this->service->getDashboardData($filters);
            
            // Return response
            $this->sendJsonResponse($result);
            
        } catch (ValidationException $e) {
            $this->sendErrorResponse($e->getMessage(), 400);
        } catch (Exception $e) {
            error_log("Error in WarehouseController::getDashboard: " . $e->getMessage());
            $this->sendErrorResponse('Internal server error', 500);
        }
    }
    
    /**
     * Export dashboard data to CSV
     * 
     * Handles GET /api/warehouse/export
     * Generates and downloads CSV file with dashboard data.
     * 
     * Requirements: 10.1, 10.2, 10.3, 10.4
     * 
     * Query Parameters: Same as getDashboard()
     * 
     * @return void Outputs CSV file
     */
    public function export() {
        try {
            // Validate and extract query parameters
            $filters = $this->validateDashboardFilters($_GET);
            
            // Generate CSV content
            $csvContent = $this->service->exportToCSV($filters);
            
            // Set headers for CSV download
            $filename = 'warehouse_dashboard_' . date('Y-m-d_His') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
            
            // Add BOM for Excel UTF-8 support
            echo "\xEF\xBB\xBF";
            echo $csvContent;
            
        } catch (ValidationException $e) {
            $this->sendErrorResponse($e->getMessage(), 400);
        } catch (Exception $e) {
            error_log("Error in WarehouseController::export: " . $e->getMessage());
            $this->sendErrorResponse('Failed to generate export', 500);
        }
    }
    
    /**
     * Get list of warehouses
     * 
     * Handles GET /api/warehouse/warehouses
     * Returns list of all warehouses with product counts.
     * 
     * Requirements: 2.3, 9.1
     * 
     * @return void Outputs JSON response
     */
    public function getWarehouses() {
        try {
            $result = $this->service->getWarehouseList();
            $this->sendJsonResponse($result);
            
        } catch (Exception $e) {
            error_log("Error in WarehouseController::getWarehouses: " . $e->getMessage());
            $this->sendErrorResponse('Failed to retrieve warehouse list', 500);
        }
    }
    
    /**
     * Get list of clusters
     * 
     * Handles GET /api/warehouse/clusters
     * Returns list of all warehouse clusters with counts.
     * 
     * Requirements: 2.4, 9.1
     * 
     * @return void Outputs JSON response
     */
    public function getClusters() {
        try {
            $result = $this->service->getClusterList();
            $this->sendJsonResponse($result);
            
        } catch (Exception $e) {
            error_log("Error in WarehouseController::getClusters: " . $e->getMessage());
            $this->sendErrorResponse('Failed to retrieve cluster list', 500);
        }
    }
    
    /**
     * Validate dashboard filter parameters
     * 
     * Validates and sanitizes query parameters for dashboard requests.
     * 
     * @param array $params Query parameters
     * @return array Validated filters
     * @throws ValidationException If validation fails
     */
    private function validateDashboardFilters($params) {
        $filters = [];
        
        // Warehouse filter (string)
        if (isset($params['warehouse']) && $params['warehouse'] !== '') {
            $filters['warehouse'] = $this->sanitizeString($params['warehouse']);
        }
        
        // Cluster filter (string)
        if (isset($params['cluster']) && $params['cluster'] !== '') {
            $filters['cluster'] = $this->sanitizeString($params['cluster']);
        }
        
        // Liquidity status filter (enum)
        if (isset($params['liquidity_status']) && $params['liquidity_status'] !== '') {
            $validStatuses = ['critical', 'low', 'normal', 'excess'];
            $status = strtolower(trim($params['liquidity_status']));
            
            if (!in_array($status, $validStatuses)) {
                throw new ValidationException(
                    'Invalid liquidity_status. Must be one of: ' . implode(', ', $validStatuses)
                );
            }
            
            $filters['liquidity_status'] = $status;
        }
        
        // Active only filter (boolean)
        if (isset($params['active_only'])) {
            $filters['active_only'] = filter_var($params['active_only'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $filters['active_only'] = true; // Default to true
        }
        
        // Has replenishment need filter (boolean)
        if (isset($params['has_replenishment_need'])) {
            $filters['has_replenishment_need'] = filter_var(
                $params['has_replenishment_need'], 
                FILTER_VALIDATE_BOOLEAN
            );
        }
        
        // Data source filter (enum) - NEW for Analytics API integration
        if (isset($params['data_source']) && $params['data_source'] !== '') {
            $validSources = ['analytics_api', 'manual', 'import', 'all'];
            $source = strtolower(trim($params['data_source']));
            
            if (!in_array($source, $validSources)) {
                throw new ValidationException(
                    'Invalid data_source. Must be one of: ' . implode(', ', $validSources)
                );
            }
            
            $filters['data_source'] = $source;
        }
        
        // Quality score filter (integer, 0-100) - NEW for Analytics API integration
        if (isset($params['quality_score'])) {
            $qualityScore = filter_var($params['quality_score'], FILTER_VALIDATE_INT);
            
            if ($qualityScore === false || $qualityScore < 0 || $qualityScore > 100) {
                throw new ValidationException('Invalid quality_score. Must be an integer between 0 and 100');
            }
            
            $filters['quality_score'] = $qualityScore;
        }
        
        // Freshness hours filter (integer, >= 0) - NEW for Analytics API integration
        if (isset($params['freshness_hours'])) {
            $freshnessHours = filter_var($params['freshness_hours'], FILTER_VALIDATE_INT);
            
            if ($freshnessHours === false || $freshnessHours < 0) {
                throw new ValidationException('Invalid freshness_hours. Must be a non-negative integer');
            }
            
            $filters['freshness_hours'] = $freshnessHours;
        }
        
        // Sort by filter (enum)
        if (isset($params['sort_by']) && $params['sort_by'] !== '') {
            $validSortFields = [
                'product_name', 
                'warehouse_name', 
                'available', 
                'daily_sales_avg', 
                'days_of_stock', 
                'replenishment_need',
                'days_without_sales',
                'data_quality_score',    // NEW for Analytics API
                'last_analytics_sync',   // NEW for Analytics API
                'data_source'            // NEW for Analytics API
            ];
            
            $sortBy = strtolower(trim($params['sort_by']));
            
            if (!in_array($sortBy, $validSortFields)) {
                throw new ValidationException(
                    'Invalid sort_by. Must be one of: ' . implode(', ', $validSortFields)
                );
            }
            
            $filters['sort_by'] = $sortBy;
        }
        
        // Sort order filter (enum)
        if (isset($params['sort_order']) && $params['sort_order'] !== '') {
            $sortOrder = strtolower(trim($params['sort_order']));
            
            if (!in_array($sortOrder, ['asc', 'desc'])) {
                throw new ValidationException('Invalid sort_order. Must be "asc" or "desc"');
            }
            
            $filters['sort_order'] = $sortOrder;
        }
        
        // Limit filter (integer, 1-1000)
        if (isset($params['limit'])) {
            $limit = filter_var($params['limit'], FILTER_VALIDATE_INT);
            
            if ($limit === false || $limit < 1) {
                throw new ValidationException('Invalid limit. Must be a positive integer');
            }
            
            $filters['limit'] = min(1000, $limit); // Cap at 1000
        }
        
        // Offset filter (integer, >= 0)
        if (isset($params['offset'])) {
            $offset = filter_var($params['offset'], FILTER_VALIDATE_INT);
            
            if ($offset === false || $offset < 0) {
                throw new ValidationException('Invalid offset. Must be a non-negative integer');
            }
            
            $filters['offset'] = $offset;
        }
        
        return $filters;
    }
    
    /**
     * Sanitize string input
     * 
     * @param string $input Input string
     * @return string Sanitized string
     */
    private function sanitizeString($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Send JSON response
     * 
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     * @return void
     */
    private function sendJsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    /**
     * Send error response
     * 
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @return void
     */
    private function sendErrorResponse($message, $statusCode = 500) {
        $this->sendJsonResponse([
            'success' => false,
            'error' => $message
        ], $statusCode);
    }
}

/**
 * Custom validation exception
 */
class ValidationException extends Exception {}

?>
