<?php
/**
 * Example usage of AnalyticsApiClient
 * 
 * Demonstrates how to use the Analytics API client for fetching
 * warehouse stock data with pagination, caching, and error handling.
 * 
 * Task: 4.1 Создать AnalyticsApiClient сервис (example)
 */

require_once __DIR__ . '/../src/Services/AnalyticsApiClient.php';
require_once __DIR__ . '/../src/config/database.php';

// Example 1: Basic usage with pagination
function basicUsageExample() {
    echo "=== Basic Usage Example ===\n";
    
    try {
        // Initialize client with credentials
        $client = new AnalyticsApiClient(
            'your_client_id',
            'your_api_key',
            getDatabaseConnection()
        );
        
        // Test connection first
        $connectionTest = $client->testConnection();
        if (!$connectionTest['success']) {
            throw new Exception("Connection failed: " . $connectionTest['message']);
        }
        
        echo "✅ Connection successful\n";
        
        // Fetch first page of stock data
        $stockData = $client->getStockOnWarehouses(0, 100);
        
        echo "📦 Fetched " . count($stockData['data']) . " stock records\n";
        echo "📊 Total available: " . $stockData['total_count'] . "\n";
        echo "🔄 Has more data: " . ($stockData['has_more'] ? 'Yes' : 'No') . "\n";
        
        // Display first few records
        foreach (array_slice($stockData['data'], 0, 3) as $record) {
            echo "  - SKU: {$record['sku']}, Warehouse: {$record['warehouse_name']}, Stock: {$record['available_stock']}\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 2: Fetch all data using generator (memory efficient)
function fetchAllDataExample() {
    echo "\n=== Fetch All Data Example ===\n";
    
    try {
        $client = new AnalyticsApiClient(
            'your_client_id',
            'your_api_key',
            getDatabaseConnection()
        );
        
        $totalProcessed = 0;
        $warehouseCounts = [];
        
        // Use generator to process all data without loading everything into memory
        foreach ($client->getAllStockData() as $batch) {
            echo "📦 Processing batch: offset {$batch['offset']}, size {$batch['batch_size']}\n";
            
            // Process each record in the batch
            foreach ($batch['data'] as $record) {
                $warehouse = $record['warehouse_name'];
                $warehouseCounts[$warehouse] = ($warehouseCounts[$warehouse] ?? 0) + 1;
            }
            
            $totalProcessed = $batch['total_processed'];
            
            // Break after processing a few batches for demo
            if ($totalProcessed >= 3000) {
                echo "🛑 Stopping after processing {$totalProcessed} records (demo limit)\n";
                break;
            }
        }
        
        echo "📊 Total processed: {$totalProcessed} records\n";
        echo "🏢 Warehouses found: " . count($warehouseCounts) . "\n";
        
        // Show top warehouses by stock count
        arsort($warehouseCounts);
        echo "🔝 Top warehouses:\n";
        foreach (array_slice($warehouseCounts, 0, 5, true) as $warehouse => $count) {
            echo "  - {$warehouse}: {$count} items\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 3: Using filters
function filtersExample() {
    echo "\n=== Filters Example ===\n";
    
    try {
        $client = new AnalyticsApiClient(
            'your_client_id',
            'your_api_key',
            getDatabaseConnection()
        );
        
        // Define filters
        $filters = [
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-31',
            'warehouse_names' => ['РФЦ Москва', 'РФЦ Санкт-Петербург'],
            'sku_list' => ['SKU001', 'SKU002', 'SKU003']
        ];
        
        echo "🔍 Applying filters:\n";
        echo "  - Date range: {$filters['date_from']} to {$filters['date_to']}\n";
        echo "  - Warehouses: " . implode(', ', $filters['warehouse_names']) . "\n";
        echo "  - SKUs: " . implode(', ', $filters['sku_list']) . "\n";
        
        $filteredData = $client->getStockOnWarehouses(0, 500, $filters);
        
        echo "📦 Filtered results: " . count($filteredData['data']) . " records\n";
        
        // Analyze filtered data
        $warehouseBreakdown = [];
        foreach ($filteredData['data'] as $record) {
            $warehouse = $record['warehouse_name'];
            $warehouseBreakdown[$warehouse] = ($warehouseBreakdown[$warehouse] ?? 0) + 1;
        }
        
        echo "📊 Warehouse breakdown:\n";
        foreach ($warehouseBreakdown as $warehouse => $count) {
            echo "  - {$warehouse}: {$count} items\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 4: Error handling and retry logic
function errorHandlingExample() {
    echo "\n=== Error Handling Example ===\n";
    
    try {
        // Initialize with invalid credentials to demonstrate error handling
        $client = new AnalyticsApiClient(
            'invalid_client_id',
            'invalid_api_key',
            getDatabaseConnection()
        );
        
        echo "🔄 Attempting request with invalid credentials...\n";
        
        $stockData = $client->getStockOnWarehouses(0, 10);
        
    } catch (AnalyticsApiException $e) {
        echo "🚨 Analytics API Exception caught:\n";
        echo "  - Message: " . $e->getMessage() . "\n";
        echo "  - Error Type: " . $e->getErrorType() . "\n";
        echo "  - Is Critical: " . ($e->isCritical() ? 'Yes' : 'No') . "\n";
        echo "  - HTTP Code: " . $e->getCode() . "\n";
        
        // Handle different error types
        switch ($e->getErrorType()) {
            case 'AUTHENTICATION_ERROR':
                echo "💡 Suggestion: Check your Client ID and API Key\n";
                break;
            case 'RATE_LIMIT_ERROR':
                echo "💡 Suggestion: Wait before making more requests\n";
                break;
            case 'NETWORK_ERROR':
                echo "💡 Suggestion: Check your internet connection\n";
                break;
            default:
                echo "💡 Suggestion: Check API documentation for error details\n";
        }
        
    } catch (Exception $e) {
        echo "❌ General Exception: " . $e->getMessage() . "\n";
    }
}

// Example 5: Cache management
function cacheManagementExample() {
    echo "\n=== Cache Management Example ===\n";
    
    try {
        $client = new AnalyticsApiClient(
            'your_client_id',
            'your_api_key',
            getDatabaseConnection()
        );
        
        echo "📊 Client statistics before requests:\n";
        $stats = $client->getStats();
        foreach ($stats as $key => $value) {
            echo "  - {$key}: {$value}\n";
        }
        
        // Make a request (will be cached)
        echo "\n🔄 Making first request (will be cached)...\n";
        $start = microtime(true);
        $data1 = $client->getStockOnWarehouses(0, 100);
        $time1 = microtime(true) - $start;
        echo "⏱️  First request took: " . round($time1 * 1000, 2) . "ms\n";
        
        // Make the same request again (should use cache)
        echo "\n🔄 Making same request again (should use cache)...\n";
        $start = microtime(true);
        $data2 = $client->getStockOnWarehouses(0, 100);
        $time2 = microtime(true) - $start;
        echo "⏱️  Second request took: " . round($time2 * 1000, 2) . "ms\n";
        
        if ($time2 < $time1 / 2) {
            echo "✅ Cache is working! Second request was much faster.\n";
        }
        
        // Clear expired cache
        echo "\n🧹 Clearing expired cache entries...\n";
        $cleared = $client->clearExpiredCache();
        echo "🗑️  Cleared {$cleared} expired cache entries\n";
        
        echo "\n📊 Client statistics after requests:\n";
        $stats = $client->getStats();
        foreach ($stats as $key => $value) {
            echo "  - {$key}: {$value}\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

// Example 6: Integration with ETL logging
function etlIntegrationExample() {
    echo "\n=== ETL Integration Example ===\n";
    
    try {
        $pdo = getDatabaseConnection();
        $client = new AnalyticsApiClient(
            'your_client_id',
            'your_api_key',
            $pdo
        );
        
        echo "🔄 Starting ETL process simulation...\n";
        
        $batchId = 'etl_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 8);
        $startTime = microtime(true);
        
        // Log ETL start
        $stmt = $pdo->prepare("
            INSERT INTO analytics_etl_log (
                batch_id, etl_type, status, started_at, data_source
            ) VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $batchId,
            'api_sync',
            'running',
            date('Y-m-d H:i:s'),
            'analytics_api'
        ]);
        
        $totalRecords = 0;
        $warehouseCount = 0;
        $uniqueWarehouses = [];
        
        // Process data in batches
        foreach ($client->getAllStockData() as $batch) {
            $totalRecords += $batch['batch_size'];
            
            foreach ($batch['data'] as $record) {
                $uniqueWarehouses[$record['warehouse_name']] = true;
            }
            
            echo "📦 Processed batch: {$batch['batch_size']} records, total: {$totalRecords}\n";
            
            // Demo: stop after 2000 records
            if ($totalRecords >= 2000) {
                break;
            }
        }
        
        $warehouseCount = count($uniqueWarehouses);
        $executionTime = round((microtime(true) - $startTime) * 1000);
        
        // Log ETL completion
        $stmt = $pdo->prepare("
            UPDATE analytics_etl_log 
            SET status = ?, completed_at = ?, records_processed = ?, 
                warehouse_count = ?, execution_time_ms = ?
            WHERE batch_id = ?
        ");
        $stmt->execute([
            'completed',
            date('Y-m-d H:i:s'),
            $totalRecords,
            $warehouseCount,
            $executionTime,
            $batchId
        ]);
        
        echo "✅ ETL process completed:\n";
        echo "  - Batch ID: {$batchId}\n";
        echo "  - Records processed: {$totalRecords}\n";
        echo "  - Warehouses found: {$warehouseCount}\n";
        echo "  - Execution time: {$executionTime}ms\n";
        
        // Show recent ETL logs
        echo "\n📋 Recent ETL logs:\n";
        $stmt = $pdo->query("
            SELECT batch_id, etl_type, status, records_processed, 
                   execution_time_ms, created_at
            FROM analytics_etl_log 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        
        while ($log = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "  - {$log['batch_id']}: {$log['status']}, {$log['records_processed']} records, {$log['execution_time_ms']}ms\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        
        // Log ETL failure
        if (isset($batchId) && isset($pdo)) {
            $stmt = $pdo->prepare("
                UPDATE analytics_etl_log 
                SET status = ?, completed_at = ?, error_message = ?
                WHERE batch_id = ?
            ");
            $stmt->execute([
                'failed',
                date('Y-m-d H:i:s'),
                $e->getMessage(),
                $batchId
            ]);
        }
    }
}

// Helper function to get database connection
function getDatabaseConnection(): PDO {
    // This would normally come from your database configuration
    // For demo purposes, using SQLite in memory
    $pdo = new PDO('sqlite::memory:');
    
    // Create required tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS analytics_api_cache (
            cache_key VARCHAR(255) PRIMARY KEY,
            data TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS analytics_etl_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            batch_id VARCHAR(255),
            etl_type VARCHAR(50),
            status VARCHAR(20),
            records_processed INTEGER DEFAULT 0,
            warehouse_count INTEGER DEFAULT 0,
            execution_time_ms INTEGER DEFAULT 0,
            data_source VARCHAR(50),
            error_message TEXT,
            started_at DATETIME,
            completed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    
    return $pdo;
}

// Main execution
if (php_sapi_name() === 'cli') {
    echo "🚀 AnalyticsApiClient Examples\n";
    echo "================================\n";
    
    // Note: Replace 'your_client_id' and 'your_api_key' with real credentials
    echo "⚠️  Note: Replace credentials with real values for actual testing\n\n";
    
    // Run examples
    basicUsageExample();
    fetchAllDataExample();
    filtersExample();
    errorHandlingExample();
    cacheManagementExample();
    etlIntegrationExample();
    
    echo "\n✅ All examples completed!\n";
}