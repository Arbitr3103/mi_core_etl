#!/usr/bin/env php
<?php
/**
 * Data Quality Report Generator CLI
 * Generate comprehensive reports about data quality issues
 * 
 * Usage:
 *   php generate_data_quality_report.php [options]
 * 
 * Options:
 *   --type=TYPE          Report type: missing_names, sync_errors, manual_review, all (default: all)
 *   --format=FORMAT      Output format: json, csv, html (default: json)
 *   --output=FILE        Output file path (optional, prints to stdout if not specified)
 *   --limit=N            Limit number of records (default: 1000)
 *   --summary            Show only summary statistics
 */

require_once __DIR__ . '/config.php';

class DataQualityReportGenerator {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function generateReport($type, $limit = 1000) {
        switch ($type) {
            case 'missing_names':
                return $this->getProductsWithMissingNames($limit);
            case 'sync_errors':
                return $this->getProductsWithSyncErrors($limit);
            case 'manual_review':
                return $this->getProductsRequiringManualReview($limit);
            case 'all':
                return $this->getAllProblematicProducts($limit);
            default:
                throw new Exception("Invalid report type: $type");
        }
    }
    
    public function getSummary() {
        $summary = [];
        
        // Missing names
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
        
        // Sync errors
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
        
        // Manual review
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
        
        // Calculate percentages and health score
        $total = $summary['total_products'];
        $summary['missing_names_percent'] = $total > 0 ? round(($summary['missing_names'] / $total) * 100, 2) : 0;
        $summary['sync_errors_percent'] = $total > 0 ? round(($summary['sync_errors'] / $total) * 100, 2) : 0;
        $summary['manual_review_percent'] = $total > 0 ? round(($summary['manual_review'] / $total) * 100, 2) : 0;
        
        $avgIssuePercent = ($summary['missing_names_percent'] + $summary['sync_errors_percent'] + $summary['manual_review_percent']) / 3;
        $summary['health_score'] = max(0, round(100 - $avgIssuePercent, 2));
        
        $summary['generated_at'] = date('Y-m-d H:i:s');
        
        return $summary;
    }
    
    private function getProductsWithMissingNames($limit) {
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
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        
        return $products;
    }
    
    private function getProductsWithSyncErrors($limit) {
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
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        
        return $products;
    }
    
    private function getProductsRequiringManualReview($limit) {
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
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }
        
        return $products;
    }
    
    private function getAllProblematicProducts($limit) {
        $missing = $this->getProductsWithMissingNames($limit);
        $errors = $this->getProductsWithSyncErrors($limit);
        $review = $this->getProductsRequiringManualReview($limit);
        
        // Combine and deduplicate
        $all = array_merge($missing, $errors, $review);
        $unique = [];
        $seenIds = [];
        
        foreach ($all as $item) {
            if (!in_array($item['id'], $seenIds)) {
                $unique[] = $item;
                $seenIds[] = $item['id'];
            }
        }
        
        return array_slice($unique, 0, $limit);
    }
    
    public function formatAsJSON($data) {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
    public function formatAsCSV($data) {
        if (empty($data)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        
        // Write headers
        fputcsv($output, array_keys($data[0]));
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
    
    public function formatAsHTML($data, $summary) {
        $html = "<!DOCTYPE html>\n<html>\n<head>\n";
        $html .= "<meta charset='UTF-8'>\n";
        $html .= "<title>Data Quality Report - " . date('Y-m-d H:i:s') . "</title>\n";
        $html .= "<style>\n";
        $html .= "body { font-family: Arial, sans-serif; margin: 20px; }\n";
        $html .= "h1 { color: #333; }\n";
        $html .= ".summary { background: #f5f5f5; padding: 15px; border-radius: 5px; margin-bottom: 20px; }\n";
        $html .= ".summary-item { display: inline-block; margin-right: 30px; }\n";
        $html .= "table { border-collapse: collapse; width: 100%; }\n";
        $html .= "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }\n";
        $html .= "th { background-color: #4CAF50; color: white; }\n";
        $html .= "tr:nth-child(even) { background-color: #f2f2f2; }\n";
        $html .= ".badge { padding: 3px 8px; border-radius: 3px; font-size: 12px; }\n";
        $html .= ".badge-danger { background: #f44336; color: white; }\n";
        $html .= ".badge-warning { background: #ff9800; color: white; }\n";
        $html .= ".badge-success { background: #4CAF50; color: white; }\n";
        $html .= "</style>\n</head>\n<body>\n";
        
        $html .= "<h1>📊 Data Quality Report</h1>\n";
        $html .= "<p>Generated: " . date('Y-m-d H:i:s') . "</p>\n";
        
        // Summary section
        $html .= "<div class='summary'>\n";
        $html .= "<h2>Summary</h2>\n";
        $html .= "<div class='summary-item'><strong>Health Score:</strong> {$summary['health_score']}%</div>\n";
        $html .= "<div class='summary-item'><strong>Missing Names:</strong> {$summary['missing_names']} ({$summary['missing_names_percent']}%)</div>\n";
        $html .= "<div class='summary-item'><strong>Sync Errors:</strong> {$summary['sync_errors']} ({$summary['sync_errors_percent']}%)</div>\n";
        $html .= "<div class='summary-item'><strong>Manual Review:</strong> {$summary['manual_review']} ({$summary['manual_review_percent']}%)</div>\n";
        $html .= "<div class='summary-item'><strong>Total Products:</strong> {$summary['total_products']}</div>\n";
        $html .= "</div>\n";
        
        // Data table
        if (!empty($data)) {
            $html .= "<h2>Problematic Products</h2>\n";
            $html .= "<table>\n<thead>\n<tr>\n";
            
            foreach (array_keys($data[0]) as $header) {
                $html .= "<th>" . htmlspecialchars($header) . "</th>\n";
            }
            
            $html .= "</tr>\n</thead>\n<tbody>\n";
            
            foreach ($data as $row) {
                $html .= "<tr>\n";
                foreach ($row as $cell) {
                    $html .= "<td>" . htmlspecialchars($cell ?? '') . "</td>\n";
                }
                $html .= "</tr>\n";
            }
            
            $html .= "</tbody>\n</table>\n";
        }
        
        $html .= "</body>\n</html>";
        
        return $html;
    }
}

// Parse command line arguments
$options = getopt('', ['type:', 'format:', 'output:', 'limit:', 'summary']);

$type = $options['type'] ?? 'all';
$format = $options['format'] ?? 'json';
$outputFile = $options['output'] ?? null;
$limit = isset($options['limit']) ? intval($options['limit']) : 1000;
$summaryOnly = isset($options['summary']);

try {
    // Connect to database
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    
    if ($db->connect_error) {
        throw new Exception("Database connection failed: " . $db->connect_error);
    }
    
    $db->set_charset("utf8mb4");
    
    $generator = new DataQualityReportGenerator($db);
    
    // Get summary
    $summary = $generator->getSummary();
    
    if ($summaryOnly) {
        // Output only summary
        $output = $generator->formatAsJSON($summary);
    } else {
        // Generate full report
        echo "Generating {$type} report...\n";
        $data = $generator->generateReport($type, $limit);
        echo "Found " . count($data) . " problematic products\n";
        
        // Format output
        switch ($format) {
            case 'csv':
                $output = $generator->formatAsCSV($data);
                break;
            case 'html':
                $output = $generator->formatAsHTML($data, $summary);
                break;
            case 'json':
            default:
                $output = $generator->formatAsJSON([
                    'summary' => $summary,
                    'data' => $data,
                    'count' => count($data)
                ]);
                break;
        }
    }
    
    // Output to file or stdout
    if ($outputFile) {
        file_put_contents($outputFile, $output);
        echo "Report saved to: {$outputFile}\n";
    } else {
        echo $output;
    }
    
    $db->close();
    
} catch (Exception $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
