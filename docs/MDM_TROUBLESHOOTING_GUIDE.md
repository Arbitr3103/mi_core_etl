# MDM System Troubleshooting Guide

## Overview

This guide helps diagnose and fix common issues in the MDM (Master Data Management) system.

## Common Issues

### 1. SQL Error: "Expression #1 of ORDER BY clause is not in SELECT list"

**Symptoms**:

```
Error: Expression #1 of ORDER BY clause is not in SELECT list,
references column 'database.table.column' which is not in SELECT list
```

**Cause**: Using DISTINCT with ORDER BY on columns not in SELECT list when ONLY_FULL_GROUP_BY mode is enabled.

**Solution**:

```sql
-- Bad (causes error)
SELECT DISTINCT product_id
FROM inventory_data
ORDER BY quantity_present DESC;

-- Good (fixed)
SELECT DISTINCT product_id, MAX(quantity_present) as max_quantity
FROM inventory_data
GROUP BY product_id
ORDER BY max_quantity DESC;
```

**Quick Fix Script**:

```bash
php test_sql_compatibility.php
```

**References**:

- Fixed queries: `fixed_sql_queries.sql`
- Requirements: 2.1, 8.1

---

### 2. Type Mismatch Error in JOIN Operations

**Symptoms**:

```
Error: Cannot join INT with VARCHAR
Products not showing names in dashboard
```

**Cause**: `dim_products.sku_ozon` was INT but needs to join with VARCHAR fields.

**Solution**:

```sql
-- Check current type
DESCRIBE dim_products;

-- If sku_ozon is INT, migrate it
ALTER TABLE dim_products MODIFY COLUMN sku_ozon VARCHAR(50) NOT NULL;
```

**Migration Script**:

```bash
mysql -u root -p your_database < migrate_dim_products_table.sql
```

**Verification**:

```php
php tests/DataTypeCompatibilityTest.php
```

**References**:

- Migration: `migrate_dim_products_table.sql`
- Requirements: 1.1, 2.2, 8.2

---

### 3. Products Showing as "Товар Ozon ID 123" Instead of Real Names

**Symptoms**:

- Dashboard displays placeholder names
- Real product names not syncing from API

**Diagnosis**:

```php
// Check sync status
php -r "
require 'config.php';
\$stmt = \$pdo->query('SELECT sync_status, COUNT(*) as count FROM product_cross_reference GROUP BY sync_status');
print_r(\$stmt->fetchAll());
"
```

**Solutions**:

1. **Run sync script**:

```bash
php sync-real-product-names-v2.php
```

2. **Check for errors**:

```php
php -r "
require 'src/SyncErrorHandler.php';
\$handler = new SyncErrorHandler(\$pdo);
print_r(\$handler->getRecentErrors(10));
"
```

3. **Force re-sync specific products**:

```php
require 'src/SafeSyncEngine.php';
$engine = new SafeSyncEngine($pdo);
$result = $engine->syncSpecificProducts(['123456', '789012']);
print_r($result);
```

**References**:

- Sync script: `sync-real-product-names-v2.php`
- Requirements: 3.1, 3.2, 3.3

---

### 4. Sync Script Timeout or Memory Issues

**Symptoms**:

```
Fatal error: Maximum execution time exceeded
Fatal error: Allowed memory size exhausted
```

**Solutions**:

1. **Increase PHP limits** (temporary):

```php
ini_set('max_execution_time', 300);  // 5 minutes
ini_set('memory_limit', '512M');
```

2. **Use batch processing**:

```php
require 'src/SafeSyncEngine.php';
$engine = new SafeSyncEngine($pdo);
$engine->setBatchSize(5);  // Smaller batches
$result = $engine->syncProductNames();
```

3. **Process in chunks**:

```bash
# Sync 50 products at a time
php sync-real-product-names-v2.php --limit 50
```

**References**:

- Requirements: 3.4

---

### 5. API Connection Failures

**Symptoms**:

```
Error: Could not connect to Ozon API
Error: API request timeout
```

**Diagnosis**:

```php
// Test API connection
php -r "
\$ch = curl_init('https://api-seller.ozon.ru/v2/product/info');
curl_setopt(\$ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt(\$ch, CURLOPT_TIMEOUT, 10);
\$result = curl_exec(\$ch);
\$error = curl_error(\$ch);
echo \$error ? 'Error: ' . \$error : 'Connection OK';
"
```

**Solutions**:

1. **Check API credentials**:

```bash
cat .env | grep OZON
```

2. **Use fallback data**:

```php
require 'src/FallbackDataProvider.php';
$fallback = new FallbackDataProvider($pdo);
$name = $fallback->getProductName('123456');  // Uses cache
```

3. **Increase timeout**:

```php
// In sync script
$engine->setApiTimeout(60);  // 60 seconds
```

**References**:

- Requirements: 3.3, 3.4, 8.4

---

### 6. Cross-Reference Table Missing or Empty

**Symptoms**:

```
Error: Table 'product_cross_reference' doesn't exist
No products found in cross-reference table
```

**Solutions**:

1. **Create table**:

```bash
mysql -u root -p your_database < create_product_cross_reference_table.sql
```

2. **Populate with existing data**:

```bash
mysql -u root -p your_database < populate_cross_reference_data.sql
```

3. **Verify**:

```sql
SELECT COUNT(*) FROM product_cross_reference;
SELECT sync_status, COUNT(*) FROM product_cross_reference GROUP BY sync_status;
```

**References**:

- Schema: `create_product_cross_reference_table.sql`
- Requirements: 1.1, 1.3, 8.4

---

### 7. Dashboard Not Showing Updated Names

**Symptoms**:

- Sync completed successfully
- Database has correct names
- Dashboard still shows old names

**Solutions**:

1. **Clear cache**:

```bash
rm -rf cache/*
```

2. **Check API endpoint**:

```bash
curl http://localhost/api/analytics.php?action=getProducts
```

3. **Verify database query**:

```php
php -r "
require 'config.php';
\$stmt = \$pdo->query('SELECT dp.sku_ozon, dp.name, pcr.cached_name
    FROM dim_products dp
    LEFT JOIN product_cross_reference pcr ON dp.sku_ozon = pcr.sku_ozon
    LIMIT 5');
print_r(\$stmt->fetchAll());
"
```

4. **Test API endpoint**:

```bash
php tests/test_api_endpoints_mdm.php
```

**References**:

- API: `api/analytics-enhanced.php`
- Requirements: 1.2, 1.3, 3.1

---

### 8. High Number of Failed Syncs

**Symptoms**:

```
Sync report shows many failed products
sync_status = 'failed' for many records
```

**Diagnosis**:

```php
require 'src/SyncErrorHandler.php';
$handler = new SyncErrorHandler($pdo);

// Get error statistics
$stats = $handler->getErrorStats();
print_r($stats);

// Get specific errors
$errors = $handler->getErrorsByType('api_timeout');
print_r($errors);
```

**Solutions**:

1. **Retry failed products**:

```php
require 'src/SafeSyncEngine.php';
$engine = new SafeSyncEngine($pdo);

// Get failed product IDs
$stmt = $pdo->query("SELECT inventory_product_id FROM product_cross_reference WHERE sync_status = 'failed'");
$failedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Retry
$result = $engine->syncSpecificProducts($failedIds);
```

2. **Reset failed status**:

```sql
UPDATE product_cross_reference
SET sync_status = 'pending'
WHERE sync_status = 'failed'
AND updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY);
```

3. **Check error patterns**:

```php
$errorHandler = new SyncErrorHandler($pdo);
$patterns = $errorHandler->getErrorPatterns();
// Analyze common error types
```

**References**:

- Requirements: 3.4, 8.1

---

### 9. Performance Issues with Large Datasets

**Symptoms**:

- Slow query execution
- Dashboard takes long to load
- Sync script runs for hours

**Diagnosis**:

```sql
-- Check table sizes
SELECT
    table_name,
    table_rows,
    ROUND(data_length / 1024 / 1024, 2) as data_mb
FROM information_schema.tables
WHERE table_schema = 'your_database'
AND table_name IN ('product_cross_reference', 'dim_products', 'inventory_data');

-- Check index usage
EXPLAIN SELECT * FROM product_cross_reference WHERE inventory_product_id = '123456';
```

**Solutions**:

1. **Add missing indexes**:

```sql
-- Check existing indexes
SHOW INDEX FROM product_cross_reference;

-- Add if missing
CREATE INDEX idx_sync_status ON product_cross_reference(sync_status);
CREATE INDEX idx_updated_at ON product_cross_reference(updated_at);
```

2. **Optimize queries**:

```sql
-- Bad (full table scan)
SELECT * FROM product_cross_reference WHERE cached_name LIKE '%product%';

-- Good (uses index)
SELECT * FROM product_cross_reference WHERE inventory_product_id = '123456';
```

3. **Update table statistics**:

```sql
ANALYZE TABLE product_cross_reference;
ANALYZE TABLE dim_products;
```

4. **Use batch processing**:

```php
$engine->setBatchSize(10);  // Process fewer at a time
```

**References**:

- Requirements: 2.4

---

### 10. Data Inconsistencies Between Tables

**Symptoms**:

- Products in inventory but not in dim_products
- Orphaned cross-references
- Mismatched product counts

**Diagnosis**:

```sql
-- Find products in inventory but not in cross-reference
SELECT COUNT(*)
FROM inventory_data i
LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
WHERE pcr.id IS NULL AND i.product_id != 0;

-- Find orphaned cross-references
SELECT COUNT(*)
FROM product_cross_reference pcr
LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
WHERE dp.sku_ozon IS NULL;
```

**Solutions**:

1. **Sync missing products**:

```bash
php sync-real-product-names-v2.php --sync-missing
```

2. **Clean orphaned records**:

```sql
-- Backup first!
DELETE pcr FROM product_cross_reference pcr
LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
WHERE dp.sku_ozon IS NULL
AND pcr.created_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

3. **Run validation**:

```bash
php validate_sync_results.php
```

**References**:

- Validation: `validate_sync_results.php`
- Requirements: 8.2, 8.3

---

## Diagnostic Commands

### Quick Health Check

```bash
# Run comprehensive health check
php -r "
require 'config.php';

echo '=== MDM System Health Check ===\n\n';

// Check tables exist
\$tables = ['product_cross_reference', 'dim_products', 'inventory_data'];
foreach (\$tables as \$table) {
    \$result = \$pdo->query(\"SHOW TABLES LIKE '\$table'\")->fetch();
    echo \$table . ': ' . (\$result ? 'OK' : 'MISSING') . \"\n\";
}

echo \"\n=== Sync Status ===\n\";
\$stmt = \$pdo->query('SELECT sync_status, COUNT(*) as count FROM product_cross_reference GROUP BY sync_status');
foreach (\$stmt->fetchAll() as \$row) {
    echo \$row['sync_status'] . ': ' . \$row['count'] . \"\n\";
}

echo \"\n=== Recent Errors ===\n\";
\$stmt = \$pdo->query('SELECT COUNT(*) as count FROM sync_errors WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)');
echo 'Last 24h: ' . \$stmt->fetch()['count'] . \"\n\";
"
```

### Test All Components

```bash
# Run all tests
php run_all_mdm_tests.php

# Run specific test suites
php tests/SafeSyncEngineTest.php
php tests/DataTypeCompatibilityTest.php
php tests/RegressionTest.php
```

### Monitor Sync Progress

```bash
# Watch sync status in real-time
watch -n 5 'mysql -u root -p your_database -e "SELECT sync_status, COUNT(*) FROM product_cross_reference GROUP BY sync_status"'
```

## Getting Help

### Log Files

Check these log files for detailed error information:

- `logs/sync_errors.log` - Sync operation errors
- `logs/api_errors.log` - API connection errors
- `logs/sql_errors.log` - Database query errors

### Debug Mode

Enable debug mode for verbose output:

```php
// In sync script
define('DEBUG_MODE', true);

// Or via environment
export MDM_DEBUG=1
php sync-real-product-names-v2.php
```

### Support Resources

- Requirements Document: `.kiro/specs/mdm-product-system/requirements.md`
- Design Document: `.kiro/specs/mdm-product-system/design.md`
- Test Documentation: `tests/README.md`
- API Documentation: `docs/API_REFERENCE.md`

## Prevention

### Regular Maintenance

```bash
# Weekly tasks
0 2 * * 0 php sync-real-product-names-v2.php
0 3 * * 0 php validate_sync_results.php

# Monthly tasks
0 4 1 * * mysql -u root -p your_database -e "ANALYZE TABLE product_cross_reference, dim_products"
```

### Monitoring Setup

See `MDM_MONITORING_GUIDE.md` for setting up automated monitoring and alerts.

## References

- Requirements: 1.1, 2.1, 3.1, 8.1, 8.2, 8.3
- Design Document: `design.md`
- Schema Changes: `MDM_DATABASE_SCHEMA_CHANGES.md`
- Classes Guide: `MDM_CLASSES_USAGE_GUIDE.md`
