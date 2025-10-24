<?php
/**
 * StockReportsAPI Class
 * 
 * Handles API requests for stock report status and history
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once __DIR__ . '/../utils/Logger.php';

class StockReportsAPI {
    
    private $pdo;
    private $logger;
    private $defaultLimit = 50;
    private $maxLimit = 500;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->logger = Logger::getInstance();
    }
    
    /**
     * Get stock reports with filtering and pagination
     * 
     * @param array $params - Query parameters
     * @return array API response
     */
    public function getStockReports(array $params = []): array {
        try {
            // Validate and sanitize parameters
            $filters = $this->validateReportParams($params);
            
            // Build query
            $query = $this->buildReportsQuery($filters);
            $countQuery = $this->buildReportsCountQuery($filters);
            
            // Execute count query for pagination
            $stmt = $this->pdo->prepare($countQuery['sql']);
            $stmt->execute($countQuery['params']);
            $totalCount = $stmt->fetchColumn();
            
            // Execute main query
            $stmt = $this->pdo->prepare($query['sql']);
            $stmt->execute($query['params']);
            $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format response data
            $formattedReports = $this->formatReportsData($reports);
            
            // Calculate pagination info
            $pagination = $this->calculatePagination($totalCount, $filters['limit'], $filters['offset']);
            
            // Get summary statistics
            $summary = $this->getReportsSummary($filters);
            
            $this->logger->info('Stock reports retrieved', [
                'total_count' => $totalCount,
                'returned_count' => count($formattedReports),
                'filters' => $filters
            ]);
            
            return [
                'success' => true,
                'data' => $formattedReports,
                'summary' => $summary,
                'pagination' => $pagination,
                'filters_applied' => $filters,
                'timestamp' => date('c')
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve stock reports', [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            
            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 500
                ],
                'timestamp' => date('c'),
                'status' => 500
            ];
        }
    }
    
    /**
     * Get details for a specific stock report
     * 
     * @param string $reportCode - Report code to retrieve
     * @return array API response
     */
    public function getStockReportDetails(string $reportCode): array {
        try {
            // Get report details
            $report = $this->getReportByCode($reportCode);
            
            if (!$report) {
                return [
                    'success' => false,
                    'error' => [
                        'message' => 'Report not found',
                        'code' => 404
                    ],
                    'timestamp' => date('c'),
                    'status' => 404
                ];
            }
            
            // Get report logs
            $logs = $this->getReportLogs($reportCode);
            
            // Get processing statistics
            $stats = $this->getReportProcessingStats($reportCode);
            
            // Format response
            $formattedReport = $this->formatReportDetails($report, $logs, $stats);
            
            $this->logger->info('Stock report details retrieved', [
                'report_code' => $reportCode,
                'status' => $report['status']
            ]);
            
            return [
                'success' => true,
                'data' => $formattedReport,
                'timestamp' => date('c')
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve stock report details', [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => 500
                ],
                'timestamp' => date('c'),
                'status' => 500
            ];
        }
    }
    
    /**
     * Validate and sanitize report query parameters
     * 
     * @param array $params - Raw query parameters
     * @return array Validated parameters
     */
    private function validateReportParams(array $params): array {
        $filters = [
            'status' => null,
            'report_type' => null,
            'date_from' => null,
            'date_to' => null,
            'limit' => $this->defaultLimit,
            'offset' => 0,
            'sort_by' => 'requested_at',
            'sort_order' => 'DESC'
        ];
        
        // Status filter
        if (!empty($params['status'])) {
            $validStatuses = ['REQUESTED', 'PROCESSING', 'SUCCESS', 'ERROR', 'TIMEOUT'];
            if (in_array(strtoupper($params['status']), $validStatuses)) {
                $filters['status'] = strtoupper($params['status']);
            } else {
                throw new Exception('Invalid status: must be one of ' . implode(', ', $validStatuses));
            }
        }
        
        // Report type filter
        if (!empty($params['report_type'])) {
            $validTypes = ['warehouse_stock'];
            if (in_array($params['report_type'], $validTypes)) {
                $filters['report_type'] = $params['report_type'];
            } else {
                throw new Exception('Invalid report_type: must be one of ' . implode(', ', $validTypes));
            }
        }
        
        // Date range filters
        if (!empty($params['date_from'])) {
            if ($this->validateDateFormat($params['date_from'])) {
                $filters['date_from'] = $params['date_from'];
            } else {
                throw new Exception('Invalid date_from format: use YYYY-MM-DD');
            }
        }
        
        if (!empty($params['date_to'])) {
            if ($this->validateDateFormat($params['date_to'])) {
                $filters['date_to'] = $params['date_to'];
            } else {
                throw new Exception('Invalid date_to format: use YYYY-MM-DD');
            }
        }
        
        // Pagination parameters
        if (isset($params['limit'])) {
            $limit = (int) $params['limit'];
            if ($limit > 0 && $limit <= $this->maxLimit) {
                $filters['limit'] = $limit;
            } else {
                throw new Exception("Invalid limit: must be between 1 and {$this->maxLimit}");
            }
        }
        
        if (isset($params['offset'])) {
            $offset = (int) $params['offset'];
            if ($offset >= 0) {
                $filters['offset'] = $offset;
            } else {
                throw new Exception('Invalid offset: must be non-negative');
            }
        }
        
        // Sorting parameters
        if (!empty($params['sort_by'])) {
            $validSortFields = ['requested_at', 'completed_at', 'status', 'report_type', 'records_processed'];
            if (in_array($params['sort_by'], $validSortFields)) {
                $filters['sort_by'] = $params['sort_by'];
            } else {
                throw new Exception('Invalid sort_by: must be one of ' . implode(', ', $validSortFields));
            }
        }
        
        if (!empty($params['sort_order'])) {
            $sortOrder = strtoupper($params['sort_order']);
            if (in_array($sortOrder, ['ASC', 'DESC'])) {
                $filters['sort_order'] = $sortOrder;
            } else {
                throw new Exception('Invalid sort_order: must be ASC or DESC');
            }
        }
        
        return $filters;
    }
    
    /**
     * Build SQL query for reports retrieval
     * 
     * @param array $filters - Validated filters
     * @return array Query with SQL and parameters
     */
    private function buildReportsQuery(array $filters): array {
        $sql = "
            SELECT 
                id,
                report_code,
                report_type,
                status,
                request_parameters,
                download_url,
                file_size,
                records_processed,
                error_message,
                requested_at,
                completed_at,
                processed_at
            FROM ozon_stock_reports
            WHERE 1=1
        ";
        
        $params = [];
        $conditions = [];
        
        // Apply filters
        if ($filters['status']) {
            $conditions[] = "status = :status";
            $params['status'] = $filters['status'];
        }
        
        if ($filters['report_type']) {
            $conditions[] = "report_type = :report_type";
            $params['report_type'] = $filters['report_type'];
        }
        
        if ($filters['date_from']) {
            $conditions[] = "DATE(requested_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        
        if ($filters['date_to']) {
            $conditions[] = "DATE(requested_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        // Add conditions to SQL
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        // Add sorting
        $sql .= " ORDER BY {$filters['sort_by']} {$filters['sort_order']}";
        
        // Add pagination
        $sql .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $filters['limit'];
        $params['offset'] = $filters['offset'];
        
        return [
            'sql' => $sql,
            'params' => $params
        ];
    }
    
    /**
     * Build count query for pagination
     * 
     * @param array $filters - Validated filters
     * @return array Count query with SQL and parameters
     */
    private function buildReportsCountQuery(array $filters): array {
        $sql = "SELECT COUNT(*) FROM ozon_stock_reports WHERE 1=1";
        
        $params = [];
        $conditions = [];
        
        // Apply same filters as main query
        if ($filters['status']) {
            $conditions[] = "status = :status";
            $params['status'] = $filters['status'];
        }
        
        if ($filters['report_type']) {
            $conditions[] = "report_type = :report_type";
            $params['report_type'] = $filters['report_type'];
        }
        
        if ($filters['date_from']) {
            $conditions[] = "DATE(requested_at) >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }
        
        if ($filters['date_to']) {
            $conditions[] = "DATE(requested_at) <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }
        
        // Add conditions to SQL
        if (!empty($conditions)) {
            $sql .= " AND " . implode(" AND ", $conditions);
        }
        
        return [
            'sql' => $sql,
            'params' => $params
        ];
    }
    
    /**
     * Format reports data for API response
     * 
     * @param array $reports - Raw reports data from database
     * @return array Formatted reports data
     */
    private function formatReportsData(array $reports): array {
        return array_map(function($report) {
            $requestParams = null;
            if ($report['request_parameters']) {
                $requestParams = json_decode($report['request_parameters'], true);
            }
            
            return [
                'id' => (int) $report['id'],
                'report_code' => $report['report_code'],
                'report_type' => $report['report_type'],
                'status' => $report['status'],
                'request_parameters' => $requestParams,
                'download_url' => $report['download_url'],
                'file_size' => $report['file_size'] ? (int) $report['file_size'] : null,
                'records_processed' => (int) $report['records_processed'],
                'error_message' => $report['error_message'],
                'requested_at' => $report['requested_at'],
                'completed_at' => $report['completed_at'],
                'processed_at' => $report['processed_at'],
                'duration' => $this->calculateDuration($report['requested_at'], $report['completed_at'])
            ];
        }, $reports);
    }
    
    /**
     * Get report by code
     * 
     * @param string $reportCode - Report code
     * @return array|null Report data or null if not found
     */
    private function getReportByCode(string $reportCode): ?array {
        $sql = "
            SELECT 
                id,
                report_code,
                report_type,
                status,
                request_parameters,
                download_url,
                file_size,
                records_processed,
                error_message,
                requested_at,
                completed_at,
                processed_at
            FROM ozon_stock_reports
            WHERE report_code = :report_code
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['report_code' => $reportCode]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Get logs for a specific report
     * 
     * @param string $reportCode - Report code
     * @return array Report logs
     */
    private function getReportLogs(string $reportCode): array {
        $sql = "
            SELECT 
                id,
                log_level,
                message,
                context,
                created_at
            FROM stock_report_logs
            WHERE report_code = :report_code
            ORDER BY created_at ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['report_code' => $reportCode]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return array_map(function($log) {
            $context = null;
            if ($log['context']) {
                $context = json_decode($log['context'], true);
            }
            
            return [
                'id' => (int) $log['id'],
                'level' => $log['log_level'],
                'message' => $log['message'],
                'context' => $context,
                'created_at' => $log['created_at']
            ];
        }, $logs);
    }
    
    /**
     * Get processing statistics for a report
     * 
     * @param string $reportCode - Report code
     * @return array Processing statistics
     */
    private function getReportProcessingStats(string $reportCode): array {
        // Get inventory records created from this report
        $sql = "
            SELECT 
                COUNT(*) as total_records,
                COUNT(DISTINCT warehouse_name) as unique_warehouses,
                COUNT(DISTINCT product_id) as unique_products,
                SUM(quantity_present) as total_stock,
                MIN(updated_at) as first_processed,
                MAX(updated_at) as last_processed
            FROM inventory
            WHERE report_code = :report_code
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['report_code' => $reportCode]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$stats || $stats['total_records'] == 0) {
            return [
                'inventory_records_created' => 0,
                'unique_warehouses' => 0,
                'unique_products' => 0,
                'total_stock_processed' => 0
            ];
        }
        
        return [
            'inventory_records_created' => (int) $stats['total_records'],
            'unique_warehouses' => (int) $stats['unique_warehouses'],
            'unique_products' => (int) $stats['unique_products'],
            'total_stock_processed' => (int) $stats['total_stock'],
            'first_processed' => $stats['first_processed'],
            'last_processed' => $stats['last_processed']
        ];
    }
    
    /**
     * Format detailed report information
     * 
     * @param array $report - Report data
     * @param array $logs - Report logs
     * @param array $stats - Processing statistics
     * @return array Formatted report details
     */
    private function formatReportDetails(array $report, array $logs, array $stats): array {
        $requestParams = null;
        if ($report['request_parameters']) {
            $requestParams = json_decode($report['request_parameters'], true);
        }
        
        return [
            'id' => (int) $report['id'],
            'report_code' => $report['report_code'],
            'report_type' => $report['report_type'],
            'status' => $report['status'],
            'request_parameters' => $requestParams,
            'download_url' => $report['download_url'],
            'file_size' => $report['file_size'] ? (int) $report['file_size'] : null,
            'records_processed' => (int) $report['records_processed'],
            'error_message' => $report['error_message'],
            'requested_at' => $report['requested_at'],
            'completed_at' => $report['completed_at'],
            'processed_at' => $report['processed_at'],
            'duration' => $this->calculateDuration($report['requested_at'], $report['completed_at']),
            'processing_stats' => $stats,
            'logs' => $logs
        ];
    }
    
    /**
     * Get summary statistics for reports
     * 
     * @param array $filters - Applied filters
     * @return array Summary statistics
     */
    private function getReportsSummary(array $filters): array {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total_reports,
                    COUNT(CASE WHEN status = 'SUCCESS' THEN 1 END) as successful_reports,
                    COUNT(CASE WHEN status = 'ERROR' THEN 1 END) as failed_reports,
                    COUNT(CASE WHEN status = 'PROCESSING' THEN 1 END) as processing_reports,
                    COUNT(CASE WHEN status = 'TIMEOUT' THEN 1 END) as timeout_reports,
                    AVG(CASE WHEN completed_at IS NOT NULL THEN 
                        TIMESTAMPDIFF(MINUTE, requested_at, completed_at) 
                    END) as avg_processing_time_minutes,
                    SUM(records_processed) as total_records_processed,
                    MAX(requested_at) as last_report_requested
                FROM ozon_stock_reports
                WHERE 1=1
            ";
            
            $params = [];
            $conditions = [];
            
            // Apply same filters as main query
            if ($filters['status']) {
                $conditions[] = "status = :status";
                $params['status'] = $filters['status'];
            }
            
            if ($filters['report_type']) {
                $conditions[] = "report_type = :report_type";
                $params['report_type'] = $filters['report_type'];
            }
            
            if ($filters['date_from']) {
                $conditions[] = "DATE(requested_at) >= :date_from";
                $params['date_from'] = $filters['date_from'];
            }
            
            if ($filters['date_to']) {
                $conditions[] = "DATE(requested_at) <= :date_to";
                $params['date_to'] = $filters['date_to'];
            }
            
            // Add conditions to SQL
            if (!empty($conditions)) {
                $sql .= " AND " . implode(" AND ", $conditions);
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_reports' => (int) $summary['total_reports'],
                'successful_reports' => (int) $summary['successful_reports'],
                'failed_reports' => (int) $summary['failed_reports'],
                'processing_reports' => (int) $summary['processing_reports'],
                'timeout_reports' => (int) $summary['timeout_reports'],
                'success_rate' => $summary['total_reports'] > 0 ? 
                    round(($summary['successful_reports'] / $summary['total_reports']) * 100, 2) : 0,
                'avg_processing_time_minutes' => $summary['avg_processing_time_minutes'] ? 
                    round($summary['avg_processing_time_minutes'], 2) : null,
                'total_records_processed' => (int) $summary['total_records_processed'],
                'last_report_requested' => $summary['last_report_requested']
            ];
            
        } catch (PDOException $e) {
            $this->logger->error('Failed to get reports summary', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'total_reports' => 0,
                'error' => 'Failed to retrieve summary statistics'
            ];
        }
    }
    
    /**
     * Calculate pagination information
     * 
     * @param int $totalCount - Total number of records
     * @param int $limit - Records per page
     * @param int $offset - Current offset
     * @return array Pagination information
     */
    private function calculatePagination(int $totalCount, int $limit, int $offset): array {
        $currentPage = floor($offset / $limit) + 1;
        $totalPages = ceil($totalCount / $limit);
        
        return [
            'total_count' => $totalCount,
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'limit' => $limit,
            'offset' => $offset,
            'has_next' => $currentPage < $totalPages,
            'has_previous' => $currentPage > 1
        ];
    }
    
    /**
     * Calculate duration between two timestamps
     * 
     * @param string $start - Start timestamp
     * @param string $end - End timestamp
     * @return array|null Duration information
     */
    private function calculateDuration(?string $start, ?string $end): ?array {
        if (!$start || !$end) {
            return null;
        }
        
        try {
            $startTime = new DateTime($start);
            $endTime = new DateTime($end);
            $interval = $startTime->diff($endTime);
            
            $totalMinutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
            
            return [
                'total_minutes' => $totalMinutes,
                'formatted' => $interval->format('%h hours %i minutes %s seconds')
            ];
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Validate date format
     * 
     * @param string $date - Date string to validate
     * @return bool True if date is valid
     */
    private function validateDateFormat(string $date): bool {
        $dateTime = DateTime::createFromFormat('Y-m-d', $date);
        return $dateTime && $dateTime->format('Y-m-d') === $date;
    }
}