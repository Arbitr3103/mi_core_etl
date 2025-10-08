<?php

namespace MDM\Repositories;

use MDM\Models\DataQualityMetric;
use PDO;
use PDOException;
use InvalidArgumentException;
use DateTime;

/**
 * Data Quality Metric Repository
 * 
 * Handles database operations for Data Quality Metric entities.
 */
class DataQualityMetricRepository extends BaseRepository
{
    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo, 'data_quality_metrics', 'id');
    }

    /**
     * Save a data quality metric (insert or update)
     */
    public function save(object $entity): bool
    {
        if (!$entity instanceof DataQualityMetric) {
            throw new InvalidArgumentException('Entity must be an instance of DataQualityMetric');
        }

        $data = $this->entityToArray($entity);
        
        // Check if record exists by ID
        if ($entity->getId() && $this->exists(['id' => $entity->getId()])) {
            return $this->update($entity);
        } else {
            return $this->insert($entity);
        }
    }

    /**
     * Insert a new data quality metric
     */
    private function insert(DataQualityMetric $metric): bool
    {
        $data = $this->entityToArray($metric);
        
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
                $metric->setId((int) $this->getLastInsertId());
                return true;
            }
            return false;
        } catch (PDOException $e) {
            throw new InvalidArgumentException("Failed to insert data quality metric: " . $e->getMessage());
        }
    }

    /**
     * Update an existing data quality metric
     */
    private function update(DataQualityMetric $metric): bool
    {
        $data = $this->entityToArray($metric);
        
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
            throw new InvalidArgumentException("Failed to update data quality metric: " . $e->getMessage());
        }
    }

    /**
     * Delete a data quality metric
     */
    public function delete(object $entity): bool
    {
        if (!$entity instanceof DataQualityMetric) {
            throw new InvalidArgumentException('Entity must be an instance of DataQualityMetric');
        }

        return $this->deleteById($entity->getId());
    }

    /**
     * Find metrics by name
     */
    public function findByMetricName(string $metricName, ?string $source = null): array
    {
        $criteria = ['metric_name' => $metricName];
        if ($source !== null) {
            $criteria['source'] = $source;
        }
        
        return $this->findBy($criteria, ['calculation_date' => 'DESC']);
    }

    /**
     * Find metrics by source
     */
    public function findBySource(string $source): array
    {
        return $this->findBy(['source' => $source], ['calculation_date' => 'DESC']);
    }

    /**
     * Find metrics by category
     */
    public function findByCategory(string $category): array
    {
        return $this->findBy(['category' => $category], ['calculation_date' => 'DESC']);
    }

    /**
     * Get latest metrics (most recent calculation for each metric/source combination)
     */
    public function getLatestMetrics(): array
    {
        $sql = "SELECT dqm.* FROM {$this->tableName} dqm
                INNER JOIN (
                    SELECT metric_name, source, MAX(calculation_date) as latest_date
                    FROM {$this->tableName}
                    GROUP BY metric_name, source
                ) latest ON dqm.metric_name = latest.metric_name 
                    AND (dqm.source = latest.source OR (dqm.source IS NULL AND latest.source IS NULL))
                    AND dqm.calculation_date = latest.latest_date
                ORDER BY dqm.metric_name, dqm.source";
        
        $stmt = $this->pdo->query($sql);
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }

    /**
     * Get latest metric by name and source
     */
    public function getLatestMetric(string $metricName, ?string $source = null): ?DataQualityMetric
    {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE metric_name = :metric_name";
        
        $params = [':metric_name' => $metricName];
        
        if ($source !== null) {
            $sql .= " AND source = :source";
            $params[':source'] = $source;
        } else {
            $sql .= " AND source IS NULL";
        }
        
        $sql .= " ORDER BY calculation_date DESC LIMIT 1";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        return $data ? $this->createEntityFromArray($data) : null;
    }

    /**
     * Get metrics trend (historical data for a specific metric)
     */
    public function getMetricTrend(string $metricName, ?string $source = null, int $days = 30): array
    {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE metric_name = :metric_name 
                AND calculation_date >= DATE_SUB(NOW(), INTERVAL :days DAY)";
        
        $params = [
            ':metric_name' => $metricName,
            ':days' => $days
        ];
        
        if ($source !== null) {
            $sql .= " AND source = :source";
            $params[':source'] = $source;
        } else {
            $sql .= " AND source IS NULL";
        }
        
        $sql .= " ORDER BY calculation_date ASC";
        
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }

    /**
     * Get quality summary by category
     */
    public function getQualitySummaryByCategory(): array
    {
        $sql = "SELECT 
                    category,
                    COUNT(*) as metric_count,
                    AVG(metric_value) as avg_quality_score,
                    MIN(metric_value) as min_quality_score,
                    MAX(metric_value) as max_quality_score,
                    COUNT(CASE WHEN metric_value >= 0.8 THEN 1 END) as good_metrics,
                    COUNT(CASE WHEN metric_value < 0.6 THEN 1 END) as poor_metrics
                FROM {$this->tableName}
                WHERE category IS NOT NULL
                GROUP BY category
                ORDER BY avg_quality_score DESC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get quality summary by source
     */
    public function getQualitySummaryBySource(): array
    {
        $sql = "SELECT 
                    source,
                    COUNT(*) as metric_count,
                    AVG(metric_value) as avg_quality_score,
                    MIN(metric_value) as min_quality_score,
                    MAX(metric_value) as max_quality_score,
                    COUNT(CASE WHEN metric_value >= 0.8 THEN 1 END) as good_metrics,
                    COUNT(CASE WHEN metric_value < 0.6 THEN 1 END) as poor_metrics
                FROM {$this->tableName}
                WHERE source IS NOT NULL
                GROUP BY source
                ORDER BY avg_quality_score DESC";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find poor quality metrics (below threshold)
     */
    public function findPoorQualityMetrics(float $threshold = 0.6): array
    {
        $sql = "SELECT * FROM {$this->tableName} 
                WHERE metric_value < :threshold
                ORDER BY metric_value ASC, calculation_date DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':threshold', $threshold);
        $stmt->execute();
        
        $results = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->createEntityFromArray($data);
        }
        
        return $results;
    }

    /**
     * Calculate and save completeness metric
     */
    public function calculateAndSaveCompletenessMetric(string $source): DataQualityMetric
    {
        // Get master products completeness data
        $sql = "SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN canonical_name IS NOT NULL AND canonical_name != '' 
                               AND canonical_brand IS NOT NULL AND canonical_brand != ''
                               AND canonical_category IS NOT NULL AND canonical_category != '' 
                          THEN 1 END) as complete_products
                FROM master_products 
                WHERE status = 'active'";
        
        $stmt = $this->pdo->query($sql);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $metric = DataQualityMetric::createCompletenessMetric(
            source: $source,
            totalRecords: (int) $data['total_products'],
            completeRecords: (int) $data['complete_products'],
            details: [
                'incomplete_products' => $data['total_products'] - $data['complete_products'],
                'calculation_method' => 'name_brand_category_required'
            ]
        );
        
        $this->save($metric);
        return $metric;
    }

    /**
     * Calculate and save coverage metric
     */
    public function calculateAndSaveCoverageMetric(string $source): DataQualityMetric
    {
        // Get SKU mapping coverage data
        $sql = "SELECT 
                    COUNT(*) as total_mappings,
                    COUNT(CASE WHEN verification_status IN ('auto', 'manual') THEN 1 END) as verified_mappings
                FROM sku_mapping";
        
        $stmt = $this->pdo->query($sql);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $metric = DataQualityMetric::createCoverageMetric(
            source: $source,
            totalRecords: (int) $data['total_mappings'],
            coveredRecords: (int) $data['verified_mappings'],
            details: [
                'pending_mappings' => $data['total_mappings'] - $data['verified_mappings'],
                'calculation_method' => 'verified_vs_total_mappings'
            ]
        );
        
        $this->save($metric);
        return $metric;
    }

    /**
     * Calculate and save accuracy metric
     */
    public function calculateAndSaveAccuracyMetric(string $source): DataQualityMetric
    {
        // Get auto-matching accuracy data
        $sql = "SELECT 
                    COUNT(*) as total_auto_matches,
                    AVG(confidence_score) as avg_confidence,
                    COUNT(CASE WHEN confidence_score >= 0.8 THEN 1 END) as high_confidence_matches
                FROM sku_mapping 
                WHERE verification_status = 'auto' 
                AND confidence_score IS NOT NULL";
        
        $stmt = $this->pdo->query($sql);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $metric = DataQualityMetric::createAccuracyMetric(
            source: $source,
            totalRecords: (int) $data['total_auto_matches'],
            accurateRecords: (int) $data['high_confidence_matches'],
            details: [
                'avg_confidence_score' => (float) $data['avg_confidence'],
                'calculation_method' => 'high_confidence_auto_matches'
            ]
        );
        
        $this->save($metric);
        return $metric;
    }

    /**
     * Delete old metrics (cleanup)
     */
    public function deleteOldMetrics(int $daysToKeep = 90): int
    {
        $sql = "DELETE FROM {$this->tableName} 
                WHERE calculation_date < DATE_SUB(NOW(), INTERVAL :days DAY)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':days', $daysToKeep, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->rowCount();
    }

    /**
     * Get overall quality score
     */
    public function getOverallQualityScore(): array
    {
        $latestMetrics = $this->getLatestMetrics();
        
        if (empty($latestMetrics)) {
            return [
                'overall_score' => 0.0,
                'quality_level' => 'unknown',
                'metric_count' => 0,
                'categories' => []
            ];
        }
        
        $totalScore = 0.0;
        $categories = [];
        
        foreach ($latestMetrics as $metric) {
            $totalScore += $metric->getMetricValue();
            
            $category = $metric->getCategory() ?? 'general';
            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'score' => 0.0,
                    'count' => 0,
                    'metrics' => []
                ];
            }
            
            $categories[$category]['score'] += $metric->getMetricValue();
            $categories[$category]['count']++;
            $categories[$category]['metrics'][] = $metric->getMetricName();
        }
        
        $overallScore = $totalScore / count($latestMetrics);
        
        // Calculate average score per category
        foreach ($categories as $category => &$data) {
            $data['avg_score'] = $data['score'] / $data['count'];
        }
        
        return [
            'overall_score' => $overallScore,
            'quality_level' => $this->getQualityLevel($overallScore),
            'metric_count' => count($latestMetrics),
            'categories' => $categories
        ];
    }

    /**
     * Get quality level from score
     */
    private function getQualityLevel(float $score): string
    {
        if ($score >= 0.9) {
            return 'excellent';
        } elseif ($score >= 0.8) {
            return 'good';
        } elseif ($score >= 0.6) {
            return 'fair';
        } else {
            return 'poor';
        }
    }

    /**
     * Create entity from database array
     */
    protected function createEntityFromArray(array $data): DataQualityMetric
    {
        return DataQualityMetric::fromArray($data);
    }

    /**
     * Convert entity to database array
     */
    protected function entityToArray(object $entity): array
    {
        if (!$entity instanceof DataQualityMetric) {
            throw new InvalidArgumentException('Entity must be an instance of DataQualityMetric');
        }
        
        return $entity->toArray();
    }
}