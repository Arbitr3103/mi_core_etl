<?php

namespace MDM\Repositories;

use MDM\Models\MasterProduct;
use PDO;
use PDOException;
use InvalidArgumentException;

/**
 * Master Product Repository
 * 
 * Handles database operations for Master Product entities.
 */
class MasterProductRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo, 'master_products', 'master_id');
    }

    /**
     * Save a master product (insert or update)
     */
    public function save(object $entity): bool
    {
        if (!$entity instanceof MasterProduct) {
            throw new InvalidArgumentException('Entity must be an instance of MasterProduct');
        }

        $data = $this->entityToArray($entity);
        
        // Check if record exists
        if ($this->exists(['master_id' => $entity->getMasterId()])) {
            return $this->update($entity);
        } else {
            return $this->insert($entity);
        }
    }

    /**
     * Insert a new master product
     */
    private function insert(MasterProduct $product): bool
    {
        $data = $this->entityToArray($product);
        
        $fields = array_keys($data);
        $placeholders = array_map(fn($field) => ":{$field}", $fields);
        
        $sql = "INSERT INTO {$this->tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $this->pdo->prepare($sql);
        
        try {
            foreach ($data as $field => $value) {
                $stmt->bindValue(":{$field}", $value);
            }
            return $stmt->execute();
        } catch (PDOException $e) {
            throw new InvalidArgumentException("Failed to insert master product: " . $e->getMessage());
        }
    }

    /**
     * Update an existing master product
     */
    private function update(MasterProduct $product): bool
    {
        $data = $this->entityToArray($product);
        
        // Remove master_id from update data since it's the primary key
        $masterId = $data['master_id'];
        unset($data['master_id']);
        
        $setClause = array_map(fn($field) => "{$field} = :{$field}", array_keys($data));
        
        $sql = "UPDATE {$this->tableName} SET " . implode(', ', $setClause) . " 
                WHERE master_id = :master_id";
        
        $stmt = $this->pdo->prepare($sql);
        
        try {
            foreach ($data as $field => $value) {
                $stmt->bindValue(":{$field}", $value);
            }
            $stmt->bindValue(':master_id', $masterId);
            return $stmt->execute();
        } catch (PDOException $e) {
            throw new InvalidArgumentException("Failed to update master product: " . $e->getMessage());
        }
    }

    /**
     * Delete a master product
     */
    public function delete(object $entity): bool
    {
        if (!$entity instanceof MasterProduct) {
            throw new InvalidArgumentException('Entity must be an instance of MasterProduct');
        }

        return $this->deleteById($entity->getMasterId());
    }

    /**
     * Find master products by name (fuzzy search)
     */
    public function findByNameFuzzy(string $name, int $limit = 10): array
    {
        $sql = "SELECT *, 
                MATCH(canonical_name, canonical_brand, description) AGAINST(:name IN NATURAL LANGUAGE MODE) as relevance_score
                FROM {$this->tableName} 
                WHERE MATCH(canonical_name, canonical_brand, description) AGAINST(:name IN NATURAL LANGUAGE MODE)
                   OR canonical_name LIKE :name_like
                ORDER BY relevance_score DESC, canonical_name ASC
                LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':name_like', "%{$name}%");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }

    /**
     * Find master products by brand
     */
    public function findByBrand(string $brand, ?string $status = null): array
    {
        $criteria = ['canonical_brand' => $brand];
        if ($status !== null) {
            $criteria['status'] = $status;
        }
        
        return $this->findBy($criteria, ['canonical_name' => 'ASC']);
    }

    /**
     * Find master products by category
     */
    public function findByCategory(string $category, ?string $status = null): array
    {
        $criteria = ['canonical_category' => $category];
        if ($status !== null) {
            $criteria['status'] = $status;
        }
        
        return $this->findBy($criteria, ['canonical_name' => 'ASC']);
    }

    /**
     * Find master products by barcode
     */
    public function findByBarcode(string $barcode): ?MasterProduct
    {
        $result = $this->findOneBy(['barcode' => $barcode]);
        return $result instanceof MasterProduct ? $result : null;
    }

    /**
     * Find active master products
     */
    public function findActive(): array
    {
        return $this->findBy(['status' => MasterProduct::STATUS_ACTIVE], ['canonical_name' => 'ASC']);
    }

    /**
     * Find incomplete master products (missing required fields)
     */
    public function findIncomplete(): array
    {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE status = :status 
                AND (canonical_brand IS NULL OR canonical_brand = '' 
                     OR canonical_category IS NULL OR canonical_category = '')
                ORDER BY created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':status', MasterProduct::STATUS_ACTIVE);
        $stmt->execute();
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }

    /**
     * Get master products statistics
     */
    public function getStatistics(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_products,
                    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_products,
                    COUNT(CASE WHEN status = 'pending_review' THEN 1 END) as pending_products,
                    COUNT(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != '' THEN 1 END) as products_with_brand,
                    COUNT(CASE WHEN canonical_category IS NOT NULL AND canonical_category != '' THEN 1 END) as products_with_category,
                    COUNT(CASE WHEN barcode IS NOT NULL AND barcode != '' THEN 1 END) as products_with_barcode,
                    COUNT(CASE WHEN description IS NOT NULL AND description != '' THEN 1 END) as products_with_description
                FROM {$this->tableName}";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get brands list with product counts
     */
    public function getBrandsWithCounts(): array
    {
        $sql = "SELECT canonical_brand, COUNT(*) as product_count
                FROM {$this->tableName} 
                WHERE canonical_brand IS NOT NULL AND canonical_brand != ''
                GROUP BY canonical_brand
                ORDER BY product_count DESC, canonical_brand ASC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get categories list with product counts
     */
    public function getCategoriesWithCounts(): array
    {
        $sql = "SELECT canonical_category, COUNT(*) as product_count
                FROM {$this->tableName} 
                WHERE canonical_category IS NOT NULL AND canonical_category != ''
                GROUP BY canonical_category
                ORDER BY product_count DESC, canonical_category ASC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Search master products with advanced filters
     */
    public function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $conditions = [];
        $params = [];
        
        // Text search
        if (!empty($filters['search'])) {
            $conditions[] = "(canonical_name LIKE :search OR canonical_brand LIKE :search OR description LIKE :search)";
            $params[':search'] = "%{$filters['search']}%";
        }
        
        // Brand filter
        if (!empty($filters['brand'])) {
            $conditions[] = "canonical_brand = :brand";
            $params[':brand'] = $filters['brand'];
        }
        
        // Category filter
        if (!empty($filters['category'])) {
            $conditions[] = "canonical_category = :category";
            $params[':category'] = $filters['category'];
        }
        
        // Status filter
        if (!empty($filters['status'])) {
            $conditions[] = "status = :status";
            $params[':status'] = $filters['status'];
        }
        
        // Date range filter
        if (!empty($filters['created_from'])) {
            $conditions[] = "created_at >= :created_from";
            $params[':created_from'] = $filters['created_from'];
        }
        
        if (!empty($filters['created_to'])) {
            $conditions[] = "created_at <= :created_to";
            $params[':created_to'] = $filters['created_to'];
        }
        
        $sql = "SELECT * FROM {$this->tableName}";
        
        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        
        $sql .= " ORDER BY canonical_name ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->pdo->prepare($sql);
        
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        $stmt->execute();
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }

    /**
     * Generate a unique Master ID
     */
    public function generateUniqueMasterId(string $prefix = 'MASTER'): string
    {
        do {
            $masterId = MasterProduct::generateMasterId($prefix);
        } while ($this->exists(['master_id' => $masterId]));
        
        return $masterId;
    }

    /**
     * Create entity from database array
     */
    protected function createEntityFromArray(array $data): MasterProduct
    {
        return MasterProduct::fromArray($data);
    }

    /**
     * Convert entity to database array
     */
    protected function entityToArray(object $entity): array
    {
        if (!$entity instanceof MasterProduct) {
            throw new InvalidArgumentException('Entity must be an instance of MasterProduct');
        }
        
        return $entity->toArray();
    }
}