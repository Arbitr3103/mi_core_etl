<?php

namespace MDM\Services;

/**
 * Data Quality Service for MDM System
 * Calculates and monitors data quality metrics
 */
class DataQualityService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = $this->getDatabaseConnection();
    }

    /**
     * Get current data quality metrics
     */
    public function getCurrentMetrics(): array
    {
        return [
            'completeness' => $this->calculateCompleteness(),
            'accuracy' => $this->calculateAccuracy(),
            'consistency' => $this->calculateConsistency(),
            'coverage' => $this->calculateCoverage(),
            'freshness' => $this->calculateFreshness(),
            'matching_performance' => $this->calculateMatchingPerformance(),
            'system_performance' => $this->calculateSystemPerformance()
        ];
    }

    /**
     * Get historical quality trends
     */
    public function getQualityTrends(int $days = 30): array
    {
        $sql = "
            SELECT 
                DATE(calculation_date) as date,
                metric_name,
                metric_value
            FROM data_quality_metrics 
            WHERE calculation_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY calculation_date DESC, metric_name
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$days]);
        
        $trends = [];
        while ($row = $stmt->fetch()) {
            $trends[$row['date']][$row['metric_name']] = $row['metric_value'];
        }
        
        return $trends;
    }

    /**
     * Update quality metrics in database
     */
    public function updateQualityMetrics(): void
    {
        $metrics = $this->getCurrentMetrics();
        
        foreach ($metrics as $metricName => $metricData) {
            $this->saveMetric($metricName, $metricData);
        }
    }

    /**
     * Calculate master data coverage percentage by source
     */
    public function getMasterDataCoverageBySource(): array
    {
        $sql = "
            SELECT 
                sm.source,
                COUNT(DISTINCT sm.external_sku) as total_skus,
                COUNT(DISTINCT CASE WHEN mp.status = 'active' THEN sm.external_sku END) as mapped_skus,
                ROUND(COUNT(DISTINCT CASE WHEN mp.status = 'active' THEN sm.external_sku END) / COUNT(DISTINCT sm.external_sku) * 100, 2) as coverage_percentage
            FROM sku_mapping sm
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id
            GROUP BY sm.source
            ORDER BY coverage_percentage DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Get detailed attribute completeness metrics
     */
    public function getAttributeCompletenessDetails(): array
    {
        $sql = "
            SELECT 
                'canonical_name' as attribute_name,
                COUNT(*) as total_products,
                SUM(CASE WHEN canonical_name IS NOT NULL AND canonical_name != '' THEN 1 ELSE 0 END) as filled_count,
                ROUND(SUM(CASE WHEN canonical_name IS NOT NULL AND canonical_name != '' THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as completeness_percentage
            FROM master_products WHERE status = 'active'
            
            UNION ALL
            
            SELECT 
                'canonical_brand' as attribute_name,
                COUNT(*) as total_products,
                SUM(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != '' AND canonical_brand != 'Неизвестный бренд' THEN 1 ELSE 0 END) as filled_count,
                ROUND(SUM(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != '' AND canonical_brand != 'Неизвестный бренд' THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as completeness_percentage
            FROM master_products WHERE status = 'active'
            
            UNION ALL
            
            SELECT 
                'canonical_category' as attribute_name,
                COUNT(*) as total_products,
                SUM(CASE WHEN canonical_category IS NOT NULL AND canonical_category != '' AND canonical_category != 'Без категории' THEN 1 ELSE 0 END) as filled_count,
                ROUND(SUM(CASE WHEN canonical_category IS NOT NULL AND canonical_category != '' AND canonical_category != 'Без категории' THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as completeness_percentage
            FROM master_products WHERE status = 'active'
            
            UNION ALL
            
            SELECT 
                'description' as attribute_name,
                COUNT(*) as total_products,
                SUM(CASE WHEN description IS NOT NULL AND description != '' THEN 1 ELSE 0 END) as filled_count,
                ROUND(SUM(CASE WHEN description IS NOT NULL AND description != '' THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as completeness_percentage
            FROM master_products WHERE status = 'active'
            
            UNION ALL
            
            SELECT 
                'attributes' as attribute_name,
                COUNT(*) as total_products,
                SUM(CASE WHEN attributes IS NOT NULL AND JSON_LENGTH(attributes) > 0 THEN 1 ELSE 0 END) as filled_count,
                ROUND(SUM(CASE WHEN attributes IS NOT NULL AND JSON_LENGTH(attributes) > 0 THEN 1 ELSE 0 END) / COUNT(*) * 100, 2) as completeness_percentage
            FROM master_products WHERE status = 'active'
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Get matching accuracy trends over time
     */
    public function getMatchingAccuracyTrends(int $days = 30): array
    {
        $sql = "
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as total_matches,
                SUM(CASE WHEN confidence_score >= 0.9 THEN 1 ELSE 0 END) as high_confidence,
                SUM(CASE WHEN confidence_score >= 0.7 AND confidence_score < 0.9 THEN 1 ELSE 0 END) as medium_confidence,
                SUM(CASE WHEN confidence_score < 0.7 THEN 1 ELSE 0 END) as low_confidence,
                AVG(confidence_score) as avg_confidence
            FROM sku_mapping 
            WHERE verification_status = 'auto'
              AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$days]);
        
        return $stmt->fetchAll();
    }

    /**
     * Calculate data completeness
     */
    private function calculateCompleteness(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN canonical_name IS NOT NULL AND canonical_name != '' THEN 1 ELSE 0 END) as has_name,
                SUM(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != '' AND canonical_brand != 'Неизвестный бренд' THEN 1 ELSE 0 END) as has_brand,
                SUM(CASE WHEN canonical_category IS NOT NULL AND canonical_category != '' AND canonical_category != 'Без категории' THEN 1 ELSE 0 END) as has_category,
                SUM(CASE WHEN description IS NOT NULL AND description != '' THEN 1 ELSE 0 END) as has_description
            FROM master_products 
            WHERE status = 'active'
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        if ($result['total'] == 0) {
            return [
                'overall' => 0,
                'name' => 0,
                'brand' => 0,
                'category' => 0,
                'description' => 0,
                'total_products' => 0
            ];
        }

        $total = $result['total'];
        
        return [
            'overall' => round(($result['has_name'] + $result['has_brand'] + $result['has_category'] + $result['has_description']) / ($total * 4) * 100, 2),
            'name' => round($result['has_name'] / $total * 100, 2),
            'brand' => round($result['has_brand'] / $total * 100, 2),
            'category' => round($result['has_category'] / $total * 100, 2),
            'description' => round($result['has_description'] / $total * 100, 2),
            'total_products' => $total
        ];
    }

    /**
     * Calculate data accuracy based on verification status
     */
    private function calculateAccuracy(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN verification_status = 'manual' THEN 1 ELSE 0 END) as manually_verified,
                SUM(CASE WHEN verification_status = 'auto' AND confidence_score >= 0.9 THEN 1 ELSE 0 END) as high_confidence_auto,
                AVG(confidence_score) as avg_confidence
            FROM sku_mapping
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        if ($result['total'] == 0) {
            return [
                'overall' => 0,
                'verified_percentage' => 0,
                'avg_confidence' => 0,
                'total_mappings' => 0
            ];
        }

        $total = $result['total'];
        $verified = $result['manually_verified'] + $result['high_confidence_auto'];
        
        return [
            'overall' => round($verified / $total * 100, 2),
            'verified_percentage' => round($verified / $total * 100, 2),
            'avg_confidence' => round($result['avg_confidence'], 3),
            'total_mappings' => $total
        ];
    }

    /**
     * Calculate data consistency
     */
    private function calculateConsistency(): array
    {
        // Check for duplicate master products with similar names
        $sql = "
            SELECT COUNT(*) as potential_duplicates
            FROM (
                SELECT canonical_name, COUNT(*) as cnt
                FROM master_products 
                WHERE status = 'active'
                GROUP BY canonical_name
                HAVING cnt > 1
            ) as duplicates
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $duplicates = $stmt->fetch()['potential_duplicates'];

        // Check for inconsistent brand names
        $sql = "
            SELECT COUNT(DISTINCT canonical_brand) as unique_brands,
                   COUNT(*) as total_products
            FROM master_products 
            WHERE status = 'active' 
              AND canonical_brand IS NOT NULL 
              AND canonical_brand != ''
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $brandData = $stmt->fetch();

        $totalProducts = $this->getTotalActiveProducts();
        
        return [
            'overall' => $totalProducts > 0 ? round((1 - $duplicates / $totalProducts) * 100, 2) : 100,
            'duplicate_names' => $duplicates,
            'brand_consistency' => $brandData['total_products'] > 0 ? round($brandData['unique_brands'] / $brandData['total_products'] * 100, 2) : 0,
            'total_products' => $totalProducts
        ];
    }

    /**
     * Calculate coverage percentage
     */
    private function calculateCoverage(): array
    {
        // Calculate how many external SKUs are mapped to master products
        $sql = "
            SELECT 
                COUNT(DISTINCT sm.external_sku) as mapped_skus,
                (SELECT COUNT(DISTINCT source) FROM sku_mapping) as sources_count
            FROM sku_mapping sm
            INNER JOIN master_products mp ON sm.master_id = mp.master_id
            WHERE mp.status = 'active'
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        // Get total unique SKUs across all sources
        $sql = "SELECT COUNT(DISTINCT external_sku) as total_skus FROM sku_mapping";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $totalSkus = $stmt->fetch()['total_skus'];

        return [
            'overall' => $totalSkus > 0 ? round($result['mapped_skus'] / $totalSkus * 100, 2) : 0,
            'mapped_skus' => $result['mapped_skus'],
            'total_skus' => $totalSkus,
            'sources_covered' => $result['sources_count']
        ];
    }

    /**
     * Calculate data freshness
     */
    private function calculateFreshness(): array
    {
        $sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) as updated_today,
                SUM(CASE WHEN updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as updated_week,
                MAX(updated_at) as last_update
            FROM master_products
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        if ($result['total'] == 0) {
            return [
                'overall' => 0,
                'updated_today' => 0,
                'updated_week' => 0,
                'last_update' => null
            ];
        }

        return [
            'overall' => round($result['updated_week'] / $result['total'] * 100, 2),
            'updated_today' => $result['updated_today'],
            'updated_week' => $result['updated_week'],
            'last_update' => $result['last_update']
        ];
    }

    /**
     * Calculate automatic matching performance metrics
     */
    private function calculateMatchingPerformance(): array
    {
        // Get automatic matching accuracy
        $sql = "
            SELECT 
                COUNT(*) as total_auto_matches,
                SUM(CASE WHEN confidence_score >= 0.9 THEN 1 ELSE 0 END) as high_confidence,
                SUM(CASE WHEN confidence_score >= 0.7 AND confidence_score < 0.9 THEN 1 ELSE 0 END) as medium_confidence,
                SUM(CASE WHEN confidence_score < 0.7 THEN 1 ELSE 0 END) as low_confidence,
                AVG(confidence_score) as avg_confidence,
                MIN(confidence_score) as min_confidence,
                MAX(confidence_score) as max_confidence
            FROM sku_mapping 
            WHERE verification_status = 'auto'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        // Get manual override rate (how often auto matches are corrected)
        $sql = "
            SELECT COUNT(*) as manual_overrides
            FROM sku_mapping 
            WHERE verification_status = 'manual'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $overrides = $stmt->fetch()['manual_overrides'];

        $total = $result['total_auto_matches'] + $overrides;

        return [
            'overall' => $result['total_auto_matches'] > 0 ? round($result['high_confidence'] / $result['total_auto_matches'] * 100, 2) : 0,
            'auto_match_rate' => $total > 0 ? round($result['total_auto_matches'] / $total * 100, 2) : 0,
            'high_confidence_rate' => $result['total_auto_matches'] > 0 ? round($result['high_confidence'] / $result['total_auto_matches'] * 100, 2) : 0,
            'medium_confidence_rate' => $result['total_auto_matches'] > 0 ? round($result['medium_confidence'] / $result['total_auto_matches'] * 100, 2) : 0,
            'low_confidence_rate' => $result['total_auto_matches'] > 0 ? round($result['low_confidence'] / $result['total_auto_matches'] * 100, 2) : 0,
            'avg_confidence' => round($result['avg_confidence'] ?? 0, 3),
            'confidence_range' => [
                'min' => round($result['min_confidence'] ?? 0, 3),
                'max' => round($result['max_confidence'] ?? 0, 3)
            ],
            'manual_override_rate' => $total > 0 ? round($overrides / $total * 100, 2) : 0,
            'total_matches_30d' => $total
        ];
    }

    /**
     * Calculate system performance metrics
     */
    private function calculateSystemPerformance(): array
    {
        // Get processing speed metrics from ETL logs if available
        $sql = "
            SELECT 
                AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) as avg_processing_time,
                COUNT(*) as processed_today
            FROM sku_mapping 
            WHERE DATE(created_at) = CURDATE()
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();

        // Get database performance indicators
        $sql = "
            SELECT 
                COUNT(*) as total_records,
                (SELECT COUNT(*) FROM master_products WHERE status = 'active') as active_products,
                (SELECT COUNT(*) FROM sku_mapping WHERE verification_status = 'pending') as pending_verification
            FROM sku_mapping
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $dbStats = $stmt->fetch();

        // Get error rate from recent operations
        $sql = "
            SELECT COUNT(*) as failed_operations
            FROM data_quality_metrics 
            WHERE metric_name = 'processing_errors'
              AND calculation_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $errors = $stmt->fetch()['failed_operations'];

        return [
            'overall' => $this->calculateOverallSystemHealth($dbStats, $errors),
            'avg_processing_time' => round($result['avg_processing_time'] ?? 0, 2),
            'throughput_today' => $result['processed_today'],
            'pending_queue_size' => $dbStats['pending_verification'],
            'database_size' => $dbStats['total_records'],
            'active_products_count' => $dbStats['active_products'],
            'error_rate_24h' => $errors,
            'system_load' => $this->calculateSystemLoad($dbStats)
        ];
    }

    /**
     * Calculate overall system health score
     */
    private function calculateOverallSystemHealth(array $dbStats, int $errors): float
    {
        $healthScore = 100;
        
        // Reduce score based on pending queue size
        $pendingRatio = $dbStats['total_records'] > 0 ? $dbStats['pending_verification'] / $dbStats['total_records'] : 0;
        $healthScore -= $pendingRatio * 30; // Max 30 points deduction for pending items
        
        // Reduce score based on error rate
        $healthScore -= min($errors * 5, 40); // Max 40 points deduction for errors
        
        return max(round($healthScore, 2), 0);
    }

    /**
     * Calculate system load indicator
     */
    private function calculateSystemLoad(array $dbStats): string
    {
        $pendingRatio = $dbStats['total_records'] > 0 ? $dbStats['pending_verification'] / $dbStats['total_records'] : 0;
        
        if ($pendingRatio > 0.2) return 'high';
        if ($pendingRatio > 0.1) return 'medium';
        return 'low';
    }

    /**
     * Save metric to database
     */
    private function saveMetric(string $metricName, array $metricData): void
    {
        $sql = "
            INSERT INTO data_quality_metrics (metric_name, metric_value, total_records, good_records)
            VALUES (?, ?, ?, ?)
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $metricName,
            $metricData['overall'] ?? 0,
            $metricData['total_products'] ?? $metricData['total_mappings'] ?? $metricData['total_skus'] ?? 0,
            $metricData['verified_percentage'] ?? $metricData['mapped_skus'] ?? $metricData['updated_week'] ?? 0
        ]);
    }

    /**
     * Get total active products count
     */
    private function getTotalActiveProducts(): int
    {
        $sql = "SELECT COUNT(*) as count FROM master_products WHERE status = 'active'";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return (int) $stmt->fetch()['count'];
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