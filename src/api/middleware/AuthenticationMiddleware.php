<?php
/**
 * Authentication Middleware
 * 
 * Handles API key authentication for warehouse stock API endpoints
 * 
 * @version 1.0
 * @author Manhattan System
 */

class AuthenticationMiddleware {
    
    private $validApiKeys;
    private $logger;
    
    public function __construct() {
        $this->logger = Logger::getInstance();
        $this->loadValidApiKeys();
    }
    
    /**
     * Authenticate API request
     * 
     * @return bool True if authenticated, false otherwise
     * @throws Exception If authentication fails
     */
    public function authenticate(): bool {
        try {
            // Get API key from header
            $apiKey = $this->getApiKeyFromRequest();
            
            if (!$apiKey) {
                $this->logAuthenticationAttempt(null, false, 'Missing API key');
                throw new Exception('API key required', 401);
            }
            
            // Validate API key
            if (!$this->isValidApiKey($apiKey)) {
                $this->logAuthenticationAttempt($apiKey, false, 'Invalid API key');
                throw new Exception('Invalid API key', 401);
            }
            
            // Check if API key is active
            if (!$this->isApiKeyActive($apiKey)) {
                $this->logAuthenticationAttempt($apiKey, false, 'API key inactive');
                throw new Exception('API key inactive', 401);
            }
            
            $this->logAuthenticationAttempt($apiKey, true, 'Authentication successful');
            return true;
            
        } catch (Exception $e) {
            if ($e->getCode() === 401) {
                throw $e;
            }
            
            $this->logger->error('Authentication error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception('Authentication failed', 500);
        }
    }
    
    /**
     * Get API key from request headers
     * 
     * @return string|null API key or null if not found
     */
    private function getApiKeyFromRequest(): ?string {
        // Check X-API-Key header
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        
        // Check Authorization header (Bearer token)
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                return trim($matches[1]);
            }
        }
        
        // Check query parameter (less secure, for testing only)
        if (isset($_GET['api_key'])) {
            return trim($_GET['api_key']);
        }
        
        return null;
    }
    
    /**
     * Check if API key is valid
     * 
     * @param string $apiKey - API key to validate
     * @return bool True if valid
     */
    private function isValidApiKey(string $apiKey): bool {
        return isset($this->validApiKeys[$apiKey]);
    }
    
    /**
     * Check if API key is active
     * 
     * @param string $apiKey - API key to check
     * @return bool True if active
     */
    private function isApiKeyActive(string $apiKey): bool {
        if (!isset($this->validApiKeys[$apiKey])) {
            return false;
        }
        
        $keyInfo = $this->validApiKeys[$apiKey];
        
        // Check if key is enabled
        if (!$keyInfo['active']) {
            return false;
        }
        
        // Check expiration date
        if (isset($keyInfo['expires_at']) && $keyInfo['expires_at']) {
            $expiresAt = new DateTime($keyInfo['expires_at']);
            if ($expiresAt < new DateTime()) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Load valid API keys from configuration
     */
    private function loadValidApiKeys(): void {
        // In production, this should load from database or secure configuration
        // For now, using environment variables and hardcoded keys for demo
        
        $this->validApiKeys = [];
        
        // Load from environment variables
        $envApiKey = $_ENV['WAREHOUSE_API_KEY'] ?? null;
        if ($envApiKey) {
            $this->validApiKeys[$envApiKey] = [
                'name' => 'Environment API Key',
                'active' => true,
                'created_at' => '2025-10-23 00:00:00',
                'expires_at' => null,
                'permissions' => ['warehouse_stock:read', 'stock_reports:read']
            ];
        }
        
        // Demo API keys (remove in production)
        $this->validApiKeys['demo_warehouse_api_key_2025'] = [
            'name' => 'Demo Warehouse API Key',
            'active' => true,
            'created_at' => '2025-10-23 00:00:00',
            'expires_at' => '2025-12-31 23:59:59',
            'permissions' => ['warehouse_stock:read', 'stock_reports:read']
        ];
        
        $this->validApiKeys['test_api_key_manhattan_2025'] = [
            'name' => 'Test API Key',
            'active' => true,
            'created_at' => '2025-10-23 00:00:00',
            'expires_at' => null,
            'permissions' => ['warehouse_stock:read', 'stock_reports:read']
        ];
        
        // Load additional keys from database if available
        $this->loadApiKeysFromDatabase();
    }
    
    /**
     * Load API keys from database
     */
    private function loadApiKeysFromDatabase(): void {
        try {
            // This would connect to database and load API keys
            // Implementation depends on your database schema
            
            // Example implementation:
            /*
            $pdo = Database::getInstance()->getConnection();
            $sql = "SELECT api_key, name, active, expires_at, permissions FROM api_keys WHERE active = 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($keys as $key) {
                $this->validApiKeys[$key['api_key']] = [
                    'name' => $key['name'],
                    'active' => (bool) $key['active'],
                    'expires_at' => $key['expires_at'],
                    'permissions' => json_decode($key['permissions'], true) ?: []
                ];
            }
            */
            
        } catch (Exception $e) {
            $this->logger->warning('Failed to load API keys from database', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Get API key information
     * 
     * @param string $apiKey - API key
     * @return array|null Key information or null if not found
     */
    public function getApiKeyInfo(string $apiKey): ?array {
        return $this->validApiKeys[$apiKey] ?? null;
    }
    
    /**
     * Check if API key has specific permission
     * 
     * @param string $apiKey - API key
     * @param string $permission - Permission to check
     * @return bool True if has permission
     */
    public function hasPermission(string $apiKey, string $permission): bool {
        $keyInfo = $this->getApiKeyInfo($apiKey);
        
        if (!$keyInfo) {
            return false;
        }
        
        $permissions = $keyInfo['permissions'] ?? [];
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }
    
    /**
     * Log authentication attempt
     * 
     * @param string|null $apiKey - API key used
     * @param bool $success - Whether authentication was successful
     * @param string $message - Additional message
     */
    private function logAuthenticationAttempt(?string $apiKey, bool $success, string $message): void {
        $this->logger->info('API authentication attempt', [
            'api_key_hash' => $apiKey ? hash('sha256', $apiKey) : null,
            'success' => $success,
            'message' => $message,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ]);
    }
    
    /**
     * Generate new API key
     * 
     * @param string $name - Name for the API key
     * @param array $permissions - Permissions for the key
     * @param string|null $expiresAt - Expiration date
     * @return string Generated API key
     */
    public static function generateApiKey(string $name, array $permissions = [], ?string $expiresAt = null): string {
        // Generate secure random API key
        $prefix = 'wh_api_';
        $randomBytes = random_bytes(32);
        $apiKey = $prefix . bin2hex($randomBytes);
        
        // In production, save to database
        // For demo, just return the key
        
        return $apiKey;
    }
    
    /**
     * Revoke API key
     * 
     * @param string $apiKey - API key to revoke
     * @return bool True if revoked successfully
     */
    public function revokeApiKey(string $apiKey): bool {
        try {
            // In production, update database to mark key as inactive
            // For demo, remove from memory
            
            if (isset($this->validApiKeys[$apiKey])) {
                $this->validApiKeys[$apiKey]['active'] = false;
                
                $this->logger->info('API key revoked', [
                    'api_key_hash' => hash('sha256', $apiKey)
                ]);
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to revoke API key', [
                'api_key_hash' => hash('sha256', $apiKey),
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
}