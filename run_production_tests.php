<?php
/**
 * Run Production Tests for Warehouse Dashboard
 * 
 * This script runs comprehensive production tests for all warehouse dashboard features.
 * Tests all functionality mentioned in task 9.2: filters, sorting, CSV export, and pagination.
 * 
 * Usage: php run_production_tests.php
 */

echo "🚀 Starting Warehouse Dashboard Production Tests...\n\n";

// Include the production test class
require_once __DIR__ . '/tests/Production/WarehouseDashboardProductionTest.php';

// Run the tests
$tester = new WarehouseDashboardProductionTest();
$tester->runAllTests();

echo "\n✅ Production testing complete!\n";
?>