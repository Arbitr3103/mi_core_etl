<?php

namespace MDM\Services;

/**
 * Products Service for MDM System
 * Handles master product management operations
 */
class ProductsService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = $this->getDatabaseConnection();
    }

    /**
     * Get product statistics
     */
    public function getProductStatistics(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_products,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_products,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_products,
                SUM(CASE WHEN canonical_brand IS NULL OR canonical_brand = '' OR canonical_brand = 'Неизвестный бренд' THEN 1 ELSE 0 END) as missing_brand,
                SUM(CASE WHEN canonical_category IS NULL OR canonical_category = '' OR canonical_category = 'Без категории' THEN 1 ELSE 0 END) as missing_category,
                SUM(CASE WHEN description IS NULL OR description = '' THEN 1 ELSE 0 END) as missing_description
            FROM master_products
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetch();
    }

    /**
     * Get products with pagination and filtering
     */
    public function getProducts(int $page = 1, int $limit = 20, string $search = '', string $filter = 'all', string $sortBy = 'updated_at', string $sortOrder = 'desc'): array
    {
        $offset = ($page - 1) * $limit;
        
        // Build WHERE clause
        $whereConditions = [];
        $params = [];
        
        if (!empty($search)) {
            $whereConditions[] = "(canonical_name LIKE ? OR canonical_brand LIKE ? OR master_id LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        switch ($filter) {
            case 'active':
                $whereConditions[] = "status = 'active'";
                break;
            case 'inactive':
                $whereConditions[] = "status = 'inactive'";
                break;
            case 'incomplete':
                $whereConditions[] = "(canonical_brand IS NULL OR canonical_brand = '' OR canonical_brand = 'Неизвестный бренд' OR canonical_category IS NULL OR canonical_category = '' OR canonical_category = 'Без категории')";
                break;
            case 'no_mappings':
                $whereConditions[] = "master_id NOT IN (SELECT DISTINCT master_id FROM sku_mapping WHERE master_id IS NOT NULL)";
                break;
        }
        
        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
        
        // Validate sort parameters
        $allowedSortFields = ['master_id', 'canonical_name', 'canonical_brand', 'canonical_category', 'status', 'created_at', 'updated_at'];
        $sortBy = in_array($sortBy, $allowedSortFields) ? $sortBy : 'updated_at';
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM master_products {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $totalCount = $countStmt->fetchColumn();
        
        // Get products with mapping counts
        $sql = "
            SELECT 
                mp.*,
                COUNT(sm.id) as mapping_count,
                COUNT(DISTINCT sm.source) as source_count
            FROM master_products mp
            LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
            {$whereClause}
            GROUP BY mp.master_id
            ORDER BY {$sortBy} {$sortOrder}
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();
        
        return [
            'products' => $products,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ];
    }

    /**
     * Get product details
     */
    public function getProductDetails(string $masterId): array
    {
        $sql = "SELECT * FROM master_products WHERE master_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$masterId]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new Exception('Product not found');
        }

        return $product;
    }

    /**
     * Get product mappings
     */
    public function getProductMappings(string $masterId): array
    {
        $sql = "
            SELECT 
                id,
                external_sku,
                source,
                source_name,
                source_brand,
                source_category,
                confidence_score,
                verification_status,
                created_at,
                updated_at
            FROM sku_mapping 
            WHERE master_id = ?
            ORDER BY created_at DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$masterId]);
        
        return $stmt->fetchAll();
    }

    /**
     * Update product
     */
    public function updateProduct(string $masterId, array $productData): array
    {
        $this->pdo->beginTransaction();
        
        try {
            // Get current product for history
            $currentProduct = $this->getProductDetails($masterId);
            
            // Update product
            $sql = "
                UPDATE master_products 
                SET canonical_name = ?, 
                    canonical_brand = ?, 
                    canonical_category = ?, 
                    description = ?,
                    attributes = ?,
                    status = ?,
                    updated_at = NOW()
                WHERE master_id = ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $productData['canonical_name'] ?? $currentProduct['canonical_name'],
                $productData['canonical_brand'] ?? $currentProduct['canonical_brand'],
                $productData['canonical_category'] ?? $currentProduct['canonical_category'],
                $productData['description'] ?? $currentProduct['description'],
                isset($productData['attributes']) ? json_encode($productData['attributes']) : $currentProduct['attributes'],
                $productData['status'] ?? $currentProduct['status'],
                $masterId
            ]);

            // Log the change
            $this->logProductChange($masterId, 'updated', $currentProduct, $productData);

            $this->pdo->commit();
            
            return [
                'master_id' => $masterId,
                'status' => 'updated'
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Delete product (soft delete)
     */
    public function deleteProduct(string $masterId): array
    {
        $this->pdo->beginTransaction();
        
        try {
            // Check if product has mappings
            $sql = "SELECT COUNT(*) FROM sku_mapping WHERE master_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$masterId]);
            $mappingCount = $stmt->fetchColumn();

            if ($mappingCount > 0) {
                throw new Exception('Cannot delete product with existing SKU mappings. Please remove mappings first.');
            }

            // Soft delete the product
            $sql = "UPDATE master_products SET status = 'inactive', updated_at = NOW() WHERE master_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$masterId]);

            // Log the change
            $this->logProductChange($masterId, 'deleted');

            $this->pdo->commit();
            
            return [
                'master_id' => $masterId,
                'status' => 'deleted'
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Bulk update products
     */
    public function bulkUpdateProducts(array $masterIds, array $updateData): array
    {
        $this->pdo->beginTransaction();
        
        try {
            $results = [];
            
            foreach ($masterIds as $masterId) {
                try {
                    $result = $this->updateProduct($masterId, $updateData);
                    $results[] = $result;
                } catch (Exception $e) {
                    // Log error but continue with other products
                    error_log("Error updating product {$masterId}: " . $e->getMessage());
                }
            }

            $this->pdo->commit();
            
            return [
                'updated_count' => count($results),
                'results' => $results
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Merge products
     */
    public function mergeProducts(string $primaryMasterId, array $secondaryMasterIds): array
    {
        $this->pdo->beginTransaction();
        
        try {
            // Validate primary product exists
            $primaryProduct = $this->getProductDetails($primaryMasterId);
            
            $mergedCount = 0;
            
            foreach ($secondaryMasterIds as $secondaryMasterId) {
                // Move all mappings to primary product
                $sql = "UPDATE sku_mapping SET master_id = ? WHERE master_id = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$primaryMasterId, $secondaryMasterId]);
                
                // Deactivate secondary product
                $sql = "UPDATE master_products SET status = 'inactive', updated_at = NOW() WHERE master_id = ?";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$secondaryMasterId]);
                
                // Log the merge
                $this->logProductChange($secondaryMasterId, 'merged_into', null, ['primary_master_id' => $primaryMasterId]);
                
                $mergedCount++;
            }

            $this->pdo->commit();
            
            return [
                'primary_master_id' => $primaryMasterId,
                'merged_count' => $mergedCount,
                'status' => 'merged'
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Export products
     */
    public function exportProducts(string $format, string $filter = 'all', string $search = ''): string
    {
        // Get all products matching criteria
        $result = $this->getProducts(1, 10000, $search, $filter); // Large limit for export
        $products = $result['products'];
        
        switch ($format) {
            case 'csv':
                return $this->exportToCsv($products);
            case 'json':
                return json_encode($products, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            default:
                throw new Exception('Unsupported export format');
        }
    }

    /**
     * Export to CSV format
     */
    private function exportToCsv(array $products): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Write header
        fputcsv($output, [
            'Master ID',
            'Canonical Name',
            'Canonical Brand',
            'Canonical Category',
            'Description',
            'Status',
            'Mapping Count',
            'Source Count',
            'Created At',
            'Updated At'
        ]);
        
        // Write data
        foreach ($products as $product) {
            fputcsv($output, [
                $product['master_id'],
                $product['canonical_name'],
                $product['canonical_brand'],
                $product['canonical_category'],
                $product['description'],
                $product['status'],
                $product['mapping_count'],
                $product['source_count'],
                $product['created_at'],
                $product['updated_at']
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Log product changes
     */
    private function logProductChange(string $masterId, string $action, ?array $oldData = null, ?array $newData = null): void
    {
        // Create product_history table if it doesn't exist
        $sql = "
            CREATE TABLE IF NOT EXISTS product_history (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                master_id VARCHAR(50) NOT NULL,
                action VARCHAR(50) NOT NULL,
                old_data JSON,
                new_data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_master_id (master_id),
                INDEX idx_action (action)
            )
        ";
        
        $this->pdo->exec($sql);

        // Insert history record
        $sql = "
            INSERT INTO product_history (master_id, action, old_data, new_data)
            VALUES (?, ?, ?, ?)
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $masterId,
            $action,
            $oldData ? json_encode($oldData) : null,
            $newData ? json_encode($newData) : null
        ]);
    }

    /**
     * Get database connection
     */
    private function getDatabaseConnection(): \PDO
    {
        $config = require __DIR__ . '/../../config/database.php';
        
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset=utf8mb4";
        return new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC
        ]);
    }
}