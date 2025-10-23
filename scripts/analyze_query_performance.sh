#!/bin/bash

# ===================================================================
# PostgreSQL Query Performance Analysis Script
# Task 5.2: Проанализировать медленные запросы с помощью EXPLAIN ANALYZE
# ===================================================================

set -e  # Exit on any error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
LOG_FILE="$PROJECT_ROOT/logs/query_analysis_$(date +%Y%m%d_%H%M%S).log"
REPORT_FILE="$PROJECT_ROOT/logs/query_performance_report_$(date +%Y%m%d_%H%M%S).md"

# Create directories if they don't exist
mkdir -p "$(dirname "$LOG_FILE")"

# Load environment variables
if [ -f "$PROJECT_ROOT/.env" ]; then
    source "$PROJECT_ROOT/.env"
fi

# PostgreSQL connection parameters
PG_HOST="${PG_HOST:-localhost}"
PG_PORT="${PG_PORT:-5432}"
PG_USER="${PG_USER:-mi_core_user}"
PG_NAME="${PG_NAME:-mi_core_db}"

echo -e "${BLUE}=== PostgreSQL Query Performance Analysis ===${NC}"
echo "Starting analysis at: $(date)"
echo "Database: $PG_NAME"
echo "Host: $PG_HOST:$PG_PORT"
echo "Report file: $REPORT_FILE"
echo ""

# Function to log messages
log_message() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" | tee -a "$LOG_FILE"
}

# Function to execute SQL and capture output
execute_sql_to_file() {
    local sql_command=$1
    local description=$2
    local output_file=$3
    
    log_message "INFO" "Analyzing: $description"
    
    echo "## $description" >> "$output_file"
    echo "Generated at: $(date)" >> "$output_file"
    echo "" >> "$output_file"
    
    if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -c "$sql_command" >> "$output_file" 2>&1; then
        echo "" >> "$output_file"
        log_message "SUCCESS" "Completed: $description"
        return 0
    else
        echo "ERROR: Failed to execute query" >> "$output_file"
        echo "" >> "$output_file"
        log_message "ERROR" "Failed: $description"
        return 1
    fi
}

# Function to analyze dashboard queries
analyze_dashboard_queries() {
    log_message "INFO" "Analyzing dashboard queries..."
    
    # Critical stock query
    execute_sql_to_file "
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT 
    p.id,
    p.sku_ozon as sku,
    p.product_name as name,
    i.quantity_present as current_stock,
    i.warehouse_name,
    'critical' as stock_status
FROM dim_products p
JOIN inventory i ON p.id = i.product_id
WHERE i.quantity_present <= 5
  AND p.sku_ozon IS NOT NULL
ORDER BY i.quantity_present ASC, p.product_name
LIMIT 10;
" "Dashboard Critical Stock Query Analysis" "$REPORT_FILE"

    # Low stock query
    execute_sql_to_file "
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT 
    p.id,
    p.sku_ozon as sku,
    p.product_name as name,
    i.quantity_present as current_stock,
    i.warehouse_name,
    'low_stock' as stock_status
FROM dim_products p
JOIN inventory i ON p.id = i.product_id
WHERE i.quantity_present > 5 AND i.quantity_present <= 20
  AND p.sku_ozon IS NOT NULL
ORDER BY i.quantity_present ASC, p.product_name
LIMIT 10;
" "Dashboard Low Stock Query Analysis" "$REPORT_FILE"

    # Overstock query
    execute_sql_to_file "
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT 
    p.id,
    p.sku_ozon as sku,
    p.product_name as name,
    i.quantity_present as current_stock,
    i.warehouse_name,
    'overstock' as stock_status
FROM dim_products p
JOIN inventory i ON p.id = i.product_id
WHERE i.quantity_present > 100
  AND p.sku_ozon IS NOT NULL
ORDER BY i.quantity_present DESC, p.product_name
LIMIT 10;
" "Dashboard Overstock Query Analysis" "$REPORT_FILE"
}

# Function to analyze materialized view queries
analyze_materialized_view_queries() {
    log_message "INFO" "Analyzing materialized view queries..."
    
    # Dashboard materialized view query
    execute_sql_to_file "
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT *
FROM mv_dashboard_inventory
WHERE stock_status = 'critical'
ORDER BY current_stock ASC, name
LIMIT 10;
" "Materialized View Dashboard Query Analysis" "$REPORT_FILE"

    # Turnover analysis query
    execute_sql_to_file "
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT 
    product_id,
    sku_ozon,
    product_name,
    total_sold_30d,
    current_stock,
    days_of_stock,
    velocity_category
FROM mv_product_turnover_analysis
WHERE velocity_category = 'fast_moving'
  AND days_of_stock < 30
ORDER BY days_of_stock ASC
LIMIT 20;
" "Turnover Analysis Query Performance" "$REPORT_FILE"
}

# Function to analyze complex aggregation queries
analyze_aggregation_queries() {
    log_message "INFO" "Analyzing aggregation queries..."
    
    # Stock movements aggregation
    execute_sql_to_file "
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT 
    p.product_name,
    COUNT(*) as movement_count,
    SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as total_sold,
    AVG(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as avg_sale_quantity
FROM stock_movements sm
JOIN dim_products p ON sm.product_id = p.id
WHERE sm.movement_date >= CURRENT_DATE - INTERVAL '30 days'
  AND sm.movement_type IN ('sale', 'order')
GROUP BY p.id, p.product_name
HAVING SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) > 0
ORDER BY total_sold DESC
LIMIT 50;
" "Stock Movements Aggregation Query Analysis" "$REPORT_FILE"

    # Inventory summary by warehouse
    execute_sql_to_file "
EXPLAIN (ANALYZE, BUFFERS, FORMAT TEXT)
SELECT 
    i.warehouse_name,
    COUNT(*) as product_count,
    SUM(i.quantity_present) as total_stock,
    SUM(i.quantity_reserved) as total_reserved,
    COUNT(CASE WHEN i.quantity_present <= 5 THEN 1 END) as critical_count,
    COUNT(CASE WHEN i.quantity_present > 100 THEN 1 END) as overstock_count
FROM inventory i
JOIN dim_products p ON i.product_id = p.id
WHERE p.sku_ozon IS NOT NULL
GROUP BY i.warehouse_name
ORDER BY total_stock DESC;
" "Inventory Summary by Warehouse Analysis" "$REPORT_FILE"
}

# Function to analyze slow queries from pg_stat_statements
analyze_slow_queries() {
    log_message "INFO" "Analyzing slow queries from pg_stat_statements..."
    
    execute_sql_to_file "
SELECT 
    'Top 10 Slowest Queries by Mean Time' as analysis_type,
    '' as separator;

SELECT 
    LEFT(query, 100) as query_preview,
    calls,
    ROUND(total_time::numeric, 2) as total_time_ms,
    ROUND(mean_time::numeric, 2) as mean_time_ms,
    ROUND((100.0 * shared_blks_hit / NULLIF(shared_blks_hit + shared_blks_read, 0))::numeric, 2) as hit_percent
FROM pg_stat_statements 
WHERE mean_time > 10  -- queries taking more than 10ms on average
ORDER BY mean_time DESC
LIMIT 10;
" "Slow Queries Analysis from pg_stat_statements" "$REPORT_FILE"

    execute_sql_to_file "
SELECT 
    'Top 10 Most Called Queries' as analysis_type,
    '' as separator;

SELECT 
    LEFT(query, 100) as query_preview,
    calls,
    ROUND(total_time::numeric, 2) as total_time_ms,
    ROUND(mean_time::numeric, 2) as mean_time_ms,
    ROUND((100.0 * shared_blks_hit / NULLIF(shared_blks_hit + shared_blks_read, 0))::numeric, 2) as hit_percent
FROM pg_stat_statements 
ORDER BY calls DESC
LIMIT 10;
" "Most Called Queries Analysis" "$REPORT_FILE"
}

# Function to analyze index usage
analyze_index_usage() {
    log_message "INFO" "Analyzing index usage..."
    
    execute_sql_to_file "
SELECT 
    schemaname,
    tablename,
    indexname,
    idx_scan as scans,
    idx_tup_read as tuples_read,
    idx_tup_fetch as tuples_fetched,
    CASE 
        WHEN idx_scan = 0 THEN 'Never Used'
        WHEN idx_scan < 100 THEN 'Rarely Used'
        WHEN idx_scan < 1000 THEN 'Moderately Used'
        ELSE 'Frequently Used'
    END as usage_level,
    pg_size_pretty(pg_relation_size(indexrelid)) as index_size
FROM pg_stat_user_indexes
WHERE schemaname = 'public'
ORDER BY idx_scan DESC;
" "Index Usage Statistics" "$REPORT_FILE"

    execute_sql_to_file "
SELECT 
    'Unused Indexes (Potential for Removal)' as analysis_type,
    '' as separator;

SELECT 
    schemaname,
    tablename,
    indexname,
    pg_size_pretty(pg_relation_size(indexrelid)) as wasted_size
FROM pg_stat_user_indexes
WHERE schemaname = 'public'
  AND idx_scan = 0
  AND indexname NOT LIKE '%_pkey'  -- Exclude primary keys
ORDER BY pg_relation_size(indexrelid) DESC;
" "Unused Indexes Analysis" "$REPORT_FILE"
}

# Function to analyze table statistics
analyze_table_statistics() {
    log_message "INFO" "Analyzing table statistics..."
    
    execute_sql_to_file "
SELECT 
    schemaname,
    tablename,
    n_live_tup as live_tuples,
    n_dead_tup as dead_tuples,
    ROUND((n_dead_tup::numeric / NULLIF(n_live_tup + n_dead_tup, 0) * 100), 2) as dead_tuple_percent,
    last_vacuum,
    last_autovacuum,
    last_analyze,
    last_autoanalyze,
    seq_scan,
    seq_tup_read,
    idx_scan,
    idx_tup_fetch,
    CASE 
        WHEN seq_scan + COALESCE(idx_scan, 0) = 0 THEN 0
        ELSE ROUND((COALESCE(idx_scan, 0)::numeric / (seq_scan + COALESCE(idx_scan, 0)) * 100), 2)
    END as index_usage_percent
FROM pg_stat_user_tables
WHERE schemaname = 'public'
ORDER BY n_live_tup DESC;
" "Table Statistics and Health" "$REPORT_FILE"
}

# Function to generate recommendations
generate_recommendations() {
    log_message "INFO" "Generating performance recommendations..."
    
    execute_sql_to_file "
SELECT 
    table_name,
    total_size,
    performance_score,
    recommendations
FROM analyze_table_performance()
WHERE array_length(recommendations, 1) > 0
ORDER BY performance_score ASC;
" "Performance Recommendations" "$REPORT_FILE"

    # Add custom recommendations to the report
    cat >> "$REPORT_FILE" << 'EOF'

## Custom Performance Recommendations

### Based on Analysis Results:

1. **Index Optimization**
   - Review unused indexes for potential removal
   - Consider composite indexes for frequently joined columns
   - Add partial indexes for commonly filtered subsets

2. **Query Optimization**
   - Use materialized views for complex aggregations
   - Implement query result caching for dashboard data
   - Consider query rewriting for better performance

3. **Maintenance Tasks**
   - Schedule regular VACUUM and ANALYZE operations
   - Monitor dead tuple percentages
   - Update table statistics regularly

4. **Monitoring Setup**
   - Enable pg_stat_statements for ongoing query monitoring
   - Set up alerts for slow queries (>100ms)
   - Monitor index usage patterns

5. **Hardware Considerations**
   - Increase shared_buffers if cache hit ratio < 95%
   - Consider SSD storage for better I/O performance
   - Monitor connection pooling efficiency

EOF
}

# Function to create summary report
create_summary_report() {
    log_message "INFO" "Creating summary report..."
    
    # Add header to report
    cat > "$REPORT_FILE" << EOF
# PostgreSQL Query Performance Analysis Report

**Generated:** $(date)
**Database:** $PG_NAME
**Host:** $PG_HOST:$PG_PORT

---

EOF

    # Run all analyses
    analyze_dashboard_queries
    analyze_materialized_view_queries
    analyze_aggregation_queries
    analyze_slow_queries
    analyze_index_usage
    analyze_table_statistics
    generate_recommendations
    
    # Add footer
    cat >> "$REPORT_FILE" << EOF

---

**Analysis completed at:** $(date)
**Log file:** $LOG_FILE

EOF
}

# Main execution
main() {
    echo -e "${YELLOW}Step 1: Checking PostgreSQL connection...${NC}"
    
    # Test connection
    if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -c "SELECT version();" > /dev/null 2>&1; then
        echo -e "${GREEN}✅ PostgreSQL connection successful${NC}"
    else
        echo -e "${RED}❌ Cannot connect to PostgreSQL${NC}"
        exit 1
    fi
    
    echo -e "${YELLOW}Step 2: Enabling pg_stat_statements if needed...${NC}"
    
    # Enable pg_stat_statements extension
    if PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -c "CREATE EXTENSION IF NOT EXISTS pg_stat_statements;" >> "$LOG_FILE" 2>&1; then
        echo -e "${GREEN}✅ pg_stat_statements enabled${NC}"
    else
        echo -e "${YELLOW}⚠️ Could not enable pg_stat_statements (may already be enabled)${NC}"
    fi
    
    echo -e "${YELLOW}Step 3: Running query performance analysis...${NC}"
    
    create_summary_report
    
    echo -e "${GREEN}✅ Query performance analysis completed${NC}"
    
    echo ""
    echo -e "${GREEN}🎉 Analysis completed successfully!${NC}"
    echo -e "${BLUE}📊 Report generated: $REPORT_FILE${NC}"
    echo -e "${BLUE}📝 Log file: $LOG_FILE${NC}"
    echo ""
    
    # Show quick summary
    echo -e "${BLUE}=== QUICK SUMMARY ===${NC}"
    
    # Count of slow queries
    slow_count=$(PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -t -c "SELECT COUNT(*) FROM pg_stat_statements WHERE mean_time > 100;" 2>/dev/null || echo "0")
    echo "Slow queries (>100ms): $slow_count"
    
    # Count of unused indexes
    unused_count=$(PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -t -c "SELECT COUNT(*) FROM pg_stat_user_indexes WHERE schemaname = 'public' AND idx_scan = 0 AND indexname NOT LIKE '%_pkey';" 2>/dev/null || echo "0")
    echo "Unused indexes: $unused_count"
    
    # Database size
    db_size=$(PGPASSWORD="$PG_PASSWORD" psql -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$PG_NAME" -t -c "SELECT pg_size_pretty(pg_database_size(current_database()));" 2>/dev/null || echo "Unknown")
    echo "Database size: $db_size"
    
    echo ""
    echo -e "${BLUE}📖 Review the full report for detailed analysis and recommendations${NC}"
    
    log_message "SUCCESS" "Query performance analysis completed successfully"
}

# Handle script interruption
trap 'echo -e "\n${RED}Script interrupted. Check log: $LOG_FILE${NC}"; exit 1' INT TERM

# Run main function
main "$@"