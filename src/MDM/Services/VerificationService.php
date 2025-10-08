<?php

namespace MDM\Services;

/**
 * Verification Service for MDM System
 * Handles product verification logic and database operations
 */
class VerificationService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = $this->getDatabaseConnection();
    }

    /**
     * Get items pending verification
     */
    public function getPendingVerificationItems(int $page = 1, int $limit = 20, string $filter = 'all'): array
    {
        $offset = ($page - 1) * $limit;
        
        $whereClause = "WHERE sm.verification_status = 'pending'";
        
        switch ($filter) {
            case 'low_confidence':
                $whereClause .= " AND sm.confidence_score < 0.7";
                break;
            case 'medium_confidence':
                $whereClause .= " AND sm.confidence_score BETWEEN 0.7 AND 0.9";
                break;
            case 'high_confidence':
                $whereClause .= " AND sm.confidence_score > 0.9";
                break;
            case 'no_matches':
                $whereClause .= " AND sm.master_id IS NULL";
                break;
        }
        
        $sql = "
            SELECT 
                sm.id,
                sm.external_sku,
                sm.source,
                sm.source_name,
                sm.source_brand,
                sm.source_category,
                sm.confidence_score,
                sm.master_id,
                sm.created_at,
                mp.canonical_name,
                mp.canonical_brand,
                mp.canonical_category,
                COUNT(*) OVER() as total_count
            FROM sku_mapping sm
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id
            {$whereClause}
            ORDER BY sm.confidence_score DESC, sm.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit, $offset]);
        $items = $stmt->fetchAll();

        $totalCount = $items[0]['total_count'] ?? 0;
        
        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ]
        ];
    }

    /**
     * Get verification statistics
     */
    public function getVerificationStatistics(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total_pending,
                SUM(CASE WHEN confidence_score < 0.7 THEN 1 ELSE 0 END) as low_confidence,
                SUM(CASE WHEN confidence_score BETWEEN 0.7 AND 0.9 THEN 1 ELSE 0 END) as medium_confidence,
                SUM(CASE WHEN confidence_score > 0.9 THEN 1 ELSE 0 END) as high_confidence,
                SUM(CASE WHEN master_id IS NULL THEN 1 ELSE 0 END) as no_matches
            FROM sku_mapping 
            WHERE verification_status = 'pending'
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetch();
    }

    /**
     * Get product comparison details
     */
    public function getProductComparisonDetails(int $skuMappingId): array
    {
        // Get SKU mapping details
        $sql = "
            SELECT 
                sm.*,
                mp.canonical_name,
                mp.canonical_brand,
                mp.canonical_category,
                mp.description as master_description,
                mp.attributes as master_attributes
            FROM sku_mapping sm
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id
            WHERE sm.id = ?
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$skuMappingId]);
        $skuMapping = $stmt->fetch();

        if (!$skuMapping) {
            throw new Exception('SKU mapping not found');
        }

        // Get suggested matches if no master_id assigned
        $suggestedMatches = [];
        if (!$skuMapping['master_id']) {
            $suggestedMatches = $this->findSuggestedMatches($skuMapping);
        }

        return [
            'sku_mapping' => $skuMapping,
            'suggested_matches' => $suggestedMatches
        ];
    }

    /**
     * Find suggested matches for a product
     */
    private function findSuggestedMatches(array $skuMapping): array
    {
        // Simple name-based matching
        $sql = "
            SELECT 
                master_id,
                canonical_name,
                canonical_brand,
                canonical_category,
                (
                    CASE 
                        WHEN LOWER(canonical_name) LIKE LOWER(CONCAT('%', ?, '%')) THEN 0.8
                        WHEN LOWER(canonical_brand) = LOWER(?) THEN 0.6
                        WHEN LOWER(canonical_category) = LOWER(?) THEN 0.4
                        ELSE 0.2
                    END
                ) as match_score
            FROM master_products 
            WHERE status = 'active'
            AND (
                LOWER(canonical_name) LIKE LOWER(CONCAT('%', ?, '%'))
                OR LOWER(canonical_brand) = LOWER(?)
                OR LOWER(canonical_category) = LOWER(?)
            )
            ORDER BY match_score DESC
            LIMIT 5
        ";

        $searchName = $this->extractKeywords($skuMapping['source_name']);
        $searchBrand = $skuMapping['source_brand'] ?? '';
        $searchCategory = $skuMapping['source_category'] ?? '';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $searchName, $searchBrand, $searchCategory,
            $searchName, $searchBrand, $searchCategory
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Extract keywords from product name
     */
    private function extractKeywords(string $name): string
    {
        // Remove common words and extract main keywords
        $commonWords = ['для', 'из', 'на', 'по', 'с', 'в', 'и', 'или', 'но', 'а', 'то', 'что'];
        $words = explode(' ', mb_strtolower($name));
        $keywords = array_filter($words, function($word) use ($commonWords) {
            return strlen($word) > 2 && !in_array($word, $commonWords);
        });
        
        return implode(' ', array_slice($keywords, 0, 3));
    }

    /**
     * Approve a product match
     */
    public function approveMatch(int $skuMappingId, ?string $masterId = null): array
    {
        $this->pdo->beginTransaction();
        
        try {
            // Get current SKU mapping
            $sql = "SELECT * FROM sku_mapping WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$skuMappingId]);
            $skuMapping = $stmt->fetch();

            if (!$skuMapping) {
                throw new Exception('SKU mapping not found');
            }

            // Use provided master_id or existing one
            $finalMasterId = $masterId ?? $skuMapping['master_id'];
            
            if (!$finalMasterId) {
                throw new Exception('Master ID is required for approval');
            }

            // Update SKU mapping
            $sql = "
                UPDATE sku_mapping 
                SET master_id = ?, 
                    verification_status = 'manual',
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$finalMasterId, $skuMappingId]);

            // Log the verification action
            $this->logVerificationAction($skuMappingId, 'approved', $finalMasterId);

            $this->pdo->commit();
            
            return [
                'sku_mapping_id' => $skuMappingId,
                'master_id' => $finalMasterId,
                'status' => 'approved'
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reject a product match
     */
    public function rejectMatch(int $skuMappingId, string $reason = ''): array
    {
        $this->pdo->beginTransaction();
        
        try {
            // Update SKU mapping to rejected status
            $sql = "
                UPDATE sku_mapping 
                SET verification_status = 'rejected',
                    master_id = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$skuMappingId]);

            // Log the verification action
            $this->logVerificationAction($skuMappingId, 'rejected', null, $reason);

            $this->pdo->commit();
            
            return [
                'sku_mapping_id' => $skuMappingId,
                'status' => 'rejected',
                'reason' => $reason
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Create new master product
     */
    public function createNewMasterProduct(int $skuMappingId, array $masterData): array
    {
        $this->pdo->beginTransaction();
        
        try {
            // Get SKU mapping details
            $sql = "SELECT * FROM sku_mapping WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$skuMappingId]);
            $skuMapping = $stmt->fetch();

            if (!$skuMapping) {
                throw new Exception('SKU mapping not found');
            }

            // Generate new master ID
            $masterId = $this->generateMasterId();

            // Create master product
            $sql = "
                INSERT INTO master_products (
                    master_id, canonical_name, canonical_brand, 
                    canonical_category, description, status
                ) VALUES (?, ?, ?, ?, ?, 'active')
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $masterId,
                $masterData['canonical_name'] ?? $skuMapping['source_name'],
                $masterData['canonical_brand'] ?? $skuMapping['source_brand'],
                $masterData['canonical_category'] ?? $skuMapping['source_category'],
                $masterData['description'] ?? ''
            ]);

            // Update SKU mapping
            $sql = "
                UPDATE sku_mapping 
                SET master_id = ?, 
                    verification_status = 'manual',
                    updated_at = NOW()
                WHERE id = ?
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$masterId, $skuMappingId]);

            // Log the verification action
            $this->logVerificationAction($skuMappingId, 'new_master_created', $masterId);

            $this->pdo->commit();
            
            return [
                'sku_mapping_id' => $skuMappingId,
                'master_id' => $masterId,
                'status' => 'new_master_created'
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Bulk approve matches
     */
    public function bulkApproveMatches(array $skuMappingIds): array
    {
        $this->pdo->beginTransaction();
        
        try {
            $results = [];
            
            foreach ($skuMappingIds as $skuMappingId) {
                // Get SKU mapping with suggested master_id
                $sql = "SELECT * FROM sku_mapping WHERE id = ? AND master_id IS NOT NULL";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$skuMappingId]);
                $skuMapping = $stmt->fetch();

                if ($skuMapping) {
                    $result = $this->approveMatch($skuMappingId);
                    $results[] = $result;
                }
            }

            $this->pdo->commit();
            
            return [
                'approved_count' => count($results),
                'results' => $results
            ];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Generate unique master ID
     */
    private function generateMasterId(): string
    {
        do {
            $masterId = 'PROD_' . strtoupper(uniqid());
            
            $sql = "SELECT COUNT(*) FROM master_products WHERE master_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$masterId]);
            $exists = $stmt->fetchColumn() > 0;
        } while ($exists);
        
        return $masterId;
    }

    /**
     * Log verification action
     */
    private function logVerificationAction(int $skuMappingId, string $action, ?string $masterId = null, string $reason = ''): void
    {
        // Create verification_log table if it doesn't exist
        $sql = "
            CREATE TABLE IF NOT EXISTS verification_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                sku_mapping_id BIGINT NOT NULL,
                action VARCHAR(50) NOT NULL,
                master_id VARCHAR(50),
                reason TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sku_mapping (sku_mapping_id),
                INDEX idx_action (action)
            )
        ";
        
        $this->pdo->exec($sql);

        // Insert log entry
        $sql = "
            INSERT INTO verification_log (sku_mapping_id, action, master_id, reason)
            VALUES (?, ?, ?, ?)
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$skuMappingId, $action, $masterId, $reason]);
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