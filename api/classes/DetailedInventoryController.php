<?php
/**
 * Detailed Inventory Controller
 * 
 * Controller for the new detailed inventory API endpoints.
 * Handles requests for product-warehouse level inventory data.
 * 
 * Requirements: 6.1, 6.4, 6.5
 * Task: 1.2 Implement new API endpoint `/api/inventory/detailed-stock`
 */

require_once __DIR__ . '/DetailedInventoryService.php';
require_once __DIR__ . '/EnhancedCacheService.php';

class DetailedInventoryController {
    
    private $service;
    private $cache;
    
    /**
     * Constructor
     * @param PDO $pdo Database connection
     */
    public function __construct($pdo) {
        $this->cache = new EnhancedCacheService($pdo);
        $this->service = new DetailedInventoryService($pdo, $this->cache);
    }
    
    /**
     * Get detailed inventory data
     * 
     * Handles GET /api/inventory/detailed-stock
     * Returns product-warehouse pairs with calculated metrics.
     * 
     * Requirements: 6.1, 6.4, 6.5
     * 
     * Query Parameters:
     * - warehouses[] (optional): Array of warehouse names to filter by
     * - warehouse (optional): Single warehouse name to filter by
     * - statuses[] (optional): Array of status levels (critical, low, normal, excess, out_of_stock)
     * - status (optional): Single status level to filter by
     * - search (optional): Product name/SKU search term
     * - min_days_of_stock (optional): Minimum days of stock
     * - max_days_of_stock (optional): Maximum days of stock
     * - min_urgency_score (optional): Minimum urgency score (0-100)
     * - has_replenishment_need (optional): Show only products needing replenishment
     * - active_only (optional): Show only products with stock or recent sales
     * - include_hidden (optional): Include archived/hidden and out of stock products (default: false)
     * - visibility (optional): Filter by visibility status (VISIBLE, HIDDEN, etc.)
     * - sort_by (optional): Field to sort by
     * - sort_order (optional): Sort direction (asc/desc)
     * - limit (optional): Number of items per page (max 1000)
     * - offset (optional): Pagination offset
     * 
     * @return void Outputs JSON response
     */
    public function getDetailedStock() {
        try {
            // Validate and extract query parameters
            $filters = $this->validateFilters($_GET);
            
            // Get detailed inventory data from service
            $result = $this->service->getDetailedInventory($filters);
            
            // Return response
            $this->sendJsonResponse($result);
            
        } catch (DetailedInventoryValidationException $e) {
            $this->sendErrorResponse($e->getMessage(), 400);
        } catch (Exception $e) {
            error_log("Error in DetailedInventoryController::getDetailedStock: " . $e->getMessage());
            $this->sendErrorResponse('Internal server error', 500);
        }
    }
    
    /**
     * Get list of warehouses for filter options
     * 
     * Handles GET /api/inventory/warehouses
     * Returns list of warehouses with product counts and statistics.
     * 
     * @return void Outputs JSON response
     */
    public function getWarehouses() {
        try {
            $result = $this->service->getWarehouses();
            $this->sendJsonResponse($result);
            
        } catch (Exception $e) {
            error_log("Error in DetailedInventoryController::getWarehouses: " . $e->getMessage());
            $this->sendErrorResponse('Failed to retrieve warehouse list', 500);
        }
    }
    
    /**
     * Get summary statistics
     * 
     * Handles GET /api/inventory/summary
     * Returns overall inventory statistics for dashboard overview.
     * 
     * @return void Outputs JSON response
     */
    public function getSummary() {
        try {
            $result = $this->service->getSummaryStats();
            $this->sendJsonResponse($result);
            
        } catch (Exception $e) {
            error_log("Error in DetailedInventoryController::getSummary: " . $e->getMessage());
            $this->sendErrorResponse('Failed to retrieve summary statistics', 500);
        }
    }
    
    /**
     * Get cache statistics
     * 
     * Handles GET /api/inventory/cache-stats
     * Returns cache performance and usage statistics.
     * 
     * @return void Outputs JSON response
     */
    public function getCacheStats() {
        try {
            $stats = $this->cache->getStats();
            $this->sendJsonResponse([
                'success' => true,
                'data' => $stats
            ]);
            
        } catch (Exception $e) {
            error_log("Error in DetailedInventoryController::getCacheStats: " . $e->getMessage());
            $this->sendErrorResponse('Failed to retrieve cache statistics', 500);
        }
    }
    
    /**
     * Clear cache
     * 
     * Handles POST /api/inventory/clear-cache
     * Clears all cached inventory data.
     * 
     * @return void Outputs JSON response
     */
    public function clearCache() {
        try {
            $success = $this->cache->clear();
            
            if ($success) {
                $this->sendJsonResponse([
                    'success' => true,
                    'message' => 'Cache cleared successfully'
                ]);
            } else {
                $this->sendErrorResponse('Failed to clear cache', 500);
            }
            
        } catch (Exception $e) {
            error_log("Error in DetailedInventoryController::clearCache: " . $e->getMessage());
            $this->sendErrorResponse('Failed to clear cache', 500);
        }
    }
    
    /**
     * Validate filter parameters
     * 
     * Validates and sanitizes query parameters for detailed inventory requests.
     * 
     * @param array $params Query parameters
     * @return array Validated filters
     * @throws DetailedInventoryValidationException If validation fails
     */
    private function validateFilters($params) {
        $filters = [];
        
        // Warehouse filters
        if (isset($params['warehouses']) && is_array($params['warehouses'])) {
            $filters['warehouses'] = array_map([$this, 'sanitizeString'], $params['warehouses']);
            $filters['warehouses'] = array_filter($filters['warehouses']); // Remove empty values
        } elseif (isset($params['warehouse']) && $params['warehouse'] !== '') {
            $filters['warehouse'] = $this->sanitizeString($params['warehouse']);
        }
        
        // Status filters
        if (isset($params['statuses']) && is_array($params['statuses'])) {
            $validStatuses = ['critical', 'low', 'normal', 'excess', 'out_of_stock', 'no_sales'];
            $statuses = array_map('strtolower', array_map('trim', $params['statuses']));
            $statuses = array_filter($statuses, function($status) use ($validStatuses) {
                return in_array($status, $validStatuses);
            });
            
            if (!empty($statuses)) {
                $filters['statuses'] = $statuses;
            }
        } elseif (isset($params['status']) && $params['status'] !== '') {
            $validStatuses = ['critical', 'low', 'normal', 'excess', 'out_of_stock', 'no_sales'];
            $status = strtolower(trim($params['status']));
            
            if (!in_array($status, $validStatuses)) {
                throw new DetailedInventoryValidationException(
                    'Invalid status. Must be one of: ' . implode(', ', $validStatuses)
                );
            }
            
            $filters['status'] = $status;
        }
        
        // Search filter
        if (isset($params['search']) && $params['search'] !== '') {
            $search = trim($params['search']);
            if (strlen($search) < 2) {
                throw new DetailedInventoryValidationException(
                    'Search term must be at least 2 characters long'
                );
            }
            $filters['search'] = $search;
        }
        
        // Days of stock filters
        if (isset($params['min_days_of_stock'])) {
            $minDays = filter_var($params['min_days_of_stock'], FILTER_VALIDATE_FLOAT);
            if ($minDays === false || $minDays < 0) {
                throw new DetailedInventoryValidationException(
                    'Invalid min_days_of_stock. Must be a non-negative number'
                );
            }
            $filters['min_days_of_stock'] = $minDays;
        }
        
        if (isset($params['max_days_of_stock'])) {
            $maxDays = filter_var($params['max_days_of_stock'], FILTER_VALIDATE_FLOAT);
            if ($maxDays === false || $maxDays < 0) {
                throw new DetailedInventoryValidationException(
                    'Invalid max_days_of_stock. Must be a non-negative number'
                );
            }
            $filters['max_days_of_stock'] = $maxDays;
        }
        
        // Urgency score filter
        if (isset($params['min_urgency_score'])) {
            $urgencyScore = filter_var($params['min_urgency_score'], FILTER_VALIDATE_INT);
            if ($urgencyScore === false || $urgencyScore < 0 || $urgencyScore > 100) {
                throw new DetailedInventoryValidationException(
                    'Invalid min_urgency_score. Must be an integer between 0 and 100'
                );
            }
            $filters['min_urgency_score'] = $urgencyScore;
        }
        
        // Boolean filters
        if (isset($params['has_replenishment_need'])) {
            $filters['has_replenishment_need'] = filter_var(
                $params['has_replenishment_need'], 
                FILTER_VALIDATE_BOOLEAN
            );
        }
        
        if (isset($params['active_only'])) {
            $filters['active_only'] = filter_var(
                $params['active_only'], 
                FILTER_VALIDATE_BOOLEAN
            );
        } else {
            $filters['active_only'] = true; // Default to true
        }
        
        // Include hidden products filter (default: false)
        // When false, excludes archived_or_hidden and out_of_stock items
        if (isset($params['include_hidden'])) {
            $filters['include_hidden'] = filter_var(
                $params['include_hidden'], 
                FILTER_VALIDATE_BOOLEAN
            );
        }
        
        // Visibility filter
        if (isset($params['visibility']) && $params['visibility'] !== '') {
            $visibility = $this->sanitizeString($params['visibility']);
            $validVisibility = ['VISIBLE', 'HIDDEN', 'MODERATION', 'UNKNOWN'];
            
            if (!in_array(strtoupper($visibility), $validVisibility)) {
                throw new DetailedInventoryValidationException(
                    'Invalid visibility. Must be one of: ' . implode(', ', $validVisibility)
                );
            }
            
            $filters['visibility'] = strtoupper($visibility);
        }
        
        // Sort parameters
        if (isset($params['sort_by']) && $params['sort_by'] !== '') {
            $validSortFields = [
                'product_name', 
                'warehouse_name', 
                'current_stock',
                'available_stock',
                'daily_sales_avg', 
                'days_of_stock', 
                'stock_status',
                'recommended_qty',
                'urgency_score',
                'stockout_risk',
                'turnover_rate',
                'last_updated'
            ];
            
            $sortBy = strtolower(trim($params['sort_by']));
            
            if (!in_array($sortBy, $validSortFields)) {
                throw new DetailedInventoryValidationException(
                    'Invalid sort_by. Must be one of: ' . implode(', ', $validSortFields)
                );
            }
            
            $filters['sort_by'] = $sortBy;
        }
        
        if (isset($params['sort_order']) && $params['sort_order'] !== '') {
            $sortOrder = strtolower(trim($params['sort_order']));
            
            if (!in_array($sortOrder, ['asc', 'desc'])) {
                throw new DetailedInventoryValidationException(
                    'Invalid sort_order. Must be "asc" or "desc"'
                );
            }
            
            $filters['sort_order'] = $sortOrder;
        }
        
        // Pagination parameters
        if (isset($params['limit'])) {
            $limit = filter_var($params['limit'], FILTER_VALIDATE_INT);
            
            if ($limit === false || $limit < 1) {
                throw new DetailedInventoryValidationException(
                    'Invalid limit. Must be a positive integer'
                );
            }
            
            $filters['limit'] = min(1000, $limit); // Cap at 1000
        }
        
        if (isset($params['offset'])) {
            $offset = filter_var($params['offset'], FILTER_VALIDATE_INT);
            
            if ($offset === false || $offset < 0) {
                throw new DetailedInventoryValidationException(
                    'Invalid offset. Must be a non-negative integer'
                );
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
            'error' => $message,
            'timestamp' => date('c')
        ], $statusCode);
    }
}

?>