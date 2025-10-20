<?php
/**
 * Ozon API Integration Service
 * 
 * Handles integration with Ozon Seller API for fetching regional sales data.
 * Implements authentication, request handling, and data synchronization.
 */

require_once __DIR__ . '/config.php';

class OzonAPIService {
    
    private $clientId;
    private $apiKey;
    private $baseUrl;
    private $timeout;
    private $maxRetries;
    private $retryDelay;
    private $pdo;
    
    /**
     * Constructor
     * 
     * @param string|null $clientId Ozon Client-Id (optional, uses secure storage if not provided)
     * @param string|null $apiKey Ozon Api-Key (optional, uses secure storage if not provided)
     */
    public function __construct($clientId = null, $apiKey = null) {
        // Try to get credentials from secure storage first, then fallback to environment
        if (!$clientId || !$apiKey) {
            $secureCredentials = $this->getSecureCredentials();
            $this->clientId = $clientId ?: ($secureCredentials['client_id'] ?: OZON_CLIENT_ID);
            $this->apiKey = $apiKey ?: ($secureCredentials['api_key'] ?: OZON_API_KEY);
        } else {
            $this->clientId = $clientId;
            $this->apiKey = $apiKey;
        }
        
        $this->baseUrl = OZON_API_BASE_URL;
        $this->timeout = OZON_API_TIMEOUT;
        $this->maxRetries = OZON_API_MAX_RETRIES;
        $this->retryDelay = OZON_API_RETRY_DELAY;
        
        // Validate credentials
        if (empty($this->clientId) || empty($this->apiKey)) {
            throw new Exception('Ozon API credentials not configured. Please configure credentials using the credential manager or set environment variables.');
        }
        
        // Initialize database connection
        $this->pdo = getAnalyticsDbConnection();
        
        logAnalyticsActivity('INFO', 'OzonAPIService initialized', [
            'client_id_length' => strlen($this->clientId),
            'api_key_length' => strlen($this->apiKey),
            'credentials_source' => $this->getCredentialsSource()
        ]);
    }
    
    /**
     * Get secure credentials from CredentialManager
     * @return array Credentials array
     */
    private function getSecureCredentials() {
        try {
            require_once __DIR__ . '/CredentialManager.php';
            return getSecureOzonCredentials();
        } catch (Exception $e) {
            logAnalyticsActivity('WARNING', 'Failed to load secure credentials, falling back to environment: ' . $e->getMessage());
            return ['client_id' => null, 'api_key' => null];
        }
    }
    
    /**
     * Determine the source of credentials for logging
     * @return string Credentials source
     */
    private function getCredentialsSource() {
        try {
            require_once __DIR__ . '/CredentialManager.php';
            $credentialManager = new CredentialManager();
            
            $hasSecureClientId = $credentialManager->getCredential('ozon', 'client_id') !== null;
            $hasSecureApiKey = $credentialManager->getCredential('ozon', 'api_key') !== null;
            
            if ($hasSecureClientId && $hasSecureApiKey) {
                return 'secure_storage';
            } elseif ($hasSecureClientId || $hasSecureApiKey) {
                return 'mixed_storage';
            } else {
                return 'environment_variables';
            }
        } catch (Exception $e) {
            return 'environment_variables';
        }
    }
    
    /**
     * Fetch analytics data from Ozon API
     * 
     * @param string $dateFrom Start date (YYYY-MM-DD)
     * @param string $dateTo End date (YYYY-MM-DD)
     * @param array $filters Additional filters
     * @return array API response data
     * @throws Exception On API errors
     */
    public function fetchAnalyticsData($dateFrom, $dateTo, $filters = []) {
        logAnalyticsActivity('INFO', 'Fetching analytics data from Ozon API', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'filters' => $filters
        ]);
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            throw new Exception('Invalid date format. Use YYYY-MM-DD.');
        }
        
        // Prepare request data
        $requestData = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'metrics' => [
                'revenue',
                'ordered_units',
                'hits_view_search',
                'hits_view_pdp',
                'conversion'
            ],
            'dimension' => ['sku'],
            'sort' => [
                [
                    'key' => 'revenue',
                    'order' => 'DESC'
                ]
            ]
        ];
        
        // Add filters if provided
        if (!empty($filters)) {
            $requestData['filters'] = $filters;
        }
        
        // Make API request with retry logic
        $response = $this->makeApiRequest('/v1/analytics/data', 'POST', $requestData);
        
        logAnalyticsActivity('INFO', 'Successfully fetched analytics data from Ozon API', [
            'records_count' => count($response['result']['data'] ?? [])
        ]);
        
        return $response;
    }
    
    /**
     * Get regional sales data from Ozon API
     * 
     * @param string $dateFrom Start date (YYYY-MM-DD)
     * @param string $dateTo End date (YYYY-MM-DD)
     * @param string $brand Brand filter (default: 'ЭТОНОВО')
     * @param array $additionalFilters Additional API filters
     * @return array Regional sales data
     * @throws Exception On API errors
     */
    public function getRegionalSalesData($dateFrom, $dateTo, $brand = 'ЭТОНОВО', $additionalFilters = []) {
        logAnalyticsActivity('INFO', 'Fetching regional sales data from Ozon API', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'brand' => $brand,
            'additional_filters' => count($additionalFilters)
        ]);
        
        // Validate date range
        $this->validateDateRange($dateFrom, $dateTo);
        
        // Prepare base filters
        $filters = [
            [
                'key' => 'brand',
                'op' => 'EQ',
                'value' => $brand
            ]
        ];
        
        // Add additional filters if provided
        if (!empty($additionalFilters)) {
            $filters = array_merge($filters, $additionalFilters);
        }
        
        // Prepare request data for regional analytics
        $requestData = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'metrics' => [
                'revenue',
                'ordered_units',
                'hits_view_search',
                'hits_view_pdp',
                'conversion'
            ],
            'dimension' => ['sku', 'region'],
            'filters' => $filters,
            'sort' => [
                [
                    'key' => 'revenue',
                    'order' => 'DESC'
                ]
            ],
            'limit' => 10000 // Maximum records per request
        ];
        
        // Make API request
        $response = $this->makeApiRequest('/v1/analytics/data', 'POST', $requestData);
        
        // Validate API response structure
        $this->validateApiResponse($response);
        
        // Process and validate response data
        $processedData = $this->processRegionalData($response, $dateFrom, $dateTo);
        
        // Validate processed data
        $validationResults = $this->validateProcessedData($processedData);
        
        logAnalyticsActivity('INFO', 'Successfully processed regional sales data', [
            'regions_count' => count(array_unique(array_column($processedData, 'region_id'))),
            'products_count' => count(array_unique(array_column($processedData, 'product_id'))),
            'total_records' => count($processedData),
            'total_revenue' => array_sum(array_column($processedData, 'sales_amount')),
            'validation_warnings' => count($validationResults['warnings']),
            'validation_errors' => count($validationResults['errors'])
        ]);
        
        // Log validation issues if any
        if (!empty($validationResults['warnings'])) {
            logAnalyticsActivity('WARNING', 'Data validation warnings', $validationResults['warnings']);
        }
        
        if (!empty($validationResults['errors'])) {
            logAnalyticsActivity('ERROR', 'Data validation errors', $validationResults['errors']);
        }
        
        return $processedData;
    }
    
    /**
     * Synchronize regional data with local database
     * 
     * @param string $dateFrom Start date (YYYY-MM-DD)
     * @param string $dateTo End date (YYYY-MM-DD)
     * @return array Sync results
     * @throws Exception On sync errors
     */
    public function syncRegionalData($dateFrom = null, $dateTo = null) {
        // Set default dates if not provided (yesterday)
        if (!$dateFrom) {
            $dateFrom = date('Y-m-d', strtotime('-1 day'));
        }
        if (!$dateTo) {
            $dateTo = $dateFrom;
        }
        
        logAnalyticsActivity('INFO', 'Starting regional data synchronization', [
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
        
        $syncResults = [
            'success' => false,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'records_processed' => 0,
            'records_inserted' => 0,
            'records_updated' => 0,
            'errors' => []
        ];
        
        try {
            // Start transaction
            $this->pdo->beginTransaction();
            
            // Fetch regional data from Ozon API
            $regionalData = $this->getRegionalSalesData($dateFrom, $dateTo);
            
            if (empty($regionalData)) {
                logAnalyticsActivity('WARNING', 'No regional data received from Ozon API');
                $this->pdo->rollback();
                return $syncResults;
            }
            
            // Process data in batches
            $batchSize = OZON_SYNC_BATCH_SIZE;
            $batches = array_chunk($regionalData, $batchSize);
            
            foreach ($batches as $batch) {
                $batchResults = $this->processSyncBatch($batch, $dateFrom, $dateTo);
                $syncResults['records_processed'] += $batchResults['processed'];
                $syncResults['records_inserted'] += $batchResults['inserted'];
                $syncResults['records_updated'] += $batchResults['updated'];
                
                if (!empty($batchResults['errors'])) {
                    $syncResults['errors'] = array_merge($syncResults['errors'], $batchResults['errors']);
                }
            }
            
            // Commit transaction
            $this->pdo->commit();
            $syncResults['success'] = true;
            
            logAnalyticsActivity('INFO', 'Regional data synchronization completed successfully', $syncResults);
            
        } catch (Exception $e) {
            $this->pdo->rollback();
            $syncResults['errors'][] = $e->getMessage();
            
            logAnalyticsActivity('ERROR', 'Regional data synchronization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
        
        return $syncResults;
    }
    
    /**
     * Make HTTP request to Ozon API with retry logic
     * 
     * @param string $endpoint API endpoint
     * @param string $method HTTP method (GET, POST)
     * @param array $data Request data
     * @return array API response
     * @throws Exception On request failures
     */
    private function makeApiRequest($endpoint, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $endpoint;
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $this->maxRetries) {
            $attempt++;
            
            try {
                logAnalyticsActivity('INFO', "Making Ozon API request (attempt $attempt)", [
                    'url' => $url,
                    'method' => $method
                ]);
                
                // Initialize cURL
                $ch = curl_init();
                
                // Set basic cURL options
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $this->timeout,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_USERAGENT => 'Regional Analytics System/1.0'
                ]);
                
                // Set headers
                $headers = [
                    'Client-Id: ' . $this->clientId,
                    'Api-Key: ' . $this->apiKey,
                    'Content-Type: application/json'
                ];
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                
                // Set method and data
                if ($method === 'POST' && $data) {
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
                
                // Execute request
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                // Check for cURL errors
                if ($curlError) {
                    throw new Exception("cURL error: $curlError");
                }
                
                // Check HTTP status code
                if ($httpCode >= 400) {
                    $errorMessage = "HTTP error $httpCode";
                    if ($response) {
                        $errorData = json_decode($response, true);
                        if (isset($errorData['message'])) {
                            $errorMessage .= ": " . $errorData['message'];
                        }
                    }
                    throw new Exception($errorMessage);
                }
                
                // Parse JSON response
                $responseData = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON response: ' . json_last_error_msg());
                }
                
                // Check for API errors
                if (isset($responseData['error'])) {
                    throw new Exception('API error: ' . $responseData['error']);
                }
                
                logAnalyticsActivity('INFO', 'Ozon API request successful', [
                    'http_code' => $httpCode,
                    'response_size' => strlen($response)
                ]);
                
                return $responseData;
                
            } catch (Exception $e) {
                $lastError = $e;
                
                logAnalyticsActivity('WARNING', "Ozon API request failed (attempt $attempt)", [
                    'error' => $e->getMessage(),
                    'url' => $url
                ]);
                
                // If this is not the last attempt, wait before retrying
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay);
                }
            }
        }
        
        // All attempts failed
        logAnalyticsActivity('ERROR', 'All Ozon API request attempts failed', [
            'attempts' => $attempt,
            'last_error' => $lastError->getMessage()
        ]);
        
        throw new Exception("Ozon API request failed after $attempt attempts: " . $lastError->getMessage());
    }
    
    /**
     * Process regional data from API response
     * 
     * @param array $apiResponse Raw API response
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @return array Processed regional data
     */
    private function processRegionalData($apiResponse, $dateFrom, $dateTo) {
        $processedData = [];
        $skippedRecords = 0;
        $unmappedSkus = [];
        
        if (!isset($apiResponse['result']['data']) || !is_array($apiResponse['result']['data'])) {
            logAnalyticsActivity('WARNING', 'No data found in Ozon API response');
            return $processedData;
        }
        
        foreach ($apiResponse['result']['data'] as $index => $item) {
            try {
                // Extract dimensions
                $dimensions = $item['dimensions'] ?? [];
                $metrics = $item['metrics'] ?? [];
                
                // Validate dimensions structure
                if (count($dimensions) < 2) {
                    $skippedRecords++;
                    continue;
                }
                
                $sku = $dimensions[0]['id'] ?? null;
                $regionData = $dimensions[1] ?? null;
                
                if (!$sku || !$regionData) {
                    $skippedRecords++;
                    continue;
                }
                
                // Map Ozon SKU to internal product ID with enhanced mapping
                $productMapping = $this->mapSkuToProductIdEnhanced($sku);
                if (!$productMapping['product_id']) {
                    $unmappedSkus[] = $sku;
                    $skippedRecords++;
                    continue;
                }
                
                // Extract regional information
                $regionId = $regionData['id'] ?? null;
                $regionName = $regionData['name'] ?? null;
                
                // Extract and validate metrics
                $metricsData = $this->extractMetrics($metrics);
                
                // Skip records with no meaningful sales data
                if ($metricsData['revenue'] <= 0 && $metricsData['ordered_units'] <= 0) {
                    $skippedRecords++;
                    continue;
                }
                
                // Get or create region mapping
                $regionMapping = $this->getRegionMapping($regionId, $regionName);
                
                $processedData[] = [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'region_id' => $regionId,
                    'region_name' => $regionMapping['region_name'],
                    'federal_district' => $regionMapping['federal_district'],
                    'offer_id' => $sku,
                    'sku' => $productMapping['sku'],
                    'product_id' => $productMapping['product_id'],
                    'sales_qty' => $metricsData['ordered_units'],
                    'sales_amount' => $metricsData['revenue'],
                    'orders_count' => $this->estimateOrdersCount($metricsData['ordered_units'], $metricsData['revenue']),
                    'marketplace' => 'OZON',
                    'hits_view_search' => $metricsData['hits_view_search'],
                    'hits_view_pdp' => $metricsData['hits_view_pdp'],
                    'conversion' => $metricsData['conversion']
                ];
                
            } catch (Exception $e) {
                logAnalyticsActivity('ERROR', 'Error processing regional data item', [
                    'index' => $index,
                    'item' => $item,
                    'error' => $e->getMessage()
                ]);
                $skippedRecords++;
            }
        }
        
        // Log processing summary
        logAnalyticsActivity('INFO', 'Regional data processing completed', [
            'total_input_records' => count($apiResponse['result']['data']),
            'processed_records' => count($processedData),
            'skipped_records' => $skippedRecords,
            'unmapped_skus_count' => count(array_unique($unmappedSkus)),
            'unique_regions' => count(array_unique(array_column($processedData, 'region_id'))),
            'unique_products' => count(array_unique(array_column($processedData, 'product_id')))
        ]);
        
        // Log unmapped SKUs for investigation
        if (!empty($unmappedSkus)) {
            logAnalyticsActivity('WARNING', 'Unmapped SKUs found during processing', [
                'unmapped_skus' => array_unique($unmappedSkus)
            ]);
        }
        
        return $processedData;
    }
    
    /**
     * Map Ozon SKU to internal product ID (legacy method)
     * 
     * @param string $sku Ozon SKU
     * @return int|null Internal product ID
     */
    private function mapSkuToProductId($sku) {
        $mapping = $this->mapSkuToProductIdEnhanced($sku);
        return $mapping['product_id'];
    }
    
    /**
     * Enhanced mapping of Ozon SKU to internal product ID
     * 
     * @param string $sku Ozon SKU
     * @return array Mapping result with product_id and sku
     */
    private function mapSkuToProductIdEnhanced($sku) {
        static $skuCache = [];
        
        // Check cache first
        if (isset($skuCache[$sku])) {
            return $skuCache[$sku];
        }
        
        $result = [
            'product_id' => null,
            'sku' => $sku,
            'mapping_method' => null
        ];
        
        try {
            // Try exact match on sku_ozon first
            $stmt = $this->pdo->prepare("
                SELECT id, sku_ozon, product_name 
                FROM dim_products 
                WHERE sku_ozon = ? AND is_active = 1
                LIMIT 1
            ");
            
            $stmt->execute([$sku]);
            $product = $stmt->fetch();
            
            if ($product) {
                $result['product_id'] = intval($product['id']);
                $result['mapping_method'] = 'exact_sku_match';
                $skuCache[$sku] = $result;
                return $result;
            }
            
            // Try partial match on product name
            $stmt = $this->pdo->prepare("
                SELECT id, sku_ozon, product_name 
                FROM dim_products 
                WHERE product_name LIKE ? AND is_active = 1
                ORDER BY LENGTH(product_name) ASC
                LIMIT 1
            ");
            
            $stmt->execute(["%$sku%"]);
            $product = $stmt->fetch();
            
            if ($product) {
                $result['product_id'] = intval($product['id']);
                $result['mapping_method'] = 'partial_name_match';
                $skuCache[$sku] = $result;
                return $result;
            }
            
            // Try fuzzy matching for common variations
            $cleanSku = $this->cleanSkuForMatching($sku);
            if ($cleanSku !== $sku) {
                $stmt = $this->pdo->prepare("
                    SELECT id, sku_ozon, product_name 
                    FROM dim_products 
                    WHERE sku_ozon LIKE ? OR product_name LIKE ? AND is_active = 1
                    ORDER BY LENGTH(product_name) ASC
                    LIMIT 1
                ");
                
                $stmt->execute(["%$cleanSku%", "%$cleanSku%"]);
                $product = $stmt->fetch();
                
                if ($product) {
                    $result['product_id'] = intval($product['id']);
                    $result['mapping_method'] = 'fuzzy_match';
                    $skuCache[$sku] = $result;
                    return $result;
                }
            }
            
            // No match found
            $result['mapping_method'] = 'no_match';
            $skuCache[$sku] = $result;
            
            return $result;
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error in enhanced SKU mapping', [
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);
            
            $result['mapping_method'] = 'error';
            $skuCache[$sku] = $result;
            return $result;
        }
    }
    
    /**
     * Clean SKU for fuzzy matching
     * 
     * @param string $sku Original SKU
     * @return string Cleaned SKU
     */
    private function cleanSkuForMatching($sku) {
        // Remove common prefixes/suffixes and normalize
        $cleaned = $sku;
        
        // Remove common Ozon prefixes
        $cleaned = preg_replace('/^(ozon_|oz_|ozon-)/i', '', $cleaned);
        
        // Remove version numbers and variants
        $cleaned = preg_replace('/_v\d+$/i', '', $cleaned);
        $cleaned = preg_replace('/-\d+$/i', '', $cleaned);
        
        // Normalize separators
        $cleaned = str_replace(['-', '_', ' '], '', $cleaned);
        
        // Convert to lowercase for matching
        $cleaned = strtolower($cleaned);
        
        return $cleaned;
    }
    
    /**
     * Extract metrics from API response
     * 
     * @param array $metrics Metrics array from API
     * @return array Extracted metrics
     */
    private function extractMetrics($metrics) {
        $result = [
            'revenue' => 0,
            'ordered_units' => 0,
            'hits_view_search' => 0,
            'hits_view_pdp' => 0,
            'conversion' => 0
        ];
        
        foreach ($metrics as $metric) {
            $key = $metric['key'] ?? '';
            $value = $metric['value'] ?? 0;
            
            switch ($key) {
                case 'revenue':
                    $result['revenue'] = floatval($value);
                    break;
                case 'ordered_units':
                    $result['ordered_units'] = intval($value);
                    break;
                case 'hits_view_search':
                    $result['hits_view_search'] = intval($value);
                    break;
                case 'hits_view_pdp':
                    $result['hits_view_pdp'] = intval($value);
                    break;
                case 'conversion':
                    $result['conversion'] = floatval($value);
                    break;
            }
        }
        
        return $result;
    }
    
    /**
     * Get region mapping from database or create new one
     * 
     * @param string $regionId Ozon region ID
     * @param string $regionName Region name
     * @return array Region mapping
     */
    private function getRegionMapping($regionId, $regionName) {
        static $regionCache = [];
        
        $cacheKey = $regionId . '|' . $regionName;
        
        // Check cache first
        if (isset($regionCache[$cacheKey])) {
            return $regionCache[$cacheKey];
        }
        
        try {
            // Try to find existing region
            $stmt = $this->pdo->prepare("
                SELECT region_name, federal_district 
                FROM regions 
                WHERE region_code = ? OR region_name = ?
                LIMIT 1
            ");
            
            $stmt->execute([$regionId, $regionName]);
            $region = $stmt->fetch();
            
            if ($region) {
                $result = [
                    'region_name' => $region['region_name'],
                    'federal_district' => $region['federal_district']
                ];
            } else {
                // Create new region mapping
                $federalDistrict = $this->getFederalDistrict($regionName);
                
                try {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO regions (region_code, region_name, federal_district, is_active) 
                        VALUES (?, ?, ?, TRUE)
                        ON DUPLICATE KEY UPDATE 
                            region_name = VALUES(region_name),
                            federal_district = VALUES(federal_district)
                    ");
                    
                    $stmt->execute([$regionId, $regionName, $federalDistrict]);
                    
                    logAnalyticsActivity('INFO', 'Created new region mapping', [
                        'region_id' => $regionId,
                        'region_name' => $regionName,
                        'federal_district' => $federalDistrict
                    ]);
                    
                } catch (Exception $e) {
                    logAnalyticsActivity('WARNING', 'Could not create region mapping', [
                        'region_id' => $regionId,
                        'region_name' => $regionName,
                        'error' => $e->getMessage()
                    ]);
                }
                
                $result = [
                    'region_name' => $regionName,
                    'federal_district' => $federalDistrict
                ];
            }
            
            $regionCache[$cacheKey] = $result;
            return $result;
            
        } catch (Exception $e) {
            logAnalyticsActivity('ERROR', 'Error getting region mapping', [
                'region_id' => $regionId,
                'region_name' => $regionName,
                'error' => $e->getMessage()
            ]);
            
            // Fallback to basic mapping
            $result = [
                'region_name' => $regionName,
                'federal_district' => $this->getFederalDistrict($regionName)
            ];
            
            $regionCache[$cacheKey] = $result;
            return $result;
        }
    }
    
    /**
     * Get federal district for region (enhanced version)
     * 
     * @param string $regionName Region name
     * @return string Federal district
     */
    private function getFederalDistrict($regionName) {
        // Comprehensive mapping of regions to federal districts
        $federalDistricts = [
            // Центральный федеральный округ
            'Москва' => 'Центральный федеральный округ',
            'Московская область' => 'Центральный федеральный округ',
            'Белгородская область' => 'Центральный федеральный округ',
            'Брянская область' => 'Центральный федеральный округ',
            'Владимирская область' => 'Центральный федеральный округ',
            'Воронежская область' => 'Центральный федеральный округ',
            'Ивановская область' => 'Центральный федеральный округ',
            'Калужская область' => 'Центральный федеральный округ',
            'Костромская область' => 'Центральный федеральный округ',
            'Курская область' => 'Центральный федеральный округ',
            'Липецкая область' => 'Центральный федеральный округ',
            'Орловская область' => 'Центральный федеральный округ',
            'Рязанская область' => 'Центральный федеральный округ',
            'Смоленская область' => 'Центральный федеральный округ',
            'Тамбовская область' => 'Центральный федеральный округ',
            'Тверская область' => 'Центральный федеральный округ',
            'Тульская область' => 'Центральный федеральный округ',
            'Ярославская область' => 'Центральный федеральный округ',
            
            // Северо-Западный федеральный округ
            'Санкт-Петербург' => 'Северо-Западный федеральный округ',
            'Ленинградская область' => 'Северо-Западный федеральный округ',
            'Архангельская область' => 'Северо-Западный федеральный округ',
            'Вологодская область' => 'Северо-Западный федеральный округ',
            'Калининградская область' => 'Северо-Западный федеральный округ',
            'Карелия' => 'Северо-Западный федеральный округ',
            'Коми' => 'Северо-Западный федеральный округ',
            'Мурманская область' => 'Северо-Западный федеральный округ',
            'Новгородская область' => 'Северо-Западный федеральный округ',
            'Псковская область' => 'Северо-Западный федеральный округ',
            
            // Южный федеральный округ
            'Краснодарский край' => 'Южный федеральный округ',
            'Ростовская область' => 'Южный федеральный округ',
            'Астраханская область' => 'Южный федеральный округ',
            'Волгоградская область' => 'Южный федеральный округ',
            'Адыгея' => 'Южный федеральный округ',
            'Калмыкия' => 'Южный федеральный округ',
            'Крым' => 'Южный федеральный округ',
            'Севастополь' => 'Южный федеральный округ',
            
            // Уральский федеральный округ
            'Свердловская область' => 'Уральский федеральный округ',
            'Челябинская область' => 'Уральский федеральный округ',
            'Тюменская область' => 'Уральский федеральный округ',
            'Курганская область' => 'Уральский федеральный округ',
            'Ханты-Мансийский автономный округ' => 'Уральский федеральный округ',
            'Ямало-Ненецкий автономный округ' => 'Уральский федеральный округ',
            
            // Сибирский федеральный округ
            'Новосибирская область' => 'Сибирский федеральный округ',
            'Красноярский край' => 'Сибирский федеральный округ',
            'Алтайский край' => 'Сибирский федеральный округ',
            'Иркутская область' => 'Сибирский федеральный округ',
            'Кемеровская область' => 'Сибирский федеральный округ',
            'Омская область' => 'Сибирский федеральный округ',
            'Томская область' => 'Сибирский федеральный округ',
            'Алтай' => 'Сибирский федеральный округ',
            'Тыва' => 'Сибирский федеральный округ',
            'Хакасия' => 'Сибирский федеральный округ'
        ];
        
        // Try exact match first
        if (isset($federalDistricts[$regionName])) {
            return $federalDistricts[$regionName];
        }
        
        // Try partial matching for variations
        foreach ($federalDistricts as $region => $district) {
            if (stripos($regionName, $region) !== false || stripos($region, $regionName) !== false) {
                return $district;
            }
        }
        
        return 'Неопределенный федеральный округ';
    }
    
    /**
     * Estimate orders count from units and revenue
     * 
     * @param int $units Number of units sold
     * @param float $revenue Total revenue
     * @return int Estimated orders count
     */
    private function estimateOrdersCount($units, $revenue) {
        if ($units <= 0 || $revenue <= 0) {
            return 0;
        }
        
        // Simple estimation: assume average 1.5 units per order
        $avgUnitsPerOrder = 1.5;
        $estimatedOrders = max(1, round($units / $avgUnitsPerOrder));
        
        return intval($estimatedOrders);
    }
    
    /**
     * Process a batch of sync data
     * 
     * @param array $batch Batch of regional data
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @return array Batch processing results
     */
    private function processSyncBatch($batch, $dateFrom, $dateTo) {
        $results = [
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'errors' => []
        ];
        
        foreach ($batch as $record) {
            try {
                $results['processed']++;
                
                // Check if record already exists
                $existingRecord = $this->findExistingRecord($record);
                
                if ($existingRecord) {
                    // Update existing record
                    $this->updateRegionalRecord($existingRecord['id'], $record);
                    $results['updated']++;
                } else {
                    // Insert new record
                    $this->insertRegionalRecord($record);
                    $results['inserted']++;
                }
                
            } catch (Exception $e) {
                $results['errors'][] = [
                    'record' => $record,
                    'error' => $e->getMessage()
                ];
                
                logAnalyticsActivity('ERROR', 'Error processing sync batch record', [
                    'record' => $record,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $results;
    }
    
    /**
     * Find existing regional record
     * 
     * @param array $record Record data
     * @return array|null Existing record or null
     */
    private function findExistingRecord($record) {
        $stmt = $this->pdo->prepare("
            SELECT id 
            FROM ozon_regional_sales 
            WHERE date_from = ? AND date_to = ? AND region_id = ? AND product_id = ?
            LIMIT 1
        ");
        
        $stmt->execute([
            $record['date_from'],
            $record['date_to'],
            $record['region_id'],
            $record['product_id']
        ]);
        
        return $stmt->fetch();
    }
    
    /**
     * Insert new regional record
     * 
     * @param array $record Record data
     */
    private function insertRegionalRecord($record) {
        $stmt = $this->pdo->prepare("
            INSERT INTO ozon_regional_sales (
                date_from, date_to, region_id, federal_district, offer_id, sku,
                product_id, sales_qty, sales_amount, orders_count, marketplace, synced_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $record['date_from'],
            $record['date_to'],
            $record['region_id'],
            $record['federal_district'],
            $record['offer_id'],
            $record['sku'] ?? null,
            $record['product_id'],
            $record['sales_qty'],
            $record['sales_amount'],
            $record['orders_count'] ?? 0,
            $record['marketplace']
        ]);
    }
    
    /**
     * Update existing regional record
     * 
     * @param int $recordId Record ID
     * @param array $record New record data
     */
    private function updateRegionalRecord($recordId, $record) {
        $stmt = $this->pdo->prepare("
            UPDATE ozon_regional_sales 
            SET sales_qty = ?, sales_amount = ?, orders_count = ?, federal_district = ?, 
                offer_id = ?, sku = ?, synced_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $record['sales_qty'],
            $record['sales_amount'],
            $record['orders_count'] ?? 0,
            $record['federal_district'],
            $record['offer_id'],
            $record['sku'] ?? null,
            $recordId
        ]);
    }
    
    /**
     * Get API connection status
     * 
     * @return array Connection status
     */
    public function getConnectionStatus() {
        try {
            // Make a simple API call to test connection
            $response = $this->makeApiRequest('/v1/analytics/data', 'POST', [
                'date_from' => date('Y-m-d', strtotime('-1 day')),
                'date_to' => date('Y-m-d', strtotime('-1 day')),
                'metrics' => ['revenue'],
                'dimension' => ['sku'],
                'limit' => 1
            ]);
            
            return [
                'connected' => true,
                'message' => 'Ozon API connection successful',
                'client_id' => substr($this->clientId, 0, 8) . '...',
                'api_key' => substr($this->apiKey, 0, 8) . '...'
            ];
            
        } catch (Exception $e) {
            return [
                'connected' => false,
                'message' => 'Ozon API connection failed: ' . $e->getMessage(),
                'client_id' => substr($this->clientId, 0, 8) . '...',
                'api_key' => substr($this->apiKey, 0, 8) . '...'
            ];
        }
    }
    
    /**
     * Validate date range
     * 
     * @param string $dateFrom Start date
     * @param string $dateTo End date
     * @throws Exception On validation errors
     */
    private function validateDateRange($dateFrom, $dateTo) {
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            throw new Exception('Invalid date_from format. Use YYYY-MM-DD.');
        }
        
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            throw new Exception('Invalid date_to format. Use YYYY-MM-DD.');
        }
        
        // Validate date values
        $fromTime = strtotime($dateFrom);
        $toTime = strtotime($dateTo);
        
        if ($fromTime === false) {
            throw new Exception('Invalid date_from value.');
        }
        
        if ($toTime === false) {
            throw new Exception('Invalid date_to value.');
        }
        
        // Check date range
        if ($fromTime > $toTime) {
            throw new Exception('date_from cannot be after date_to.');
        }
        
        // Check maximum date range (1 year)
        $daysDiff = ($toTime - $fromTime) / (24 * 60 * 60);
        if ($daysDiff > 365) {
            throw new Exception('Date range cannot exceed 365 days.');
        }
        
        // Check minimum date (not too far in the past)
        $minTime = strtotime('-2 years');
        if ($fromTime < $minTime) {
            throw new Exception('date_from cannot be more than 2 years in the past.');
        }
        
        // Check future dates
        $maxTime = strtotime('+1 day');
        if ($toTime > $maxTime) {
            throw new Exception('date_to cannot be in the future.');
        }
    }
    
    /**
     * Validate API response structure
     * 
     * @param array $response API response
     * @throws Exception On validation errors
     */
    private function validateApiResponse($response) {
        if (!is_array($response)) {
            throw new Exception('Invalid API response: not an array.');
        }
        
        if (!isset($response['result'])) {
            throw new Exception('Invalid API response: missing result field.');
        }
        
        if (!isset($response['result']['data'])) {
            throw new Exception('Invalid API response: missing data field.');
        }
        
        if (!is_array($response['result']['data'])) {
            throw new Exception('Invalid API response: data field is not an array.');
        }
    }
    
    /**
     * Validate processed data
     * 
     * @param array $processedData Processed data array
     * @return array Validation results
     */
    private function validateProcessedData($processedData) {
        $warnings = [];
        $errors = [];
        
        if (empty($processedData)) {
            $warnings[] = 'No data was processed from API response';
            return ['warnings' => $warnings, 'errors' => $errors];
        }
        
        $totalRevenue = 0;
        $totalUnits = 0;
        $regionsCount = 0;
        $productsCount = 0;
        $uniqueRegions = [];
        $uniqueProducts = [];
        
        foreach ($processedData as $index => $record) {
            // Validate required fields
            $requiredFields = ['date_from', 'date_to', 'region_id', 'product_id', 'sales_qty', 'sales_amount'];
            foreach ($requiredFields as $field) {
                if (!isset($record[$field])) {
                    $errors[] = "Missing required field '$field' in record $index";
                }
            }
            
            // Validate data types and values
            if (isset($record['sales_amount'])) {
                if (!is_numeric($record['sales_amount']) || $record['sales_amount'] < 0) {
                    $warnings[] = "Invalid sales_amount in record $index: " . $record['sales_amount'];
                } else {
                    $totalRevenue += floatval($record['sales_amount']);
                }
            }
            
            if (isset($record['sales_qty'])) {
                if (!is_numeric($record['sales_qty']) || $record['sales_qty'] < 0) {
                    $warnings[] = "Invalid sales_qty in record $index: " . $record['sales_qty'];
                } else {
                    $totalUnits += intval($record['sales_qty']);
                }
            }
            
            if (isset($record['product_id'])) {
                if (!is_numeric($record['product_id']) || $record['product_id'] <= 0) {
                    $errors[] = "Invalid product_id in record $index: " . $record['product_id'];
                } else {
                    $uniqueProducts[] = $record['product_id'];
                }
            }
            
            if (isset($record['region_id'])) {
                if (empty($record['region_id'])) {
                    $warnings[] = "Empty region_id in record $index";
                } else {
                    $uniqueRegions[] = $record['region_id'];
                }
            }
            
            // Validate date format
            if (isset($record['date_from']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $record['date_from'])) {
                $errors[] = "Invalid date_from format in record $index: " . $record['date_from'];
            }
            
            if (isset($record['date_to']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $record['date_to'])) {
                $errors[] = "Invalid date_to format in record $index: " . $record['date_to'];
            }
        }
        
        // Summary validations
        $regionsCount = count(array_unique($uniqueRegions));
        $productsCount = count(array_unique($uniqueProducts));
        
        if ($regionsCount === 0) {
            $warnings[] = 'No valid regions found in processed data';
        }
        
        if ($productsCount === 0) {
            $errors[] = 'No valid products found in processed data';
        }
        
        if ($totalRevenue <= 0) {
            $warnings[] = 'Total revenue is zero or negative';
        }
        
        if ($totalUnits <= 0) {
            $warnings[] = 'Total units sold is zero or negative';
        }
        
        // Log validation summary
        logAnalyticsActivity('INFO', 'Data validation completed', [
            'total_records' => count($processedData),
            'unique_regions' => $regionsCount,
            'unique_products' => $productsCount,
            'total_revenue' => $totalRevenue,
            'total_units' => $totalUnits,
            'warnings_count' => count($warnings),
            'errors_count' => count($errors)
        ]);
        
        return [
            'warnings' => $warnings,
            'errors' => $errors,
            'summary' => [
                'total_records' => count($processedData),
                'unique_regions' => $regionsCount,
                'unique_products' => $productsCount,
                'total_revenue' => $totalRevenue,
                'total_units' => $totalUnits
            ]
        ];
    }
}

?>