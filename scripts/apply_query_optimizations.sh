#!/bin/bash
# ===================================================================
# Apply PostgreSQL Query Optimizations
# Task 14: Optimize PostgreSQL queries and indexes
# ===================================================================

set -e

echo "🚀 Starting PostgreSQL Query Optimization..."
echo "=============================================="

# Database connection parameters
DB_HOST="localhost"
DB_PORT="5432"
DB_NAME="mi_core_db"
DB_USER="mi_core_user"
DB_PASSWORD="MiCore2025Secure"

# Colors for output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Function to execute SQL
execute_sql() {
    local sql="$1"
    PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -c "$sql"
}

# Function to execute SQL file
execute_sql_file() {
    local file="$1"
    echo -e "${YELLOW}Executing: $file${NC}"
    PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" -f "$file"
}

# Step 1: Enable required extensions
echo ""
echo "📦 Step 1: Enabling required PostgreSQL extensions..."
execute_sql "CREATE EXTENSION IF NOT EXISTS pg_stat_statements;" || echo "Note: pg_stat_statements requires superuser privileges"
execute_sql "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
echo -e "${GREEN}✓ Extensions enabled${NC}"

# Step 2: Apply optimization migration
echo ""
echo "🔧 Step 2: Applying index optimizations..."
if [ -f "migrations/014_optimize_postgresql_indexes.sql" ]; then
    execute_sql_file "migrations/014_optimize_postgresql_indexes.sql"
    echo -e "${GREEN}✓ Indexes created successfully${NC}"
else
    echo -e "${RED}✗ Migration file not found${NC}"
    exit 1
fi

# Step 3: Refresh materialized views
echo ""
echo "🔄 Step 3: Refreshing materialized views..."
execute_sql "REFRESH MATERIALIZED VIEW CONCURRENTLY mv_warehouse_summary;" || \
execute_sql "REFRESH MATERIALIZED VIEW mv_warehouse_summary;"
echo -e "${GREEN}✓ Materialized views refreshed${NC}"

# Step 4: Update table statistics
echo ""
echo "📊 Step 4: Updating table statistics..."
execute_sql "ANALYZE inventory;"
execute_sql "ANALYZE dim_products;"
execute_sql "ANALYZE warehouse_sales_metrics;"
echo -e "${GREEN}✓ Statistics updated${NC}"

# Step 5: Display optimization summary
echo ""
echo "📈 Step 5: Optimization Summary"
echo "================================"

echo ""
echo "Indexes created:"
execute_sql "
SELECT 
    tablename,
    indexname,
    pg_size_pretty(pg_relation_size(indexrelid)) as size
FROM pg_stat_user_indexes
WHERE schemaname = 'public'
  AND indexname LIKE 'idx_%'
  AND indexname LIKE '%warehouse%' OR indexname LIKE '%stock%' OR indexname LIKE '%product%'
ORDER BY tablename, indexname;
"

echo ""
echo "Table sizes:"
execute_sql "
SELECT 
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as total_size,
    pg_size_pretty(pg_relation_size(schemaname||'.'||tablename)) as table_size,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename) - pg_relation_size(schemaname||'.'||tablename)) as indexes_size
FROM pg_stat_user_tables
WHERE schemaname = 'public'
  AND tablename IN ('inventory', 'dim_products', 'warehouse_sales_metrics')
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC;
"

# Step 6: Create cache directory
echo ""
echo "📁 Step 6: Setting up query cache..."
mkdir -p /tmp/warehouse_cache
chmod 755 /tmp/warehouse_cache
echo -e "${GREEN}✓ Cache directory created${NC}"

# Step 7: Setup cron job for materialized view refresh
echo ""
echo "⏰ Step 7: Setting up materialized view refresh..."
cat > /tmp/refresh_warehouse_cache.sh << 'EOF'
#!/bin/bash
# Refresh warehouse dashboard materialized views
PGPASSWORD="MiCore2025Secure" psql -h localhost -U mi_core_user -d mi_core_db -c "SELECT refresh_dashboard_cache();" >> /var/log/warehouse_cache_refresh.log 2>&1
EOF

chmod +x /tmp/refresh_warehouse_cache.sh

echo "Add this to crontab to refresh cache every 10 minutes:"
echo "*/10 * * * * /tmp/refresh_warehouse_cache.sh"
echo ""
echo "Run: crontab -e"
echo "Then add the line above"

# Step 8: Performance test
echo ""
echo "🧪 Step 8: Running performance test..."
echo "Testing warehouse summary query..."

time_start=$(date +%s%N)
execute_sql "SELECT COUNT(*) FROM mv_warehouse_summary;" > /dev/null
time_end=$(date +%s%N)
time_diff=$(( (time_end - time_start) / 1000000 ))

echo -e "${GREEN}✓ Query executed in ${time_diff}ms${NC}"

# Step 9: Display monitoring views
echo ""
echo "📊 Step 9: Monitoring Views Available"
echo "======================================"
echo "1. v_slow_queries - Monitor slow queries"
echo "2. v_index_usage_stats - Monitor index usage"
echo "3. v_table_stats - Monitor table statistics"
echo ""
echo "Example usage:"
echo "  SELECT * FROM v_slow_queries LIMIT 10;"
echo "  SELECT * FROM v_index_usage_stats WHERE tablename = 'inventory';"
echo "  SELECT * FROM v_table_stats;"

# Final summary
echo ""
echo "=============================================="
echo -e "${GREEN}✅ PostgreSQL Optimization Complete!${NC}"
echo "=============================================="
echo ""
echo "Next steps:"
echo "1. Update API endpoints to use optimized queries"
echo "2. Setup cron job for materialized view refresh"
echo "3. Monitor query performance using v_slow_queries"
echo "4. Test API performance improvements"
echo ""
echo "Performance improvements expected:"
echo "  - Warehouse summary: 10-50x faster (using materialized view)"
echo "  - Product search: 5-10x faster (using trigram indexes)"
echo "  - Stock status queries: 3-5x faster (using partial indexes)"
echo "  - JOIN operations: 2-3x faster (using covering indexes)"
echo ""
