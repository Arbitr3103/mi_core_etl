-- ===================================================================
-- Performance Comparison and Validation Tests
-- ===================================================================
-- 
-- This script creates comprehensive performance tests to compare
-- the functional approach with the original view implementation.
-- 
-- Requirements: 3.4, 4.4, 6.1
-- Task: 11.4 Performance comparison and validation
-- ===================================================================

-- Create comprehensive performance comparison function
CREATE OR REPLACE FUNCTION compare_view_performance()
RETURNS TABLE (
    test_scenario TEXT,
    view_type TEXT,
    execution_time_ms NUMERIC,
    rows_returned BIGINT,
    performance_rating TEXT
) AS $$
DECLARE
    start_time TIMESTAMP;
    end_time TIMESTAMP;
    row_count BIGINT;
BEGIN
    -- Test 1: Basic COUNT on standard view
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'basic_count'::TEXT,
        'v_detailed_inventory'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

    -- Test 2: Basic COUNT on fast view (materialized)
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory_fast;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'basic_count'::TEXT,
        'v_detailed_inventory_fast'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

    -- Test 3: Filter by stock_status on standard view
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory 
    WHERE stock_status != 'archived_or_hidden';
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'stock_status_filter'::TEXT,
        'v_detailed_inventory'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

    -- Test 4: Filter by stock_status on fast view
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory_fast 
    WHERE stock_status != 'archived_or_hidden';
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'stock_status_filter'::TEXT,
        'v_detailed_inventory_fast'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

    -- Test 5: Complex filter on standard view
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory 
    WHERE stock_status IN ('critical', 'low') 
      AND available_stock > 0
      AND visibility IN ('VISIBLE', 'ACTIVE', 'продаётся');
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'complex_filter'::TEXT,
        'v_detailed_inventory'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

    -- Test 6: Complex filter on fast view
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM v_detailed_inventory_fast 
    WHERE stock_status IN ('critical', 'low') 
      AND available_stock > 0
      AND visibility IN ('VISIBLE', 'ACTIVE', 'продаётся');
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'complex_filter'::TEXT,
        'v_detailed_inventory_fast'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

    -- Test 7: Ordering with LIMIT on standard view
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM (
        SELECT * FROM v_detailed_inventory 
        WHERE stock_status != 'archived_or_hidden'
        ORDER BY urgency_score DESC, days_of_stock ASC
        LIMIT 50
    ) subq;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'ordering_pagination'::TEXT,
        'v_detailed_inventory'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

    -- Test 8: Ordering with LIMIT on fast view
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM (
        SELECT * FROM v_detailed_inventory_fast 
        WHERE stock_status != 'archived_or_hidden'
        ORDER BY stock_status DESC, days_of_stock ASC
        LIMIT 50
    ) subq;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'ordering_pagination'::TEXT,
        'v_detailed_inventory_fast'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

    -- Test 9: Aggregation on standard view
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM (
        SELECT warehouse_name, 
               COUNT(*) as products,
               SUM(available_stock) as total_stock
        FROM v_detailed_inventory 
        WHERE stock_status != 'archived_or_hidden'
        GROUP BY warehouse_name
    ) subq;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'aggregation'::TEXT,
        'v_detailed_inventory'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

    -- Test 10: Aggregation on fast view
    start_time := clock_timestamp();
    SELECT COUNT(*) INTO row_count FROM (
        SELECT warehouse_name, 
               COUNT(*) as products,
               SUM(available_stock) as total_stock
        FROM v_detailed_inventory_fast 
        WHERE stock_status != 'archived_or_hidden'
        GROUP BY warehouse_name
    ) subq;
    end_time := clock_timestamp();
    
    RETURN QUERY SELECT 
        'aggregation'::TEXT,
        'v_detailed_inventory_fast'::TEXT,
        EXTRACT(MILLISECONDS FROM (end_time - start_time))::NUMERIC,
        row_count,
        CASE 
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 10 THEN 'Excellent'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 50 THEN 'Good'
            WHEN EXTRACT(MILLISECONDS FROM (end_time - start_time)) < 100 THEN 'Acceptable'
            ELSE 'Needs Optimization'
        END::TEXT;

END;
$$ LANGUAGE plpgsql;

-- Create data consistency validation function
CREATE OR REPLACE FUNCTION validate_data_consistency()
RETURNS TABLE (
    validation_check TEXT,
    standard_view_count BIGINT,
    fast_view_count BIGINT,
    materialized_cache_count BIGINT,
    consistency_status TEXT
) AS $$
BEGIN
    -- Check 1: Total row counts
    RETURN QUERY SELECT 
        'total_rows'::TEXT,
        (SELECT COUNT(*) FROM v_detailed_inventory),
        (SELECT COUNT(*) FROM v_detailed_inventory_fast),
        (SELECT COUNT(*) FROM mv_stock_status_cache),
        CASE 
            WHEN (SELECT COUNT(*) FROM v_detailed_inventory) = (SELECT COUNT(*) FROM mv_stock_status_cache)
            THEN 'CONSISTENT'
            ELSE 'INCONSISTENT'
        END::TEXT;

    -- Check 2: Visible products count
    RETURN QUERY SELECT 
        'visible_products'::TEXT,
        (SELECT COUNT(*) FROM v_detailed_inventory WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')),
        (SELECT COUNT(*) FROM v_detailed_inventory_fast WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')),
        (SELECT COUNT(*) FROM mv_stock_status_cache WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')),
        CASE 
            WHEN (SELECT COUNT(*) FROM v_detailed_inventory WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся')) = 
                 (SELECT COUNT(*) FROM mv_stock_status_cache WHERE visibility IN ('VISIBLE', 'ACTIVE', 'продаётся'))
            THEN 'CONSISTENT'
            ELSE 'INCONSISTENT'
        END::TEXT;

    -- Check 3: Products with available stock
    RETURN QUERY SELECT 
        'products_with_stock'::TEXT,
        (SELECT COUNT(*) FROM v_detailed_inventory WHERE available_stock > 0),
        (SELECT COUNT(*) FROM v_detailed_inventory_fast WHERE available_stock > 0),
        (SELECT COUNT(*) FROM mv_stock_status_cache WHERE available_stock > 0),
        CASE 
            WHEN (SELECT COUNT(*) FROM v_detailed_inventory WHERE available_stock > 0) = 
                 (SELECT COUNT(*) FROM mv_stock_status_cache WHERE available_stock > 0)
            THEN 'CONSISTENT'
            ELSE 'INCONSISTENT'
        END::TEXT;

    -- Check 4: Critical/Low stock products
    RETURN QUERY SELECT 
        'critical_low_stock'::TEXT,
        (SELECT COUNT(*) FROM v_detailed_inventory WHERE stock_status IN ('critical', 'low')),
        (SELECT COUNT(*) FROM v_detailed_inventory_fast WHERE stock_status IN ('critical', 'low')),
        (SELECT COUNT(*) FROM mv_stock_status_cache WHERE stock_status IN ('critical', 'low')),
        CASE 
            WHEN (SELECT COUNT(*) FROM v_detailed_inventory WHERE stock_status IN ('critical', 'low')) = 
                 (SELECT COUNT(*) FROM mv_stock_status_cache WHERE stock_status IN ('critical', 'low'))
            THEN 'CONSISTENT'
            ELSE 'INCONSISTENT'
        END::TEXT;

END;
$$ LANGUAGE plpgsql;

-- Create performance summary report
CREATE OR REPLACE FUNCTION generate_performance_summary()
RETURNS TABLE (
    metric TEXT,
    value TEXT,
    recommendation TEXT
) AS $$
BEGIN
    RETURN QUERY SELECT 
        'Average Query Time (Standard View)'::TEXT,
        (SELECT ROUND(AVG(execution_time_ms), 2)::TEXT || ' ms' 
         FROM compare_view_performance() 
         WHERE view_type = 'v_detailed_inventory'),
        'Target: < 50ms for good performance'::TEXT;

    RETURN QUERY SELECT 
        'Average Query Time (Fast View)'::TEXT,
        (SELECT ROUND(AVG(execution_time_ms), 2)::TEXT || ' ms' 
         FROM compare_view_performance() 
         WHERE view_type = 'v_detailed_inventory_fast'),
        'Target: < 10ms for excellent performance'::TEXT;

    RETURN QUERY SELECT 
        'Performance Improvement'::TEXT,
        (SELECT ROUND(
            (1 - (AVG(CASE WHEN view_type = 'v_detailed_inventory_fast' THEN execution_time_ms END) / 
                  AVG(CASE WHEN view_type = 'v_detailed_inventory' THEN execution_time_ms END))) * 100, 1
        )::TEXT || '%' 
         FROM compare_view_performance()),
        'Higher is better'::TEXT;

    RETURN QUERY SELECT 
        'Data Consistency'::TEXT,
        (SELECT CASE 
            WHEN COUNT(*) = COUNT(*) FILTER (WHERE consistency_status = 'CONSISTENT')
            THEN 'All checks passed'
            ELSE 'Some checks failed'
         END
         FROM validate_data_consistency()),
        'All checks should pass'::TEXT;

    RETURN QUERY SELECT 
        'Materialized Cache Size'::TEXT,
        (SELECT pg_size_pretty(pg_relation_size('mv_stock_status_cache'))),
        'Monitor growth over time'::TEXT;

    RETURN QUERY SELECT 
        'Total Indexes Size'::TEXT,
        (SELECT pg_size_pretty(SUM(pg_relation_size(indexname::regclass)))
         FROM pg_indexes 
         WHERE tablename IN ('inventory', 'dim_products', 'warehouse_sales_metrics', 'mv_stock_status_cache')),
        'Balance between performance and storage'::TEXT;

END;
$$ LANGUAGE plpgsql;

-- Log completion
SELECT 'Performance comparison tests created successfully' as status;