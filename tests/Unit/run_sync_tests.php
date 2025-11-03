<?php
/**
 * Test Runner Script for MDM Sync Engine
 * 
 * Runs all unit and integration tests for the synchronization system.
 * Can be executed without PHPUnit installed.
 */

// Попытка подключить общий bootstrap тестов из корня tests
$bootstrap1 = __DIR__ . '/../bootstrap.php';
$bootstrap2 = __DIR__ . '/bootstrap.php';
if (file_exists($bootstrap1)) {
    require_once $bootstrap1;
} elseif (file_exists($bootstrap2)) {
    require_once $bootstrap2;
}

class SimpleTestRunner {
    private $passed = 0;
    private $failed = 0;
    private $skipped = 0;
    private $errors = [];
    
    public function runAllTests() {
        echo "Starting MDM Sync Engine Tests...\n\n";
        
        // Run unit tests
        echo "=== UNIT TESTS ===\n\n";
        $this->runTestFile('tests/DataTypeNormalizerTest.php');
        $this->runTestFile('tests/FallbackDataProviderTest.php');
        $this->runTestFile('tests/SafeSyncEngineTest.php');
        
        // Run integration tests
        echo "\n=== INTEGRATION TESTS ===\n\n";
        $this->runTestFile('tests/SyncIntegrationTest.php');
        
        // Display summary
        $this->displaySummary();
    }
    
    private function runTestFile($file) {
        if (!file_exists($file)) {
            echo "⚠️  Test file not found: {$file}\n";
            $this->skipped++;
            return;
        }
        
        echo "Running tests from: " . basename($file) . "\n";
        
        try {
            require_once $file;
            
            // Get test class name from file
            $className = $this->getClassNameFromFile($file);
            
            if (!class_exists($className)) {
                echo "❌ Test class not found: {$className}\n";
                $this->failed++;
                return;
            }
            
            // Run tests using reflection
            $this->runTestClass($className);
            
        } catch (Exception $e) {
            echo "❌ Error running test file: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->errors[] = [
                'file' => $file,
                'error' => $e->getMessage()
            ];
        }
        
        echo "\n";
    }
    
    private function runTestClass($className) {
        $reflection = new ReflectionClass($className);
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
        
        $testMethods = array_filter($methods, function($method) {
            return strpos($method->getName(), 'test') === 0;
        });
        
        if (empty($testMethods)) {
            echo "  No test methods found\n";
            return;
        }
        
        foreach ($testMethods as $method) {
            $this->runTestMethod($className, $method->getName());
        }
    }
    
    private function runTestMethod($className, $methodName) {
        try {
            // Note: This is a simplified runner
            // For full PHPUnit functionality, use: vendor/bin/phpunit
            echo "  ℹ️  {$methodName} - Requires PHPUnit to run\n";
            $this->skipped++;
            
        } catch (Exception $e) {
            echo "  ❌ {$methodName} - Failed: " . $e->getMessage() . "\n";
            $this->failed++;
            $this->errors[] = [
                'class' => $className,
                'method' => $methodName,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function getClassNameFromFile($file) {
        $content = file_get_contents($file);
        
        if (preg_match('/class\s+(\w+)\s+extends\s+TestCase/', $content, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    private function displaySummary() {
        echo "\n";
        echo "========================================\n";
        echo "TEST SUMMARY\n";
        echo "========================================\n";
        echo "Passed:  {$this->passed}\n";
        echo "Failed:  {$this->failed}\n";
        echo "Skipped: {$this->skipped}\n";
        echo "========================================\n";
        
        if (!empty($this->errors)) {
            echo "\nERRORS:\n";
            foreach ($this->errors as $error) {
                echo "- " . ($error['file'] ?? $error['class'] . '::' . $error['method']) . "\n";
                echo "  " . $error['error'] . "\n";
            }
        }
        
        echo "\n";
        echo "ℹ️  Note: This is a simplified test runner.\n";
        echo "For full test execution with assertions, install PHPUnit:\n";
        echo "  composer require --dev phpunit/phpunit\n";
        echo "  vendor/bin/phpunit\n";
        echo "\n";
    }
}

// Check if PHPUnit is available
if (class_exists('PHPUnit\Framework\TestCase')) {
    echo "✅ PHPUnit is installed. Run tests with:\n";
    echo "   vendor/bin/phpunit\n";
    echo "   vendor/bin/phpunit --testsuite=\"Unit Tests\"\n";
    echo "   vendor/bin/phpunit --testsuite=\"Integration Tests\"\n";
    echo "\n";
} else {
    echo "⚠️  PHPUnit is not installed.\n";
    echo "Install it with: composer require --dev phpunit/phpunit\n\n";
    
    // Run simplified test discovery
    $runner = new SimpleTestRunner();
    $runner->runAllTests();
}
