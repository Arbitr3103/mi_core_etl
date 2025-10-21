<?php
/**
 * Test Runner for Active Products Filter API Tests
 * Runs all tests related to the active products filter functionality
 */

echo "🚀 Active Products Filter Test Suite\n";
echo "====================================\n\n";

$test_files = [
    'tests/test_active_products_filter_api.php' => 'API Endpoint Tests for Active Products Filter'
];

$total_passed = 0;
$total_tests = 0;
$start_time = microtime(true);

foreach ($test_files as $test_file => $description) {
    if (!file_exists($test_file)) {
        echo "❌ Test file not found: $test_file\n";
        continue;
    }
    
    echo "📋 Running: $description\n";
    echo "   File: $test_file\n";
    
    // Execute test
    $output = [];
    $return_code = 0;
    exec("php $test_file 2>&1", $output, $return_code);
    
    if ($return_code === 0) {
        echo "✅ PASSED\n";
        $total_passed++;
    } else {
        echo "❌ FAILED\n";
        echo "   Output:\n";
        foreach ($output as $line) {
            echo "   " . $line . "\n";
        }
    }
    
    $total_tests++;
    echo "\n";
}

$end_time = microtime(true);
$execution_time = round($end_time - $start_time, 2);

echo "====================================\n";
echo "📊 FINAL TEST RESULTS\n";
echo "====================================\n";
echo "Passed: $total_passed/$total_tests test suites\n";
echo "Total execution time: {$execution_time} seconds\n";

if ($total_passed === $total_tests) {
    echo "🎉 ALL TEST SUITES PASSED!\n";
    echo "\n✅ Active Products Filter functionality is working correctly:\n";
    echo "   • Dashboard filtering with active_only parameter\n";
    echo "   • Critical products filtering\n";
    echo "   • Overstock products filtering\n";
    echo "   • Warehouse summary filtering\n";
    echo "   • New activity monitoring endpoints\n";
    echo "   • Statistics calculations with active products only\n";
    echo "   • Backward compatibility maintained\n";
    exit(0);
} else {
    echo "⚠️ SOME TEST SUITES FAILED\n";
    exit(1);
}
?>