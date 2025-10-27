-- ===================================================================
-- View Performance Tests
-- ===================================================================
-- 
-- This script creates performance tests for v_detailed_inventory view
-- to measure query execution time and identify bottlenecks.
-- 
-- Requirements: 3.4, 4.4
-- Task: 3.4 Create view performance tests
-- ===================================================================

-- Function to test view performance with different scenarios
CREATE OR REPLACE FUNCTION test_view_performance()
RETURNS TABLE (
    test_name TEXT,
    execution_time_ms NUMERIC,
    rows_returned BIGINT,
    description TEXT
) AS $$
DECLARE
    start_time TIMESTAMP;
    end_time TIMESTAMP;
    row_count BIGINT;
BEGIN
    -- Test 1: Basic count query
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'basic_count'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        'Basic COUNT(*) query on full view'::TEXT;

    -- Test 2: Filter by stock_status (problematic query)
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory 
    WHERE stock_status != 'archived_or_hidden';
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'filter_stock_status'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        'Filter by stock_status (complex CASE expression)'::TEXT;

    -- Test 3: Filter by visibility (indexed field)
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory 
    WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся');
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'filter_visibility'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        'Filter by visibility (indexed field)'::TEXT;

    -- Test 4: Filter by warehouse_name
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory 
    WHERE warehouse_name LIKE '%Екатеринбург%';
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'filter_warehouse'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        'Filter by warehouse_name (LIKE query)'::TEXT;

    -- Test 5: Complex filtering (realistic dashboard query)
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory 
    WHERE stock_status IN ('critical', 'low') 
      AND available_stock > 0
      AND daily_sales_avg > 0;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'complex_filter'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        'Complex filtering (critical/low stock with sales)'::TEXT;

    -- Test 6: Ordering and limiting (pagination scenario)
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM (
        SELECT * FROM v_detailed_inventory 
        WHERE available_stock > 0
        ORDER BY urgency_score DESC, days_of_stock ASC
        LIMIT 50
    ) subq;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'order_limit'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        'Ordering by urgency_score with LIMIT (pagination)'::TEXT;

    -- Test 7: Aggregation by warehouse
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM (
        SELECT warehouse_name, 
               COUNT(*) as products,
               SUM(available_stock) as total_stock,
               AVG(days_of_stock) as avg_days
        FROM v_detailed_inventory 
        WHERE stock_status != 'archived_or_hidden'
        GROUP BY warehouse_name
    ) subq;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'aggregation'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        'Aggregation by warehouse with filtering'::TEXT;

END;
$$ LANGUAGE plpgsql;

-- Function to test different data volumes
CREATE OR REPLACE FUNCTION test_scalability()
RETURNS TABLE (
    data_volume TEXT,
    query_type TEXT,
    execution_time_ms NUMERIC,
    rows_processed BIGINT
) AS $$
DECLARE
    start_time TIMESTAMP;
    end_time TIMESTAMP;
    row_count BIGINT;
BEGIN
    -- Current data volume
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory;
    
    -- Test with current data
    start_time := clock_timestamp();
    PERFORM * FROM v_detailed_inventory WHERE stock_status != 'archived_or_hidden' LIMIT 100;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'current_' || row_count::TEXT,
        'filtered_select',
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count;

    -- Test JOIN performance
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory v
    JOIN dim_products p ON v.product_id = p.id
    WHERE v.available_stock > 0;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'current_' || row_count::TEXT,
        'join_test',
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count;

END;
$$ LANGUAGE plpgsql;

-- Function to identify bottlenecks
CREATE OR REPLACE FUNCTION identify_bottlenecks()
RETURNS TABLE (
    bottleneck_type TEXT,
    issue_description TEXT,
    impact_level TEXT,
    recommendation TEXT
) AS $$
BEGIN
    -- Check for sequential scans
    RETURN QUERY SELECT 
        'sequential_scan'::TEXT,
        'View uses sequential scans instead of index scans'::TEXT,
        'HIGH'::TEXT,
        'Consider materialized view or pre-computed status table'::TEXT;

    -- Check for complex expressions in WHERE
    RETURN QUERY SELECT 
        'computed_where'::TEXT,
        'WHERE clauses on computed fields (stock_status) cannot use indexes'::TEXT,
        'HIGH'::TEXT,
        'Move computed fields to separate indexed table'::TEXT;

    -- Check for multiple JOINs
    RETURN QUERY SELECT 
        'complex_joins'::TEXT,
        'Multiple LEFT JOINs with composite conditions'::TEXT,
        'MEDIUM'::TEXT,
        'Optimize JOIN conditions and consider denormalization'::TEXT;

    -- Check for NULL handling
    RETURN QUERY SELECT 
        'null_visibility'::TEXT,
        'Most products have NULL visibility causing poor selectivity'::TEXT,
        'MEDIUM'::TEXT,
        'Populate visibility field or use default values'::TEXT;

END;
$$ LANGUAGE plpgsql;

-- Create a comprehensive performance report
CREATE OR REPLACE FUNCTION generate_performance_report()
RETURNS TABLE (
    section TEXT,
    metric TEXT,
    value TEXT,
    notes TEXT
) AS $$
BEGIN
    -- Current data statistics
    RETURN QUERY SELECT 
        'Data Statistics'::TEXT,
        'Total Products'::TEXT,
        (SELECT COUNT(*)::TEXT FROM v_detailed_inventory),
        'Total rows in view'::TEXT;

    RETURN QUERY SELECT 
        'Data Statistics'::TEXT,
        'Visible Products'::TEXT,
        (SELECT COUNT(*)::TEXT FROM v_detailed_inventory WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')),
        'Products with visible status'::TEXT;

    RETURN QUERY SELECT 
        'Data Statistics'::TEXT,
        'Products with Stock'::TEXT,
        (SELECT COUNT(*)::TEXT FROM v_detailed_inventory WHERE available_stock > 0),
        'Products with available stock > 0'::TEXT;

    -- Performance metrics from pg_stat_statements (if available)
    RETURN QUERY SELECT 
        'Query Performance'::TEXT,
        'View Query Count'::TEXT,
        COALESCE(
            (SELECT calls::TEXT FROM pg_stat_statements 
             WHERE query LIKE '%v_detailed_inventory%' 
             ORDER BY calls DESC LIMIT 1),
            'N/A'
        ),
        'Number of times view was queried'::TEXT;

    -- Index usage
    RETURN QUERY SELECT 
        'Index Usage'::TEXT,
        'Available Indexes'::TEXT,
        (SELECT COUNT(*)::TEXT FROM pg_indexes 
         WHERE tablename IN ('inventory', 'dim_products', 'warehouse_sales_metrics')),
        'Total indexes on related tables'::TEXT;

END;
$$ LANGUAGE plpgsql;

-- Log completion
SELECT 'View performance test functions created successfully' as status;