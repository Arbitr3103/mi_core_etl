#!/usr/bin/env php
<?php
/**
 * Test Monitoring System
 * 
 * Quick test to verify all monitoring components work
 */

echo "=== MDM Monitoring System Test ===\n\n";

$tests = [];
$passed = 0;
$failed = 0;

// Test 1: Config file
echo "1. Testing config file...\n";
if (file_exists('config.php')) {
    require_once 'config.php';
    if (defined('DB_HOST') && defined('DB_NAME')) {
        echo "   ✓ Config loaded successfully\n";
        $tests[] = ['name' => 'Config', 'status' => 'pass'];
        $passed++;
    } else {
        echo "   ✗ Config missing required constants\n";
        $tests[] = ['name' => 'Config', 'status' => 'fail'];
        $failed++;
    }
} else {
    echo "   ✗ config.php not found\n";
    $tests[] = ['name' => 'Config', 'status' => 'fail'];
    $failed++;
}

// Test 2: Database connection
echo "\n2. Testing database connection...\n";
try {
    if (isset($pdo)) {
        $stmt = $pdo->query("SELECT 1");
        echo "   ✓ Database connected\n";
        $tests[] = ['name' => 'Database', 'status' => 'pass'];
        $passed++;
    } else {
        echo "   ✗ PDO not initialized\n";
        $tests[] = ['name' => 'Database', 'status' => 'fail'];
        $failed++;
    }
} catch (Exception $e) {
    echo "   ✗ Database connection failed: " . $e->getMessage() . "\n";
    $tests[] = ['name' => 'Database', 'status' => 'fail'];
    $failed++;
}

// Test 3: DataQualityMonitor class
echo "\n3. Testing DataQualityMonitor class...\n";
if (file_exists('src/DataQualityMonitor.php')) {
    require_once 'src/DataQualityMonitor.php';
    try {
        $monitor = new DataQualityMonitor($pdo);
        echo "   ✓ DataQualityMonitor initialized\n";
        $tests[] = ['name' => 'DataQualityMonitor', 'status' => 'pass'];
        $passed++;
    } catch (Exception $e) {
        echo "   ✗ Failed to initialize: " . $e->getMessage() . "\n";
        $tests[] = ['name' => 'DataQualityMonitor', 'status' => 'fail'];
        $failed++;
    }
} else {
    echo "   ✗ DataQualityMonitor.php not found\n";
    $tests[] = ['name' => 'DataQualityMonitor', 'status' => 'fail'];
    $failed++;
}

// Test 4: Get metrics
echo "\n4. Testing metrics collection...\n";
try {
    if (isset($monitor)) {
        $metrics = $monitor->getQualityMetrics();
        if (isset($metrics['overall_score'])) {
            echo "   ✓ Metrics collected successfully\n";
            echo "   Overall Score: {$metrics['overall_score']}/100\n";
            $tests[] = ['name' => 'Metrics', 'status' => 'pass'];
            $passed++;
        } else {
            echo "   ✗ Metrics incomplete\n";
            $tests[] = ['name' => 'Metrics', 'status' => 'fail'];
            $failed++;
        }
    } else {
        echo "   ⊘ Skipped (monitor not initialized)\n";
        $tests[] = ['name' => 'Metrics', 'status' => 'skip'];
    }
} catch (Exception $e) {
    echo "   ✗ Failed: " . $e->getMessage() . "\n";
    $tests[] = ['name' => 'Metrics', 'status' => 'fail'];
    $failed++;
}

// Test 5: Alert handlers
echo "\n5. Testing alert handlers...\n";
if (file_exists('src/AlertHandlers.php')) {
    require_once 'src/AlertHandlers.php';
    try {
        $logHandler = new LogAlertHandler('logs/test_alert.log');
        echo "   ✓ Alert handlers loaded\n";
        $tests[] = ['name' => 'AlertHandlers', 'status' => 'pass'];
        $passed++;
    } catch (Exception $e) {
        echo "   ✗ Failed: " . $e->getMessage() . "\n";
        $tests[] = ['name' => 'AlertHandlers', 'status' => 'fail'];
        $failed++;
    }
} else {
    echo "   ✗ AlertHandlers.php not found\n";
    $tests[] = ['name' => 'AlertHandlers', 'status' => 'fail'];
    $failed++;
}

// Test 6: Monitoring script
echo "\n6. Testing monitoring script...\n";
if (file_exists('monitor_data_quality.php') && is_executable('monitor_data_quality.php')) {
    echo "   ✓ Monitoring script exists and is executable\n";
    $tests[] = ['name' => 'MonitorScript', 'status' => 'pass'];
    $passed++;
} else {
    echo "   ✗ Monitoring script not found or not executable\n";
    $tests[] = ['name' => 'MonitorScript', 'status' => 'fail'];
    $failed++;
}

// Test 7: Dashboard file
echo "\n7. Testing dashboard file...\n";
if (file_exists('html/quality_dashboard.php')) {
    echo "   ✓ Dashboard file exists\n";
    $tests[] = ['name' => 'Dashboard', 'status' => 'pass'];
    $passed++;
} else {
    echo "   ✗ Dashboard file not found\n";
    $tests[] = ['name' => 'Dashboard', 'status' => 'fail'];
    $failed++;
}

// Test 8: API endpoint
echo "\n8. Testing API endpoint...\n";
if (file_exists('api/quality-metrics.php')) {
    echo "   ✓ API endpoint exists\n";
    $tests[] = ['name' => 'API', 'status' => 'pass'];
    $passed++;
} else {
    echo "   ✗ API endpoint not found\n";
    $tests[] = ['name' => 'API', 'status' => 'fail'];
    $failed++;
}

// Test 9: Documentation
echo "\n9. Testing documentation...\n";
$docs = [
    'docs/MDM_DATABASE_SCHEMA_CHANGES.md',
    'docs/MDM_CLASSES_USAGE_GUIDE.md',
    'docs/MDM_TROUBLESHOOTING_GUIDE.md',
    'docs/MDM_MONITORING_GUIDE.md',
    'docs/MONITORING_SYSTEM_README.md'
];
$docsFound = 0;
foreach ($docs as $doc) {
    if (file_exists($doc)) {
        $docsFound++;
    }
}
if ($docsFound === count($docs)) {
    echo "   ✓ All documentation files present ($docsFound/5)\n";
    $tests[] = ['name' => 'Documentation', 'status' => 'pass'];
    $passed++;
} else {
    echo "   ⚠ Some documentation missing ($docsFound/5)\n";
    $tests[] = ['name' => 'Documentation', 'status' => 'partial'];
}

// Test 10: Test suite
echo "\n10. Testing test suite...\n";
if (file_exists('tests/test_data_quality_monitoring.php')) {
    echo "   ✓ Test suite exists\n";
    $tests[] = ['name' => 'TestSuite', 'status' => 'pass'];
    $passed++;
} else {
    echo "   ✗ Test suite not found\n";
    $tests[] = ['name' => 'TestSuite', 'status' => 'fail'];
    $failed++;
}

// Summary
echo "\n" . str_repeat('=', 50) . "\n";
echo "TEST SUMMARY\n";
echo str_repeat('=', 50) . "\n";
echo "Total tests: " . count($tests) . "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

if ($failed === 0) {
    echo "\n✅ ALL TESTS PASSED - System ready for deployment!\n";
    exit(0);
} else {
    echo "\n⚠️  SOME TESTS FAILED - Please review errors above\n";
    exit(1);
}
