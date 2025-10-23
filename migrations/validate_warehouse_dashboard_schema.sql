-- ===================================================================
-- VALIDATION: Warehouse Dashboard Schema
-- Date: October 22, 2025
-- Description: Validate warehouse dashboard schema migration
-- ===================================================================

-- Connect to the database
\c mi_core_db;

\echo '===================================================================';
\echo 'VALIDATION: Warehouse Dashboard Schema Migration';
\echo '===================================================================';
\echo '';

-- ===================================================================
-- Test 1: Verify inventory table extensions
-- ===================================================================

\echo 'Test 1: Checking inventory table new columns...';
SELECT 
    column_name, 
    data_type, 
    is_nullable,
    column_default
FROM information_schema.columns
WHERE table_schema = 'public'
    AND table_name = 'inventory'
    AND column_name IN (
        'preparing_for_sale', 'in_supply_requests', 'in_transit',
        'in_inspection', 'returning_from_customers', 'expiring_soon',
        'defective', 'excess_from_supply', 'awaiting_upd',
        'preparing_for_removal', 'cluster'
    )
ORDER BY ordinal_position;

\echo '';
\echo 'Expected: 11 columns (10 Ozon metrics + 1 cluster field)';
SELECT COUNT(*) as column_count
FROM information_schema.columns
WHERE table_schema = 'public'
    AND table_name = 'inventory'
    AND column_name IN (
        'preparing_for_sale', 'in_supply_requests', 'in_transit',
        'in_inspection', 'returning_from_customers', 'expiring_soon',
        'defective', 'excess_from_supply', 'awaiting_upd',
        'preparing_for_removal', 'cluster'
    );

-- ===================================================================
-- Test 2: Verify warehouse_sales_metrics table
-- ===================================================================

\echo '';
\echo 'Test 2: Checking warehouse_sales_metrics table...';
SELECT 
    table_name,
    table_type
FROM information_schema.tables
WHERE table_schema = 'public'
    AND table_name = 'warehouse_sales_metrics';

\echo '';
\echo 'Columns in warehouse_sales_metrics:';
SELECT 
    column_name, 
    data_type,
    is_nullable
FROM information_schema.columns
WHERE table_schema = 'public'
    AND table_name = 'warehouse_sales_metrics'
ORDER BY ordinal_position;

-- ===================================================================
-- Test 3: Verify indexes
-- ===================================================================

\echo '';
\echo 'Test 3: Checking indexes...';
SELECT 
    tablename,
    indexname,
    indexdef
FROM pg_indexes
WHERE schemaname = 'public'
    AND (
        (tablename = 'inventory' AND indexname LIKE '%cluster%')
        OR (tablename = 'warehouse_sales_metrics')
    )
ORDER BY tablename, indexname;

-- ===================================================================
-- Test 4: Verify SQL functions
-- ===================================================================

\echo '';
\echo 'Test 4: Checking SQL functions...';
SELECT 
    routine_name,
    routine_type,
    data_type as return_type,
    type_udt_name
FROM information_schema.routines
WHERE routine_schema = 'public'
    AND routine_name IN (
        'calculate_daily_sales_avg',
        'calculate_days_of_stock',
        'determine_liquidity_status',
        'refresh_warehouse_metrics_for_product'
    )
ORDER BY routine_name;

\echo '';
\echo 'Expected: 4 functions';
SELECT COUNT(*) as function_count
FROM information_schema.routines
WHERE routine_schema = 'public'
    AND routine_name IN (
        'calculate_daily_sales_avg',
        'calculate_days_of_stock',
        'determine_liquidity_status',
        'refresh_warehouse_metrics_for_product'
    );

-- ===================================================================
-- Test 5: Test SQL functions with sample data
-- ===================================================================

\echo '';
\echo 'Test 5: Testing SQL functions...';

-- Test calculate_days_of_stock
\echo 'Testing calculate_days_of_stock(100, 5.5):';
SELECT calculate_days_of_stock(100, 5.5) as days_of_stock;

\echo 'Testing calculate_days_of_stock(100, 0):';
SELECT calculate_days_of_stock(100, 0) as days_of_stock;

-- Test determine_liquidity_status
\echo '';
\echo 'Testing determine_liquidity_status with various values:';
SELECT 
    days,
    determine_liquidity_status(days) as status
FROM (
    VALUES (5), (10), (20), (50), (NULL)
) AS t(days);

-- ===================================================================
-- Test 6: Verify constraints and foreign keys
-- ===================================================================

\echo '';
\echo 'Test 6: Checking constraints on warehouse_sales_metrics...';
SELECT
    tc.constraint_name,
    tc.constraint_type,
    kcu.column_name,
    ccu.table_name AS foreign_table_name,
    ccu.column_name AS foreign_column_name
FROM information_schema.table_constraints AS tc
JOIN information_schema.key_column_usage AS kcu
    ON tc.constraint_name = kcu.constraint_name
    AND tc.table_schema = kcu.table_schema
LEFT JOIN information_schema.constraint_column_usage AS ccu
    ON ccu.constraint_name = tc.constraint_name
    AND ccu.table_schema = tc.table_schema
WHERE tc.table_schema = 'public'
    AND tc.table_name = 'warehouse_sales_metrics'
ORDER BY tc.constraint_type, tc.constraint_name;

-- ===================================================================
-- Summary
-- ===================================================================

\echo '';
\echo '===================================================================';
\echo 'VALIDATION SUMMARY';
\echo '===================================================================';

DO $$
DECLARE
    v_inventory_columns INTEGER;
    v_metrics_table INTEGER;
    v_functions INTEGER;
    v_indexes INTEGER;
    v_all_valid BOOLEAN := TRUE;
BEGIN
    -- Check inventory columns
    SELECT COUNT(*) INTO v_inventory_columns
    FROM information_schema.columns
    WHERE table_schema = 'public'
        AND table_name = 'inventory'
        AND column_name IN (
            'preparing_for_sale', 'in_supply_requests', 'in_transit',
            'in_inspection', 'returning_from_customers', 'expiring_soon',
            'defective', 'excess_from_supply', 'awaiting_upd',
            'preparing_for_removal', 'cluster'
        );

    -- Check metrics table
    SELECT COUNT(*) INTO v_metrics_table
    FROM information_schema.tables
    WHERE table_schema = 'public'
        AND table_name = 'warehouse_sales_metrics';

    -- Check functions
    SELECT COUNT(*) INTO v_functions
    FROM information_schema.routines
    WHERE routine_schema = 'public'
        AND routine_name IN (
            'calculate_daily_sales_avg',
            'calculate_days_of_stock',
            'determine_liquidity_status',
            'refresh_warehouse_metrics_for_product'
        );

    -- Check indexes
    SELECT COUNT(*) INTO v_indexes
    FROM pg_indexes
    WHERE schemaname = 'public'
        AND tablename = 'warehouse_sales_metrics';

    -- Print results
    RAISE NOTICE '';
    RAISE NOTICE '✓ Inventory columns added: % (expected: 11)', v_inventory_columns;
    RAISE NOTICE '✓ Warehouse sales metrics table: % (expected: 1)', v_metrics_table;
    RAISE NOTICE '✓ SQL functions created: % (expected: 4)', v_functions;
    RAISE NOTICE '✓ Indexes on metrics table: % (expected: 6+)', v_indexes;
    RAISE NOTICE '';

    -- Validate
    IF v_inventory_columns = 11 AND v_metrics_table = 1 AND v_functions = 4 AND v_indexes >= 6 THEN
        RAISE NOTICE '✅ ALL VALIDATIONS PASSED';
    ELSE
        RAISE NOTICE '❌ SOME VALIDATIONS FAILED';
        v_all_valid := FALSE;
    END IF;

    IF NOT v_all_valid THEN
        RAISE EXCEPTION 'Validation failed';
    END IF;
END $$;

\echo '';
\echo '===================================================================';
