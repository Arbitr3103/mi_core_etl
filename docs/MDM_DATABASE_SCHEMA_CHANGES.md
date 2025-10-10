# MDM Database Schema Changes Documentation

## Overview

This document describes all database schema changes made to fix critical issues in the MDM (Master Data Management) system.

## Problem Statement

The original system had several critical issues:

1. **Type incompatibility**: `dim_products.sku_ozon` was INT, but `inventory_data.product_id` needed VARCHAR for proper JOIN operations
2. **SQL errors**: DISTINCT with ORDER BY caused "Expression #1 of ORDER BY clause is not in SELECT list" errors
3. **Missing cross-references**: No reliable way to map product IDs across different API endpoints
4. **No fallback mechanism**: System failed completely when API was unavailable

## Schema Changes

### 1. New Table: product_cross_reference

**Purpose**: Unified mapping table for product IDs from different sources

```sql
CREATE TABLE product_cross_reference (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,

    -- Unified VARCHAR types to avoid JOIN errors
    inventory_product_id VARCHAR(50) NOT NULL,  -- From inventory_data
    analytics_product_id VARCHAR(50),           -- From analytics API
    ozon_product_id VARCHAR(50),               -- From product info API
    sku_ozon VARCHAR(50),                      -- For compatibility with dim_products

    -- Cached data for fallback
    cached_name VARCHAR(500),
    cached_brand VARCHAR(200),
    last_api_sync TIMESTAMP,
    sync_status ENUM('synced', 'pending', 'failed') DEFAULT 'pending',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Indexes for fast lookups
    INDEX idx_inventory_id (inventory_product_id),
    INDEX idx_ozon_id (ozon_product_id),
    INDEX idx_sku_ozon (sku_ozon),
    INDEX idx_sync_status (sync_status)
);
```

**Key Features**:

- All ID fields are VARCHAR(50) to prevent type mismatch errors
- Cached name/brand for fallback when API is unavailable
- Sync status tracking for monitoring
- Multiple indexes for efficient queries

**Migration Script**: `create_product_cross_reference_table.sql`

### 2. Modified Table: dim_products

**Changes Made**:

```sql
-- Change sku_ozon from INT to VARCHAR
ALTER TABLE dim_products
MODIFY COLUMN sku_ozon VARCHAR(50) NOT NULL;

-- Add cross-reference link
ALTER TABLE dim_products
ADD COLUMN cross_ref_id BIGINT,
ADD FOREIGN KEY (cross_ref_id) REFERENCES product_cross_reference(id);
```

**Why This Change**:

- VARCHAR allows proper JOIN with inventory_data.product_id (which can contain non-numeric values)
- Foreign key to cross_reference enables reliable product mapping
- Prevents SQL errors when joining tables with different data types

**Migration Script**: `migrate_dim_products_table.sql`

### 3. Data Population

**Initial Data Migration**:

```sql
-- Populate cross_reference from existing inventory data
INSERT INTO product_cross_reference (inventory_product_id, sku_ozon, sync_status)
SELECT DISTINCT
    CAST(i.product_id AS CHAR) as inventory_product_id,
    CAST(i.product_id AS CHAR) as sku_ozon,
    'pending' as sync_status
FROM inventory_data i
WHERE i.product_id != 0
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;
```

**Migration Script**: `populate_cross_reference_data.sql`

## Impact Analysis

### Before Changes

**Problems**:

```sql
-- This query FAILED with type mismatch error
SELECT i.product_id, dp.name
FROM inventory_data i
LEFT JOIN dim_products dp ON i.product_id = dp.sku_ozon  -- INT vs VARCHAR error
WHERE i.product_id != 0;

-- This query FAILED with ORDER BY error
SELECT DISTINCT i.product_id
FROM inventory_data i
ORDER BY i.quantity_present DESC;  -- Column not in SELECT list
```

### After Changes

**Solutions**:

```sql
-- Now works correctly with VARCHAR types
SELECT i.product_id, dp.name
FROM inventory_data i
LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
WHERE i.product_id != 0;

-- Fixed with proper GROUP BY
SELECT DISTINCT pcr.inventory_product_id, MAX(i.quantity_present) as max_quantity
FROM inventory_data i
JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
GROUP BY pcr.inventory_product_id
ORDER BY max_quantity DESC;
```

## Rollback Procedures

### If Issues Occur

1. **Backup First**:

```bash
mysqldump -u root -p your_database > backup_before_rollback.sql
```

2. **Remove Foreign Key**:

```sql
ALTER TABLE dim_products DROP FOREIGN KEY dim_products_ibfk_1;
ALTER TABLE dim_products DROP COLUMN cross_ref_id;
```

3. **Revert sku_ozon Type** (if needed):

```sql
-- Only if you need to revert to INT (not recommended)
ALTER TABLE dim_products MODIFY COLUMN sku_ozon INT NOT NULL;
```

4. **Drop Cross-Reference Table**:

```sql
DROP TABLE IF EXISTS product_cross_reference;
```

## Performance Considerations

### Index Usage

All queries should use indexes. Verify with EXPLAIN:

```sql
EXPLAIN SELECT * FROM product_cross_reference WHERE inventory_product_id = '123456';
-- Should show: key = idx_inventory_id

EXPLAIN SELECT * FROM product_cross_reference WHERE sync_status = 'pending';
-- Should show: key = idx_sync_status
```

### Query Optimization

**Bad** (table scan):

```sql
SELECT * FROM product_cross_reference WHERE cached_name LIKE '%product%';
```

**Good** (uses index):

```sql
SELECT * FROM product_cross_reference WHERE inventory_product_id = '123456';
```

## Maintenance

### Regular Tasks

1. **Clean up old failed syncs** (monthly):

```sql
DELETE FROM product_cross_reference
WHERE sync_status = 'failed'
AND updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY);
```

2. **Update statistics** (weekly):

```sql
ANALYZE TABLE product_cross_reference;
ANALYZE TABLE dim_products;
```

3. **Check for orphaned records**:

```sql
SELECT COUNT(*) FROM dim_products
WHERE cross_ref_id IS NOT NULL
AND cross_ref_id NOT IN (SELECT id FROM product_cross_reference);
```

## References

- Requirements: 1.1, 2.1, 3.1
- Design Document: `design.md`
- Migration Scripts: `migrations/` directory
- Test Coverage: `tests/DataTypeCompatibilityTest.php`
