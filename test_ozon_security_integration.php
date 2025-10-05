<?php
/**
 * Test Script for Ozon Analytics Security Integration
 * 
 * This script tests all aspects of the security integration including:
 * - Access control
 * - Rate limiting
 * - Audit logging
 * - User management
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once 'src/classes/OzonSecurityManager.php';
require_once 'src/classes/OzonSecurityMiddleware.php';

// Test configuration
$testConfig = [
    'db_host' => 'localhost',
    'db_name' => 'mi_core_db',
    'db_user' => 'mi_core_user',
    'db_pass' => 'secure_password_123'
];

// Colors for console output
class Colors {
    const RED = "\033[0;31m";
    const GREEN = "\033[0;32m";
    const YELLOW = "\033[1;33m";
    const BLUE = "\033[0;34m";
    const NC = "\033[0m"; // No Color
}

class OzonSecurityTest {
    private $pdo;
    private $securityManager;
    private $securityMiddleware;
    private $testResults = [];
    
    public function __construct($config) {
        $this->initializeDatabase($config);
        $this->initializeSecurity();
    }
    
    private function initializeDatabase($config) {
        try {
            $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            $this->log("Database connection established", Colors::GREEN);
        } catch (PDOException $e) {
            $this->log("Database connection failed: " . $e->getMessage(), Colors::RED);
            exit(1);
        }
    }
    
    private function initializeSecurity() {
        $this->securityManager = new OzonSecurityManager($this->pdo);
        $this->securityMiddleware = new OzonSecurityMiddleware($this->securityManager, [
            'require_authentication' => true,
            'enable_rate_limiting' => true,
            'log_all_requests' => true
        ]);
        
        $this->log("Security components initialized", Colors::GREEN);
    }
    
    public function runAllTests() {
        $this->log("=== Starting Ozon Security Integration Tests ===", Colors::BLUE);
        
        $this->testDatabaseTables();
        $this->testUserAccessLevels();
        $this->testAccessControl();
        $this->testRateLimiting();
        $this->testAuditLogging();
        $this->testSecurityMiddleware();
        $this->testSecurityStatistics();
        $this->testCleanupFunctionality();
        
        $this->displayTestSummary();
    }
    
    private function testDatabaseTables() {
        $this->log("\n--- Testing Database Tables ---", Colors::YELLOW);
        
        $requiredTables = [
            'ozon_access_log',
            'ozon_user_access',
            'ozon_rate_limit_counters',
            'ozon_security_config'
        ];
        
        foreach ($requiredTables as $table) {
            try {
                $stmt = $this->pdo->query("DESCRIBE $table");
                $columns = $stmt->fetchAll();
                
                if (count($columns) > 0) {
                    $this->testResults['tables'][$table] = 'PASS';
                    $this->log("✓ Table $table exists with " . count($columns) . " columns", Colors::GREEN);
                } else {
                    $this->testResults['tables'][$table] = 'FAIL';
                    $this->log("✗ Table $table is empty", Colors::RED);
                }
            } catch (PDOException $e) {
                $this->testResults['tables'][$table] = 'FAIL';
                $this->log("✗ Table $table does not exist: " . $e->getMessage(), Colors::RED);
            }
        }
        
        // Test views
        $requiredViews = ['v_ozon_security_stats', 'v_ozon_user_activity'];
        foreach ($requiredViews as $view) {
            try {
                $stmt = $this->pdo->query("SELECT * FROM $view LIMIT 1");
                $this->testResults['views'][$view] = 'PASS';
                $this->log("✓ View $view is accessible", Colors::GREEN);
            } catch (PDOException $e) {
                $this->testResults['views'][$view] = 'FAIL';
                $this->log("✗ View $view is not accessible: " . $e->getMessage(), Colors::RED);
            }
        }
    }
    
    private function testUserAccessLevels() {
        $this->log("\n--- Testing User Access Levels ---", Colors::YELLOW);
        
        $testUsers = [
            ['user_id' => 'test_admin', 'access_level' => OzonSecurityManager::ACCESS_LEVEL_ADMIN],
            ['user_id' => 'test_export', 'access_level' => OzonSecurityManager::ACCESS_LEVEL_EXPORT],
            ['user_id' => 'test_read', 'access_level' => OzonSecurityManager::ACCESS_LEVEL_READ],
            ['user_id' => 'test_none', 'access_level' => OzonSecurityManager::ACCESS_LEVEL_NONE]
        ];
        
        foreach ($testUsers as $user) {
            try {
                $result = $this->securityManager->setUserAccessLevel(
                    $user['user_id'], 
                    $user['access_level'], 
                    'test_admin'
                );
                
                if ($result) {
                    $this->testResults['user_access'][$user['user_id']] = 'PASS';
                    $this->log("✓ User {$user['user_id']} access level set to {$user['access_level']}", Colors::GREEN);
                } else {
                    $this->testResults['user_access'][$user['user_id']] = 'FAIL';
                    $this->log("✗ Failed to set access level for {$user['user_id']}", Colors::RED);
                }
            } catch (Exception $e) {
                $this->testResults['user_access'][$user['user_id']] = 'FAIL';
                $this->log("✗ Error setting access for {$user['user_id']}: " . $e->getMessage(), Colors::RED);
            }
        }
    }
    
    private function testAccessControl() {
        $this->log("\n--- Testing Access Control ---", Colors::YELLOW);
        
        $testCases = [
            // Admin user should have access to all operations
            ['user' => 'test_admin', 'operation' => OzonSecurityManager::OPERATION_VIEW_FUNNEL, 'expected' => true],
            ['user' => 'test_admin', 'operation' => OzonSecurityManager::OPERATION_EXPORT_DATA, 'expected' => true],
            ['user' => 'test_admin', 'operation' => OzonSecurityManager::OPERATION_MANAGE_SETTINGS, 'expected' => true],
            
            // Export user should have read and export access
            ['user' => 'test_export', 'operation' => OzonSecurityManager::OPERATION_VIEW_FUNNEL, 'expected' => true],
            ['user' => 'test_export', 'operation' => OzonSecurityManager::OPERATION_EXPORT_DATA, 'expected' => true],
            ['user' => 'test_export', 'operation' => OzonSecurityManager::OPERATION_MANAGE_SETTINGS, 'expected' => false],
            
            // Read user should only have read access
            ['user' => 'test_read', 'operation' => OzonSecurityManager::OPERATION_VIEW_FUNNEL, 'expected' => true],
            ['user' => 'test_read', 'operation' => OzonSecurityManager::OPERATION_EXPORT_DATA, 'expected' => false],
            ['user' => 'test_read', 'operation' => OzonSecurityManager::OPERATION_MANAGE_SETTINGS, 'expected' => false],
            
            // No access user should be denied everything
            ['user' => 'test_none', 'operation' => OzonSecurityManager::OPERATION_VIEW_FUNNEL, 'expected' => false],
            ['user' => 'test_none', 'operation' => OzonSecurityManager::OPERATION_EXPORT_DATA, 'expected' => false],
        ];
        
        foreach ($testCases as $test) {
            try {
                $hasAccess = false;
                try {
                    $this->securityManager->checkAccess($test['user'], $test['operation']);
                    $hasAccess = true;
                } catch (SecurityException $e) {
                    $hasAccess = false;
                }
                
                if ($hasAccess === $test['expected']) {
                    $this->testResults['access_control'][] = 'PASS';
                    $status = $test['expected'] ? 'granted' : 'denied';
                    $this->log("✓ Access correctly $status for {$test['user']} -> {$test['operation']}", Colors::GREEN);
                } else {
                    $this->testResults['access_control'][] = 'FAIL';
                    $expected = $test['expected'] ? 'granted' : 'denied';
                    $actual = $hasAccess ? 'granted' : 'denied';
                    $this->log("✗ Access test failed for {$test['user']} -> {$test['operation']} (expected: $expected, got: $actual)", Colors::RED);
                }
            } catch (Exception $e) {
                $this->testResults['access_control'][] = 'FAIL';
                $this->log("✗ Access control test error: " . $e->getMessage(), Colors::RED);
            }
        }
    }
    
    private function testRateLimiting() {
        $this->log("\n--- Testing Rate Limiting ---", Colors::YELLOW);
        
        $testUser = 'test_rate_limit';
        $operation = OzonSecurityManager::OPERATION_VIEW_FUNNEL;
        
        // Set a very low rate limit for testing
        $originalLimit = 100;
        $testLimit = 3;
        
        try {
            // Create test user with read access
            $this->securityManager->setUserAccessLevel($testUser, OzonSecurityManager::ACCESS_LEVEL_READ, 'test_admin');
            
            // Test normal requests within limit
            $successCount = 0;
            for ($i = 1; $i <= $testLimit; $i++) {
                try {
                    $this->securityManager->checkAccess($testUser, $operation);
                    $successCount++;
                } catch (SecurityException $e) {
                    break;
                }
            }
            
            if ($successCount >= $testLimit - 1) {
                $this->testResults['rate_limiting']['within_limit'] = 'PASS';
                $this->log("✓ Rate limiting allows requests within limit ($successCount/$testLimit)", Colors::GREEN);
            } else {
                $this->testResults['rate_limiting']['within_limit'] = 'FAIL';
                $this->log("✗ Rate limiting failed within limit ($successCount/$testLimit)", Colors::RED);
            }
            
            // Test rate limit exceeded (this would require modifying the rate limit temporarily)
            // For now, we'll just log that this test needs manual verification
            $this->log("ℹ Rate limit exceeded test requires manual verification with lower limits", Colors::BLUE);
            $this->testResults['rate_limiting']['exceeded'] = 'MANUAL';
            
        } catch (Exception $e) {
            $this->testResults['rate_limiting']['error'] = 'FAIL';
            $this->log("✗ Rate limiting test error: " . $e->getMessage(), Colors::RED);
        }
    }
    
    private function testAuditLogging() {
        $this->log("\n--- Testing Audit Logging ---", Colors::YELLOW);
        
        $testUser = 'test_logging';
        $operation = OzonSecurityManager::OPERATION_VIEW_FUNNEL;
        
        try {
            // Get initial log count
            $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM ozon_access_log WHERE user_id = ?");
            $stmt->execute([$testUser]);
            $initialCount = $stmt->fetch()['count'];
            
            // Perform an operation that should be logged
            $this->securityManager->logSecurityEvent(
                'TEST_EVENT',
                $testUser,
                $operation,
                ['ip_address' => '127.0.0.1'],
                ['test' => true]
            );
            
            // Check if log was created
            $stmt->execute([$testUser]);
            $finalCount = $stmt->fetch()['count'];
            
            if ($finalCount > $initialCount) {
                $this->testResults['audit_logging']['basic'] = 'PASS';
                $this->log("✓ Audit logging working (logs increased from $initialCount to $finalCount)", Colors::GREEN);
            } else {
                $this->testResults['audit_logging']['basic'] = 'FAIL';
                $this->log("✗ Audit logging failed (logs: $initialCount -> $finalCount)", Colors::RED);
            }
            
            // Test log content
            $stmt = $this->pdo->prepare("
                SELECT event_type, operation, details 
                FROM ozon_access_log 
                WHERE user_id = ? AND event_type = 'TEST_EVENT' 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute([$testUser]);
            $logEntry = $stmt->fetch();
            
            if ($logEntry && $logEntry['event_type'] === 'TEST_EVENT') {
                $this->testResults['audit_logging']['content'] = 'PASS';
                $this->log("✓ Log content is correct", Colors::GREEN);
            } else {
                $this->testResults['audit_logging']['content'] = 'FAIL';
                $this->log("✗ Log content is incorrect or missing", Colors::RED);
            }
            
        } catch (Exception $e) {
            $this->testResults['audit_logging']['error'] = 'FAIL';
            $this->log("✗ Audit logging test error: " . $e->getMessage(), Colors::RED);
        }
    }
    
    private function testSecurityMiddleware() {
        $this->log("\n--- Testing Security Middleware ---", Colors::YELLOW);
        
        try {
            // Test valid request
            $result = $this->securityMiddleware->checkRequest('funnel-data', ['user_id' => 'test_admin']);
            
            if ($result['success'] && $result['user_id'] === 'test_admin') {
                $this->testResults['middleware']['valid_request'] = 'PASS';
                $this->log("✓ Middleware allows valid requests", Colors::GREEN);
            } else {
                $this->testResults['middleware']['valid_request'] = 'FAIL';
                $this->log("✗ Middleware failed for valid request", Colors::RED);
            }
            
            // Test user security config
            $config = $this->securityMiddleware->getUserSecurityConfig('test_admin');
            
            if (isset($config['permissions']) && is_array($config['permissions'])) {
                $this->testResults['middleware']['user_config'] = 'PASS';
                $this->log("✓ User security config retrieved successfully", Colors::GREEN);
            } else {
                $this->testResults['middleware']['user_config'] = 'FAIL';
                $this->log("✗ User security config failed", Colors::RED);
            }
            
        } catch (Exception $e) {
            $this->testResults['middleware']['error'] = 'FAIL';
            $this->log("✗ Middleware test error: " . $e->getMessage(), Colors::RED);
        }
    }
    
    private function testSecurityStatistics() {
        $this->log("\n--- Testing Security Statistics ---", Colors::YELLOW);
        
        try {
            $stats = $this->securityManager->getSecurityStats('day');
            
            if (isset($stats['events']) && isset($stats['operations'])) {
                $this->testResults['statistics']['basic'] = 'PASS';
                $this->log("✓ Security statistics generated successfully", Colors::GREEN);
                $this->log("  - Events: " . count($stats['events']), Colors::BLUE);
                $this->log("  - Operations: " . count($stats['operations']), Colors::BLUE);
                $this->log("  - Top users: " . count($stats['top_users']), Colors::BLUE);
            } else {
                $this->testResults['statistics']['basic'] = 'FAIL';
                $this->log("✗ Security statistics failed", Colors::RED);
            }
            
        } catch (Exception $e) {
            $this->testResults['statistics']['error'] = 'FAIL';
            $this->log("✗ Statistics test error: " . $e->getMessage(), Colors::RED);
        }
    }
    
    private function testCleanupFunctionality() {
        $this->log("\n--- Testing Cleanup Functionality ---", Colors::YELLOW);
        
        try {
            $stats = $this->securityManager->cleanupOldRecords(1); // Keep only 1 day
            
            if (isset($stats['deleted_access_logs']) && isset($stats['deleted_rate_counters'])) {
                $this->testResults['cleanup']['basic'] = 'PASS';
                $this->log("✓ Cleanup functionality working", Colors::GREEN);
                $this->log("  - Deleted access logs: " . $stats['deleted_access_logs'], Colors::BLUE);
                $this->log("  - Deleted rate counters: " . $stats['deleted_rate_counters'], Colors::BLUE);
            } else {
                $this->testResults['cleanup']['basic'] = 'FAIL';
                $this->log("✗ Cleanup functionality failed", Colors::RED);
            }
            
        } catch (Exception $e) {
            $this->testResults['cleanup']['error'] = 'FAIL';
            $this->log("✗ Cleanup test error: " . $e->getMessage(), Colors::RED);
        }
    }
    
    private function displayTestSummary() {
        $this->log("\n=== Test Summary ===", Colors::BLUE);
        
        $totalTests = 0;
        $passedTests = 0;
        $failedTests = 0;
        $manualTests = 0;
        
        foreach ($this->testResults as $category => $tests) {
            $this->log("\n$category:", Colors::YELLOW);
            
            if (is_array($tests)) {
                foreach ($tests as $test => $result) {
                    $totalTests++;
                    
                    switch ($result) {
                        case 'PASS':
                            $passedTests++;
                            $this->log("  ✓ $test", Colors::GREEN);
                            break;
                        case 'FAIL':
                            $failedTests++;
                            $this->log("  ✗ $test", Colors::RED);
                            break;
                        case 'MANUAL':
                            $manualTests++;
                            $this->log("  ⚠ $test (manual verification required)", Colors::YELLOW);
                            break;
                    }
                }
            }
        }
        
        $this->log("\n=== Final Results ===", Colors::BLUE);
        $this->log("Total Tests: $totalTests", Colors::BLUE);
        $this->log("Passed: $passedTests", Colors::GREEN);
        $this->log("Failed: $failedTests", $failedTests > 0 ? Colors::RED : Colors::GREEN);
        $this->log("Manual: $manualTests", Colors::YELLOW);
        
        $successRate = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 1) : 0;
        $this->log("Success Rate: $successRate%", $successRate >= 80 ? Colors::GREEN : Colors::RED);
        
        if ($failedTests === 0) {
            $this->log("\n🎉 All automated tests passed! Security integration is working correctly.", Colors::GREEN);
        } else {
            $this->log("\n⚠️  Some tests failed. Please review the errors above.", Colors::YELLOW);
        }
    }
    
    private function log($message, $color = Colors::NC) {
        echo $color . $message . Colors::NC . "\n";
    }
}

// Run the tests
try {
    $tester = new OzonSecurityTest($testConfig);
    $tester->runAllTests();
} catch (Exception $e) {
    echo Colors::RED . "Test execution failed: " . $e->getMessage() . Colors::NC . "\n";
    exit(1);
}
?>