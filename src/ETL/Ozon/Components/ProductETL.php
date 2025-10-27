<?php

declare(strict_types=1);

namespace MiCore\ETL\Ozon\Components;

use Exception;
use InvalidArgumentException;
use MiCore\ETL\Ozon\Core\BaseETL;
use MiCore\ETL\Ozon\Core\DatabaseConnection;
use MiCore\ETL\Ozon\Core\Logger;
use MiCore\ETL\Ozon\Api\OzonApiClient;

/**
 * Product ETL Component
 * 
 * Handles extraction, transformation, and loading of product catalog data
 * from Ozon API to the dim_products table. Implements pagination handling
 * and large volume data processing.
 * 
 * Requirements addressed:
 * - 1.1: Extract product catalog data from Ozon API endpoint `/v2/product/list` daily
 * - 1.2: Store product_id, offer_id, name, fbo_sku, and fbs_sku for each product
 */
class ProductETL extends BaseETL
{
    private int $batchSize;
    private int $maxProducts;
    private bool $enableProgressCallback;

    public function __construct(
        DatabaseConnection $db,
        Logger $logger,
        OzonApiClient $apiClient,
        array $config = []
    ) {
        parent::__construct($db, $logger, $apiClient, $config);
        
        // Set configuration with defaults
        $this->batchSize = $config['batch_size'] ?? 1000;
        $this->maxProducts = $config['max_products'] ?? 0; // 0 = no limit
        $this->enableProgressCallback = $config['enable_progress'] ?? true;
        
        // Validate configuration
        $this->validateProductETLConfig();
        
        $this->logger->info('ProductETL initialized', [
            'batch_size' => $this->batchSize,
            'max_products' => $this->maxProducts,
            'enable_progress' => $this->enableProgressCallback
        ]);
    }

    /**
     * Extract product data from Ozon API using products report
     * 
     * Uses the products report API to get comprehensive product data including
     * visibility status. Implements report polling logic similar to InventoryETL.
     * 
     * @return array Raw product data from CSV report
     * @throws Exception When extraction fails
     */
    public function extract(): array
    {
        $this->logger->info('Starting product extraction from Ozon products report API');
        
        $startTime = microtime(true);
        
        try {
            // Create products report
            $this->logger->info('Creating products report');
            $reportCode = $this->apiClient->createProductsReport();
            $reportCode = $reportCode['result']['code'];
            
            $this->logger->info('Products report created', [
                'report_code' => $reportCode
            ]);
            
            // Wait for report completion
            $this->logger->info('Waiting for products report completion');
            $statusResponse = $this->apiClient->waitForReportCompletion($reportCode);
            
            // Download and parse CSV
            $fileUrl = $statusResponse['result']['file'];
            $this->logger->info('Downloading and parsing products CSV', [
                'file_url' => $fileUrl
            ]);
            
            $csvData = $this->apiClient->downloadAndParseCsv($fileUrl);
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->info('Product extraction completed successfully', [
                'total_products' => count($csvData),
                'duration_seconds' => round($duration, 2),
                'report_code' => $reportCode
            ]);
            
            // Update metrics
            $this->metrics['records_extracted'] = count($csvData);
            $this->metrics['extraction_duration'] = $duration;
            $this->metrics['report_code'] = $reportCode;
            
            return $csvData;
            
        } catch (Exception $e) {
            $this->logger->error('Product extraction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception("Product extraction failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Transform raw product data from CSV into normalized format
     * 
     * Validates CSV structure, normalizes product data and validates required fields
     * according to the database schema requirements. Implements comprehensive
     * data validation and normalization for product_id, offer_id, visibility fields.
     * 
     * Requirements addressed:
     * - 1.1: Extract visibility field from CSV and validate values
     * - 1.2: Validate and normalize product_id, offer_id, name, fbo_sku, fbs_sku, visibility
     * - 2.1: Map Ozon visibility values to standardized internal values
     * 
     * @param array $data Raw product data from CSV report
     * @return array Transformed product data ready for loading
     * @throws Exception When transformation fails
     */
    public function transform(array $data): array
    {
        $this->logger->info('Starting product data transformation', [
            'input_count' => count($data)
        ]);
        
        // Validate CSV structure first
        $this->validateProductsCsvStructure($data);
        
        $transformedProducts = [];
        $validProducts = 0;
        $skippedProducts = 0;
        $errors = [];
        $duplicateOfferIds = [];
        $seenOfferIds = [];
        
        foreach ($data as $index => $rawProduct) {
            try {
                // Validate required fields
                $validationResult = $this->validateProductData($rawProduct, $index);
                
                if (!$validationResult['valid']) {
                    $skippedProducts++;
                    $errors[] = $validationResult['error'];
                    
                    $this->logger->warning('Skipping invalid product', [
                        'index' => $index,
                        'product_id' => $rawProduct['product_id'] ?? 'unknown',
                        'offer_id' => $rawProduct['offer_id'] ?? 'unknown',
                        'error' => $validationResult['error']
                    ]);
                    continue;
                }
                
                // Check for duplicate offer_ids in the batch
                $offerId = trim($rawProduct['offer_id']);
                if (isset($seenOfferIds[$offerId])) {
                    $duplicateOfferIds[] = $offerId;
                    $this->logger->warning('Duplicate offer_id detected in batch', [
                        'offer_id' => $offerId,
                        'first_index' => $seenOfferIds[$offerId],
                        'current_index' => $index
                    ]);
                    // Keep the latest occurrence
                }
                $seenOfferIds[$offerId] = $index;
                
                // Transform product data with comprehensive normalization
                $transformedProduct = $this->transformSingleProduct($rawProduct);
                $transformedProducts[] = $transformedProduct;
                $validProducts++;
                
            } catch (Exception $e) {
                $skippedProducts++;
                $errorMessage = "Transformation error at index {$index}: " . $e->getMessage();
                $errors[] = $errorMessage;
                
                $this->logger->warning('Product transformation error', [
                    'index' => $index,
                    'product_id' => $rawProduct['product_id'] ?? 'unknown',
                    'offer_id' => $rawProduct['offer_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->logger->info('Product transformation completed', [
            'input_count' => count($data),
            'valid_products' => $validProducts,
            'skipped_products' => $skippedProducts,
            'error_count' => count($errors),
            'duplicate_offer_ids' => count($duplicateOfferIds),
            'unique_offer_ids' => count($seenOfferIds)
        ]);
        
        // Log errors if any
        if (!empty($errors)) {
            $this->logger->warning('Transformation errors summary', [
                'total_errors' => count($errors),
                'sample_errors' => array_slice($errors, 0, 5) // Log first 5 errors
            ]);
        }
        
        // Log duplicate offer_ids if any
        if (!empty($duplicateOfferIds)) {
            $this->logger->warning('Duplicate offer_ids found in batch', [
                'duplicate_count' => count($duplicateOfferIds),
                'sample_duplicates' => array_slice(array_unique($duplicateOfferIds), 0, 5)
            ]);
        }
        
        // Update metrics
        $this->metrics['records_transformed'] = $validProducts;
        $this->metrics['transformation_errors'] = count($errors);
        $this->metrics['skipped_records'] = $skippedProducts;
        $this->metrics['duplicate_offer_ids'] = count($duplicateOfferIds);
        
        return $transformedProducts;
    }

    /**
     * Load transformed product data into database
     * 
     * Implements comprehensive upsert logic with:
     * - Transaction management for data consistency
     * - Batch processing for optimal performance
     * - Detailed statistics tracking (inserts vs updates)
     * - Data integrity verification
     * - Comprehensive error handling and rollback
     * 
     * Requirements addressed:
     * - 1.3: Update existing products and insert new products in dim_products table
     * - 1.5: Batch processing for performance optimization
     * 
     * @param array $data Transformed product data
     * @return void
     * @throws Exception When loading fails
     */
    public function load(array $data): void
    {
        $this->logger->info('Starting product data loading', [
            'product_count' => count($data)
        ]);
        
        if (empty($data)) {
            $this->logger->warning('No products to load');
            return;
        }
        
        $totalLoaded = 0;
        $totalInserted = 0;
        $totalUpdated = 0;
        $totalBatches = 0;
        $startTime = microtime(true);
        
        // Begin transaction for data consistency
        $this->db->beginTransaction();
        
        try {
            // Process data in batches for optimal performance
            $batches = array_chunk($data, $this->batchSize);
            
            $this->logger->info('Processing products in batches', [
                'total_batches' => count($batches),
                'batch_size' => $this->batchSize,
                'total_products' => count($data)
            ]);
            
            foreach ($batches as $batchIndex => $batch) {
                $this->logger->debug('Processing product batch for loading', [
                    'batch_number' => $batchIndex + 1,
                    'batch_size' => count($batch),
                    'total_batches' => count($batches),
                    'progress_percent' => round((($batchIndex + 1) / count($batches)) * 100, 1)
                ]);
                
                $batchResult = $this->loadProductBatch($batch);
                $totalLoaded += $batchResult['loaded'];
                $totalInserted += $batchResult['inserted'];
                $totalUpdated += $batchResult['updated'];
                $totalBatches++;
                
                $this->logger->debug('Product batch loaded', [
                    'batch_number' => $batchIndex + 1,
                    'loaded_count' => $batchResult['loaded'],
                    'inserted_count' => $batchResult['inserted'],
                    'updated_count' => $batchResult['updated'],
                    'total_loaded' => $totalLoaded,
                    'batch_duration' => round($batchResult['duration'], 3)
                ]);
            }
            
            // Verify data integrity
            $verificationResult = $this->verifyLoadedData($data);
            
            // Commit transaction
            $this->db->commit();
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->info('Product loading completed successfully', [
                'total_loaded' => $totalLoaded,
                'total_inserted' => $totalInserted,
                'total_updated' => $totalUpdated,
                'total_batches' => $totalBatches,
                'duration_seconds' => round($duration, 2),
                'products_per_second' => $totalLoaded > 0 ? round($totalLoaded / $duration, 2) : 0,
                'insert_rate' => $totalLoaded > 0 ? round(($totalInserted / $totalLoaded) * 100, 1) : 0,
                'update_rate' => $totalLoaded > 0 ? round(($totalUpdated / $totalLoaded) * 100, 1) : 0,
                'verification_rate' => $verificationResult['verification_rate']
            ]);
            
            // Update metrics
            $this->metrics['records_loaded'] = $totalLoaded;
            $this->metrics['records_inserted'] = $totalInserted;
            $this->metrics['records_updated'] = $totalUpdated;
            $this->metrics['loading_batches'] = $totalBatches;
            $this->metrics['loading_duration'] = $duration;
            $this->metrics['verification_rate'] = $verificationResult['verification_rate'];
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $this->db->rollback();
            
            $this->logger->error('Product loading failed', [
                'total_loaded_before_error' => $totalLoaded,
                'total_inserted_before_error' => $totalInserted,
                'total_updated_before_error' => $totalUpdated,
                'total_batches_processed' => $totalBatches,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception("Product loading failed after {$totalBatches} batches: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate products CSV structure and required headers
     * 
     * Validates that the CSV contains all required headers for product processing
     * including the new visibility field from the products report.
     * 
     * Requirements addressed:
     * - 1.1: Extract visibility field from CSV and validate values
     * - 1.2: Validate product_id, offer_id, name, fbo_sku, fbs_sku fields
     * - 2.1: Ensure visibility field is present for status mapping
     * 
     * @param array $csvData Raw CSV data with headers
     * @throws Exception When CSV structure is invalid
     */
    private function validateProductsCsvStructure(array $csvData): void
    {
        if (empty($csvData)) {
            throw new Exception('Products CSV data is empty');
        }
        
        // Get headers from first row (assuming CSV has headers)
        $firstRow = reset($csvData);
        if (!is_array($firstRow)) {
            throw new Exception('Invalid CSV structure: first row is not an array');
        }
        
        $headers = array_keys($firstRow);
        
        // Required headers for product processing
        $requiredHeaders = [
            'product_id',
            'offer_id',
            'name',
            'visibility'  // New required field from products report
        ];
        
        // Optional headers that we use if present
        $optionalHeaders = [
            'fbo_sku',
            'fbs_sku',
            'status'
        ];
        
        $missingHeaders = [];
        foreach ($requiredHeaders as $requiredHeader) {
            if (!in_array($requiredHeader, $headers)) {
                $missingHeaders[] = $requiredHeader;
            }
        }
        
        if (!empty($missingHeaders)) {
            $this->logger->error('Products CSV missing required headers', [
                'missing_headers' => $missingHeaders,
                'available_headers' => $headers,
                'required_headers' => $requiredHeaders
            ]);
            
            throw new Exception(
                'Products CSV missing required headers: ' . implode(', ', $missingHeaders) .
                '. Available headers: ' . implode(', ', $headers)
            );
        }
        
        $this->logger->info('Products CSV structure validation passed', [
            'total_rows' => count($csvData),
            'available_headers' => $headers,
            'required_headers_found' => $requiredHeaders,
            'optional_headers_found' => array_intersect($optionalHeaders, $headers)
        ]);
    }

    /**
     * Validate product data structure and required fields
     * 
     * Comprehensive validation of product data according to requirements:
     * - 1.2: Validate product_id, offer_id as required fields
     * - 1.4: Ensure offer_id can be used as primary key for data linking
     * - 2.1: Validate visibility field is present and not empty
     * 
     * @param array $product Raw product data
     * @param int $index Product index for error reporting
     * @return array Validation result with 'valid' boolean and 'error' message
     */
    private function validateProductData(array $product, int $index): array
    {
        // Check required fields according to requirements 1.2, 1.4, and 2.1
        $requiredFields = ['product_id', 'offer_id', 'visibility'];
        
        foreach ($requiredFields as $field) {
            if (!isset($product[$field]) || $product[$field] === '' || $product[$field] === null) {
                return [
                    'valid' => false,
                    'error' => "Missing required field '{$field}' at index {$index}"
                ];
            }
        }
        
        // Validate product_id is numeric and positive
        if (!is_numeric($product['product_id']) || (int)$product['product_id'] <= 0) {
            return [
                'valid' => false,
                'error' => "Invalid product_id format at index {$index}: must be positive numeric value"
            ];
        }
        
        // Validate offer_id is not empty string and has reasonable length
        $offerId = trim($product['offer_id']);
        if ($offerId === '') {
            return [
                'valid' => false,
                'error' => "Empty offer_id at index {$index}"
            ];
        }
        
        if (strlen($offerId) > 255) {
            return [
                'valid' => false,
                'error' => "offer_id too long at index {$index}: maximum 255 characters allowed"
            ];
        }
        
        // Validate offer_id contains only valid characters (alphanumeric, dash, underscore)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $offerId)) {
            return [
                'valid' => false,
                'error' => "Invalid offer_id format at index {$index}: only alphanumeric, dash, and underscore allowed"
            ];
        }
        
        // Validate optional fields if present
        if (isset($product['name']) && strlen($product['name']) > 1000) {
            return [
                'valid' => false,
                'error' => "Product name too long at index {$index}: maximum 1000 characters allowed"
            ];
        }
        
        if (isset($product['fbo_sku']) && $product['fbo_sku'] !== null && strlen($product['fbo_sku']) > 255) {
            return [
                'valid' => false,
                'error' => "fbo_sku too long at index {$index}: maximum 255 characters allowed"
            ];
        }
        
        if (isset($product['fbs_sku']) && $product['fbs_sku'] !== null && strlen($product['fbs_sku']) > 255) {
            return [
                'valid' => false,
                'error' => "fbs_sku too long at index {$index}: maximum 255 characters allowed"
            ];
        }
        
        return ['valid' => true, 'error' => null];
    }

    /**
     * Transform single product data to database format
     * 
     * Performs comprehensive data normalization including:
     * - Type casting for numeric fields
     * - String trimming and null handling
     * - Status and visibility normalization
     * - Timestamp generation
     * 
     * Requirements addressed:
     * - 1.2: Normalize product_id, offer_id, name, fbo_sku, fbs_sku, visibility
     * - 2.1: Map Ozon visibility values to standardized internal values
     * 
     * @param array $rawProduct Raw product data from CSV
     * @return array Transformed product data
     */
    private function transformSingleProduct(array $rawProduct): array
    {
        // Normalize product_id to integer
        $productId = (int)$rawProduct['product_id'];
        
        // Normalize offer_id (trim and ensure not empty)
        $offerId = trim($rawProduct['offer_id']);
        
        // Normalize name (trim and handle empty strings as null)
        $name = isset($rawProduct['name']) && trim($rawProduct['name']) !== '' 
            ? trim($rawProduct['name']) 
            : null;
        
        // Normalize SKU fields (trim and handle empty strings as null)
        $fboSku = isset($rawProduct['fbo_sku']) && trim($rawProduct['fbo_sku']) !== '' 
            ? trim($rawProduct['fbo_sku']) 
            : null;
            
        $fbsSku = isset($rawProduct['fbs_sku']) && trim($rawProduct['fbs_sku']) !== '' 
            ? trim($rawProduct['fbs_sku']) 
            : null;
        
        // Normalize status with known values mapping
        $status = $this->normalizeProductStatus($rawProduct['status'] ?? null);
        
        // Normalize visibility status (new field from products report)
        $visibility = $this->normalizeVisibilityStatus($rawProduct['visibility'] ?? null);
        
        return [
            'product_id' => $productId,
            'offer_id' => $offerId,
            'name' => $name,
            'fbo_sku' => $fboSku,
            'fbs_sku' => $fbsSku,
            'status' => $status,
            'visibility' => $visibility,
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Normalize visibility status to standardized internal values
     * 
     * Maps Ozon visibility values to standardized internal values according to
     * the design document requirements. Handles unknown or null visibility values
     * with default mapping.
     * 
     * Requirements addressed:
     * - 1.2: Map Ozon visibility values to standardized internal values
     * - 2.1: Handle unknown or null visibility values with default mapping
     * 
     * @param string|null $rawVisibility Raw visibility status from Ozon CSV
     * @return string Normalized visibility status
     */
    private function normalizeVisibilityStatus(?string $rawVisibility): string
    {
        if ($rawVisibility === null || trim($rawVisibility) === '') {
            return 'UNKNOWN';
        }
        
        $visibility = strtoupper(trim($rawVisibility));
        
        // Map Ozon visibility values to standardized internal values
        $visibilityMap = [
            // Visible/Active states
            'VISIBLE' => 'VISIBLE',
            'ACTIVE' => 'VISIBLE',
            'ПРОДАЁТСЯ' => 'VISIBLE',
            'ПРОДАЕТСЯ' => 'VISIBLE',
            'ON_SALE' => 'VISIBLE',
            
            // Hidden/Inactive states
            'INACTIVE' => 'HIDDEN',
            'ARCHIVED' => 'HIDDEN',
            'СКРЫТ' => 'HIDDEN',
            'СКРЫТО' => 'HIDDEN',
            'HIDDEN' => 'HIDDEN',
            'DISABLED' => 'HIDDEN',
            
            // Moderation states
            'MODERATION' => 'MODERATION',
            'НА МОДЕРАЦИИ' => 'MODERATION',
            'MODERATING' => 'MODERATION',
            'PENDING' => 'MODERATION',
            
            // Declined states
            'DECLINED' => 'DECLINED',
            'ОТКЛОНЁН' => 'DECLINED',
            'ОТКЛОНЕНО' => 'DECLINED',
            'REJECTED' => 'DECLINED'
        ];
        
        $normalizedVisibility = $visibilityMap[$visibility] ?? 'UNKNOWN';
        
        // Log unknown visibility values for monitoring
        if ($normalizedVisibility === 'UNKNOWN' && $visibility !== 'UNKNOWN') {
            $this->logger->warning('Unknown visibility status encountered', [
                'raw_visibility' => $rawVisibility,
                'normalized_visibility' => $normalizedVisibility
            ]);
        }
        
        return $normalizedVisibility;
    }

    /**
     * Normalize product status to standardized values
     * 
     * @param string|null $rawStatus Raw status from API
     * @return string Normalized status
     */
    private function normalizeProductStatus(?string $rawStatus): string
    {
        if ($rawStatus === null || trim($rawStatus) === '') {
            return 'unknown';
        }
        
        $status = strtolower(trim($rawStatus));
        
        // Map known status values to standardized format
        $statusMap = [
            'active' => 'active',
            'inactive' => 'inactive',
            'archived' => 'archived',
            'draft' => 'draft',
            'moderation' => 'moderation',
            'declined' => 'declined',
            'disabled' => 'disabled',
            'processing' => 'processing'
        ];
        
        return $statusMap[$status] ?? 'unknown';
    }

    /**
     * Load a batch of products using upsert logic
     * 
     * Implements comprehensive upsert logic with:
     * - Insert new products
     * - Update existing products based on offer_id
     * - Batch processing for optimal performance
     * - Detailed logging and error handling
     * 
     * Requirements addressed:
     * - 1.3: Update existing products and insert new products in dim_products table
     * - 1.5: Batch processing for performance optimization
     * 
     * @param array $batch Batch of transformed products
     * @return array Result with loaded count and statistics
     * @throws Exception When batch loading fails
     */
    private function loadProductBatch(array $batch): array
    {
        if (empty($batch)) {
            return ['loaded' => 0, 'inserted' => 0, 'updated' => 0];
        }
        
        $startTime = microtime(true);
        
        // Prepare upsert SQL with comprehensive ON CONFLICT handling including visibility
        $sql = "
            INSERT INTO dim_products (
                product_id, offer_id, name, fbo_sku, fbs_sku, status, visibility, created_at, updated_at
            ) VALUES ";
        
        $values = [];
        $params = [];
        
        foreach ($batch as $product) {
            $values[] = "(?, ?, ?, ?, ?, ?, ?, NOW(), ?)";
            $params = array_merge($params, [
                $product['product_id'],
                $product['offer_id'],
                $product['name'],
                $product['fbo_sku'],
                $product['fbs_sku'],
                $product['status'],
                $product['visibility'],
                $product['updated_at']
            ]);
        }
        
        $sql .= implode(', ', $values);
        
        // Add comprehensive upsert logic (PostgreSQL syntax) including visibility field
        $sql .= "
            ON CONFLICT (offer_id) 
            DO UPDATE SET 
                product_id = EXCLUDED.product_id,
                name = CASE 
                    WHEN EXCLUDED.name IS NOT NULL THEN EXCLUDED.name 
                    ELSE dim_products.name 
                END,
                fbo_sku = CASE 
                    WHEN EXCLUDED.fbo_sku IS NOT NULL THEN EXCLUDED.fbo_sku 
                    ELSE dim_products.fbo_sku 
                END,
                fbs_sku = CASE 
                    WHEN EXCLUDED.fbs_sku IS NOT NULL THEN EXCLUDED.fbs_sku 
                    ELSE dim_products.fbs_sku 
                END,
                status = EXCLUDED.status,
                visibility = EXCLUDED.visibility,
                updated_at = EXCLUDED.updated_at
            RETURNING 
                offer_id,
                CASE WHEN xmax = 0 THEN 'inserted' ELSE 'updated' END as operation
        ";
        
        try {
            // Execute the batch insert/update with returning clause
            $result = $this->db->query($sql, $params);
            
            $duration = microtime(true) - $startTime;
            
            // Count operations
            $inserted = 0;
            $updated = 0;
            
            foreach ($result as $row) {
                if ($row['operation'] === 'inserted') {
                    $inserted++;
                } else {
                    $updated++;
                }
            }
            
            $this->logger->debug('Product batch upsert completed', [
                'batch_size' => count($batch),
                'inserted' => $inserted,
                'updated' => $updated,
                'duration_seconds' => round($duration, 3)
            ]);
            
            return [
                'loaded' => count($batch),
                'inserted' => $inserted,
                'updated' => $updated,
                'duration' => $duration
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Product batch upsert failed', [
                'batch_size' => count($batch),
                'error' => $e->getMessage(),
                'sample_offer_ids' => array_slice(array_column($batch, 'offer_id'), 0, 5)
            ]);
            
            throw new Exception("Batch upsert failed for " . count($batch) . " products: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify data integrity after loading
     * 
     * @param array $loadedProducts Products that were loaded
     * @return array Verification results
     */
    private function verifyLoadedData(array $loadedProducts): array
    {
        if (empty($loadedProducts)) {
            return ['verified' => 0, 'missing' => 0];
        }
        
        $offerIds = array_column($loadedProducts, 'offer_id');
        $placeholders = str_repeat('?,', count($offerIds) - 1) . '?';
        
        $sql = "
            SELECT offer_id, product_id, updated_at 
            FROM dim_products 
            WHERE offer_id IN ({$placeholders})
        ";
        
        $result = $this->db->query($sql, $offerIds);
        $foundOfferIds = array_column($result, 'offer_id');
        
        $verified = count($foundOfferIds);
        $missing = count($offerIds) - $verified;
        
        if ($missing > 0) {
            $missingOfferIds = array_diff($offerIds, $foundOfferIds);
            $this->logger->warning('Some products not found after loading', [
                'expected_count' => count($offerIds),
                'found_count' => $verified,
                'missing_count' => $missing,
                'sample_missing' => array_slice($missingOfferIds, 0, 5)
            ]);
        }
        
        return [
            'verified' => $verified,
            'missing' => $missing,
            'verification_rate' => count($offerIds) > 0 ? round(($verified / count($offerIds)) * 100, 2) : 0
        ];
    }

    /**
     * Log progress information for monitoring
     * 
     * @param int $totalProducts Total products processed
     * @param int $totalBatches Total batches processed
     * @param int $batchSize Current batch size
     * @param bool $hasMore Whether more data is available
     */
    private function logProgress(int $totalProducts, int $totalBatches, int $batchSize, bool $hasMore): void
    {
        $this->logger->info('Product extraction progress', [
            'total_products' => $totalProducts,
            'total_batches' => $totalBatches,
            'current_batch_size' => $batchSize,
            'has_more' => $hasMore,
            'estimated_completion' => $hasMore ? 'unknown' : '100%'
        ]);
    }

    /**
     * Validate ProductETL specific configuration
     * 
     * @throws InvalidArgumentException When configuration is invalid
     */
    private function validateProductETLConfig(): void
    {
        if ($this->batchSize <= 0 || $this->batchSize > 1000) {
            throw new InvalidArgumentException('Batch size must be between 1 and 1000');
        }
        
        if ($this->maxProducts < 0) {
            throw new InvalidArgumentException('Max products must be non-negative (0 = no limit)');
        }
    }

    /**
     * Get ProductETL specific statistics
     * 
     * @return array Statistics and configuration
     */
    public function getProductETLStats(): array
    {
        return [
            'config' => [
                'batch_size' => $this->batchSize,
                'max_products' => $this->maxProducts,
                'enable_progress' => $this->enableProgressCallback
            ],
            'metrics' => $this->getMetrics()
        ];
    }
}