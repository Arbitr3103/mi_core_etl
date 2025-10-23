<?php
/**
 * Inventory Controller for mi_core_etl
 * Unified API controller for inventory management with enhanced features
 */

require_once __DIR__ . '/../utils/Database.php';
require_once __DIR__ . '/../utils/Logger.php';
require_once __DIR__ . '/../services/CacheService.php';
require_once __DIR__ . '/../models/Product.php';
require_once __DIR__ . '/../models/Inventory.php';
require_once __DIR__ . '/../models/Warehouse.php';

class InventoryController {
    private $db;
    private $logger;
    private $cache;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->logger = Logger::getInstance();
        $this->cache = CacheService::getInstance();
    }

    /**
     * Get dashboard data for React frontend with caching
     */
    public function getDashboardData($limit = null) {
        $startTime = microtime(true);
        
        try {
            $cacheKey = "dashboard_data_" . ($limit ?? 'all');
            
            // Try to get from cache first
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                $this->logger->info('Dashboard data served from cache', [
                    'limit' => $limit,
                    'cache_key' => $cacheKey
                ]);
                return $cachedData;
            }

            $data = [
                'critical_products' => $this->getProductsByStockStatus('critical', $limit),
                'low_stock_products' => $this->getProductsByStockStatus('low_stock', $limit),
                'overstock_products' => $this->getProductsByStockStatus('overstock', $limit),
                'last_updated' => date('Y-m-d H:i:s'),
                'total_products' => $this->getTotalProductCount()
            ];

            // Cache for 5 minutes
            $this->cache->set($cacheKey, $data, 300);
            
            $duration = microtime(true) - $startTime;
            $this->logger->performance('getDashboardData', $duration, [
                'limit' => $limit,
                'critical_count' => $data['critical_products']['count'],
                'low_stock_count' => $data['low_stock_products']['count'],
                'overstock_count' => $data['overstock_products']['count'],
                'total_products' => $data['total_products']
            ]);

            return $data;

        } catch (Exception $e) {
            $this->logger->error('Dashboard data error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'limit' => $limit
            ]);
            throw $e;
        }
    }

    /**
     * Get products by stock status
     */
    private function getProductsByStockStatus($status, $limit = null) {
        $whereClause = $this->getStockStatusWhereClause($status);
        
        $sql = "
            SELECT 
                p.id,
                p.sku_ozon as sku,
                p.product_name as name,
                i.quantity_present as current_stock,
                i.quantity_present as available_stock,
                i.quantity_reserved as reserved_stock,
                i.warehouse_name,
                CASE 
                    WHEN i.quantity_present <= 5 THEN 'critical'
                    WHEN i.quantity_present <= 20 THEN 'low_stock'
                    WHEN i.quantity_present > 100 THEN 'overstock'
                    ELSE 'normal'
                END as stock_status,
                i.updated_at as last_updated,
                p.cost_price as price,
                'Auto Parts' as category
            FROM dim_products p
            JOIN inventory i ON p.id = i.product_id
            WHERE p.sku_ozon IS NOT NULL 
            AND ($whereClause)
            ORDER BY
                CASE 
                    WHEN i.quantity_present <= 5 THEN 1
                    WHEN i.quantity_present <= 20 THEN 2
                    WHEN i.quantity_present > 100 THEN 3
                    ELSE 4
                END,
                i.quantity_present ASC,
                p.product_name
        ";

        if ($limit) {
            $sql .= " LIMIT " . intval($limit);
        }

        $items = $this->db->fetchAll($sql);
        
        // Get total count for this status
        $countSql = "
            SELECT COUNT(*) as count
            FROM dim_products p
            JOIN inventory i ON p.id = i.product_id
            WHERE p.sku_ozon IS NOT NULL 
            AND ($whereClause)
        ";
        
        $countResult = $this->db->fetchOne($countSql);
        $totalCount = $countResult['count'] ?? 0;

        return [
            'count' => $totalCount,
            'items' => $items
        ];
    }

    /**
     * Get WHERE clause for stock status
     */
    private function getStockStatusWhereClause($status) {
        switch ($status) {
            case 'critical':
                return 'i.quantity_present <= 5';
            case 'low_stock':
                return 'i.quantity_present > 5 AND i.quantity_present <= 20';
            case 'overstock':
                return 'i.quantity_present > 100';
            default:
                return 'i.quantity_present > 20 AND i.quantity_present <= 100';
        }
    }

    /**
     * Get total product count
     */
    private function getTotalProductCount() {
        $sql = "
            SELECT COUNT(*) as count
            FROM dim_products p
            JOIN inventory i ON p.id = i.product_id
            WHERE p.sku_ozon IS NOT NULL
        ";
        
        $result = $this->db->fetchOne($sql);
        return $result['count'] ?? 0;
    }

    /**
     * Get product details by SKU with enhanced data
     */
    public function getProductBySku($sku) {
        $startTime = microtime(true);
        
        try {
            $cacheKey = "product_details_" . $sku;
            
            // Try cache first
            $cachedProduct = $this->cache->get($cacheKey);
            if ($cachedProduct !== null) {
                $this->logger->info('Product details served from cache', ['sku' => $sku]);
                return $cachedProduct;
            }

            $sql = "
                SELECT 
                    p.id,
                    p.sku_ozon as sku,
                    p.product_name as name,
                    p.cost_price as price,
                    p.margin_percent,
                    i.quantity_present as current_stock,
                    i.quantity_present as available_stock,
                    i.quantity_reserved as reserved_stock,
                    i.warehouse_name,
                    i.stock_type,
                    i.source,
                    i.updated_at as last_updated,
                    w.id as warehouse_id,
                    w.name as warehouse_full_name,
                    w.code as warehouse_code,
                    'Auto Parts' as category,
                    true as is_active
                FROM dim_products p
                LEFT JOIN inventory i ON p.id = i.product_id
                LEFT JOIN warehouses w ON i.warehouse_name = w.name
                WHERE p.sku_ozon = ?
                LIMIT 1
            ";

            $row = $this->db->fetchOne($sql, [$sku]);
            
            if (!$row) {
                $this->logger->warning('Product not found', ['sku' => $sku]);
                return null;
            }

            // Create Product model instance
            $product = Product::fromDatabase($row);
            $productData = $product->toArray();
            
            // Add inventory movements if available
            if ($row['id']) {
                $productData['recent_movements'] = $this->getStockMovements($row['id'], 10);
            }

            // Cache for 10 minutes
            $this->cache->set($cacheKey, $productData, 600);
            
            $duration = microtime(true) - $startTime;
            $this->logger->performance('getProductBySku', $duration, ['sku' => $sku]);

            return $productData;

        } catch (Exception $e) {
            $this->logger->error('Product details error', [
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get inventory analytics
     */
    public function getInventoryAnalytics() {
        $sql = "
            SELECT 
                COUNT(*) as total_products,
                SUM(CASE WHEN i.quantity_present <= 5 THEN 1 ELSE 0 END) as critical_count,
                SUM(CASE WHEN i.quantity_present > 5 AND i.quantity_present <= 20 THEN 1 ELSE 0 END) as low_stock_count,
                SUM(CASE WHEN i.quantity_present > 100 THEN 1 ELSE 0 END) as overstock_count,
                SUM(CASE WHEN i.quantity_present > 20 AND i.quantity_present <= 100 THEN 1 ELSE 0 END) as normal_count,
                AVG(i.quantity_present) as avg_stock_level,
                SUM(i.quantity_present * COALESCE(p.cost_price, 0)) as total_inventory_value
            FROM dim_products p
            JOIN inventory i ON p.id = i.product_id
            WHERE p.sku_ozon IS NOT NULL
        ";

        return $this->db->fetchOne($sql);
    }

    /**
     * Get stock movements for a product
     */
    public function getStockMovements($productId, $limit = 50) {
        $sql = "
            SELECT 
                sm.movement_id,
                sm.movement_date,
                sm.movement_type,
                sm.quantity,
                sm.warehouse_name,
                sm.order_id,
                sm.source,
                p.sku_ozon as sku,
                p.product_name
            FROM stock_movements sm
            JOIN dim_products p ON sm.product_id = p.id
            WHERE sm.product_id = ?
            ORDER BY sm.movement_date DESC
            LIMIT ?
        ";

        return $this->db->fetchAll($sql, [$productId, $limit]);
    }

    /**
     * Update inventory quantity
     */
    public function updateInventoryQuantity($productId, $warehouseName, $quantity, $source = 'manual') {
        try {
            $this->db->beginTransaction();

            // Check if inventory record exists
            $existingSql = "
                SELECT id FROM inventory 
                WHERE product_id = ? AND warehouse_name = ? AND source = ?
            ";
            
            $existing = $this->db->fetchOne($existingSql, [$productId, $warehouseName, $source]);

            if ($existing) {
                // Update existing record
                $updateSql = "
                    UPDATE inventory 
                    SET quantity_present = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ";
                
                $this->db->execute($updateSql, [$quantity, $existing['id']]);
            } else {
                // Insert new record
                $insertSql = "
                    INSERT INTO inventory (product_id, warehouse_name, quantity_present, source, stock_type)
                    VALUES (?, ?, ?, ?, 'FBO')
                ";
                
                $this->db->execute($insertSql, [$productId, $warehouseName, $quantity, $source]);
            }

            // Record stock movement
            $movementSql = "
                INSERT INTO stock_movements (
                    movement_id, product_id, movement_date, movement_type, 
                    quantity, warehouse_name, source
                ) VALUES (?, ?, CURRENT_TIMESTAMP, 'manual_adjustment', ?, ?, ?)
            ";
            
            $movementId = 'MANUAL_' . time() . '_' . $productId;
            $this->db->execute($movementSql, [
                $movementId, $productId, $quantity, $warehouseName, $source
            ]);

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * Health check for inventory system
     */
    public function healthCheck() {
        try {
            $checks = [];

            // Database connection test
            $checks['database'] = $this->db->testConnection() ? 'ok' : 'error';

            // Table existence checks
            $requiredTables = ['dim_products', 'inventory', 'stock_movements'];
            foreach ($requiredTables as $table) {
                $checks["table_$table"] = $this->db->tableExists($table) ? 'ok' : 'error';
            }

            // Data integrity checks
            $checks['products_with_inventory'] = $this->db->getTableCount('inventory') > 0 ? 'ok' : 'warning';
            
            // Recent data check (updated within last 24 hours)
            $recentSql = "
                SELECT COUNT(*) as count 
                FROM inventory 
                WHERE updated_at > CURRENT_TIMESTAMP - INTERVAL '24 hours'
            ";
            
            $recentResult = $this->db->fetchOne($recentSql);
            $checks['recent_updates'] = ($recentResult['count'] ?? 0) > 0 ? 'ok' : 'warning';

            return [
                'status' => in_array('error', $checks) ? 'error' : 'ok',
                'checks' => $checks,
                'timestamp' => date('Y-m-d H:i:s')
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
}

    /**
     * Get warehouses list
     */
    public function getWarehouses() {
        try {
            $cacheKey = "warehouses_list";
            
            $cachedWarehouses = $this->cache->get($cacheKey);
            if ($cachedWarehouses !== null) {
                return $cachedWarehouses;
            }

            $sql = "
                SELECT 
                    id, name, code, address, city, country,
                    is_active, capacity, current_utilization,
                    contact_email, contact_phone, timezone,
                    created_at, updated_at
                FROM warehouses 
                WHERE is_active = true
                ORDER BY name
            ";

            $rows = $this->db->fetchAll($sql);
            $warehouses = Warehouse::collectionFromDatabase($rows);
            
            $warehousesData = array_map(function($warehouse) {
                return $warehouse->toArray();
            }, $warehouses);

            // Cache for 1 hour
            $this->cache->set($cacheKey, $warehousesData, 3600);

            return $warehousesData;

        } catch (Exception $e) {
            $this->logger->error('Warehouses list error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get inventory by warehouse
     */
    public function getInventoryByWarehouse($warehouseId, $limit = null) {
        try {
            $sql = "
                SELECT 
                    p.id, p.sku_ozon as sku, p.product_name as name,
                    i.quantity_present as current_stock,
                    i.quantity_present as available_stock,
                    i.quantity_reserved as reserved_stock,
                    i.warehouse_name, i.updated_at as last_updated,
                    p.cost_price as price, 'Auto Parts' as category,
                    w.name as warehouse_full_name
                FROM dim_products p
                JOIN inventory i ON p.id = i.product_id
                JOIN warehouses w ON i.warehouse_name = w.name
                WHERE w.id = ? AND p.sku_ozon IS NOT NULL
                ORDER BY i.quantity_present ASC, p.product_name
            ";

            if ($limit) {
                $sql .= " LIMIT " . intval($limit);
            }

            $rows = $this->db->fetchAll($sql, [$warehouseId]);
            $products = Product::collectionFromDatabase($rows);
            
            return array_map(function($product) {
                return $product->toArray();
            }, $products);

        } catch (Exception $e) {
            $this->logger->error('Inventory by warehouse error', [
                'warehouse_id' => $warehouseId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Search products by name or SKU
     */
    public function searchProducts($query, $limit = 20) {
        try {
            $searchTerm = '%' . $query . '%';
            
            $sql = "
                SELECT 
                    p.id, p.sku_ozon as sku, p.product_name as name,
                    i.quantity_present as current_stock,
                    i.quantity_present as available_stock,
                    i.quantity_reserved as reserved_stock,
                    i.warehouse_name, i.updated_at as last_updated,
                    p.cost_price as price, 'Auto Parts' as category
                FROM dim_products p
                LEFT JOIN inventory i ON p.id = i.product_id
                WHERE (p.product_name ILIKE ? OR p.sku_ozon ILIKE ?)
                AND p.sku_ozon IS NOT NULL
                ORDER BY 
                    CASE WHEN p.sku_ozon ILIKE ? THEN 1 ELSE 2 END,
                    p.product_name
                LIMIT ?
            ";

            $rows = $this->db->fetchAll($sql, [$searchTerm, $searchTerm, $searchTerm, $limit]);
            $products = Product::collectionFromDatabase($rows);
            
            return array_map(function($product) {
                return $product->toArray();
            }, $products);

        } catch (Exception $e) {
            $this->logger->error('Product search error', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get low stock alerts
     */
    public function getLowStockAlerts($threshold = 20) {
        try {
            $sql = "
                SELECT 
                    p.id, p.sku_ozon as sku, p.product_name as name,
                    i.quantity_present as current_stock,
                    i.warehouse_name, i.updated_at as last_updated,
                    p.cost_price as price
                FROM dim_products p
                JOIN inventory i ON p.id = i.product_id
                WHERE i.quantity_present <= ? AND p.sku_ozon IS NOT NULL
                ORDER BY i.quantity_present ASC, p.product_name
            ";

            $rows = $this->db->fetchAll($sql, [$threshold]);
            $products = Product::collectionFromDatabase($rows);
            
            return array_map(function($product) {
                return $product->toArray();
            }, $products);

        } catch (Exception $e) {
            $this->logger->error('Low stock alerts error', [
                'threshold' => $threshold,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk update inventory
     */
    public function bulkUpdateInventory($updates) {
        try {
            $this->db->beginTransaction();
            
            $successCount = 0;
            $errorCount = 0;
            $errors = [];

            foreach ($updates as $update) {
                try {
                    $this->updateInventoryQuantity(
                        $update['product_id'],
                        $update['warehouse_name'],
                        $update['quantity'],
                        $update['source'] ?? 'bulk_update'
                    );
                    $successCount++;
                } catch (Exception $e) {
                    $errorCount++;
                    $errors[] = [
                        'product_id' => $update['product_id'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            if ($errorCount === 0) {
                $this->db->commit();
                
                // Clear relevant caches
                $this->cache->delete('dashboard_data_all');
                $this->cache->delete('dashboard_data_10');
                
                $this->logger->info('Bulk inventory update completed', [
                    'success_count' => $successCount,
                    'total_updates' => count($updates)
                ]);
            } else {
                $this->db->rollback();
                
                $this->logger->warning('Bulk inventory update failed', [
                    'success_count' => $successCount,
                    'error_count' => $errorCount,
                    'errors' => $errors
                ]);
            }

            return [
                'success' => $errorCount === 0,
                'success_count' => $successCount,
                'error_count' => $errorCount,
                'errors' => $errors
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            $this->logger->error('Bulk inventory update error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Get inventory statistics
     */
    public function getInventoryStatistics() {
        try {
            $cacheKey = "inventory_statistics";
            
            $cachedStats = $this->cache->get($cacheKey);
            if ($cachedStats !== null) {
                return $cachedStats;
            }

            $analytics = $this->getInventoryAnalytics();
            
            // Additional statistics
            $warehouseStatsSql = "
                SELECT 
                    i.warehouse_name,
                    COUNT(*) as product_count,
                    SUM(i.quantity_present) as total_stock,
                    AVG(i.quantity_present) as avg_stock,
                    SUM(CASE WHEN i.quantity_present <= 5 THEN 1 ELSE 0 END) as critical_count
                FROM inventory i
                JOIN dim_products p ON i.product_id = p.id
                WHERE p.sku_ozon IS NOT NULL
                GROUP BY i.warehouse_name
                ORDER BY total_stock DESC
            ";

            $warehouseStats = $this->db->fetchAll($warehouseStatsSql);

            $stats = [
                'overview' => $analytics,
                'warehouse_breakdown' => $warehouseStats,
                'last_updated' => date('Y-m-d H:i:s')
            ];

            // Cache for 15 minutes
            $this->cache->set($cacheKey, $stats, 900);

            return $stats;

        } catch (Exception $e) {
            $this->logger->error('Inventory statistics error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Clear all inventory caches
     */
    public function clearCache() {
        try {
            $cacheKeys = [
                'dashboard_data_all',
                'dashboard_data_10',
                'inventory_statistics',
                'warehouses_list'
            ];

            foreach ($cacheKeys as $key) {
                $this->cache->delete($key);
            }

            $this->logger->info('Inventory caches cleared');
            
            return ['success' => true, 'message' => 'Caches cleared successfully'];

        } catch (Exception $e) {
            $this->logger->error('Cache clear error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
?>