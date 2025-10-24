<?php
/**
 * Rate Limit Middleware
 * 
 * Implements rate limiting for API endpoints to prevent abuse
 * 
 * @version 1.0
 * @author Manhattan System
 */

class RateLimitMiddleware {
    
    private $logger;
    private $rateLimits;
    private $storage;
    
    public function __construct() {
        $this->logger = Logger::getInstance();
        $this->initializeRateLimits();
        $this->initializeStorage();
    }
    
    /**
     * Check rate limit for current request
     * 
     * @param string $identifier - Client identifier (IP, API key, etc.)
     * @param string $endpoint - Endpoint being accessed
     * @return bool True if within rate limit
     * @throws Exception If rate limit exceeded
     */
    public function checkRateLimit(string $identifier, string $endpoint = 'default'): bool {
        try {
            $limit = $this->getRateLimitForEndpoint($endpoint);
            $key = $this->generateRateLimitKey($identifier, $endpoint);
            
            // Get current usage
            $currentUsage = $this->getCurrentUsage($key);
            
            // Check if limit exceeded
            if ($currentUsage >= $limit['requests']) {
                $this->logRateLimitExceeded($identifier, $endpoint, $currentUsage, $limit);
                throw new Exception('Rate limit exceeded', 429);
            }
            
            // Increment usage
            $this->incrementUsage($key, $limit['window']);
            
            // Log successful request
            $this->logRateLimitCheck($identifier, $endpoint, $currentUsage + 1, $limit);
            
            return true;
            
        } catch (Exception $e) {
            if ($e->getCode() === 429) {
                throw $e;
            }
            
            $this->logger->error('Rate limit check error', [
                'identifier' => $identifier,
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            
            // Fail open - allow request if rate limiting fails
            return true;
        }
    }
    
    /**
     * Get rate limit headers for response
     * 
     * @param string $identifier - Client identifier
     * @param string $endpoint - Endpoint being accessed
     * @return array Rate limit headers
     */
    public function getRateLimitHeaders(string $identifier, string $endpoint = 'default'): array {
        try {
            $limit = $this->getRateLimitForEndpoint($endpoint);
            $key = $this->generateRateLimitKey($identifier, $endpoint);
            $currentUsage = $this->getCurrentUsage($key);
            $remaining = max(0, $limit['requests'] - $currentUsage);
            $resetTime = $this->getResetTime($key, $limit['window']);
            
            return [
                'X-RateLimit-Limit' => $limit['requests'],
                'X-RateLimit-Remaining' => $remaining,
                'X-RateLimit-Reset' => $resetTime,
                'X-RateLimit-Window' => $limit['window']
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to get rate limit headers', [
                'identifier' => $identifier,
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            
            return [];
        }
    }
    
    /**
     * Initialize rate limits configuration
     */
    private function initializeRateLimits(): void {
        $this->rateLimits = [
            'default' => [
                'requests' => 100,
                'window' => 3600 // 1 hour
            ],
            'warehouse-stock' => [
                'requests' => 1000,
                'window' => 3600 // 1 hour
            ],
            'warehouse-stock-specific' => [
                'requests' => 500,
                'window' => 3600 // 1 hour
            ],
            'stock-reports' => [
                'requests' => 200,
                'window' => 3600 // 1 hour
            ],
            'stock-reports-details' => [
                'requests' => 100,
                'window' => 3600 // 1 hour
            ]
        ];
        
        // Load custom rate limits from environment or configuration
        $this->loadCustomRateLimits();
    }
    
    /**
     * Load custom rate limits from configuration
     */
    private function loadCustomRateLimits(): void {
        // Check for environment variable overrides
        $envLimits = [
            'RATE_LIMIT_DEFAULT' => 'default',
            'RATE_LIMIT_WAREHOUSE_STOCK' => 'warehouse-stock',
            'RATE_LIMIT_STOCK_REPORTS' => 'stock-reports'
        ];
        
        foreach ($envLimits as $envVar => $endpoint) {
            $envValue = $_ENV[$envVar] ?? null;
            if ($envValue && preg_match('/^(\d+):(\d+)$/', $envValue, $matches)) {
                $this->rateLimits[$endpoint] = [
                    'requests' => (int) $matches[1],
                    'window' => (int) $matches[2]
                ];
            }
        }
    }
    
    /**
     * Initialize storage for rate limit data
     */
    private function initializeStorage(): void {
        // Use file-based storage for simplicity
        // In production, consider using Redis or Memcached
        $this->storage = [
            'type' => 'file',
            'path' => sys_get_temp_dir() . '/rate_limits/'
        ];
        
        // Create storage directory if it doesn't exist
        if (!is_dir($this->storage['path'])) {
            mkdir($this->storage['path'], 0755, true);
        }
    }
    
    /**
     * Get rate limit configuration for endpoint
     * 
     * @param string $endpoint - Endpoint name
     * @return array Rate limit configuration
     */
    private function getRateLimitForEndpoint(string $endpoint): array {
        return $this->rateLimits[$endpoint] ?? $this->rateLimits['default'];
    }
    
    /**
     * Generate rate limit key for storage
     * 
     * @param string $identifier - Client identifier
     * @param string $endpoint - Endpoint name
     * @return string Storage key
     */
    private function generateRateLimitKey(string $identifier, string $endpoint): string {
        $limit = $this->getRateLimitForEndpoint($endpoint);
        $window = floor(time() / $limit['window']);
        
        return 'rate_limit:' . hash('sha256', $identifier . ':' . $endpoint . ':' . $window);
    }
    
    /**
     * Get current usage for a key
     * 
     * @param string $key - Storage key
     * @return int Current usage count
     */
    private function getCurrentUsage(string $key): int {
        $filePath = $this->storage['path'] . $key . '.txt';
        
        if (!file_exists($filePath)) {
            return 0;
        }
        
        $content = file_get_contents($filePath);
        return (int) $content;
    }
    
    /**
     * Increment usage for a key
     * 
     * @param string $key - Storage key
     * @param int $window - Time window in seconds
     */
    private function incrementUsage(string $key, int $window): void {
        $filePath = $this->storage['path'] . $key . '.txt';
        $currentUsage = $this->getCurrentUsage($key);
        
        file_put_contents($filePath, $currentUsage + 1);
        
        // Set file to expire after the window
        touch($filePath, time() + $window);
    }
    
    /**
     * Get reset time for rate limit window
     * 
     * @param string $key - Storage key
     * @param int $window - Time window in seconds
     * @return int Unix timestamp when rate limit resets
     */
    private function getResetTime(string $key, int $window): int {
        $currentWindow = floor(time() / $window);
        return ($currentWindow + 1) * $window;
    }
    
    /**
     * Clean up expired rate limit files
     */
    public function cleanupExpiredLimits(): void {
        try {
            $files = glob($this->storage['path'] . '*.txt');
            $now = time();
            $cleaned = 0;
            
            foreach ($files as $file) {
                if (filemtime($file) < $now) {
                    unlink($file);
                    $cleaned++;
                }
            }
            
            if ($cleaned > 0) {
                $this->logger->info('Cleaned up expired rate limit files', [
                    'files_cleaned' => $cleaned
                ]);
            }
            
        } catch (Exception $e) {
            $this->logger->error('Failed to cleanup expired rate limits', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get client identifier from request
     * 
     * @return string Client identifier
     */
    public function getClientIdentifier(): string {
        // Try to get API key first (more specific)
        $apiKey = $this->getApiKeyFromRequest();
        if ($apiKey) {
            return 'api_key:' . hash('sha256', $apiKey);
        }
        
        // Fall back to IP address
        $ipAddress = $this->getClientIpAddress();
        return 'ip:' . $ipAddress;
    }
    
    /**
     * Get API key from request
     * 
     * @return string|null API key or null if not found
     */
    private function getApiKeyFromRequest(): ?string {
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return null;
    }
    
    /**
     * Get client IP address
     * 
     * @return string Client IP address
     */
    private function getClientIpAddress(): string {
        // Check for IP from various headers (for load balancers, proxies)
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (isset($_SERVER[$header]) && !empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    /**
     * Log rate limit check
     * 
     * @param string $identifier - Client identifier
     * @param string $endpoint - Endpoint name
     * @param int $currentUsage - Current usage count
     * @param array $limit - Rate limit configuration
     */
    private function logRateLimitCheck(string $identifier, string $endpoint, int $currentUsage, array $limit): void {
        $this->logger->debug('Rate limit check', [
            'identifier_hash' => hash('sha256', $identifier),
            'endpoint' => $endpoint,
            'current_usage' => $currentUsage,
            'limit' => $limit['requests'],
            'window' => $limit['window'],
            'remaining' => max(0, $limit['requests'] - $currentUsage)
        ]);
    }
    
    /**
     * Log rate limit exceeded
     * 
     * @param string $identifier - Client identifier
     * @param string $endpoint - Endpoint name
     * @param int $currentUsage - Current usage count
     * @param array $limit - Rate limit configuration
     */
    private function logRateLimitExceeded(string $identifier, string $endpoint, int $currentUsage, array $limit): void {
        $this->logger->warning('Rate limit exceeded', [
            'identifier_hash' => hash('sha256', $identifier),
            'endpoint' => $endpoint,
            'current_usage' => $currentUsage,
            'limit' => $limit['requests'],
            'window' => $limit['window'],
            'ip_address' => $this->getClientIpAddress(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    }
    
    /**
     * Set custom rate limit for specific client
     * 
     * @param string $identifier - Client identifier
     * @param string $endpoint - Endpoint name
     * @param int $requests - Number of requests allowed
     * @param int $window - Time window in seconds
     */
    public function setCustomRateLimit(string $identifier, string $endpoint, int $requests, int $window): void {
        // This would typically be stored in database for persistence
        // For demo purposes, we'll just log it
        
        $this->logger->info('Custom rate limit set', [
            'identifier_hash' => hash('sha256', $identifier),
            'endpoint' => $endpoint,
            'requests' => $requests,
            'window' => $window
        ]);
    }
}