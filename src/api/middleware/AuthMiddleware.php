<?php
/**
 * Authentication Middleware for mi_core_etl API
 * Handles API key authentication and basic auth
 */

require_once __DIR__ . '/BaseMiddleware.php';

class AuthMiddleware extends BaseMiddleware {
    private $validApiKeys = [];
    private $validUsers = [];
    
    public function __construct() {
        parent::__construct();
        $this->loadAuthConfig();
    }
    
    /**
     * Load authentication configuration
     */
    private function loadAuthConfig() {
        // Load from environment variables
        $apiKeys = $_ENV['API_KEYS'] ?? '';
        if ($apiKeys) {
            $this->validApiKeys = explode(',', $apiKeys);
        }
        
        // Default API key for development
        if (empty($this->validApiKeys)) {
            $this->validApiKeys = ['dev-api-key-' . md5('mi_core_etl')];
        }
        
        // Load basic auth users
        $authUsers = $_ENV['AUTH_USERS'] ?? '';
        if ($authUsers) {
            $users = explode(',', $authUsers);
            foreach ($users as $user) {
                if (strpos($user, ':') !== false) {
                    list($username, $password) = explode(':', $user, 2);
                    $this->validUsers[$username] = $password;
                }
            }
        }
        
        // Default user for development
        if (empty($this->validUsers)) {
            $this->validUsers['admin'] = 'admin123';
        }
    }
    
    /**
     * Handle authentication
     */
    public function handle($request, $next) {
        $headers = $this->getHeaders();
        $method = $this->getMethod();
        $clientIp = $this->getClientIp();
        
        // Skip authentication for health check endpoints
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($requestUri, '/health') !== false || strpos($requestUri, '/status') !== false) {
            return $next($request);
        }
        
        // Try API key authentication first
        if ($this->authenticateApiKey($headers)) {
            $this->logger->info('API key authentication successful', [
                'method' => $method,
                'uri' => $requestUri,
                'client_ip' => $clientIp
            ]);
            return $next($request);
        }
        
        // Try basic authentication
        if ($this->authenticateBasicAuth($headers)) {
            $this->logger->info('Basic authentication successful', [
                'method' => $method,
                'uri' => $requestUri,
                'client_ip' => $clientIp
            ]);
            return $next($request);
        }
        
        // Authentication failed
        $this->logger->warning('Authentication failed', [
            'method' => $method,
            'uri' => $requestUri,
            'client_ip' => $clientIp,
            'user_agent' => $this->getUserAgent()
        ]);
        
        $this->errorResponse('Authentication required', 401, [
            'supported_methods' => ['API Key (X-API-Key header)', 'Basic Auth']
        ]);
    }
    
    /**
     * Authenticate using API key
     */
    private function authenticateApiKey($headers) {
        $apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? null;
        
        if (!$apiKey) {
            return false;
        }
        
        return in_array($apiKey, $this->validApiKeys);
    }
    
    /**
     * Authenticate using basic auth
     */
    private function authenticateBasicAuth($headers) {
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        
        if (!$authHeader || strpos($authHeader, 'Basic ') !== 0) {
            return false;
        }
        
        $credentials = base64_decode(substr($authHeader, 6));
        if (!$credentials || strpos($credentials, ':') === false) {
            return false;
        }
        
        list($username, $password) = explode(':', $credentials, 2);
        
        return isset($this->validUsers[$username]) && $this->validUsers[$username] === $password;
    }
    
    /**
     * Get current authenticated user info
     */
    public function getCurrentUser($headers = null) {
        if ($headers === null) {
            $headers = $this->getHeaders();
        }
        
        // Check API key
        $apiKey = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? null;
        if ($apiKey && in_array($apiKey, $this->validApiKeys)) {
            return [
                'type' => 'api_key',
                'identifier' => substr($apiKey, 0, 8) . '...',
                'permissions' => ['read', 'write']
            ];
        }
        
        // Check basic auth
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        if ($authHeader && strpos($authHeader, 'Basic ') === 0) {
            $credentials = base64_decode(substr($authHeader, 6));
            if ($credentials && strpos($credentials, ':') !== false) {
                list($username, $password) = explode(':', $credentials, 2);
                if (isset($this->validUsers[$username]) && $this->validUsers[$username] === $password) {
                    return [
                        'type' => 'basic_auth',
                        'identifier' => $username,
                        'permissions' => ['read', 'write']
                    ];
                }
            }
        }
        
        return null;
    }
}