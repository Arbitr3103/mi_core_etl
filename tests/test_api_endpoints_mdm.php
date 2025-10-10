<?php
/**
 * Test script for MDM-enhanced API endpoints
 * 
 * Tests that API endpoints correctly use product_cross_reference table
 * and implement fallback logic for product names.
 */

require_once __DIR__ . '/../config.php';

class APIEndpointTester {
    private $baseUrl;
    private $results = [];
    
    public function __construct($baseUrl = 'http://localhost') {
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    public function runAllTests() {
        echo "=== Testing MDM-Enhanced API Endpoints ===\n\n";
        
        $this->testAnalyticsAPI();
        $this->testAnalyticsEnhancedAPI();
        $this->testInventoryAPI();
        
        $this->printSummary();
    }
    
    private function testAnalyticsAPI() {
        echo "Testing Analytics API (api/analytics.php)...\n";
        
        $response = $this->makeRequest('/api/analytics.php');
        
        if ($response) {
            $data = json_decode($response, true);
            
            $this->assertField($data, 'total_products', 'Analytics: total_products');
            $this->assertField($data, 'products_with_real_names', 'Analytics: products_with_real_names (NEW)');
            $this->assertField($data, 'sync_status', 'Analytics: sync_status (NEW)');
            $this->assertField($data, 'data_quality_score', 'Analytics: data_quality_score');
            
            if (isset($data['sync_status'])) {
                echo "  ✓ Sync status breakdown: " . json_encode($data['sync_status']) . "\n";
            }
            
            if (isset($data['products_with_real_names'])) {
                $realNamesPercent = $data['total_products'] > 0 
                    ? round(($data['products_with_real_names'] / $data['total_products']) * 100, 2)
                    : 0;
                echo "  ✓ Real names coverage: {$realNamesPercent}%\n";
            }
        } else {
            echo "  ✗ Failed to get response from Analytics API\n";
        }
        
        echo "\n";
    }
    
    private function testAnalyticsEnhancedAPI() {
        echo "Testing Enhanced Analytics API (api/analytics-enhanced.php)...\n";
        
        // Test overview
        $response = $this->makeRequest('/api/analytics-enhanced.php?action=overview');
        if ($response) {
            $data = json_decode($response, true);
            $this->assertField($data, 'total_products', 'Enhanced Analytics: total_products');
            $this->assertField($data, 'products_with_real_names', 'Enhanced Analytics: products_with_real_names');
            echo "  ✓ Overview endpoint working\n";
        }
        
        // Test products list
        $response = $this->makeRequest('/api/analytics-enhanced.php?action=products&limit=10');
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['data']['products'])) {
                echo "  ✓ Products list endpoint working (" . count($data['data']['products']) . " products)\n";
                
                // Check if products have quality indicators
                if (!empty($data['data']['products'])) {
                    $firstProduct = $data['data']['products'][0];
                    if (isset($firstProduct['name_quality'])) {
                        echo "  ✓ Products have name_quality indicator: {$firstProduct['name_quality']}\n";
                    }
                    if (isset($firstProduct['sync_status'])) {
                        echo "  ✓ Products have sync_status: {$firstProduct['sync_status']}\n";
                    }
                }
            }
        }
        
        // Test sync status
        $response = $this->makeRequest('/api/analytics-enhanced.php?action=sync-status');
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['data']['sync_statistics'])) {
                echo "  ✓ Sync status endpoint working\n";
                $syncStats = $data['data']['sync_statistics'];
                echo "    - Synced: {$syncStats['synced']}/{$syncStats['total_products']} ({$syncStats['sync_percentage']}%)\n";
            }
            if (isset($data['data']['cache_statistics'])) {
                echo "  ✓ Cache statistics available\n";
                $cacheStats = $data['data']['cache_statistics'];
                echo "    - Cache hit rate: {$cacheStats['cache_hit_rate']}%\n";
            }
        }
        
        // Test quality metrics
        $response = $this->makeRequest('/api/analytics-enhanced.php?action=quality-metrics');
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['data']['quality_breakdown'])) {
                echo "  ✓ Quality metrics endpoint working\n";
                foreach ($data['data']['quality_breakdown'] as $quality) {
                    echo "    - {$quality['quality_level']}: {$quality['count']} products\n";
                }
            }
            if (isset($data['data']['total_issues'])) {
                echo "  ✓ Products needing attention: {$data['data']['total_issues']}\n";
            }
        }
        
        echo "\n";
    }
    
    private function testInventoryAPI() {
        echo "Testing Inventory API (api/inventory-v4.php)...\n";
        
        // Test products endpoint
        $response = $this->makeRequest('/api/inventory-v4.php?action=products&limit=10');
        if ($response) {
            $data = json_decode($response, true);
            
            if (isset($data['data']['products'])) {
                echo "  ✓ Products endpoint working (" . count($data['data']['products']) . " products)\n";
                
                // Check if products use cross-reference names
                if (!empty($data['data']['products'])) {
                    $firstProduct = $data['data']['products'][0];
                    if (isset($firstProduct['name_source'])) {
                        echo "  ✓ Products have name_source: {$firstProduct['name_source']}\n";
                    }
                    if (isset($firstProduct['sync_status'])) {
                        echo "  ✓ Products have sync_status from cross-reference\n";
                    }
                }
            }
        }
        
        // Test critical stock
        $response = $this->makeRequest('/api/inventory-v4.php?action=critical&threshold=5');
        if ($response) {
            $data = json_decode($response, true);
            
            if (isset($data['data']['critical_items'])) {
                echo "  ✓ Critical stock endpoint working (" . count($data['data']['critical_items']) . " items)\n";
                
                // Check if critical items use cross-reference names
                if (!empty($data['data']['critical_items'])) {
                    $firstItem = $data['data']['critical_items'][0];
                    if (isset($firstItem['sync_status'])) {
                        echo "  ✓ Critical items have sync_status from cross-reference\n";
                    }
                }
            }
        }
        
        // Test marketing analytics
        $response = $this->makeRequest('/api/inventory-v4.php?action=marketing');
        if ($response) {
            $data = json_decode($response, true);
            
            if (isset($data['data']['top_products'])) {
                echo "  ✓ Marketing analytics endpoint working\n";
                
                // Check if top products use cross-reference names
                if (!empty($data['data']['top_products'])) {
                    $firstProduct = $data['data']['top_products'][0];
                    if (isset($firstProduct['sync_status'])) {
                        echo "  ✓ Top products have sync_status from cross-reference\n";
                    }
                }
            }
        }
        
        echo "\n";
    }
    
    private function makeRequest($endpoint) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            echo "  ✗ CURL error: $error\n";
            return null;
        }
        
        if ($httpCode !== 200) {
            echo "  ✗ HTTP error: $httpCode\n";
            return null;
        }
        
        return $response;
    }
    
    private function assertField($data, $field, $description) {
        if (isset($data[$field])) {
            echo "  ✓ $description: " . (is_array($data[$field]) ? json_encode($data[$field]) : $data[$field]) . "\n";
            $this->results[] = ['test' => $description, 'status' => 'pass'];
        } else {
            echo "  ✗ $description: MISSING\n";
            $this->results[] = ['test' => $description, 'status' => 'fail'];
        }
    }
    
    private function printSummary() {
        echo "=== Test Summary ===\n";
        
        $passed = count(array_filter($this->results, function($r) { return $r['status'] === 'pass'; }));
        $failed = count(array_filter($this->results, function($r) { return $r['status'] === 'fail'; }));
        $total = count($this->results);
        
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
$tester = new APIEndpointTester();
$tester->runAllTests();
?>
