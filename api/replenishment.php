<?php
/**
 * Replenishment Recommendations API
 * 
 * Provides endpoints for inventory replenishment recommendations system.
 * Supports getting recommendations, generating reports, calculating new recommendations,
 * and managing configuration parameters.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include configuration (automatically detects environment)
require_once __DIR__ . '/../config_replenishment.php';

// Include required classes
require_once __DIR__ . '/../src/Replenishment/ReplenishmentRecommender.php';
require_once __DIR__ . '/../src/Replenishment/SalesAnalyzer.php';
require_once __DIR__ . '/../src/Replenishment/StockCalculator.php';

use Replenishment\ReplenishmentRecommender;
use Replenishment\SalesAnalyzer;
use Replenishment\StockCalculator;

/**
 * ReplenishmentAPI Class
 * 
 * Handles API requests for replenishment recommendations system
 */
class ReplenishmentAPI
{
    private PDO $pdo;
    private ReplenishmentRecommender $recommender;
    private array $config;
    private array $requestLog;
    
    public function __construct()
    {
        $this->initializeDatabase();
        $this->initializeConfig();
        $this->initializeRecommender();
        $this->initializeRequestLog();
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void
    {
        $this->loadEnvConfig();
        
        try {
            $this->pdo = new PDO(
                "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . 
                ";dbname=" . ($_ENV['DB_NAME'] ?? 'mi_core_db') . 
                ";charset=utf8mb4",
                $_ENV['DB_USER'] ?? 'v_admin',
                $_ENV['DB_PASSWORD'] ?? '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 30
                ]
            );
        } catch (PDOException $e) {
            $this->sendErrorResponse(500, 'DATABASE_CONNECTION_FAILED', 
                'Database connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Load environment configuration
     */
    private function loadEnvConfig(): void
    {
        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $value = trim($value, '"\'');
                    $_ENV[trim($key)] = $value;
                }
            }
        }
    }
    
    /**
     * Initialize configuration
     */
    private function initializeConfig(): void
    {
        $this->config = [
            'debug' => ($_ENV['DEBUG'] ?? 'false') === 'true',
            'rate_limit_enabled' => true,
            'rate_limit_requests' => 100,
            'rate_limit_window' => 3600, // 1 hour
            'max_page_size' => 1000,
            'default_page_size' => 50
        ];
    }
    
    /**
     * Initialize ReplenishmentRecommender
     */
    private function initializeRecommender(): void
    {
        try {
            $this->recommender = new ReplenishmentRecommender($this->pdo, [
                'debug' => $this->config['debug']
            ]);
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'RECOMMENDER_INIT_FAILED', 
                'Failed to initialize recommender: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize request logging
     */
    private function initializeRequestLog(): void
    {
        $this->requestLog = [
            'timestamp' => date('Y-m-d H:i:s'),
            'method' => $_SERVER['REQUEST_METHOD'],
            'action' => $_GET['action'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
    }
    
    /**
     * Handle API request
     */
    public function handleRequest(): void
    {
        try {
            // Validate GET parameters
            $this->validateGetParameters();
            
            // Check rate limiting
            if ($this->config['rate_limit_enabled']) {
                $this->checkRateLimit();
            }
            
            $action = $_GET['action'] ?? '';
            $method = $_SERVER['REQUEST_METHOD'];
            
            // Log request
            $this->logRequest($action, $method);
            
            // Route request to appropriate handler
            switch ($action) {
                case 'recommendations':
                    if ($method === 'GET') {
                        $this->handleGetRecommendations();
                    } else {
                        $this->sendErrorResponse(405, 'METHOD_NOT_ALLOWED', 
                            'Method not allowed for recommendations endpoint');
                    }
                    break;
                    
                case 'report':
                    if ($method === 'GET') {
                        $this->handleGetReport();
                    } else {
                        $this->sendErrorResponse(405, 'METHOD_NOT_ALLOWED', 
                            'Method not allowed for report endpoint');
                    }
                    break;
                    
                case 'calculate':
                    if ($method === 'POST') {
                        $this->handlePostCalculate();
                    } else {
                        $this->sendErrorResponse(405, 'METHOD_NOT_ALLOWED', 
                            'Method not allowed for calculate endpoint');
                    }
                    break;
                    
                case 'config':
                    if ($method === 'GET') {
                        $this->handleGetConfig();
                    } elseif ($method === 'POST') {
                        $this->handlePostConfig();
                    } else {
                        $this->sendErrorResponse(405, 'METHOD_NOT_ALLOWED', 
                            'Method not allowed for config endpoint');
                    }
                    break;
                    
                case 'status':
                    $this->handleGetStatus();
                    break;
                    
                default:
                    $this->sendErrorResponse(400, 'INVALID_ACTION', 
                        'Invalid or missing action parameter');
            }
            
        } catch (Exception $e) {
            $this->logError($e);
            $this->sendErrorResponse(500, 'INTERNAL_SERVER_ERROR', 
                'Internal server error: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle GET /api/replenishment.php?action=recommendations
     */
    private function handleGetRecommendations(): void
    {
        try {
            // Parse and validate parameters
            $filters = $this->parseRecommendationFilters();
            $pagination = $this->parsePaginationParams();
            
            // Get recommendations with pagination
            $result = $this->recommender->getRecommendationsPaginated(
                $pagination['page'],
                $pagination['per_page'],
                $filters
            );
            
            // Add metadata
            $response = [
                'success' => true,
                'data' => $result['data'],
                'pagination' => $result['pagination'],
                'filters_applied' => $filters,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            $this->sendSuccessResponse($response);
            
        } catch (Exception $e) {
            $this->sendErrorResponse(400, 'RECOMMENDATIONS_FETCH_FAILED', 
                'Failed to fetch recommendations: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle GET /api/replenishment.php?action=report
     */
    private function handleGetReport(): void
    {
        try {
            // Check if we should generate a new report or return existing
            $forceGenerate = filter_var($_GET['force'] ?? false, FILTER_VALIDATE_BOOLEAN);
            
            if ($forceGenerate) {
                // Generate fresh weekly report
                $report = $this->recommender->generateWeeklyReport();
            } else {
                // Get existing recommendations and format as report
                $recommendations = $this->recommender->getRecommendations([
                    'actionable_only' => false
                ]);
                
                $actionableRecommendations = array_filter($recommendations, function($rec) {
                    return $rec['recommended_quantity'] > 0;
                });
                
                // Sort by recommended quantity (descending)
                usort($actionableRecommendations, function($a, $b) {
                    return $b['recommended_quantity'] <=> $a['recommended_quantity'];
                });
                
                $report = [
                    'report_date' => date('Y-m-d'),
                    'generation_time' => date('Y-m-d H:i:s'),
                    'summary' => $this->generateReportSummary($recommendations),
                    'actionable_recommendations' => $actionableRecommendations,
                    'all_recommendations' => $recommendations
                ];
            }
            
            $response = [
                'success' => true,
                'data' => $report,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            $this->sendSuccessResponse($response);
            
        } catch (Exception $e) {
            $this->sendErrorResponse(400, 'REPORT_GENERATION_FAILED', 
                'Failed to generate report: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle POST /api/replenishment.php?action=calculate
     */
    private function handlePostCalculate(): void
    {
        try {
            // Parse request body
            $input = $this->getJsonInput();
            
            // Validate input
            $productIds = $input['product_ids'] ?? null;
            $forceRecalculate = $input['force'] ?? false;
            
            if ($productIds !== null && !is_array($productIds)) {
                $this->sendErrorResponse(400, 'INVALID_PRODUCT_IDS', 
                    'product_ids must be an array or null');
            }
            
            // Validate product IDs if provided
            if ($productIds !== null) {
                foreach ($productIds as $productId) {
                    if (!is_numeric($productId) || $productId <= 0) {
                        $this->sendErrorResponse(400, 'INVALID_PRODUCT_ID', 
                            'All product IDs must be positive integers');
                    }
                }
            }
            
            // Start calculation (this might take a while)
            $startTime = microtime(true);
            
            $recommendations = $this->recommender->generateRecommendations($productIds);
            
            $executionTime = round(microtime(true) - $startTime, 2);
            
            $response = [
                'success' => true,
                'data' => [
                    'calculation_completed' => true,
                    'recommendations_generated' => count($recommendations),
                    'execution_time_seconds' => $executionTime,
                    'product_ids_processed' => $productIds ? count($productIds) : 'all_active',
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            
            $this->sendSuccessResponse($response);
            
        } catch (Exception $e) {
            $this->sendErrorResponse(400, 'CALCULATION_FAILED', 
                'Failed to calculate recommendations: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle GET /api/replenishment.php?action=config
     */
    private function handleGetConfig(): void
    {
        try {
            $config = $this->getReplenishmentConfig();
            
            $response = [
                'success' => true,
                'data' => [
                    'config' => $config,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            
            $this->sendSuccessResponse($response);
            
        } catch (Exception $e) {
            $this->sendErrorResponse(400, 'CONFIG_FETCH_FAILED', 
                'Failed to fetch configuration: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle POST /api/replenishment.php?action=config
     */
    private function handlePostConfig(): void
    {
        try {
            // Parse request body
            $input = $this->getJsonInput();
            
            if (empty($input)) {
                $this->sendErrorResponse(400, 'EMPTY_REQUEST_BODY', 
                    'Request body cannot be empty for config update');
            }
            
            // Validate and sanitize configuration parameters
            $validatedConfig = $this->validateConfigParameters($input);
            
            // Update configuration
            $updatedParams = $this->updateReplenishmentConfig($validatedConfig);
            
            // Log configuration changes
            $this->logConfigurationChange($updatedParams);
            
            $response = [
                'success' => true,
                'data' => [
                    'updated_parameters' => $updatedParams,
                    'message' => 'Configuration updated successfully',
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            
            $this->sendSuccessResponse($response);
            
        } catch (Exception $e) {
            $this->sendErrorResponse(400, 'CONFIG_UPDATE_FAILED', 
                'Failed to update configuration: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle GET /api/replenishment.php?action=status
     */
    private function handleGetStatus(): void
    {
        try {
            // Check database connectivity
            $dbStatus = $this->checkDatabaseStatus();
            
            // Check table existence
            $tablesStatus = $this->checkTablesStatus();
            
            // Get latest calculation info
            $latestCalculation = $this->getLatestCalculationInfo();
            
            // Get system metrics
            $systemMetrics = $this->getSystemMetrics();
            
            $response = [
                'success' => true,
                'data' => [
                    'api_status' => 'operational',
                    'database' => $dbStatus,
                    'tables' => $tablesStatus,
                    'latest_calculation' => $latestCalculation,
                    'system_metrics' => $systemMetrics,
                    'timestamp' => date('Y-m-d H:i:s')
                ]
            ];
            
            $this->sendSuccessResponse($response);
            
        } catch (Exception $e) {
            $this->sendErrorResponse(500, 'STATUS_CHECK_FAILED', 
                'Failed to check system status: ' . $e->getMessage());
        }
    }
    
    /**
     * Get replenishment configuration from database
     */
    private function getReplenishmentConfig(): array
    {
        try {
            $sql = "SELECT parameter_name, parameter_value, description FROM replenishment_config ORDER BY parameter_name";
            $stmt = $this->pdo->query($sql);
            $configRows = $stmt->fetchAll();
            
            $config = [];
            foreach ($configRows as $row) {
                $config[$row['parameter_name']] = [
                    'value' => $this->castConfigValue($row['parameter_value']),
                    'description' => $row['description']
                ];
            }
            
            return $config;
            
        } catch (Exception $e) {
            throw new Exception("Failed to fetch configuration: " . $e->getMessage());
        }
    }
    
    /**
     * Validate configuration parameters
     */
    private function validateConfigParameters(array $input): array
    {
        $validatedConfig = [];
        
        $validParameters = [
            'replenishment_days' => ['type' => 'int', 'min' => 1, 'max' => 365],
            'safety_days' => ['type' => 'int', 'min' => 0, 'max' => 90],
            'analysis_days' => ['type' => 'int', 'min' => 7, 'max' => 365],
            'min_ads_threshold' => ['type' => 'float', 'min' => 0, 'max' => 1000]
        ];
        
        foreach ($input as $paramName => $paramValue) {
            if (!isset($validParameters[$paramName])) {
                throw new Exception("Invalid parameter: $paramName");
            }
            
            $validation = $validParameters[$paramName];
            
            // Type validation and casting
            if ($validation['type'] === 'int') {
                $value = filter_var($paramValue, FILTER_VALIDATE_INT);
                if ($value === false) {
                    throw new Exception("Parameter $paramName must be an integer");
                }
            } elseif ($validation['type'] === 'float') {
                $value = filter_var($paramValue, FILTER_VALIDATE_FLOAT);
                if ($value === false) {
                    throw new Exception("Parameter $paramName must be a number");
                }
            } else {
                $value = $paramValue;
            }
            
            // Range validation
            if (isset($validation['min']) && $value < $validation['min']) {
                throw new Exception("Parameter $paramName must be at least {$validation['min']}");
            }
            
            if (isset($validation['max']) && $value > $validation['max']) {
                throw new Exception("Parameter $paramName must be at most {$validation['max']}");
            }
            
            $validatedConfig[$paramName] = $value;
        }
        
        return $validatedConfig;
    }
    
    /**
     * Update replenishment configuration in database
     */
    private function updateReplenishmentConfig(array $config): array
    {
        try {
            $this->pdo->beginTransaction();
            
            $updatedParams = [];
            
            $sql = "UPDATE replenishment_config SET parameter_value = ?, updated_at = NOW() WHERE parameter_name = ?";
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($config as $paramName => $paramValue) {
                $stmt->execute([$paramValue, $paramName]);
                
                if ($stmt->rowCount() > 0) {
                    $updatedParams[$paramName] = $paramValue;
                }
            }
            
            $this->pdo->commit();
            
            return $updatedParams;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw new Exception("Failed to update configuration: " . $e->getMessage());
        }
    }
    
    /**
     * Log configuration changes
     */
    private function logConfigurationChange(array $updatedParams): void
    {
        try {
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'ip' => $this->requestLog['ip'],
                'user_agent' => $this->requestLog['user_agent'],
                'updated_parameters' => $updatedParams
            ];
            
            $logMessage = "[ReplenishmentAPI Config Change] " . json_encode($logEntry);
            error_log($logMessage);
            
            // Also log to database if config_changes table exists
            $sql = "INSERT INTO replenishment_config_changes (parameters_changed, ip_address, user_agent, changed_at) 
                    VALUES (?, ?, ?, NOW())";
            
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    json_encode($updatedParams),
                    $this->requestLog['ip'],
                    $this->requestLog['user_agent']
                ]);
            } catch (Exception $e) {
                // Ignore if config_changes table doesn't exist
            }
            
        } catch (Exception $e) {
            // Don't fail the request if logging fails
            error_log("Failed to log configuration change: " . $e->getMessage());
        }
    }
    
    /**
     * Cast configuration value to appropriate type
     */
    private function castConfigValue(string $value)
    {
        // Try to cast to appropriate type
        if (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                return (float)$value;
            } else {
                return (int)$value;
            }
        }
        
        // Boolean values
        if (in_array(strtolower($value), ['true', 'false'])) {
            return strtolower($value) === 'true';
        }
        
        return $value;
    }
    
    /**
     * Check database status
     */
    private function checkDatabaseStatus(): array
    {
        try {
            $stmt = $this->pdo->query("SELECT 1");
            $stmt->fetch();
            
            return [
                'status' => 'connected',
                'connection_time' => date('Y-m-d H:i:s')
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Check required tables status
     */
    private function checkTablesStatus(): array
    {
        $requiredTables = [
            'replenishment_recommendations',
            'replenishment_config',
            'replenishment_calculations'
        ];
        
        $tablesStatus = [];
        
        foreach ($requiredTables as $table) {
            try {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM $table LIMIT 1");
                $count = $stmt->fetchColumn();
                
                $tablesStatus[$table] = [
                    'exists' => true,
                    'record_count' => (int)$count
                ];
            } catch (Exception $e) {
                $tablesStatus[$table] = [
                    'exists' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $tablesStatus;
    }
    
    /**
     * Get latest calculation information
     */
    private function getLatestCalculationInfo(): array
    {
        try {
            $sql = "SELECT * FROM replenishment_calculations ORDER BY calculation_date DESC, id DESC LIMIT 1";
            $stmt = $this->pdo->query($sql);
            $calculation = $stmt->fetch();
            
            if (!$calculation) {
                return ['status' => 'no_calculations_found'];
            }
            
            return [
                'calculation_date' => $calculation['calculation_date'],
                'status' => $calculation['status'],
                'products_processed' => (int)$calculation['products_processed'],
                'recommendations_generated' => (int)$calculation['recommendations_generated'],
                'execution_time_seconds' => (float)$calculation['execution_time_seconds'],
                'created_at' => $calculation['created_at'] ?? null
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get system metrics
     */
    private function getSystemMetrics(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
            'peak_memory' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB',
            'server_time' => date('Y-m-d H:i:s'),
            'timezone' => date_default_timezone_get()
        ];
    }
    
    /**
     * Parse recommendation filters from request parameters
     */
    private function parseRecommendationFilters(): array
    {
        $filters = [];
        
        // Calculation date filter
        if (isset($_GET['date'])) {
            $date = $_GET['date'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $filters['calculation_date'] = $date;
            }
        }
        
        // Minimum ADS filter
        if (isset($_GET['min_ads'])) {
            $minAds = filter_var($_GET['min_ads'], FILTER_VALIDATE_FLOAT);
            if ($minAds !== false && $minAds >= 0) {
                $filters['min_ads'] = $minAds;
            }
        }
        
        // Minimum recommended quantity filter
        if (isset($_GET['min_quantity'])) {
            $minQuantity = filter_var($_GET['min_quantity'], FILTER_VALIDATE_INT);
            if ($minQuantity !== false && $minQuantity >= 0) {
                $filters['min_recommended_quantity'] = $minQuantity;
            }
        }
        
        // Actionable only filter
        if (isset($_GET['actionable_only'])) {
            $filters['actionable_only'] = filter_var($_GET['actionable_only'], FILTER_VALIDATE_BOOLEAN);
        }
        
        // Product IDs filter
        if (isset($_GET['product_ids'])) {
            $productIds = explode(',', $_GET['product_ids']);
            $productIds = array_map('intval', array_filter($productIds, 'is_numeric'));
            if (!empty($productIds)) {
                $filters['product_ids'] = $productIds;
            }
        }
        
        // Sort parameters
        $validSortFields = [
            'recommended_quantity', 'ads', 'current_stock', 'target_stock', 
            'product_name', 'calculation_date', 'created_at'
        ];
        
        if (isset($_GET['sort_by']) && in_array($_GET['sort_by'], $validSortFields)) {
            $filters['sort_by'] = $_GET['sort_by'];
        }
        
        if (isset($_GET['sort_order']) && in_array(strtoupper($_GET['sort_order']), ['ASC', 'DESC'])) {
            $filters['sort_order'] = strtoupper($_GET['sort_order']);
        }
        
        return $filters;
    }
    
    /**
     * Parse pagination parameters
     */
    private function parsePaginationParams(): array
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(
            $this->config['max_page_size'], 
            max(1, (int)($_GET['per_page'] ?? $this->config['default_page_size']))
        );
        
        return [
            'page' => $page,
            'per_page' => $perPage
        ];
    }
    
    /**
     * Generate report summary from recommendations
     */
    private function generateReportSummary(array $recommendations): array
    {
        $totalProducts = count($recommendations);
        $actionableCount = 0;
        $totalRecommendedQuantity = 0;
        $averageADS = 0;
        $stockSufficientCount = 0;
        
        foreach ($recommendations as $rec) {
            if ($rec['recommended_quantity'] > 0) {
                $actionableCount++;
                $totalRecommendedQuantity += $rec['recommended_quantity'];
            } else {
                $stockSufficientCount++;
            }
            
            $averageADS += $rec['ads'];
        }
        
        $averageADS = $totalProducts > 0 ? round($averageADS / $totalProducts, 2) : 0;
        
        return [
            'total_products_analyzed' => $totalProducts,
            'actionable_recommendations' => $actionableCount,
            'stock_sufficient_products' => $stockSufficientCount,
            'total_recommended_quantity' => $totalRecommendedQuantity,
            'average_ads' => $averageADS,
            'actionable_percentage' => $totalProducts > 0 ? round(($actionableCount / $totalProducts) * 100, 1) : 0
        ];
    }
    
    /**
     * Get JSON input from request body with validation and sanitization
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return [];
        }
        
        // Check input size limit (prevent DoS attacks)
        $maxInputSize = 1024 * 1024; // 1MB
        if (strlen($input) > $maxInputSize) {
            $this->sendErrorResponse(413, 'REQUEST_TOO_LARGE', 
                'Request body too large. Maximum size is 1MB.');
        }
        
        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendErrorResponse(400, 'INVALID_JSON', 
                'Invalid JSON in request body: ' . json_last_error_msg());
        }
        
        // Sanitize input recursively
        return $this->sanitizeInput($decoded ?? []);
    }
    
    /**
     * Sanitize input data recursively
     */
    private function sanitizeInput($data)
    {
        if (is_array($data)) {
            $sanitized = [];
            foreach ($data as $key => $value) {
                $sanitizedKey = $this->sanitizeString($key);
                $sanitized[$sanitizedKey] = $this->sanitizeInput($value);
            }
            return $sanitized;
        } elseif (is_string($data)) {
            return $this->sanitizeString($data);
        } elseif (is_numeric($data)) {
            return $data;
        } elseif (is_bool($data)) {
            return $data;
        } elseif (is_null($data)) {
            return $data;
        } else {
            // Convert other types to string and sanitize
            return $this->sanitizeString((string)$data);
        }
    }
    
    /**
     * Sanitize string input
     */
    private function sanitizeString(string $input): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        // Remove control characters except newlines and tabs
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        // Limit string length
        $maxLength = 1000;
        if (strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }
        
        return $input;
    }
    
    /**
     * Validate and sanitize GET parameters
     */
    private function validateGetParameters(): void
    {
        $allowedParams = [
            'action', 'page', 'per_page', 'date', 'min_ads', 'min_quantity',
            'actionable_only', 'product_ids', 'sort_by', 'sort_order', 'force',
            'search', 'limit', 'offset'
        ];
        
        foreach ($_GET as $key => $value) {
            if (!in_array($key, $allowedParams)) {
                $this->sendErrorResponse(400, 'INVALID_PARAMETER', 
                    "Invalid parameter: $key");
            }
            
            // Sanitize parameter values
            $_GET[$key] = $this->sanitizeString($value);
        }
    }
    
    /**
     * Enhanced request logging with more details
     */
    private function logRequest(string $action, string $method): void
    {
        $logData = [
            'timestamp' => $this->requestLog['timestamp'],
            'method' => $method,
            'action' => $action,
            'ip' => $this->requestLog['ip'],
            'user_agent' => substr($this->requestLog['user_agent'], 0, 200), // Limit length
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'query_params' => array_keys($_GET),
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 0
        ];
        
        if ($this->config['debug']) {
            error_log("[ReplenishmentAPI Request] " . json_encode($logData));
        }
        
        // Log to database if request_log table exists
        try {
            $sql = "INSERT INTO replenishment_request_log 
                    (method, action, ip_address, user_agent, request_uri, logged_at) 
                    VALUES (?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $method,
                $action,
                $logData['ip'],
                $logData['user_agent'],
                $logData['request_uri']
            ]);
        } catch (Exception $e) {
            // Ignore if request_log table doesn't exist
        }
    }
    
    /**
     * Enhanced error logging with stack trace
     */
    private function logError(Exception $e): void
    {
        $errorData = [
            'timestamp' => $this->requestLog['timestamp'],
            'error_message' => $e->getMessage(),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'stack_trace' => $e->getTraceAsString(),
            'request_data' => [
                'method' => $this->requestLog['method'],
                'action' => $this->requestLog['action'],
                'ip' => $this->requestLog['ip'],
                'user_agent' => substr($this->requestLog['user_agent'], 0, 200)
            ]
        ];
        
        $logMessage = "[ReplenishmentAPI Error] " . json_encode($errorData);
        error_log($logMessage);
        
        // Log to database if error_log table exists
        try {
            $sql = "INSERT INTO replenishment_error_log 
                    (error_message, error_file, error_line, stack_trace, 
                     request_method, request_action, ip_address, logged_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString(),
                $this->requestLog['method'],
                $this->requestLog['action'],
                $this->requestLog['ip']
            ]);
        } catch (Exception $logException) {
            // Ignore if error_log table doesn't exist
        }
    }
    
    /**
     * Enhanced rate limiting with different limits per endpoint
     */
    private function checkRateLimit(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $action = $_GET['action'] ?? 'unknown';
        
        // Different rate limits for different actions
        $rateLimits = [
            'recommendations' => ['requests' => 60, 'window' => 3600], // 60 per hour
            'report' => ['requests' => 10, 'window' => 3600],          // 10 per hour
            'calculate' => ['requests' => 5, 'window' => 3600],        // 5 per hour
            'config' => ['requests' => 20, 'window' => 3600],          // 20 per hour
            'status' => ['requests' => 100, 'window' => 3600],         // 100 per hour
            'default' => ['requests' => 50, 'window' => 3600]          // 50 per hour
        ];
        
        $limit = $rateLimits[$action] ?? $rateLimits['default'];
        
        $cacheKey = "rate_limit_{$ip}_{$action}";
        $cacheFile = sys_get_temp_dir() . "/replenishment_api_{$ip}_{$action}.cache";
        
        $now = time();
        $requests = [];
        
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data && isset($data['requests'])) {
                $requests = array_filter($data['requests'], function($timestamp) use ($now, $limit) {
                    return ($now - $timestamp) < $limit['window'];
                });
            }
        }
        
        if (count($requests) >= $limit['requests']) {
            $resetTime = min($requests) + $limit['window'];
            $waitTime = $resetTime - $now;
            
            http_response_code(429);
            header("Retry-After: $waitTime");
            header("X-RateLimit-Limit: {$limit['requests']}");
            header("X-RateLimit-Remaining: 0");
            header("X-RateLimit-Reset: $resetTime");
            
            $this->sendErrorResponse(429, 'RATE_LIMIT_EXCEEDED', 
                "Rate limit exceeded for action '$action'. Try again in $waitTime seconds.");
        }
        
        $requests[] = $now;
        file_put_contents($cacheFile, json_encode(['requests' => $requests]));
        
        // Add rate limit headers to response
        $remaining = max(0, $limit['requests'] - count($requests));
        $resetTime = min($requests) + $limit['window'];
        
        header("X-RateLimit-Limit: {$limit['requests']}");
        header("X-RateLimit-Remaining: $remaining");
        header("X-RateLimit-Reset: $resetTime");
    }
    

    
    /**
     * Send success response with security headers
     */
    private function sendSuccessResponse(array $data): void
    {
        $this->addSecurityHeaders();
        http_response_code(200);
        
        // Add response metadata
        $data['meta'] = [
            'api_version' => '1.0',
            'response_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    /**
     * Send error response with security headers
     */
    private function sendErrorResponse(int $httpCode, string $errorCode, string $message): void
    {
        $this->addSecurityHeaders();
        http_response_code($httpCode);
        
        $errorResponse = [
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $message,
                'timestamp' => date('Y-m-d H:i:s')
            ],
            'meta' => [
                'api_version' => '1.0',
                'response_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
            ]
        ];
        
        // Log error response
        $this->logErrorResponse($httpCode, $errorCode, $message);
        
        echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    /**
     * Add security headers to response
     */
    private function addSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    
    /**
     * Log error responses for monitoring
     */
    private function logErrorResponse(int $httpCode, string $errorCode, string $message): void
    {
        $errorLogData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'http_code' => $httpCode,
            'error_code' => $errorCode,
            'message' => $message,
            'ip' => $this->requestLog['ip'],
            'action' => $this->requestLog['action'],
            'method' => $this->requestLog['method']
        ];
        
        error_log("[ReplenishmentAPI Error Response] " . json_encode($errorLogData));
    }
}

// Handle the request
try {
    $api = new ReplenishmentAPI();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'FATAL_ERROR',
            'message' => 'Fatal error: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>