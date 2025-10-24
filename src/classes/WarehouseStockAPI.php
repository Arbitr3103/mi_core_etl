<?php
/**
 * WarehouseStockAPI Class
 * 
 * Handles API requests for warehouse stock data with filtering, pagination, and sorting
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once __DIR__ . '/../utils/Logger.php';
require_once __DIR__ . '/DatabaseQueryOptimizer.php';

class WarehouseStockAPI {
    
    private $pdo;
    private $logger;
    private $defaultLimit = 100;
    private $maxLimit = 1000;
    private $queryOptimizer;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->logger = Logger::getInstance();
        $this->queryOptimizer = new DatabaseQueryOptimizer($pdo);
    }
    
    /**
     * Get warehouse stock data with filtering options
     * 
     * @param array $params - Query parameters for filtering
     * @return array API response
     */
    public function getWarehouseStock(array $params = []): array {
        try {
            // Validate and sanitize parameters
            $filters = $this->validateAndSanitizeParams($params);
            
            // Build optimized query
            $query = $this->queryOptimizer->optimizeWarehouseStockQuery($filters);
            $countQuery = $this->buildStockCountQuery($filters);
            
            // Execute count query for pagination
            $stmt = $this->pdo->prepare($countQuery['sql']);
            $stmt->execute($countQuery['params']);
            $totalCount = $stmt->fetchColumn();
            
            // Execute main query
            $stmt = $this->pdo->prepare($query['sql']);
            $stmt->execute($query['params']);
            $stockData = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format response data
            $formattedData = $this->formatStockData($stockData);
            
            // Calculate pagination info
            $pagination = $this->calculatePagination($totalCount, $filters['limit'], $filters['offset']);
            
            $this->logger->info('Warehouse stock data retrieved', [
                'total_count' => $totalCount,
                'returned_count' => count($formattedData),
                'filters' => $filters
            ]);
            
            return [
                'success' => true,
                'data' => $formattedData,
                'pagination' => $pagination,
                'filters_applied' => $filters,
                'timestamp' => date('c')
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve warehouse stock data', [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            
            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 500
                ],
                'timestamp' => date('c'),
                'status' => 500
            ];
        }
    }
    
    /**
     * Get stock data for a specific warehouse
     * 
     * @param string $warehouse - Warehouse name
     * @param array $params - Additional query parameters
     * @return array API response
     */
    public function getWarehouseStockByWarehouse(string $warehouse, array $params = []): array {
        try {
            // Add warehouse filter to params
            $params['warehouse'] = $warehouse;
            
            // Use the main method with warehouse filter
            $response = $this->getWarehouseStock($params);
            
            // Add warehouse-specific metadata
            if ($response['success']) {
                $response['warehouse_info'] = $this->getWarehouseInfo($warehouse);
            }
            
            return $response;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve warehouse-specific stock data', [
                'warehouse' => $warehouse,
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            
            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 500
                ],
                'timestamp' => date('c'),
                'status' => 500
            ];
        }
    }
    
    /**
     * Validate and sanitize query parameters
     * 
     * @param array $params - Raw query parameters
     * @return array Validated and sanitized parameters
     */
    private function validateAndSanitizeParams(array $params): array {
        $filters = [
            'warehouse' => null,
            'product_id' => null,
            'sku' => null,
            'source' => null,
            'stock_level' => null,
            'date_from' => null,
            'date_to' => null,
            'limit' => $this->defaultLimit,
            'offset' => 0,
            'sort_by' => 'updated_at',
            'sort_order' => 'DESC'
        ];
        
        // Warehouse filter
        if (!empty($params['warehouse'])) {
            $filters['warehouse'] = trim($params['warehouse']);
        }
        
        // Product ID filter
        if (!empty($params['product_id'])) {
            if (is_numeric($params['product_id'])) {
                $filters['product_id'] = (int) $params['product_id'];
            } else {
                throw new Exception('Invalid product_id: must be numeric');
            }
        }
        
        // SKU filter
        if (!empty($params['sku'])) {
            $filters['sku'] = trim($params['sku']);
        }
        
        // Source filter
        if (!empty($params['source'])) {
            $validSources = ['Ozon', 'Wildberries'];
            if (in_array($params['source'], $validSources)) {
                $filters['source'] = $params['source'];
            } else {
                throw new Exception('Invalid source: must be one of ' . implode(', ', $validSources));
            }
        }
        
        // Stock level filter
        if (!empty($params['stock_level'])) {
            $validLevels = ['zero', 'low', 'normal', 'high'];
            if (in_array($params['stock_level'], $validLevels)) {
                $filters['stock_level'] = $params['stock_level'];
            } else {
                throw new Exception('Invalid stock_level: must be one of ' . implode(', ', $validLevels));
            }
        }
        
        // Date range filters
        if (!empty($params['date_from'])) {
            if ($this->validateDateFormat($params['date_from'])) {
                $filters['date_from'] = $params['date_from'];
            } else {
                throw new Exception('Invalid date_from format: use YYYY-MM-DD');
            }
        }
        
        if (!empty($params['date_to'])) {
            if ($this->validateDateFormat($params['date_to'])) {
                $filters['date_to'] = $params['date_to'];
            } else {
                throw new Exception('Invalid date_to format: use YYYY-MM-DD');
            }
        }
        
        // Pagination parameters
        if (isset($params['limit'])) {
            $limit = (int) $params['limit'];
            if ($limit > 0 && $limit <= $this->maxLimit) {
                $filters['limit'] = $limit;
            } else {
                throw new Exception("Invalid limit: must be between 1 and {$this->maxLimit}");
            }
        }
        
        if (isset($params['offset'])) {
            $offset = (int) $params['offset'];
            if ($offset >= 0) {
                $filters['offset'] = $offset;
            } else {
                throw new Exception('Invalid offset: must be non-negative');
            }
        }
        
        // Sorting parameters
        if (!empty($params['sort_by'])) {
            $validSortFields = [
                'warehouse_name', 'product_id', 'quantity_present', 
                'quantity_reserved', 'updated_at', 'last_report_update'
            ];
            if (in_array($params['sort_by'], $validSortFields)) {
                $filters['sort_by'] = $params['sort_by'];
            } else {
                throw new Exception('Invalid sort_by: must be one of ' . implode(', ', $validSortFields));
            }
        }
        
        if (!empty($params['sort_order'])) {
            $sortOrder = strtoupper($params['sort_order']);
            if (in_array($sortOrder, ['ASC', 'DESC'])) {
                $filters['sort_order'] = $sortOrder;
            } else {
                throw new Exception('Invalid sort_order: must be ASC or DESC');
            }
        }
        
        return $filters;
    }
    
    /**
     * Build SQL query for stock data retrieval
     * 
     * @param array $filters - Validated filters
     * @return array Query with SQL and parameters
     */
    private function buildStockQuery(array $filters): array {
        $sql = "
            SELECT 
                i.id,
                i.product_id,
                p.sku,
                p.name as product_name,
                i.warehouse_name,
                i.source,
                i.quantity_present,
                i.quantity_reserved,
                (i.quantity_present - i.quantity_reserved) as quantity_available,
                i.stock_type,
                i.report_source,
                i.last_report_update,
                i.report_code,
                i.updated_at
            FROM inventory i
            LEFT JOIN dim_products p ON i.product_id = p.id
            WHERE 1=1
        ";
        
        $params = [];
        $conditions = [];
        
        // Apply filters
        if ($filters['warehouse']) {
            $conditions[] = "i.warehouse_name = :warehouse";
            $params['warehouse'] = $filters['warehouse'];
        }
        
        if ($filters['product_id']) {
            $conditions[] = "i.product_id = :product_id";
            $params['product_id'] = $filters['product_id'];
        }
        
        if ($filters['sku']) {
            $conditions[] = "p.sku LIKE :sku";
            $params['sku'] = '%' . $filters['sku'] . '%';
        }
        
        if ($filters['source']) {
            $conditions[] = "i.source = :source";
            $params['source'] = $filters['source'];
        }
        
        // Stock level filter
        if ($filters['stock_level']) {
            switch ($filters['stock_level']) {
                case 'zero':
                    $conditions[] = "i.quantity_present = 0";
                    break;
                case 'low':
                    $conditions[] = "i.quantity_present > 0 AND i.quantity_present <= 10";
                    break;
                case 'normal':
                    $conditions[] = "i.quantity_present > 10 AND i.quantity_present <= 100";
                    break;
                case 'high':
                    $conditions[] = "i.quantity_present > 100";
                    break;
            }
        }
        
        // Date range filters
        if ($filters['date_from']) {
            $conditions[] = "DATE(i.updated_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        
        if ($filters['date_to']) {
            $conditions[] = "DATE(i.updated_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        // Add conditions to SQL
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        // Add sorting
        $sql .= " ORDER BY i.{$filters['sort_by']} {$filters['sort_order']}";
        
        // Add pagination
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $filters['limit'];
        $params['offset'] = $filters['offset'];
        
        return [
            'sql' => $sql,
            'params' => $params
        ];
    }
    
    /**
     * Build count query for pagination
     * 
     * @param array $filters - Validated filters
     * @return array Count query with SQL and parameters
     */
    private function buildStockCountQuery(array $filters): array {
        $sql = "
            SELECT COUNT(*)
            FROM inventory i
            LEFT JOIN dim_products p ON i.product_id = p.id
            WHERE 1=1
        ";
        
        $params = [];
        $conditions = [];
        
        // Apply same filters as main query (excluding pagination)
        if ($filters['warehouse']) {
            $conditions[] = "i.warehouse_name = :warehouse";
            $params['warehouse'] = $filters['warehouse'];
        }
        
        if ($filters['product_id']) {
            $conditions[] = "i.product_id = :product_id";
            $params['product_id'] = $filters['product_id'];
        }
        
        if ($filters['sku']) {
            $conditions[] = "p.sku LIKE :sku";
            $params['sku'] = '%' . $filters['sku'] . '%';
        }
        
        if ($filters['source']) {
            $conditions[] = "i.source = :source";
            $params['source'] = $filters['source'];
        }
        
        // Stock level filter
        if ($filters['stock_level']) {
            switch ($filters['stock_level']) {
                case 'zero':
                    $conditions[] = "i.quantity_present = 0";
                    break;
                case 'low':
                    $conditions[] = "i.quantity_present > 0 AND i.quantity_present <= 10";
                    break;
                case 'normal':
                    $conditions[] = "i.quantity_present > 10 AND i.quantity_present <= 100";
                    break;
                case 'high':
                    $conditions[] = "i.quantity_present > 100";
                    break;
            }
        }
        
        // Date range filters
        if ($filters['date_from']) {
            $conditions[] = "DATE(i.updated_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        
        if ($filters['date_to']) {
            $conditions[] = "DATE(i.updated_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        // Add conditions to SQL
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        return [
            'sql' => $sql,
            'params' => $params
        ];
    }
    
    /**
     * Format stock data for API response
     * 
     * @param array $stockData - Raw stock data from database
     * @return array Formatted stock data
     */
    private function formatStockData(array $stockData): array {
        return array_map(function($record) {
            return [
                'id' => (int) $record['id'],
                'product' => [
                    'id' => (int) $record['product_id'],
                    'sku' => $record['sku'],
                    'name' => $record['product_name']
                ],
                'warehouse_name' => $record['warehouse_name'],
                'source' => $record['source'],
                'stock' => [
                    'present' => (int) $record['quantity_present'],
                    'reserved' => (int) $record['quantity_reserved'],
                    'available' => (int) $record['quantity_available']
                ],
                'stock_type' => $record['stock_type'],
                'report_source' => $record['report_source'],
                'last_report_update' => $record['last_report_update'],
                'report_code' => $record['report_code'],
                'updated_at' => $record['updated_at']
            ];
        }, $stockData);
    }
    
    /**
     * Calculate pagination information
     * 
     * @param int $totalCount - Total number of records
     * @param int $limit - Records per page
     * @param int $offset - Current offset
     * @return array Pagination information
     */
    private function calculatePagination(int $totalCount, int $limit, int $offset): array {
        $currentPage = floor($offset / $limit) + 1;
        $totalPages = ceil($totalCount / $limit);
        
        return [
            'total_count' => $totalCount,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'limit' => $limit,
            'offset' => $offset,
            'has_next' => $currentPage < $totalPages,
            'has_previous' => $currentPage > 1
        ];
    }
    
    /**
     * Get warehouse information and statistics
     * 
     * @param string $warehouse - Warehouse name
     * @return array Warehouse information
     */
    private function getWarehouseInfo(string $warehouse): array {
        try {
            $sql = "
                SELECT 
                    warehouse_name,
                    COUNT(*) as total_products,
                    SUM(quantity_present) as total_stock,
                    SUM(quantity_reserved) as total_reserved,
                    SUM(quantity_present - quantity_reserved) as total_available,
                    COUNT(CASE WHEN quantity_present = 0 THEN 1 END) as zero_stock_products,
                    COUNT(CASE WHEN quantity_present > 0 AND quantity_present <= 10 THEN 1 END) as low_stock_products,
                    MAX(updated_at) as last_updated
                FROM inventory
                WHERE warehouse_name = :warehouse
                GROUP BY warehouse_name
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['warehouse' => $warehouse]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$info) {
                return [
                    'warehouse_name' => $warehouse,
                    'exists' => false
                ];
            }
            
            return [
                'warehouse_name' => $info['warehouse_name'],
                'exists' => true,
                'statistics' => [
                    'total_products' => (int) $info['total_products'],
                    'total_stock' => (int) $info['total_stock'],
                    'total_reserved' => (int) $info['total_reserved'],
                    'total_available' => (int) $info['total_available'],
                    'zero_stock_products' => (int) $info['zero_stock_products'],
                    'low_stock_products' => (int) $info['low_stock_products']
                ],
                'last_updated' => $info['last_updated']
            ];
            
        } catch (PDOException $e) {
            $this->logger->error('Failed to get warehouse info', [
                'warehouse' => $warehouse,
                'error' => $e->getMessage()
            ]);
            
            return [
                'warehouse_name' => $warehouse,
                'exists' => false,
                'error' => 'Failed to retrieve warehouse information'
            ];
        }
    }
    
    /**
     * Validate date format
     * 
     * @param string $date - Date string to validate
     * @return bool True if date is valid
     */
    private function validateDateFormat(string $date): bool {
        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        return $dateTime && $dateTime->format('Y-m-d') === $date;
    }
}