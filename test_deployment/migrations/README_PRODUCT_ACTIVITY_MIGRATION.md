# Product Activity Tracking Migration

## Overview

This migration adds product activity tracking functionality to support filtering of active Ozon products. The system currently processes all 176 products but only 48 are actually active in sales. This migration enables the system to track and filter products based on their activity status.

## Migration Details

### Version: 1.0

### Date: 2025-01-15

### Migration Name: `add_product_activity_tracking_v1.0`

## Changes Made

### 1. dim_products Table Updates

Added three new columns to track product activity:

```sql
ALTER TABLE dim_products
ADD COLUMN is_active BOOLEAN DEFAULT FALSE COMMENT 'Флаг активности товара (видимый, обработанный, с остатками)',
ADD COLUMN activity_checked_at TIMESTAMP NULL COMMENT 'Время последней проверки активности товара',
ADD COLUMN activity_reason VARCHAR(255) NULL COMMENT 'Причина статуса активности (для отладки)';
```

### 2. New Indexes for Performance

```sql
ADD INDEX idx_is_active (is_active),
ADD INDEX idx_activity_checked (activity_checked_at),
ADD INDEX idx_active_updated (is_active, updated_at),
ADD INDEX idx_active_sku (is_active, sku_ozon);
```

### 3. New product_activity_log Table

Created a comprehensive logging table to track all activity status changes:

```sql
CREATE TABLE product_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    external_sku VARCHAR(255) NOT NULL,
    previous_status BOOLEAN NULL,
    new_status BOOLEAN NOT NULL,
    reason VARCHAR(255) NULL,
    criteria_details JSON NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- ... indexes and constraints
);
```

## Activity Criteria

A product is considered active when ALL of the following criteria are met:

1. **Visibility**: `visibility = "VISIBLE"` (from Ozon API)
2. **State**: `state = "processed"` (product is processed and ready for sale)
3. **Stock**: `present > 0` (has available inventory)
4. **Pricing**: Has valid pricing information

## Files Included

### Migration Files

- `migrations/add_product_activity_tracking.sql` - Main migration script
- `migrations/rollback_product_activity_tracking.sql` - Rollback script
- `migrations/validate_product_activity_tracking.sql` - Validation script

### Application Script

- `apply_product_activity_migration.sh` - Safe migration application script

### Documentation

- `migrations/README_PRODUCT_ACTIVITY_MIGRATION.md` - This file

## Usage

### Apply Migration

```bash
# Standard application
./apply_product_activity_migration.sh

# Dry run (check without applying)
./apply_product_activity_migration.sh --dry-run

# Validate existing migration
./apply_product_activity_migration.sh --validate
```

### Rollback Migration

```bash
# Rollback if needed
./apply_product_activity_migration.sh --rollback
```

### Manual Application

```bash
# Apply migration manually
mysql mi_core_db < migrations/add_product_activity_tracking.sql

# Validate migration
mysql mi_core_db < migrations/validate_product_activity_tracking.sql

# Rollback if needed
mysql mi_core_db < migrations/rollback_product_activity_tracking.sql
```

## Post-Migration Steps

After applying this migration, the following components need to be updated:

### 1. ETL Process Updates

- Update OzonExtractor to check product activity criteria
- Implement ProductActivityChecker class
- Update product normalization to set activity status

### 2. Dashboard API Updates

- Modify inventory-analytics.php to filter by `is_active = 1`
- Add `active_only` parameter to all endpoints (default: true)
- Update all statistics calculations to use active products only

### 3. Database Query Updates

All dashboard queries should be updated to include:

```sql
WHERE is_active = 1
```

## Expected Results

After full implementation:

- Dashboard will show data for ~48 active products instead of 176
- System performance will improve due to reduced data processing
- More accurate inventory analytics and recommendations
- Better resource utilization

## Validation Checks

The migration includes comprehensive validation:

1. ✅ Migration log entry exists and is completed
2. ✅ All 3 new columns added to dim_products
3. ✅ product_activity_log table created
4. ✅ All indexes created successfully
5. ✅ Foreign key constraint established
6. ✅ Existing products initialized with activity status
7. ✅ Initial activity log entries created

## Troubleshooting

### Common Issues

1. **Migration already applied**

   - The script detects and handles this gracefully
   - Check migration_log table for status

2. **Foreign key constraint errors**

   - Ensure dim_products table exists and has proper structure
   - Check for orphaned records

3. **Index creation failures**
   - May indicate existing indexes with same names
   - Check SHOW INDEX FROM dim_products;

### Recovery

If migration fails:

1. Check error messages in migration output
2. Review migration_log table for details
3. Use rollback script if needed
4. Restore from backup if necessary

## Backup Strategy

The migration script automatically creates backups:

- Location: `backup/dim_products_backup_YYYYMMDD_HHMMSS.sql`
- Includes: Full dim_products table structure and data
- Retention: Manual cleanup required

## Performance Impact

### Expected Improvements

- Faster dashboard queries (filtering ~48 vs 176 products)
- Reduced memory usage in ETL processes
- More efficient API responses

### Monitoring

- Monitor query execution times before/after
- Track memory usage during ETL processes
- Monitor API response times

## Security Considerations

- Migration uses transactions for atomicity
- Backup created before any changes
- Rollback capability available
- No sensitive data exposed in logs

## Compliance

- Follows existing migration patterns in the project
- Uses consistent naming conventions
- Includes comprehensive logging and validation
- Maintains data integrity with foreign key constraints
