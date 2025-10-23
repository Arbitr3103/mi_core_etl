#!/usr/bin/env php
<?php
/**
 * Final System Testing Script
 * 
 * Comprehensive testing of all system components including:
 * - Database connectivity and performance
 * - API endpoints functionality
 * - Frontend build integrity
 * - ETL processes
 * - System health
 */

// Load environment variables if .env exists
if (file_exists(__DIR__ . '/../.env')) {
    $lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

class FinalSystemTest
{
    private $results = [];
    private $startTime;
    private $db;
    
    public function __construct()
    {
        $this->startTime = microtime(true);
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║         MI Core ETL - Final System Test Suite             ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }
    
    public function run()
    {
        $this->testDatabaseConnectivity();
        $this->testDatabasePerformance();
        $this->testAPIEndpoints();
        $this->testFrontendBuild();
        $this->testCacheSystem();
        $this->testLoggingSystem();
        $this->testSystemHealth();
        
        $this->printSummary();
    }
    
    private function testDatabaseConnectivity()
    {
        $this->printTestHeader("Database Connectivity");
        
        try {
            // Try to load config from file or environment
            $configFile = __DIR__ . '/../config/database_postgresql.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
            } else {
                // Fallback to environment variables
                $config = [
                    'host' => getenv('PG_HOST') ?: 'localhost',
                    'port' => getenv('PG_PORT') ?: '5432',
                    'database' => getenv('PG_NAME') ?: 'mi_core_db',
                    'username' => getenv('PG_USER') ?: 'mi_core_user',
                    'password' => getenv('PG_PASS') ?: ''
                ];
            }
            
            $dsn = sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                $config['host'],
                $config['port'],
                $config['database']
            );
            
            $this->db = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            $this->pass("PostgreSQL connection established");
            
            // Test version
            $stmt = $this->db->query("SELECT version()");
            $version = $stmt->fetchColumn();
            $this->info("PostgreSQL version: " . substr($version, 0, 50) . "...");
            
            // Test database size
            $stmt = $this->db->query("SELECT pg_size_pretty(pg_database_size(current_database()))");
            $size = $stmt->fetchColumn();
            $this->info("Database size: {$size}");
            
        } catch (Exception $e) {
            $this->fail("Database connection failed: " . $e->getMessage());
        }
    }
    
    private function testDatabasePerformance()
    {
        $this->printTestHeader("Database Performance");
        
        if (!$this->db) {
            $this->skip("Database not connected");
            return;
        }
        
        try {
            // Test table counts
            $tables = ['products', 'inventory_data', 'warehouses', 'dim_products'];
            foreach ($tables as $table) {
                $start = microtime(true);
                $stmt = $this->db->query("SELECT COUNT(*) FROM $table");
                $count = $stmt->fetchColumn();
                $time = round((microtime(true) - $start) * 1000, 2);
                
                if ($time < 1000) {
                    $this->pass("Table '$table': $count rows ({$time}ms)");
                } else {
                    $this->warn("Table '$table': $count rows ({$time}ms) - Slow query");
                }
            }
            
            // Test dashboard query performance
            $start = microtime(true);
            $stmt = $this->db->query("
                SELECT 
                    p.sku, 
                    p.name, 
                    i.current_stock,
                    w.name as warehouse_name
                FROM products p
                JOIN inventory_data i ON p.id = i.product_id
                JOIN warehouses w ON i.warehouse_id = w.id
                WHERE p.is_active = true
                LIMIT 100
            ");
            $results = $stmt->fetchAll();
            $time = round((microtime(true) - $start) * 1000, 2);
            
            if ($time < 2000) {
                $this->pass("Dashboard query: " . count($results) . " products ({$time}ms)");
            } else {
                $this->warn("Dashboard query: " . count($results) . " products ({$time}ms) - Slow");
            }
            
            // Test indexes
            $stmt = $this->db->query("
                SELECT 
                    schemaname, 
                    tablename, 
                    indexname 
                FROM pg_indexes 
                WHERE schemaname = 'public'
                ORDER BY tablename, indexname
            ");
            $indexes = $stmt->fetchAll();
            $this->info("Total indexes: " . count($indexes));
            
        } catch (Exception $e) {
            $this->fail("Performance test failed: " . $e->getMessage());
        }
    }
    
    private function testAPIEndpoints()
    {
        $this->printTestHeader("API Endpoints");
        
        $baseUrl = getenv('API_BASE_URL') ?: 'http://localhost/api';
        
        // Test health endpoint
        $this->testEndpoint("$baseUrl/health", "Health Check");
        
        // Test dashboard endpoint
        $this->testEndpoint("$baseUrl/inventory/dashboard?limit=10", "Dashboard (limit=10)");
        $this->testEndpoint("$baseUrl/inventory/dashboard?limit=100", "Dashboard (limit=100)");
        
        // Test with invalid parameters
        $response = @file_get_contents("$baseUrl/inventory/dashboard?limit=invalid");
        if ($response === false) {
            $this->pass("Invalid parameter handling works");
        } else {
            $this->warn("Invalid parameter should return error");
        }
    }
    
    private function testEndpoint($url, $name)
    {
        $start = microtime(true);
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        $time = round((microtime(true) - $start) * 1000, 2);
        
        if ($response === false) {
            $this->fail("$name: Connection failed");
            return;
        }
        
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->fail("$name: Invalid JSON response");
            return;
        }
        
        if ($time < 2000) {
            $this->pass("$name: Response received ({$time}ms)");
        } else {
            $this->warn("$name: Response received ({$time}ms) - Slow");
        }
        
        // Check response structure
        if (isset($data['data']) || isset($data['status'])) {
            $this->info("  ✓ Valid response structure");
        } else {
            $this->warn("  ⚠ Unexpected response structure");
        }
    }
    
    private function testFrontendBuild()
    {
        $this->printTestHeader("Frontend Build");
        
        $buildDir = __DIR__ . '/../frontend/dist';
        $publicBuildDir = __DIR__ . '/../public/build';
        
        // Check if build exists
        if (is_dir($buildDir)) {
            $this->pass("Frontend build directory exists");
            
            // Check for essential files
            $essentialFiles = ['index.html', 'assets'];
            foreach ($essentialFiles as $file) {
                $path = "$buildDir/$file";
                if (file_exists($path)) {
                    $this->pass("  ✓ $file exists");
                } else {
                    $this->fail("  ✗ $file missing");
                }
            }
            
            // Check build size
            $size = $this->getDirectorySize($buildDir);
            $sizeMB = round($size / 1024 / 1024, 2);
            $this->info("Build size: {$sizeMB}MB");
            
        } else {
            $this->warn("Frontend build directory not found - run 'npm run build'");
        }
        
        // Check public build
        if (is_dir($publicBuildDir)) {
            $this->pass("Public build directory exists");
        } else {
            $this->warn("Public build directory not found");
        }
    }
    
    private function testCacheSystem()
    {
        $this->printTestHeader("Cache System");
        
        $cacheDir = __DIR__ . '/../storage/cache';
        
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        if (is_writable($cacheDir)) {
            $this->pass("Cache directory is writable");
        } else {
            $this->fail("Cache directory is not writable");
        }
        
        // Test cache write
        $testFile = "$cacheDir/test_" . time() . ".cache";
        $testData = ['test' => 'data', 'timestamp' => time()];
        
        if (file_put_contents($testFile, serialize($testData))) {
            $this->pass("Cache write successful");
            
            // Test cache read
            $readData = unserialize(file_get_contents($testFile));
            if ($readData === $testData) {
                $this->pass("Cache read successful");
            } else {
                $this->fail("Cache read failed");
            }
            
            // Cleanup
            unlink($testFile);
        } else {
            $this->fail("Cache write failed");
        }
        
        // Check cache size
        $size = $this->getDirectorySize($cacheDir);
        $sizeMB = round($size / 1024 / 1024, 2);
        $this->info("Cache size: {$sizeMB}MB");
    }
    
    private function testLoggingSystem()
    {
        $this->printTestHeader("Logging System");
        
        $logDir = __DIR__ . '/../storage/logs';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        if (is_writable($logDir)) {
            $this->pass("Log directory is writable");
        } else {
            $this->fail("Log directory is not writable");
        }
        
        // Test log write
        $testLog = "$logDir/test_" . date('Y-m-d') . ".log";
        $testMessage = "[" . date('Y-m-d H:i:s') . "] TEST: System test log entry\n";
        
        if (file_put_contents($testLog, $testMessage, FILE_APPEND)) {
            $this->pass("Log write successful");
        } else {
            $this->fail("Log write failed");
        }
        
        // Check log files
        $logFiles = glob("$logDir/*.log");
        $this->info("Log files found: " . count($logFiles));
        
        // Check log size
        $size = $this->getDirectorySize($logDir);
        $sizeMB = round($size / 1024 / 1024, 2);
        $this->info("Total log size: {$sizeMB}MB");
        
        if ($sizeMB > 100) {
            $this->warn("Log size is large - consider log rotation");
        }
    }
    
    private function testSystemHealth()
    {
        $this->printTestHeader("System Health");
        
        // Check PHP version
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, '8.1.0', '>=')) {
            $this->pass("PHP version: $phpVersion");
        } else {
            $this->fail("PHP version $phpVersion is below required 8.1.0");
        }
        
        // Check required extensions
        $requiredExtensions = ['pdo', 'pdo_pgsql', 'json', 'mbstring', 'curl'];
        foreach ($requiredExtensions as $ext) {
            if (extension_loaded($ext)) {
                $this->pass("Extension '$ext' loaded");
            } else {
                $this->fail("Extension '$ext' not loaded");
            }
        }
        
        // Check disk space
        $freeSpace = disk_free_space(__DIR__);
        $totalSpace = disk_total_space(__DIR__);
        $freeSpaceGB = round($freeSpace / 1024 / 1024 / 1024, 2);
        $totalSpaceGB = round($totalSpace / 1024 / 1024 / 1024, 2);
        $percentFree = round(($freeSpace / $totalSpace) * 100, 2);
        
        $this->info("Disk space: {$freeSpaceGB}GB free of {$totalSpaceGB}GB ({$percentFree}%)");
        
        if ($percentFree < 10) {
            $this->warn("Low disk space - less than 10% free");
        } else {
            $this->pass("Sufficient disk space available");
        }
        
        // Check memory limit
        $memoryLimit = ini_get('memory_limit');
        $this->info("PHP memory limit: $memoryLimit");
        
        // Check max execution time
        $maxExecutionTime = ini_get('max_execution_time');
        $this->info("Max execution time: {$maxExecutionTime}s");
    }
    
    private function getDirectorySize($dir)
    {
        $size = 0;
        if (is_dir($dir)) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $size += $file->getSize();
            }
        }
        return $size;
    }
    
    private function printTestHeader($title)
    {
        echo "\n";
        echo "┌─────────────────────────────────────────────────────────────┐\n";
        echo "│ " . str_pad($title, 59) . " │\n";
        echo "└─────────────────────────────────────────────────────────────┘\n";
    }
    
    private function pass($message)
    {
        echo "  ✓ \033[32m$message\033[0m\n";
        $this->results['pass'][] = $message;
    }
    
    private function fail($message)
    {
        echo "  ✗ \033[31m$message\033[0m\n";
        $this->results['fail'][] = $message;
    }
    
    private function warn($message)
    {
        echo "  ⚠ \033[33m$message\033[0m\n";
        $this->results['warn'][] = $message;
    }
    
    private function info($message)
    {
        echo "  ℹ \033[36m$message\033[0m\n";
        $this->results['info'][] = $message;
    }
    
    private function skip($message)
    {
        echo "  ⊘ \033[90m$message\033[0m\n";
        $this->results['skip'][] = $message;
    }
    
    private function printSummary()
    {
        $duration = round(microtime(true) - $this->startTime, 2);
        
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║                      Test Summary                          ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n";
        
        $passed = count($this->results['pass'] ?? []);
        $failed = count($this->results['fail'] ?? []);
        $warnings = count($this->results['warn'] ?? []);
        $skipped = count($this->results['skip'] ?? []);
        
        echo "  \033[32mPassed:\033[0m   $passed\n";
        echo "  \033[31mFailed:\033[0m   $failed\n";
        echo "  \033[33mWarnings:\033[0m $warnings\n";
        echo "  \033[90mSkipped:\033[0m  $skipped\n";
        echo "\n";
        echo "  Duration: {$duration}s\n";
        echo "\n";
        
        if ($failed > 0) {
            echo "  \033[31m✗ TESTS FAILED\033[0m\n";
            echo "\n";
            echo "Failed tests:\n";
            foreach ($this->results['fail'] as $failure) {
                echo "  - $failure\n";
            }
            exit(1);
        } elseif ($warnings > 0) {
            echo "  \033[33m⚠ TESTS PASSED WITH WARNINGS\033[0m\n";
            exit(0);
        } else {
            echo "  \033[32m✓ ALL TESTS PASSED\033[0m\n";
            exit(0);
        }
    }
}

// Run tests
$test = new FinalSystemTest();
$test->run();
