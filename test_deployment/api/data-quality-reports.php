<?php
/**
 * Data Quality Reports API
 * Provides comprehensive reports on data quality issues
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config.php';

class DataQualityReports {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Get products with missing names
     */
    public function getProductsWithMissingNames($limit = 100, $offset = 0) {
        $sql = "
            SELECT 
                pcr.id,
                pcr.inventory_product_id,
                pcr.ozon_product_id,
                pcr.sku_ozon,
                pcr.cached_name,
                pcr.sync_status,
                pcr.last_successful_sync,
                dp.name as dim_product_name,
                CASE 
                    WHEN dp.name IS NULL THEN 'completely_missing'
                    WHEN dp.name LIKE 'Товар Ozon ID%' THEN 'placeholder_name'
                    WHEN dp.name LIKE '%требует обновления%' THEN 'needs_update'
                    ELSE 'unknown_issue'
                END as issue_type,
                i.quantity_present,
                i.warehouse_name
            FROM product_cross_reference pcr
            LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
            LEFT JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            WHERE 
                dp.name IS NULL 
                OR dp.name LIKE 'Товар Ozon ID%'
                OR dp.name LIKE '%требует обновления%'
                OR dp.name = ''
            ORDER BY 
                CASE 
                    WHEN i.quantity_present > 0 THEN 0
                    ELSE 1
                END,
                i.quantity_present DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        
        // Get total count
        $countSql = "
            SELECT COUNT(*) as total
            FROM product_cross_reference pcr
            LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
            WHERE 
                dp.name IS NULL 
                OR dp.name LIKE 'Товар Ozon ID%'
                OR dp.name LIKE '%требует обновления%'
                OR dp.name = ''
        ";
        $countResult = $this->db->query($countSql);
        $total = $countResult->fetch_assoc()['total'];
        
        return [
            'products' => $products,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    /**
     * Get products with sync errors
     */
    public function getProductsWithSyncErrors($limit = 100, $offset = 0) {
        $sql = "
            SELECT 
                pcr.id,
                pcr.inventory_product_id,
                pcr.ozon_product_id,
                pcr.sku_ozon,
                pcr.cached_name,
                pcr.sync_status,
                pcr.last_successful_sync,
                TIMESTAMPDIFF(HOUR, pcr.last_successful_sync, NOW()) as hours_since_sync,
                dp.name as dim_product_name,
                i.quantity_present,
                i.warehouse_name,
                CASE 
                    WHEN pcr.sync_status = 'failed' THEN 'sync_failed'
                    WHEN pcr.last_successful_sync IS NULL THEN 'never_synced'
                    WHEN TIMESTAMPDIFF(DAY, pcr.last_successful_sync, NOW()) > 7 THEN 'stale_data'
                    ELSE 'unknown'
                END as error_type
            FROM product_cross_reference pcr
            LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
            LEFT JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            WHERE 
                pcr.sync_status = 'failed'
                OR pcr.last_successful_sync IS NULL
                OR TIMESTAMPDIFF(DAY, pcr.last_successful_sync, NOW()) > 7
            ORDER BY 
                CASE pcr.sync_status
                    WHEN 'failed' THEN 0
                    WHEN 'pending' THEN 1
                    ELSE 2
                END,
                pcr.last_successful_sync ASC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        
        // Get total count
        $countSql = "
            SELECT COUNT(*) as total
            FROM product_cross_reference pcr
            WHERE 
                pcr.sync_status = 'failed'
                OR pcr.last_successful_sync IS NULL
                OR TIMESTAMPDIFF(DAY, pcr.last_successful_sync, NOW()) > 7
        ";
        $countResult = $this->db->query($countSql);
        $total = $countResult->fetch_assoc()['total'];
        
        return [
            'products' => $products,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    /**
     * Get products requiring manual review
     */
    public function getProductsRequiringManualReview($limit = 100, $offset = 0) {
        $sql = "
            SELECT 
                pcr.id,
                pcr.inventory_product_id,
                pcr.ozon_product_id,
                pcr.sku_ozon,
                pcr.cached_name,
                pcr.sync_status,
                pcr.last_successful_sync,
                dp.name as dim_product_name,
                i.quantity_present,
                i.warehouse_name,
                CASE 
                    WHEN pcr.ozon_product_id IS NULL AND pcr.inventory_product_id IS NOT NULL THEN 'missing_ozon_id'
                    WHEN pcr.inventory_product_id IS NULL AND pcr.ozon_product_id IS NOT NULL THEN 'missing_inventory_id'
                    WHEN pcr.cached_name IS NULL AND dp.name IS NULL THEN 'no_name_data'
                    WHEN pcr.sync_status = 'failed' AND pcr.last_successful_sync IS NOT NULL THEN 'repeated_failures'
                    WHEN i.quantity_present > 100 AND (dp.name IS NULL OR dp.name LIKE 'Товар%') THEN 'high_stock_no_name'
                    ELSE 'data_inconsistency'
                END as review_reason,
                CASE 
                    WHEN i.quantity_present > 100 THEN 'high'
                    WHEN i.quantity_present > 10 THEN 'medium'
                    ELSE 'low'
                END as priority
            FROM product_cross_reference pcr
            LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
            LEFT JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            WHERE 
                (pcr.ozon_product_id IS NULL AND pcr.inventory_product_id IS NOT NULL)
                OR (pcr.inventory_product_id IS NULL AND pcr.ozon_product_id IS NOT NULL)
                OR (pcr.cached_name IS NULL AND dp.name IS NULL)
                OR (pcr.sync_status = 'failed' AND pcr.last_successful_sync IS NOT NULL)
                OR (i.quantity_present > 100 AND (dp.name IS NULL OR dp.name LIKE 'Товар%'))
            ORDER BY 
                CASE 
                    WHEN i.quantity_present > 100 THEN 0
                    WHEN i.quantity_present > 10 THEN 1
                    ELSE 2
                END,
                pcr.sync_status DESC,
                i.quantity_present DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        
        // Get total count
        $countSql = "
            SELECT COUNT(*) as total
            FROM product_cross_reference pcr
            LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
            LEFT JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            WHERE 
                (pcr.ozon_product_id IS NULL AND pcr.inventory_product_id IS NOT NULL)
                OR (pcr.inventory_product_id IS NULL AND pcr.ozon_product_id IS NOT NULL)
                OR (pcr.cached_name IS NULL AND dp.name IS NULL)
                OR (pcr.sync_status = 'failed' AND pcr.last_successful_sync IS NOT NULL)
                OR (i.quantity_present > 100 AND (dp.name IS NULL OR dp.name LIKE 'Товар%'))
        ";
        $countResult = $this->db->query($countSql);
        $total = $countResult->fetch_assoc()['total'];
        
        return [
            'products' => $products,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }
    
    /**
     * Get summary statistics for all data quality issues
     */
    public function getDataQualitySummary() {
        $summary = [];
        
        // Missing names count
        $sql = "
            SELECT COUNT(*) as count
            FROM product_cross_reference pcr
            LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
            WHERE 
                dp.name IS NULL 
                OR dp.name LIKE 'Товар Ozon ID%'
                OR dp.name LIKE '%требует обновления%'
                OR dp.name = ''
        ";
        $result = $this->db->query($sql);
        $summary['missing_names'] = $result->fetch_assoc()['count'];
        
        // Sync errors count
        $sql = "
            SELECT COUNT(*) as count
            FROM product_cross_reference pcr
            WHERE 
                pcr.sync_status = 'failed'
                OR pcr.last_successful_sync IS NULL
                OR TIMESTAMPDIFF(DAY, pcr.last_successful_sync, NOW()) > 7
        ";
        $result = $this->db->query($sql);
        $summary['sync_errors'] = $result->fetch_assoc()['count'];
        
        // Manual review count
        $sql = "
            SELECT COUNT(*) as count
            FROM product_cross_reference pcr
            LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
            LEFT JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            WHERE 
                (pcr.ozon_product_id IS NULL AND pcr.inventory_product_id IS NOT NULL)
                OR (pcr.inventory_product_id IS NULL AND pcr.ozon_product_id IS NOT NULL)
                OR (pcr.cached_name IS NULL AND dp.name IS NULL)
                OR (pcr.sync_status = 'failed' AND pcr.last_successful_sync IS NOT NULL)
                OR (i.quantity_present > 100 AND (dp.name IS NULL OR dp.name LIKE 'Товар%'))
        ";
        $result = $this->db->query($sql);
        $summary['manual_review'] = $result->fetch_assoc()['count'];
        
        // Total products
        $sql = "SELECT COUNT(*) as count FROM product_cross_reference";
        $result = $this->db->query($sql);
        $summary['total_products'] = $result->fetch_assoc()['count'];
        
        // Calculate percentages
        $total = $summary['total_products'];
        $summary['missing_names_percent'] = $total > 0 ? round(($summary['missing_names'] / $total) * 100, 2) : 0;
        $summary['sync_errors_percent'] = $total > 0 ? round(($summary['sync_errors'] / $total) * 100, 2) : 0;
        $summary['manual_review_percent'] = $total > 0 ? round(($summary['manual_review'] / $total) * 100, 2) : 0;
        
        // Health score (100 - average of all issue percentages)
        $avgIssuePercent = ($summary['missing_names_percent'] + $summary['sync_errors_percent'] + $summary['manual_review_percent']) / 3;
        $summary['health_score'] = max(0, round(100 - $avgIssuePercent, 2));
        
        return $summary;
    }
    
    /**
     * Export problematic products to CSV
     */
    public function exportProblematicProducts($reportType = 'all') {
        $data = [];
        
        switch ($reportType) {
            case 'missing_names':
                $result = $this->getProductsWithMissingNames(10000, 0);
                $data = $result['products'];
                break;
            case 'sync_errors':
                $result = $this->getProductsWithSyncErrors(10000, 0);
                $data = $result['products'];
                break;
            case 'manual_review':
                $result = $this->getProductsRequiringManualReview(10000, 0);
                $data = $result['products'];
                break;
            case 'all':
                // Combine all reports
                $missing = $this->getProductsWithMissingNames(10000, 0);
                $errors = $this->getProductsWithSyncErrors(10000, 0);
                $review = $this->getProductsRequiringManualReview(10000, 0);
                $data = array_merge($missing['products'], $errors['products'], $review['products']);
                // Remove duplicates based on ID
                $uniqueData = [];
                $seenIds = [];
                foreach ($data as $item) {
                    if (!in_array($item['id'], $seenIds)) {
                        $uniqueData[] = $item;
                        $seenIds[] = $item['id'];
                    }
                }
                $data = $uniqueData;
                break;
        }
        
        return $data;
    }
}

// Handle API requests
try {
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    
    if ($db->connect_error) {
        throw new Exception("Database connection failed: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
    $reports = new DataQualityReports($db);
    
    $action = $_GET['action'] ?? 'summary';
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    
    switch ($action) {
        case 'missing_names':
            $result = $reports->getProductsWithMissingNames($limit, $offset);
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
            
        case 'sync_errors':
            $result = $reports->getProductsWithSyncErrors($limit, $offset);
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
            
        case 'manual_review':
            $result = $reports->getProductsRequiringManualReview($limit, $offset);
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
            
        case 'summary':
            $result = $reports->getDataQualitySummary();
            echo json_encode([
                'success' => true,
                'data' => $result
            ]);
            break;
            
        case 'export':
            $reportType = $_GET['type'] ?? 'all';
            $format = $_GET['format'] ?? 'json';
            
            $data = $reports->exportProblematicProducts($reportType);
            
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="data_quality_report_' . $reportType . '_' . date('Y-m-d') . '.csv"');
                
                if (!empty($data)) {
                    $output = fopen('php://output', 'w');
                    
                    // Write headers
                    fputcsv($output, array_keys($data[0]));
                    
                    // Write data
                    foreach ($data as $row) {
                        fputcsv($output, $row);
                    }
                    
                    fclose($output);
                }
            } else {
                echo json_encode([
                    'success' => true,
                    'data' => $data,
                    'count' => count($data)
                ]);
            }
            break;
            
        default:
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action'
            ]);
    }
    
    $db->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
