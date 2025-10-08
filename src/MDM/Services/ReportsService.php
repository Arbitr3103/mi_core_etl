<?php

namespace MDM\Services;

/**
 * Reports Service for MDM System
 * Generates various data quality and analytics reports
 */
class ReportsService
{
    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = $this->getDatabaseConnection();
    }

    /**
     * Get report summary for dashboard
     */
    public function getReportSummary(): array
    {
        return [
            'coverage' => $this->getCoverageSummary(),
            'quality' => $this->getQualitySummary(),
            'problematic' => $this->getProblematicSummary(),
            'recent_reports' => $this->getRecentReports()
        ];
    }

    /**
     * Generate coverage report
     */
    public function generateCoverageReport(): array
    {
        // Overall coverage statistics
        $sql = "
            SELECT 
                COUNT(DISTINCT sm.external_sku) as mapped_skus,
                COUNT(DISTINCT sm.source) as sources_with_mappings,
                COUNT(DISTINCT mp.master_id) as master_products_with_mappings
            FROM sku_mapping sm
            INNER JOIN master_products mp ON sm.master_id = mp.master_id
            WHERE mp.status = 'active'
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $overall = $stmt->fetch();

        // Coverage by source
        $sql = "
            SELECT 
                sm.source,
                COUNT(DISTINCT sm.external_sku) as total_skus,
                COUNT(DISTINCT CASE WHEN mp.master_id IS NOT NULL THEN sm.external_sku END) as mapped_skus,
                ROUND(COUNT(DISTINCT CASE WHEN mp.master_id IS NOT NULL THEN sm.external_sku END) * 100.0 / COUNT(DISTINCT sm.external_sku), 2) as coverage_percentage
            FROM sku_mapping sm
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active'
            GROUP BY sm.source
            ORDER BY coverage_percentage DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $bySource = $stmt->fetchAll();

        // Coverage trends (last 30 days)
        $sql = "
            SELECT 
                DATE(sm.created_at) as date,
                COUNT(DISTINCT sm.external_sku) as new_mappings,
                COUNT(DISTINCT mp.master_id) as new_master_products
            FROM sku_mapping sm
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id
            WHERE sm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY DATE(sm.created_at)
            ORDER BY date DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $trends = $stmt->fetchAll();

        return [
            'overall' => $overall,
            'by_source' => $bySource,
            'trends' => $trends,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Generate incomplete data report
     */
    public function generateIncompleteDataReport(int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;
        
        // Get products with incomplete data
        $sql = "
            SELECT 
                master_id,
                canonical_name,
                canonical_brand,
                canonical_category,
                description,
                created_at,
                updated_at,
                CASE 
                    WHEN canonical_brand IS NULL OR canonical_brand = '' OR canonical_brand = 'Неизвестный бренд' THEN 1 
                    ELSE 0 
                END as missing_brand,
                CASE 
                    WHEN canonical_category IS NULL OR canonical_category = '' OR canonical_category = 'Без категории' THEN 1 
                    ELSE 0 
                END as missing_category,
                CASE 
                    WHEN description IS NULL OR description = '' THEN 1 
                    ELSE 0 
                END as missing_description
            FROM master_products 
            WHERE status = 'active'
            AND (
                canonical_brand IS NULL OR canonical_brand = '' OR canonical_brand = 'Неизвестный бренд'
                OR canonical_category IS NULL OR canonical_category = '' OR canonical_category = 'Без категории'
                OR description IS NULL OR description = ''
            )
            ORDER BY updated_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit, $offset]);
        $products = $stmt->fetchAll();

        // Get total count
        $countSql = "
            SELECT COUNT(*) 
            FROM master_products 
            WHERE status = 'active'
            AND (
                canonical_brand IS NULL OR canonical_brand = '' OR canonical_brand = 'Неизвестный бренд'
                OR canonical_category IS NULL OR canonical_category = '' OR canonical_category = 'Без категории'
                OR description IS NULL OR description = ''
            )
        ";
        
        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute();
        $totalCount = $stmt->fetchColumn();

        // Get summary statistics
        $sql = "
            SELECT 
                SUM(CASE WHEN canonical_brand IS NULL OR canonical_brand = '' OR canonical_brand = 'Неизвестный бренд' THEN 1 ELSE 0 END) as missing_brand_count,
                SUM(CASE WHEN canonical_category IS NULL OR canonical_category = '' OR canonical_category = 'Без категории' THEN 1 ELSE 0 END) as missing_category_count,
                SUM(CASE WHEN description IS NULL OR description = '' THEN 1 ELSE 0 END) as missing_description_count
            FROM master_products 
            WHERE status = 'active'
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $summary = $stmt->fetch();

        return [
            'products' => $products,
            'summary' => $summary,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Generate problematic products report
     */
    public function generateProblematicProductsReport(string $type, int $page = 1, int $limit = 50): array
    {
        $offset = ($page - 1) * $limit;
        
        $whereConditions = [];
        switch ($type) {
            case 'unknown_brand':
                $whereConditions[] = "(canonical_brand IS NULL OR canonical_brand = '' OR canonical_brand = 'Неизвестный бренд')";
                break;
            case 'no_category':
                $whereConditions[] = "(canonical_category IS NULL OR canonical_category = '' OR canonical_category = 'Без категории')";
                break;
            case 'no_mappings':
                $whereConditions[] = "master_id NOT IN (SELECT DISTINCT master_id FROM sku_mapping WHERE master_id IS NOT NULL)";
                break;
            case 'duplicate_names':
                // This requires a more complex query
                return $this->getDuplicateNamesReport($page, $limit);
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $sql = "
            SELECT 
                mp.master_id,
                mp.canonical_name,
                mp.canonical_brand,
                mp.canonical_category,
                mp.created_at,
                mp.updated_at,
                COUNT(sm.id) as mapping_count
            FROM master_products mp
            LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
            WHERE mp.status = 'active' AND {$whereClause}
            GROUP BY mp.master_id
            ORDER BY mp.updated_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit, $offset]);
        $products = $stmt->fetchAll();

        // Get total count
        $countSql = "
            SELECT COUNT(*) 
            FROM master_products mp
            WHERE mp.status = 'active' AND {$whereClause}
        ";
        
        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute();
        $totalCount = $stmt->fetchColumn();

        return [
            'type' => $type,
            'products' => $products,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => $totalCount,
                'total_pages' => ceil($totalCount / $limit)
            ],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Generate quality trends report
     */
    public function generateQualityTrendsReport(int $days = 30): array
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
        $metrics = $stmt->fetchAll();

        // Organize data by metric
        $trends = [];
        foreach ($metrics as $metric) {
            $trends[$metric['metric_name']][] = [
                'date' => $metric['date'],
                'value' => $metric['metric_value']
            ];
        }

        return [
            'trends' => $trends,
            'period_days' => $days,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Generate source analysis report
     */
    public function generateSourceAnalysisReport(): array
    {
        // Analysis by source
        $sql = "
            SELECT 
                source,
                COUNT(*) as total_mappings,
                COUNT(DISTINCT external_sku) as unique_skus,
                COUNT(DISTINCT master_id) as linked_masters,
                AVG(confidence_score) as avg_confidence,
                SUM(CASE WHEN verification_status = 'manual' THEN 1 ELSE 0 END) as manual_verifications,
                SUM(CASE WHEN verification_status = 'auto' THEN 1 ELSE 0 END) as auto_verifications,
                SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending_verifications
            FROM sku_mapping
            GROUP BY source
            ORDER BY total_mappings DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $sourceAnalysis = $stmt->fetchAll();

        // Quality by source
        $sql = "
            SELECT 
                sm.source,
                COUNT(*) as total_products,
                SUM(CASE WHEN mp.canonical_brand IS NOT NULL AND mp.canonical_brand != '' AND mp.canonical_brand != 'Неизвестный бренд' THEN 1 ELSE 0 END) as products_with_brand,
                SUM(CASE WHEN mp.canonical_category IS NOT NULL AND mp.canonical_category != '' AND mp.canonical_category != 'Без категории' THEN 1 ELSE 0 END) as products_with_category
            FROM sku_mapping sm
            INNER JOIN master_products mp ON sm.master_id = mp.master_id
            WHERE mp.status = 'active'
            GROUP BY sm.source
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $qualityBySource = $stmt->fetchAll();

        return [
            'source_analysis' => $sourceAnalysis,
            'quality_by_source' => $qualityBySource,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Export report in specified format
     */
    public function exportReport(string $reportType, string $format): string
    {
        switch ($reportType) {
            case 'coverage':
                $data = $this->generateCoverageReport();
                break;
            case 'incomplete':
                $data = $this->generateIncompleteDataReport(1, 10000); // Large limit for export
                break;
            case 'problematic':
                $data = $this->generateProblematicProductsReport('unknown_brand', 1, 10000);
                break;
            case 'source_analysis':
                $data = $this->generateSourceAnalysisReport();
                break;
            default:
                throw new Exception('Unknown report type');
        }

        switch ($format) {
            case 'csv':
                return $this->exportToCsv($data, $reportType);
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            default:
                throw new Exception('Unsupported export format');
        }
    }

    /**
     * Get duplicate names report
     */
    private function getDuplicateNamesReport(int $page, int $limit): array
    {
        $offset = ($page - 1) * $limit;
        
        $sql = "
            SELECT 
                canonical_name,
                COUNT(*) as duplicate_count,
                GROUP_CONCAT(master_id) as master_ids
            FROM master_products 
            WHERE status = 'active'
            GROUP BY canonical_name
            HAVING COUNT(*) > 1
            ORDER BY duplicate_count DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit, $offset]);
        $duplicates = $stmt->fetchAll();

        return [
            'type' => 'duplicate_names',
            'duplicates' => $duplicates,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total' => count($duplicates),
                'total_pages' => ceil(count($duplicates) / $limit)
            ],
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Export data to CSV format
     */
    private function exportToCsv(array $data, string $reportType): string
    {
        $output = fopen('php://temp', 'r+');
        
        switch ($reportType) {
            case 'coverage':
                fputcsv($output, ['Source', 'Total SKUs', 'Mapped SKUs', 'Coverage %']);
                foreach ($data['by_source'] as $row) {
                    fputcsv($output, [$row['source'], $row['total_skus'], $row['mapped_skus'], $row['coverage_percentage']]);
                }
                break;
                
            case 'incomplete':
                fputcsv($output, ['Master ID', 'Name', 'Brand', 'Category', 'Missing Brand', 'Missing Category', 'Missing Description']);
                foreach ($data['products'] as $row) {
                    fputcsv($output, [
                        $row['master_id'], $row['canonical_name'], $row['canonical_brand'], 
                        $row['canonical_category'], $row['missing_brand'], $row['missing_category'], $row['missing_description']
                    ]);
                }
                break;
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Helper methods for report summary
     */
    private function getCoverageSummary(): array
    {
        $sql = "
            SELECT 
                COUNT(DISTINCT sm.external_sku) as mapped_skus,
                (SELECT COUNT(DISTINCT external_sku) FROM sku_mapping) as total_skus
            FROM sku_mapping sm
            INNER JOIN master_products mp ON sm.master_id = mp.master_id
            WHERE mp.status = 'active'
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $result = $stmt->fetch();
        
        $coverage = $result['total_skus'] > 0 ? ($result['mapped_skus'] / $result['total_skus']) * 100 : 0;
        
        return [
            'coverage_percentage' => round($coverage, 2),
            'mapped_skus' => $result['mapped_skus'],
            'total_skus' => $result['total_skus']
        ];
    }

    private function getQualitySummary(): array
    {
        $dataQualityService = new DataQualityService();
        return $dataQualityService->getCurrentMetrics();
    }

    private function getProblematicSummary(): array
    {
        $sql = "
            SELECT 
                SUM(CASE WHEN canonical_brand IS NULL OR canonical_brand = '' OR canonical_brand = 'Неизвестный бренд' THEN 1 ELSE 0 END) as unknown_brand,
                SUM(CASE WHEN canonical_category IS NULL OR canonical_category = '' OR canonical_category = 'Без категории' THEN 1 ELSE 0 END) as no_category
            FROM master_products 
            WHERE status = 'active'
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetch();
    }

    private function getRecentReports(): array
    {
        // This would typically come from a reports_log table
        // For now, return empty array
        return [];
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