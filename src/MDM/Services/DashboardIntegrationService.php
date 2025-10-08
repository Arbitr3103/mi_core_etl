<?php

namespace MDM\Services;

/**
 * Dashboard Integration Service
 * Provides MDM-enhanced data for analytics dashboards
 */
class DashboardIntegrationService {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Get enhanced inventory data with master data integration
     */
    public function getEnhancedInventoryData($filters = []) {
        $whereConditions = ['1=1'];
        $params = [];
        
        // Apply filters
        if (!empty($filters['source'])) {
            $whereConditions[] = 'i.source = ?';
            $params[] = $filters['source'];
        }
        
        if (!empty($filters['brand'])) {
            $whereConditions[] = 'mp.canonical_brand = ?';
            $params[] = $filters['brand'];
        }
        
        if (!empty($filters['category'])) {
            $whereConditions[] = 'mp.canonical_category = ?';
            $params[] = $filters['category'];
        }
        
        if (!empty($filters['min_stock'])) {
            $whereConditions[] = 'i.current_stock >= ?';
            $params[] = $filters['min_stock'];
        }
        
        if (!empty($filters['max_stock'])) {
            $whereConditions[] = 'i.current_stock <= ?';
            $params[] = $filters['max_stock'];
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        $limit = min((int)($filters['limit'] ?? 100), 1000);
        $offset = (int)($filters['offset'] ?? 0);
        
        $sql = "
            SELECT 
                i.sku,
                i.current_stock,
                i.reserved_stock,
                i.warehouse_name,
                i.source,
                i.stock_type,
                -- Master data fields
                mp.master_id,
                COALESCE(mp.canonical_name, i.sku) as display_name,
                COALESCE(mp.canonical_brand, 'Неизвестный бренд') as canonical_brand,
                COALESCE(mp.canonical_category, 'Без категории') as canonical_category,
                mp.description,
                mp.attributes,
                -- SKU mapping info
                sm.confidence_score,
                sm.verification_status,
                -- Data quality indicators
                CASE 
                    WHEN mp.master_id IS NOT NULL THEN 'master_data'
                    WHEN sm.external_sku IS NOT NULL THEN 'mapped'
                    ELSE 'raw_data'
                END as data_quality_level,
                -- Stock level classification
                CASE 
                    WHEN i.current_stock > 100 THEN 'high'
                    WHEN i.current_stock > 20 THEN 'medium'
                    WHEN i.current_stock > 0 THEN 'low'
                    ELSE 'empty'
                END as stock_level,
                -- Demand indicators
                CASE 
                    WHEN i.reserved_stock > 0 AND i.current_stock < 10 THEN 'high_demand_low_stock'
                    WHEN i.reserved_stock > 0 THEN 'has_demand'
                    WHEN i.current_stock > 100 AND i.reserved_stock = 0 THEN 'overstocked'
                    ELSE 'normal'
                END as demand_status
            FROM inventory_data i
            LEFT JOIN sku_mapping sm ON i.sku = sm.external_sku
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active'
            WHERE $whereClause
            ORDER BY 
                CASE 
                    WHEN mp.master_id IS NOT NULL THEN 1
                    WHEN sm.external_sku IS NOT NULL THEN 2
                    ELSE 3
                END,
                i.current_stock DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get enhanced brand analytics with master data
     */
    public function getEnhancedBrandAnalytics() {
        $sql = "
            SELECT 
                mp.canonical_brand as brand_name,
                COUNT(DISTINCT mp.master_id) as master_products_count,
                COUNT(DISTINCT sm.external_sku) as mapped_skus_count,
                COUNT(DISTINCT i.sku) as inventory_skus_count,
                SUM(i.current_stock) as total_stock,
                SUM(i.reserved_stock) as total_reserved,
                AVG(i.current_stock) as avg_stock_per_sku,
                -- Quality metrics
                COUNT(DISTINCT CASE WHEN mp.canonical_category IS NOT NULL AND mp.canonical_category != 'Без категории' THEN mp.master_id END) as products_with_category,
                COUNT(DISTINCT CASE WHEN mp.description IS NOT NULL AND mp.description != '' THEN mp.master_id END) as products_with_description,
                -- Stock level distribution
                COUNT(DISTINCT CASE WHEN i.current_stock > 100 THEN i.sku END) as high_stock_skus,
                COUNT(DISTINCT CASE WHEN i.current_stock BETWEEN 20 AND 100 THEN i.sku END) as medium_stock_skus,
                COUNT(DISTINCT CASE WHEN i.current_stock BETWEEN 1 AND 19 THEN i.sku END) as low_stock_skus,
                COUNT(DISTINCT CASE WHEN i.current_stock = 0 THEN i.sku END) as empty_stock_skus,
                -- Demand indicators
                COUNT(DISTINCT CASE WHEN i.reserved_stock > 0 THEN i.sku END) as skus_with_demand,
                COUNT(DISTINCT CASE WHEN i.reserved_stock > 0 AND i.current_stock < 10 THEN i.sku END) as critical_demand_skus,
                -- Data sources
                GROUP_CONCAT(DISTINCT sm.source) as data_sources
            FROM master_products mp
            LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
            LEFT JOIN inventory_data i ON sm.external_sku = i.sku
            WHERE mp.canonical_brand IS NOT NULL 
            AND mp.canonical_brand != 'Неизвестный бренд'
            AND mp.status = 'active'
            GROUP BY mp.canonical_brand
            HAVING total_stock > 0
            ORDER BY total_stock DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get enhanced category analytics with master data
     */
    public function getEnhancedCategoryAnalytics() {
        $sql = "
            SELECT 
                mp.canonical_category as category_name,
                COUNT(DISTINCT mp.master_id) as master_products_count,
                COUNT(DISTINCT sm.external_sku) as mapped_skus_count,
                COUNT(DISTINCT i.sku) as inventory_skus_count,
                SUM(i.current_stock) as total_stock,
                SUM(i.reserved_stock) as total_reserved,
                AVG(i.current_stock) as avg_stock_per_sku,
                -- Brand diversity
                COUNT(DISTINCT mp.canonical_brand) as unique_brands,
                -- Stock performance
                ROUND(AVG(CASE WHEN i.current_stock > 0 THEN 100 ELSE 0 END), 2) as availability_percentage,
                ROUND(AVG(CASE WHEN i.reserved_stock > 0 THEN 100 ELSE 0 END), 2) as demand_percentage,
                -- Top brand in category
                (
                    SELECT mp2.canonical_brand 
                    FROM master_products mp2
                    LEFT JOIN sku_mapping sm2 ON mp2.master_id = sm2.master_id
                    LEFT JOIN inventory_data i2 ON sm2.external_sku = i2.sku
                    WHERE mp2.canonical_category = mp.canonical_category
                    AND mp2.status = 'active'
                    GROUP BY mp2.canonical_brand
                    ORDER BY SUM(i2.current_stock) DESC
                    LIMIT 1
                ) as top_brand_by_stock
            FROM master_products mp
            LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
            LEFT JOIN inventory_data i ON sm.external_sku = i.sku
            WHERE mp.canonical_category IS NOT NULL 
            AND mp.canonical_category != 'Без категории'
            AND mp.status = 'active'
            GROUP BY mp.canonical_category
            HAVING total_stock > 0
            ORDER BY total_stock DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get data quality dashboard metrics
     */
    public function getDataQualityMetrics() {
        // Overall coverage metrics
        $coverageStmt = $this->pdo->query("
            SELECT 
                COUNT(DISTINCT i.sku) as total_inventory_skus,
                COUNT(DISTINCT sm.external_sku) as mapped_skus,
                COUNT(DISTINCT mp.master_id) as master_products,
                ROUND((COUNT(DISTINCT sm.external_sku) / COUNT(DISTINCT i.sku)) * 100, 2) as mapping_coverage_percentage,
                ROUND((COUNT(DISTINCT mp.master_id) / COUNT(DISTINCT sm.master_id)) * 100, 2) as master_data_quality_percentage
            FROM inventory_data i
            LEFT JOIN sku_mapping sm ON i.sku = sm.external_sku
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active'
        ");
        $coverage = $coverageStmt->fetch(\PDO::FETCH_ASSOC);
        
        // Data completeness metrics
        $completenessStmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total_master_products,
                COUNT(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != 'Неизвестный бренд' THEN 1 END) as products_with_brand,
                COUNT(CASE WHEN canonical_category IS NOT NULL AND canonical_category != 'Без категории' THEN 1 END) as products_with_category,
                COUNT(CASE WHEN description IS NOT NULL AND description != '' THEN 1 END) as products_with_description,
                COUNT(CASE WHEN attributes IS NOT NULL AND attributes != '{}' THEN 1 END) as products_with_attributes
            FROM master_products
            WHERE status = 'active'
        ");
        $completeness = $completenessStmt->fetch(\PDO::FETCH_ASSOC);
        
        // Verification status metrics
        $verificationStmt = $this->pdo->query("
            SELECT 
                verification_status,
                COUNT(*) as count,
                COUNT(DISTINCT master_id) as unique_masters
            FROM sku_mapping
            GROUP BY verification_status
        ");
        $verification = $verificationStmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Source distribution
        $sourceStmt = $this->pdo->query("
            SELECT 
                sm.source,
                COUNT(*) as mappings_count,
                COUNT(DISTINCT sm.master_id) as unique_masters,
                COUNT(DISTINCT i.sku) as inventory_skus,
                SUM(i.current_stock) as total_stock
            FROM sku_mapping sm
            LEFT JOIN inventory_data i ON sm.external_sku = i.sku
            GROUP BY sm.source
            ORDER BY mappings_count DESC
        ");
        $sources = $sourceStmt->fetchAll(\PDO::FETCH_ASSOC);
        
        return [
            'coverage' => $coverage,
            'completeness' => $completeness,
            'verification' => $verification,
            'sources' => $sources
        ];
    }
    
    /**
     * Get critical stock alerts with master data context
     */
    public function getCriticalStockAlerts($threshold = 10) {
        $sql = "
            SELECT 
                i.sku,
                i.current_stock,
                i.reserved_stock,
                i.warehouse_name,
                i.source,
                -- Master data context
                mp.master_id,
                COALESCE(mp.canonical_name, i.sku) as display_name,
                COALESCE(mp.canonical_brand, 'Неизвестный бренд') as canonical_brand,
                COALESCE(mp.canonical_category, 'Без категории') as canonical_category,
                -- Alert classification
                CASE 
                    WHEN i.current_stock = 0 AND i.reserved_stock > 0 THEN 'out_of_stock_with_demand'
                    WHEN i.current_stock < 3 AND i.reserved_stock > 0 THEN 'critical_with_demand'
                    WHEN i.current_stock < 3 THEN 'critical_no_demand'
                    WHEN i.current_stock < ? AND i.reserved_stock > 0 THEN 'low_with_demand'
                    ELSE 'low_stock'
                END as alert_type,
                -- Priority scoring
                CASE 
                    WHEN i.current_stock = 0 AND i.reserved_stock > 0 THEN 1
                    WHEN i.current_stock < 3 AND i.reserved_stock > 0 THEN 2
                    WHEN i.current_stock < 3 THEN 3
                    WHEN i.current_stock < ? AND i.reserved_stock > 0 THEN 4
                    ELSE 5
                END as priority,
                -- Data quality indicator
                CASE 
                    WHEN mp.master_id IS NOT NULL THEN 'master_data'
                    WHEN sm.external_sku IS NOT NULL THEN 'mapped'
                    ELSE 'raw_data'
                END as data_quality_level
            FROM inventory_data i
            LEFT JOIN sku_mapping sm ON i.sku = sm.external_sku
            LEFT JOIN master_products mp ON sm.master_id = mp.master_id AND mp.status = 'active'
            WHERE i.current_stock < ?
            ORDER BY priority ASC, i.reserved_stock DESC, i.current_stock ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$threshold, $threshold, $threshold]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get performance trends with master data grouping
     */
    public function getPerformanceTrends($days = 30) {
        $sql = "
            SELECT 
                DATE(dqm.calculation_date) as date,
                dqm.metric_name,
                dqm.metric_value,
                dqm.total_records,
                dqm.good_records
            FROM data_quality_metrics dqm
            WHERE dqm.calculation_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
            ORDER BY dqm.calculation_date DESC, dqm.metric_name
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$days]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get top performing products with master data
     */
    public function getTopPerformingProducts($limit = 20) {
        $sql = "
            SELECT 
                mp.master_id,
                mp.canonical_name,
                mp.canonical_brand,
                mp.canonical_category,
                COUNT(DISTINCT sm.external_sku) as mapped_skus_count,
                SUM(i.current_stock) as total_stock,
                SUM(i.reserved_stock) as total_reserved,
                AVG(i.current_stock) as avg_stock_per_sku,
                -- Performance indicators
                ROUND((SUM(i.reserved_stock) / NULLIF(SUM(i.current_stock), 0)) * 100, 2) as demand_ratio,
                COUNT(DISTINCT i.source) as data_sources_count,
                GROUP_CONCAT(DISTINCT i.source) as data_sources,
                -- Quality score
                CASE 
                    WHEN mp.canonical_brand IS NOT NULL AND mp.canonical_brand != 'Неизвестный бренд' THEN 1 ELSE 0 END +
                    CASE 
                        WHEN mp.canonical_category IS NOT NULL AND mp.canonical_category != 'Без категории' THEN 1 ELSE 0 END +
                    CASE 
                        WHEN mp.description IS NOT NULL AND mp.description != '' THEN 1 ELSE 0 END as quality_score
            FROM master_products mp
            INNER JOIN sku_mapping sm ON mp.master_id = sm.master_id
            INNER JOIN inventory_data i ON sm.external_sku = i.sku
            WHERE mp.status = 'active'
            AND i.current_stock > 0
            GROUP BY mp.master_id, mp.canonical_name, mp.canonical_brand, mp.canonical_category
            ORDER BY total_reserved DESC, total_stock DESC
            LIMIT ?
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    
    /**
     * Get dashboard summary statistics
     */
    public function getDashboardSummary() {
        return [
            'inventory_overview' => $this->getInventoryOverview(),
            'master_data_coverage' => $this->getMasterDataCoverage(),
            'data_quality_summary' => $this->getDataQualitySummary(),
            'recent_activity' => $this->getRecentActivity()
        ];
    }
    
    private function getInventoryOverview() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(DISTINCT i.sku) as total_skus,
                COUNT(DISTINCT CASE WHEN i.current_stock > 0 THEN i.sku END) as skus_in_stock,
                SUM(i.current_stock) as total_stock,
                SUM(i.reserved_stock) as total_reserved,
                COUNT(DISTINCT i.warehouse_name) as total_warehouses,
                COUNT(DISTINCT i.source) as data_sources
            FROM inventory_data i
        ");
        
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    private function getMasterDataCoverage() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(DISTINCT mp.master_id) as total_master_products,
                COUNT(DISTINCT sm.external_sku) as total_mappings,
                COUNT(DISTINCT i.sku) as inventory_skus_with_master_data,
                ROUND((COUNT(DISTINCT i.sku) / (SELECT COUNT(DISTINCT sku) FROM inventory_data)) * 100, 2) as coverage_percentage
            FROM master_products mp
            INNER JOIN sku_mapping sm ON mp.master_id = sm.master_id
            INNER JOIN inventory_data i ON sm.external_sku = i.sku
            WHERE mp.status = 'active'
        ");
        
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    private function getDataQualitySummary() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total_master_products,
                ROUND(AVG(CASE WHEN canonical_brand IS NOT NULL AND canonical_brand != 'Неизвестный бренд' THEN 100 ELSE 0 END), 2) as brand_completeness,
                ROUND(AVG(CASE WHEN canonical_category IS NOT NULL AND canonical_category != 'Без категории' THEN 100 ELSE 0 END), 2) as category_completeness,
                ROUND(AVG(CASE WHEN description IS NOT NULL AND description != '' THEN 100 ELSE 0 END), 2) as description_completeness
            FROM master_products
            WHERE status = 'active'
        ");
        
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
    
    private function getRecentActivity() {
        $stmt = $this->pdo->query("
            SELECT 
                'master_products' as activity_type,
                COUNT(*) as count,
                MAX(updated_at) as last_activity
            FROM master_products
            WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            
            UNION ALL
            
            SELECT 
                'sku_mappings' as activity_type,
                COUNT(*) as count,
                MAX(updated_at) as last_activity
            FROM sku_mapping
            WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
?>