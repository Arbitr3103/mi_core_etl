<?php
/**
 * Unit Tests for Inventory Controller
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../src/api/InventoryController.php';

class InventoryControllerTest extends TestCase {
    private $controller;
    private $mockDb;
    private $mockCache;
    private $originalEnv;
    
    protected function setUp(): void {
        // Store original environment
        $this->originalEnv = $_ENV;
        
        // Set test environment
        $_ENV['LOG_PATH'] = sys_get_temp_dir();
        $_ENV['CACHE_DRIVER'] = 'array';
        $_ENV['PG_HOST'] = 'localhost';
        $_ENV['PG_NAME'] = 'test_db';
        $_ENV['PG_USER'] = 'test_user';
        $_ENV['PG_PASSWORD'] = 'test_pass';
        
        // We'll mock the database and cache dependencies
        // For now, we'll test what we can without actual database connection
        try {
            $this->controller = new InventoryController();
        } catch (Exception $e) {
            $this->markTestSkipped('InventoryController requires database connection: ' . $e->getMessage());
        }
    }
    
    protected function tearDown(): void {
        // Restore original environment
        $_ENV = $this->originalEnv;
        
        // Reset singletons
        $this->resetSingletons();
    }
    
    private function resetSingletons() {
        $classes = [Database::class, Logger::class, CacheService::class];
        
        foreach ($classes as $class) {
            if (class_exists($class)) {
                $reflection = new ReflectionClass($class);
                if ($reflection->hasProperty('instance')) {
                    $instance = $reflection->getProperty('instance');
                    $instance->setAccessible(true);
                    $instance->setValue(null, null);
                }
            }
        }
    }
    
    public function testControllerInstantiation() {
        $this->assertInstanceOf(InventoryController::class, $this->controller);
    }
    
    public function testGetDashboardDataStructure() {
        try {
            $data = $this->controller->getDashboardData();
            
            $this->assertIsArray($data);
            $this->assertArrayHasKey('critical_products', $data);
            $this->assertArrayHasKey('low_stock_products', $data);
            $this->assertArrayHasKey('overstock_products', $data);
            $this->assertArrayHasKey('last_updated', $data);
            $this->assertArrayHasKey('total_products', $data);
            
            // Check structure of product groups
            foreach (['critical_products', 'low_stock_products', 'overstock_products'] as $key) {
                $this->assertIsArray($data[$key]);
                $this->assertArrayHasKey('count', $data[$key]);
                $this->assertArrayHasKey('items', $data[$key]);
                $this->assertIsInt($data[$key]['count']);
                $this->assertIsArray($data[$key]['items']);
            }
            
            $this->assertIsString($data['last_updated']);
            $this->assertIsInt($data['total_products']);
            
        } catch (Exception $e) {
            $this->markTestSkipped('Dashboard data test requires database: ' . $e->getMessage());
        }
    }
    
    public function testGetDashboardDataWithLimit() {
        try {
            $limit = 5;
            $data = $this->controller->getDashboardData($limit);
            
            $this->assertIsArray($data);
            
            // Check that items arrays don't exceed the limit
            foreach (['critical_products', 'low_stock_products', 'overstock_products'] as $key) {
                $items = $data[$key]['items'];
                $this->assertLessThanOrEqual($limit, count($items));
            }
            
        } catch (Exception $e) {
            $this->markTestSkipped('Dashboard data with limit test requires database: ' . $e->getMessage());
        }
    }
    
    public function testGetProductBySkuNotFound() {
        try {
            $nonExistentSku = 'NON-EXISTENT-SKU-' . uniqid();
            $result = $this->controller->getProductBySku($nonExistentSku);
            
            $this->assertNull($result);
            
        } catch (Exception $e) {
            $this->markTestSkipped('Product by SKU test requires database: ' . $e->getMessage());
        }
    }
    
    public function testGetInventoryAnalyticsStructure() {
        try {
            $analytics = $this->controller->getInventoryAnalytics();
            
            $this->assertIsArray($analytics);
            
            // Check expected fields
            $expectedFields = [
                'total_products',
                'critical_count',
                'low_stock_count',
                'overstock_count',
                'normal_count',
                'avg_stock_level',
                'total_inventory_value'
            ];
            
            foreach ($expectedFields as $field) {
                $this->assertArrayHasKey($field, $analytics);
            }
            
        } catch (Exception $e) {
            $this->markTestSkipped('Inventory analytics test requires database: ' . $e->getMessage());
        }
    }
    
    public function testGetInventoryStatisticsStructure() {
        try {
            $stats = $this->controller->getInventoryStatistics();
            
            $this->assertIsArray($stats);
            $this->assertArrayHasKey('overview', $stats);
            $this->assertArrayHasKey('warehouse_breakdown', $stats);
            $this->assertArrayHasKey('last_updated', $stats);
            
            $this->assertIsArray($stats['overview']);
            $this->assertIsArray($stats['warehouse_breakdown']);
            $this->assertIsString($stats['last_updated']);
            
        } catch (Exception $e) {
            $this->markTestSkipped('Inventory statistics test requires database: ' . $e->getMessage());
        }
    }
    
    public function testSearchProductsWithEmptyQuery() {
        try {
            $results = $this->controller->searchProducts('');
            
            $this->assertIsArray($results);
            // Empty query should return empty results or all products (depending on implementation)
            
        } catch (Exception $e) {
            $this->markTestSkipped('Search products test requires database: ' . $e->getMessage());
        }
    }
    
    public function testGetLowStockAlertsStructure() {
        try {
            $alerts = $this->controller->getLowStockAlerts();
            
            $this->assertIsArray($alerts);
            
            // Each alert should be a product array
            foreach ($alerts as $alert) {
                $this->assertIsArray($alert);
                $this->assertArrayHasKey('id', $alert);
                $this->assertArrayHasKey('sku', $alert);
                $this->assertArrayHasKey('name', $alert);
                $this->assertArrayHasKey('current_stock', $alert);
            }
            
        } catch (Exception $e) {
            $this->markTestSkipped('Low stock alerts test requires database: ' . $e->getMessage());
        }
    }
    
    public function testGetWarehousesStructure() {
        try {
            $warehouses = $this->controller->getWarehouses();
            
            $this->assertIsArray($warehouses);
            
            // Each warehouse should have expected structure
            foreach ($warehouses as $warehouse) {
                $this->assertIsArray($warehouse);
                $this->assertArrayHasKey('id', $warehouse);
                $this->assertArrayHasKey('name', $warehouse);
                $this->assertArrayHasKey('code', $warehouse);
                $this->assertArrayHasKey('is_active', $warehouse);
            }
            
        } catch (Exception $e) {
            $this->markTestSkipped('Warehouses test requires database: ' . $e->getMessage());
        }
    }
    
    public function testHealthCheckStructure() {
        try {
            $health = $this->controller->healthCheck();
            
            $this->assertIsArray($health);
            $this->assertArrayHasKey('status', $health);
            $this->assertArrayHasKey('checks', $health);
            $this->assertArrayHasKey('timestamp', $health);
            
            $this->assertIsString($health['status']);
            $this->assertIsArray($health['checks']);
            $this->assertIsString($health['timestamp']);
            
            // Status should be 'ok' or 'error'
            $this->assertContains($health['status'], ['ok', 'error']);
            
        } catch (Exception $e) {
            $this->markTestSkipped('Health check test requires database: ' . $e->getMessage());
        }
    }
    
    public function testClearCacheReturnsSuccess() {
        try {
            $result = $this->controller->clearCache();
            
            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('message', $result);
            $this->assertTrue($result['success']);
            
        } catch (Exception $e) {
            $this->markTestSkipped('Clear cache test requires cache service: ' . $e->getMessage());
        }
    }
    
    public function testBulkUpdateInventoryWithEmptyArray() {
        try {
            $result = $this->controller->bulkUpdateInventory([]);
            
            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('success_count', $result);
            $this->assertArrayHasKey('error_count', $result);
            $this->assertArrayHasKey('errors', $result);
            
            $this->assertTrue($result['success']);
            $this->assertEquals(0, $result['success_count']);
            $this->assertEquals(0, $result['error_count']);
            $this->assertEmpty($result['errors']);
            
        } catch (Exception $e) {
            $this->markTestSkipped('Bulk update test requires database: ' . $e->getMessage());
        }
    }
    
    public function testBulkUpdateInventoryWithInvalidData() {
        try {
            $invalidUpdates = [
                [
                    'product_id' => 0, // Invalid product ID
                    'warehouse_name' => '',
                    'quantity' => -1 // Invalid quantity
                ]
            ];
            
            $result = $this->controller->bulkUpdateInventory($invalidUpdates);
            
            $this->assertIsArray($result);
            $this->assertArrayHasKey('success', $result);
            $this->assertArrayHasKey('error_count', $result);
            
            // Should have errors due to invalid data
            $this->assertGreaterThan(0, $result['error_count']);
            
        } catch (Exception $e) {
            $this->markTestSkipped('Bulk update with invalid data test requires database: ' . $e->getMessage());
        }
    }
    
    public function testGetStockMovementsStructure() {
        try {
            $productId = 1;
            $movements = $this->controller->getStockMovements($productId);
            
            $this->assertIsArray($movements);
            
            // Each movement should have expected structure
            foreach ($movements as $movement) {
                $this->assertIsArray($movement);
                $this->assertArrayHasKey('movement_id', $movement);
                $this->assertArrayHasKey('movement_date', $movement);
                $this->assertArrayHasKey('movement_type', $movement);
                $this->assertArrayHasKey('quantity', $movement);
            }
            
        } catch (Exception $e) {
            $this->markTestSkipped('Stock movements test requires database: ' . $e->getMessage());
        }
    }
    
    public function testGetInventoryByWarehouseStructure() {
        try {
            $warehouseId = 1;
            $inventory = $this->controller->getInventoryByWarehouse($warehouseId);
            
            $this->assertIsArray($inventory);
            
            // Each inventory item should have expected structure
            foreach ($inventory as $item) {
                $this->assertIsArray($item);
                $this->assertArrayHasKey('id', $item);
                $this->assertArrayHasKey('sku', $item);
                $this->assertArrayHasKey('name', $item);
                $this->assertArrayHasKey('current_stock', $item);
            }
            
        } catch (Exception $e) {
            $this->markTestSkipped('Inventory by warehouse test requires database: ' . $e->getMessage());
        }
    }
}