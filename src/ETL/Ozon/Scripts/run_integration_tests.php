#!/usr/bin/env php
<?php

/**
 * ETL Integration Tests Runner
 * 
 * Runs comprehensive integration tests for the ETL system including
 * workflow execution, dependency handling, and data consistency validation.
 * 
 * Requirements addressed:
 * - 5.3: Write end-to-end tests for complete ETL workflow
 * - 5.3: Test ETL sequence execution and dependency handling
 * - 5.3: Verify data consistency after both ETL processes complete
 * 
 * Usage:
 *   php run_integration_tests.php [options]
 * 
 * Options:
 *   --verbose          Enable verbose output
 *   --config=FILE      Use custom configuration file
 *   --test=NAME        Run specific test method
 *   --coverage         Generate code coverage report
 *   --junit            Generate JUnit XML report
 *   --help             Show this help message
 */

declare(strict_types=1);

// Set error reporting and time limits
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(1800); // 30 minutes for tests

// Change to script directory
chdir(__DIR__);

// Load autoloader
try {
    require_once __DIR__ . '/../autoload.php';
    
    // Check if PHPUnit is available
    if (!class_exists('PHPUnit\Framework\TestCase')) {
        echo "PHPUnit is not available. Please install PHPUnit to run integration tests.\n";
        echo "Install with: composer require --dev phpunit/phpunit\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "Error loading dependencies: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array
{
    $options = [
        'verbose' => false,
        'config_file' => null,
        'test_method' => null,
        'coverage' => false,
        'junit' => false,
        'help' => false
    ];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        switch ($arg) {
            case '--verbose':
                $options['verbose'] = true;
                break;
            case '--coverage':
                $options['coverage'] = true;
                break;
            case '--junit':
                $options['junit'] = true;
                break;
            case '--help':
                $options['help'] = true;
                break;
            default:
                if (strpos($arg, '--config=') === 0) {
                    $options['config_file'] = substr($arg, 9);
                } elseif (strpos($arg, '--test=') === 0) {
                    $options['test_method'] = substr($arg, 7);
                } else {
                    echo "Unknown option: $arg\n";
                    exit(1);
                }
        }
    }
    
    return $options;
}

/**
 * Show help message
 */
function showHelp(): void
{
    echo "ETL Integration Tests Runner\n";
    echo "============================\n\n";
    echo "Runs comprehensive integration tests for the ETL system including\n";
    echo "workflow execution, dependency handling, and data consistency validation.\n\n";
    echo "Usage:\n";
    echo "  php run_integration_tests.php [options]\n\n";
    echo "Options:\n";
    echo "  --verbose          Enable verbose output\n";
    echo "  --config=FILE      Use custom configuration file\n";
    echo "  --test=NAME        Run specific test method\n";
    echo "  --coverage         Generate code coverage report\n";
    echo "  --junit            Generate JUnit XML report\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php run_integration_tests.php --verbose\n";
    echo "  php run_integration_tests.php --test=testCompleteETLWorkflow\n";
    echo "  php run_integration_tests.php --coverage --junit\n\n";
    echo "Test Methods Available:\n";
    echo "  testCompleteETLWorkflow         - Test complete ETL workflow execution\n";
    echo "  testETLSequenceAndDependencies  - Test ETL sequence and dependency handling\n";
    echo "  testETLRetryLogic              - Test retry logic for failed processes\n";
    echo "  testDataConsistencyAfterETL    - Test data consistency validation\n";
    echo "  testETLFailureScenarios        - Test failure scenarios and error handling\n";
    echo "  testETLPerformanceMetrics      - Test performance metrics collection\n\n";
}

/**
 * Setup test environment
 */
function setupTestEnvironment(array $options): array
{
    // Load configuration
    $configFile = $options['config_file'] ?? __DIR__ . '/../Config/test_config.php';
    
    if (!file_exists($configFile)) {
        // Create default test configuration if it doesn't exist
        $testConfig = createDefaultTestConfig();
        file_put_contents($configFile, "<?php\nreturn " . var_export($testConfig, true) . ";\n");
        
        if ($options['verbose']) {
            echo "Created default test configuration: $configFile\n";
        }
    } else {
        $testConfig = require $configFile;
    }
    
    // Setup test database environment variables
    $_ENV['TEST_DB_HOST'] = $testConfig['database']['host'] ?? 'localhost';
    $_ENV['TEST_DB_PORT'] = $testConfig['database']['port'] ?? 5432;
    $_ENV['TEST_DB_NAME'] = $testConfig['database']['database'] ?? 'etl_test';
    $_ENV['TEST_DB_USER'] = $testConfig['database']['username'] ?? 'test';
    $_ENV['TEST_DB_PASS'] = $testConfig['database']['password'] ?? 'test';
    
    if ($options['verbose']) {
        echo "Test environment configured:\n";
        echo "  Database: {$_ENV['TEST_DB_HOST']}:{$_ENV['TEST_DB_PORT']}/{$_ENV['TEST_DB_NAME']}\n";
        echo "  User: {$_ENV['TEST_DB_USER']}\n";
    }
    
    return $testConfig;
}

/**
 * Create default test configuration
 */
function createDefaultTestConfig(): array
{
    return [
        'database' => [
            'host' => 'localhost',
            'port' => 5432,
            'database' => 'etl_test',
            'username' => 'test',
            'password' => 'test'
        ],
        'test_settings' => [
            'timeout' => 1800,
            'memory_limit' => '512M',
            'verbose' => true
        ],
        'coverage' => [
            'enabled' => false,
            'output_directory' => __DIR__ . '/../Logs/coverage',
            'include_paths' => [
                __DIR__ . '/../Core',
                __DIR__ . '/../Components'
            ]
        ]
    ];
}

/**
 * Run PHPUnit tests
 */
function runPHPUnitTests(array $options, array $testConfig): int
{
    $testClass = 'MiCore\\ETL\\Ozon\\Tests\\ETLIntegrationTest';
    $testFile = __DIR__ . '/../Tests/ETLIntegrationTest.php';
    
    if (!file_exists($testFile)) {
        echo "Test file not found: $testFile\n";
        return 1;
    }
    
    // Build PHPUnit command
    $phpunitCommand = 'vendor/bin/phpunit';
    
    // Check if PHPUnit is available in vendor/bin
    if (!file_exists('vendor/bin/phpunit')) {
        // Try global PHPUnit
        $phpunitCommand = 'phpunit';
    }
    
    $command = [$phpunitCommand];
    
    // Add test file
    $command[] = $testFile;
    
    // Add specific test method if specified
    if ($options['test_method']) {
        $command[] = '--filter';
        $command[] = $options['test_method'];
    }
    
    // Add verbose output
    if ($options['verbose']) {
        $command[] = '--verbose';
    }
    
    // Add coverage report
    if ($options['coverage']) {
        $coverageDir = $testConfig['coverage']['output_directory'] ?? '/tmp/coverage';
        
        if (!is_dir($coverageDir)) {
            mkdir($coverageDir, 0755, true);
        }
        
        $command[] = '--coverage-html';
        $command[] = $coverageDir;
        
        if ($options['verbose']) {
            echo "Coverage report will be generated in: $coverageDir\n";
        }
    }
    
    // Add JUnit XML report
    if ($options['junit']) {
        $junitFile = __DIR__ . '/../Logs/junit_results.xml';
        $junitDir = dirname($junitFile);
        
        if (!is_dir($junitDir)) {
            mkdir($junitDir, 0755, true);
        }
        
        $command[] = '--log-junit';
        $command[] = $junitFile;
        
        if ($options['verbose']) {
            echo "JUnit XML report will be generated: $junitFile\n";
        }
    }
    
    // Execute PHPUnit
    $commandString = implode(' ', array_map('escapeshellarg', $command));
    
    if ($options['verbose']) {
        echo "Executing: $commandString\n\n";
    }
    
    $output = [];
    $returnCode = 0;
    
    exec($commandString . ' 2>&1', $output, $returnCode);
    
    // Display output
    foreach ($output as $line) {
        echo $line . "\n";
    }
    
    return $returnCode;
}

/**
 * Run custom test validation
 */
function runCustomValidation(array $options): int
{
    echo "Running custom ETL validation tests...\n\n";
    
    $passed = 0;
    $failed = 0;
    
    // Test 1: Configuration validation
    echo "1. Testing configuration validation...\n";
    try {
        $configFile = $options['config_file'] ?? __DIR__ . '/../Config/cron_config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception("Configuration file not found: $configFile");
        }
        
        $config = require $configFile;
        
        // Validate required sections
        $requiredSections = ['etl_execution', 'monitoring', 'alerts', 'dependencies'];
        foreach ($requiredSections as $section) {
            if (!isset($config[$section])) {
                throw new Exception("Missing required configuration section: $section");
            }
        }
        
        echo "   ✅ Configuration validation passed\n";
        $passed++;
        
    } catch (Exception $e) {
        echo "   ❌ Configuration validation failed: " . $e->getMessage() . "\n";
        $failed++;
    }
    
    // Test 2: Database connectivity
    echo "2. Testing database connectivity...\n";
    try {
        $testConfig = setupTestEnvironment($options);
        
        use MiCore\ETL\Ozon\Core\DatabaseConnection;
        
        $db = new DatabaseConnection($testConfig['database']);
        $result = $db->query('SELECT 1 as test');
        
        if (empty($result) || $result[0]['test'] != 1) {
            throw new Exception('Database connectivity test failed');
        }
        
        echo "   ✅ Database connectivity test passed\n";
        $passed++;
        
    } catch (Exception $e) {
        echo "   ❌ Database connectivity test failed: " . $e->getMessage() . "\n";
        $failed++;
    }
    
    // Test 3: Required classes availability
    echo "3. Testing required classes availability...\n";
    try {
        $requiredClasses = [
            'MiCore\\ETL\\Ozon\\Core\\ETLOrchestrator',
            'MiCore\\ETL\\Ozon\\Components\\ProductETL',
            'MiCore\\ETL\\Ozon\\Components\\InventoryETL',
            'MiCore\\ETL\\Ozon\\Core\\DatabaseConnection',
            'MiCore\\ETL\\Ozon\\Core\\Logger'
        ];
        
        foreach ($requiredClasses as $className) {
            if (!class_exists($className)) {
                throw new Exception("Required class not found: $className");
            }
        }
        
        echo "   ✅ Required classes availability test passed\n";
        $passed++;
        
    } catch (Exception $e) {
        echo "   ❌ Required classes availability test failed: " . $e->getMessage() . "\n";
        $failed++;
    }
    
    // Test 4: File permissions
    echo "4. Testing file permissions...\n";
    try {
        $requiredDirs = [
            __DIR__ . '/../Logs',
            __DIR__ . '/../Logs/cron',
            __DIR__ . '/../Logs/visibility_reports'
        ];
        
        foreach ($requiredDirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            if (!is_writable($dir)) {
                throw new Exception("Directory not writable: $dir");
            }
        }
        
        echo "   ✅ File permissions test passed\n";
        $passed++;
        
    } catch (Exception $e) {
        echo "   ❌ File permissions test failed: " . $e->getMessage() . "\n";
        $failed++;
    }
    
    // Summary
    echo "\nCustom validation summary:\n";
    echo "  Passed: $passed\n";
    echo "  Failed: $failed\n";
    echo "  Total:  " . ($passed + $failed) . "\n";
    
    return $failed > 0 ? 1 : 0;
}

/**
 * Main execution function
 */
function main(): int
{
    global $argv;
    
    $startTime = microtime(true);
    $options = parseArguments($argv);
    
    // Show help if requested
    if ($options['help']) {
        showHelp();
        return 0;
    }
    
    echo "ETL Integration Tests Runner\n";
    echo "============================\n\n";
    
    try {
        // Setup test environment
        $testConfig = setupTestEnvironment($options);
        
        if ($options['verbose']) {
            echo "Starting ETL integration tests...\n\n";
        }
        
        // Run custom validation first
        $customResult = runCustomValidation($options);
        
        if ($customResult !== 0) {
            echo "\nCustom validation failed. Skipping PHPUnit tests.\n";
            return $customResult;
        }
        
        echo "\nRunning PHPUnit integration tests...\n";
        echo "=====================================\n\n";
        
        // Run PHPUnit tests
        $phpunitResult = runPHPUnitTests($options, $testConfig);
        
        $duration = microtime(true) - $startTime;
        
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "Integration tests completed in " . round($duration, 2) . " seconds\n";
        
        if ($phpunitResult === 0) {
            echo "✅ All tests passed!\n";
        } else {
            echo "❌ Some tests failed (exit code: $phpunitResult)\n";
        }
        
        // Show additional information
        if ($options['coverage']) {
            $coverageDir = $testConfig['coverage']['output_directory'] ?? '/tmp/coverage';
            echo "\n📊 Coverage report available at: $coverageDir/index.html\n";
        }
        
        if ($options['junit']) {
            $junitFile = __DIR__ . '/../Logs/junit_results.xml';
            echo "📋 JUnit XML report available at: $junitFile\n";
        }
        
        return $phpunitResult;
        
    } catch (Exception $e) {
        echo "Error running integration tests: " . $e->getMessage() . "\n";
        return 1;
    }
}

// Execute main function
exit(main());