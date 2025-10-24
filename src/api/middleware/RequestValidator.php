<?php
/**
 * Request Validator
 * 
 * Validates API request parameters and provides detailed error responses
 * 
 * @version 1.0
 * @author Manhattan System
 */

class RequestValidator {
    
    private $logger;
    private $errors;
    
    public function __construct() {
        $this->logger = Logger::getInstance();
        $this->errors = [];
    }
    
    /**
     * Validate warehouse stock API parameters
     * 
     * @param array $params - Request parameters
     * @return array Validation result
     */
    public function validateWarehouseStockParams(array $params): array {
        $this->errors = [];
        
        // Validate warehouse parameter
        if (isset($params['warehouse'])) {
            $this->validateWarehouseName($params['warehouse']);
        }
        
        // Validate product_id parameter
        if (isset($params['product_id'])) {
            $this->validateProductId($params['product_id']);
        }
        
        // Validate SKU parameter
        if (isset($params['sku'])) {
            $this->validateSku($params['sku']);
        }
        
        // Validate source parameter
        if (isset($params['source'])) {
            $this->validateSource($params['source']);
        }
        
        // Validate stock_level parameter
        if (isset($params['stock_level'])) {
            $this->validateStockLevel($params['stock_level']);
        }
        
        // Validate date parameters
        if (isset($params['date_from'])) {
            $this->validateDate($params['date_from'], 'date_from');
        }
        
        if (isset($params['date_to'])) {
            $this->validateDate($params['date_to'], 'date_to');
        }
        
        // Validate date range
        if (isset($params['date_from']) && isset($params['date_to'])) {
            $this->validateDateRange($params['date_from'], $params['date_to']);
        }
        
        // Validate pagination parameters
        if (isset($params['limit'])) {
            $this->validateLimit($params['limit'], 1000);
        }
        
        if (isset($params['offset'])) {
            $this->validateOffset($params['offset']);
        }
        
        // Validate sorting parameters
        if (isset($params['sort_by'])) {
            $validSortFields = [
                'warehouse_name', 'product_id', 'quantity_present', 
                'quantity_reserved', 'updated_at', 'last_report_update'
            ];
            $this->validateSortBy($params['sort_by'], $validSortFields);
        }
        
        if (isset($params['sort_order'])) {
            $this->validateSortOrder($params['sort_order']);
        }
        
        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors
        ];
    }
    
    /**
     * Validate stock reports API parameters
     * 
     * @param array $params - Request parameters
     * @return array Validation result
     */
    public function validateStockReportsParams(array $params): array {
        $this->errors = [];
        
        // Validate status parameter
        if (isset($params['status'])) {
            $validStatuses = ['REQUESTED', 'PROCESSING', 'SUCCESS', 'ERROR', 'TIMEOUT'];
            $this->validateEnum($params['status'], $validStatuses, 'status');
        }
        
        // Validate report_type parameter
        if (isset($params['report_type'])) {
            $validTypes = ['warehouse_stock'];
            $this->validateEnum($params['report_type'], $validTypes, 'report_type');
        }
        
        // Validate date parameters
        if (isset($params['date_from'])) {
            $this->validateDate($params['date_from'], 'date_from');
        }
        
        if (isset($params['date_to'])) {
            $this->validateDate($params['date_to'], 'date_to');
        }
        
        // Validate date range
        if (isset($params['date_from']) && isset($params['date_to'])) {
            $this->validateDateRange($params['date_from'], $params['date_to']);
        }
        
        // Validate pagination parameters
        if (isset($params['limit'])) {
            $this->validateLimit($params['limit'], 500);
        }
        
        if (isset($params['offset'])) {
            $this->validateOffset($params['offset']);
        }
        
        // Validate sorting parameters
        if (isset($params['sort_by'])) {
            $validSortFields = ['requested_at', 'completed_at', 'status', 'report_type', 'records_processed'];
            $this->validateSortBy($params['sort_by'], $validSortFields);
        }
        
        if (isset($params['sort_order'])) {
            $this->validateSortOrder($params['sort_order']);
        }
        
        return [
            'valid' => empty($this->errors),
            'errors' => $this->errors
        ];
    }
    
    /**
     * Validate warehouse name
     * 
     * @param mixed $warehouse - Warehouse name to validate
     */
    private function validateWarehouseName($warehouse): void {
        if (!is_string($warehouse)) {
            $this->addError('warehouse', 'Warehouse name must be a string');
            return;
        }
        
        $warehouse = trim($warehouse);
        
        if (empty($warehouse)) {
            $this->addError('warehouse', 'Warehouse name cannot be empty');
            return;
        }
        
        if (strlen($warehouse) > 255) {
            $this->addError('warehouse', 'Warehouse name cannot exceed 255 characters');
            return;
        }
        
        // Check for valid characters (allow Unicode for Russian warehouse names)
        if (!preg_match('/^[\p{L}\p{N}\s\-_\.]+$/u', $warehouse)) {
            $this->addError('warehouse', 'Warehouse name contains invalid characters');
        }
    }
    
    /**
     * Validate product ID
     * 
     * @param mixed $productId - Product ID to validate
     */
    private function validateProductId($productId): void {
        if (!is_numeric($productId)) {
            $this->addError('product_id', 'Product ID must be numeric');
            return;
        }
        
        $productId = (int) $productId;
        
        if ($productId <= 0) {
            $this->addError('product_id', 'Product ID must be a positive integer');
        }
        
        if ($productId > 2147483647) { // Max INT value
            $this->addError('product_id', 'Product ID is too large');
        }
    }
    
    /**
     * Validate SKU
     * 
     * @param mixed $sku - SKU to validate
     */
    private function validateSku($sku): void {
        if (!is_string($sku)) {
            $this->addError('sku', 'SKU must be a string');
            return;
        }
        
        $sku = trim($sku);
        
        if (empty($sku)) {
            $this->addError('sku', 'SKU cannot be empty');
            return;
        }
        
        if (strlen($sku) > 100) {
            $this->addError('sku', 'SKU cannot exceed 100 characters');
            return;
        }
        
        // Allow alphanumeric characters, hyphens, underscores
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $sku)) {
            $this->addError('sku', 'SKU contains invalid characters (only letters, numbers, hyphens, and underscores allowed)');
        }
    }
    
    /**
     * Validate source
     * 
     * @param mixed $source - Source to validate
     */
    private function validateSource($source): void {
        $validSources = ['Ozon', 'Wildberries'];
        $this->validateEnum($source, $validSources, 'source');
    }
    
    /**
     * Validate stock level
     * 
     * @param mixed $stockLevel - Stock level to validate
     */
    private function validateStockLevel($stockLevel): void {
        $validLevels = ['zero', 'low', 'normal', 'high'];
        $this->validateEnum($stockLevel, $validLevels, 'stock_level');
    }
    
    /**
     * Validate enum value
     * 
     * @param mixed $value - Value to validate
     * @param array $validValues - Valid enum values
     * @param string $fieldName - Field name for error message
     */
    private function validateEnum($value, array $validValues, string $fieldName): void {
        if (!is_string($value)) {
            $this->addError($fieldName, ucfirst($fieldName) . ' must be a string');
            return;
        }
        
        if (!in_array($value, $validValues)) {
            $this->addError($fieldName, ucfirst($fieldName) . ' must be one of: ' . implode(', ', $validValues));
        }
    }
    
    /**
     * Validate date format
     * 
     * @param mixed $date - Date to validate
     * @param string $fieldName - Field name for error message
     */
    private function validateDate($date, string $fieldName): void {
        if (!is_string($date)) {
            $this->addError($fieldName, ucfirst($fieldName) . ' must be a string');
            return;
        }
        
        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        
        if (!$dateTime || $dateTime->format('Y-m-d') !== $date) {
            $this->addError($fieldName, ucfirst($fieldName) . ' must be in YYYY-MM-DD format');
            return;
        }
        
        // Check if date is not too far in the past or future
        $now = new DateTime();
        $minDate = (clone $now)->modify('-2 years');
        $maxDate = (clone $now)->modify('+1 year');
        
        if ($dateTime < $minDate) {
            $this->addError($fieldName, ucfirst($fieldName) . ' cannot be more than 2 years in the past');
        }
        
        if ($dateTime > $maxDate) {
            $this->addError($fieldName, ucfirst($fieldName) . ' cannot be more than 1 year in the future');
        }
    }
    
    /**
     * Validate date range
     * 
     * @param string $dateFrom - Start date
     * @param string $dateTo - End date
     */
    private function validateDateRange(string $dateFrom, string $dateTo): void {
        $dateFromObj = DateTime::createFromFormat('Y-m-d', $dateFrom);
        $dateToObj = DateTime::createFromFormat('Y-m-d', $dateTo);
        
        if (!$dateFromObj || !$dateToObj) {
            return; // Individual date validation will catch format errors
        }
        
        if ($dateFromObj > $dateToObj) {
            $this->addError('date_range', 'date_from cannot be later than date_to');
        }
        
        // Check if date range is not too large (max 1 year)
        $interval = $dateFromObj->diff($dateToObj);
        if ($interval->days > 365) {
            $this->addError('date_range', 'Date range cannot exceed 365 days');
        }
    }
    
    /**
     * Validate limit parameter
     * 
     * @param mixed $limit - Limit to validate
     * @param int $maxLimit - Maximum allowed limit
     */
    private function validateLimit($limit, int $maxLimit): void {
        if (!is_numeric($limit)) {
            $this->addError('limit', 'Limit must be numeric');
            return;
        }
        
        $limit = (int) $limit;
        
        if ($limit <= 0) {
            $this->addError('limit', 'Limit must be a positive integer');
        }
        
        if ($limit > $maxLimit) {
            $this->addError('limit', "Limit cannot exceed {$maxLimit}");
        }
    }
    
    /**
     * Validate offset parameter
     * 
     * @param mixed $offset - Offset to validate
     */
    private function validateOffset($offset): void {
        if (!is_numeric($offset)) {
            $this->addError('offset', 'Offset must be numeric');
            return;
        }
        
        $offset = (int) $offset;
        
        if ($offset < 0) {
            $this->addError('offset', 'Offset must be non-negative');
        }
        
        // Reasonable upper limit to prevent abuse
        if ($offset > 1000000) {
            $this->addError('offset', 'Offset is too large (maximum 1,000,000)');
        }
    }
    
    /**
     * Validate sort_by parameter
     * 
     * @param mixed $sortBy - Sort field to validate
     * @param array $validFields - Valid sort fields
     */
    private function validateSortBy($sortBy, array $validFields): void {
        if (!is_string($sortBy)) {
            $this->addError('sort_by', 'sort_by must be a string');
            return;
        }
        
        if (!in_array($sortBy, $validFields)) {
            $this->addError('sort_by', 'sort_by must be one of: ' . implode(', ', $validFields));
        }
    }
    
    /**
     * Validate sort_order parameter
     * 
     * @param mixed $sortOrder - Sort order to validate
     */
    private function validateSortOrder($sortOrder): void {
        if (!is_string($sortOrder)) {
            $this->addError('sort_order', 'sort_order must be a string');
            return;
        }
        
        $sortOrder = strtoupper($sortOrder);
        
        if (!in_array($sortOrder, ['ASC', 'DESC'])) {
            $this->addError('sort_order', 'sort_order must be ASC or DESC');
        }
    }
    
    /**
     * Add validation error
     * 
     * @param string $field - Field name
     * @param string $message - Error message
     */
    private function addError(string $field, string $message): void {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        
        $this->errors[$field][] = $message;
    }
    
    /**
     * Get formatted error response
     * 
     * @return array Error response
     */
    public function getErrorResponse(): array {
        return [
            'success' => false,
            'error' => [
                'message' => 'Validation failed',
                'code' => 400,
                'details' => $this->errors
            ],
            'timestamp' => date('c')
        ];
    }
    
    /**
     * Validate report code format
     * 
     * @param string $reportCode - Report code to validate
     * @return bool True if valid
     */
    public function validateReportCode(string $reportCode): bool {
        // Report codes should follow pattern: RPT_YYYYMMDD_NNN or similar
        if (strlen($reportCode) < 3 || strlen($reportCode) > 50) {
            return false;
        }
        
        // Allow alphanumeric characters, hyphens, underscores
        return preg_match('/^[a-zA-Z0-9\-_]+$/', $reportCode);
    }
    
    /**
     * Sanitize string input
     * 
     * @param string $input - Input to sanitize
     * @return string Sanitized input
     */
    public function sanitizeString(string $input): string {
        // Remove null bytes and control characters
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        return $input;
    }
    
    /**
     * Check if request contains suspicious patterns
     * 
     * @param array $params - Request parameters
     * @return bool True if suspicious
     */
    public function containsSuspiciousPatterns(array $params): bool {
        $suspiciousPatterns = [
            '/union\s+select/i',
            '/drop\s+table/i',
            '/delete\s+from/i',
            '/insert\s+into/i',
            '/update\s+set/i',
            '/<script/i',
            '/javascript:/i',
            '/on\w+\s*=/i'
        ];
        
        foreach ($params as $value) {
            if (!is_string($value)) {
                continue;
            }
            
            foreach ($suspiciousPatterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    $this->logger->warning('Suspicious request pattern detected', [
                        'pattern' => $pattern,
                        'value' => substr($value, 0, 100),
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    return true;
                }
            }
        }
        
        return false;
    }
}