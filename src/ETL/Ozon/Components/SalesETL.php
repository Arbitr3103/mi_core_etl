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
 * Sales ETL Component
 * 
 * Handles extraction, transformation, and loading of sales history data
 * from Ozon API to the fact_orders table. Implements date filtering
 * for the last 30 days and incremental loading logic.
 * 
 * Requirements addressed:
 * - 2.1: Extract sales history from Ozon API endpoint `/v2/posting/fbo/list` daily
 * - 2.2: Collect posting_number, in_process_at, and products array for each order
 * - 2.3: Extract sku (offer_id) and quantity for each product in orders
 * - 2.4: Store sales data in fact_orders table linked by offer_id
 * - 2.5: Process sales data for the last 30 days to enable ADS calculations
 */
class SalesETL extends BaseETL
{
    private int $batchSize;
    private int $daysBack;
    private bool $enableProgressCallback;
    private bool $incrementalLoad;

    public function __construct(
        DatabaseConnection $db,
        Logger $logger,
        OzonApiClient $apiClient,
        array $config = []
    ) {
        parent::__construct($db, $logger, $apiClient, $config);
        
        // Set configuration with defaults
        $this->batchSize = $config['batch_size'] ?? 1000;
        $this->daysBack = $config['days_back'] ?? 30;
        $this->enableProgressCallback = $config['enable_progress'] ?? true;
        $this->incrementalLoad = $config['incremental_load'] ?? true;
        
        // Validate configuration
        $this->validateSalesETLConfig();
        
        $this->logger->info('SalesETL initialized', [
            'batch_size' => $this->batchSize,
            'days_back' => $this->daysBack,
            'enable_progress' => $this->enableProgressCallback,
            'incremental_load' => $this->incrementalLoad
        ]);
    }

    /**
     * Extract sales history data from Ozon API with date filtering
     * 
     * Implements automatic pagination to handle large sales volumes
     * and provides progress tracking for long-running operations.
     * Filters data for the last 30 days as per requirements.
     * 
     * Requirements addressed:
     * - 2.1: Extract sales history from Ozon API endpoint `/v2/posting/fbo/list` daily
     * - 2.2: Collect posting_number, in_process_at, and products array for each order
     * 
     * @return array Raw sales data from API
     * @throws Exception When extraction fails
     */
    public function extract(): array
    {
        $this->logger->info('Starting sales extraction from Ozon API', [
            'days_back' => $this->daysBack,
            'batch_size' => $this->batchSize
        ]);
        
        // Calculate date range for the last N days
        $to = date('Y-m-d\TH:i:s\Z');
        $since = date('Y-m-d\TH:i:s\Z', strtotime("-{$this->daysBack} days"));
        
        $this->logger->info('Sales extraction date range', [
            'since' => $since,
            'to' => $to,
            'days_back' => $this->daysBack
        ]);
        
        $allOrders = [];
        $offset = 0;
        $totalBatches = 0;
        $totalOrders = 0;
        $startTime = microtime(true);
        
        try {
            do {
                $this->logger->debug('Requesting sales batch', [
                    'batch_number' => $totalBatches + 1,
                    'batch_size' => $this->batchSize,
                    'offset' => $offset,
                    'total_orders_so_far' => $totalOrders
                ]);
                
                // Get batch of sales from API
                $response = $this->apiClient->getSalesHistory(
                    $since,
                    $to,
                    $this->batchSize,
                    $offset
                );
                
                $batch = $response['result']['postings'] ?? [];
                
                // Add batch to collection
                $allOrders = array_merge($allOrders, $batch);
                $totalBatches++;
                $totalOrders += count($batch);
                $offset += $this->batchSize;
                
                $this->logger->debug('Sales batch extracted', [
                    'batch_number' => $totalBatches,
                    'batch_size' => count($batch),
                    'total_orders' => $totalOrders,
                    'offset' => $offset,
                    'has_more' => count($batch) === $this->batchSize
                ]);
                
                // Progress callback for monitoring
                if ($this->enableProgressCallback) {
                    $this->logProgress($totalOrders, $totalBatches, count($batch), count($batch) === $this->batchSize);
                }
                
                // Small delay between requests to be respectful to API
                if (count($batch) === $this->batchSize) {
                    usleep(100000); // 100ms delay
                }
                
            } while (count($batch) === $this->batchSize);
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->info('Sales extraction completed successfully', [
                'total_orders' => $totalOrders,
                'total_batches' => $totalBatches,
                'duration_seconds' => round($duration, 2),
                'orders_per_second' => $totalOrders > 0 ? round($totalOrders / $duration, 2) : 0,
                'date_range' => "{$since} to {$to}"
            ]);
            
            // Update metrics
            $this->metrics['records_extracted'] = $totalOrders;
            $this->metrics['extraction_batches'] = $totalBatches;
            $this->metrics['extraction_duration'] = $duration;
            $this->metrics['date_range'] = ['since' => $since, 'to' => $to];
            
            return $allOrders;
            
        } catch (Exception $e) {
            $this->logger->error('Sales extraction failed', [
                'total_orders_extracted' => $totalOrders,
                'total_batches' => $totalBatches,
                'offset' => $offset,
                'date_range' => "{$since} to {$to}",
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception("Sales extraction failed after {$totalBatches} batches: " . $e->getMessage(), 0, $e);
        }
    }
   
 /**
     * Transform raw sales data into normalized format
     * 
     * Processes the products array in each order to extract individual
     * product sales records. Validates required fields and normalizes
     * data structure according to the database schema requirements.
     * 
     * Requirements addressed:
     * - 2.3: Extract sku (offer_id) and quantity for each product in orders
     * - 2.4: Store sales data in fact_orders table linked by offer_id
     * 
     * @param array $data Raw sales data from API
     * @return array Transformed sales data ready for loading
     * @throws Exception When transformation fails
     */
    public function transform(array $data): array
    {
        $this->logger->info('Starting sales data transformation', [
            'input_orders_count' => count($data)
        ]);
        
        $transformedOrderItems = [];
        $validOrderItems = 0;
        $skippedOrderItems = 0;
        $errors = [];
        $duplicateKeys = [];
        $seenKeys = [];
        
        foreach ($data as $orderIndex => $rawOrder) {
            try {
                // Validate order structure
                $orderValidation = $this->validateOrderData($rawOrder, $orderIndex);
                
                if (!$orderValidation['valid']) {
                    $skippedOrderItems++;
                    $errors[] = $orderValidation['error'];
                    
                    $this->logger->warning('Skipping invalid order', [
                        'order_index' => $orderIndex,
                        'posting_number' => $rawOrder['posting_number'] ?? 'unknown',
                        'error' => $orderValidation['error']
                    ]);
                    continue;
                }
                
                // Process each product in the order
                $products = $rawOrder['products'] ?? [];
                
                foreach ($products as $productIndex => $product) {
                    try {
                        // Validate product data
                        $productValidation = $this->validateProductInOrder($product, $orderIndex, $productIndex);
                        
                        if (!$productValidation['valid']) {
                            $skippedOrderItems++;
                            $errors[] = $productValidation['error'];
                            
                            $this->logger->warning('Skipping invalid product in order', [
                                'order_index' => $orderIndex,
                                'product_index' => $productIndex,
                                'posting_number' => $rawOrder['posting_number'],
                                'sku' => $product['sku'] ?? 'unknown',
                                'error' => $productValidation['error']
                            ]);
                            continue;
                        }
                        
                        // Create unique key for duplicate detection
                        $uniqueKey = $rawOrder['posting_number'] . '|' . $product['sku'];
                        
                        if (isset($seenKeys[$uniqueKey])) {
                            $duplicateKeys[] = $uniqueKey;
                            $this->logger->warning('Duplicate order item detected in batch', [
                                'posting_number' => $rawOrder['posting_number'],
                                'sku' => $product['sku'],
                                'first_occurrence' => $seenKeys[$uniqueKey],
                                'current_occurrence' => "{$orderIndex}:{$productIndex}"
                            ]);
                            // Keep the latest occurrence
                        }
                        $seenKeys[$uniqueKey] = "{$orderIndex}:{$productIndex}";
                        
                        // Transform single order item
                        $transformedItem = $this->transformSingleOrderItem($rawOrder, $product);
                        $transformedOrderItems[] = $transformedItem;
                        $validOrderItems++;
                        
                    } catch (Exception $e) {
                        $skippedOrderItems++;
                        $errorMessage = "Product transformation error at order {$orderIndex}, product {$productIndex}: " . $e->getMessage();
                        $errors[] = $errorMessage;
                        
                        $this->logger->warning('Product transformation error', [
                            'order_index' => $orderIndex,
                            'product_index' => $productIndex,
                            'posting_number' => $rawOrder['posting_number'] ?? 'unknown',
                            'sku' => $product['sku'] ?? 'unknown',
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
            } catch (Exception $e) {
                $skippedOrderItems++;
                $errorMessage = "Order transformation error at index {$orderIndex}: " . $e->getMessage();
                $errors[] = $errorMessage;
                
                $this->logger->warning('Order transformation error', [
                    'order_index' => $orderIndex,
                    'posting_number' => $rawOrder['posting_number'] ?? 'unknown',
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->logger->info('Sales transformation completed', [
            'input_orders_count' => count($data),
            'valid_order_items' => $validOrderItems,
            'skipped_order_items' => $skippedOrderItems,
            'error_count' => count($errors),
            'duplicate_keys' => count($duplicateKeys),
            'unique_keys' => count($seenKeys)
        ]);
        
        // Log errors if any
        if (!empty($errors)) {
            $this->logger->warning('Transformation errors summary', [
                'total_errors' => count($errors),
                'sample_errors' => array_slice($errors, 0, 5) // Log first 5 errors
            ]);
        }
        
        // Log duplicate keys if any
        if (!empty($duplicateKeys)) {
            $this->logger->warning('Duplicate order items found in batch', [
                'duplicate_count' => count($duplicateKeys),
                'sample_duplicates' => array_slice(array_unique($duplicateKeys), 0, 5)
            ]);
        }
        
        // Update metrics
        $this->metrics['records_transformed'] = $validOrderItems;
        $this->metrics['transformation_errors'] = count($errors);
        $this->metrics['skipped_records'] = $skippedOrderItems;
        $this->metrics['duplicate_keys'] = count($duplicateKeys);
        
        return $transformedOrderItems;
    }

    /**
     * Validate order data structure and required fields
     * 
     * @param array $order Raw order data
     * @param int $index Order index for error reporting
     * @return array Validation result with 'valid' boolean and 'error' message
     */
    protected function validateOrderData(array $order, int $index): array
    {
        // Check required fields according to requirements 2.1 and 2.2
        $requiredFields = ['posting_number', 'products'];
        
        foreach ($requiredFields as $field) {
            if (!isset($order[$field]) || $order[$field] === '' || $order[$field] === null) {
                return [
                    'valid' => false,
                    'error' => "Missing required field '{$field}' in order at index {$index}"
                ];
            }
        }
        
        // Validate posting_number is not empty string
        $postingNumber = trim($order['posting_number']);
        if ($postingNumber === '') {
            return [
                'valid' => false,
                'error' => "Empty posting_number in order at index {$index}"
            ];
        }
        
        // Validate posting_number length
        if (strlen($postingNumber) > 255) {
            return [
                'valid' => false,
                'error' => "posting_number too long at index {$index}: maximum 255 characters allowed"
            ];
        }
        
        // Validate products is an array
        if (!is_array($order['products'])) {
            return [
                'valid' => false,
                'error' => "Products field must be an array in order at index {$index}"
            ];
        }
        
        // Validate products array is not empty
        if (empty($order['products'])) {
            return [
                'valid' => false,
                'error' => "Products array is empty in order at index {$index}"
            ];
        }
        
        // Validate in_process_at if present
        if (isset($order['in_process_at']) && $order['in_process_at'] !== null) {
            $timestamp = strtotime($order['in_process_at']);
            if ($timestamp === false) {
                return [
                    'valid' => false,
                    'error' => "Invalid in_process_at date format in order at index {$index}"
                ];
            }
        }
        
        return ['valid' => true, 'error' => null];
    }

    /**
     * Validate product data within an order
     * 
     * Requirements addressed:
     * - 2.3: Validate sku (offer_id) and quantity for each product
     * 
     * @param array $product Product data within order
     * @param int $orderIndex Order index for error reporting
     * @param int $productIndex Product index for error reporting
     * @return array Validation result
     */
    protected function validateProductInOrder(array $product, int $orderIndex, int $productIndex): array
    {
        // Check required fields according to requirement 2.3
        $requiredFields = ['sku', 'quantity'];
        
        foreach ($requiredFields as $field) {
            if (!isset($product[$field]) || $product[$field] === '' || $product[$field] === null) {
                return [
                    'valid' => false,
                    'error' => "Missing required field '{$field}' in product at order {$orderIndex}, product {$productIndex}"
                ];
            }
        }
        
        // Validate sku (offer_id) is not empty string
        $sku = trim($product['sku']);
        if ($sku === '') {
            return [
                'valid' => false,
                'error' => "Empty sku in product at order {$orderIndex}, product {$productIndex}"
            ];
        }
        
        // Validate sku length
        if (strlen($sku) > 255) {
            return [
                'valid' => false,
                'error' => "sku too long at order {$orderIndex}, product {$productIndex}: maximum 255 characters allowed"
            ];
        }
        
        // Validate quantity is numeric and positive
        if (!is_numeric($product['quantity']) || (int)$product['quantity'] <= 0) {
            return [
                'valid' => false,
                'error' => "Invalid quantity in product at order {$orderIndex}, product {$productIndex}: must be positive numeric value"
            ];
        }
        
        // Validate price if present
        if (isset($product['price']) && $product['price'] !== null) {
            if (!is_numeric($product['price']) || (float)$product['price'] < 0) {
                return [
                    'valid' => false,
                    'error' => "Invalid price in product at order {$orderIndex}, product {$productIndex}: must be non-negative numeric value"
                ];
            }
        }
        
        return ['valid' => true, 'error' => null];
    }

    /**
     * Transform single order item to database format
     * 
     * Performs comprehensive data normalization including:
     * - Type casting for numeric fields
     * - String trimming and null handling
     * - Date parsing and formatting
     * - Price normalization
     * 
     * Requirements addressed:
     * - 2.3: Extract sku (offer_id) and quantity for each product
     * - 2.4: Prepare data for fact_orders table
     * 
     * @param array $rawOrder Raw order data from API
     * @param array $product Product data within order
     * @return array Transformed order item data
     */
    protected function transformSingleOrderItem(array $rawOrder, array $product): array
    {
        // Normalize posting_number
        $postingNumber = trim($rawOrder['posting_number']);
        
        // Normalize offer_id (sku)
        $offerId = trim($product['sku']);
        
        // Normalize quantity to integer
        $quantity = (int)$product['quantity'];
        
        // Normalize price (handle null and convert to decimal)
        $price = isset($product['price']) && $product['price'] !== null 
            ? (float)$product['price'] 
            : null;
        
        // Normalize warehouse_id
        $warehouseId = isset($rawOrder['warehouse_id']) && trim($rawOrder['warehouse_id']) !== '' 
            ? trim($rawOrder['warehouse_id']) 
            : null;
        
        // Parse and normalize in_process_at date
        $inProcessAt = $this->normalizeOrderDate($rawOrder['in_process_at'] ?? null);
        
        return [
            'posting_number' => $postingNumber,
            'offer_id' => $offerId,
            'quantity' => $quantity,
            'price' => $price,
            'warehouse_id' => $warehouseId,
            'in_process_at' => $inProcessAt,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Normalize order date to standardized format
     * 
     * @param string|null $rawDate Raw date from API
     * @return string|null Normalized date in Y-m-d H:i:s format
     */
    protected function normalizeOrderDate(?string $rawDate): ?string
    {
        if ($rawDate === null || trim($rawDate) === '') {
            return null;
        }
        
        $timestamp = strtotime($rawDate);
        if ($timestamp === false) {
            // If date parsing fails, return null and log warning
            $this->logger->warning('Failed to parse order date', [
                'raw_date' => $rawDate
            ]);
            return null;
        }
        
        return date('Y-m-d H:i:s', $timestamp);
    }    /*
*
     * Load transformed sales data into database with incremental logic
     * 
     * Implements comprehensive incremental loading with:
     * - Duplicate detection by posting_number + offer_id
     * - Transaction management for data consistency
     * - Batch processing for optimal performance
     * - Detailed statistics tracking (inserts vs skips)
     * - Data integrity verification
     * 
     * Requirements addressed:
     * - 2.4: Store sales data in fact_orders table linked by offer_id
     * - 2.5: Incremental loading logic for only new orders
     * 
     * @param array $data Transformed sales data
     * @return void
     * @throws Exception When loading fails
     */
    public function load(array $data): void
    {
        $this->logger->info('Starting sales data loading', [
            'order_items_count' => count($data),
            'incremental_load' => $this->incrementalLoad
        ]);
        
        if (empty($data)) {
            $this->logger->warning('No sales data to load');
            return;
        }
        
        $totalLoaded = 0;
        $totalInserted = 0;
        $totalSkipped = 0;
        $totalBatches = 0;
        $startTime = microtime(true);
        
        // Begin transaction for data consistency
        $this->db->beginTransaction();
        
        try {
            if ($this->incrementalLoad) {
                // Get existing order items to avoid duplicates
                $existingKeys = $this->getExistingOrderKeys($data);
                $this->logger->info('Found existing order items', [
                    'existing_count' => count($existingKeys),
                    'total_items' => count($data)
                ]);
            } else {
                $existingKeys = [];
                $this->logger->info('Full load mode - no duplicate checking');
            }
            
            // Filter out existing items if incremental load is enabled
            $newData = $this->incrementalLoad ? $this->filterNewOrderItems($data, $existingKeys) : $data;
            
            $this->logger->info('Filtered data for loading', [
                'original_count' => count($data),
                'new_items_count' => count($newData),
                'skipped_existing' => count($data) - count($newData)
            ]);
            
            if (empty($newData)) {
                $this->logger->info('No new sales data to load - all items already exist');
                $this->db->commit();
                return;
            }
            
            // Process data in batches for optimal performance
            $batches = array_chunk($newData, $this->batchSize);
            
            $this->logger->info('Processing sales data in batches', [
                'total_batches' => count($batches),
                'batch_size' => $this->batchSize,
                'new_items' => count($newData)
            ]);
            
            foreach ($batches as $batchIndex => $batch) {
                $this->logger->debug('Processing sales batch for loading', [
                    'batch_number' => $batchIndex + 1,
                    'batch_size' => count($batch),
                    'total_batches' => count($batches),
                    'progress_percent' => round((($batchIndex + 1) / count($batches)) * 100, 1)
                ]);
                
                $batchResult = $this->loadSalesBatch($batch);
                $totalLoaded += $batchResult['loaded'];
                $totalInserted += $batchResult['inserted'];
                $totalSkipped += $batchResult['skipped'];
                $totalBatches++;
                
                $this->logger->debug('Sales batch loaded', [
                    'batch_number' => $batchIndex + 1,
                    'loaded_count' => $batchResult['loaded'],
                    'inserted_count' => $batchResult['inserted'],
                    'skipped_count' => $batchResult['skipped'],
                    'total_loaded' => $totalLoaded,
                    'batch_duration' => round($batchResult['duration'], 3)
                ]);
            }
            
            // Verify data integrity
            $verificationResult = $this->verifyLoadedSalesData($newData);
            
            // Commit transaction
            $this->db->commit();
            
            $duration = microtime(true) - $startTime;
            
            $this->logger->info('Sales loading completed successfully', [
                'total_processed' => count($data),
                'total_loaded' => $totalLoaded,
                'total_inserted' => $totalInserted,
                'total_skipped' => $totalSkipped,
                'existing_skipped' => count($data) - count($newData),
                'total_batches' => $totalBatches,
                'duration_seconds' => round($duration, 2),
                'items_per_second' => $totalLoaded > 0 ? round($totalLoaded / $duration, 2) : 0,
                'verification_rate' => $verificationResult['verification_rate']
            ]);
            
            // Update metrics
            $this->metrics['records_loaded'] = $totalLoaded;
            $this->metrics['records_inserted'] = $totalInserted;
            $this->metrics['records_skipped'] = $totalSkipped;
            $this->metrics['existing_records_skipped'] = count($data) - count($newData);
            $this->metrics['loading_batches'] = $totalBatches;
            $this->metrics['loading_duration'] = $duration;
            $this->metrics['verification_rate'] = $verificationResult['verification_rate'];
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $this->db->rollback();
            
            $this->logger->error('Sales loading failed', [
                'total_loaded_before_error' => $totalLoaded,
                'total_inserted_before_error' => $totalInserted,
                'total_skipped_before_error' => $totalSkipped,
                'total_batches_processed' => $totalBatches,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new Exception("Sales loading failed after {$totalBatches} batches: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Get existing order keys from database for duplicate detection
     * 
     * @param array $data Order items to check
     * @return array Set of existing keys (posting_number|offer_id)
     */
    private function getExistingOrderKeys(array $data): array
    {
        if (empty($data)) {
            return [];
        }
        
        // Extract unique combinations of posting_number and offer_id
        $keysToCheck = [];
        foreach ($data as $item) {
            $key = $item['posting_number'] . '|' . $item['offer_id'];
            $keysToCheck[$key] = [
                'posting_number' => $item['posting_number'],
                'offer_id' => $item['offer_id']
            ];
        }
        
        if (empty($keysToCheck)) {
            return [];
        }
        
        // Build query to check existing records
        $conditions = [];
        $params = [];
        
        foreach ($keysToCheck as $keyData) {
            $conditions[] = "(posting_number = ? AND offer_id = ?)";
            $params[] = $keyData['posting_number'];
            $params[] = $keyData['offer_id'];
        }
        
        $sql = "
            SELECT DISTINCT posting_number, offer_id 
            FROM fact_orders 
            WHERE " . implode(' OR ', $conditions);
        
        try {
            $result = $this->db->query($sql, $params);
            
            // Convert to set of keys for fast lookup
            $existingKeys = [];
            foreach ($result as $row) {
                $key = $row['posting_number'] . '|' . $row['offer_id'];
                $existingKeys[$key] = true;
            }
            
            $this->logger->debug('Checked for existing order items', [
                'keys_checked' => count($keysToCheck),
                'existing_found' => count($existingKeys)
            ]);
            
            return $existingKeys;
            
        } catch (Exception $e) {
            $this->logger->error('Failed to check existing order keys', [
                'keys_count' => count($keysToCheck),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Filter out existing order items for incremental loading
     * 
     * @param array $data All order items
     * @param array $existingKeys Set of existing keys
     * @return array Filtered new order items
     */
    private function filterNewOrderItems(array $data, array $existingKeys): array
    {
        if (empty($existingKeys)) {
            return $data;
        }
        
        $newItems = [];
        $skippedCount = 0;
        
        foreach ($data as $item) {
            $key = $item['posting_number'] . '|' . $item['offer_id'];
            
            if (!isset($existingKeys[$key])) {
                $newItems[] = $item;
            } else {
                $skippedCount++;
            }
        }
        
        $this->logger->debug('Filtered order items', [
            'original_count' => count($data),
            'new_items' => count($newItems),
            'skipped_existing' => $skippedCount
        ]);
        
        return $newItems;
    }

    /**
     * Load a batch of sales data using insert logic
     * 
     * Requirements addressed:
     * - 2.4: Insert sales data into fact_orders table
     * - 2.5: Handle duplicate detection by posting_number + offer_id
     * 
     * @param array $batch Batch of transformed order items
     * @return array Result with loaded count and statistics
     * @throws Exception When batch loading fails
     */
    private function loadSalesBatch(array $batch): array
    {
        if (empty($batch)) {
            return ['loaded' => 0, 'inserted' => 0, 'skipped' => 0, 'duration' => 0];
        }
        
        $startTime = microtime(true);
        
        // Prepare insert SQL with ON CONFLICT handling for duplicate prevention
        $sql = "
            INSERT INTO fact_orders (
                posting_number, offer_id, quantity, price, warehouse_id, in_process_at, created_at
            ) VALUES ";
        
        $values = [];
        $params = [];
        
        foreach ($batch as $item) {
            $values[] = "(?, ?, ?, ?, ?, ?, ?)";
            $params = array_merge($params, [
                $item['posting_number'],
                $item['offer_id'],
                $item['quantity'],
                $item['price'],
                $item['warehouse_id'],
                $item['in_process_at'],
                $item['created_at']
            ]);
        }
        
        $sql .= implode(', ', $values);
        
        // Add conflict handling to prevent duplicates
        $sql .= "
            ON CONFLICT (posting_number, offer_id) 
            DO NOTHING
            RETURNING posting_number, offer_id
        ";
        
        try {
            // Execute the batch insert
            $result = $this->db->query($sql, $params);
            
            $duration = microtime(true) - $startTime;
            
            // Count actual inserts (returned rows)
            $inserted = count($result);
            $skipped = count($batch) - $inserted;
            
            $this->logger->debug('Sales batch insert completed', [
                'batch_size' => count($batch),
                'inserted' => $inserted,
                'skipped' => $skipped,
                'duration_seconds' => round($duration, 3)
            ]);
            
            return [
                'loaded' => count($batch),
                'inserted' => $inserted,
                'skipped' => $skipped,
                'duration' => $duration
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Sales batch insert failed', [
                'batch_size' => count($batch),
                'error' => $e->getMessage(),
                'sample_posting_numbers' => array_slice(array_column($batch, 'posting_number'), 0, 5)
            ]);
            
            throw new Exception("Batch insert failed for " . count($batch) . " order items: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verify data integrity after loading
     * 
     * @param array $loadedItems Order items that were loaded
     * @return array Verification results
     */
    private function verifyLoadedSalesData(array $loadedItems): array
    {
        if (empty($loadedItems)) {
            return ['verified' => 0, 'missing' => 0, 'verification_rate' => 100];
        }
        
        // Create unique keys for verification
        $keysToVerify = [];
        foreach ($loadedItems as $item) {
            $key = $item['posting_number'] . '|' . $item['offer_id'];
            $keysToVerify[$key] = [
                'posting_number' => $item['posting_number'],
                'offer_id' => $item['offer_id']
            ];
        }
        
        // Build verification query
        $conditions = [];
        $params = [];
        
        foreach ($keysToVerify as $keyData) {
            $conditions[] = "(posting_number = ? AND offer_id = ?)";
            $params[] = $keyData['posting_number'];
            $params[] = $keyData['offer_id'];
        }
        
        $sql = "
            SELECT posting_number, offer_id, created_at 
            FROM fact_orders 
            WHERE " . implode(' OR ', $conditions);
        
        try {
            $result = $this->db->query($sql, $params);
            
            $foundKeys = [];
            foreach ($result as $row) {
                $key = $row['posting_number'] . '|' . $row['offer_id'];
                $foundKeys[$key] = true;
            }
            
            $verified = count($foundKeys);
            $missing = count($keysToVerify) - $verified;
            
            if ($missing > 0) {
                $missingKeys = array_diff(array_keys($keysToVerify), array_keys($foundKeys));
                $this->logger->warning('Some order items not found after loading', [
                    'expected_count' => count($keysToVerify),
                    'found_count' => $verified,
                    'missing_count' => $missing,
                    'sample_missing' => array_slice($missingKeys, 0, 5)
                ]);
            }
            
            return [
                'verified' => $verified,
                'missing' => $missing,
                'verification_rate' => count($keysToVerify) > 0 ? round(($verified / count($keysToVerify)) * 100, 2) : 0
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Sales data verification failed', [
                'items_to_verify' => count($keysToVerify),
                'error' => $e->getMessage()
            ]);
            
            return [
                'verified' => 0,
                'missing' => count($keysToVerify),
                'verification_rate' => 0
            ];
        }
    }

    /**
     * Log progress information for monitoring
     * 
     * @param int $totalOrders Total orders processed
     * @param int $totalBatches Total batches processed
     * @param int $batchSize Current batch size
     * @param bool $hasMore Whether more data is available
     */
    private function logProgress(int $totalOrders, int $totalBatches, int $batchSize, bool $hasMore): void
    {
        $this->logger->info('Sales extraction progress', [
            'total_orders' => $totalOrders,
            'total_batches' => $totalBatches,
            'current_batch_size' => $batchSize,
            'has_more' => $hasMore,
            'estimated_completion' => $hasMore ? 'unknown' : '100%'
        ]);
    }

    /**
     * Validate SalesETL specific configuration
     * 
     * @throws InvalidArgumentException When configuration is invalid
     */
    private function validateSalesETLConfig(): void
    {
        if ($this->batchSize <= 0 || $this->batchSize > 1000) {
            throw new InvalidArgumentException('Batch size must be between 1 and 1000');
        }
        
        if ($this->daysBack <= 0 || $this->daysBack > 365) {
            throw new InvalidArgumentException('Days back must be between 1 and 365');
        }
    }

    /**
     * Get SalesETL specific statistics
     * 
     * @return array Statistics and configuration
     */
    public function getSalesETLStats(): array
    {
        return [
            'config' => [
                'batch_size' => $this->batchSize,
                'days_back' => $this->daysBack,
                'enable_progress' => $this->enableProgressCallback,
                'incremental_load' => $this->incrementalLoad
            ],
            'metrics' => $this->getMetrics()
        ];
    }
}