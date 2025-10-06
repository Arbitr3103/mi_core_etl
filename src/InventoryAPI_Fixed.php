<?php
/**
 * Исправленный API класс для работы с остатками товаров
 * Адаптирован под реальную структуру таблицы inventory
 */

class InventoryAPI_Fixed {
    private $pdo;
    private $logFile = '/tmp/inventory_api.log';
    private $cacheDir = '/tmp/inventory_cache/';
    private $cacheTimeout = 300; // 5 минут
    
    public function __construct($host, $dbname, $username, $password) {
        // Создаем директорию для кэша
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        try {
            $this->pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к БД: " . $e->getMessage());
        }
    }
    
    /**
     * Логирование для отладки
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
    
    /**
     * Получить данные из кэша
     */
    private function getFromCache($key) {
        $cacheFile = $this->cacheDir . md5($key) . '.cache';
        
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTimeout) {
            $data = file_get_contents($cacheFile);
            return json_decode($data, true);
        }
        
        return null;
    }
    
    /**
     * Сохранить данные в кэш
     */
    private function saveToCache($key, $data) {
        $cacheFile = $this->cacheDir . md5($key) . '.cache';
        file_put_contents($cacheFile, json_encode($data));
    }
    
    /**
     * Очистить кэш
     */
    public function clearCache() {
        $files = glob($this->cacheDir . '*.cache');
        foreach ($files as $file) {
            unlink($file);
        }
        $this->log("Cache cleared");
    }
    
    /**
     * Получить сводную статистику по остаткам
     */
    public function getInventorySummary() {
        $cacheKey = "inventory_summary";
        
        // Проверяем кэш
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            $this->log("Inventory summary loaded from cache");
            return $cached;
        }
        
        $this->log("Getting inventory summary from database");
        
        $sql = "
            SELECT 
                i.source as marketplace,
                COUNT(DISTINCT i.product_id) as total_products,
                COUNT(DISTINCT i.warehouse_name) as total_warehouses,
                SUM(i.quantity_present) as total_quantity,
                SUM(COALESCE(i.quantity_reserved, 0)) as total_reserved,
                SUM(i.quantity_present - COALESCE(i.quantity_reserved, 0)) as total_available,
                SUM(i.quantity_present * COALESCE(dp.cost_price, 0)) as total_inventory_value,
                SUM(CASE WHEN i.quantity_present <= 5 THEN 1 ELSE 0 END) as critical_items,
                SUM(CASE WHEN i.quantity_present <= 20 THEN 1 ELSE 0 END) as low_stock_items
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.quantity_present > 0
            GROUP BY i.source
            ORDER BY total_inventory_value DESC
        ";
        
        $stmt = $this->pdo->query($sql);
        $result = $stmt->fetchAll();
        
        // Сохраняем в кэш
        $this->saveToCache($cacheKey, $result);
        
        $this->log("Inventory summary result: " . json_encode($result));
        return $result;
    }
    
    /**
     * Получить детальные остатки по маркетплейсу
     */
    public function getInventoryByMarketplace($marketplace = null, $warehouse = null, $search = null, $stockLevel = null, $limit = 100, $offset = 0) {
        $this->log("Getting inventory by marketplace: $marketplace");
        
        $sql = "
            SELECT 
                i.product_id,
                dp.product_name,
                CASE 
                    WHEN i.source = 'Ozon' THEN dp.sku_ozon
                    WHEN i.source = 'Wildberries' THEN dp.sku_wb
                    ELSE CONCAT('ID_', i.product_id)
                END as display_sku,
                dp.sku_ozon,
                dp.sku_wb,
                i.source as marketplace,
                i.warehouse_name,
                i.stock_type as storage_type,
                i.quantity_present as quantity,
                COALESCE(i.quantity_reserved, 0) as reserved_quantity,
                (i.quantity_present - COALESCE(i.quantity_reserved, 0)) as available_quantity,
                dp.cost_price,
                (i.quantity_present * COALESCE(dp.cost_price, 0)) as inventory_value,
                i.updated_at as last_updated,
                CASE 
                    WHEN i.quantity_present <= 5 THEN 'critical'
                    WHEN i.quantity_present <= 20 THEN 'low'
                    WHEN i.quantity_present <= 50 THEN 'medium'
                    ELSE 'good'
                END as stock_level
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.quantity_present > 0
        ";
        
        $params = [];
        
        // Фильтр по маркетплейсу
        if ($marketplace) {
            $sql .= " AND i.source = :marketplace";
            $params['marketplace'] = $marketplace;
        }
        
        // Фильтр по складу
        if ($warehouse) {
            $sql .= " AND i.warehouse_name = :warehouse";
            $params['warehouse'] = $warehouse;
        }
        
        // Поиск по названию или SKU
        if ($search) {
            $sql .= " AND (dp.product_name LIKE :search OR dp.sku_ozon LIKE :search OR dp.sku_wb LIKE :search)";
            $params['search'] = "%$search%";
        }
        
        // Фильтр по уровню остатков
        if ($stockLevel) {
            switch ($stockLevel) {
                case 'critical':
                    $sql .= " AND i.quantity_present <= 5";
                    break;
                case 'low':
                    $sql .= " AND i.quantity_present <= 20";
                    break;
                case 'medium':
                    $sql .= " AND i.quantity_present <= 50";
                    break;
            }
        }
        
        $sql .= " ORDER BY i.source, i.warehouse_name, dp.product_name LIMIT :limit OFFSET :offset";
        
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить остатки по складам для маркетплейса
     */
    public function getWarehouseStats($marketplace) {
        $this->log("Getting warehouse stats for: $marketplace");
        
        $sql = "
            SELECT 
                i.warehouse_name,
                i.stock_type as storage_type,
                COUNT(DISTINCT i.product_id) as products_count,
                SUM(i.quantity_present) as total_quantity,
                SUM(i.quantity_present * COALESCE(dp.cost_price, 0)) as warehouse_value,
                ROUND(AVG(i.quantity_present), 2) as avg_quantity_per_product
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.source = :marketplace AND i.quantity_present > 0
            GROUP BY i.warehouse_name, i.stock_type
            ORDER BY warehouse_value DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['marketplace' => $marketplace]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить топ товаров по остаткам
     */
    public function getTopProductsByStock($marketplace = null, $limit = 10) {
        $this->log("Getting top products by stock for: $marketplace");
        
        $sql = "
            SELECT 
                i.product_id,
                dp.product_name,
                CASE 
                    WHEN i.source = 'Ozon' THEN dp.sku_ozon
                    WHEN i.source = 'Wildberries' THEN dp.sku_wb
                    ELSE CONCAT('ID_', i.product_id)
                END as display_sku,
                i.source,
                SUM(i.quantity_present) as total_stock,
                SUM(i.quantity_present * COALESCE(dp.cost_price, 0)) as stock_value,
                COUNT(DISTINCT i.warehouse_name) as warehouses_count
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.quantity_present > 0
        ";
        
        $params = ['limit' => $limit];
        
        if ($marketplace) {
            $sql .= " AND i.source = :marketplace";
            $params['marketplace'] = $marketplace;
        }
        
        $sql .= " GROUP BY i.product_id, dp.product_name, i.source ORDER BY stock_value DESC LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить критические остатки
     */
    public function getCriticalStock($marketplace = null, $threshold = 5) {
        $this->log("Getting critical stock for: $marketplace, threshold: $threshold");
        
        $sql = "
            SELECT 
                i.product_id,
                dp.product_name,
                CASE 
                    WHEN i.source = 'Ozon' THEN dp.sku_ozon
                    WHEN i.source = 'Wildberries' THEN dp.sku_wb
                    ELSE CONCAT('ID_', i.product_id)
                END as display_sku,
                i.source,
                i.warehouse_name,
                i.quantity_present as quantity,
                COALESCE(i.quantity_reserved, 0) as reserved_quantity,
                (i.quantity_present - COALESCE(i.quantity_reserved, 0)) as available_quantity
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.quantity_present > 0 AND i.quantity_present <= :threshold
        ";
        
        $params = ['threshold' => $threshold];
        
        if ($marketplace) {
            $sql .= " AND i.source = :marketplace";
            $params['marketplace'] = $marketplace;
        }
        
        $sql .= " ORDER BY i.quantity_present ASC, i.source, dp.product_name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить список складов
     */
    public function getWarehouses($marketplace = null) {
        $sql = "SELECT DISTINCT warehouse_name FROM inventory WHERE quantity_present > 0";
        $params = [];
        
        if ($marketplace) {
            $sql .= " AND source = :marketplace";
            $params['marketplace'] = $marketplace;
        }
        
        $sql .= " ORDER BY warehouse_name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Получить общее количество записей для пагинации
     */
    public function getInventoryCount($marketplace = null, $warehouse = null, $search = null, $stockLevel = null) {
        $sql = "
            SELECT COUNT(*) as total
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.quantity_present > 0
        ";
        
        $params = [];
        
        if ($marketplace) {
            $sql .= " AND i.source = :marketplace";
            $params['marketplace'] = $marketplace;
        }
        
        if ($warehouse) {
            $sql .= " AND i.warehouse_name = :warehouse";
            $params['warehouse'] = $warehouse;
        }
        
        if ($search) {
            $sql .= " AND (dp.product_name LIKE :search OR dp.sku_ozon LIKE :search OR dp.sku_wb LIKE :search)";
            $params['search'] = "%$search%";
        }
        
        if ($stockLevel) {
            switch ($stockLevel) {
                case 'critical':
                    $sql .= " AND i.quantity_present <= 5";
                    break;
                case 'low':
                    $sql .= " AND i.quantity_present <= 20";
                    break;
                case 'medium':
                    $sql .= " AND i.quantity_present <= 50";
                    break;
            }
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch();
        return $result['total'];
    }
    
    /**
     * Получить данные для API endpoint
     */
    public function getInventoryAPIResponse($params = []) {
        try {
            $marketplace = $params['marketplace'] ?? null;
            $warehouse = $params['warehouse'] ?? null;
            $search = $params['search'] ?? null;
            $stockLevel = $params['stock_level'] ?? null;
            $page = max(1, intval($params['page'] ?? 1));
            $limit = min(100, max(10, intval($params['limit'] ?? 50)));
            $offset = ($page - 1) * $limit;
            
            // Получаем данные
            $data = $this->getInventoryByMarketplace($marketplace, $warehouse, $search, $stockLevel, $limit, $offset);
            $total = $this->getInventoryCount($marketplace, $warehouse, $search, $stockLevel);
            $summary = $this->getInventorySummary();
            
            return [
                'success' => true,
                'data' => $data,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ],
                'summary' => $summary,
                'filters' => [
                    'marketplace' => $marketplace,
                    'warehouse' => $warehouse,
                    'search' => $search,
                    'stock_level' => $stockLevel
                ]
            ];
            
        } catch (Exception $e) {
            $this->log("API Error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
?>