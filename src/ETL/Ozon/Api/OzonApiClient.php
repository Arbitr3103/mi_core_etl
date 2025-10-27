<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Api;

use Exception;
use MiCore\ETL\Ozon\Core\Logger;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ozon API Client
 * 
 * Provides methods for interacting with Ozon Seller API including:
 * - Authentication and basic HTTP requests
 * - Retry logic with exponential backoff
 * - Rate limiting for API compliance
 * - Product catalog management
 * - Sales history retrieval
 * - Inventory reports handling
 */
class OzonApiClient
{
    private string $clientId;
    private string $apiKey;
    private string $baseUrl;
    private Logger $logger;
    private array $config;
    
    // Rate limiting properties
    private array $rateLimits = [];
    private int $lastRequestTime = 0;
    private int $requestCount = 0;
    private int $windowStart = 0;
    
    // Retry configuration
    private int $maxRetries;
    private float $baseDelay;
    private float $maxDelay;
    
    public function __construct(
        string $clientId,
        string $apiKey,
        Logger $logger,
        array $config = []
    ) {
        $this->clientId = $clientId;
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        
        $this->baseUrl = $this->config['base_url'];
        $this->maxRetries = $this->config['retry']['max_attempts'];
        $this->baseDelay = $this->config['retry']['base_delay'];
        $this->maxDelay = $this->config['retry']['max_delay'];
        
        $this->validateCredentials();
        $this->initializeRateLimits();
        
        $this->logger->info('OzonApiClient initialized', [
            'client_id' => substr($this->clientId, 0, 8) . '...',
            'base_url' => $this->baseUrl,
            'rate_limits' => $this->config['rate_limits']
        ]);
    }

    /**
     * Create OzonApiClient from configuration array
     * 
     * @param array $config Configuration array containing auth and other settings
     * @param Logger|null $logger Optional logger instance
     * @return self OzonApiClient instance
     */
    public static function fromConfig(array $config, ?Logger $logger = null): self
    {
        $logger = $logger ?? new Logger('ozon-api-client');
        
        $clientId = $config['auth']['client_id'] ?? '';
        $apiKey = $config['auth']['api_key'] ?? '';
        
        return new self($clientId, $apiKey, $logger, $config);
    }

    /**
     * Get default configuration
     * 
     * @return array Default configuration
     */
    private function getDefaultConfig(): array
    {
        return [
            'base_url' => 'https://api-seller.ozon.ru',
            'timeout' => 30,
            'connect_timeout' => 10,
            'user_agent' => 'MiCore-ETL-OzonClient/1.0',
            'retry' => [
                'max_attempts' => 5,
                'base_delay' => 1.0,
                'max_delay' => 60.0,
                'backoff_multiplier' => 2.0,
                'jitter' => true
            ],
            'rate_limits' => [
                'requests_per_minute' => 1000,
                'requests_per_second' => 20,
                'burst_limit' => 50
            ],
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ];
    }

    /**
     * Validate API credentials
     * 
     * @throws InvalidArgumentException When credentials are invalid
     */
    private function validateCredentials(): void
    {
        if (empty($this->clientId)) {
            throw new InvalidArgumentException('Client ID cannot be empty');
        }
        
        if (empty($this->apiKey)) {
            throw new InvalidArgumentException('API Key cannot be empty');
        }
        
        if (strlen($this->clientId) < 5) {
            throw new InvalidArgumentException('Client ID appears to be invalid');
        }
        
        if (strlen($this->apiKey) < 10) {
            throw new InvalidArgumentException('API Key appears to be invalid');
        }
    }

    /**
     * Initialize rate limiting counters
     */
    private function initializeRateLimits(): void
    {
        $this->rateLimits = [
            'minute' => [
                'count' => 0,
                'window_start' => time(),
                'limit' => $this->config['rate_limits']['requests_per_minute']
            ],
            'second' => [
                'count' => 0,
                'window_start' => time(),
                'limit' => $this->config['rate_limits']['requests_per_second']
            ]
        ];
    }

    /**
     * Make HTTP request to Ozon API with retry logic and rate limiting
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param array $headers Additional headers
     * @return array API response
     * @throws RuntimeException When request fails after all retries
     */
    public function makeRequest(
        string $method,
        string $endpoint,
        array $data = [],
        array $headers = []
    ): array {
        $attempt = 0;
        $lastException = null;
        
        while ($attempt <= $this->maxRetries) {
            try {
                // Apply rate limiting
                $this->enforceRateLimit();
                
                // Make the actual request
                $response = $this->executeRequest($method, $endpoint, $data, $headers);
                
                // Log successful request
                $this->logger->debug('API request successful', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'attempt' => $attempt + 1,
                    'response_size' => strlen(json_encode($response))
                ]);
                
                return $response;
                
            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;
                
                $this->logger->warning('API request failed', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'will_retry' => $attempt <= $this->maxRetries
                ]);
                
                // Don't retry on certain errors
                if ($this->shouldNotRetry($e)) {
                    break;
                }
                
                // Calculate delay for next attempt
                if ($attempt <= $this->maxRetries) {
                    $delay = $this->calculateRetryDelay($attempt);
                    $this->logger->debug('Waiting before retry', [
                        'delay_seconds' => $delay,
                        'attempt' => $attempt
                    ]);
                    usleep((int)($delay * 1000000)); // Convert to microseconds
                }
            }
        }
        
        // All retries exhausted
        $this->logger->error('API request failed after all retries', [
            'method' => $method,
            'endpoint' => $endpoint,
            'attempts' => $attempt,
            'final_error' => $lastException->getMessage()
        ]);
        
        throw new RuntimeException(
            "API request failed after {$attempt} attempts: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }

    /**
     * Execute the actual HTTP request
     * 
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param array $headers Additional headers
     * @return array Parsed response
     * @throws RuntimeException When request fails
     */
    private function executeRequest(
        string $method,
        string $endpoint,
        array $data = [],
        array $headers = []
    ): array {
        $url = $this->baseUrl . $endpoint;
        
        // Prepare headers
        $requestHeaders = array_merge($this->config['headers'], $headers, [
            'Client-Id: ' . $this->clientId,
            'Api-Key: ' . $this->apiKey
        ]);
        
        // Initialize cURL
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config['timeout'],
            CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'],
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_USERAGENT => $this->config['user_agent'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0
        ]);
        
        // Set method-specific options
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                break;
                
            case 'GET':
                if (!empty($data)) {
                    $url .= '?' . http_build_query($data);
                    curl_setopt($ch, CURLOPT_URL, $url);
                }
                break;
                
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if (!empty($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
        }
        
        // Execute request
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $duration = microtime(true) - $startTime;
        
        // Get request info
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        
        // Check for cURL errors
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error: {$error}");
        }
        
        curl_close($ch);
        
        // Log request performance
        $this->logger->logPerformance('api_request', $duration, [
            'method' => $method,
            'endpoint' => $endpoint,
            'http_code' => $httpCode,
            'response_size' => strlen($response),
            'total_time' => $totalTime
        ]);
        
        // Parse and validate response
        return $this->parseResponse($response, $httpCode, $endpoint);
    }

    /**
     * Parse and validate API response
     * 
     * @param string $response Raw response
     * @param int $httpCode HTTP status code
     * @param string $endpoint API endpoint for context
     * @return array Parsed response
     * @throws RuntimeException When response is invalid
     */
    private function parseResponse(string $response, int $httpCode, string $endpoint): array
    {
        // Check HTTP status code
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('HTTP error response', [
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'response' => substr($response, 0, 1000)
            ]);
            
            throw new RuntimeException("HTTP {$httpCode} error for endpoint {$endpoint}");
        }
        
        // Parse JSON response
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('JSON decode error', [
                'endpoint' => $endpoint,
                'json_error' => json_last_error_msg(),
                'response_preview' => substr($response, 0, 500)
            ]);
            
            throw new RuntimeException('Invalid JSON response: ' . json_last_error_msg());
        }
        
        // Validate response structure
        if (!is_array($decoded)) {
            throw new RuntimeException('Response is not a valid array');
        }
        
        // Check for API errors
        if (isset($decoded['error'])) {
            $errorMessage = $decoded['error']['message'] ?? 'Unknown API error';
            $errorCode = $decoded['error']['code'] ?? 'UNKNOWN';
            
            $this->logger->error('API error response', [
                'endpoint' => $endpoint,
                'error_code' => $errorCode,
                'error_message' => $errorMessage
            ]);
            
            throw new RuntimeException("API Error [{$errorCode}]: {$errorMessage}");
        }
        
        return $decoded;
    }

    /**
     * Enforce rate limiting before making requests
     * 
     * @throws RuntimeException When rate limit is exceeded
     */
    private function enforceRateLimit(): void
    {
        $currentTime = time();
        
        // Check and reset minute window
        if ($currentTime - $this->rateLimits['minute']['window_start'] >= 60) {
            $this->rateLimits['minute']['count'] = 0;
            $this->rateLimits['minute']['window_start'] = $currentTime;
        }
        
        // Check and reset second window
        if ($currentTime - $this->rateLimits['second']['window_start'] >= 1) {
            $this->rateLimits['second']['count'] = 0;
            $this->rateLimits['second']['window_start'] = $currentTime;
        }
        
        // Check minute limit
        if ($this->rateLimits['minute']['count'] >= $this->rateLimits['minute']['limit']) {
            $waitTime = 60 - ($currentTime - $this->rateLimits['minute']['window_start']);
            $this->logger->warning('Rate limit exceeded (minute)', [
                'wait_seconds' => $waitTime,
                'requests_made' => $this->rateLimits['minute']['count']
            ]);
            sleep($waitTime);
            $this->rateLimits['minute']['count'] = 0;
            $this->rateLimits['minute']['window_start'] = time();
        }
        
        // Check second limit
        if ($this->rateLimits['second']['count'] >= $this->rateLimits['second']['limit']) {
            $waitTime = 1 - ($currentTime - $this->rateLimits['second']['window_start']);
            if ($waitTime > 0) {
                $this->logger->debug('Rate limit throttling (second)', [
                    'wait_seconds' => $waitTime
                ]);
                usleep((int)($waitTime * 1000000));
            }
            $this->rateLimits['second']['count'] = 0;
            $this->rateLimits['second']['window_start'] = time();
        }
        
        // Increment counters
        $this->rateLimits['minute']['count']++;
        $this->rateLimits['second']['count']++;
    }

    /**
     * Calculate retry delay with exponential backoff and jitter
     * 
     * @param int $attempt Attempt number (1-based)
     * @return float Delay in seconds
     */
    private function calculateRetryDelay(int $attempt): float
    {
        $delay = $this->baseDelay * pow($this->config['retry']['backoff_multiplier'], $attempt - 1);
        
        // Apply maximum delay limit
        $delay = min($delay, $this->maxDelay);
        
        // Add jitter to prevent thundering herd
        if ($this->config['retry']['jitter']) {
            $jitter = $delay * 0.1 * (mt_rand() / mt_getrandmax());
            $delay += $jitter;
        }
        
        return $delay;
    }

    /**
     * Determine if an error should not be retried
     * 
     * @param Exception $exception The exception to check
     * @return bool True if should not retry
     */
    private function shouldNotRetry(Exception $exception): bool
    {
        $message = $exception->getMessage();
        
        // Don't retry on authentication errors
        if (strpos($message, 'HTTP 401') !== false || 
            strpos($message, 'HTTP 403') !== false) {
            return true;
        }
        
        // Don't retry on bad request errors
        if (strpos($message, 'HTTP 400') !== false) {
            return true;
        }
        
        // Don't retry on not found errors
        if (strpos($message, 'HTTP 404') !== false) {
            return true;
        }
        
        // Don't retry on JSON decode errors (likely malformed response)
        if (strpos($message, 'Invalid JSON') !== false) {
            return true;
        }
        
        return false;
    }

    /**
     * Get API client statistics
     * 
     * @return array Statistics
     */
    public function getStats(): array
    {
        return [
            'base_url' => $this->baseUrl,
            'rate_limits' => $this->rateLimits,
            'config' => [
                'timeout' => $this->config['timeout'],
                'max_retries' => $this->maxRetries,
                'base_delay' => $this->baseDelay,
                'max_delay' => $this->maxDelay
            ]
        ];
    }

    /**
     * Reset rate limiting counters (useful for testing)
     */
    public function resetRateLimits(): void
    {
        $this->initializeRateLimits();
        $this->logger->debug('Rate limits reset');
    }

    /**
     * Get products from Ozon API with pagination support
     * 
     * @param int $limit Number of products per request (max 1000)
     * @param string|null $lastId Last product ID for pagination
     * @param array $filter Additional filters
     * @return array API response with products
     * @throws InvalidArgumentException When parameters are invalid
     * @throws RuntimeException When API request fails
     */
    public function getProducts(int $limit = 1000, ?string $lastId = null, array $filter = []): array
    {
        // Validate parameters
        if ($limit <= 0 || $limit > 1000) {
            throw new InvalidArgumentException('Limit must be between 1 and 1000');
        }
        
        $requestData = [
            'filter' => $filter,
            'limit' => $limit
        ];
        
        if ($lastId !== null) {
            $requestData['last_id'] = $lastId;
        }
        
        $this->logger->debug('Requesting products', [
            'limit' => $limit,
            'last_id' => $lastId,
            'filter_count' => count($filter)
        ]);
        
        try {
            $response = $this->makeRequest('POST', '/v2/product/list', $requestData);
            
            // Validate response structure
            $this->validateProductsResponse($response);
            
            $productCount = count($response['result']['items'] ?? []);
            $hasMore = !empty($response['result']['last_id']);
            
            $this->logger->info('Products retrieved successfully', [
                'count' => $productCount,
                'has_more' => $hasMore,
                'last_id' => $response['result']['last_id'] ?? null
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve products', [
                'limit' => $limit,
                'last_id' => $lastId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get all products with automatic pagination
     * 
     * @param array $filter Additional filters
     * @param int $batchSize Batch size for each request (max 1000)
     * @param callable|null $progressCallback Callback for progress updates
     * @return array All products
     * @throws InvalidArgumentException When parameters are invalid
     * @throws RuntimeException When API request fails
     */
    public function getAllProducts(
        array $filter = [],
        int $batchSize = 1000,
        ?callable $progressCallback = null
    ): array {
        if ($batchSize <= 0 || $batchSize > 1000) {
            throw new InvalidArgumentException('Batch size must be between 1 and 1000');
        }
        
        $allProducts = [];
        $lastId = null;
        $totalBatches = 0;
        $totalProducts = 0;
        
        $this->logger->info('Starting full product retrieval', [
            'batch_size' => $batchSize,
            'filter_count' => count($filter)
        ]);
        
        do {
            $response = $this->getProducts($batchSize, $lastId, $filter);
            $batch = $response['result']['items'] ?? [];
            $lastId = $response['result']['last_id'] ?? null;
            
            $allProducts = array_merge($allProducts, $batch);
            $totalBatches++;
            $totalProducts += count($batch);
            
            // Call progress callback if provided
            if ($progressCallback !== null) {
                $progressCallback($totalProducts, $totalBatches, count($batch), $lastId !== null);
            }
            
            $this->logger->debug('Product batch processed', [
                'batch_number' => $totalBatches,
                'batch_size' => count($batch),
                'total_products' => $totalProducts,
                'has_more' => $lastId !== null
            ]);
            
            // Small delay between requests to be respectful to API
            if ($lastId !== null) {
                usleep(100000); // 100ms delay
            }
            
        } while ($lastId !== null && count($batch) > 0);
        
        $this->logger->info('Full product retrieval completed', [
            'total_products' => $totalProducts,
            'total_batches' => $totalBatches
        ]);
        
        return $allProducts;
    }

    /**
     * Get product information by product IDs
     * 
     * @param array $productIds Array of product IDs
     * @return array Product information
     * @throws InvalidArgumentException When parameters are invalid
     * @throws RuntimeException When API request fails
     */
    public function getProductInfo(array $productIds): array
    {
        if (empty($productIds)) {
            throw new InvalidArgumentException('Product IDs array cannot be empty');
        }
        
        if (count($productIds) > 1000) {
            throw new InvalidArgumentException('Cannot request more than 1000 products at once');
        }
        
        // Ensure all IDs are integers
        $productIds = array_map('intval', $productIds);
        
        $requestData = [
            'product_id' => $productIds
        ];
        
        $this->logger->debug('Requesting product info', [
            'product_count' => count($productIds)
        ]);
        
        try {
            $response = $this->makeRequest('POST', '/v2/product/info', $requestData);
            
            // Validate response structure
            if (!isset($response['result']['items'])) {
                throw new RuntimeException('Invalid product info response structure');
            }
            
            $this->logger->info('Product info retrieved successfully', [
                'requested_count' => count($productIds),
                'returned_count' => count($response['result']['items'])
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve product info', [
                'product_ids_count' => count($productIds),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get product attributes by product IDs
     * 
     * @param array $productIds Array of product IDs
     * @param array $filter Additional filters
     * @return array Product attributes
     * @throws InvalidArgumentException When parameters are invalid
     * @throws RuntimeException When API request fails
     */
    public function getProductAttributes(array $productIds, array $filter = []): array
    {
        if (empty($productIds)) {
            throw new InvalidArgumentException('Product IDs array cannot be empty');
        }
        
        if (count($productIds) > 100) {
            throw new InvalidArgumentException('Cannot request attributes for more than 100 products at once');
        }
        
        // Ensure all IDs are integers
        $productIds = array_map('intval', $productIds);
        
        $requestData = array_merge([
            'product_id' => $productIds
        ], $filter);
        
        $this->logger->debug('Requesting product attributes', [
            'product_count' => count($productIds),
            'filter_count' => count($filter)
        ]);
        
        try {
            $response = $this->makeRequest('POST', '/v3/products/info/attributes', $requestData);
            
            // Validate response structure
            if (!isset($response['result'])) {
                throw new RuntimeException('Invalid product attributes response structure');
            }
            
            $this->logger->info('Product attributes retrieved successfully', [
                'requested_count' => count($productIds),
                'returned_count' => count($response['result'])
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve product attributes', [
                'product_ids_count' => count($productIds),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate products API response structure
     * 
     * @param array $response API response
     * @throws RuntimeException When response structure is invalid
     */
    private function validateProductsResponse(array $response): void
    {
        if (!isset($response['result'])) {
            throw new RuntimeException('Missing result field in products response');
        }
        
        if (!isset($response['result']['items'])) {
            throw new RuntimeException('Missing items field in products response');
        }
        
        if (!is_array($response['result']['items'])) {
            throw new RuntimeException('Items field must be an array');
        }
        
        // Validate each product item has required fields
        foreach ($response['result']['items'] as $index => $item) {
            if (!isset($item['product_id'])) {
                throw new RuntimeException("Missing product_id in item {$index}");
            }
            
            if (!isset($item['offer_id'])) {
                throw new RuntimeException("Missing offer_id in item {$index}");
            }
        }
    }

    /**
     * Get sales history from Ozon API with date filtering and pagination
     * 
     * @param string $since Start date in ISO 8601 format (Y-m-d\TH:i:s\Z)
     * @param string $to End date in ISO 8601 format (Y-m-d\TH:i:s\Z)
     * @param int $limit Number of orders per request (max 1000)
     * @param int $offset Offset for pagination
     * @param array $additionalFilters Additional filters
     * @return array API response with sales data
     * @throws InvalidArgumentException When parameters are invalid
     * @throws RuntimeException When API request fails
     */
    public function getSalesHistory(
        string $since,
        string $to,
        int $limit = 1000,
        int $offset = 0,
        array $additionalFilters = []
    ): array {
        // Validate parameters
        if ($limit <= 0 || $limit > 1000) {
            throw new InvalidArgumentException('Limit must be between 1 and 1000');
        }
        
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be non-negative');
        }
        
        // Validate date formats
        $this->validateDateFormat($since, 'since');
        $this->validateDateFormat($to, 'to');
        
        // Validate date range
        if (strtotime($since) >= strtotime($to)) {
            throw new InvalidArgumentException('Since date must be before to date');
        }
        
        $filter = array_merge([
            'since' => $since,
            'to' => $to
        ], $additionalFilters);
        
        $requestData = [
            'filter' => $filter,
            'limit' => $limit,
            'offset' => $offset
        ];
        
        $this->logger->debug('Requesting sales history', [
            'since' => $since,
            'to' => $to,
            'limit' => $limit,
            'offset' => $offset,
            'additional_filters' => count($additionalFilters)
        ]);
        
        try {
            $response = $this->makeRequest('POST', '/v2/posting/fbo/list', $requestData);
            
            // Validate response structure
            $this->validateSalesResponse($response);
            
            $orderCount = count($response['result']['postings'] ?? []);
            $hasMore = $orderCount === $limit; // If we got full batch, there might be more
            
            $this->logger->info('Sales history retrieved successfully', [
                'count' => $orderCount,
                'has_more' => $hasMore,
                'date_range' => "{$since} to {$to}"
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve sales history', [
                'since' => $since,
                'to' => $to,
                'limit' => $limit,
                'offset' => $offset,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get all sales history for a date range with automatic pagination
     * 
     * @param string $since Start date in ISO 8601 format
     * @param string $to End date in ISO 8601 format
     * @param int $batchSize Batch size for each request (max 1000)
     * @param array $additionalFilters Additional filters
     * @param callable|null $progressCallback Callback for progress updates
     * @return array All sales orders
     * @throws InvalidArgumentException When parameters are invalid
     * @throws RuntimeException When API request fails
     */
    public function getAllSalesHistory(
        string $since,
        string $to,
        int $batchSize = 1000,
        array $additionalFilters = [],
        ?callable $progressCallback = null
    ): array {
        if ($batchSize <= 0 || $batchSize > 1000) {
            throw new InvalidArgumentException('Batch size must be between 1 and 1000');
        }
        
        $allOrders = [];
        $offset = 0;
        $totalBatches = 0;
        $totalOrders = 0;
        
        $this->logger->info('Starting full sales history retrieval', [
            'since' => $since,
            'to' => $to,
            'batch_size' => $batchSize,
            'additional_filters' => count($additionalFilters)
        ]);
        
        do {
            $response = $this->getSalesHistory($since, $to, $batchSize, $offset, $additionalFilters);
            $batch = $response['result']['postings'] ?? [];
            
            $allOrders = array_merge($allOrders, $batch);
            $totalBatches++;
            $totalOrders += count($batch);
            $offset += $batchSize;
            
            // Call progress callback if provided
            if ($progressCallback !== null) {
                $progressCallback($totalOrders, $totalBatches, count($batch), count($batch) === $batchSize);
            }
            
            $this->logger->debug('Sales batch processed', [
                'batch_number' => $totalBatches,
                'batch_size' => count($batch),
                'total_orders' => $totalOrders,
                'offset' => $offset
            ]);
            
            // Small delay between requests to be respectful to API
            if (count($batch) === $batchSize) {
                usleep(100000); // 100ms delay
            }
            
        } while (count($batch) === $batchSize);
        
        $this->logger->info('Full sales history retrieval completed', [
            'total_orders' => $totalOrders,
            'total_batches' => $totalBatches,
            'date_range' => "{$since} to {$to}"
        ]);
        
        return $allOrders;
    }

    /**
     * Get sales history for the last N days
     * 
     * @param int $days Number of days to look back
     * @param int $batchSize Batch size for each request
     * @param array $additionalFilters Additional filters
     * @return array Sales orders
     * @throws InvalidArgumentException When parameters are invalid
     * @throws RuntimeException When API request fails
     */
    public function getRecentSalesHistory(
        int $days = 30,
        int $batchSize = 1000,
        array $additionalFilters = []
    ): array {
        if ($days <= 0 || $days > 365) {
            throw new InvalidArgumentException('Days must be between 1 and 365');
        }
        
        $to = date('Y-m-d\TH:i:s\Z');
        $since = date('Y-m-d\TH:i:s\Z', strtotime("-{$days} days"));
        
        $this->logger->info('Retrieving recent sales history', [
            'days' => $days,
            'since' => $since,
            'to' => $to
        ]);
        
        return $this->getAllSalesHistory($since, $to, $batchSize, $additionalFilters);
    }

    /**
     * Get FBS (Fulfillment by Seller) orders
     * 
     * @param string $since Start date in ISO 8601 format
     * @param string $to End date in ISO 8601 format
     * @param int $limit Number of orders per request (max 1000)
     * @param int $offset Offset for pagination
     * @param array $additionalFilters Additional filters
     * @return array API response with FBS orders
     * @throws InvalidArgumentException When parameters are invalid
     * @throws RuntimeException When API request fails
     */
    public function getFBSOrders(
        string $since,
        string $to,
        int $limit = 1000,
        int $offset = 0,
        array $additionalFilters = []
    ): array {
        // Validate parameters (same as FBO)
        if ($limit <= 0 || $limit > 1000) {
            throw new InvalidArgumentException('Limit must be between 1 and 1000');
        }
        
        if ($offset < 0) {
            throw new InvalidArgumentException('Offset must be non-negative');
        }
        
        $this->validateDateFormat($since, 'since');
        $this->validateDateFormat($to, 'to');
        
        $filter = array_merge([
            'since' => $since,
            'to' => $to
        ], $additionalFilters);
        
        $requestData = [
            'filter' => $filter,
            'limit' => $limit,
            'offset' => $offset
        ];
        
        $this->logger->debug('Requesting FBS orders', [
            'since' => $since,
            'to' => $to,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        try {
            $response = $this->makeRequest('POST', '/v2/posting/fbs/list', $requestData);
            
            // Validate response structure (same as FBO)
            $this->validateSalesResponse($response);
            
            $orderCount = count($response['result']['postings'] ?? []);
            
            $this->logger->info('FBS orders retrieved successfully', [
                'count' => $orderCount,
                'date_range' => "{$since} to {$to}"
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve FBS orders', [
                'since' => $since,
                'to' => $to,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Validate date format for API requests
     * 
     * @param string $date Date string to validate
     * @param string $fieldName Field name for error messages
     * @throws InvalidArgumentException When date format is invalid
     */
    private function validateDateFormat(string $date, string $fieldName): void
    {
        // Check if date matches ISO 8601 format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $date)) {
            throw new InvalidArgumentException(
                "Invalid {$fieldName} date format. Expected: Y-m-d\\TH:i:s\\Z (ISO 8601)"
            );
        }
        
        // Validate that the date is actually parseable
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new InvalidArgumentException("Invalid {$fieldName} date: {$date}");
        }
        
        // Check if date is not too far in the future
        if ($timestamp > time() + 86400) { // Allow 1 day in future for timezone differences
            throw new InvalidArgumentException("Date {$fieldName} cannot be more than 1 day in the future");
        }
    }

    /**
     * Validate sales API response structure
     * 
     * @param array $response API response
     * @throws RuntimeException When response structure is invalid
     */
    private function validateSalesResponse(array $response): void
    {
        if (!isset($response['result'])) {
            throw new RuntimeException('Missing result field in sales response');
        }
        
        if (!isset($response['result']['postings'])) {
            throw new RuntimeException('Missing postings field in sales response');
        }
        
        if (!is_array($response['result']['postings'])) {
            throw new RuntimeException('Postings field must be an array');
        }
        
        // Validate each posting has required fields
        foreach ($response['result']['postings'] as $index => $posting) {
            if (!isset($posting['posting_number'])) {
                throw new RuntimeException("Missing posting_number in posting {$index}");
            }
            
            if (!isset($posting['products']) || !is_array($posting['products'])) {
                throw new RuntimeException("Missing or invalid products array in posting {$index}");
            }
            
            // Validate each product in the posting
            foreach ($posting['products'] as $productIndex => $product) {
                if (!isset($product['sku'])) {
                    throw new RuntimeException(
                        "Missing sku in product {$productIndex} of posting {$index}"
                    );
                }
                
                if (!isset($product['quantity'])) {
                    throw new RuntimeException(
                        "Missing quantity in product {$productIndex} of posting {$index}"
                    );
                }
            }
        }
    }

    /**
     * Create products report
     * 
     * @param string $language Report language (DEFAULT, RU, EN, etc.)
     * @param array $filter Additional filters for products report
     * @return array API response with report code
     * @throws RuntimeException When report creation fails
     */
    public function createProductsReport(string $language = 'DEFAULT', array $filter = []): array
    {
        $requestData = [
            'language' => $language,
            'filter' => $filter
        ];
        
        $this->logger->info('Creating products report', [
            'language' => $language,
            'filter_count' => count($filter)
        ]);
        
        try {
            $response = $this->makeRequest('POST', '/v1/report/products/create', $requestData);
            
            // Validate response structure
            if (!isset($response['result']['code'])) {
                throw new RuntimeException('Missing report code in response');
            }
            
            $reportCode = $response['result']['code'];
            
            $this->logger->info('Products report creation initiated', [
                'report_code' => $reportCode,
                'language' => $language
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to create products report', [
                'language' => $language,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create warehouse stock report
     * 
     * @param string $language Report language (DEFAULT, RU, EN, etc.)
     * @return array API response with report code
     * @throws RuntimeException When report creation fails
     */
    public function createStockReport(string $language = 'DEFAULT'): array
    {
        $requestData = [
            'language' => $language
        ];
        
        $this->logger->info('Creating warehouse stock report', [
            'language' => $language
        ]);
        
        try {
            $response = $this->makeRequest('POST', '/v1/report/warehouse/stock', $requestData);
            
            // Validate response structure
            if (!isset($response['result']['code'])) {
                throw new RuntimeException('Missing report code in response');
            }
            
            $reportCode = $response['result']['code'];
            
            $this->logger->info('Stock report creation initiated', [
                'report_code' => $reportCode,
                'language' => $language
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to create stock report', [
                'language' => $language,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get report status and information
     * 
     * @param string $reportCode Report code from createStockReport
     * @return array Report status information
     * @throws InvalidArgumentException When report code is invalid
     * @throws RuntimeException When status check fails
     */
    public function getReportStatus(string $reportCode): array
    {
        if (empty($reportCode)) {
            throw new InvalidArgumentException('Report code cannot be empty');
        }
        
        $requestData = [
            'code' => $reportCode
        ];
        
        $this->logger->debug('Checking report status', [
            'report_code' => $reportCode
        ]);
        
        try {
            $response = $this->makeRequest('POST', '/v1/report/info', $requestData);
            
            // Validate response structure
            if (!isset($response['result']['status'])) {
                throw new RuntimeException('Missing status in report info response');
            }
            
            $status = $response['result']['status'];
            $fileUrl = $response['result']['file'] ?? null;
            
            $this->logger->debug('Report status retrieved', [
                'report_code' => $reportCode,
                'status' => $status,
                'has_file' => $fileUrl !== null
            ]);
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to get report status', [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Wait for report completion with polling
     * 
     * @param string $reportCode Report code to monitor
     * @param int $maxAttempts Maximum number of polling attempts
     * @param int $pollInterval Interval between polls in seconds
     * @return array Final report status when completed
     * @throws RuntimeException When report fails or times out
     */
    public function waitForReportCompletion(
        string $reportCode,
        int $maxAttempts = 30,
        int $pollInterval = 60
    ): array {
        if ($maxAttempts <= 0) {
            throw new InvalidArgumentException('Max attempts must be positive');
        }
        
        if ($pollInterval <= 0) {
            throw new InvalidArgumentException('Poll interval must be positive');
        }
        
        $this->logger->info('Starting report polling', [
            'report_code' => $reportCode,
            'max_attempts' => $maxAttempts,
            'poll_interval' => $pollInterval
        ]);
        
        $attempt = 0;
        
        while ($attempt < $maxAttempts) {
            $attempt++;
            
            try {
                $statusResponse = $this->getReportStatus($reportCode);
                $status = $statusResponse['result']['status'];
                
                $this->logger->debug('Report polling attempt', [
                    'report_code' => $reportCode,
                    'attempt' => $attempt,
                    'status' => $status
                ]);
                
                switch ($status) {
                    case 'success':
                        $this->logger->info('Report completed successfully', [
                            'report_code' => $reportCode,
                            'attempts' => $attempt,
                            'file_url' => $statusResponse['result']['file'] ?? null
                        ]);
                        return $statusResponse;
                        
                    case 'failed':
                    case 'error':
                        $errorMessage = $statusResponse['result']['error'] ?? 'Unknown error';
                        $this->logger->error('Report generation failed', [
                            'report_code' => $reportCode,
                            'status' => $status,
                            'error' => $errorMessage
                        ]);
                        throw new RuntimeException("Report generation failed: {$errorMessage}");
                        
                    case 'processing':
                    case 'waiting':
                        // Continue polling
                        if ($attempt < $maxAttempts) {
                            $this->logger->debug('Report still processing, waiting', [
                                'report_code' => $reportCode,
                                'status' => $status,
                                'next_check_in' => $pollInterval
                            ]);
                            sleep($pollInterval);
                        }
                        break;
                        
                    default:
                        $this->logger->warning('Unknown report status', [
                            'report_code' => $reportCode,
                            'status' => $status
                        ]);
                        if ($attempt < $maxAttempts) {
                            sleep($pollInterval);
                        }
                }
                
            } catch (Exception $e) {
                $this->logger->error('Error during report polling', [
                    'report_code' => $reportCode,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                // If it's the last attempt, throw the error
                if ($attempt >= $maxAttempts) {
                    throw $e;
                }
                
                // Otherwise, wait and try again
                sleep($pollInterval);
            }
        }
        
        // Timeout reached
        $this->logger->error('Report polling timeout', [
            'report_code' => $reportCode,
            'max_attempts' => $maxAttempts,
            'total_wait_time' => $maxAttempts * $pollInterval
        ]);
        
        throw new RuntimeException(
            "Report generation timeout after {$maxAttempts} attempts " .
            "(" . ($maxAttempts * $pollInterval) . " seconds)"
        );
    }

    /**
     * Download and parse CSV report file
     * 
     * @param string $fileUrl URL of the CSV file to download
     * @param bool $hasHeader Whether the CSV has a header row
     * @param string $delimiter CSV delimiter
     * @param string $enclosure CSV enclosure character
     * @return array Parsed CSV data
     * @throws InvalidArgumentException When URL is invalid
     * @throws RuntimeException When download or parsing fails
     */
    public function downloadAndParseCsv(
        string $fileUrl,
        bool $hasHeader = true,
        string $delimiter = ',',
        string $enclosure = '"'
    ): array {
        if (empty($fileUrl)) {
            throw new InvalidArgumentException('File URL cannot be empty');
        }
        
        if (!filter_var($fileUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Invalid file URL format');
        }
        
        $this->logger->info('Downloading CSV report', [
            'file_url' => $fileUrl,
            'has_header' => $hasHeader
        ]);
        
        try {
            // Download the file
            $csvContent = $this->downloadFile($fileUrl);
            
            // Parse CSV content
            $parsedData = $this->parseCsvContent($csvContent, $hasHeader, $delimiter, $enclosure);
            
            $this->logger->info('CSV report downloaded and parsed successfully', [
                'file_url' => $fileUrl,
                'rows_count' => count($parsedData),
                'file_size' => strlen($csvContent)
            ]);
            
            return $parsedData;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to download and parse CSV', [
                'file_url' => $fileUrl,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Download file from URL
     * 
     * @param string $url File URL
     * @return string File content
     * @throws RuntimeException When download fails
     */
    private function downloadFile(string $url): string
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300, // 5 minutes for large files
            CURLOPT_CONNECTTIMEOUT => $this->config['connect_timeout'],
            CURLOPT_USERAGENT => $this->config['user_agent'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5
        ]);
        
        $startTime = microtime(true);
        $content = curl_exec($ch);
        $duration = microtime(true) - $startTime;
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        
        if ($content === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("File download failed: {$error}");
        }
        
        curl_close($ch);
        
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("HTTP {$httpCode} error while downloading file");
        }
        
        $this->logger->logPerformance('file_download', $duration, [
            'url' => $url,
            'http_code' => $httpCode,
            'content_length' => $contentLength,
            'actual_size' => strlen($content)
        ]);
        
        return $content;
    }

    /**
     * Parse CSV content into array
     * 
     * @param string $csvContent CSV content
     * @param bool $hasHeader Whether CSV has header row
     * @param string $delimiter CSV delimiter
     * @param string $enclosure CSV enclosure character
     * @return array Parsed data
     * @throws RuntimeException When parsing fails
     */
    private function parseCsvContent(
        string $csvContent,
        bool $hasHeader = true,
        string $delimiter = ',',
        string $enclosure = '"'
    ): array {
        if (empty($csvContent)) {
            throw new RuntimeException('CSV content is empty');
        }
        
        // Create temporary file for CSV parsing
        $tempFile = tmpfile();
        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file for CSV parsing');
        }
        
        fwrite($tempFile, $csvContent);
        rewind($tempFile);
        
        $parsedData = [];
        $headers = [];
        $rowNumber = 0;
        
        try {
            while (($row = fgetcsv($tempFile, 0, $delimiter, $enclosure)) !== false) {
                $rowNumber++;
                
                if ($hasHeader && $rowNumber === 1) {
                    $headers = $row;
                    continue;
                }
                
                if ($hasHeader && !empty($headers)) {
                    // Create associative array with headers as keys
                    $rowData = [];
                    foreach ($headers as $index => $header) {
                        $rowData[$header] = $row[$index] ?? '';
                    }
                    $parsedData[] = $rowData;
                } else {
                    // Use numeric indices
                    $parsedData[] = $row;
                }
            }
            
        } catch (Exception $e) {
            fclose($tempFile);
            throw new RuntimeException("CSV parsing error at row {$rowNumber}: " . $e->getMessage());
        }
        
        fclose($tempFile);
        
        $this->logger->debug('CSV parsing completed', [
            'total_rows' => count($parsedData),
            'has_header' => $hasHeader,
            'headers_count' => count($headers)
        ]);
        
        return $parsedData;
    }

    /**
     * Create and download products report in one operation
     * 
     * @param string $language Report language
     * @param array $filter Additional filters for products report
     * @param int $maxWaitTime Maximum wait time in seconds
     * @param int $pollInterval Poll interval in seconds
     * @return array Parsed CSV data
     * @throws RuntimeException When report creation or download fails
     */
    public function getProductsReportData(
        string $language = 'DEFAULT',
        array $filter = [],
        int $maxWaitTime = 1800,
        int $pollInterval = 60
    ): array {
        $maxAttempts = (int)ceil($maxWaitTime / $pollInterval);
        
        $this->logger->info('Starting complete products report process', [
            'language' => $language,
            'filter_count' => count($filter),
            'max_wait_time' => $maxWaitTime,
            'poll_interval' => $pollInterval
        ]);
        
        // Step 1: Create report
        $createResponse = $this->createProductsReport($language, $filter);
        $reportCode = $createResponse['result']['code'];
        
        // Step 2: Wait for completion
        $statusResponse = $this->waitForReportCompletion($reportCode, $maxAttempts, $pollInterval);
        
        // Step 3: Download and parse
        $fileUrl = $statusResponse['result']['file'];
        $csvData = $this->downloadAndParseCsv($fileUrl);
        
        $this->logger->info('Complete products report process finished', [
            'report_code' => $reportCode,
            'rows_count' => count($csvData)
        ]);
        
        return $csvData;
    }

    /**
     * Create and download stock report in one operation
     * 
     * @param string $language Report language
     * @param int $maxWaitTime Maximum wait time in seconds
     * @param int $pollInterval Poll interval in seconds
     * @return array Parsed CSV data
     * @throws RuntimeException When report creation or download fails
     */
    public function getStockReportData(
        string $language = 'DEFAULT',
        int $maxWaitTime = 1800,
        int $pollInterval = 60
    ): array {
        $maxAttempts = (int)ceil($maxWaitTime / $pollInterval);
        
        $this->logger->info('Starting complete stock report process', [
            'language' => $language,
            'max_wait_time' => $maxWaitTime,
            'poll_interval' => $pollInterval
        ]);
        
        // Step 1: Create report
        $createResponse = $this->createStockReport($language);
        $reportCode = $createResponse['result']['code'];
        
        // Step 2: Wait for completion
        $statusResponse = $this->waitForReportCompletion($reportCode, $maxAttempts, $pollInterval);
        
        // Step 3: Download and parse
        $fileUrl = $statusResponse['result']['file'];
        $csvData = $this->downloadAndParseCsv($fileUrl);
        
        $this->logger->info('Complete stock report process finished', [
            'report_code' => $reportCode,
            'rows_count' => count($csvData)
        ]);
        
        return $csvData;
    }

    /**
     * Test API connectivity
     * 
     * @return bool True if API is accessible
     */
    public function testConnection(): bool
    {
        try {
            // Use a simple endpoint to test connectivity
            $this->makeRequest('POST', '/v2/product/list', [
                'filter' => [],
                'limit' => 1
            ]);
            
            $this->logger->info('API connection test successful');
            return true;
            
        } catch (Exception $e) {
            $this->logger->error('API connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}