<?php
/**
 * CSVReportProcessor Class - Processes warehouse stock CSV reports from Ozon
 * 
 * Handles parsing, validation, and transformation of CSV reports containing
 * warehouse stock data from Ozon API reports system.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class CSVReportProcessor {
    
    private $pdo;
    private $requiredColumns = [
        'SKU',
        'Warehouse_Name', 
        'Current_Stock',
        'Reserved_Stock',
        'Available_Stock',
        'Last_Updated'
    ];
    
    private $warehouseMapping = [
        // Ozon warehouse names to normalized names
        'Хоругвино' => 'Хоругвино',
        'Тверь' => 'Тверь',
        'Екатеринбург' => 'Екатеринбург',
        'Новосибирск' => 'Новосибирск',
        'Казань' => 'Казань',
        'Ростов-на-Дону' => 'Ростов-на-Дону',
        'Краснодар' => 'Краснодар',
        'Самара' => 'Самара',
        'Челябинск' => 'Челябинск',
        'Воронеж' => 'Воронеж'
    ];
    
    private $warehouseLocations = [
        'Хоругвино' => ['region' => 'Московская область', 'type' => 'main'],
        'Тверь' => ['region' => 'Тверская область', 'type' => 'main'],
        'Екатеринбург' => ['region' => 'Свердловская область', 'type' => 'regional'],
        'Новосибирск' => ['region' => 'Новосибирская область', 'type' => 'regional'],
        'Казань' => ['region' => 'Республика Татарстан', 'type' => 'regional'],
        'Ростов-на-Дону' => ['region' => 'Ростовская область', 'type' => 'regional'],
        'Краснодар' => ['region' => 'Краснодарский край', 'type' => 'regional'],
        'Самара' => ['region' => 'Самарская область', 'type' => 'regional'],
        'Челябинск' => ['region' => 'Челябинская область', 'type' => 'regional'],
        'Воронеж' => ['region' => 'Воронежская область', 'type' => 'regional']
    ];
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Parse warehouse stock CSV content into structured array
     * 
     * @param string $csvContent - Raw CSV content from Ozon report
     * @return array Parsed and validated stock data
     * @throws Exception If CSV parsing or validation fails
     */
    public function parseWarehouseStockCSV(string $csvContent): array {
        if (empty($csvContent)) {
            throw new Exception("CSV content is empty");
        }
        
        // Parse CSV content
        $lines = str_getcsv($csvContent, "\n");
        if (empty($lines)) {
            throw new Exception("No lines found in CSV content");
        }
        
        // Get header row
        $header = str_getcsv(array_shift($lines));
        if (empty($header)) {
            throw new Exception("CSV header is empty");
        }
        
        // Validate CSV structure
        if (!$this->validateCSVStructure(['header' => $header])) {
            throw new Exception("CSV structure validation failed");
        }
        
        $parsedData = [];
        $lineNumber = 2; // Start from line 2 (after header)
        
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue; // Skip empty lines
            }
            
            $row = str_getcsv($line);
            if (count($row) !== count($header)) {
                error_log("Warning: Line $lineNumber has incorrect column count. Expected: " . count($header) . ", Got: " . count($row));
                $lineNumber++;
                continue;
            }
            
            // Combine header with row data
            $rowData = array_combine($header, $row);
            
            // Validate and normalize row data
            $validatedRow = $this->validateAndNormalizeRow($rowData, $lineNumber);
            if ($validatedRow !== null) {
                $parsedData[] = $validatedRow;
            }
            
            $lineNumber++;
        }
        
        return $parsedData;
    }
    
    /**
     * Validate CSV structure and required columns
     * 
     * @param array $csvData - CSV data with header information
     * @return bool True if structure is valid
     */
    public function validateCSVStructure(array $csvData): bool {
        if (!isset($csvData['header']) || !is_array($csvData['header'])) {
            error_log("CSV validation failed: No header found");
            return false;
        }
        
        $header = $csvData['header'];
        
        // Check for required columns
        foreach ($this->requiredColumns as $requiredColumn) {
            if (!in_array($requiredColumn, $header)) {
                error_log("CSV validation failed: Missing required column '$requiredColumn'");
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate and normalize individual CSV row
     * 
     * @param array $rowData - Row data from CSV
     * @param int $lineNumber - Line number for error reporting
     * @return array|null Normalized row data or null if invalid
     */
    private function validateAndNormalizeRow(array $rowData, int $lineNumber): ?array {
        // Validate SKU
        if (empty($rowData['SKU']) || !is_numeric($rowData['SKU'])) {
            error_log("Warning: Invalid SKU on line $lineNumber: " . ($rowData['SKU'] ?? 'empty'));
            return null;
        }
        
        // Validate warehouse name
        if (empty($rowData['Warehouse_Name'])) {
            error_log("Warning: Empty warehouse name on line $lineNumber");
            return null;
        }
        
        // Validate stock quantities
        $stockFields = ['Current_Stock', 'Reserved_Stock', 'Available_Stock'];
        foreach ($stockFields as $field) {
            if (!isset($rowData[$field]) || !is_numeric($rowData[$field]) || $rowData[$field] < 0) {
                error_log("Warning: Invalid $field on line $lineNumber: " . ($rowData[$field] ?? 'empty'));
                return null;
            }
        }
        
        // Validate date format
        if (!empty($rowData['Last_Updated'])) {
            $timestamp = strtotime($rowData['Last_Updated']);
            if ($timestamp === false) {
                error_log("Warning: Invalid date format on line $lineNumber: " . $rowData['Last_Updated']);
                $rowData['Last_Updated'] = date('Y-m-d H:i:s'); // Use current timestamp as fallback
            } else {
                $rowData['Last_Updated'] = date('Y-m-d H:i:s', $timestamp);
            }
        } else {
            $rowData['Last_Updated'] = date('Y-m-d H:i:s');
        }
        
        // Normalize data types
        $rowData['SKU'] = (string)$rowData['SKU'];
        $rowData['Current_Stock'] = (int)$rowData['Current_Stock'];
        $rowData['Reserved_Stock'] = (int)$rowData['Reserved_Stock'];
        $rowData['Available_Stock'] = (int)$rowData['Available_Stock'];
        
        return $rowData;
    }
    
    /**
     * Normalize warehouse names to consistent format
     * 
     * @param array $stockData - Stock data with warehouse names
     * @return array Stock data with normalized warehouse names
     */
    public function normalizeWarehouseNames(array $stockData): array {
        foreach ($stockData as &$record) {
            if (isset($record['Warehouse_Name'])) {
                $originalName = trim($record['Warehouse_Name']);
                
                // Check if we have a mapping for this warehouse
                if (isset($this->warehouseMapping[$originalName])) {
                    $record['Warehouse_Name'] = $this->warehouseMapping[$originalName];
                    $record['warehouse_normalized'] = true;
                } else {
                    // Keep original name but mark as unmapped
                    $record['warehouse_normalized'] = false;
                    error_log("Warning: Unknown warehouse name encountered: $originalName");
                }
                
                // Add location information if available
                if (isset($this->warehouseLocations[$record['Warehouse_Name']])) {
                    $record['warehouse_region'] = $this->warehouseLocations[$record['Warehouse_Name']]['region'];
                    $record['warehouse_type'] = $this->warehouseLocations[$record['Warehouse_Name']]['type'];
                }
            }
        }
        
        return $stockData;
    }
    
    /**
     * Map product SKUs to internal product IDs
     * 
     * @param array $stockData - Stock data with SKUs
     * @return array Stock data with mapped product IDs
     */
    public function mapProductSKUs(array $stockData): array {
        if (empty($stockData)) {
            return $stockData;
        }
        
        // Extract unique SKUs for batch lookup
        $skus = array_unique(array_column($stockData, 'SKU'));
        $skuToProductIdMap = $this->buildSKUMapping($skus);
        
        // Map SKUs to product IDs
        foreach ($stockData as &$record) {
            $sku = $record['SKU'];
            
            if (isset($skuToProductIdMap[$sku])) {
                $record['product_id'] = $skuToProductIdMap[$sku]['product_id'];
                $record['product_name'] = $skuToProductIdMap[$sku]['product_name'] ?? null;
                $record['sku_mapped'] = true;
            } else {
                $record['product_id'] = null;
                $record['sku_mapped'] = false;
                error_log("Warning: SKU not found in product catalog: $sku");
            }
        }
        
        return $stockData;
    }
    
    /**
     * Build SKU to product ID mapping from database
     * 
     * @param array $skus - Array of SKUs to map
     * @return array Mapping of SKU to product information
     */
    private function buildSKUMapping(array $skus): array {
        if (empty($skus)) {
            return [];
        }
        
        $mapping = [];
        
        try {
            // Create placeholders for IN clause
            $placeholders = str_repeat('?,', count($skus) - 1) . '?';
            
            // Try multiple table structures to find SKU mappings
            $queries = [
                // Try dim_products table first
                "SELECT id as product_id, sku, name as product_name FROM dim_products WHERE sku IN ($placeholders)",
                // Try alternative column names
                "SELECT product_id, sku_ozon as sku, product_name FROM dim_products WHERE sku_ozon IN ($placeholders)",
                // Try product_master table
                "SELECT id as product_id, sku, product_name FROM product_master WHERE sku IN ($placeholders)"
            ];
            
            foreach ($queries as $query) {
                try {
                    $stmt = $this->pdo->prepare($query);
                    $stmt->execute($skus);
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($results)) {
                        foreach ($results as $row) {
                            $mapping[$row['sku']] = [
                                'product_id' => $row['product_id'],
                                'product_name' => $row['product_name'] ?? null
                            ];
                        }
                        break; // Use first successful query
                    }
                } catch (PDOException $e) {
                    // Try next query if this one fails
                    continue;
                }
            }
            
        } catch (Exception $e) {
            error_log("Error building SKU mapping: " . $e->getMessage());
        }
        
        return $mapping;
    }
    
    /**
     * Get warehouse mapping configuration
     * 
     * @return array Current warehouse mapping
     */
    public function getWarehouseMapping(): array {
        return $this->warehouseMapping;
    }
    
    /**
     * Add new warehouse mapping
     * 
     * @param string $ozonName - Ozon warehouse name
     * @param string $normalizedName - Normalized warehouse name
     * @param array $location - Location information
     */
    public function addWarehouseMapping(string $ozonName, string $normalizedName, array $location = []): void {
        $this->warehouseMapping[$ozonName] = $normalizedName;
        
        if (!empty($location)) {
            $this->warehouseLocations[$normalizedName] = $location;
        }
    }
    
    /**
     * Validate unknown warehouse names and suggest mappings
     * 
     * @param array $stockData - Stock data to analyze
     * @return array List of unknown warehouse names
     */
    public function validateUnknownWarehouses(array $stockData): array {
        $unknownWarehouses = [];
        
        foreach ($stockData as $record) {
            if (isset($record['warehouse_normalized']) && !$record['warehouse_normalized']) {
                $warehouseName = $record['Warehouse_Name'];
                if (!in_array($warehouseName, $unknownWarehouses)) {
                    $unknownWarehouses[] = $warehouseName;
                }
            }
        }
        
        return $unknownWarehouses;
    }
}