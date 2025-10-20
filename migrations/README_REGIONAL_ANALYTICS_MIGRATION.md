# Regional Analytics Migration

## Overview

This migration creates the database schema for the Regional Sales Analytics system, which enables analysis of ЭТОНОВО brand sales across different marketplaces (Ozon, Wildberries) with regional breakdown capabilities.

## Migration Files

- `add_regional_analytics_schema.sql` - Main migration file
- `rollback_regional_analytics_schema.sql` - Rollback migration
- `validate_regional_analytics_schema.sql` - Validation queries
- `apply_regional_analytics_migration.php` - PHP migration runner
- `rollback_regional_analytics_migration.php` - PHP rollback runner

## Tables Created

### 1. ozon_regional_sales

Stores regional sales data from Ozon API integration.

**Key Features:**

- Regional breakdown of sales data
- Product mapping to internal IDs
- Performance optimized indexes
- Foreign key relationship to dim_products

**Indexes:**

- Date range queries
- Regional filtering
- Product analytics
- Marketplace comparisons

### 2. regions

Reference table for regional data and federal districts.

**Key Features:**

- Regional codes and names
- Federal district mapping
- Priority-based reporting
- Economic zone classification

**Pre-populated Data:**

- Top 10 Russian regions by priority
- Federal district mappings
- Active/inactive status

### 3. regional_analytics_cache

Performance optimization cache for analytics queries.

**Key Features:**

- JSON-based cache storage
- Expiration management
- Query-specific caching
- Filter-aware cache keys

## Views Created

### 1. v_regional_sales_summary

Aggregated view of regional sales data with key metrics.

### 2. v_marketplace_comparison

Comparison view between Ozon and Wildberries sales data.

## Prerequisites

- MySQL 5.7+ or MariaDB 10.2+
- `dim_products` table must exist (for foreign key constraint)
- PHP 7.4+ (for migration runners)

## Running the Migration

### Option 1: Using PHP Runner (Recommended)

```bash
php apply_regional_analytics_migration.php
```

### Option 2: Direct SQL Execution

```bash
mysql -u username -p database_name < migrations/add_regional_analytics_schema.sql
```

## Validation

After running the migration, validate the installation:

```bash
mysql -u username -p database_name < migrations/validate_regional_analytics_schema.sql
```

Or use the PHP runner which includes automatic validation.

## Rollback

If you need to remove the regional analytics schema:

### Option 1: Using PHP Rollback (Recommended)

```bash
php rollback_regional_analytics_migration.php
```

### Option 2: Direct SQL Execution

```bash
mysql -u username -p database_name < migrations/rollback_regional_analytics_schema.sql
```

**⚠️ Warning:** Rollback will permanently delete all regional analytics data!

## Performance Considerations

### Indexes

The migration creates comprehensive indexes for:

- Date range queries (most common)
- Regional filtering
- Product-based analytics
- Marketplace comparisons
- Cache optimization

### Expected Query Patterns

- Date range + region filtering
- Product performance across regions
- Marketplace comparison queries
- Federal district aggregations

### Cache Strategy

The `regional_analytics_cache` table is designed to cache:

- Expensive aggregation queries
- API response data
- Dashboard widget data
- Report generation results

## Integration Points

### Existing Tables

- `dim_products` - Product master data
- `fact_orders` - Existing sales data (Wildberries)
- `dim_sources` - Marketplace source definitions

### API Integration

- Ozon Seller API data will populate `ozon_regional_sales`
- Regional reference data in `regions` table
- Cache optimization for dashboard performance

## Monitoring

### Key Metrics to Monitor

- `ozon_regional_sales` table growth rate
- Cache hit/miss ratios
- Query performance on date ranges
- Regional data completeness

### Maintenance Tasks

- Regular cache cleanup (expired entries)
- Index optimization based on query patterns
- Regional reference data updates
- Performance monitoring

## Troubleshooting

### Common Issues

1. **Foreign Key Constraint Fails**

   - Ensure `dim_products` table exists
   - Check product_id references are valid

2. **Migration Timeout**

   - Run migration during low-traffic periods
   - Consider running statements individually

3. **Index Creation Slow**

   - Normal for large datasets
   - Monitor MySQL process list

4. **Cache Table Growing Too Large**
   - Implement regular cleanup job
   - Adjust expiration times

### Recovery Procedures

1. **Partial Migration Failure**

   ```bash
   php rollback_regional_analytics_migration.php
   # Fix issues, then re-run
   php apply_regional_analytics_migration.php
   ```

2. **Data Corruption**
   - Restore from backup
   - Re-run migration
   - Validate data integrity

## Next Steps

After successful migration:

1. **Implement SalesAnalyticsService class**
2. **Create API endpoints for regional data**
3. **Set up Ozon API integration**
4. **Build dashboard frontend components**
5. **Configure caching strategies**
6. **Set up monitoring and alerting**

## Requirements Satisfied

This migration satisfies requirement **5.3** from the Regional Sales Analytics specification:

- Database schema for regional analytics
- Performance optimization through indexes
- Regional reference data structure
- Integration points for API data
