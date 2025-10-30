# Query Optimization Quick Start Guide

## Quick Setup (5 minutes)

### 1. Apply Optimizations

```bash
bash scripts/apply_query_optimizations.sh
```

### 2. Setup Auto-Refresh (Cron)

```bash
# Edit crontab
crontab -e

# Add this line (refresh every 10 minutes)
*/10 * * * * PGPASSWORD='MiCore2025Secure' psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT refresh_dashboard_cache();" >> /var/log/warehouse_cache.log 2>&1
```

### 3. Test Performance

```bash
# Test warehouse summary (should be < 50ms)
time curl "http://localhost/api/inventory/detailed-stock-optimized.php?action=warehouses"

# Test with caching
curl "http://localhost/api/inventory/detailed-stock-optimized.php?action=warehouses" | jq '.meta.cached'
```

## Quick Commands

### Check Optimization Status

```sql
-- Count indexes
SELECT COUNT(*) FROM pg_indexes WHERE schemaname = 'public' AND indexname LIKE 'idx_%';

-- Check materialized view
SELECT COUNT(*) FROM mv_warehouse_summary;

-- View slow queries
SELECT * FROM v_slow_queries LIMIT 5;
```

### Performance Monitoring

```bash
# API overview
curl "http://localhost/api/performance-monitor.php?action=overview" | jq

# Slow queries
curl "http://localhost/api/performance-monitor.php?action=slow_queries" | jq

# Cache hit ratio
curl "http://localhost/api/performance-monitor.php?action=cache_hit_ratio" | jq
```

### Cache Management

```bash
# View cache stats
curl "http://localhost/api/inventory/detailed-stock-optimized.php?action=cache_stats" | jq

# Clear cache
curl "http://localhost/api/inventory/detailed-stock-optimized.php?action=clear_cache"
```

### Manual Maintenance

```sql
-- Refresh materialized view
REFRESH MATERIALIZED VIEW CONCURRENTLY mv_warehouse_summary;

-- Update statistics
ANALYZE inventory;
ANALYZE dim_products;
ANALYZE warehouse_sales_metrics;

-- Check index usage
SELECT * FROM v_index_usage_stats WHERE tablename = 'inventory' ORDER BY index_scans DESC LIMIT 10;
```

## Performance Targets

| Metric            | Target  | Command to Check                        |
| ----------------- | ------- | --------------------------------------- |
| Warehouse Summary | < 50ms  | `time curl "...?action=warehouses"`     |
| Product Search    | < 100ms | `time curl "...?search=product"`        |
| Stock Filtering   | < 100ms | `time curl "...?stock_status=critical"` |
| Cache Hit Ratio   | > 95%   | `SELECT * FROM v_cache_hit_ratio`       |

## Troubleshooting

### Slow Queries

```sql
-- Find slow queries
SELECT * FROM v_slow_queries WHERE mean_time_ms > 100;

-- Check if query uses indexes
EXPLAIN ANALYZE SELECT * FROM inventory WHERE product_id = 123;
```

### Cache Issues

```bash
# Check cache directory
ls -lh /tmp/warehouse_cache/

# Check cache stats
curl "http://localhost/api/inventory/detailed-stock-optimized.php?action=cache_stats"

# Clear and rebuild
curl "http://localhost/api/inventory/detailed-stock-optimized.php?action=clear_cache"
```

### Materialized View Not Updating

```sql
-- Check last refresh
SELECT calculated_at FROM mv_warehouse_summary LIMIT 1;

-- Manual refresh
REFRESH MATERIALIZED VIEW mv_warehouse_summary;

-- Check cron job
crontab -l | grep refresh_dashboard_cache
```

## Common Issues

### Issue: pg_stat_statements not available

**Solution:** Requires superuser. Use `query_performance_log` table instead.

### Issue: Materialized view is stale

**Solution:** Setup cron job or refresh manually.

### Issue: Cache directory permission denied

**Solution:** `chmod 755 /tmp/warehouse_cache`

### Issue: Slow queries still present

**Solution:** Check EXPLAIN ANALYZE output and verify indexes are being used.

## Quick Reference

### API Endpoints

-   Optimized Stock API: `/api/inventory/detailed-stock-optimized.php`
-   Performance Monitor: `/api/performance-monitor.php`

### Database Views

-   `v_slow_queries` - Slow query monitoring
-   `v_index_usage_stats` - Index usage statistics
-   `v_table_stats` - Table health metrics

### Materialized Views

-   `mv_warehouse_summary` - Warehouse aggregations

### Functions

-   `refresh_dashboard_cache()` - Refresh all caches

## Next Steps

1. ✅ Verify optimizations are working
2. ✅ Setup cron job for auto-refresh
3. ✅ Monitor performance metrics
4. ⏳ Consider Redis for production caching
5. ⏳ Setup alerts for slow queries

For detailed information, see: `docs/QUERY_OPTIMIZATION_GUIDE.md`
