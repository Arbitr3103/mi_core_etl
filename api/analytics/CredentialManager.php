<?php
/**
 * Credential Manager for Regional Analytics API
 * 
 * Handles secure storage, encryption, and rotation of API credentials
 * for marketplace integrations (Ozon, Wildberries, etc.)
 */

require_once __DIR__ . '/config.php';

class CredentialManager {
    private $pdo;
    private $encryptionKey;
    private $cipher = 'AES-256-CBC';
    
    public function __construct() {
        $this->pdo = getAnalyticsDbConnection();
        $this->encryptionKey = $this->getEncryptionKey();
        $this->initializeCredentialTables();
    }
    
    /**
     * Get or generate encryption key
     * @return string Encryption key
     */
    private function getEncryptionKey() {
        // Try to get from environment first
        $key = getenv('ENCRYPTION_KEY');
        
        if (!$key) {
            // Generate and store new key if not exists
            $keyFile = __DIR__ . '/../../.credentials_key';
            
            if (file_exists($keyFile)) {
                $key = file_get_contents($keyFile);
            } else {
                // Generate new 256-bit key
                $key = base64_encode(random_bytes(32));
                
                // Store securely with restricted permissions
                file_put_contents($keyFile, $key);
                chmod($keyFile, 0600); // Read/write for owner only
                
                logAnalyticsActivity('INFO', 'Generated new encryption key for credentials');
            }
        }
        
        return $key;
    }
    
    /**
     * Initialize credential storage tables
     */
    private function initializeCredentialTables() {
        $sql = "
            CREATE TABLE IF NOT EXISTS analytics_credentials (
                id INT PRIMARY KEY AUTO_INCREMENT,
                service_name VARCHAR(50) NOT NULL,
                credential_type VARCHAR(50) NOT NULL,
                encrypted_value TEXT NOT NULL,
                iv VARCHAR(32) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                is_active BOOLEAN DEFAULT TRUE,
                rotation_count INT DEFAULT 0,
                last_used_at TIMESTAMP NULL,
                UNIQUE KEY unique_service_credential (service_name, credential_type),
                INDEX idx_service (service_name),
                INDEX idx_active (is_active),
                INDEX idx_expires (expires_at)
            )
        ";
        
        try {
            $this->pdo->exec($sql);
            
            // Create credential rotation log table
            $rotationSql = "
                CREATE TABLE IF NOT EXISTS analytics_credential_rotations (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    credential_id INT NOT NULL,
                    old_value_hash VARCHAR(64),
                    new_value_hash VARCHAR(64),
                    rotation_reason VARCHAR(255),
                    rotated_by VARCHAR(100),
                    rotated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_credential (credential_id),
                    INDEX idx_rotated_at (rotated_at),
                    FOREIGN KEY (credential_id) REFERENCES analytics_credentials(id) ON DELETE CASCADE
                )
            ";
            
            $this->pdo->exec($rotationSql);
            
            logAnalyticsActivity('INFO', 'Credential storage tables initialized');
            
        } catch (PDOException $e) {
            logAnalyticsActivity('ERROR', 'Failed to initialize credential tables: ' . $e->getMessage());
            throw new Exception('Failed to initialize credential storage system');
        }
    }
    
    /**
     * Store encrypted credential
     * @param string $serviceName Service name (e.g., 'ozon', 'wildberries')
     * @param string $credentialType Type of credential (e.g., 'api_key', 'client_id')
     * @param string $value Credential value to encrypt and store
     * @param int|null $expiryDays Days until expiry (null for no expiry)
     * @return bool Success status
     */
    public function storeCredential($serviceName, $credentialType, $value, $expiryDays = null) {
        try {
            // Generate random IV for encryption
            $iv = random_bytes(16);
            $ivHex = bin2hex($iv);
            
            // Encrypt the credential value
            $encryptedValue = openssl_encrypt($value, $this->cipher, base64_decode($this->encryptionKey), 0, $iv);
            
            if ($encryptedValue === false) {
                throw new Exception('Failed to encrypt credential value');
            }
            
            // Calculate expiry date
            $expiresAt = null;
            if ($expiryDays !== null) {
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryDays} days"));
            }
            
            // Store in database
            $sql = "
                INSERT INTO analytics_credentials 
                (service_name, credential_type, encrypted_value, iv, expires_at) 
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    encrypted_value = VALUES(encrypted_value),
                    iv = VALUES(iv),
                    expires_at = VALUES(expires_at),
                    updated_at = CURRENT_TIMESTAMP,
                    rotation_count = rotation_count + 1
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([$serviceName, $credentialType, $encryptedValue, $ivHex, $expiresAt]);
            
            if ($result) {
                logAnalyticsActivity('INFO', 'Credential stored successfully', [
                    'service' => $serviceName,
                    'type' => $credentialType,
                    'expires_at' => $expiresAt
                ]);
                
                // Log rotation if this was an update
                if ($stmt->rowCount() > 0) {
                    $this->logCredentialRotation($serviceName, $credentialType, 'manual_update');
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Failed to store credential: ' . $e->getMessage(), [
                'service' => $serviceName,
                'type' => $credentialType
            ]);
            return false;
        }
    }
    
    /**
     * Retrieve and decrypt credential
     * @param string $serviceName Service name
     * @param string $credentialType Credential type
     * @return string|null Decrypted credential value or null if not found
     */
    public function getCredential($serviceName, $credentialType) {
        try {
            $sql = "
                SELECT encrypted_value, iv, expires_at, id
                FROM analytics_credentials 
                WHERE service_name = ? AND credential_type = ? AND is_active = TRUE
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$serviceName, $credentialType]);
            $credential = $stmt->fetch();
            
            if (!$credential) {
                return null;
            }
            
            // Check if credential has expired
            if ($credential['expires_at'] && strtotime($credential['expires_at']) < time()) {
                logAnalyticsActivity('WARNING', 'Expired credential accessed', [
                    'service' => $serviceName,
                    'type' => $credentialType,
                    'expired_at' => $credential['expires_at']
                ]);
                return null;
            }
            
            // Decrypt the value
            $iv = hex2bin($credential['iv']);
            $decryptedValue = openssl_decrypt(
                $credential['encrypted_value'], 
                $this->cipher, 
                base64_decode($this->encryptionKey), 
                0, 
                $iv
            );
            
            if ($decryptedValue === false) {
                logAnalyticsActivity('ERROR', 'Failed to decrypt credential', [
                    'service' => $serviceName,
                    'type' => $credentialType
                ]);
                return null;
            }
            
            // Update last used timestamp
            $this->updateLastUsed($credential['id']);
            
            return $decryptedValue;
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error retrieving credential: ' . $e->getMessage(), [
                'service' => $serviceName,
                'type' => $credentialType
            ]);
            return null;
        }
    }
    
    /**
     * Rotate credential (generate new value and store)
     * @param string $serviceName Service name
     * @param string $credentialType Credential type
     * @param string $newValue New credential value
     * @param string $reason Rotation reason
     * @param string $rotatedBy Who performed the rotation
     * @return bool Success status
     */
    public function rotateCredential($serviceName, $credentialType, $newValue, $reason = 'scheduled_rotation', $rotatedBy = 'system') {
        try {
            // Get current credential for logging
            $currentCredential = $this->getCredentialInfo($serviceName, $credentialType);
            
            // Store new credential
            $result = $this->storeCredential($serviceName, $credentialType, $newValue);
            
            if ($result) {
                // Log the rotation
                $this->logCredentialRotation($serviceName, $credentialType, $reason, $rotatedBy);
                
                logAnalyticsActivity('INFO', 'Credential rotated successfully', [
                    'service' => $serviceName,
                    'type' => $credentialType,
                    'reason' => $reason,
                    'rotated_by' => $rotatedBy
                ]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Failed to rotate credential: ' . $e->getMessage(), [
                'service' => $serviceName,
                'type' => $credentialType,
                'reason' => $reason
            ]);
            return false;
        }
    }
    
    /**
     * Get credential information without decrypting the value
     * @param string $serviceName Service name
     * @param string $credentialType Credential type
     * @return array|null Credential info or null if not found
     */
    public function getCredentialInfo($serviceName, $credentialType) {
        try {
            $sql = "
                SELECT id, service_name, credential_type, created_at, updated_at, 
                       expires_at, is_active, rotation_count, last_used_at
                FROM analytics_credentials 
                WHERE service_name = ? AND credential_type = ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$serviceName, $credentialType]);
            
            return $stmt->fetch();
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error getting credential info: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * List all credentials for a service
     * @param string $serviceName Service name
     * @return array List of credentials (without decrypted values)
     */
    public function listServiceCredentials($serviceName) {
        try {
            $sql = "
                SELECT service_name, credential_type, created_at, updated_at, 
                       expires_at, is_active, rotation_count, last_used_at
                FROM analytics_credentials 
                WHERE service_name = ?
                ORDER BY credential_type
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$serviceName]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error listing service credentials: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check for expiring credentials
     * @param int $warningDays Days before expiry to warn
     * @return array List of expiring credentials
     */
    public function getExpiringCredentials($warningDays = 7) {
        try {
            $warningDate = date('Y-m-d H:i:s', strtotime("+{$warningDays} days"));
            
            $sql = "
                SELECT service_name, credential_type, expires_at, 
                       DATEDIFF(expires_at, NOW()) as days_until_expiry
                FROM analytics_credentials 
                WHERE expires_at IS NOT NULL 
                  AND expires_at <= ? 
                  AND is_active = TRUE
                ORDER BY expires_at ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$warningDate]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error checking expiring credentials: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Deactivate credential
     * @param string $serviceName Service name
     * @param string $credentialType Credential type
     * @return bool Success status
     */
    public function deactivateCredential($serviceName, $credentialType) {
        try {
            $sql = "
                UPDATE analytics_credentials 
                SET is_active = FALSE, updated_at = CURRENT_TIMESTAMP
                WHERE service_name = ? AND credential_type = ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([$serviceName, $credentialType]);
            
            if ($result && $stmt->rowCount() > 0) {
                logAnalyticsActivity('INFO', 'Credential deactivated', [
                    'service' => $serviceName,
                    'type' => $credentialType
                ]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Failed to deactivate credential: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update last used timestamp
     * @param int $credentialId Credential ID
     */
    private function updateLastUsed($credentialId) {
        try {
            $sql = "UPDATE analytics_credentials SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$credentialId]);
        } catch (Exception $e) {
            // Non-critical error, just log it
            logAnalyticsActivity('WARNING', 'Failed to update last used timestamp: ' . $e->getMessage());
        }
    }
    
    /**
     * Log credential rotation
     * @param string $serviceName Service name
     * @param string $credentialType Credential type
     * @param string $reason Rotation reason
     * @param string $rotatedBy Who performed the rotation
     */
    private function logCredentialRotation($serviceName, $credentialType, $reason, $rotatedBy = 'system') {
        try {
            // Get credential ID
            $credentialInfo = $this->getCredentialInfo($serviceName, $credentialType);
            if (!$credentialInfo) {
                return;
            }
            
            $sql = "
                INSERT INTO analytics_credential_rotations 
                (credential_id, rotation_reason, rotated_by) 
                VALUES (?, ?, ?)
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$credentialInfo['id'], $reason, $rotatedBy]);
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Failed to log credential rotation: ' . $e->getMessage());
        }
    }
    
    /**
     * Get rotation history for a credential
     * @param string $serviceName Service name
     * @param string $credentialType Credential type
     * @param int $limit Number of records to return
     * @return array Rotation history
     */
    public function getRotationHistory($serviceName, $credentialType, $limit = 10) {
        try {
            $sql = "
                SELECT r.rotation_reason, r.rotated_by, r.rotated_at
                FROM analytics_credential_rotations r
                JOIN analytics_credentials c ON r.credential_id = c.id
                WHERE c.service_name = ? AND c.credential_type = ?
                ORDER BY r.rotated_at DESC
                LIMIT ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$serviceName, $credentialType, $limit]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error getting rotation history: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Cleanup expired credentials
     * @return int Number of cleaned up credentials
     */
    public function cleanupExpiredCredentials() {
        try {
            $sql = "
                UPDATE analytics_credentials 
                SET is_active = FALSE 
                WHERE expires_at < CURRENT_TIMESTAMP AND is_active = TRUE
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $cleanedCount = $stmt->rowCount();
            
            if ($cleanedCount > 0) {
                logAnalyticsActivity('INFO', 'Cleaned up expired credentials', [
                    'count' => $cleanedCount
                ]);
            }
            
            return $cleanedCount;
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Failed to cleanup expired credentials: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Migrate existing environment credentials to encrypted storage
     * @return array Migration results
     */
    public function migrateEnvironmentCredentials() {
        $results = [
            'migrated' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        // Define credentials to migrate from environment
        $envCredentials = [
            'ozon' => [
                'client_id' => getenv('OZON_CLIENT_ID'),
                'api_key' => getenv('OZON_API_KEY')
            ],
            'wildberries' => [
                'api_key' => getenv('WB_API_KEY')
            ]
        ];
        
        foreach ($envCredentials as $service => $credentials) {
            foreach ($credentials as $type => $value) {
                if (!empty($value)) {
                    // Check if already exists
                    $existing = $this->getCredentialInfo($service, $type);
                    
                    if (!$existing) {
                        if ($this->storeCredential($service, $type, $value, 90)) { // 90 days expiry
                            $results['migrated']++;
                            logAnalyticsActivity('INFO', 'Migrated environment credential', [
                                'service' => $service,
                                'type' => $type
                            ]);
                        } else {
                            $results['errors'][] = "Failed to migrate {$service}.{$type}";
                        }
                    } else {
                        $results['skipped']++;
                    }
                }
            }
        }
        
        return $results;
    }
}

/**
 * Get secure Ozon credentials
 * @return array Ozon credentials (client_id, api_key)
 */
function getSecureOzonCredentials() {
    static $credentialManager = null;
    
    if ($credentialManager === null) {
        $credentialManager = new CredentialManager();
    }
    
    return [
        'client_id' => $credentialManager->getCredential('ozon', 'client_id'),
        'api_key' => $credentialManager->getCredential('ozon', 'api_key')
    ];
}

/**
 * Get secure Wildberries credentials
 * @return array Wildberries credentials
 */
function getSecureWildberriesCredentials() {
    static $credentialManager = null;
    
    if ($credentialManager === null) {
        $credentialManager = new CredentialManager();
    }
    
    return [
        'api_key' => $credentialManager->getCredential('wildberries', 'api_key')
    ];
}

?>