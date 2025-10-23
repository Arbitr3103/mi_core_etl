<?php
/**
 * Rate Limiting Middleware for mi_core_etl API
 * Implements rate limiting to prevent API abuse
 */

require_once __DIR__ . '/BaseMiddleware.php';

class RateLimitMiddleware extends BaseMiddleware {
    private $cache;
    private $limits = [];
    private $defaultLimit = 100; // requests per window
    private $windowSize = 3600; // 1 hour in seconds
    
    public function __construct() {
        parent::__construct();
        require_once __DIR__ . '/../../services/CacheService.php';
        $this->cache = CacheService::getInstance();
        $this->loadRateLimitConfig();
    }
    
    /**
     * Load rate limiting configuration
     */
    private function loadRateLimitConfig() {
        // Load from environment variables
        $this->defaultLimit = (int)($_ENV['RATE_LIMIT_DEFAULT'] ?? 100);
        $this->windowSize = (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 3600);
        
        // Load endpoint-specific limits
        $endpointLimits = $_ENV['RATE_LIMIT_ENDPOINTS'] ?? '';
        if ($endpointLimits) {
            $limits = explode(',', $endpointLimits);
            foreach ($limits as $limit) {
                if (strpos($limit, ':') !== false) {
                    list($endpoint, $maxRequests) = explode(':', $limit, 2);
                    $this->limits[trim($endpoint)] = (int)$maxRequests;
                }
            }
        }
        
        // Default endpoint limits
        if (empty($this->limits)) {
            $this->limits = [
                '/api/inventory/dashboard' => 60,
                '/api/inventory/search' => 30,
                '/api/inventory/bulk-update' => 10,
                '/api/inventory/product' => 120
            ];
        }
    }
    
    /**
     * Handle rate limiting
     */
    public function handle($request, $next) {
        $clientId = $this->getClientIdentifier();
        $endpoint = $this->getEndpoint();
        $limit = $this->getEndpointLimit($endpoint);
        
        // Check rate limit
        $result = $this->checkRateLimit($clientId, $endpoint, $limit);
        
        if (!$result['allowed']) {
            $this->logger->warning('Rate limit exceeded', [
                'client_id' => $clientId,
                'endpoint' => $endpoint,
                'limit' => $limit,
                'current_requests' => $result['current_requests'],
                'reset_time' => $result['reset_time']
            ]);
            
            // Set rate limit headers
            header('X-RateLimit-Limit: ' . $limit);
            header('X-RateLimit-Remaining: 0');
            header('X-RateLimit-Reset: ' . $result['reset_time']);
            header('Retry-After: ' . ($result['reset_time'] - time()));
            
            $this->errorResponse('Rate limit exceeded', 429, [
                'limit' => $limit,
                'window_size' => $this->windowSize,
                'reset_time' => date('Y-m-d H:i:s', $result['reset_time']),
                'retry_after' => $result['reset_time'] - time()
            ]);
        }
        
        // Set rate limit headers for successful requests
        header('X-RateLimit-Limit: ' . $limit);
        header('X-RateLimit-Remaining: ' . ($limit - $result['current_requests']));
        header('X-RateLimit-Reset: ' . $result['reset_time']);
        
        $this->logger->debug('Rate limit check passed', [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'current_requests' => $result['current_requests'],
            'limit' => $limit
        ]);
        
        return $next($request);
    }
    
    /**
     * Get client identifier for rate limiting
     */
    private function getClientIdentifier() {
        // Try to get authenticated user first
        $headers = $this->getHeaders();
        
        // Check for API key
        $apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? null;
        if ($apiKey) {
            return 'api_key:' . md5($apiKey);
        }
        
        // Check for basic auth
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        if ($authHeader && strpos($authHeader, 'Basic ') === 0) {
            $credentials = base64_decode(substr($authHeader, 6));
            if ($credentials && strpos($credentials, ':') !== false) {
                list($username, $password) = explode(':', $credentials, 2);
                return 'user:' . $username;
            }
        }
        
        // Fall back to IP address
        return 'ip:' . $this->getClientIp();
    }
    
    /**
     * Get current endpoint
     */
    private function getEndpoint() {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        
        // Remove query parameters
        $endpoint = parse_url($requestUri, PHP_URL_PATH);
        
        // Normalize endpoint for rate limiting
        $endpoint = rtrim($endpoint, '/');
        
        return $endpoint;
    }
    
    /**
     * Get rate limit for specific endpoint
     */
    private function getEndpointLimit($endpoint) {
        // Check for exact match
        if (isset($this->limits[$endpoint])) {
            return $this->limits[$endpoint];
        }
        
        // Check for pattern matches
        foreach ($this->limits as $pattern => $limit) {
            if (strpos($pattern, '*') !== false) {
                $regex = str_replace('*', '.*', preg_quote($pattern, '/'));
                if (preg_match('/^' . $regex . '$/', $endpoint)) {
                    return $limit;
                }
            }
        }
        
        return $this->defaultLimit;
    }
    
    /**
     * Check rate limit for client and endpoint
     */
    private function checkRateLimit($clientId, $endpoint, $limit) {
        $windowStart = floor(time() / $this->windowSize) * $this->windowSize;
        $cacheKey = "rate_limit:{$clientId}:{$endpoint}:{$windowStart}";
        
        // Get current request count
        $currentRequests = (int)$this->cache->get($cacheKey, 0);
        
        // Increment request count
        $newRequestCount = $currentRequests + 1;
        $this->cache->set($cacheKey, $newRequestCount, $this->windowSize);
        
        return [
            'allowed' => $newRequestCount <= $limit,
            'current_requests' => $newRequestCount,
            'limit' => $limit,
            'reset_time' => $windowStart + $this->windowSize
        ];
    }
    
    /**
     * Get rate limit status for client
     */
    public function getRateLimitStatus($clientId = null, $endpoint = null) {
        if ($clientId === null) {
            $clientId = $this->getClientIdentifier();
        }
        
        if ($endpoint === null) {
            $endpoint = $this->getEndpoint();
        }
        
        $limit = $this->getEndpointLimit($endpoint);
        $windowStart = floor(time() / $this->windowSize) * $this->windowSize;
        $cacheKey = "rate_limit:{$clientId}:{$endpoint}:{$windowStart}";
        
        $currentRequests = (int)$this->cache->get($cacheKey, 0);
        
        return [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'limit' => $limit,
            'current_requests' => $currentRequests,
            'remaining' => max(0, $limit - $currentRequests),
            'reset_time' => $windowStart + $this->windowSize,
            'window_size' => $this->windowSize
        ];
    }
    
    /**
     * Reset rate limit for client
     */
    public function resetRateLimit($clientId, $endpoint = null) {
        $windowStart = floor(time() / $this->windowSize) * $this->windowSize;
        
        if ($endpoint) {
            $cacheKey = "rate_limit:{$clientId}:{$endpoint}:{$windowStart}";
            $this->cache->delete($cacheKey);
        } else {
            // Reset all endpoints for client (this is expensive, use carefully)
            $pattern = "rate_limit:{$clientId}:*";
            // Note: This would require cache implementation that supports pattern deletion
            $this->logger->info('Rate limit reset requested', [
                'client_id' => $clientId,
                'endpoint' => $endpoint
            ]);
        }
    }
    
    /**
     * Get rate limiting configuration
     */
    public function getConfig() {
        return [
            'default_limit' => $this->defaultLimit,
            'window_size' => $this->windowSize,
            'endpoint_limits' => $this->limits
        ];
    }
}