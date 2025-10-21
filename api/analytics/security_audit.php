<?php
/**
 * Security Audit Script for Credential Management System
 * 
 * Performs security checks and generates audit reports for the
 * credential management system.
 */

require_once __DIR__ . '/CredentialManager.php';

class CredentialSecurityAuditor {
    private $credentialManager;
    private $auditResults;
    
    public function __construct() {
        $this->credentialManager = new CredentialManager();
        $this->auditResults = [
            'timestamp' => date('Y-m-d H:i:s'),
            'checks' => [],
            'summary' => [
                'total_checks' => 0,
                'passed' => 0,
                'warnings' => 0,
                'failures' => 0,
                'critical_issues' => 0
            ]
        ];
    }
    
    /**
     * Run complete security audit
     * @return array Audit results
     */
    public function runSecurityAudit() {
        echo "🔒 Starting Credential Security Audit\n";
        echo str_repeat('=', 50) . "\n";
        
        // File system security checks
        $this->checkFilePermissions();
        $this->checkEncryptionKeyStorage();
        $this->checkLogFileSecurity();
        
        // Database security checks
        $this->checkDatabaseSecurity();
        $this->checkCredentialEncryption();
        
        // Configuration security checks
        $this->checkEnvironmentVariables();
        $this->checkRotationPolicies();
        
        // Operational security checks
        $this->checkCredentialAge();
        $this->checkUnusedCredentials();
        $this->checkFailedAccess();
        
        // Generate summary
        $this->generateAuditSummary();
        
        return $this->auditResults;
    }
    
    /**
     * Check file system permissions
     */
    private function checkFilePermissions() {
        $this->addCheck('File Permissions', function() {
            $issues = [];
            
            // Check credential key file
            $keyFile = __DIR__ . '/../../.credentials_key';
            if (file_exists($keyFile)) {
                $perms = fileperms($keyFile) & 0777;
                if ($perms !== 0600) {
                    $issues[] = "Credential key file has insecure permissions: " . decoct($perms);
                }
            }
            
            // Check credential manager file
            $credManagerFile = __DIR__ . '/CredentialManager.php';
            if (file_exists($credManagerFile)) {
                $perms = fileperms($credManagerFile) & 0777;
                if ($perms > 0644) {
                    $issues[] = "CredentialManager.php has overly permissive permissions: " . decoct($perms);
                }
            }
            
            // Check .env files
            $envFiles = ['.env', '.env.credentials', '.env.local'];
            foreach ($envFiles as $envFile) {
                $fullPath = __DIR__ . '/../../' . $envFile;
                if (file_exists($fullPath)) {
                    $perms = fileperms($fullPath) & 0777;
                    if ($perms > 0600) {
                        $issues[] = "$envFile has insecure permissions: " . decoct($perms);
                    }
                }
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'warning',
                'message' => empty($issues) ? 'File permissions are secure' : 'File permission issues found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Check encryption key storage
     */
    private function checkEncryptionKeyStorage() {
        $this->addCheck('Encryption Key Storage', function() {
            $issues = [];
            
            // Check if encryption key is in environment
            $envKey = getenv('ENCRYPTION_KEY');
            if ($envKey) {
                if (strlen($envKey) < 32) {
                    $issues[] = "Encryption key in environment is too short";
                }
            }
            
            // Check key file
            $keyFile = __DIR__ . '/../../.credentials_key';
            if (file_exists($keyFile)) {
                $keyContent = file_get_contents($keyFile);
                if (strlen($keyContent) < 32) {
                    $issues[] = "Encryption key file contains short key";
                }
                
                // Check if key file is in web-accessible directory
                $webRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
                if ($webRoot && strpos($keyFile, $webRoot) === 0) {
                    $issues[] = "Encryption key file is in web-accessible directory";
                }
            } else {
                $issues[] = "No encryption key file found";
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'critical',
                'message' => empty($issues) ? 'Encryption key storage is secure' : 'Encryption key storage issues found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Check log file security
     */
    private function checkLogFileSecurity() {
        $this->addCheck('Log File Security', function() {
            $issues = [];
            
            $logDir = __DIR__ . '/../../logs';
            if (is_dir($logDir)) {
                $logFiles = glob($logDir . '/*.log');
                
                foreach ($logFiles as $logFile) {
                    $perms = fileperms($logFile) & 0777;
                    if ($perms > 0644) {
                        $issues[] = basename($logFile) . " has overly permissive permissions: " . decoct($perms);
                    }
                    
                    // Check if log files contain sensitive data
                    if (preg_match('/credential|password|key|secret/i', basename($logFile))) {
                        $content = file_get_contents($logFile, false, null, 0, 1024); // Read first 1KB
                        if (preg_match('/[A-Za-z0-9+\/]{20,}/', $content)) {
                            $issues[] = basename($logFile) . " may contain sensitive data";
                        }
                    }
                }
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'warning',
                'message' => empty($issues) ? 'Log files are secure' : 'Log file security issues found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Check database security
     */
    private function checkDatabaseSecurity() {
        $this->addCheck('Database Security', function() {
            $issues = [];
            
            try {
                $pdo = getAnalyticsDbConnection();
                
                // Check if credential tables exist
                $stmt = $pdo->query("SHOW TABLES LIKE 'analytics_credentials'");
                if (!$stmt->fetch()) {
                    $issues[] = "Credential storage table does not exist";
                }
                
                // Check table permissions (if possible)
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM analytics_credentials");
                $result = $stmt->fetch();
                
                // Check for unencrypted data
                $stmt = $pdo->query("SELECT encrypted_value FROM analytics_credentials LIMIT 1");
                $cred = $stmt->fetch();
                if ($cred && strlen($cred['encrypted_value']) < 20) {
                    $issues[] = "Credentials may not be properly encrypted";
                }
                
            } catch (Exception $e) {
                $issues[] = "Database connection error: " . $e->getMessage();
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'critical',
                'message' => empty($issues) ? 'Database security is adequate' : 'Database security issues found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Check credential encryption
     */
    private function checkCredentialEncryption() {
        $this->addCheck('Credential Encryption', function() {
            $issues = [];
            
            try {
                // Test encryption/decryption
                $testValue = 'test_credential_' . time();
                $result = $this->credentialManager->storeCredential('test', 'audit_test', $testValue, 1);
                
                if ($result) {
                    $retrieved = $this->credentialManager->getCredential('test', 'audit_test');
                    
                    if ($retrieved !== $testValue) {
                        $issues[] = "Encryption/decryption test failed";
                    }
                    
                    // Clean up test credential
                    $this->credentialManager->deactivateCredential('test', 'audit_test');
                } else {
                    $issues[] = "Failed to store test credential";
                }
                
            } catch (Exception $e) {
                $issues[] = "Encryption test error: " . $e->getMessage();
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'critical',
                'message' => empty($issues) ? 'Credential encryption is working' : 'Credential encryption issues found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Check environment variables
     */
    private function checkEnvironmentVariables() {
        $this->addCheck('Environment Variables', function() {
            $issues = [];
            
            // Check for sensitive data in environment
            $sensitiveVars = ['OZON_API_KEY', 'WB_API_KEY', 'ENCRYPTION_KEY'];
            
            foreach ($sensitiveVars as $var) {
                $value = getenv($var);
                if ($value) {
                    // Check if it's a placeholder or example value
                    if (preg_match('/your_|example|test|demo/i', $value)) {
                        $issues[] = "$var appears to contain placeholder value";
                    }
                    
                    // Check if it's too short
                    if (strlen($value) < 10) {
                        $issues[] = "$var appears to be too short";
                    }
                }
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'warning',
                'message' => empty($issues) ? 'Environment variables look secure' : 'Environment variable issues found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Check rotation policies
     */
    private function checkRotationPolicies() {
        $this->addCheck('Rotation Policies', function() {
            $issues = [];
            
            // Check for credentials without expiry
            $services = ['ozon', 'wildberries'];
            
            foreach ($services as $service) {
                $credentials = $this->credentialManager->listServiceCredentials($service);
                
                foreach ($credentials as $cred) {
                    if (!$cred['expires_at']) {
                        $issues[] = "{$service}.{$cred['credential_type']} has no expiry date";
                    }
                    
                    if ($cred['rotation_count'] == 0 && $cred['is_active']) {
                        $createdDays = (time() - strtotime($cred['created_at'])) / (24 * 60 * 60);
                        if ($createdDays > 90) {
                            $issues[] = "{$service}.{$cred['credential_type']} has never been rotated (created {$createdDays} days ago)";
                        }
                    }
                }
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'warning',
                'message' => empty($issues) ? 'Rotation policies are adequate' : 'Rotation policy issues found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Check credential age
     */
    private function checkCredentialAge() {
        $this->addCheck('Credential Age', function() {
            $issues = [];
            
            $services = ['ozon', 'wildberries'];
            
            foreach ($services as $service) {
                $credentials = $this->credentialManager->listServiceCredentials($service);
                
                foreach ($credentials as $cred) {
                    if ($cred['is_active']) {
                        $ageDays = (time() - strtotime($cred['updated_at'])) / (24 * 60 * 60);
                        
                        if ($ageDays > 180) {
                            $issues[] = "{$service}.{$cred['credential_type']} is very old ({$ageDays} days)";
                        } elseif ($ageDays > 90) {
                            $issues[] = "{$service}.{$cred['credential_type']} is getting old ({$ageDays} days)";
                        }
                    }
                }
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'warning',
                'message' => empty($issues) ? 'Credential ages are acceptable' : 'Old credentials found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Check for unused credentials
     */
    private function checkUnusedCredentials() {
        $this->addCheck('Unused Credentials', function() {
            $issues = [];
            
            $services = ['ozon', 'wildberries'];
            
            foreach ($services as $service) {
                $credentials = $this->credentialManager->listServiceCredentials($service);
                
                foreach ($credentials as $cred) {
                    if ($cred['is_active'] && !$cred['last_used_at']) {
                        $issues[] = "{$service}.{$cred['credential_type']} has never been used";
                    } elseif ($cred['is_active'] && $cred['last_used_at']) {
                        $daysSinceUse = (time() - strtotime($cred['last_used_at'])) / (24 * 60 * 60);
                        if ($daysSinceUse > 30) {
                            $issues[] = "{$service}.{$cred['credential_type']} hasn't been used for {$daysSinceUse} days";
                        }
                    }
                }
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'warning',
                'message' => empty($issues) ? 'All credentials are being used' : 'Unused credentials found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Check for failed access attempts
     */
    private function checkFailedAccess() {
        $this->addCheck('Failed Access Attempts', function() {
            $issues = [];
            
            // Check logs for failed credential access
            $logFile = __DIR__ . '/../../logs/analytics_errors.log';
            
            if (file_exists($logFile)) {
                $logContent = file_get_contents($logFile);
                $failedAttempts = preg_match_all('/Failed to decrypt credential|Invalid credential|Authentication failed/i', $logContent);
                
                if ($failedAttempts > 10) {
                    $issues[] = "High number of failed credential access attempts: $failedAttempts";
                } elseif ($failedAttempts > 5) {
                    $issues[] = "Moderate number of failed credential access attempts: $failedAttempts";
                }
            }
            
            return [
                'status' => empty($issues) ? 'pass' : 'warning',
                'message' => empty($issues) ? 'No suspicious access patterns' : 'Suspicious access patterns found',
                'details' => $issues
            ];
        });
    }
    
    /**
     * Add a security check
     * @param string $name Check name
     * @param callable $checkFunction Function that performs the check
     */
    private function addCheck($name, $checkFunction) {
        echo "🔍 Checking: $name... ";
        
        try {
            $result = $checkFunction();
            $result['name'] = $name;
            
            $this->auditResults['checks'][] = $result;
            $this->auditResults['summary']['total_checks']++;
            
            switch ($result['status']) {
                case 'pass':
                    $this->auditResults['summary']['passed']++;
                    echo "✅ PASS\n";
                    break;
                case 'warning':
                    $this->auditResults['summary']['warnings']++;
                    echo "⚠️  WARNING\n";
                    break;
                case 'fail':
                    $this->auditResults['summary']['failures']++;
                    echo "❌ FAIL\n";
                    break;
                case 'critical':
                    $this->auditResults['summary']['critical_issues']++;
                    echo "🚨 CRITICAL\n";
                    break;
            }
            
            if (!empty($result['details'])) {
                foreach ($result['details'] as $detail) {
                    echo "   - $detail\n";
                }
            }
            
        } catch (Exception $e) {
            $result = [
                'name' => $name,
                'status' => 'fail',
                'message' => 'Check failed with exception',
                'details' => [$e->getMessage()]
            ];
            
            $this->auditResults['checks'][] = $result;
            $this->auditResults['summary']['total_checks']++;
            $this->auditResults['summary']['failures']++;
            
            echo "❌ ERROR: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Generate audit summary
     */
    private function generateAuditSummary() {
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "🔒 SECURITY AUDIT SUMMARY\n";
        echo str_repeat('=', 50) . "\n";
        
        $summary = $this->auditResults['summary'];
        
        echo "Total Checks: {$summary['total_checks']}\n";
        echo "✅ Passed: {$summary['passed']}\n";
        echo "⚠️  Warnings: {$summary['warnings']}\n";
        echo "❌ Failures: {$summary['failures']}\n";
        echo "🚨 Critical: {$summary['critical_issues']}\n";
        
        // Overall security score
        $score = 0;
        if ($summary['total_checks'] > 0) {
            $score = (($summary['passed'] * 100) + ($summary['warnings'] * 50)) / ($summary['total_checks'] * 100) * 100;
        }
        
        echo "\n📊 Security Score: " . round($score, 1) . "%\n";
        
        if ($summary['critical_issues'] > 0) {
            echo "\n🚨 CRITICAL ISSUES FOUND - IMMEDIATE ACTION REQUIRED\n";
        } elseif ($summary['failures'] > 0) {
            echo "\n❌ FAILURES FOUND - ACTION REQUIRED\n";
        } elseif ($summary['warnings'] > 0) {
            echo "\n⚠️  WARNINGS FOUND - REVIEW RECOMMENDED\n";
        } else {
            echo "\n✅ ALL CHECKS PASSED - SECURITY LOOKS GOOD\n";
        }
        
        echo "\nAudit completed at: {$this->auditResults['timestamp']}\n";
    }
    
    /**
     * Export audit results to JSON
     * @param string $filename Output filename
     */
    public function exportResults($filename) {
        $json = json_encode($this->auditResults, JSON_PRETTY_PRINT);
        file_put_contents($filename, $json);
        echo "\n📄 Audit results exported to: $filename\n";
    }
}

// Run audit if called from command line
if (php_sapi_name() === 'cli') {
    $auditor = new CredentialSecurityAuditor();
    $results = $auditor->runSecurityAudit();
    
    // Export results if requested
    if (isset($argv[1]) && $argv[1] === '--export') {
        $filename = isset($argv[2]) ? $argv[2] : 'credential_security_audit_' . date('Y-m-d_H-i-s') . '.json';
        $auditor->exportResults($filename);
    }
}

?>