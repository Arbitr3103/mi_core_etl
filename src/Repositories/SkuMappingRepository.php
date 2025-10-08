<?php

namespace MDM\Repositories;

use MDM\Models\SkuMapping;
use PDO;
use PDOException;
use InvalidArgumentException;

/**
 * SKU Mapping Repository
 * 
 * Handles database operations for SKU Mapping entities.
 */
class SkuMappingRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo, 'sku_mapping', 'id');
    }

    /**
     * Save a SKU mapping (insert or update)
     */
    public function save(object $entity): bool
    {
        if (!$entity instanceof SkuMapping) {
            throw new InvalidArgumentException('Entity must be an instance of SkuMapping');
        }

        $data = $this->entityToArray($entity);
        
        // Check if record exists by ID or by unique constraint (source + external_sku)
        if ($entity->getId() && $this->exists(['id' => $entity->getId()])) {
            return $this->update($entity);
        } elseif ($this->exists(['source' => $entity->getSource(), 'external_sku' => $entity->getExternalSku()])) {
            return $this->updateByUniqueKey($entity);
        } else {
            return $this->insert($entity);
        }
    }

    /**
     * Insert a new SKU mapping
     */
    private function insert(SkuMapping $mapping): bool
    {
        $data = $this->entityToArray($mapping);
        
        // Remove ID from insert data since it's auto-increment
        unset($data['id']);
        
        $fields = array_keys($data);
        $placeholders = array_map(fn($field) => ":{$field}", $fields);
        
        $sql = "INSERT INTO {$this->tableName} (" . implode(', ', $fields) . ") 
                VALUES (" . implode(', ', $placeholders) . ")";
        
        $stmt = $this->pdo->prepare($sql);
        
        try {
            foreach ($data as $field => $value) {
                $stmt->bindValue(":{$field}", $value);
            }
            
            if ($stmt->execute()) {
                $mapping->setId((int) $this->getLastInsertId());
                return true;
            }
            return false;
        } catch (PDOException $e) {
            throw new InvalidArgumentException("Failed to insert SKU mapping: " . $e->getMessage());
        }
    }

    /**
     * Update an existing SKU mapping by ID
     */
    private function update(SkuMapping $mapping): bool
    {
        $data = $this->entityToArray($mapping);
        
        // Remove ID from update data since it's the primary key
        $id = $data['id'];
        unset($data['id']);
        
        $setClause = array_map(fn($field) => "{$field} = :{$field}", array_keys($data));
        
        $sql = "UPDATE {$this->tableName} SET " . implode(', ', $setClause) . " 
                WHERE id = :id";
        
        $stmt = $this->pdo->prepare($sql);
        
        try {
            foreach ($data as $field => $value) {
                $stmt->bindValue(":{$field}", $value);
            }
            $stmt->bindValue(':id', $id);
            return $stmt->execute();
        } catch (PDOException $e) {
            throw new InvalidArgumentException("Failed to update SKU mapping: " . $e->getMessage());
        }
    }

    /**
     * Update an existing SKU mapping by unique key (source + external_sku)
     */
    private function updateByUniqueKey(SkuMapping $mapping): bool
    {
        $data = $this->entityToArray($mapping);
        
        // Remove unique key fields from update data
        $source = $data['source'];
        $externalSku = $data['external_sku'];
        unset($data['id'], $data['source'], $data['external_sku']);
        
        $setClause = array_map(fn($field) => "{$field} = :{$field}", array_keys($data));
        
        $sql = "UPDATE {$this->tableName} SET " . implode(', ', $setClause) . " 
                WHERE source = :source AND external_sku = :external_sku";
        
        $stmt = $this->pdo->prepare($sql);
        
        try {
            foreach ($data as $field => $value) {
                $stmt->bindValue(":{$field}", $value);
            }
            $stmt->bindValue(':source', $source);
            $stmt->bindValue(':external_sku', $externalSku);
            return $stmt->execute();
        } catch (PDOException $e) {
            throw new InvalidArgumentException("Failed to update SKU mapping: " . $e->getMessage());
        }
    }

    /**
     * Delete a SKU mapping
     */
    public function delete(object $entity): bool
    {
        if (!$entity instanceof SkuMapping) {
            throw new InvalidArgumentException('Entity must be an instance of SkuMapping');
        }

        if ($entity->getId()) {
            return $this->deleteById($entity->getId());
        } else {
            return $this->deleteByUniqueKey($entity->getSource(), $entity->getExternalSku());
        }
    }

    /**
     * Delete SKU mapping by unique key
     */
    public function deleteByUniqueKey(string $source, string $externalSku): bool
    {
        $sql = "DELETE FROM {$this->tableName} WHERE source = :source AND external_sku = :external_sku";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':source', $source);
        $stmt->bindValue(':external_sku', $externalSku);
        
        try {
            return $stmt->execute();
        } catch (PDOException $e) {
            throw new InvalidArgumentException("Failed to delete SKU mapping: " . $e->getMessage());
        }
    }

    /**
     * Find SKU mapping by external SKU and source
     */
    public function findByExternalSku(string $externalSku, string $source): ?SkuMapping
    {
        $result = $this->findOneBy(['external_sku' => $externalSku, 'source' => $source]);
        return $result instanceof SkuMapping ? $result : null;
    }

    /**
     * Find all SKU mappings for a master product
     */
    public function findByMasterId(string $masterId): array
    {
        return $this->findBy(['master_id' => $masterId], ['created_at' => 'DESC']);
    }

    /**
     * Find SKU mappings by source
     */
    public function findBySource(string $source, ?string $verificationStatus = null): array
    {
        $criteria = ['source' => $source];
        if ($verificationStatus !== null) {
            $criteria['verification_status'] = $verificationStatus;
        }
        
        return $this->findBy($criteria, ['created_at' => 'DESC']);
    }

    /**
     * Find pending SKU mappings (requiring manual verification)
     */
    public function findPending(int $limit = 100): array
    {
        return $this->findBy(
            ['verification_status' => SkuMapping::STATUS_PENDING],
            ['confidence_score' => 'DESC', 'created_at' => 'ASC'],
            $limit
        );
    }

    /**
     * Find high confidence pending mappings (candidates for auto-approval)
     */
    public function findHighConfidencePending(float $threshold = 0.9, int $limit = 50): array
    {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE verification_status = :status 
                AND confidence_score >= :threshold
                ORDER BY confidence_score DESC, created_at ASC
                LIMIT :limit";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':status', SkuMapping::STATUS_PENDING);
        $stmt->bindValue(':threshold', $threshold);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }

    /**
     * Find verified SKU mappings
     */
    public function findVerified(): array
    {
        return $this->findBy(
            ['verification_status' => [SkuMapping::STATUS_AUTO, SkuMapping::STATUS_MANUAL]],
            ['verified_at' => 'DESC']
        );
    }

    /**
     * Get SKU mapping statistics
     */
    public function getStatistics(): array
    {
        $sql = "SELECT 
                    COUNT(*) as total_mappings,
                    COUNT(CASE WHEN verification_status = 'auto' THEN 1 END) as auto_verified,
                    COUNT(CASE WHEN verification_status = 'manual' THEN 1 END) as manual_verified,
                    COUNT(CASE WHEN verification_status = 'pending' THEN 1 END) as pending_verification,
                    COUNT(CASE WHEN verification_status = 'rejected' THEN 1 END) as rejected,
                    AVG(confidence_score) as avg_confidence_score,
                    COUNT(CASE WHEN confidence_score >= 0.8 THEN 1 END) as high_confidence,
                    COUNT(CASE WHEN confidence_score >= 0.5 AND confidence_score < 0.8 THEN 1 END) as medium_confidence,
                    COUNT(CASE WHEN confidence_score < 0.5 THEN 1 END) as low_confidence
                FROM {$this->tableName}";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get statistics by source
     */
    public function getStatisticsBySource(): array
    {
        $sql = "SELECT 
                    source,
                    COUNT(*) as total_mappings,
                    COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END) as verified_mappings,
                    COUNT(CASE WHEN verification_status = 'pending' THEN 1 END) as pending_mappings,
                    AVG(confidence_score) as avg_confidence_score
                FROM {$this->tableName}
                GROUP BY source
                ORDER BY total_mappings DESC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find duplicate external SKUs within same source
     */
    public function findDuplicates(): array
    {
        $sql = "SELECT source, external_sku, COUNT(*) as duplicate_count
                FROM {$this->tableName}
                GROUP BY source, external_sku
                HAVING COUNT(*) > 1
                ORDER BY duplicate_count DESC, source, external_sku";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find orphaned SKU mappings (master product doesn't exist)
     */
    public function findOrphaned(): array
    {
        $sql = "SELECT sm.* FROM {$this->tableName} sm
                LEFT JOIN master_products mp ON sm.master_id = mp.master_id
                WHERE mp.master_id IS NULL";
        
        $stmt = $this->pdo->query($sql);
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }

    /**
     * Bulk approve pending mappings by confidence threshold
     */
    public function bulkApproveByConfidence(float $threshold = 0.9, string $verifiedBy = 'system'): int
    {
        $sql = "UPDATE {$this->tableName} 
                SET verification_status = :new_status, 
                    verified_by = :verified_by, 
                    verified_at = NOW()
                WHERE verification_status = :current_status 
                AND confidence_score >= :threshold";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':new_status', SkuMapping::STATUS_AUTO);
        $stmt->bindValue(':current_status', SkuMapping::STATUS_PENDING);
        $stmt->bindValue(':threshold', $threshold);
        $stmt->bindValue(':verified_by', $verifiedBy);
        
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Search SKU mappings with advanced filters
     */
    public function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $conditions = [];
        $params = [];
        
        // Master ID filter
        if (!empty($filters['master_id'])) {
            $conditions[] = "master_id = :master_id";
            $params[':master_id'] = $filters['master_id'];
        }
        
        // External SKU search
        if (!empty($filters['external_sku'])) {
            $conditions[] = "external_sku LIKE :external_sku";
            $params[':external_sku'] = "%{$filters['external_sku']}%";
        }
        
        // Source filter
        if (!empty($filters['source'])) {
            $conditions[] = "source = :source";
            $params[':source'] = $filters['source'];
        }
        
        // Verification status filter
        if (!empty($filters['verification_status'])) {
            $conditions[] = "verification_status = :verification_status";
            $params[':verification_status'] = $filters['verification_status'];
        }
        
        // Confidence score range
        if (isset($filters['min_confidence'])) {
            $conditions[] = "confidence_score >= :min_confidence";
            $params[':min_confidence'] = $filters['min_confidence'];
        }
        
        if (isset($filters['max_confidence'])) {
            $conditions[] = "confidence_score <= :max_confidence";
            $params[':max_confidence'] = $filters['max_confidence'];
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
        
        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        
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
     * Create entity from database array
     */
    protected function createEntityFromArray(array $data): SkuMapping
    {
        return SkuMapping::fromArray($data);
    }

    /**
     * Convert entity to database array
     */
    protected function entityToArray(object $entity): array
    {
        if (!$entity instanceof SkuMapping) {
            throw new InvalidArgumentException('Entity must be an instance of SkuMapping');
        }
        
        return $entity->toArray();
    }
}