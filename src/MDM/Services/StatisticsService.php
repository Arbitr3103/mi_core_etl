<?php

namespace MDM\Services;

/**
 * Statistics Service for MDM System
 * Provides various statistics and metrics for the dashboard
 */
class StatisticsService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = $this->getDatabaseConnection();
    }

    /**
     * Get overall system statistics
     */
    public function getOverallStatistics(): array
    {
        return [
            'total_master_products' => $this->getTotalMasterProducts(),
            'total_sku_mappings' => $this->getTotalSkuMappings(),
            'coverage_percentage' => $this->getCoveragePercentage(),
            'sources_count' => $this->getSourcesCount(),
            'active_products' => $this->getActiveProductsCount(),
            'pending_verification' => $this->getPendingVerificationCount()
        ];
    }

    /**
     * Get recent activity data
     */
    public function getRecentActivity(): array
    {
        $sql = "
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as new_mappings
            FROM sku_mapping 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Get count of items pending verification
     */
    public function getPendingItemsCount(): array
    {
        return [
            'pending_verification' => $this->getPendingVerificationCount(),
            'low_confidence_matches' => $this->getLowConfidenceMatchesCount(),
            'incomplete_data' => $this->getIncompleteDataCount()
        ];
    }

    /**
     * Get ETL process status
     */
    public function getETLStatus(): array
    {
        // Check if ETL monitoring table exists
        try {
            $sql = "
                SELECT 
                    status,
                    started_at,
                    completed_at,
                    records_processed,
                    errors_count
                FROM etl_job_logs 
                ORDER BY started_at DESC 
                LIMIT 1
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $lastJob = $stmt->fetch();

            if ($lastJob) {
                return [
                    'status' => $lastJob['status'],
                    'last_run' => $lastJob['started_at'],
                    'records_processed' => $lastJob['records_processed'],
                    'errors' => $lastJob['errors_count']
                ];
            }
        } catch (\Exception $e) {
            // ETL monitoring table might not exist yet
        }

        return [
            'status' => 'unknown',
            'last_run' => null,
            'records_processed' => 0,
            'errors' => 0
        ];
    }

    /**
     * Get last synchronization time
     */
    public function getLastSyncTime(): ?string
    {
        try {
            $sql = "
                SELECT MAX(updated_at) as last_sync 
                FROM sku_mapping
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            
            return $result['last_sync'];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get total number of master products
     */
    private function getTotalMasterProducts(): int
    {
        $sql = "SELECT COUNT(*) as count FROM master_products";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return (int) $result['count'];
    }

    /**
     * Get total number of SKU mappings
     */
    private function getTotalSkuMappings(): int
    {
        $sql = "SELECT COUNT(*) as count FROM sku_mapping";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return (int) $result['count'];
    }

    /**
     * Calculate coverage percentage
     */
    private function getCoveragePercentage(): float
    {
        $sql = "
            SELECT 
                (COUNT(DISTINCT sm.external_sku) * 100.0 / 
                 (SELECT COUNT(*) FROM (
                     SELECT DISTINCT external_sku FROM sku_mapping
                     UNION
                     SELECT DISTINCT sku FROM products WHERE sku IS NOT NULL
                 ) as all_skus)) as coverage
            FROM sku_mapping sm
            INNER JOIN master_products mp ON sm.master_id = mp.master_id
            WHERE mp.status = 'active'
        ";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch();
            
            return round((float) ($result['coverage'] ?? 0), 2);
        } catch (\Exception $e) {
            // Fallback calculation if products table doesn't exist
            return 0.0;
        }
    }

    /**
     * Get number of different data sources
     */
    private function getSourcesCount(): int
    {
        $sql = "SELECT COUNT(DISTINCT source) as count FROM sku_mapping";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return (int) $result['count'];
    }

    /**
     * Get count of active products
     */
    private function getActiveProductsCount(): int
    {
        $sql = "SELECT COUNT(*) as count FROM master_products WHERE status = 'active'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return (int) $result['count'];
    }

    /**
     * Get count of items pending verification
     */
    private function getPendingVerificationCount(): int
    {
        $sql = "SELECT COUNT(*) as count FROM sku_mapping WHERE verification_status = 'pending'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return (int) $result['count'];
    }

    /**
     * Get count of low confidence matches
     */
    private function getLowConfidenceMatchesCount(): int
    {
        $sql = "SELECT COUNT(*) as count FROM sku_mapping WHERE confidence_score < 0.7 AND verification_status = 'pending'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return (int) $result['count'];
    }

    /**
     * Get count of products with incomplete data
     */
    private function getIncompleteDataCount(): int
    {
        $sql = "
            SELECT COUNT(*) as count 
            FROM master_products 
            WHERE canonical_brand IS NULL 
               OR canonical_brand = '' 
               OR canonical_category IS NULL 
               OR canonical_category = ''
               OR canonical_brand = 'Неизвестный бренд'
               OR canonical_category = 'Без категории'
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        return (int) $result['count'];
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