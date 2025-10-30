# PostgreSQL Query Optimization Guide

## Overview

This guide covers the PostgreSQL query optimizations implemented for the warehouse dashboard system. The optimizations include:

1. **Composite Indexes** - For frequent query patterns
2. **Partial Indexes** - For filtered queries
3. **Covering Indexes** - To avoid table lookups
4. **Materialized Views** - For expensive aggregations
5. **Query Caching** - Application-level result caching
6. **Performance Monitoring** - Tools to track query performance

## Performance Improvements

Expected performance improvements after optimization:

| Query Type             | Before     | After    | Improvement       |
| ---------------------- | ---------- | -------- | ----------------- |
| Warehouse Summary      | 500-2000ms | 10-50ms  | **10-50x faster** |
| Product Search (ILIKE) | 200-800ms  | 20-80ms  | **5-10x faster**  |
| Stock Status Filtering | 150-500ms  | 30-100ms | **3-5x faster**   |
| JOIN Operations        | 100-300ms  | 30-100ms | **2-3x faster**   |

## Index Strategy

### 1. Composite Indexes

Composite indexes optimize queries that filter or join on multiple columns:

```sql
-- Warehouse aggregation queries
CREATE INDEX idx_inventory_warehouse_aggregation
ON inventory(warehouse_name, product_id, quantity_present, quantity_reserved)
WHERE quantity_present > 0 OR quantity_reserved > 0;

-- Product-warehouse lookups
CREATE INDEX idx_inventory_product_warehouse_lookup
ON inventory(product_id, warehouse_name, quantity_present, quantity_reserved, source);
```

**Use Case**: Queries like `SELECT * FROM inventory WHERE product_id = X AND warehouse_name = Y`

### 2. Partial Indexes

Partial indexes only index rows matching a condition, reducing index size and improving performance:

```sql
-- Critical stock levels
CREATE INDEX idx_inventory_critical_stock
ON inventory(product_id, warehouse_name, quantity_present, quantity_reserved)
WHERE (quantity_present - quantity_reserved) > 0
  AND (quantity_present - quantity_reserved) < 20;

-- Out of stock
CREATE INDEX idx_inventory_out_of_stock
ON inventory(product_id, warehouse_name, source)
WHERE (quantity_present - quantity_reserved) <= 0;
```

**Use Case**: Queries filtering by stock status (critical, low, out_of_stock)

### 3. Covering Indexes

Covering indexes include all columns needed by a query, avoiding table lookups:

```sql
-- Warehouse summary covering index
CREATE INDEX idx_inventory_warehouse_summary_covering
ON inventory(warehouse_name, product_id, quantity_present, quantity_reserved, source, stock_type);
```

**Use Case**: Queries that only need indexed columns (no table scan required)

### 4. Trigram Indexes

Trigram indexes optimize fuzzy text search (ILIKE queries):

```sql
-- Product name search
CREATE INDEX idx_dim_products_name_trgm
ON dim_products USING gin (product_name gin_trgm_ops);
```

**Use Case**: Product search queries like `WHERE product_name ILIKE '%search%'`

## Materialized Views

Materialized views cache expensive aggregation results:

### Warehouse Summary View

```sql
CREATE MATERIALIZED VIEW mv_warehouse_summary AS
SELECT
    warehouse_name,
    source,
    COUNT(DISTINCT dp.id) as product_count,
    SUM(i.quantity_present) as total_present,
    -- ... more aggregations
FROM inventory i
JOIN dim_products dp ON i.product_id = dp.id
GROUP BY warehouse_name, source;
```

**Refresh Strategy**:

-   Automatic: Every 10 minutes via cron
-   Manual: `REFRESH MATERIALIZED VIEW CONCURRENTLY mv_warehouse_summary;`
-   API: Call `SELECT refresh_dashboard_cache();`

**Benefits**:

-   Warehouse summary queries: 500ms → 10ms (50x faster)
-   Reduces database load during peak hours
-   Consistent response times

## Query Caching

Application-level caching using `QueryCache` class:

### Basic Usage

```php
use Database\QueryCache;

$cache = new QueryCache('/tmp/warehouse_cache', 300); // 5 minute TTL

// Cache a query result
$result = $cache->remember('warehouse_list', function() use ($pdo) {
    $stmt = $pdo->query("SELECT * FROM warehouses");
    return $stmt->fetchAll();
}, 300);
```

### Cache Strategies

1. **Short TTL (1-3 minutes)**: Frequently changing data (inventory levels)
2. **Medium TTL (5-10 minutes)**: Semi-static data (warehouse list, product info)
3. **Long TTL (30-60 minutes)**: Static data (configuration, reference data)

### Cache Invalidation

```php
// Clear specific cache entry
$cache->delete('warehouse_list');

// Clear by pattern
$cache->invalidatePattern('warehouse_*');

// Clear all cache
$cache->clear();

// Clear expired entries
$cache->clearExpired();
```

## Performance Monitoring

### 1. Monitoring Views

Three views are available for monitoring:

```sql
-- Slow queries (requires pg_stat_statements)
SELECT * FROM v_slow_queries LIMIT 10;

-- Index usage statistics
SELECT * FROM v_index_usage_stats WHERE tablename = 'inventory';

-- Table statistics and bloat
SELECT * FROM v_table_stats;
```

### 2. Performance Monitoring API

Access performance metrics via API:

```bash
# Overview
curl "http://localhost/api/performance-monitor.php?action=overview"

# Slow queries
curl "http://localhost/api/performance-monitor.php?action=slow_queries"

# Index usage
curl "http://localhost/api/performance-monitor.php?action=index_usage&table=inventory"

# Table statistics
curl "http://localhost/api/performance-monitor.php?action=table_stats"

# Query performance history
curl "http://localhost/api/performance-monitor.php?action=query_performance&hours=24"

# Cache hit ratio
curl "http://localhost/api/performance-monitor.php?action=cache_hit_ratio"
```

### 3. Query Performance Logging

All API queries are automatically logged to `query_performance_log` table:

```sql
-- Average query performance by endpoint
SELECT
    query_name,
    COUNT(*) as executions,
    ROUND(AVG(execution_time_ms), 2) as avg_ms,
    ROUND(MAX(execution_time_ms), 2) as max_ms
FROM query_performance_log
WHERE executed_at > NOW() - INTERVAL '24 hours'
GROUP BY query_name
ORDER BY avg_ms DESC;
```

## Optimization Checklist

### Initial Setup

-   [x] Apply migration: `migrations/014_optimize_postgresql_indexes.sql`
-   [x] Enable extensions: `pg_stat_statements`, `pg_trgm`
-   [x] Create materialized views
-   [x] Setup query cache directory
-   [x] Configure cron for materialized view refresh

### Ongoing Maintenance

-   [ ] Monitor slow queries weekly
-   [ ] Check index usage monthly
-   [ ] Vacuum and analyze tables regularly
-   [ ] Review cache hit ratios
-   [ ] Optimize queries with mean_exec_time > 100ms

### Performance Testing

```bash
# Test warehouse summary (should be < 50ms)
time curl "http://localhost/api/inventory/detailed-stock-optimized.php?action=warehouses"

# Test product search (should be < 100ms)
time curl "http://localhost/api/inventory/detailed-stock-optimized.php?search=product"

# Test stock filtering (should be < 100ms)
time curl "http://localhost/api/inventory/detailed-stock-optimized.php?stock_status=critical"
```

## Query Optimization Best Practices

### 1. Use Appropriate Indexes

```sql
-- ✅ Good: Use composite index for multi-column filters
SELECT * FROM inventory
WHERE product_id = 123 AND warehouse_name = 'Main';

-- ❌ Bad: Separate indexes on each column
```

### 2. Avoid SELECT \*

```sql
-- ✅ Good: Select only needed columns
SELECT product_id, warehouse_name, quantity_present
FROM inventory;

-- ❌ Bad: Select all columns
SELECT * FROM inventory;
```

### 3. Use Covering Indexes

```sql
-- ✅ Good: All columns in index (no table lookup)
SELECT warehouse_name, SUM(quantity_present)
FROM inventory
GROUP BY warehouse_name;

-- Index: (warehouse_name, quantity_present)
```

### 4. Leverage Partial Indexes

```sql
-- ✅ Good: Use partial index for filtered queries
SELECT * FROM inventory
WHERE (quantity_present - quantity_reserved) < 20;

-- Partial index: WHERE (quantity_present - quantity_reserved) < 20
```

### 5. Use Materialized Views for Aggregations

```sql
-- ✅ Good: Query materialized view
SELECT * FROM mv_warehouse_summary;

-- ❌ Bad: Expensive aggregation on every request
SELECT warehouse_name, COUNT(*), SUM(quantity)
FROM inventory
GROUP BY warehouse_name;
```

## Troubleshooting

### Slow Queries

1. Check if query is using indexes:

```sql
EXPLAIN ANALYZE SELECT * FROM inventory WHERE product_id = 123;
```

2. Look for:

    - Seq Scan (bad) vs Index Scan (good)
    - High execution time
    - Large row counts

3. Solutions:
    - Add appropriate index
    - Rewrite query to use existing indexes
    - Use materialized view for aggregations

### Low Cache Hit Ratio

Target: > 95% cache hit ratio

```sql
SELECT
    ROUND(100.0 * sum(blks_hit) / NULLIF(sum(blks_hit) + sum(blks_read), 0), 2) as cache_hit_ratio
FROM pg_stat_database
WHERE datname = 'mi_core_db';
```

Solutions:

-   Increase `shared_buffers` in postgresql.conf
-   Increase `effective_cache_size`
-   Add more RAM to server

### Unused Indexes

Find unused indexes:

```sql
SELECT
    schemaname,
    tablename,
    indexname,
    idx_scan,
    pg_size_pretty(pg_relation_size(indexrelid)) as size
FROM pg_stat_user_indexes
WHERE idx_scan = 0
  AND schemaname = 'public'
ORDER BY pg_relation_size(indexrelid) DESC;
```

Consider dropping unused indexes to save space and improve write performance.

## Configuration Recommendations

### postgresql.conf

```ini
# Memory settings
shared_buffers = 256MB          # 25% of RAM
effective_cache_size = 1GB      # 50-75% of RAM
work_mem = 16MB                 # Per operation
maintenance_work_mem = 128MB    # For VACUUM, CREATE INDEX

# Query planning
random_page_cost = 1.1          # For SSD storage
effective_io_concurrency = 200  # For SSD storage

# Monitoring
shared_preload_libraries = 'pg_stat_statements'
pg_stat_statements.track = all
pg_stat_statements.max = 10000

# Autovacuum (important for performance)
autovacuum = on
autovacuum_max_workers = 3
autovacuum_naptime = 1min
```

## Maintenance Schedule

### Daily

-   Monitor slow queries
-   Check cache hit ratios
-   Review query performance logs

### Weekly

-   Analyze table statistics
-   Review index usage
-   Check for table bloat

### Monthly

-   Vacuum full on large tables (during maintenance window)
-   Review and optimize slow queries
-   Update indexes based on usage patterns

## Support

For issues or questions:

1. Check monitoring views for performance metrics
2. Review query execution plans with EXPLAIN ANALYZE
3. Check application logs for errors
4. Contact database administrator for configuration changes
