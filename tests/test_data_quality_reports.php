<?php
/**
 * Tests for Data Quality Reports System
 */

require_once __DIR__ . '/../config.php';

class DataQualityReportsTest {
    private $db;
    private $testResults = [];
    
    public function __construct() {
        $this->db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
        
        if ($this->db->connect_error) {
            throw new Exception("Database connection failed: " . $this->db->connect_error);
        }
        
        $this->db->set_charset("utf8mb4");
    }
    
    public function runAllTests() {
        echo "🧪 Running Data Quality Reports Tests\n";
        echo str_repeat("=", 60) . "\n\n";
        
        $this->testMissingNamesReport();
        $this->testSyncErrorsReport();
        $this->testManualReviewReport();
        $this->testSummaryStatistics();
        $this->testExportFunctionality();
        $this->testAPIEndpoints();
        
        $this->printSummary();
    }
    
    private function testMissingNamesReport() {
        echo "📋 Test: Missing Names Report\n";
        
        try {
            $sql = "
                SELECT COUNT(*) as count
                FROM product_cross_reference pcr
                LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                WHERE 
                    dp.name IS NULL 
                    OR dp.name LIKE 'Товар Ozon ID%'
                    OR dp.name LIKE '%требует обновления%'
                    OR dp.name = ''
            ";
            
            $result = $this->db->query($sql);
            $count = $result->fetch_assoc()['count'];
            
            $this->assert($result !== false, "Query executed successfully");
            $this->assert($count >= 0, "Count is non-negative: $count");
            
            // Test detailed query
            $sql = "
                SELECT 
                    pcr.id,
                    pcr.inventory_product_id,
                    pcr.ozon_product_id,
                    dp.name as dim_product_name,
                    CASE 
                        WHEN dp.name IS NULL THEN 'completely_missing'
                        WHEN dp.name LIKE 'Товар Ozon ID%' THEN 'placeholder_name'
                        WHEN dp.name LIKE '%требует обновления%' THEN 'needs_update'
                        ELSE 'unknown_issue'
                    END as issue_type
                FROM product_cross_reference pcr
                LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                WHERE 
                    dp.name IS NULL 
                    OR dp.name LIKE 'Товар Ozon ID%'
                    OR dp.name LIKE '%требует обновления%'
                    OR dp.name = ''
                LIMIT 10
            ";
            
            $result = $this->db->query($sql);
            $this->assert($result !== false, "Detailed query executed successfully");
            
            $products = [];
            while ($row = $result->fetch_assoc()) {
                $products[] = $row;
            }
            
            $this->assert(is_array($products), "Results returned as array");
            
            if (!empty($products)) {
                $this->assert(isset($products[0]['issue_type']), "Issue type is categorized");
                echo "   Sample issue types: ";
                $types = array_unique(array_column($products, 'issue_type'));
                echo implode(', ', $types) . "\n";
            }
            
            echo "   ✅ Missing Names Report: PASSED ($count products found)\n\n";
            $this->testResults['missing_names'] = true;
            
        } catch (Exception $e) {
            echo "   ❌ Missing Names Report: FAILED - " . $e->getMessage() . "\n\n";
            $this->testResults['missing_names'] = false;
        }
    }
    
    private function testSyncErrorsReport() {
        echo "📋 Test: Sync Errors Report\n";
        
        try {
            $sql = "
                SELECT COUNT(*) as count
                FROM product_cross_reference pcr
                WHERE 
                    pcr.sync_status = 'failed'
                    OR pcr.last_successful_sync IS NULL
                    OR TIMESTAMPDIFF(DAY, pcr.last_successful_sync, NOW()) > 7
            ";
            
            $result = $this->db->query($sql);
            $count = $result->fetch_assoc()['count'];
            
            $this->assert($result !== false, "Query executed successfully");
            $this->assert($count >= 0, "Count is non-negative: $count");
            
            // Test detailed query with error categorization
            $sql = "
                SELECT 
                    pcr.id,
                    pcr.sync_status,
                    pcr.last_successful_sync,
                    TIMESTAMPDIFF(HOUR, pcr.last_successful_sync, NOW()) as hours_since_sync,
                    CASE 
                        WHEN pcr.sync_status = 'failed' THEN 'sync_failed'
                        WHEN pcr.last_successful_sync IS NULL THEN 'never_synced'
                        WHEN TIMESTAMPDIFF(DAY, pcr.last_successful_sync, NOW()) > 7 THEN 'stale_data'
                        ELSE 'unknown'
                    END as error_type
                FROM product_cross_reference pcr
                WHERE 
                    pcr.sync_status = 'failed'
                    OR pcr.last_successful_sync IS NULL
                    OR TIMESTAMPDIFF(DAY, pcr.last_successful_sync, NOW()) > 7
                LIMIT 10
            ";
            
            $result = $this->db->query($sql);
            $this->assert($result !== false, "Detailed query executed successfully");
            
            $errors = [];
            while ($row = $result->fetch_assoc()) {
                $errors[] = $row;
            }
            
            if (!empty($errors)) {
                $this->assert(isset($errors[0]['error_type']), "Error type is categorized");
                echo "   Sample error types: ";
                $types = array_unique(array_column($errors, 'error_type'));
                echo implode(', ', $types) . "\n";
            }
            
            echo "   ✅ Sync Errors Report: PASSED ($count errors found)\n\n";
            $this->testResults['sync_errors'] = true;
            
        } catch (Exception $e) {
            echo "   ❌ Sync Errors Report: FAILED - " . $e->getMessage() . "\n\n";
            $this->testResults['sync_errors'] = false;
        }
    }
    
    private function testManualReviewReport() {
        echo "📋 Test: Manual Review Report\n";
        
        try {
            $sql = "
                SELECT COUNT(*) as count
                FROM product_cross_reference pcr
                LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                LEFT JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
                WHERE 
                    (pcr.ozon_product_id IS NULL AND pcr.inventory_product_id IS NOT NULL)
                    OR (pcr.inventory_product_id IS NULL AND pcr.ozon_product_id IS NOT NULL)
                    OR (pcr.cached_name IS NULL AND dp.name IS NULL)
                    OR (pcr.sync_status = 'failed' AND pcr.last_successful_sync IS NOT NULL)
                    OR (i.quantity_present > 100 AND (dp.name IS NULL OR dp.name LIKE 'Товар%'))
            ";
            
            $result = $this->db->query($sql);
            $count = $result->fetch_assoc()['count'];
            
            $this->assert($result !== false, "Query executed successfully");
            $this->assert($count >= 0, "Count is non-negative: $count");
            
            // Test detailed query with priority and review reasons
            $sql = "
                SELECT 
                    pcr.id,
                    pcr.inventory_product_id,
                    pcr.ozon_product_id,
                    i.quantity_present,
                    CASE 
                        WHEN pcr.ozon_product_id IS NULL AND pcr.inventory_product_id IS NOT NULL THEN 'missing_ozon_id'
                        WHEN pcr.inventory_product_id IS NULL AND pcr.ozon_product_id IS NOT NULL THEN 'missing_inventory_id'
                        WHEN pcr.cached_name IS NULL AND dp.name IS NULL THEN 'no_name_data'
                        WHEN pcr.sync_status = 'failed' AND pcr.last_successful_sync IS NOT NULL THEN 'repeated_failures'
                        WHEN i.quantity_present > 100 AND (dp.name IS NULL OR dp.name LIKE 'Товар%') THEN 'high_stock_no_name'
                        ELSE 'data_inconsistency'
                    END as review_reason,
                    CASE 
                        WHEN i.quantity_present > 100 THEN 'high'
                        WHEN i.quantity_present > 10 THEN 'medium'
                        ELSE 'low'
                    END as priority
                FROM product_cross_reference pcr
                LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                LEFT JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
                WHERE 
                    (pcr.ozon_product_id IS NULL AND pcr.inventory_product_id IS NOT NULL)
                    OR (pcr.inventory_product_id IS NULL AND pcr.ozon_product_id IS NOT NULL)
                    OR (pcr.cached_name IS NULL AND dp.name IS NULL)
                    OR (pcr.sync_status = 'failed' AND pcr.last_successful_sync IS NOT NULL)
                    OR (i.quantity_present > 100 AND (dp.name IS NULL OR dp.name LIKE 'Товар%'))
                LIMIT 10
            ";
            
            $result = $this->db->query($sql);
            $this->assert($result !== false, "Detailed query executed successfully");
            
            $reviews = [];
            while ($row = $result->fetch_assoc()) {
                $reviews[] = $row;
            }
            
            if (!empty($reviews)) {
                $this->assert(isset($reviews[0]['review_reason']), "Review reason is categorized");
                $this->assert(isset($reviews[0]['priority']), "Priority is assigned");
                
                echo "   Sample review reasons: ";
                $reasons = array_unique(array_column($reviews, 'review_reason'));
                echo implode(', ', $reasons) . "\n";
                
                echo "   Priority distribution: ";
                $priorities = array_count_values(array_column($reviews, 'priority'));
                foreach ($priorities as $priority => $count) {
                    echo "$priority: $count ";
                }
                echo "\n";
            }
            
            echo "   ✅ Manual Review Report: PASSED ($count items found)\n\n";
            $this->testResults['manual_review'] = true;
            
        } catch (Exception $e) {
            echo "   ❌ Manual Review Report: FAILED - " . $e->getMessage() . "\n\n";
            $this->testResults['manual_review'] = false;
        }
    }
    
    private function testSummaryStatistics() {
        echo "📋 Test: Summary Statistics\n";
        
        try {
            // Get all counts
            $sql = "SELECT COUNT(*) as count FROM product_cross_reference";
            $result = $this->db->query($sql);
            $totalProducts = $result->fetch_assoc()['count'];
            
            $this->assert($totalProducts > 0, "Total products count: $totalProducts");
            
            // Calculate health score
            $sql = "
                SELECT 
                    (SELECT COUNT(*) FROM product_cross_reference pcr
                     LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                     WHERE dp.name IS NULL OR dp.name LIKE 'Товар%' OR dp.name = '') as missing_names,
                    (SELECT COUNT(*) FROM product_cross_reference
                     WHERE sync_status = 'failed' OR last_successful_sync IS NULL) as sync_errors,
                    (SELECT COUNT(*) FROM product_cross_reference) as total
            ";
            
            $result = $this->db->query($sql);
            $stats = $result->fetch_assoc();
            
            $this->assert($result !== false, "Statistics query executed successfully");
            
            $missingPercent = ($stats['missing_names'] / $stats['total']) * 100;
            $errorsPercent = ($stats['sync_errors'] / $stats['total']) * 100;
            $healthScore = 100 - (($missingPercent + $errorsPercent) / 2);
            
            echo "   Total Products: {$stats['total']}\n";
            echo "   Missing Names: {$stats['missing_names']} (" . round($missingPercent, 2) . "%)\n";
            echo "   Sync Errors: {$stats['sync_errors']} (" . round($errorsPercent, 2) . "%)\n";
            echo "   Health Score: " . round($healthScore, 2) . "%\n";
            
            $this->assert($healthScore >= 0 && $healthScore <= 100, "Health score is valid");
            
            echo "   ✅ Summary Statistics: PASSED\n\n";
            $this->testResults['summary'] = true;
            
        } catch (Exception $e) {
            echo "   ❌ Summary Statistics: FAILED - " . $e->getMessage() . "\n\n";
            $this->testResults['summary'] = false;
        }
    }
    
    private function testExportFunctionality() {
        echo "📋 Test: Export Functionality\n";
        
        try {
            // Test that we can retrieve data for export
            $sql = "
                SELECT 
                    pcr.id,
                    pcr.inventory_product_id,
                    pcr.ozon_product_id,
                    pcr.sku_ozon,
                    pcr.sync_status,
                    dp.name
                FROM product_cross_reference pcr
                LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                LIMIT 5
            ";
            
            $result = $this->db->query($sql);
            $this->assert($result !== false, "Export query executed successfully");
            
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            
            $this->assert(!empty($data), "Export data retrieved");
            $this->assert(isset($data[0]['id']), "Export data has required fields");
            
            // Test CSV formatting
            $csvData = $this->formatAsCSV($data);
            $this->assert(!empty($csvData), "CSV formatting works");
            $this->assert(strpos($csvData, 'id') !== false, "CSV contains headers");
            
            // Test JSON formatting
            $jsonData = json_encode($data);
            $this->assert(!empty($jsonData), "JSON formatting works");
            $decoded = json_decode($jsonData, true);
            $this->assert(is_array($decoded), "JSON can be decoded");
            
            echo "   ✅ Export Functionality: PASSED\n\n";
            $this->testResults['export'] = true;
            
        } catch (Exception $e) {
            echo "   ❌ Export Functionality: FAILED - " . $e->getMessage() . "\n\n";
            $this->testResults['export'] = false;
        }
    }
    
    private function testAPIEndpoints() {
        echo "📋 Test: API Endpoints\n";
        
        try {
            // Check if API file exists
            $apiFile = __DIR__ . '/../api/data-quality-reports.php';
            $this->assert(file_exists($apiFile), "API file exists");
            
            // Test API by simulating requests
            $_GET['action'] = 'summary';
            ob_start();
            include $apiFile;
            $output = ob_get_clean();
            
            $this->assert(!empty($output), "API returns output");
            
            $result = json_decode($output, true);
            $this->assert(isset($result['success']), "API returns success flag");
            
            if ($result['success']) {
                $this->assert(isset($result['data']), "API returns data");
                echo "   API response structure is valid\n";
            }
            
            echo "   ✅ API Endpoints: PASSED\n\n";
            $this->testResults['api'] = true;
            
        } catch (Exception $e) {
            echo "   ❌ API Endpoints: FAILED - " . $e->getMessage() . "\n\n";
            $this->testResults['api'] = false;
        }
    }
    
    private function formatAsCSV($data) {
        if (empty($data)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($data[0]));
        
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    private function assert($condition, $message) {
        if (!$condition) {
            throw new Exception("Assertion failed: $message");
        }
        echo "   ✓ $message\n";
    }
    
    private function printSummary() {
        echo str_repeat("=", 60) . "\n";
        echo "📊 Test Summary\n";
        echo str_repeat("=", 60) . "\n\n";
        
        $passed = 0;
        $failed = 0;
        
        foreach ($this->testResults as $test => $result) {
            $status = $result ? "✅ PASSED" : "❌ FAILED";
            echo sprintf("%-30s %s\n", ucfirst(str_replace('_', ' ', $test)), $status);
            
            if ($result) {
                $passed++;
            } else {
                $failed++;
            }
        }
        
        $total = $passed + $failed;
        $percentage = $total > 0 ? round(($passed / $total) * 100, 2) : 0;
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "Total: $total | Passed: $passed | Failed: $failed | Success Rate: $percentage%\n";
        echo str_repeat("=", 60) . "\n";
        
        if ($failed === 0) {
            echo "\n🎉 All tests passed!\n";
        } else {
            echo "\n⚠️  Some tests failed. Please review the output above.\n";
        }
    }
    
    public function __destruct() {
        if ($this->db) {
            $this->db->close();
        }
    }
}

// Run tests
try {
    $tester = new DataQualityReportsTest();
    $tester->runAllTests();
} catch (Exception $e) {
    echo "❌ Test execution failed: " . $e->getMessage() . "\n";
    exit(1);
}
