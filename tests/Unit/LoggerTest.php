<?php
/**
 * Unit Tests for Logger Utility
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/utils/Logger.php';

class LoggerTest extends TestCase {
    private $logger;
    private $testLogPath;
    
    protected function setUp(): void {
        // Create temporary log directory for testing
        $this->testLogPath = sys_get_temp_dir() . '/mi_core_etl_test_logs_' . uniqid();
        mkdir($this->testLogPath, 0755, true);
        
        // Set environment variable for test log path
        $_ENV['LOG_PATH'] = $this->testLogPath;
        $_ENV['LOG_LEVEL'] = 'debug';
        
        $this->logger = Logger::getInstance();
    }
    
    protected function tearDown(): void {
        // Clean up test log files
        if (is_dir($this->testLogPath)) {
            $files = glob($this->testLogPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testLogPath);
        }
        
        // Reset singleton instance for next test
        $reflection = new ReflectionClass(Logger::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }
    
    public function testSingletonInstance() {
        $logger1 = Logger::getInstance();
        $logger2 = Logger::getInstance();
        
        $this->assertSame($logger1, $logger2);
    }
    
    public function testInfoLogging() {
        $message = 'Test info message';
        $context = ['test_key' => 'test_value'];
        
        $this->logger->info($message, $context);
        
        $logFile = $this->testLogPath . '/info_' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContainsString($message, $logContent);
        $this->assertStringContainsString('INFO', $logContent);
        $this->assertStringContainsString('test_key', $logContent);
    }
    
    public function testErrorLogging() {
        $message = 'Test error message';
        $context = ['error_code' => 500];
        
        $this->logger->error($message, $context);
        
        $logFile = $this->testLogPath . '/error_' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContainsString($message, $logContent);
        $this->assertStringContainsString('ERROR', $logContent);
        $this->assertStringContainsString('error_code', $logContent);
    }
    
    public function testDebugLogging() {
        $message = 'Test debug message';
        
        $this->logger->debug($message);
        
        $logFile = $this->testLogPath . '/debug_' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContainsString($message, $logContent);
        $this->assertStringContainsString('DEBUG', $logContent);
    }
    
    public function testPerformanceLogging() {
        $operation = 'test_operation';
        $duration = 1.5;
        $context = ['param' => 'value'];
        
        $this->logger->performance($operation, $duration, $context);
        
        $logFile = $this->testLogPath . '/info_' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContainsString($operation, $logContent);
        $this->assertStringContainsString('1500', $logContent); // duration in ms
        $this->assertStringContainsString('memory_usage', $logContent);
    }
    
    public function testQueryLogging() {
        $sql = 'SELECT * FROM test_table WHERE id = ?';
        $params = [123];
        $duration = 0.05;
        
        $this->logger->query($sql, $params, $duration);
        
        $logFile = $this->testLogPath . '/debug_' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContainsString($sql, $logContent);
        $this->assertStringContainsString('50', $logContent); // duration in ms
    }
    
    public function testApiRequestLogging() {
        $method = 'GET';
        $url = 'https://api.example.com/test';
        $headers = ['Authorization' => 'Bearer token123'];
        $statusCode = 200;
        $duration = 0.3;
        
        $this->logger->apiRequest($method, $url, $headers, null, $statusCode, $duration);
        
        $logFile = $this->testLogPath . '/info_' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);
        
        $logContent = file_get_contents($logFile);
        $this->assertStringContainsString($method, $logContent);
        $this->assertStringContainsString($url, $logContent);
        $this->assertStringContainsString('[REDACTED]', $logContent); // Authorization header should be redacted
        $this->assertStringContainsString('200', $logContent);
    }
    
    public function testContextSetting() {
        $globalContext = ['user_id' => 123, 'session_id' => 'abc123'];
        
        $this->logger->setContext($globalContext);
        $this->logger->info('Test message with context');
        
        $logFile = $this->testLogPath . '/info_' . date('Y-m-d') . '.log';
        $logContent = file_get_contents($logFile);
        
        $this->assertStringContainsString('user_id', $logContent);
        $this->assertStringContainsString('123', $logContent);
        $this->assertStringContainsString('session_id', $logContent);
    }
    
    public function testLogStats() {
        $this->logger->info('Test message 1');
        $this->logger->error('Test error 1');
        $this->logger->debug('Test debug 1');
        
        $stats = $this->logger->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('log_path', $stats);
        $this->assertArrayHasKey('files', $stats);
        $this->assertArrayHasKey('total_size', $stats);
        $this->assertEquals($this->testLogPath, $stats['log_path']);
    }
    
    public function testLogLevelFiltering() {
        // Set log level to error only
        $_ENV['LOG_LEVEL'] = 'error';
        
        // Reset logger instance to pick up new config
        $reflection = new ReflectionClass(Logger::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        
        $logger = Logger::getInstance();
        
        $logger->debug('This should not be logged');
        $logger->info('This should not be logged');
        $logger->error('This should be logged');
        
        $debugFile = $this->testLogPath . '/debug_' . date('Y-m-d') . '.log';
        $infoFile = $this->testLogPath . '/info_' . date('Y-m-d') . '.log';
        $errorFile = $this->testLogPath . '/error_' . date('Y-m-d') . '.log';
        
        $this->assertFileDoesNotExist($debugFile);
        $this->assertFileDoesNotExist($infoFile);
        $this->assertFileExists($errorFile);
    }
    
    public function testHelperFunctions() {
        // Test global helper functions
        logInfo('Test info via helper');
        logError('Test error via helper');
        logDebug('Test debug via helper');
        
        $infoFile = $this->testLogPath . '/info_' . date('Y-m-d') . '.log';
        $errorFile = $this->testLogPath . '/error_' . date('Y-m-d') . '.log';
        $debugFile = $this->testLogPath . '/debug_' . date('Y-m-d') . '.log';
        
        $this->assertFileExists($infoFile);
        $this->assertFileExists($errorFile);
        $this->assertFileExists($debugFile);
        
        $this->assertStringContainsString('Test info via helper', file_get_contents($infoFile));
        $this->assertStringContainsString('Test error via helper', file_get_contents($errorFile));
        $this->assertStringContainsString('Test debug via helper', file_get_contents($debugFile));
    }
}