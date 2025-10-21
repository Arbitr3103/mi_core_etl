<?php
/**
 * Security Validator for Regional Analytics API
 * 
 * Provides comprehensive input validation, sanitization, and security
 * measures for the regional sales analytics system.
 */

require_once __DIR__ . '/config.php';

class SecurityValidator {
    
    // Input validation patterns
    private static $validationPatterns = [
        'date' => '/^\d{4}-\d{2}-\d{2}$/',
        'datetime' => '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
        'marketplace' => '/^(ozon|wildberries|all)$/',
        'region_code' => '/^[A-Z]{2}-[A-Z]{3}$/',
        'product_id' => '/^\d+$/',
        'client_id' => '/^\d+$/',
        'limit' => '/^\d{1,3}$/',
        'offset' => '/^\d+$/',
        'sort_field' => '/^[a-zA-Z_][a-zA-Z0-9_]*$/',
        'sort_direction' => '/^(asc|desc)$/i',
        'api_key' => '/^ra_[a-f0-9]{64}$/',
        'period' => '/^(day|week|month|quarter|year)$/',
        'metric' => '/^(revenue|orders|quantity|avg_price)$/',
        'format' => '/^(json|csv|xml)$/',
        'boolean' => '/^(true|false|1|0)$/i'
    ];
    
    // Maximum values for numeric inputs
    private static $maxValues = [
        'limit' => 1000,
        'offset' => 100000,
        'product_id' => 999999999,
        'client_id' => 999999,
        'date_range_days' => 365
    ];
    
    // Allowed SQL operators for dynamic queries
    private static $allowedOperators = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'IN', 'NOT IN'];
    
    // Allowed table columns for sorting and filtering
    private static $allowedColumns = [
        'fact_orders' => ['id', 'product_id', 'order_date', 'price', 'qty', 'source_id'],
        'dim_products' => ['id', 'product_name', 'brand', 'category', 'cost_price'],
        'ozon_regional_sales' => ['date_from', 'date_to', 'region_id', 'sales_qty', 'sales_amount']
    ];
    
    /**
     * Validate input parameter against pattern
     * @param string $type Parameter type
     * @param mixed $value Parameter value
     * @param bool $required Whether parameter is required
     * @return array Validation result with error flag and message
     */
    public static function validateInput($type, $value, $required = false) {
        $result = [
            'valid' => false,
            'value' => null,
            'error' => null
        ];
        
        // Check if value is provided when required
        if ($required && (is_null($value) || $value === '')) {
            $result['error'] = "Parameter of type '$type' is required";
            return $result;
        }
        
        // Allow null/empty for non-required parameters
        if (!$required && (is_null($value) || $value === '')) {
            $result['valid'] = true;
            $result['value'] = null;
            return $result;
        }
        
        // Convert value to string for pattern matching
        $stringValue = (string)$value;
        
        // Check if pattern exists for this type
        if (!isset(self::$validationPatterns[$type])) {
            $result['error'] = "Unknown validation type: $type";
            return $result;
        }
        
        // Validate against pattern
        if (!preg_match(self::$validationPatterns[$type], $stringValue)) {
            $result['error'] = "Invalid format for parameter type '$type'";
            return $result;
        }
        
        // Additional validation for specific types
        switch ($type) {
            case 'date':
                if (!self::validateDate($stringValue)) {
                    $result['error'] = "Invalid date: $stringValue";
                    return $result;
                }
                break;
                
            case 'datetime':
                if (!self::validateDateTime($stringValue)) {
                    $result['error'] = "Invalid datetime: $stringValue";
                    return $result;
                }
                break;
                
            case 'limit':
            case 'offset':
            case 'product_id':
            case 'client_id':
                $numValue = (int)$stringValue;
                if (isset(self::$maxValues[$type]) && $numValue > self::$maxValues[$type]) {
                    $result['error'] = "Value too large for parameter type '$type'. Maximum: " . self::$maxValues[$type];
                    return $result;
                }
                if ($numValue < 0) {
                    $result['error'] = "Negative values not allowed for parameter type '$type'";
                    return $result;
                }
                break;
        }
        
        $result['valid'] = true;
        $result['value'] = $stringValue;
        return $result;
    }
    
    /**
     * Validate date string
     * @param string $date Date string in YYYY-MM-DD format
     * @return bool True if valid date
     */
    private static function validateDate($date) {
        $parts = explode('-', $date);
        if (count($parts) !== 3) {
            return false;
        }
        
        $year = (int)$parts[0];
        $month = (int)$parts[1];
        $day = (int)$parts[2];
        
        return checkdate($month, $day, $year);
    }
    
    /**
     * Validate datetime string
     * @param string $datetime Datetime string in YYYY-MM-DD HH:MM:SS format
     * @return bool True if valid datetime
     */
    private static function validateDateTime($datetime) {
        $timestamp = strtotime($datetime);
        return $timestamp !== false && date('Y-m-d H:i:s', $timestamp) === $datetime;
    }
    
    /**
     * Validate date range
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @return array Validation result
     */
    public static function validateDateRange($dateFrom, $dateTo) {
        $result = [
            'valid' => false,
            'error' => null,
            'date_from' => null,
            'date_to' => null
        ];
        
        // Validate individual dates
        $fromValidation = self::validateInput('date', $dateFrom, true);
        if (!$fromValidation['valid']) {
            $result['error'] = 'Invalid date_from: ' . $fromValidation['error'];
            return $result;
        }
        
        $toValidation = self::validateInput('date', $dateTo, true);
        if (!$toValidation['valid']) {
            $result['error'] = 'Invalid date_to: ' . $toValidation['error'];
            return $result;
        }
        
        $fromTime = strtotime($dateFrom);
        $toTime = strtotime($dateTo);
        
        // Check if from date is not after to date
        if ($fromTime > $toTime) {
            $result['error'] = 'date_from cannot be after date_to';
            return $result;
        }
        
        // Check maximum date range
        $daysDiff = ($toTime - $fromTime) / (24 * 60 * 60);
        if ($daysDiff > self::$maxValues['date_range_days']) {
            $result['error'] = 'Date range cannot exceed ' . self::$maxValues['date_range_days'] . ' days';
            return $result;
        }
        
        // Check minimum date
        $minTime = strtotime(ANALYTICS_MIN_DATE);
        if ($fromTime < $minTime) {
            $result['error'] = 'date_from cannot be before ' . ANALYTICS_MIN_DATE;
            return $result;
        }
        
        $result['valid'] = true;
        $result['date_from'] = $dateFrom;
        $result['date_to'] = $dateTo;
        return $result;
    }
    
    /**
     * Sanitize string input to prevent XSS and injection attacks
     * @param string $input Input string
     * @param bool $allowHtml Whether to allow HTML tags
     * @return string Sanitized string
     */
    public static function sanitizeString($input, $allowHtml = false) {
        if (!is_string($input)) {
            return '';
        }
        
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        if (!$allowHtml) {
            // Remove HTML tags
            $input = strip_tags($input);
            
            // Convert special characters to HTML entities
            $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        
        return $input;
    }
    
    /**
     * Validate and sanitize SQL column name
     * @param string $column Column name
     * @param string $table Table name (optional)
     * @return array Validation result
     */
    public static function validateSqlColumn($column, $table = null) {
        $result = [
            'valid' => false,
            'column' => null,
            'error' => null
        ];
        
        // Basic pattern validation
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            $result['error'] = 'Invalid column name format';
            return $result;
        }
        
        // Check against allowed columns if table is specified
        if ($table && isset(self::$allowedColumns[$table])) {
            if (!in_array($column, self::$allowedColumns[$table])) {
                $result['error'] = "Column '$column' not allowed for table '$table'";
                return $result;
            }
        }
        
        $result['valid'] = true;
        $result['column'] = $column;
        return $result;
    }
    
    /**
     * Validate SQL operator
     * @param string $operator SQL operator
     * @return bool True if operator is allowed
     */
    public static function validateSqlOperator($operator) {
        return in_array(strtoupper($operator), self::$allowedOperators);
    }
    
    /**
     * Escape SQL identifier (table/column name)
     * @param string $identifier SQL identifier
     * @return string Escaped identifier
     */
    public static function escapeSqlIdentifier($identifier) {
        // Remove any existing backticks
        $identifier = str_replace('`', '', $identifier);
        
        // Validate identifier format
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new InvalidArgumentException('Invalid SQL identifier format');
        }
        
        return '`' . $identifier . '`';
    }
    
    /**
     * Validate request parameters against schema
     * @param array $params Request parameters
     * @param array $schema Parameter schema
     * @return array Validation result with validated parameters
     */
    public static function validateRequestParams($params, $schema) {
        $result = [
            'valid' => true,
            'errors' => [],
            'params' => []
        ];
        
        foreach ($schema as $paramName => $config) {
            $type = $config['type'] ?? 'string';
            $required = $config['required'] ?? false;
            $default = $config['default'] ?? null;
            
            $value = $params[$paramName] ?? $default;
            
            $validation = self::validateInput($type, $value, $required);
            
            if (!$validation['valid']) {
                $result['valid'] = false;
                $result['errors'][$paramName] = $validation['error'];
            } else {
                $result['params'][$paramName] = $validation['value'];
            }
        }
        
        return $result;
    }
    
    /**
     * Check for SQL injection patterns
     * @param string $input Input string
     * @return bool True if suspicious patterns found
     */
    public static function detectSqlInjection($input) {
        if (!is_string($input)) {
            return false;
        }
        
        $suspiciousPatterns = [
            '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|EXECUTE)\b)/i',
            '/(\b(UNION|OR|AND)\s+\d+\s*=\s*\d+)/i',
            '/(\'|\")(\s*;\s*|\s*--|\s*\/\*)/i',
            '/(\bxp_cmdshell\b|\bsp_executesql\b)/i',
            '/(\b(INFORMATION_SCHEMA|SYSOBJECTS|SYSCOLUMNS)\b)/i'
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Validate IP address and check against whitelist/blacklist
     * @param string $ip IP address
     * @return array Validation result
     */
    public static function validateIpAddress($ip) {
        $result = [
            'valid' => false,
            'ip' => null,
            'error' => null
        ];
        
        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $result['error'] = 'Invalid IP address format';
            return $result;
        }
        
        // Check if it's a private IP (for development)
        $isPrivate = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;
        
        // Log suspicious IPs (non-private)
        if (!$isPrivate) {
            logAnalyticsActivity('INFO', 'External IP access', ['ip' => $ip]);
        }
        
        $result['valid'] = true;
        $result['ip'] = $ip;
        return $result;
    }
    
    /**
     * Generate CSRF token
     * @return string CSRF token
     */
    public static function generateCsrfToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        
        return $token;
    }
    
    /**
     * Validate CSRF token
     * @param string $token Token to validate
     * @return bool True if valid
     */
    public static function validateCsrfToken($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Get client IP address (considering proxies)
     * @return string Client IP address
     */
    public static function getClientIp() {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Log security event
     * @param string $event Event type
     * @param string $message Event message
     * @param array $context Additional context
     */
    public static function logSecurityEvent($event, $message, $context = []) {
        $context['ip'] = self::getClientIp();
        $context['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        $context['timestamp'] = date('c');
        
        logAnalyticsActivity('WARNING', "SECURITY: $event - $message", $context);
    }
}

/**
 * Middleware function for comprehensive input validation
 * @param array $paramSchema Parameter validation schema
 * @return array Validated parameters
 */
function validateSecureInput($paramSchema) {
    $params = [];
    
    // Get parameters from request
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $params = $_GET;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $params = $_POST;
        
        // Also check JSON body
        $jsonInput = file_get_contents('php://input');
        if ($jsonInput) {
            $jsonData = json_decode($jsonInput, true);
            if ($jsonData) {
                $params = array_merge($params, $jsonData);
            }
        }
    }
    
    // Validate parameters
    $validation = SecurityValidator::validateRequestParams($params, $paramSchema);
    
    if (!$validation['valid']) {
        $errors = implode(', ', $validation['errors']);
        SecurityValidator::logSecurityEvent('INVALID_INPUT', $errors);
        sendAnalyticsErrorResponse('Invalid input parameters: ' . $errors, 400, 'INVALID_INPUT');
    }
    
    // Check for SQL injection attempts
    foreach ($params as $key => $value) {
        if (is_string($value) && SecurityValidator::detectSqlInjection($value)) {
            SecurityValidator::logSecurityEvent('SQL_INJECTION_ATTEMPT', "Suspicious input in parameter: $key");
            sendAnalyticsErrorResponse('Invalid input detected', 400, 'INVALID_INPUT');
        }
    }
    
    return $validation['params'];
}
?>