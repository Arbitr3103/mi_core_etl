<?php
/**
 * Authentication Manager for Regional Analytics API
 * 
 * Handles API key generation, validation, and rate limiting
 * for the regional sales analytics system.
 */

require_once __DIR__ . '/config.php';

class AuthenticationManager {
    private $pdo;
    private $rateLimitCache = [];
    
    public function __construct() {
        $this->pdo = getAnalyticsDbConnection();
        $this->initializeApiKeyTable();
    }
    
    /**
     * Initialize API keys table if it doesn't exist
     */
    private function initializeApiKeyTable() {
        $sql = "
            CREATE TABLE IF NOT EXISTS analytics_api_keys (
                id INT PRIMARY KEY AUTO_INCREMENT,
                api_key VARCHAR(64) UNIQUE NOT NULL,
                name VARCHAR(255) NOT NULL,
                client_id INT DEFAULT 1,
                is_active BOOLEAN DEFAULT TRUE,
                rate_limit_per_hour INT DEFAULT 100,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                last_used_at TIMESTAMP NULL,
                usage_count INT DEFAULT 0,
                INDEX idx_api_key (api_key),
                INDEX idx_active (is_active),
                INDEX idx_expires (expires_at)
            )
        ";
        
        try {
            $this->pdo->exec($sql);
            logAnalyticsActivity('INFO', 'API keys table initialized');
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'Failed to initialize API keys table: ' . $e->getMessage());
            throw new Exception('Failed to initialize authentication system');
        }
    }
    
    /**
     * Generate a new API key
     * @param string $name API key name/description
     * @param int $clientId Client ID (default: 1 for TD Manhattan)
     * @param int $rateLimitPerHour Rate limit per hour (default: 100)
     * @param int $expiryDays Days until expiry (default: 90)
     * @return array API key data
     */
    public function generateApiKey($name, $clientId = 1, $rateLimitPerHour = 100, $expiryDays = 90) {
        // Generate secure API key
        $apiKey = 'ra_' . bin2hex(random_bytes(ANALYTICS_API_KEY_LENGTH));
        
        // Calculate expiry date
        $expiresAt = null;
        if ($expiryDays > 0) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
        }
        
        $sql = "
            INSERT INTO analytics_api_keys 
            (api_key, name, client_id, rate_limit_per_hour, expires_at) 
            VALUES (?, ?, ?, ?, ?)
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$apiKey, $name, $clientId, $rateLimitPerHour, $expiresAt]);
            
            logAnalyticsActivity('INFO', 'New API key generated', [
                'name' => $name,
                'client_id' => $clientId,
                'expires_at' => $expiresAt
            ]);
            
            return [
                'api_key' => $apiKey,
                'name' => $name,
                'client_id' => $clientId,
                'rate_limit_per_hour' => $rateLimitPerHour,
                'expires_at' => $expiresAt,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'Failed to generate API key: ' . $e->getMessage());
            throw new Exception('Failed to generate API key');
        }
    }
    
    /**
     * Validate API key and check permissions
     * @param string $apiKey API key to validate
     * @return array|false API key data or false if invalid
     */
    public function validateApiKey($apiKey) {
        if (empty($apiKey)) {
            return false;
        }
        
        $sql = "
            SELECT id, api_key, name, client_id, is_active, rate_limit_per_hour, 
                   expires_at, last_used_at, usage_count
            FROM analytics_api_keys 
            WHERE api_key = ? AND is_active = TRUE
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$apiKey]);
            $keyData = $stmt->fetch();
            
            if (!$keyData) {
                logAnalyticsActivity('WARNING', 'Invalid API key attempted', ['api_key' => substr($apiKey, 0, 10) . '...']);
                return false;
            }
            
            // Check if key has expired
            if ($keyData['expires_at'] && strtotime($keyData['expires_at']) < time()) {
                logAnalyticsActivity('WARNING', 'Expired API key attempted', ['api_key' => substr($apiKey, 0, 10) . '...']);
                return false;
            }
            
            // Update last used timestamp and usage count
            $this->updateApiKeyUsage($keyData['id']);
            
            return $keyData;
            
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'API key validation error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check rate limiting for API key
     * @param array $keyData API key data from validateApiKey
     * @return bool True if within rate limit, false if exceeded
     */
    public function checkRateLimit($keyData) {
        $keyId = $keyData['id'];
        $rateLimit = $keyData['rate_limit_per_hour'];
        $currentHour = date('Y-m-d H:00:00');
        
        // Get current hour usage from database
        $sql = "
            SELECT COUNT(*) as request_count 
            FROM analytics_api_requests 
            WHERE api_key_id = ? AND created_at >= ?
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$keyId, $currentHour]);
            $result = $stmt->fetch();
            $currentUsage = $result['request_count'] ?? 0;
            
            if ($currentUsage >= $rateLimit) {
                logAnalyticsActivity('WARNING', 'Rate limit exceeded', [
                    'api_key_id' => $keyId,
                    'current_usage' => $currentUsage,
                    'rate_limit' => $rateLimit
                ]);
                return false;
            }
            
            // Log this request
            $this->logApiRequest($keyId);
            
            return true;
            
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'Rate limit check error: ' . $e->getMessage());
            // Allow request on error to avoid blocking legitimate users
            return true;
        }
    }
    
    /**
     * Log API request for rate limiting
     * @param int $keyId API key ID
     */
    private function logApiRequest($keyId) {
        // Create requests table if it doesn't exist
        $createTableSql = "
            CREATE TABLE IF NOT EXISTS analytics_api_requests (
                id INT PRIMARY KEY AUTO_INCREMENT,
                api_key_id INT NOT NULL,
                endpoint VARCHAR(255),
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_api_key_time (api_key_id, created_at),
                FOREIGN KEY (api_key_id) REFERENCES analytics_api_keys(id) ON DELETE CASCADE
            )
        ";
        
        try {
            $this->pdo->exec($createTableSql);
        } catch (PDOException $e) {
            // Table might already exist, continue
        }
        
        // Log the request
        $sql = "
            INSERT INTO analytics_api_requests 
            (api_key_id, endpoint, ip_address, user_agent) 
            VALUES (?, ?, ?, ?)
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $keyId,
                $_SERVER['REQUEST_URI'] ?? '',
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'Failed to log API request: ' . $e->getMessage());
        }
    }
    
    /**
     * Update API key usage statistics
     * @param int $keyId API key ID
     */
    private function updateApiKeyUsage($keyId) {
        $sql = "
            UPDATE analytics_api_keys 
            SET last_used_at = CURRENT_TIMESTAMP, usage_count = usage_count + 1 
            WHERE id = ?
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$keyId]);
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'Failed to update API key usage: ' . $e->getMessage());
        }
    }
    
    /**
     * Get API key from request headers
     * @return string|null API key or null if not found
     */
    public function getApiKeyFromRequest() {
        // Check X-API-Key header
        $headers = getallheaders();
        if (isset($headers['X-API-Key'])) {
            return $headers['X-API-Key'];
        }
        
        // Check Authorization header (Bearer token)
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.+)/', $auth, $matches)) {
                return $matches[1];
            }
        }
        
        // Check query parameter (less secure, for development only)
        if (isset($_GET['api_key'])) {
            return $_GET['api_key'];
        }
        
        return null;
    }
    
    /**
     * Authenticate request and return API key data
     * @return array|false API key data or false if authentication failed
     */
    public function authenticateRequest() {
        $apiKey = $this->getApiKeyFromRequest();
        
        if (!$apiKey) {
            return false;
        }
        
        $keyData = $this->validateApiKey($apiKey);
        
        if (!$keyData) {
            return false;
        }
        
        // Check rate limiting
        if (!$this->checkRateLimit($keyData)) {
            return false;
        }
        
        return $keyData;
    }
    
    /**
     * Revoke API key
     * @param string $apiKey API key to revoke
     * @return bool Success status
     */
    public function revokeApiKey($apiKey) {
        $sql = "UPDATE analytics_api_keys SET is_active = FALSE WHERE api_key = ?";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([$apiKey]);
            
            if ($stmt->rowCount() > 0) {
                logAnalyticsActivity('INFO', 'API key revoked', ['api_key' => substr($apiKey, 0, 10) . '...']);
                return true;
            }
            
            return false;
            
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'Failed to revoke API key: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * List all API keys for a client
     * @param int $clientId Client ID
     * @return array List of API keys
     */
    public function listApiKeys($clientId = 1) {
        $sql = "
            SELECT id, name, api_key, is_active, rate_limit_per_hour, 
                   created_at, expires_at, last_used_at, usage_count
            FROM analytics_api_keys 
            WHERE client_id = ? 
            ORDER BY created_at DESC
        ";
        
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$clientId]);
            $keys = $stmt->fetchAll();
            
            // Mask API keys for security
            foreach ($keys as &$key) {
                $key['api_key'] = substr($key['api_key'], 0, 10) . '...' . substr($key['api_key'], -4);
            }
            
            return $keys;
            
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'Failed to list API keys: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clean up expired API keys and old request logs
     */
    public function cleanup() {
        try {
            // Remove expired API keys
            $sql = "DELETE FROM analytics_api_keys WHERE expires_at < CURRENT_TIMESTAMP";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $expiredKeys = $stmt->rowCount();
            
            // Remove old request logs (older than 30 days)
            $sql = "DELETE FROM analytics_api_requests WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $oldRequests = $stmt->rowCount();
            
            logAnalyticsActivity('INFO', 'Authentication cleanup completed', [
                'expired_keys_removed' => $expiredKeys,
                'old_requests_removed' => $oldRequests
            ]);
            
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'Authentication cleanup failed: ' . $e->getMessage());
        }
    }
}

/**
 * Middleware function to authenticate API requests
 * @return array|null API key data or null if authentication failed
 */
function requireAuthentication() {
    $auth = new AuthenticationManager();
    $keyData = $auth->authenticateRequest();
    
    if (!$keyData) {
        sendAnalyticsErrorResponse('Authentication required. Please provide a valid API key.', 401, 'AUTH_REQUIRED');
        return null;
    }
    
    return $keyData;
}

/**
 * Middleware function to check if rate limit is exceeded
 * @param array $keyData API key data
 * @return bool True if within limits
 */
function checkAuthenticationRateLimit($keyData) {
    $auth = new AuthenticationManager();
    
    if (!$auth->checkRateLimit($keyData)) {
        sendAnalyticsErrorResponse(
            'Rate limit exceeded. Maximum ' . $keyData['rate_limit_per_hour'] . ' requests per hour allowed.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
        return false;
    }
    
    return true;
}
?>