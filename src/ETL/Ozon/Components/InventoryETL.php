<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Components;

use Exception;
use InvalidArgumentException;
use RuntimeException;
use MiCore\ETL\Ozon\Core\BaseETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * Inventory ETL Component
 * 
 * Handles extraction, transformation, and loading of warehouse inventory data
 * from Ozon API to the inventory table. Implements report generation,
 * polling logic, CSV download and parsing, and full table refresh.
 * 
 * Requirements addressed:
 * - 3.1: Request warehouse stock report via `/v1/report/warehouse/stock` API daily
 * - 3.2: Poll `/v1/report/info` until report status becomes "success"
 * - 3.3: Download and parse CSV report containing SKU, warehouse name, and stock quantities
 * - 3.4: Store offer_id, warehouse_name, present, reserved quantities in inventory table
 * - 3.5: Completely refresh inventory table with each update to ensure data accuracy
 */
class InventoryETL extends BaseETL
{
    private string $reportLanguage;
    private int $maxWaitTime;
    private int $pollInterval;
    private int $maxAttempts;
    private bool $enableProgressCallback;
    private bool $validateCsvStructure;

    public function __construct(
        DatabaseConnection $db,
        Logger $logger,
        OzonApiClient $apiClient,
        array $config = []
    ) {
        parent::__construct($db, $logger, $apiClient, $config);
        
        // Set configuration with defaults
        $this->reportLanguage = $config['report_language'] ?? 'DEFAULT';
        $this->maxWaitTime = $config['max_wait_time'] ?? 1800; // 30 minutes
        $this->pollInterval = $config['poll_interval'] ?? 60; // 1 minute
        $this->maxAttempts = (int)ceil($this->maxWaitTime / $this->pollInterval);
        $this->enableProgressCallback = $config['enable_progress'] ?? true;
        $this->validateCsvStructure = $config['validate_csv_structure'] ?? true;
        
        // Validate configuration
        $this->validateInventoryETLConfig();
        
        $this->logger->info('InventoryETL initialized', [
            'report_language' => $this->reportLanguage,
            'max_wait_time' => $this->maxWaitTime,
            'poll_interval' => $this->pollInterval,
            'max_attempts' => $this->maxAttempts,
            'enable_progress' => $this->enableProgressCallback,
            'validate_csv_structure' => $this->validateCsvStructure
        ]);
    }

    /**
     * Extract inventory data from Ozon API via report generation
     * 
     * Implements the complete report workflow:
     * 1. Request report generation via API
     * 2. Poll for report completion with timeout handling
     * 3. Download and parse CSV report when ready
     * 
     * Requirements addressed:
     * - 3.1: Request warehouse stock report via `/v1/report/warehouse/stock` API daily
     * - 3.2: Poll `/v1/report/info` until report status becomes "success"
     * - 3.3: Download and parse CSV report containing SKU, warehouse name, and stock quantities
     * 
     * @return array Raw inventory data from CSV report
     * @throws Exception When extraction fails
     */
    public function extract(): array
    {
        $this->logger->info('Starting inventory extraction from Ozon API', [
            'report_language' => $this->reportLanguage,
            'max_wait_time' => $this->maxWaitTime,
            'poll_interval' => $this->pollInterval
        ]);
        
        $startTime = microtime(true);
        
        try {
            // Step 1: Request report generation
            $this->logger->info('Requesting warehouse stock report generation');
            $createResponse = $this->apiClient->createStockReport($this->reportLanguage);
            
            if (!isset($createResponse['result']['code'])) {
                throw new RuntimeException('Invalid response from stock report creation - missing report code');
            }
            
            $reportCode = $createResponse['result']['code'];
            
            $this->logger->info('Stock report creation initiated', [
                'report_code' => $reportCode,
                'language' => $this->reportLanguage
            ]);
            
            // Step 2: Wait for report completion with polling
            $this->logger->info('Starting report polling', [
                'report_code' => $reportCode,
                'max_attempts' => $this->maxAttempts,
                'poll_interval' => $this->pollInterval
            ]);
            
            $statusResponse = $this->waitForReportCompletion($reportCode);
            
            // Step 3: Download and parse CSV report
            $fileUrl = $statusResponse['result']['file'] ?? null;
            
            if (empty($fileUrl)) {
                throw new RuntimeException('Report completed but no file URL provided');
            }
            
            $this->logger->info('Downloading and parsing CSV report', [
                'report_code' => $reportCode,
                'file_url' => $fileUrl
            ]);
            
            $csvData = $this->downloadAndParseCsv($fileUrl);
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->info('Inventory extraction completed successfully', [
                'report_code' => $reportCode,
                'csv_rows' => count($csvData),
                'duration_seconds' => round($duration, 2),
                'file_url' => $fileUrl
            ]);
            
            // Update metrics
            $this->metrics['records_extracted'] = count($csvData);
            $this->metrics['extraction_duration'] = $duration;
            $this->metrics['report_code'] = $reportCode;
            $this->metrics['file_url'] = $fileUrl;
            
            return $csvData;
            
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            $this->logger->error('Inventory extraction failed', [
                'report_language' => $this->reportLanguage,
                'duration_seconds' => round($duration, 2),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception("Inventory extraction failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Wait for report completion with polling logic
     * 
     * Implements robust polling with:
     * - Configurable timeout and interval
     * - Progress tracking and logging
     * - Error handling for failed reports
     * - Status validation
     * 
     * Requirements addressed:
     * - 3.2: Poll `/v1/report/info` until report status becomes "success"
     * 
     * @param string $reportCode Report code to monitor
     * @return array Final report status response
     * @throws RuntimeException When report fails or times out
     */
    private function waitForReportCompletion(string $reportCode): array
    {
        $attempt = 0;
        $startTime = microtime(true);
        
        while ($attempt < $this->maxAttempts) {
            $attempt++;
            
            try {
                $statusResponse = $this->apiClient->getReportStatus($reportCode);
                $status = $statusResponse['result']['status'] ?? 'unknown';
                
                $this->logger->debug('Report polling attempt', [
                    'report_code' => $reportCode,
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'status' => $status,
                    'elapsed_seconds' => round(microtime(true) - $startTime, 1)
                ]);
                
                // Progress callback for monitoring
                if ($this->enableProgressCallback) {
                    $this->logPollingProgress($reportCode, $attempt, $status);
                }
                
                switch ($status) {
                    case 'success':
                        $duration = microtime(true) - $startTime;
                        $fileUrl = $statusResponse['result']['file'] ?? null;
                        
                        $this->logger->info('Report completed successfully', [
                            'report_code' => $reportCode,
                            'attempts' => $attempt,
                            'duration_seconds' => round($duration, 2),
                            'file_url' => $fileUrl
                        ]);
                        
                        if (empty($fileUrl)) {
                            throw new RuntimeException('Report completed but no file URL provided');
                        }
                        
                        return $statusResponse;
                        
                    case 'failed':
                    case 'error':
                        $errorMessage = $statusResponse['result']['error'] ?? 'Unknown error';
                        $this->logger->error('Report generation failed', [
                            'report_code' => $reportCode,
                            'status' => $status,
                            'error' => $errorMessage,
                            'attempts' => $attempt
                        ]);
                        throw new RuntimeException("Report generation failed: {$errorMessage}");
                        
                    case 'processing':
                    case 'waiting':
                    case 'in_progress':
                        // Continue polling
                        if ($attempt < $this->maxAttempts) {
                            $this->logger->debug('Report still processing, waiting', [
                                'report_code' => $reportCode,
                                'status' => $status,
                                'next_check_in' => $this->pollInterval,
                                'progress_percent' => round(($attempt / $this->maxAttempts) * 100, 1)
                            ]);
                            sleep($this->pollInterval);
                        }
                        break;
                        
                    default:
                        $this->logger->warning('Unknown report status', [
                            'report_code' => $reportCode,
                            'status' => $status,
                            'attempt' => $attempt
                        ]);
                        
                        // Continue polling for unknown statuses
                        if ($attempt < $this->maxAttempts) {
                            sleep($this->pollInterval);
                        }
                }
                
            } catch (Exception $e) {
                $this->logger->error('Error during report polling', [
                    'report_code' => $reportCode,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                // If it's the last attempt, throw the error
                if ($attempt >= $this->maxAttempts) {
                    throw $e;
                }
                
                // Otherwise, wait and try again
                $this->logger->info('Retrying after polling error', [
                    'report_code' => $reportCode,
                    'retry_in' => $this->pollInterval
                ]);
                sleep($this->pollInterval);
            }
        }
        
        // Timeout reached
        $totalWaitTime = $this->maxAttempts * $this->pollInterval;
        $this->logger->error('Report polling timeout', [
            'report_code' => $reportCode,
            'max_attempts' => $this->maxAttempts,
            'total_wait_time' => $totalWaitTime,
            'poll_interval' => $this->pollInterval
        ]);
        
        throw new RuntimeException(
            "Report generation timeout after {$this->maxAttempts} attempts " .
            "({$totalWaitTime} seconds). Report code: {$reportCode}"
        );
    }

    /**
     * Log polling progress for monitoring
     * 
     * @param string $reportCode Report code
     * @param int $attempt Current attempt number
     * @param string $status Current status
     * @return void
     */
    private function logPollingProgress(string $reportCode, int $attempt, string $status): void
    {
        $progressPercent = round(($attempt / $this->maxAttempts) * 100, 1);
        $estimatedTimeRemaining = ($this->maxAttempts - $attempt) * $this->pollInterval;
        
        $this->logger->info('Report polling progress', [
            'report_code' => $reportCode,
            'status' => $status,
            'progress_percent' => $progressPercent,
            'attempt' => $attempt,
            'max_attempts' => $this->maxAttempts,
            'estimated_time_remaining' => $estimatedTimeRemaining
        ]);
    }

    /**
     * Validate InventoryETL specific configuration
     * 
     * @return void
     * @throws InvalidArgumentException When configuration is invalid
     */
    private function validateInventoryETLConfig(): void
    {
        if ($this->maxWaitTime <= 0) {
            throw new InvalidArgumentException('max_wait_time must be positive');
        }
        
        if ($this->pollInterval <= 0) {
            throw new InvalidArgumentException('poll_interval must be positive');
        }
        
        if ($this->maxWaitTime < $this->pollInterval) {
            throw new InvalidArgumentException('max_wait_time must be greater than poll_interval');
        }
        
        $validLanguages = ['DEFAULT', 'RU', 'EN'];
        if (!in_array($this->reportLanguage, $validLanguages)) {
            throw new InvalidArgumentException(
                'Invalid report_language. Must be one of: ' . implode(', ', $validLanguages)
            );
        }
    }

    /**
     * Download and parse CSV report file with validation
     * 
     * Implements comprehensive CSV processing with:
     * - File download with error handling
     * - CSV structure validation
     * - Header validation and mapping
     * - Data type validation
     * - Error reporting and recovery
     * 
     * Requirements addressed:
     * - 3.3: Download and parse CSV report containing SKU, warehouse name, and stock quantities
     * - 3.4: Validate CSV structure and handle parsing errors
     * 
     * @param string $fileUrl URL of the CSV file to download
     * @return array Parsed and validated CSV data
     * @throws RuntimeException When download or parsing fails
     */
    public function downloadAndParseCsv(string $fileUrl): array
    {
        $this->logger->info('Starting CSV download and parsing', [
            'file_url' => $fileUrl,
            'validate_structure' => $this->validateCsvStructure
        ]);
        
        $startTime = microtime(true);
        
        try {
            // Download CSV file using API client
            $csvData = $this->apiClient->downloadAndParseCsv($fileUrl, true, ',', '"');
            
            $downloadDuration = microtime(true) - $startTime;
            
            $this->logger->info('CSV file downloaded successfully', [
                'file_url' => $fileUrl,
                'raw_rows' => count($csvData),
                'download_duration' => round($downloadDuration, 3)
            ]);
            
            // Validate CSV structure if enabled
            if ($this->validateCsvStructure) {
                $this->validateCsvStructure($csvData);
            }
            
            // Validate and normalize CSV data
            $validatedData = $this->validateAndNormalizeCsvData($csvData);
            
            $totalDuration = microtime(true) - $startTime;
            
            $this->logger->info('CSV parsing completed successfully', [
                'file_url' => $fileUrl,
                'raw_rows' => count($csvData),
                'validated_rows' => count($validatedData),
                'total_duration' => round($totalDuration, 3),
                'validation_enabled' => $this->validateCsvStructure
            ]);
            
            return $validatedData;
            
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            
            $this->logger->error('CSV download and parsing failed', [
                'file_url' => $fileUrl,
                'duration' => round($duration, 3),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new RuntimeException("CSV download and parsing failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate CSV structure and required headers
     * 
     * Checks for required columns and validates CSV format according to
     * Ozon warehouse stock report specification.
     * 
     * @param array $csvData Raw CSV data with headers
     * @return void
     * @throws RuntimeException When CSV structure is invalid
     */
    private function validateCsvStructure(array $csvData): void
    {
        if (empty($csvData)) {
            throw new RuntimeException('CSV file is empty');
        }
        
        // Get headers from first row (assuming API client parsed with headers)
        $firstRow = reset($csvData);
        if (!is_array($firstRow)) {
            throw new RuntimeException('Invalid CSV data structure');
        }
        
        $headers = array_keys($firstRow);
        
        // Define required headers based on Ozon warehouse stock report format
        $requiredHeaders = [
            'SKU',                    // Product SKU (offer_id)
            'Warehouse name',         // Warehouse name
            'Item Name',             // Product name
            'Present',               // Present quantity
            'Reserved'               // Reserved quantity
        ];
        
        // Check for required headers
        $missingHeaders = [];
        foreach ($requiredHeaders as $requiredHeader) {
            if (!in_array($requiredHeader, $headers)) {
                $missingHeaders[] = $requiredHeader;
            }
        }
        
        if (!empty($missingHeaders)) {
            $this->logger->error('CSV structure validation failed - missing headers', [
                'missing_headers' => $missingHeaders,
                'available_headers' => $headers,
                'required_headers' => $requiredHeaders
            ]);
            
            throw new RuntimeException(
                'CSV missing required headers: ' . implode(', ', $missingHeaders) . 
                '. Available headers: ' . implode(', ', $headers)
            );
        }
        
        // Validate minimum number of rows
        if (count($csvData) < 1) {
            throw new RuntimeException('CSV file contains no data rows');
        }
        
        $this->logger->info('CSV structure validation passed', [
            'total_rows' => count($csvData),
            'headers' => $headers,
            'required_headers_found' => count($requiredHeaders)
        ]);
    }

    /**
     * Validate and normalize CSV data rows
     * 
     * Performs comprehensive validation and normalization:
     * - Required field validation
     * - Data type validation and conversion
     * - SKU format validation
     * - Quantity validation (non-negative integers)
     * - Warehouse name validation
     * - Error collection and reporting
     * 
     * @param array $csvData Raw CSV data
     * @return array Validated and normalized data
     * @throws RuntimeException When validation fails critically
     */
    private function validateAndNormalizeCsvData(array $csvData): array
    {
        $this->logger->info('Starting CSV data validation and normalization', [
            'total_rows' => count($csvData)
        ]);
        
        $validatedData = [];
        $validRows = 0;
        $skippedRows = 0;
        $errors = [];
        $duplicateSkus = [];
        $seenSkus = [];
        
        foreach ($csvData as $rowIndex => $row) {
            try {
                // Validate row structure
                $rowValidation = $this->validateInventoryRow($row, $rowIndex);
                
                if (!$rowValidation['valid']) {
                    $skippedRows++;
                    $errors[] = $rowValidation['error'];
                    
                    $this->logger->warning('Skipping invalid inventory row', [
                        'row_index' => $rowIndex,
                        'sku' => $row['SKU'] ?? 'unknown',
                        'warehouse' => $row['Warehouse name'] ?? 'unknown',
                        'error' => $rowValidation['error']
                    ]);
                    continue;
                }
                
                // Check for duplicate SKU + Warehouse combinations
                $uniqueKey = trim($row['SKU']) . '|' . trim($row['Warehouse name']);
                
                if (isset($seenSkus[$uniqueKey])) {
                    $duplicateSkus[] = $uniqueKey;
                    $this->logger->warning('Duplicate SKU+Warehouse combination detected', [
                        'sku' => trim($row['SKU']),
                        'warehouse' => trim($row['Warehouse name']),
                        'first_occurrence' => $seenSkus[$uniqueKey],
                        'current_occurrence' => $rowIndex
                    ]);
                    // Keep the latest occurrence
                }
                $seenSkus[$uniqueKey] = $rowIndex;
                
                // Normalize and validate single row
                $normalizedRow = $this->normalizeInventoryRow($row);
                $validatedData[] = $normalizedRow;
                $validRows++;
                
            } catch (Exception $e) {
                $skippedRows++;
                $errorMessage = "Row validation error at index {$rowIndex}: " . $e->getMessage();
                $errors[] = $errorMessage;
                
                $this->logger->warning('Row validation error', [
                    'row_index' => $rowIndex,
                    'sku' => $row['SKU'] ?? 'unknown',
                    'warehouse' => $row['Warehouse name'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->logger->info('CSV data validation completed', [
            'total_rows' => count($csvData),
            'valid_rows' => $validRows,
            'skipped_rows' => $skippedRows,
            'error_count' => count($errors),
            'duplicate_combinations' => count($duplicateSkus),
            'unique_combinations' => count($seenSkus)
        ]);
        
        // Log errors if any
        if (!empty($errors)) {
            $this->logger->warning('CSV validation errors summary', [
                'total_errors' => count($errors),
                'sample_errors' => array_slice($errors, 0, 5) // Log first 5 errors
            ]);
        }
        
        // Log duplicate combinations if any
        if (!empty($duplicateSkus)) {
            $this->logger->warning('Duplicate SKU+Warehouse combinations found', [
                'duplicate_count' => count($duplicateSkus),
                'sample_duplicates' => array_slice(array_unique($duplicateSkus), 0, 5)
            ]);
        }
        
        // Check if we have enough valid data
        $validDataPercentage = count($csvData) > 0 ? ($validRows / count($csvData)) * 100 : 0;
        
        if ($validDataPercentage < 50) {
            throw new RuntimeException(
                "Too many validation errors: only {$validDataPercentage}% of rows are valid. " .
                "This may indicate a problem with the CSV format or data quality."
            );
        }
        
        // Update metrics
        $this->metrics['csv_validation'] = [
            'total_rows' => count($csvData),
            'valid_rows' => $validRows,
            'skipped_rows' => $skippedRows,
            'error_count' => count($errors),
            'duplicate_combinations' => count($duplicateSkus),
            'valid_percentage' => round($validDataPercentage, 2)
        ];
        
        return $validatedData;
    }

    /**
     * Validate individual inventory row data
     * 
     * @param array $row CSV row data
     * @param int $rowIndex Row index for error reporting
     * @return array Validation result with 'valid' boolean and 'error' message
     */
    private function validateInventoryRow(array $row, int $rowIndex): array
    {
        // Check required fields
        $requiredFields = ['SKU', 'Warehouse name', 'Present', 'Reserved'];
        
        foreach ($requiredFields as $field) {
            if (!isset($row[$field]) || $row[$field] === '' || $row[$field] === null) {
                return [
                    'valid' => false,
                    'error' => "Missing required field '{$field}' in row {$rowIndex}"
                ];
            }
        }
        
        // Validate SKU (offer_id) is not empty
        $sku = trim($row['SKU']);
        if ($sku === '') {
            return [
                'valid' => false,
                'error' => "Empty SKU in row {$rowIndex}"
            ];
        }
        
        // Validate SKU length
        if (strlen($sku) > 255) {
            return [
                'valid' => false,
                'error' => "SKU too long in row {$rowIndex}: maximum 255 characters allowed"
            ];
        }
        
        // Validate warehouse name is not empty
        $warehouseName = trim($row['Warehouse name']);
        if ($warehouseName === '') {
            return [
                'valid' => false,
                'error' => "Empty warehouse name in row {$rowIndex}"
            ];
        }
        
        // Validate warehouse name length
        if (strlen($warehouseName) > 255) {
            return [
                'valid' => false,
                'error' => "Warehouse name too long in row {$rowIndex}: maximum 255 characters allowed"
            ];
        }
        
        // Validate Present quantity
        if (!is_numeric($row['Present']) || (int)$row['Present'] < 0) {
            return [
                'valid' => false,
                'error' => "Invalid Present quantity in row {$rowIndex}: must be non-negative integer"
            ];
        }
        
        // Validate Reserved quantity
        if (!is_numeric($row['Reserved']) || (int)$row['Reserved'] < 0) {
            return [
                'valid' => false,
                'error' => "Invalid Reserved quantity in row {$rowIndex}: must be non-negative integer"
            ];
        }
        
        // Validate that Reserved <= Present (logical constraint)
        $present = (int)$row['Present'];
        $reserved = (int)$row['Reserved'];
        
        if ($reserved > $present) {
            return [
                'valid' => false,
                'error' => "Invalid quantities in row {$rowIndex}: Reserved ({$reserved}) cannot be greater than Present ({$present})"
            ];
        }
        
        return ['valid' => true, 'error' => null];
    }

    /**
     * Normalize inventory row to database format
     * 
     * Performs comprehensive data normalization including:
     * - String trimming and null handling
     * - Type casting for numeric fields
     * - Available quantity calculation
     * - Timestamp addition
     * 
     * Requirements addressed:
     * - 3.4: Prepare data for inventory table with offer_id, warehouse_name, present, reserved quantities
     * 
     * @param array $row Raw CSV row data
     * @return array Normalized inventory row data
     */
    private function normalizeInventoryRow(array $row): array
    {
        // Normalize offer_id (SKU)
        $offerId = trim($row['SKU']);
        
        // Normalize warehouse name
        $warehouseName = trim($row['Warehouse name']);
        
        // Normalize item name (optional field)
        $itemName = isset($row['Item Name']) && trim($row['Item Name']) !== '' 
            ? trim($row['Item Name']) 
            : null;
        
        // Normalize quantities to integers
        $present = (int)$row['Present'];
        $reserved = (int)$row['Reserved'];
        
        // Calculate available quantity (Present - Reserved)
        $available = max(0, $present - $reserved);
        
        return [
            'offer_id' => $offerId,
            'warehouse_name' => $warehouseName,
            'item_name' => $itemName,
            'present' => $present,
            'reserved' => $reserved,
            'available' => $available,
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Transform raw CSV data into normalized format
     * 
     * Performs final data transformation and validation before loading.
     * This method serves as a bridge between extraction and loading phases,
     * ensuring data consistency and applying any final business rules.
     * 
     * @param array $data Raw CSV data from extraction
     * @return array Transformed data ready for loading
     * @throws Exception When transformation fails
     */
    public function transform(array $data): array
    {
        $this->logger->info('Starting inventory data transformation', [
            'input_rows_count' => count($data)
        ]);
        
        // The data is already normalized during CSV parsing,
        // but we can apply additional transformations here if needed
        
        $transformedData = [];
        $validItems = 0;
        $skippedItems = 0;
        
        foreach ($data as $index => $item) {
            try {
                // Apply any additional business rules or transformations
                $transformedItem = $this->applyBusinessRules($item, $index);
                
                if ($transformedItem !== null) {
                    $transformedData[] = $transformedItem;
                    $validItems++;
                } else {
                    $skippedItems++;
                }
                
            } catch (Exception $e) {
                $skippedItems++;
                $this->logger->warning('Item transformation error', [
                    'index' => $index,
                    'offer_id' => $item['offer_id'] ?? 'unknown',
                    'warehouse_name' => $item['warehouse_name'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->logger->info('Inventory transformation completed', [
            'input_rows' => count($data),
            'valid_items' => $validItems,
            'skipped_items' => $skippedItems
        ]);
        
        // Update metrics
        $this->metrics['records_transformed'] = $validItems;
        $this->metrics['transformation_errors'] = $skippedItems;
        
        return $transformedData;
    }

    /**
     * Apply business rules to inventory items
     * 
     * @param array $item Inventory item data
     * @param int $index Item index for error reporting
     * @return array|null Transformed item or null if should be skipped
     */
    private function applyBusinessRules(array $item, int $index): ?array
    {
        // Example business rules (can be extended based on requirements):
        
        // Skip items with zero present and reserved quantities
        if ($item['present'] === 0 && $item['reserved'] === 0) {
            $this->logger->debug('Skipping item with zero quantities', [
                'offer_id' => $item['offer_id'],
                'warehouse_name' => $item['warehouse_name']
            ]);
            return null;
        }
        
        // Ensure available quantity is correctly calculated
        $item['available'] = max(0, $item['present'] - $item['reserved']);
        
        // Add any additional computed fields
        $item['has_stock'] = $item['available'] > 0;
        $item['is_reserved'] = $item['reserved'] > 0;
        
        return $item;
    }

    /**
     * Load transformed inventory data with full table refresh
     * 
     * Implements comprehensive full refresh loading with:
     * - Complete table truncation for data accuracy
     * - Batch processing for optimal performance
     * - Transaction management for data consistency
     * - Detailed statistics tracking
     * - Data integrity verification
     * - Rollback on failure
     * 
     * Requirements addressed:
     * - 3.4: Store offer_id, warehouse_name, present, reserved quantities in inventory table
     * - 3.5: Completely refresh inventory table with each update to ensure data accuracy
     * 
     * @param array $data Transformed inventory data
     * @return void
     * @throws Exception When loading fails
     */
    public function load(array $data): void
    {
        $this->logger->info('Starting inventory data loading with full refresh', [
            'inventory_items_count' => count($data)
        ]);
        
        if (empty($data)) {
            $this->logger->warning('No inventory data to load');
            return;
        }
        
        $totalLoaded = 0;
        $totalBatches = 0;
        $startTime = microtime(true);
        $batchSize = $this->config['batch_size'] ?? 1000;
        
        // Begin transaction for data consistency
        $this->db->beginTransaction();
        
        try {
            // Step 1: Backup current data count for verification
            $oldCount = $this->getCurrentInventoryCount();
            
            $this->logger->info('Current inventory count before refresh', [
                'current_count' => $oldCount
            ]);
            
            // Step 2: Truncate inventory table for full refresh
            $this->logger->info('Truncating inventory table for full refresh');
            $this->truncateInventoryTable();
            
            // Step 3: Load new data in batches
            $batches = array_chunk($data, $batchSize);
            
            $this->logger->info('Processing inventory data in batches', [
                'total_batches' => count($batches),
                'batch_size' => $batchSize,
                'total_items' => count($data)
            ]);
            
            foreach ($batches as $batchIndex => $batch) {
                $this->logger->debug('Processing inventory batch for loading', [
                    'batch_number' => $batchIndex + 1,
                    'batch_size' => count($batch),
                    'total_batches' => count($batches),
                    'progress_percent' => round((($batchIndex + 1) / count($batches)) * 100, 1)
                ]);
                
                $batchResult = $this->loadInventoryBatch($batch);
                $totalLoaded += $batchResult['loaded'];
                $totalBatches++;
                
                $this->logger->debug('Inventory batch loaded', [
                    'batch_number' => $batchIndex + 1,
                    'loaded_count' => $batchResult['loaded'],
                    'total_loaded' => $totalLoaded,
                    'batch_duration' => round($batchResult['duration'], 3)
                ]);
            }
            
            // Step 4: Verify data integrity
            $verificationResult = $this->verifyLoadedInventoryData($data);
            
            // Step 5: Update table statistics (PostgreSQL specific)
            $this->updateTableStatistics();
            
            // Commit transaction
            $this->db->commit();
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->info('Inventory loading completed successfully', [
                'old_count' => $oldCount,
                'new_count' => $totalLoaded,
                'total_batches' => $totalBatches,
                'duration_seconds' => round($duration, 2),
                'items_per_second' => $totalLoaded > 0 ? round($totalLoaded / $duration, 2) : 0,
                'verification_rate' => $verificationResult['verification_rate']
            ]);
            
            // Update metrics
            $this->metrics['records_loaded'] = $totalLoaded;
            $this->metrics['loading_batches'] = $totalBatches;
            $this->metrics['loading_duration'] = $duration;
            $this->metrics['old_inventory_count'] = $oldCount;
            $this->metrics['verification_rate'] = $verificationResult['verification_rate'];
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $this->db->rollback();
            
            $this->logger->error('Inventory loading failed', [
                'total_loaded_before_error' => $totalLoaded,
                'total_batches_processed' => $totalBatches,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception("Inventory loading failed after {$totalBatches} batches: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get current inventory count for verification
     * 
     * @return int Current number of inventory records
     */
    private function getCurrentInventoryCount(): int
    {
        try {
            $result = $this->db->query('SELECT COUNT(*) as count FROM inventory');
            return (int)($result[0]['count'] ?? 0);
        } catch (Exception $e) {
            $this->logger->warning('Failed to get current inventory count', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Truncate inventory table for full refresh
     * 
     * @return void
     * @throws Exception When truncation fails
     */
    private function truncateInventoryTable(): void
    {
        try {
            $this->db->execute('TRUNCATE TABLE inventory RESTART IDENTITY');
            
            $this->logger->info('Inventory table truncated successfully');
            
        } catch (Exception $e) {
            $this->logger->error('Failed to truncate inventory table', [
                'error' => $e->getMessage()
            ]);
            throw new Exception('Failed to truncate inventory table: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Load single batch of inventory data
     * 
     * @param array $batch Batch of inventory items
     * @return array Batch loading result
     * @throws Exception When batch loading fails
     */
    private function loadInventoryBatch(array $batch): array
    {
        $startTime = microtime(true);
        
        try {
            // Prepare data for batch insert
            $columns = [
                'offer_id',
                'warehouse_name', 
                'item_name',
                'present',
                'reserved',
                'available',
                'updated_at'
            ];
            
            // Execute batch insert
            $affectedRows = $this->db->batchInsert('inventory', $columns, $batch);
            
            $duration = microtime(true) - $startTime;
            
            return [
                'loaded' => $affectedRows,
                'duration' => $duration
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Batch loading failed', [
                'batch_size' => count($batch),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Verify loaded inventory data integrity
     * 
     * @param array $originalData Original data for comparison
     * @return array Verification result
     */
    private function verifyLoadedInventoryData(array $originalData): array
    {
        try {
            // Get loaded count
            $loadedCount = $this->getCurrentInventoryCount();
            
            // Calculate verification rate
            $expectedCount = count($originalData);
            $verificationRate = $expectedCount > 0 ? ($loadedCount / $expectedCount) * 100 : 100;
            
            // Sample verification - check a few random records
            $sampleSize = min(10, $expectedCount);
            $sampleVerified = 0;
            
            if ($sampleSize > 0) {
                $sampleIndices = array_rand($originalData, $sampleSize);
                if (!is_array($sampleIndices)) {
                    $sampleIndices = [$sampleIndices];
                }
                
                foreach ($sampleIndices as $index) {
                    $originalItem = $originalData[$index];
                    
                    $result = $this->db->query(
                        'SELECT * FROM inventory WHERE offer_id = ? AND warehouse_name = ?',
                        [$originalItem['offer_id'], $originalItem['warehouse_name']]
                    );
                    
                    if (!empty($result)) {
                        $sampleVerified++;
                    }
                }
            }
            
            $sampleVerificationRate = $sampleSize > 0 ? ($sampleVerified / $sampleSize) * 100 : 100;
            
            $this->logger->info('Data integrity verification completed', [
                'expected_count' => $expectedCount,
                'loaded_count' => $loadedCount,
                'verification_rate' => round($verificationRate, 2),
                'sample_size' => $sampleSize,
                'sample_verified' => $sampleVerified,
                'sample_verification_rate' => round($sampleVerificationRate, 2)
            ]);
            
            return [
                'verification_rate' => round($verificationRate, 2),
                'sample_verification_rate' => round($sampleVerificationRate, 2),
                'expected_count' => $expectedCount,
                'loaded_count' => $loadedCount
            ];
            
        } catch (Exception $e) {
            $this->logger->warning('Data verification failed', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'verification_rate' => 0,
                'sample_verification_rate' => 0,
                'expected_count' => count($originalData),
                'loaded_count' => 0
            ];
        }
    }

    /**
     * Update table statistics for query optimization
     * 
     * @return void
     */
    private function updateTableStatistics(): void
    {
        try {
            $this->db->execute('ANALYZE inventory');
            $this->logger->debug('Table statistics updated for inventory table');
        } catch (Exception $e) {
            $this->logger->warning('Failed to update table statistics', [
                'error' => $e->getMessage()
            ]);
        }
    }
}