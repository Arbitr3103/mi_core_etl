# MDM Classes and Methods Usage Guide

## Overview

This guide explains how to use the new classes and methods created to fix critical MDM system issues.

## Core Classes

### 1. SafeSyncEngine

**Purpose**: Reliable synchronization engine that handles errors gracefully and prevents SQL failures.

**Location**: `src/SafeSyncEngine.php`

#### Basic Usage

```php
<?php
require_once 'src/SafeSyncEngine.php';

// Initialize
$syncEngine = new SafeSyncEngine($pdo);

// Sync all pending products
$result = $syncEngine->syncProductNames();

echo "Synced: {$result['synced']}\n";
echo "Failed: {$result['failed']}\n";
echo "Skipped: {$result['skipped']}\n";
```

#### Advanced Usage

```php
// Sync specific products
$productIds = ['123456', '789012', '345678'];
$result = $syncEngine->syncSpecificProducts($productIds);

// Sync with custom batch size
$syncEngine->setBatchSize(20);  // Default is 10
$result = $syncEngine->syncProductNames();

// Get detailed sync report
$report = $syncEngine->getLastSyncReport();
print_r($report);
```

#### Key Methods

| Method                      | Parameters        | Returns | Description                  |
| --------------------------- | ----------------- | ------- | ---------------------------- |
| `syncProductNames()`        | none              | array   | Syncs all pending products   |
| `syncSpecificProducts()`    | array $productIds | array   | Syncs specific product IDs   |
| `setBatchSize()`            | int $size         | void    | Sets batch processing size   |
| `getLastSyncReport()`       | none              | array   | Returns detailed sync report |
| `findProductsNeedingSync()` | int $limit        | array   | Finds products needing sync  |

#### Error Handling

```php
try {
    $result = $syncEngine->syncProductNames();

    if ($result['failed'] > 0) {
        // Check error log
        $errors = $syncEngine->getErrors();
        foreach ($errors as $error) {
            error_log("Product {$error['product_id']}: {$error['message']}");
        }
    }
} catch (Exception $e) {
    error_log("Critical sync error: " . $e->getMessage());
}
```

### 2. FallbackDataProvider

**Purpose**: Provides cached data when API is unavailable or fails.

**Location**: `src/FallbackDataProvider.php`

#### Basic Usage

```php
<?php
require_once 'src/FallbackDataProvider.php';

$fallback = new FallbackDataProvider($pdo);

// Get product name (tries cache first, then API)
$name = $fallback->getProductName('123456');
echo $name;  // "Product Name" or "Товар ID 123456 (требует обновления)"

// Get product brand
$brand = $fallback->getProductBrand('123456');
```

#### Caching Methods

```php
// Manually cache product data
$fallback->cacheProductData('123456', [
    'name' => 'Product Name',
    'brand' => 'Brand Name'
]);

// Check if product has cached data
if ($fallback->hasCachedData('123456')) {
    echo "Data is cached\n";
}

// Get cache age
$age = $fallback->getCacheAge('123456');
echo "Cache is {$age} seconds old\n";

// Clear old cache (older than 30 days)
$fallback->clearOldCache(30);
```

#### Configuration

```php
// Set cache expiration (in days)
$fallback->setCacheExpiration(7);  // Default is 30 days

// Enable/disable API fallback
$fallback->setApiEnabled(false);  // Only use cache

// Set temporary name format
$fallback->setTemporaryNameFormat('Product #{id} (pending sync)');
```

### 3. DataTypeNormalizer

**Purpose**: Normalizes data types to prevent SQL JOIN errors.

**Location**: `src/DataTypeNormalizer.php`

#### Basic Usage

```php
<?php
require_once 'src/DataTypeNormalizer.php';

$normalizer = new DataTypeNormalizer();

// Normalize product ID (converts to VARCHAR)
$normalized = $normalizer->normalizeProductId(123456);
echo $normalized;  // "123456" (string)

// Normalize for SQL query
$safeId = $normalizer->normalizeForQuery($productId);
```

#### Validation Methods

```php
// Validate product ID format
if ($normalizer->isValidProductId($productId)) {
    echo "Valid ID\n";
}

// Validate and normalize
$result = $normalizer->validateAndNormalize($productId);
if ($result['valid']) {
    $safeId = $result['normalized'];
} else {
    echo "Error: {$result['error']}\n";
}
```

#### Batch Operations

```php
// Normalize array of IDs
$ids = [123, 456, 789, '012'];
$normalized = $normalizer->normalizeArray($ids);
// Result: ['123', '456', '789', '012']

// Prepare for SQL IN clause
$inClause = $normalizer->prepareForInClause($ids);
echo $inClause;  // "'123','456','789','012'"
```

### 4. CrossReferenceManager

**Purpose**: Manages product cross-reference mappings.

**Location**: `src/CrossReferenceManager.php`

#### Basic Usage

```php
<?php
require_once 'src/CrossReferenceManager.php';

$manager = new CrossReferenceManager($pdo);

// Create or update cross-reference
$manager->createOrUpdate([
    'inventory_product_id' => '123456',
    'ozon_product_id' => 'OZ789012',
    'cached_name' => 'Product Name',
    'sync_status' => 'synced'
]);

// Get cross-reference by inventory ID
$ref = $manager->getByInventoryId('123456');
print_r($ref);
```

#### Query Methods

```php
// Find by different ID types
$ref = $manager->getByOzonId('OZ789012');
$ref = $manager->getBySkuOzon('123456');

// Get all pending syncs
$pending = $manager->getPendingSync(50);  // Limit 50

// Get failed syncs
$failed = $manager->getFailedSync();

// Update sync status
$manager->updateSyncStatus('123456', 'synced');
$manager->updateSyncStatus('789012', 'failed');
```

#### Bulk Operations

```php
// Bulk create from inventory data
$count = $manager->bulkCreateFromInventory();
echo "Created {$count} cross-references\n";

// Bulk update cached names
$updates = [
    '123456' => 'Product Name 1',
    '789012' => 'Product Name 2'
];
$manager->bulkUpdateCachedNames($updates);
```

### 5. SyncErrorHandler

**Purpose**: Handles and logs synchronization errors.

**Location**: `src/SyncErrorHandler.php`

#### Basic Usage

```php
<?php
require_once 'src/SyncErrorHandler.php';

$errorHandler = new SyncErrorHandler($pdo);

// Log an error
$errorHandler->logError([
    'product_id' => '123456',
    'error_type' => 'api_timeout',
    'error_message' => 'API request timed out after 30s',
    'context' => ['endpoint' => '/api/products']
]);

// Get recent errors
$errors = $errorHandler->getRecentErrors(10);
foreach ($errors as $error) {
    echo "{$error['product_id']}: {$error['error_message']}\n";
}
```

#### Error Analysis

```php
// Get error statistics
$stats = $errorHandler->getErrorStats();
echo "Total errors: {$stats['total']}\n";
echo "API errors: {$stats['api_errors']}\n";
echo "SQL errors: {$stats['sql_errors']}\n";

// Get errors by product
$productErrors = $errorHandler->getErrorsByProduct('123456');

// Get errors by type
$apiErrors = $errorHandler->getErrorsByType('api_timeout');
```

#### Error Recovery

```php
// Mark error as resolved
$errorHandler->markResolved($errorId);

// Retry failed products
$failedProducts = $errorHandler->getFailedProducts();
foreach ($failedProducts as $productId) {
    // Retry sync logic here
}

// Clear old errors (older than 30 days)
$errorHandler->clearOldErrors(30);
```

## Integration Examples

### Complete Sync Workflow

```php
<?php
require_once 'src/SafeSyncEngine.php';
require_once 'src/FallbackDataProvider.php';
require_once 'src/SyncErrorHandler.php';

// Initialize components
$pdo = new PDO("mysql:host=localhost;dbname=your_db", "user", "pass");
$syncEngine = new SafeSyncEngine($pdo);
$fallback = new FallbackDataProvider($pdo);
$errorHandler = new SyncErrorHandler($pdo);

// Run sync
try {
    echo "Starting product sync...\n";
    $result = $syncEngine->syncProductNames();

    echo "Results:\n";
    echo "- Synced: {$result['synced']}\n";
    echo "- Failed: {$result['failed']}\n";
    echo "- Skipped: {$result['skipped']}\n";

    // Handle failures
    if ($result['failed'] > 0) {
        $errors = $syncEngine->getErrors();
        foreach ($errors as $error) {
            $errorHandler->logError($error);

            // Use fallback for failed products
            $name = $fallback->getProductName($error['product_id']);
            echo "Using fallback name for {$error['product_id']}: {$name}\n";
        }
    }

} catch (Exception $e) {
    $errorHandler->logError([
        'error_type' => 'critical',
        'error_message' => $e->getMessage()
    ]);
    echo "Critical error: {$e->getMessage()}\n";
}
```

### API Endpoint Integration

```php
<?php
// api/products.php
require_once 'src/FallbackDataProvider.php';
require_once 'src/DataTypeNormalizer.php';

$fallback = new FallbackDataProvider($pdo);
$normalizer = new DataTypeNormalizer();

// Get product ID from request
$productId = $_GET['id'] ?? null;

// Normalize and validate
$result = $normalizer->validateAndNormalize($productId);
if (!$result['valid']) {
    http_response_code(400);
    echo json_encode(['error' => $result['error']]);
    exit;
}

// Get product data (with fallback)
$name = $fallback->getProductName($result['normalized']);
$brand = $fallback->getProductBrand($result['normalized']);

echo json_encode([
    'id' => $result['normalized'],
    'name' => $name,
    'brand' => $brand
]);
```

## Best Practices

### 1. Always Use Try-Catch

```php
try {
    $result = $syncEngine->syncProductNames();
} catch (Exception $e) {
    error_log("Sync failed: " . $e->getMessage());
    // Handle gracefully
}
```

### 2. Normalize IDs Before Queries

```php
// Bad
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$productId]);

// Good
$normalizedId = $normalizer->normalizeProductId($productId);
$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$normalizedId]);
```

### 3. Use Fallback for User-Facing Data

```php
// Always use fallback for display
$name = $fallback->getProductName($productId);
echo htmlspecialchars($name);
```

### 4. Log Errors for Monitoring

```php
if ($result['failed'] > 0) {
    foreach ($syncEngine->getErrors() as $error) {
        $errorHandler->logError($error);
    }
}
```

### 5. Batch Process Large Operations

```php
$syncEngine->setBatchSize(10);  // Process 10 at a time
$result = $syncEngine->syncProductNames();
```

## Troubleshooting

See `MDM_TROUBLESHOOTING_GUIDE.md` for common issues and solutions.

## References

- Requirements: 2.1, 3.1
- Design Document: `design.md`
- Test Examples: `tests/SafeSyncEngineTest.php`
- API Documentation: `docs/API_REFERENCE.md`
