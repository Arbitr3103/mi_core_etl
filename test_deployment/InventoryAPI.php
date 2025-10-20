<?php
/**
 * API класс для работы с остатками товаров
 */

class InventoryAPI {
    private $pdo;
    private $logFile = 'inventory_api.log';
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
                SUM(i.quantity) as total_quantity,
                SUM(COALESCE(i.reserved_quantity, 0)) as total_reserved,
                SUM(i.quantity - COALESCE(i.reserved_quantity, 0)) as total_available,
                SUM(i.quantity * COALESCE(dp.cost_price, 0)) as total_inventory_value,
                SUM(CASE WHEN i.quantity <= 5 THEN 1 ELSE 0 END) as critical_items,
                SUM(CASE WHEN i.quantity <= 20 THEN 1 ELSE 0 END) as low_stock_items
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.quantity > 0
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
                    ELSE i.sku
                END as display_sku,
                dp.sku_ozon,
                dp.sku_wb,
                i.source as marketplace,
                i.warehouse_name,
                i.storage_type,
                i.quantity_present as quantity,
                COALESCE(i.quantity_reserved, 0) as reserved_quantity,
                (i.quantity_present - COALESCE(i.quantity_reserved, 0)) as available_quantity,
                dp.cost_price,
                (i.quantity * COALESCE(dp.cost_price, 0)) as inventory_value,
                i.last_updated,
                CASE 
                    WHEN i.quantity <= 5 THEN 'critical'
                    WHEN i.quantity <= 20 THEN 'low'
                    WHEN i.quantity <= 50 THEN 'medium'
                    ELSE 'good'
                END as stock_level
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.quantity > 0
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
            $sql .= " AND (dp.product_name LIKE :search OR dp.sku_ozon LIKE :search OR dp.sku_wb LIKE :search OR i.sku LIKE :search)";
            $params['search'] = "%$search%";
        }
        
        // Фильтр по уровню остатков
        if ($stockLevel) {
            switch ($stockLevel) {
                case 'critical':
                    $sql .= " AND i.quantity <= 5";
                    break;
                case 'low':
                    $sql .= " AND i.quantity <= 20";
                    break;
                case 'medium':
                    $sql .= " AND i.quantity <= 50";
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
                i.storage_type,
                COUNT(DISTINCT i.product_id) as products_count,
                SUM(i.quantity) as total_quantity,
                SUM(i.quantity * COALESCE(dp.cost_price, 0)) as warehouse_value,
                ROUND(AVG(i.quantity), 2) as avg_quantity_per_product
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.source = :marketplace AND i.quantity > 0
            GROUP BY i.warehouse_name, i.storage_type
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
                    ELSE i.sku
                END as display_sku,
                i.source,
                SUM(i.quantity) as total_stock,
                SUM(i.quantity * COALESCE(dp.cost_price, 0)) as stock_value,
                COUNT(DISTINCT i.warehouse_name) as warehouses_count
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.quantity > 0
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
                    ELSE i.sku
                END as display_sku,
                i.source,
                i.warehouse_name,
                i.quantity,
                COALESCE(i.reserved_quantity, 0) as reserved_quantity,
                (i.quantity - COALESCE(i.reserved_quantity, 0)) as available_quantity
            FROM inventory i
            LEFT JOIN dim_products dp ON i.product_id = dp.id
            WHERE i.quantity > 0 AND i.quantity <= :threshold
        ";
        
        $params = ['threshold' => $threshold];
        
        if ($marketplace) {
            $sql .= " AND i.source = :marketplace";
            $params['marketplace'] = $marketplace;
        }
        
        $sql .= " ORDER BY i.quantity ASC, i.source, dp.product_name";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Получить список складов
     */
    public function getWarehouses($marketplace = null) {
        $sql = "SELECT DISTINCT warehouse_name FROM inventory WHERE quantity > 0";
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
            WHERE i.quantity > 0
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
            $sql .= " AND (dp.product_name LIKE :search OR dp.sku_ozon LIKE :search OR dp.sku_wb LIKE :search OR i.sku LIKE :search)";
            $params['search'] = "%$search%";
        }
        
        if ($stockLevel) {
            switch ($stockLevel) {
                case 'critical':
                    $sql .= " AND i.quantity <= 5";
                    break;
                case 'low':
                    $sql .= " AND i.quantity <= 20";
                    break;
                case 'medium':
                    $sql .= " AND i.quantity <= 50";
                    break;
            }
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        $result = $stmt->fetch();
        return $result['total'];
    }
    
    /**
     * Экспорт данных остатков в CSV
     */
    public function exportInventoryToCSV($marketplace = null, $warehouse = null, $search = null, $stockLevel = null) {
        $this->log("Exporting inventory to CSV: marketplace=$marketplace");
        
        // Получаем все данные без лимита для экспорта
        $data = $this->getInventoryByMarketplace($marketplace, $warehouse, $search, $stockLevel, 10000, 0);
        
        $filename = 'inventory_export_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = '/tmp/' . $filename;
        
        $file = fopen($filepath, 'w');
        
        // UTF-8 BOM для корректного отображения в Excel
        fwrite($file, "\xEF\xBB\xBF");
        
        // Заголовки CSV
        $headers = [
            'ID товара',
            'Название товара', 
            'SKU',
            'SKU Ozon',
            'SKU WB',
            'Маркетплейс',
            'Склад',
            'Тип хранения',
            'Количество',
            'Зарезервировано',
            'Доступно',
            'Себестоимость',
            'Стоимость остатков',
            'Обновлено',
            'Уровень остатков'
        ];
        
        fputcsv($file, $headers, ';');
        
        // Данные
        foreach ($data as $row) {
            $csvRow = [
                $row['product_id'],
                $row['product_name'],
                $row['display_sku'],
                $row['sku_ozon'],
                $row['sku_wb'],
                $row['marketplace'],
                $row['warehouse_name'],
                $row['storage_type'],
                $row['quantity'],
                $row['reserved_quantity'],
                $row['available_quantity'],
                $row['cost_price'],
                $row['inventory_value'],
                $row['last_updated'],
                $row['stock_level']
            ];
            
            fputcsv($file, $csvRow, ';');
        }
        
        fclose($file);
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'records_count' => count($data)
        ];
    }
    
    /**
     * Экспорт сводной статистики в CSV
     */
    public function exportSummaryToCSV() {
        $this->log("Exporting summary to CSV");
        
        $summary = $this->getInventorySummary();
        
        $filename = 'inventory_summary_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = '/tmp/' . $filename;
        
        $file = fopen($filepath, 'w');
        
        // UTF-8 BOM
        fwrite($file, "\xEF\xBB\xBF");
        
        // Заголовки
        $headers = [
            'Маркетплейс',
            'Товаров',
            'Складов', 
            'Общее количество',
            'Зарезервировано',
            'Доступно',
            'Стоимость остатков',
            'Критические остатки',
            'Низкие остатки'
        ];
        
        fputcsv($file, $headers, ';');
        
        // Данные
        foreach ($summary as $row) {
            $csvRow = [
                $row['marketplace'],
                $row['total_products'],
                $row['total_warehouses'],
                $row['total_quantity'],
                $row['total_reserved'],
                $row['total_available'],
                $row['total_inventory_value'],
                $row['critical_items'],
                $row['low_stock_items']
            ];
            
            fputcsv($file, $csvRow, ';');
        }
        
        fclose($file);
        
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'records_count' => count($summary)
        ];
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