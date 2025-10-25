<?php
/**
 * AnalyticsApiClient - Сервис для работы с Ozon Analytics API
 * 
 * Специализированный клиент для работы с эндпоинтом /v2/analytics/stock_on_warehouses
 * Поддерживает пагинацию, retry logic с exponential backoff, rate limiting и кэширование.
 * 
 * Requirements: 1.1, 15.1, 16.5
 * Task: 4.1 Создать AnalyticsApiClient сервис
 * 
 * @version 1.0
 * @author Warehouse Multi-Source Integration System
 */

require_once __DIR__ . '/../classes/OzonAnalyticsAPI.php';

class AnalyticsApiClient {
    // API Configuration
    const API_BASE_URL = 'https://api-seller.ozon.ru';
    const ENDPOINT_STOCK_ON_WAREHOUSES = '/v2/analytics/stock_on_warehouses';
    
    // Pagination settings (as required by task)
    const DEFAULT_LIMIT = 1000; // 1000 records per request
    const MAX_LIMIT = 1000;
    
    // Retry logic settings (as required by task)
    const MAX_RETRIES = 3;
    const INITIAL_RETRY_DELAY = 1; // seconds
    const BACKOFF_MULTIPLIER = 2; // exponential backoff
    const MAX_RETRY_DELAY = 30; // seconds
    
    // Rate limiting settings (as required by task)
    const RATE_LIMIT_REQUESTS_PER_MINUTE = 30;
    const RATE_LIMIT_DELAY = 2; // seconds between requests
    
    // Cache settings (as required by task)
    const CACHE_TTL = 7200; // 2 hours
    const CACHE_PREFIX = 'analytics_api_';
    
    private string $clientId;
    private string $apiKey;
    private ?PDO $pdo;
    private array $requestHistory = [];
    private array $cache = [];
    private int $lastRequestTime = 0;
    
    /**
     * Constructor
     * 
     * @param string $clientId Ozon Client ID
     * @param string $apiKey Ozon API Key
     * @param PDO|null $pdo Database connection for caching and logging
     */
    public function __construct(string $clientId, string $apiKey, ?PDO $pdo = null) {
        if (empty($clientId)) {
            throw new InvalidArgumentException('Client ID cannot be empty');
        }
        
        if (empty($apiKey)) {
            throw new InvalidArgumentException('API Key cannot be empty');
        }
        
        $this->clientId = $clientId;
        $this->apiKey = $apiKey;
        $this->pdo = $pdo;
        
        $this->initializeCache();
    }
    
    /**
     * Get stock data on warehouses with pagination support
     * 
     * @param int $offset Starting offset for pagination
     * @param int $limit Number of records to fetch (max 1000)
     * @param array $filters Additional filters for the request
     * @return array API response with stock data
     * @throws AnalyticsApiException
     */
    public function getStockOnWarehouses(int $offset = 0, int $limit = self::DEFAULT_LIMIT, array $filters = []): array {
        // Validate pagination parameters
        $this->validatePaginationParams($offset, $limit);
        
        // Check cache first
        $cacheKey = $this->generateCacheKey('stock_warehouses', $offset, $limit, $filters);
        $cachedData = $this->getCachedData($cacheKey);
        if ($cachedData !== null) {
            return $cachedData;
        }
        
        // Prepare request payload
        $payload = $this->buildStockWarehousesPayload($offset, $limit, $filters);
        
        // Make request with retry logic
        $response = $this->makeRequestWithRetry(self::ENDPOINT_STOCK_ON_WAREHOUSES, $payload);
        
        // Process and validate response
        $processedData = $this->processStockWarehousesResponse($response);
        
        // Cache the response
        $this->setCachedData($cacheKey, $processedData);
        
        return $processedData;
    }
    
    /**
     * Get all stock data using pagination (Generator for memory efficiency)
     * 
     * @param array $filters Additional filters for the request
     * @return Generator Yields batches of stock data
     * @throws AnalyticsApiException
     */
    public function getAllStockData(array $filters = []): Generator {
        $offset = 0;
        $limit = self::DEFAULT_LIMIT;
        $totalProcessed = 0;
        
        do {
            $batch = $this->getStockOnWarehouses($offset, $limit, $filters);
            
            if (empty($batch['data'])) {
                break;
            }
            
            $batchSize = count($batch['data']);
            $totalProcessed += $batchSize;
            
            yield [
                'data' => $batch['data'],
                'offset' => $offset,
                'batch_size' => $batchSize,
                'total_processed' => $totalProcessed,
                'has_more' => $batchSize === $limit
            ];
            
            $offset += $limit;
            
            // Stop if we got less than requested (last page)
            if ($batchSize < $limit) {
                break;
            }
            
        } while (true);
    }
    
    /**
     * Make HTTP request with retry logic and exponential backoff
     * 
     * @param string $endpoint API endpoint
     * @param array $payload Request payload
     * @return array API response
     * @throws AnalyticsApiException
     */
    private function makeRequestWithRetry(string $endpoint, array $payload): array {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < self::MAX_RETRIES) {
            try {
                // Apply rate limiting
                $this->enforceRateLimit();
                
                // Make the actual request
                $response = $this->makeHttpRequest($endpoint, $payload);
                
                // Log successful request
                $this->logRequest($endpoint, $payload, $response, $attempt + 1);
                
                return $response;
                
            } catch (AnalyticsApiException $e) {
                $lastException = $e;
                $attempt++;
                
                // Don't retry on authentication errors
                if ($e->getErrorType() === 'AUTHENTICATION_ERROR') {
                    throw $e;
                }
                
                // Don't retry on client errors (4xx)
                if ($e->getCode() >= 400 && $e->getCode() < 500) {
                    throw $e;
                }
                
                // Calculate delay for exponential backoff
                if ($attempt < self::MAX_RETRIES) {
                    $delay = min(
                        self::INITIAL_RETRY_DELAY * pow(self::BACKOFF_MULTIPLIER, $attempt - 1),
                        self::MAX_RETRY_DELAY
                    );
                    
                    $this->logRetryAttempt($endpoint, $attempt, $delay, $e->getMessage());
                    sleep($delay);
                }
            }
        }
        
        // All retries failed
        throw new AnalyticsApiException(
            "Request failed after {$attempt} attempts. Last error: " . $lastException->getMessage(),
            $lastException->getCode(),
            'MAX_RETRIES_EXCEEDED'
        );
    }
    
    /**
     * Make actual HTTP request to Ozon API
     * 
     * @param string $endpoint API endpoint
     * @param array $payload Request payload
     * @return array Parsed response
     * @throws AnalyticsApiException
     */
    private function makeHttpRequest(string $endpoint, array $payload): array {
        $url = self::API_BASE_URL . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Client-Id: ' . $this->clientId,
            'Api-Key: ' . $this->apiKey
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'WarehouseIntegration/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            throw new AnalyticsApiException(
                "CURL error: {$error}",
                0,
                'NETWORK_ERROR'
            );
        }
        
        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new AnalyticsApiException(
                "Invalid JSON response: " . json_last_error_msg(),
                $httpCode,
                'INVALID_RESPONSE'
            );
        }
        
        // Handle HTTP errors
        if ($httpCode >= 400) {
            $errorMessage = $decodedResponse['message'] ?? $decodedResponse['error'] ?? 'Unknown API error';
            $errorType = $this->determineErrorType($httpCode, $decodedResponse);
            
            throw new AnalyticsApiException(
                "API error ({$httpCode}): {$errorMessage}",
                $httpCode,
                $errorType
            );
        }
        
        return $decodedResponse;
    }
    
    /**
     * Enforce rate limiting between requests
     */
    private function enforceRateLimit(): void {
        $currentTime = microtime(true);
        $timeSinceLastRequest = $currentTime - $this->lastRequestTime;
        
        if ($timeSinceLastRequest < self::RATE_LIMIT_DELAY) {
            $sleepTime = self::RATE_LIMIT_DELAY - $timeSinceLastRequest;
            usleep($sleepTime * 1000000); // Convert to microseconds
        }
        
        $this->lastRequestTime = microtime(true);
        
        // Clean old request history (keep only last minute)
        $oneMinuteAgo = time() - 60;
        $this->requestHistory = array_filter(
            $this->requestHistory,
            fn($timestamp) => $timestamp > $oneMinuteAgo
        );
        
        // Check if we're exceeding rate limit
        if (count($this->requestHistory) >= self::RATE_LIMIT_REQUESTS_PER_MINUTE) {
            $oldestRequest = min($this->requestHistory);
            $waitTime = 60 - (time() - $oldestRequest);
            
            if ($waitTime > 0) {
                sleep($waitTime);
            }
        }
        
        $this->requestHistory[] = time();
    }
    
    /**
     * Build payload for stock_on_warehouses request
     * 
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @param array $filters Additional filters
     * @return array Request payload
     */
    private function buildStockWarehousesPayload(int $offset, int $limit, array $filters): array {
        $payload = [
            'offset' => $offset,
            'limit' => $limit
        ];
        
        // Add date range if provided
        if (isset($filters['date_from'])) {
            $payload['date_from'] = $filters['date_from'];
        }
        
        if (isset($filters['date_to'])) {
            $payload['date_to'] = $filters['date_to'];
        }
        
        // Add warehouse filters if provided
        if (isset($filters['warehouse_names']) && is_array($filters['warehouse_names'])) {
            $payload['warehouse_names'] = $filters['warehouse_names'];
        }
        
        // Add SKU filters if provided
        if (isset($filters['sku_list']) && is_array($filters['sku_list'])) {
            $payload['sku_list'] = $filters['sku_list'];
        }
        
        return $payload;
    }
    
    /**
     * Process and validate stock warehouses response
     * 
     * @param array $response Raw API response
     * @return array Processed response
     * @throws AnalyticsApiException
     */
    private function processStockWarehousesResponse(array $response): array {
        // Validate response structure
        if (!isset($response['result'])) {
            throw new AnalyticsApiException(
                'Invalid response structure: missing result field',
                0,
                'INVALID_RESPONSE'
            );
        }
        
        $result = $response['result'];
        
        // Extract data array
        $data = $result['data'] ?? [];
        
        // Process each record
        $processedData = [];
        foreach ($data as $record) {
            $processedRecord = $this->processStockRecord($record);
            if ($processedRecord !== null) {
                $processedData[] = $processedRecord;
            }
        }
        
        return [
            'data' => $processedData,
            'total_count' => $result['total_count'] ?? count($processedData),
            'has_more' => count($processedData) === $this->getLastRequestLimit(),
            'processed_at' => date('Y-m-d H:i:s'),
            'source' => 'analytics_api'
        ];
    }
    
    /**
     * Process individual stock record
     * 
     * @param array $record Raw stock record
     * @return array|null Processed record or null if invalid
     */
    private function processStockRecord(array $record): ?array {
        // Validate required fields
        if (empty($record['sku']) || empty($record['warehouse_name'])) {
            return null;
        }
        
        return [
            'sku' => $record['sku'],
            'warehouse_name' => trim($record['warehouse_name']),
            'available_stock' => max(0, (int)($record['available_stock'] ?? 0)),
            'reserved_stock' => max(0, (int)($record['reserved_stock'] ?? 0)),
            'total_stock' => max(0, (int)($record['total_stock'] ?? 0)),
            'product_name' => $record['product_name'] ?? '',
            'category' => $record['category'] ?? '',
            'brand' => $record['brand'] ?? '',
            'price' => max(0, (float)($record['price'] ?? 0)),
            'currency' => $record['currency'] ?? 'RUB',
            'updated_at' => $record['updated_at'] ?? date('Y-m-d H:i:s'),
            'data_source' => 'api',
            'data_quality_score' => 100 // API data has highest quality
        ];
    }
    
    /**
     * Validate pagination parameters
     * 
     * @param int $offset Pagination offset
     * @param int $limit Pagination limit
     * @throws InvalidArgumentException
     */
    private function validatePaginationParams(int $offset, int $limit): void {
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be non-negative');
        }
        
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be positive');
        }
        
        if ($limit > self::MAX_LIMIT) {
            throw new InvalidArgumentException("Limit cannot exceed " . self::MAX_LIMIT);
        }
    }
    
    /**
     * Initialize cache system
     */
    private function initializeCache(): void {
        if ($this->pdo) {
            $this->createCacheTableIfNotExists();
        }
    }
    
    /**
     * Create cache table if it doesn't exist
     */
    private function createCacheTableIfNotExists(): void {
        $sql = "
            CREATE TABLE IF NOT EXISTS analytics_api_cache (
                cache_key VARCHAR(255) PRIMARY KEY,
                data LONGTEXT NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_expires_at (expires_at)
            )
        ";
        
        $this->pdo->exec($sql);
    }
    
    /**
     * Generate cache key
     * 
     * @param string $method API method
     * @param mixed ...$params Parameters
     * @return string Cache key
     */
    private function generateCacheKey(string $method, ...$params): string {
        $key = self::CACHE_PREFIX . $method . '_' . md5(serialize($params));
        return substr($key, 0, 255); // Ensure key length limit
    }
    
    /**
     * Get cached data
     * 
     * @param string $cacheKey Cache key
     * @return array|null Cached data or null if not found/expired
     */
    private function getCachedData(string $cacheKey): ?array {
        // Try memory cache first
        if (isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            if ($cached['expires_at'] > time()) {
                return $cached['data'];
            }
            unset($this->cache[$cacheKey]);
        }
        
        // Try database cache
        if ($this->pdo) {
            $stmt = $this->pdo->prepare(
                "SELECT data FROM analytics_api_cache 
                 WHERE cache_key = ? AND expires_at > NOW()"
            );
            $stmt->execute([$cacheKey]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $data = json_decode($result['data'], true);
                
                // Store in memory cache
                $this->cache[$cacheKey] = [
                    'data' => $data,
                    'expires_at' => time() + self::CACHE_TTL
                ];
                
                return $data;
            }
        }
        
        return null;
    }
    
    /**
     * Set cached data
     * 
     * @param string $cacheKey Cache key
     * @param array $data Data to cache
     */
    private function setCachedData(string $cacheKey, array $data): void {
        $expiresAt = time() + self::CACHE_TTL;
        
        // Store in memory cache
        $this->cache[$cacheKey] = [
            'data' => $data,
            'expires_at' => $expiresAt
        ];
        
        // Store in database cache
        if ($this->pdo) {
            $stmt = $this->pdo->prepare(
                "INSERT INTO analytics_api_cache (cache_key, data, expires_at) 
                 VALUES (?, ?, FROM_UNIXTIME(?))
                 ON DUPLICATE KEY UPDATE 
                 data = VALUES(data), expires_at = VALUES(expires_at)"
            );
            $stmt->execute([$cacheKey, json_encode($data), $expiresAt]);
        }
    }
    
    /**
     * Clear expired cache entries
     * 
     * @return int Number of cleared entries
     */
    public function clearExpiredCache(): int {
        $cleared = 0;
        
        // Clear memory cache
        foreach ($this->cache as $key => $cached) {
            if ($cached['expires_at'] <= time()) {
                unset($this->cache[$key]);
                $cleared++;
            }
        }
        
        // Clear database cache
        if ($this->pdo) {
            $stmt = $this->pdo->prepare("DELETE FROM analytics_api_cache WHERE expires_at <= NOW()");
            $stmt->execute();
            $cleared += $stmt->rowCount();
        }
        
        return $cleared;
    }
    
    /**
     * Determine error type from HTTP response
     * 
     * @param int $httpCode HTTP status code
     * @param array $response Response data
     * @return string Error type
     */
    private function determineErrorType(int $httpCode, array $response): string {
        switch ($httpCode) {
            case 401:
            case 403:
                return 'AUTHENTICATION_ERROR';
            case 429:
                return 'RATE_LIMIT_ERROR';
            case 400:
                return 'VALIDATION_ERROR';
            case 404:
                return 'NOT_FOUND_ERROR';
            case 500:
            case 502:
            case 503:
            case 504:
                return 'SERVER_ERROR';
            default:
                return 'UNKNOWN_ERROR';
        }
    }
    
    /**
     * Log API request for monitoring
     * 
     * @param string $endpoint API endpoint
     * @param array $payload Request payload
     * @param array $response API response
     * @param int $attempts Number of attempts made
     */
    private function logRequest(string $endpoint, array $payload, array $response, int $attempts): void {
        if (!$this->pdo) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO analytics_etl_log (
                    batch_id, etl_type, status, records_processed, 
                    api_requests_made, execution_time_ms, data_source
                ) VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            
            $batchId = $this->generateBatchId();
            $recordsProcessed = count($response['result']['data'] ?? []);
            
            $stmt->execute([
                $batchId,
                'api_request',
                'completed',
                $recordsProcessed,
                $attempts,
                0, // Will be calculated elsewhere
                'analytics_api'
            ]);
        } catch (Exception $e) {
            // Log error but don't fail the main operation
            error_log("Failed to log API request: " . $e->getMessage());
        }
    }
    
    /**
     * Log retry attempt
     * 
     * @param string $endpoint API endpoint
     * @param int $attempt Attempt number
     * @param int $delay Delay before retry
     * @param string $error Error message
     */
    private function logRetryAttempt(string $endpoint, int $attempt, int $delay, string $error): void {
        error_log("Analytics API retry attempt {$attempt} for {$endpoint}. Waiting {$delay}s. Error: {$error}");
    }
    
    /**
     * Generate unique batch ID
     * 
     * @return string Batch ID
     */
    private function generateBatchId(): string {
        return 'analytics_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
    }
    
    /**
     * Get limit from last request (for pagination)
     * 
     * @return int Last request limit
     */
    private function getLastRequestLimit(): int {
        return self::DEFAULT_LIMIT; // Simplified for now
    }
    
    /**
     * Get API client statistics
     * 
     * @return array Statistics
     */
    public function getStats(): array {
        return [
            'client_id' => $this->clientId,
            'cache_entries' => count($this->cache),
            'request_history_count' => count($this->requestHistory),
            'last_request_time' => $this->lastRequestTime,
            'rate_limit_per_minute' => self::RATE_LIMIT_REQUESTS_PER_MINUTE,
            'max_retries' => self::MAX_RETRIES,
            'cache_ttl' => self::CACHE_TTL
        ];
    }
    
    /**
     * Test API connection
     * 
     * @return array Test result
     */
    public function testConnection(): array {
        try {
            $testData = $this->getStockOnWarehouses(0, 1);
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'response_time' => microtime(true) - $this->lastRequestTime,
                'data_count' => count($testData['data'] ?? [])
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => $e instanceof AnalyticsApiException ? $e->getErrorType() : 'UNKNOWN'
            ];
        }
    }
}

/**
 * Custom exception for Analytics API errors
 */
class AnalyticsApiException extends Exception {
    private string $errorType;
    
    public function __construct(string $message, int $code = 0, string $errorType = 'UNKNOWN') {
        parent::__construct($message, $code);
        $this->errorType = $errorType;
    }
    
    public function getErrorType(): string {
        return $this->errorType;
    }
    
    public function isCritical(): bool {
        $criticalTypes = ['AUTHENTICATION_ERROR', 'NETWORK_ERROR', 'MAX_RETRIES_EXCEEDED'];
        return in_array($this->errorType, $criticalTypes);
    }
}