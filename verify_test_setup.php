<?php
/**
 * Test Setup Verification Script
 * 
 * Verifies that all test files are properly created and configured.
 */

echo "========================================\n";
echo "MDM Sync Engine Test Setup Verification\n";
echo "========================================\n\n";

$checks = [
    'passed' => 0,
    'failed' => 0,
    'warnings' => 0
];

// Check 1: Test files exist
echo "1. Checking test files...\n";
$testFiles = [
    'tests/bootstrap.php',
    'tests/SafeSyncEngineTest.php',
    'tests/FallbackDataProviderTest.php',
    'tests/DataTypeNormalizerTest.php',
    'tests/SyncIntegrationTest.php',
    'tests/README.md'
];

foreach ($testFiles as $file) {
    if (file_exists($file)) {
        echo "   ✅ {$file}\n";
        $checks['passed']++;
    } else {
        echo "   ❌ {$file} - NOT FOUND\n";
        $checks['failed']++;
    }
}

// Check 2: Source files exist
echo "\n2. Checking source files...\n";
$sourceFiles = [
    'src/SafeSyncEngine.php',
    'src/FallbackDataProvider.php',
    'src/DataTypeNormalizer.php'
];

foreach ($sourceFiles as $file) {
    if (file_exists($file)) {
        echo "   ✅ {$file}\n";
        $checks['passed']++;
    } else {
        echo "   ❌ {$file} - NOT FOUND\n";
        $checks['failed']++;
    }
}

// Check 3: PHPUnit configuration
echo "\n3. Checking PHPUnit configuration...\n";
if (file_exists('phpunit.xml')) {
    echo "   ✅ phpunit.xml\n";
    $checks['passed']++;
    
    // Validate XML
    $xml = @simplexml_load_file('phpunit.xml');
    if ($xml) {
        echo "   ✅ phpunit.xml is valid XML\n";
        $checks['passed']++;
    } else {
        echo "   ❌ phpunit.xml is invalid XML\n";
        $checks['failed']++;
    }
} else {
    echo "   ❌ phpunit.xml - NOT FOUND\n";
    $checks['failed']++;
}

// Check 4: PHP version
echo "\n4. Checking PHP version...\n";
$phpVersion = PHP_VERSION;
echo "   PHP Version: {$phpVersion}\n";
if (version_compare($phpVersion, '7.4.0', '>=')) {
    echo "   ✅ PHP version is compatible (>= 7.4.0)\n";
    $checks['passed']++;
} else {
    echo "   ❌ PHP version is too old (requires >= 7.4.0)\n";
    $checks['failed']++;
}

// Check 5: Required PHP extensions
echo "\n5. Checking PHP extensions...\n";
$requiredExtensions = ['pdo', 'pdo_sqlite', 'json', 'curl'];
foreach ($requiredExtensions as $ext) {
    if (extension_loaded($ext)) {
        echo "   ✅ {$ext}\n";
        $checks['passed']++;
    } else {
        echo "   ⚠️  {$ext} - NOT LOADED (may be required for some tests)\n";
        $checks['warnings']++;
    }
}

// Check 6: PHPUnit availability
echo "\n6. Checking PHPUnit...\n";
$phpunitPaths = [
    'vendor/bin/phpunit',
    'vendor/phpunit/phpunit/phpunit'
];

$phpunitFound = false;
foreach ($phpunitPaths as $path) {
    if (file_exists($path)) {
        echo "   ✅ PHPUnit found at: {$path}\n";
        $phpunitFound = true;
        $checks['passed']++;
        break;
    }
}

if (!$phpunitFound) {
    echo "   ⚠️  PHPUnit not found. Install with: composer require --dev phpunit/phpunit\n";
    $checks['warnings']++;
}

// Check 7: Test runner script
echo "\n7. Checking test runner...\n";
if (file_exists('run_sync_tests.php')) {
    echo "   ✅ run_sync_tests.php\n";
    $checks['passed']++;
} else {
    echo "   ❌ run_sync_tests.php - NOT FOUND\n";
    $checks['failed']++;
}

// Check 8: Directory structure
echo "\n8. Checking directory structure...\n";
$directories = [
    'src' => 'Source files',
    'tests' => 'Test files',
];

foreach ($directories as $dir => $description) {
    if (is_dir($dir)) {
        echo "   ✅ {$dir}/ - {$description}\n";
        $checks['passed']++;
    } else {
        echo "   ❌ {$dir}/ - NOT FOUND\n";
        $checks['failed']++;
    }
}

// Check 9: Test file syntax
echo "\n9. Checking test file syntax...\n";
foreach ($testFiles as $file) {
    if (file_exists($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        $output = [];
        $returnCode = 0;
        exec("php -l {$file} 2>&1", $output, $returnCode);
        
        if ($returnCode === 0) {
            echo "   ✅ {$file} - Syntax OK\n";
            $checks['passed']++;
        } else {
            echo "   ❌ {$file} - Syntax Error\n";
            $checks['failed']++;
        }
    }
}

// Check 10: Count test methods
echo "\n10. Counting test methods...\n";
$totalTests = 0;
foreach ($testFiles as $file) {
    if (file_exists($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
        $content = file_get_contents($file);
        preg_match_all('/public function test\w+\(/', $content, $matches);
        $count = count($matches[0]);
        if ($count > 0) {
            echo "   ✅ " . basename($file) . " - {$count} test methods\n";
            $totalTests += $count;
            $checks['passed']++;
        }
    }
}
echo "   Total test methods: {$totalTests}\n";

// Summary
echo "\n========================================\n";
echo "VERIFICATION SUMMARY\n";
echo "========================================\n";
echo "✅ Passed:   {$checks['passed']}\n";
echo "❌ Failed:   {$checks['failed']}\n";
echo "⚠️  Warnings: {$checks['warnings']}\n";
echo "========================================\n\n";

if ($checks['failed'] === 0) {
    echo "✅ All checks passed! Test setup is complete.\n\n";
    echo "Next steps:\n";
    if (!$phpunitFound) {
        echo "1. Install PHPUnit: composer require --dev phpunit/phpunit\n";
    }
    echo "2. Run tests: vendor/bin/phpunit\n";
    echo "3. Or use: php run_sync_tests.php\n";
} else {
    echo "❌ Some checks failed. Please fix the issues above.\n";
    exit(1);
}

echo "\n";
