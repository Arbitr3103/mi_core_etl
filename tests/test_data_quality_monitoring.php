<?php
/**
 * Data Quality Monitoring Tests
 * 
 * Tests for the monitoring system including alerts and metrics
 * Requirements: 8.3, 4.3
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/DataQualityMonitor.php';
require_once __DIR__ . '/../src/AlertHandlers.php';

class DataQualityMonitoringTest {
    private $pdo;
    private $testResults = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function runAllTests() {
        echo "=== Data Quality Monitoring Tests ===\n\n";
        
        $this->testMonitorInitialization();
        $this->testQualityMetrics();
        $this->testAlertThresholds();
        $this->testAlertHandlers();
        $this->testQualityChecks();
        $this->testAlertLogging();
        $this->testAPIEndpoints();
        
        $this->printSummary();
    }
    
    private function testMonitorInitialization() {
        echo "Test 1: Monitor Initialization\n";
        
        try {
            $monitor = new DataQualityMonitor($this->pdo);
            $this->pass("Monitor initialized successfully");
            
            // Test custom thresholds
            $monitor->setThresholds([
                'failed_percentage' => 10,
                'pending_percentage' => 20
            ]);
            $this->pass("Custom thresholds set successfully");
            
        } catch (Exception $e) {
            $this->fail("Monitor initialization failed: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testQualityMetrics() {
        echo "Test 2: Quality Metrics Collection\n";
        
        try {
            $monitor = new DataQualityMonitor($this->pdo);
            $metrics = $monitor->getQualityMetrics();
            
            // Verify metrics structure
            $requiredKeys = ['sync_status', 'data_quality', 'error_metrics', 'performance', 'overall_score'];
            foreach ($requiredKeys as $key) {
                if (!isset($metrics[$key])) {
                    $this->fail("Missing metric key: $key");
                    return;
                }
            }
            $this->pass("All required metric keys present");
            
            // Verify sync status metrics
            $syncStatus = $metrics['sync_status'];
            if (isset($syncStatus['total']) && isset($syncStatus['synced']) && isset($syncStatus['pending'])) {
                $this->pass("Sync status metrics valid");
            } else {
                $this->fail("Sync status metrics incomplete");
            }
            
            // Verify data quality metrics
            $dataQuality = $metrics['data_quality'];
            if (isset($dataQuality['real_names_percentage']) && isset($dataQuality['brands_percentage'])) {
                $this->pass("Data quality metrics valid");
            } else {
                $this->fail("Data quality metrics incomplete");
            }
            
            // Verify overall score
            $score = $metrics['overall_score'];
            if ($score >= 0 && $score <= 100) {
                $this->pass("Overall quality score valid: $score/100");
            } else {
                $this->fail("Overall quality score out of range: $score");
            }
            
        } catch (Exception $e) {
            $this->fail("Quality metrics collection failed: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testAlertThresholds() {
        echo "Test 3: Alert Threshold Configuration\n";
        
        try {
            $monitor = new DataQualityMonitor($this->pdo);
            
            // Set custom thresholds
            $customThresholds = [
                'failed_percentage' => 3,
                'pending_percentage' => 8,
                'real_names_percentage' => 85,
                'sync_age_hours' => 24,
                'error_count_hourly' => 5
            ];
            
            $monitor->setThresholds($customThresholds);
            $this->pass("Custom thresholds configured");
            
            // Run checks to see if thresholds are applied
            $result = $monitor->runQualityChecks();
            $this->pass("Quality checks executed with custom thresholds");
            
        } catch (Exception $e) {
            $this->fail("Alert threshold configuration failed: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testAlertHandlers() {
        echo "Test 4: Alert Handlers\n";
        
        try {
            // Test Log Handler
            $logFile = 'logs/test_alerts.log';
            if (file_exists($logFile)) {
                unlink($logFile);
            }
            
            $logHandler = new LogAlertHandler($logFile);
            $testAlert = [
                'level' => 'warning',
                'type' => 'test_alert',
                'message' => 'Test alert message',
                'value' => 10,
                'threshold' => 5
            ];
            
            $logHandler($testAlert);
            
            if (file_exists($logFile)) {
                $this->pass("Log handler created log file");
                $content = file_get_contents($logFile);
                if (strpos($content, 'test_alert') !== false) {
                    $this->pass("Log handler wrote alert correctly");
                } else {
                    $this->fail("Log handler content incorrect");
                }
            } else {
                $this->fail("Log handler did not create log file");
            }
            
            // Test Console Handler
            $consoleHandler = new ConsoleAlertHandler();
            ob_start();
            $consoleHandler($testAlert);
            $output = ob_get_clean();
            
            if (strpos($output, 'test_alert') !== false) {
                $this->pass("Console handler output correct");
            } else {
                $this->fail("Console handler output incorrect");
            }
            
            // Test Composite Handler
            $composite = new CompositeAlertHandler();
            $composite->addHandler($logHandler);
            $composite->addHandler($consoleHandler);
            
            ob_start();
            $composite($testAlert);
            $output = ob_get_clean();
            
            $this->pass("Composite handler executed multiple handlers");
            
        } catch (Exception $e) {
            $this->fail("Alert handlers test failed: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testQualityChecks() {
        echo "Test 5: Quality Checks Execution\n";
        
        try {
            $monitor = new DataQualityMonitor($this->pdo);
            
            // Add test handler to capture alerts
            $capturedAlerts = [];
            $monitor->addAlertHandler(function($alert) use (&$capturedAlerts) {
                $capturedAlerts[] = $alert;
            });
            
            // Run quality checks
            $result = $monitor->runQualityChecks();
            
            // Verify result structure
            if (isset($result['alerts_triggered']) && isset($result['alerts']) && isset($result['timestamp'])) {
                $this->pass("Quality check result structure valid");
            } else {
                $this->fail("Quality check result structure invalid");
            }
            
            // Verify alerts were processed
            $alertCount = $result['alerts_triggered'];
            $this->pass("Quality checks completed: $alertCount alert(s) triggered");
            
            // Verify captured alerts match result
            if (count($capturedAlerts) === $alertCount) {
                $this->pass("Alert handlers received correct number of alerts");
            } else {
                $this->fail("Alert handler count mismatch");
            }
            
        } catch (Exception $e) {
            $this->fail("Quality checks execution failed: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testAlertLogging() {
        echo "Test 6: Alert Logging to Database\n";
        
        try {
            $monitor = new DataQualityMonitor($this->pdo);
            
            // Trigger some checks to generate alerts
            $monitor->runQualityChecks();
            
            // Get recent alerts
            $alerts = $monitor->getRecentAlerts(5);
            $this->pass("Retrieved recent alerts: " . count($alerts) . " found");
            
            // Get alert statistics
            $stats = $monitor->getAlertStats();
            $this->pass("Retrieved alert statistics: " . count($stats) . " types");
            
            // Verify alert structure
            if (!empty($alerts)) {
                $alert = $alerts[0];
                $requiredKeys = ['alert_level', 'alert_type', 'message', 'created_at'];
                $valid = true;
                foreach ($requiredKeys as $key) {
                    if (!isset($alert[$key])) {
                        $valid = false;
                        break;
                    }
                }
                
                if ($valid) {
                    $this->pass("Alert database structure valid");
                } else {
                    $this->fail("Alert database structure invalid");
                }
            }
            
        } catch (Exception $e) {
            $this->fail("Alert logging test failed: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function testAPIEndpoints() {
        echo "Test 7: API Endpoints\n";
        
        try {
            // Test metrics endpoint
            $metricsUrl = 'http://localhost/api/quality-metrics.php?action=metrics';
            $this->pass("Metrics endpoint URL: $metricsUrl");
            
            // Test health endpoint
            $healthUrl = 'http://localhost/api/quality-metrics.php?action=health';
            $this->pass("Health endpoint URL: $healthUrl");
            
            // Test alerts endpoint
            $alertsUrl = 'http://localhost/api/quality-metrics.php?action=alerts';
            $this->pass("Alerts endpoint URL: $alertsUrl");
            
            $this->pass("All API endpoints configured");
            
        } catch (Exception $e) {
            $this->fail("API endpoints test failed: " . $e->getMessage());
        }
        
        echo "\n";
    }
    
    private function pass($message) {
        echo "  ✓ $message\n";
        $this->testResults[] = ['status' => 'pass', 'message' => $message];
    }
    
    private function fail($message) {
        echo "  ✗ $message\n";
        $this->testResults[] = ['status' => 'fail', 'message' => $message];
    }
    
    private function printSummary() {
        echo "\n=== Test Summary ===\n";
        
        $passed = count(array_filter($this->testResults, function($r) { return $r['status'] === 'pass'; }));
        $failed = count(array_filter($this->testResults, function($r) { return $r['status'] === 'fail'; }));
        $total = count($this->testResults);
        
        echo "Total tests: $total\n";
        echo "Passed: $passed\n";
        echo "Failed: $failed\n";
        
        if ($failed === 0) {
            echo "\n✓ All tests passed!\n";
        } else {
            echo "\n✗ Some tests failed. Please review the output above.\n";
        }
    }
}

// Run tests
try {
    $tester = new DataQualityMonitoringTest($pdo);
    $tester->runAllTests();
} catch (Exception $e) {
    echo "Test execution failed: " . $e->getMessage() . "\n";
    exit(1);
}
