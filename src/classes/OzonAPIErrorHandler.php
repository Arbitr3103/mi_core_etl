<?php
/**
 * OzonAPIErrorHandler Class - Robust API error handling with retry logic
 * 
 * Implements comprehensive error handling for Ozon API calls with:
 * - Exponential backoff retry logic (30s, 60s, 120s intervals)
 * - Specific handling for rate limits and authentication errors
 * - Fallback to cached data when API is unavailable
 * - Integration with existing OzonETLRetryManager
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonAPIErrorHandler {
    
    private $pdo;
    private $retryManager;
    private $logger;
    private $config;
    
    // Retry intervals in seconds (30s, 60s, 120s as specified)
    const RETRY_INTERVALS = [30, 60, 120];
    const MAX_RETRIES = 3;
    
    // Error types that require specific handling
    const RATE_LIMIT_ERROR = 'rate_limit_exceeded';
    const AUTH_ERROR = 'authentication_failed';
    const API_UNAVAILABLE = 'api_unavailable';
    const NETWORK_TIMEOUT = 'network_timeout';
    const SERVER_ERROR = 'server_error';
    
    /**
     * Constructor
     * 
     * @param PDO $pdo Database connection
     * @param OzonETLRetryManager $retryManager Existing retry manager
     * @param array $config Configuration options
     */
    public function __construct(PDO $pdo, OzonETLRetryManager $retryManager, array $config = []) {
        $this->pdo = $pdo;
        $this->retryManager = $retryManager;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeLogger();
        $this->initializeErrorTables();
    }
    
    /**
     * Get default configuration
     */
    private function getDefaultConfig(): array {
        return [
            'enable_fallback' => true,
            'fallback_cache_hours' => 24,
            'rate_limit_backoff_multiplier' => 2,
            'auth_error_max_retries' => 1,
            'network_timeout_seconds' => 30,
            'enable_circuit_breaker' => true,
            'circuit_breaker_threshold' => 5,
            'circuit_breaker_timeout_minutes' => 15
        ];
    }
    
    /**
     * Initialize logger
     */
    private function initializeLogger(): void {
        $this->logger = [
            'info' => function($message, $context = []) {
                error_log("[INFO] OzonAPIErrorHandler: $message " . json_encode($context));
            },
            'warning' => function($message, $context = []) {
                error_log("[WARNING] OzonAPIErrorHandler: $message " . json_encode($context));
            },
            'error' => function($message, $context = []) {
                error_log("[ERROR] OzonAPIErrorHandler: $message " . json_encode($context));
            }
        ];
    }
    
    /**
     * Initialize error tracking tables
     */
    private function initializeErrorTables(): void {
        try {
            // Table for API error tracking
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_api_error_log (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    api_endpoint VARCHAR(255) NOT NULL,
                    error_type VARCHAR(100) NOT NULL,
                    error_code INT NULL,
                    error_message TEXT NOT NULL,
                    request_params JSON NULL,
                    response_data JSON NULL,
                    retry_attempt INT DEFAULT 0,
                    resolved_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_api_endpoint (api_endpoint),
                    INDEX idx_error_type (error_type),
                    INDEX idx_created_at (created_at),
                    INDEX idx_resolved_at (resolved_at)
                ) ENGINE=InnoDB
            ");
            
            // Table for circuit breaker state
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS ozon_api_circuit_breaker (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    api_endpoint VARCHAR(255) NOT NULL UNIQUE,
                    state ENUM('CLOSED', 'OPEN', 'HALF_OPEN') DEFAULT 'CLOSED',
                    failure_count INT DEFAULT 0,
                    last_failure_at TIMESTAMP NULL,
                    next_attempt_at TIMESTAMP NULL,
                    success_count INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    
                    INDEX idx_state (state),
                    INDEX idx_next_attempt_at (next_attempt_at)
                ) ENGINE=InnoDB
            ");
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to initialize error tables", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Execute API call with comprehensive error handling
     * 
     * @param callable $apiCall The API call to execute
     * @param string $endpoint API endpoint identifier
     * @param array $params Request parameters
     * @param string $etlId ETL process identifier
     * @return mixed API response or fallback data
     * @throws Exception if all retry attempts fail and no fallback available
     */
    public function executeWithErrorHandling(callable $apiCall, string $endpoint, array $params = [], string $etlId = null): mixed {
        ($this->logger['info'])("Starting API call with error handling", [
            'endpoint' => $endpoint,
            'etl_id' => $etlId
        ]);
        
        // Check circuit breaker state
        if (!$this->isCircuitBreakerClosed($endpoint)) {
            ($this->logger['warning'])("Circuit breaker is open for endpoint", [
                'endpoint' => $endpoint
            ]);
            
            return $this->handleFallback($endpoint, $params, $etlId, 'circuit_breaker_open');
        }
        
        $lastException = null;
        
        // Execute with retry logic using specified intervals
        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                ($this->logger['info'])("Executing API call attempt", [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_RETRIES
                ]);
                
                $result = $apiCall();
                
                // Success - reset circuit breaker and return result
                $this->recordSuccessfulCall($endpoint);
                
                ($this->logger['info'])("API call successful", [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt
                ]);
                
                return $result;
                
            } catch (Exception $e) {
                $lastException = $e;
                $errorType = $this->classifyAPIError($e);
                
                ($this->logger['warning'])("API call failed", [
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'error_type' => $errorType,
                    'error_message' => $e->getMessage(),
                    'error_code' => $e->getCode()
                ]);
                
                // Log the error
                $this->logAPIError($endpoint, $errorType, $e, $params, $attempt);
                
                // Handle specific error types
                $shouldRetry = $this->shouldRetryError($errorType, $attempt, $e);
                
                if (!$shouldRetry || $attempt >= self::MAX_RETRIES) {
                    // Record failure for circuit breaker
                    $this->recordFailedCall($endpoint, $errorType);
                    break;
                }
                
                // Calculate delay for next attempt
                $delay = $this->calculateRetryDelay($attempt, $errorType);
                
                ($this->logger['info'])("Retrying API call after delay", [
                    'endpoint' => $endpoint,
                    'delay_seconds' => $delay,
                    'next_attempt' => $attempt + 1
                ]);
                
                sleep($delay);
            }
        }
        
        // All retries failed - try fallback
        ($this->logger['error'])("All API retry attempts failed", [
            'endpoint' => $endpoint,
            'total_attempts' => self::MAX_RETRIES,
            'final_error' => $lastException->getMessage()
        ]);
        
        if ($this->config['enable_fallback']) {
            $fallbackResult = $this->handleFallback($endpoint, $params, $etlId, 'api_failure');
            if ($fallbackResult !== null) {
                return $fallbackResult;
            }
        }
        
        throw $lastException;
    }
    
    /**
     * Classify API error type
     */
    private function classifyAPIError(Exception $e): string {
        $message = strtolower($e->getMessage());
        $code = $e->getCode();
        
        // Rate limiting
        if ($code === 429 || strpos($message, 'rate limit') !== false || strpos($message, 'too many requests') !== false) {
            return self::RATE_LIMIT_ERROR;
        }
        
        // Authentication errors
        if ($code === 401 || $code === 403 || strpos($message, 'unauthorized') !== false || strpos($message, 'authentication') !== false) {
            return self::AUTH_ERROR;
        }
        
        // Network timeouts
        if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) {
            return self::NETWORK_TIMEOUT;
        }
        
        // Server errors (5xx)
        if ($code >= 500 && $code < 600) {
            return self::SERVER_ERROR;
        }
        
        // API unavailable
        if ($code === 503 || strpos($message, 'service unavailable') !== false || strpos($message, 'connection refused') !== false) {
            return self::API_UNAVAILABLE;
        }
        
        return 'unknown_error';
    }
    
    /**
     * Determine if error should be retried
     */
    private function shouldRetryError(string $errorType, int $attempt, Exception $e): bool {
        switch ($errorType) {
            case self::RATE_LIMIT_ERROR:
                return true; // Always retry rate limits with backoff
                
            case self::AUTH_ERROR:
                return $attempt <= $this->config['auth_error_max_retries']; // Limited retries for auth
                
            case self::NETWORK_TIMEOUT:
            case self::SERVER_ERROR:
            case self::API_UNAVAILABLE:
                return true; // Retry transient errors
                
            default:
                return false; // Don't retry unknown errors
        }
    }
    
    /**
     * Calculate retry delay with exponential backoff
     */
    private function calculateRetryDelay(int $attempt, string $errorType): int {
        // Use specified intervals: 30s, 60s, 120s
        $baseDelay = self::RETRY_INTERVALS[$attempt - 1] ?? self::RETRY_INTERVALS[2];
        
        // Special handling for rate limits
        if ($errorType === self::RATE_LIMIT_ERROR) {
            $baseDelay *= $this->config['rate_limit_backoff_multiplier'];
        }
        
        // Add small jitter to avoid thundering herd
        $jitter = rand(0, 10);
        
        return $baseDelay + $jitter;
    }
    
    /**
     * Handle fallback to cached data
     */
    private function handleFallback(string $endpoint, array $params, ?string $etlId, string $reason): mixed {
        ($this->logger['info'])("Attempting fallback to cached data", [
            'endpoint' => $endpoint,
            'reason' => $reason,
            'etl_id' => $etlId
        ]);
        
        try {
            // Generate cache key based on endpoint and params
            $cacheKey = $this->generateCacheKey($endpoint, $params);
            
            // Try to get cached data from retry manager's fallback system
            $fallbackData = $this->getCachedAPIResponse($endpoint, $cacheKey);
            
            if ($fallbackData !== null) {
                ($this->logger['info'])("Using cached data for fallback", [
                    'endpoint' => $endpoint,
                    'cache_age_hours' => $fallbackData['age_hours'],
                    'reason' => $reason
                ]);
                
                return $fallbackData['data'];
            }
            
            ($this->logger['warning'])("No cached data available for fallback", [
                'endpoint' => $endpoint,
                'cache_key' => $cacheKey
            ]);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Fallback mechanism failed", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Generate cache key for API response
     */
    private function generateCacheKey(string $endpoint, array $params): string {
        // Create deterministic key from endpoint and sorted params
        ksort($params);
        $paramString = http_build_query($params);
        return md5($endpoint . '_' . $paramString);
    }
    
    /**
     * Get cached API response
     */
    private function getCachedAPIResponse(string $endpoint, string $cacheKey): ?array {
        try {
            $maxAgeHours = $this->config['fallback_cache_hours'];
            
            $sql = "SELECT data_content, 
                           TIMESTAMPDIFF(HOUR, created_at, NOW()) as age_hours
                    FROM ozon_etl_fallback_data 
                    WHERE data_type = 'api_response' 
                    AND data_key = :cache_key
                    AND TIMESTAMPDIFF(HOUR, created_at, NOW()) <= :max_age_hours
                    ORDER BY created_at DESC
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'cache_key' => $cacheKey,
                'max_age_hours' => $maxAgeHours
            ]);
            
            $result = $stmt->fetch();
            
            if ($result) {
                return [
                    'data' => json_decode($result['data_content'], true),
                    'age_hours' => $result['age_hours']
                ];
            }
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to get cached API response", [
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Cache successful API response for fallback
     */
    public function cacheAPIResponse(string $endpoint, array $params, mixed $response, ?string $etlId = null): bool {
        try {
            $cacheKey = $this->generateCacheKey($endpoint, $params);
            
            return $this->retryManager->saveFallbackData(
                'api_response',
                $response,
                $cacheKey,
                $etlId,
                $this->config['fallback_cache_hours']
            );
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to cache API response", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Log API error to database
     */
    private function logAPIError(string $endpoint, string $errorType, Exception $e, array $params, int $attempt): void {
        try {
            $sql = "INSERT INTO ozon_api_error_log 
                    (api_endpoint, error_type, error_code, error_message, request_params, retry_attempt)
                    VALUES (:endpoint, :error_type, :error_code, :error_message, :request_params, :retry_attempt)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'endpoint' => $endpoint,
                'error_type' => $errorType,
                'error_code' => $e->getCode(),
                'error_message' => $e->getMessage(),
                'request_params' => json_encode($params),
                'retry_attempt' => $attempt
            ]);
            
        } catch (Exception $ex) {
            ($this->logger['warning'])("Failed to log API error", [
                'error' => $ex->getMessage()
            ]);
        }
    }
    
    /**
     * Check if circuit breaker is closed (allowing requests)
     */
    private function isCircuitBreakerClosed(string $endpoint): bool {
        if (!$this->config['enable_circuit_breaker']) {
            return true;
        }
        
        try {
            $sql = "SELECT state, failure_count, next_attempt_at 
                    FROM ozon_api_circuit_breaker 
                    WHERE api_endpoint = :endpoint";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['endpoint' => $endpoint]);
            $breaker = $stmt->fetch();
            
            if (!$breaker) {
                // No record exists - circuit is closed
                return true;
            }
            
            switch ($breaker['state']) {
                case 'CLOSED':
                    return true;
                    
                case 'OPEN':
                    // Check if timeout period has passed
                    if ($breaker['next_attempt_at'] && strtotime($breaker['next_attempt_at']) <= time()) {
                        $this->setCircuitBreakerState($endpoint, 'HALF_OPEN');
                        return true;
                    }
                    return false;
                    
                case 'HALF_OPEN':
                    return true;
                    
                default:
                    return true;
            }
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to check circuit breaker state", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            return true; // Fail open
        }
    }
    
    /**
     * Record successful API call
     */
    private function recordSuccessfulCall(string $endpoint): void {
        if (!$this->config['enable_circuit_breaker']) {
            return;
        }
        
        try {
            $sql = "INSERT INTO ozon_api_circuit_breaker 
                    (api_endpoint, state, failure_count, success_count)
                    VALUES (:endpoint, 'CLOSED', 0, 1)
                    ON DUPLICATE KEY UPDATE
                    state = 'CLOSED',
                    failure_count = 0,
                    success_count = success_count + 1,
                    next_attempt_at = NULL";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['endpoint' => $endpoint]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to record successful call", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Record failed API call
     */
    private function recordFailedCall(string $endpoint, string $errorType): void {
        if (!$this->config['enable_circuit_breaker']) {
            return;
        }
        
        try {
            $threshold = $this->config['circuit_breaker_threshold'];
            $timeoutMinutes = $this->config['circuit_breaker_timeout_minutes'];
            
            $sql = "INSERT INTO ozon_api_circuit_breaker 
                    (api_endpoint, state, failure_count, last_failure_at)
                    VALUES (:endpoint, 'CLOSED', 1, NOW())
                    ON DUPLICATE KEY UPDATE
                    failure_count = failure_count + 1,
                    last_failure_at = NOW(),
                    state = CASE 
                        WHEN failure_count + 1 >= :threshold THEN 'OPEN'
                        ELSE state 
                    END,
                    next_attempt_at = CASE 
                        WHEN failure_count + 1 >= :threshold THEN DATE_ADD(NOW(), INTERVAL :timeout_minutes MINUTE)
                        ELSE next_attempt_at 
                    END";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'endpoint' => $endpoint,
                'threshold' => $threshold,
                'timeout_minutes' => $timeoutMinutes
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to record failed call", [
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Set circuit breaker state
     */
    private function setCircuitBreakerState(string $endpoint, string $state): void {
        try {
            $sql = "UPDATE ozon_api_circuit_breaker 
                    SET state = :state 
                    WHERE api_endpoint = :endpoint";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'endpoint' => $endpoint,
                'state' => $state
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to set circuit breaker state", [
                'endpoint' => $endpoint,
                'state' => $state,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get API error statistics
     */
    public function getErrorStatistics(int $hours = 24): array {
        try {
            $sql = "SELECT 
                        api_endpoint,
                        error_type,
                        COUNT(*) as error_count,
                        MAX(retry_attempt) as max_retries,
                        AVG(retry_attempt) as avg_retries,
                        COUNT(DISTINCT DATE_FORMAT(created_at, '%Y-%m-%d %H')) as error_hours
                    FROM ozon_api_error_log 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL :hours HOUR)
                    GROUP BY api_endpoint, error_type
                    ORDER BY error_count DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['hours' => $hours]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get error statistics", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Clean up old error logs
     */
    public function cleanupOldErrors(int $daysToKeep = 30): int {
        try {
            $sql = "DELETE FROM ozon_api_error_log 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['days' => $daysToKeep]);
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to cleanup old errors", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}