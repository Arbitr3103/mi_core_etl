-- Validation Script: 007_create_ozon_etl_schema.sql
-- Description: Validate Ozon ETL System database schema
-- Date: 2025-10-26

-- ============================================================================
-- Test 1: Verify all tables exist
-- ============================================================================

DO $$
DECLARE
    table_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO table_count
    FROM information_schema.tables 
    WHERE table_schema = 'public' 
    AND table_name IN ('dim_products', 'fact_orders', 'inventory', 'etl_execution_log');
    
    IF table_count != 4 THEN
        RAISE EXCEPTION 'Expected 4 tables, found %', table_count;
    END IF;
    
    RAISE NOTICE 'PASS: All 4 required tables exist';
END $$;

-- ============================================================================
-- Test 2: Verify table structures
-- ============================================================================

-- Test dim_products structure
DO $$
DECLARE
    column_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO column_count
    FROM information_schema.columns 
    WHERE table_name = 'dim_products' 
    AND column_name IN ('id', 'product_id', 'offer_id', 'name', 'fbo_sku', 'fbs_sku', 'status', 'created_at', 'updated_at');
    
    IF column_count != 9 THEN
        RAISE EXCEPTION 'dim_products: Expected 9 columns, found %', column_count;
    END IF;
    
    RAISE NOTICE 'PASS: dim_products table structure is correct';
END $$;

-- Test fact_orders structure
DO $$
DECLARE
    column_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO column_count
    FROM information_schema.columns 
    WHERE table_name = 'fact_orders' 
    AND column_name IN ('id', 'posting_number', 'offer_id', 'quantity', 'price', 'warehouse_id', 'in_process_at', 'created_at');
    
    IF column_count != 8 THEN
        RAISE EXCEPTION 'fact_orders: Expected 8 columns, found %', column_count;
    END IF;
    
    RAISE NOTICE 'PASS: fact_orders table structure is correct';
END $$;

-- Test inventory structure
DO $$
DECLARE
    column_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO column_count
    FROM information_schema.columns 
    WHERE table_name = 'inventory' 
    AND column_name IN ('id', 'offer_id', 'warehouse_name', 'item_name', 'present', 'reserved', 'available', 'updated_at');
    
    IF column_count != 8 THEN
        RAISE EXCEPTION 'inventory: Expected 8 columns, found %', column_count;
    END IF;
    
    RAISE NOTICE 'PASS: inventory table structure is correct';
END $$;

-- Test etl_execution_log structure
DO $$
DECLARE
    column_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO column_count
    FROM information_schema.columns 
    WHERE table_name = 'etl_execution_log' 
    AND column_name IN ('id', 'etl_class', 'status', 'records_processed', 'duration_seconds', 'error_message', 'started_at', 'completed_at', 'created_at');
    
    IF column_count != 9 THEN
        RAISE EXCEPTION 'etl_execution_log: Expected 9 columns, found %', column_count;
    END IF;
    
    RAISE NOTICE 'PASS: etl_execution_log table structure is correct';
END $$;

-- ============================================================================
-- Test 3: Verify indexes exist
-- ============================================================================

DO $$
DECLARE
    index_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO index_count
    FROM pg_indexes 
    WHERE schemaname = 'public' 
    AND indexname LIKE 'idx_%';
    
    IF index_count < 15 THEN
        RAISE EXCEPTION 'Expected at least 15 indexes, found %', index_count;
    END IF;
    
    RAISE NOTICE 'PASS: Required indexes exist (found %)', index_count;
END $$;

-- ============================================================================
-- Test 4: Verify foreign key constraints
-- ============================================================================

DO $$
DECLARE
    constraint_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO constraint_count
    FROM information_schema.table_constraints 
    WHERE constraint_type = 'FOREIGN KEY' 
    AND table_name IN ('fact_orders', 'inventory');
    
    IF constraint_count != 2 THEN
        RAISE EXCEPTION 'Expected 2 foreign key constraints, found %', constraint_count;
    END IF;
    
    RAISE NOTICE 'PASS: Foreign key constraints exist';
END $$;

-- ============================================================================
-- Test 5: Verify views exist
-- ============================================================================

DO $$
DECLARE
    view_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO view_count
    FROM information_schema.views 
    WHERE table_schema = 'public' 
    AND table_name IN ('v_products_with_inventory', 'v_sales_summary_30d', 'v_etl_monitoring');
    
    IF view_count != 3 THEN
        RAISE EXCEPTION 'Expected 3 views, found %', view_count;
    END IF;
    
    RAISE NOTICE 'PASS: All required views exist';
END $$;

-- ============================================================================
-- Test 6: Verify triggers exist
-- ============================================================================

DO $$
DECLARE
    trigger_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO trigger_count
    FROM information_schema.triggers 
    WHERE trigger_schema = 'public' 
    AND trigger_name LIKE 'update_%_updated_at';
    
    IF trigger_count != 2 THEN
        RAISE EXCEPTION 'Expected 2 triggers, found %', trigger_count;
    END IF;
    
    RAISE NOTICE 'PASS: Update triggers exist';
END $$;

-- ============================================================================
-- Test 7: Test data insertion and constraints
-- ============================================================================

-- Test product insertion
INSERT INTO dim_products (product_id, offer_id, name, status) 
VALUES (12345, 'TEST-SKU-001', 'Test Product', 'active');

-- Test order insertion (should work with foreign key)
INSERT INTO fact_orders (posting_number, offer_id, quantity, price, in_process_at) 
VALUES ('TEST-ORDER-001', 'TEST-SKU-001', 2, 99.99, NOW());

-- Test inventory insertion (should work with foreign key)
INSERT INTO inventory (offer_id, warehouse_name, present, reserved, available) 
VALUES ('TEST-SKU-001', 'Test Warehouse', 100, 10, 90);

-- Test unique constraints
DO $$
BEGIN
    BEGIN
        INSERT INTO dim_products (product_id, offer_id, name, status) 
        VALUES (12345, 'TEST-SKU-001', 'Duplicate Product', 'active');
        RAISE EXCEPTION 'Unique constraint should have prevented duplicate insertion';
    EXCEPTION
        WHEN unique_violation THEN
            RAISE NOTICE 'PASS: Unique constraints working correctly';
    END;
END $$;

-- Test foreign key constraints
DO $$
BEGIN
    BEGIN
        INSERT INTO fact_orders (posting_number, offer_id, quantity, price, in_process_at) 
        VALUES ('TEST-ORDER-002', 'NON-EXISTENT-SKU', 1, 50.00, NOW());
        RAISE EXCEPTION 'Foreign key constraint should have prevented insertion';
    EXCEPTION
        WHEN foreign_key_violation THEN
            RAISE NOTICE 'PASS: Foreign key constraints working correctly';
    END;
END $$;

-- ============================================================================
-- Test 8: Test views functionality
-- ============================================================================

-- Test products with inventory view
DO $$
DECLARE
    view_result_count INTEGER;
BEGIN
    SELECT COUNT(*) INTO view_result_count
    FROM v_products_with_inventory 
    WHERE offer_id = 'TEST-SKU-001';
    
    IF view_result_count != 1 THEN
        RAISE EXCEPTION 'v_products_with_inventory view not working correctly';
    END IF;
    
    RAISE NOTICE 'PASS: v_products_with_inventory view working correctly';
END $$;

-- ============================================================================
-- Test 9: Test triggers functionality
-- ============================================================================

-- Test updated_at trigger
DO $$
DECLARE
    old_updated_at TIMESTAMP;
    new_updated_at TIMESTAMP;
BEGIN
    SELECT updated_at INTO old_updated_at FROM dim_products WHERE offer_id = 'TEST-SKU-001';
    
    -- Small delay to ensure timestamp difference
    PERFORM pg_sleep(0.1);
    
    UPDATE dim_products SET name = 'Updated Test Product' WHERE offer_id = 'TEST-SKU-001';
    
    SELECT updated_at INTO new_updated_at FROM dim_products WHERE offer_id = 'TEST-SKU-001';
    
    IF new_updated_at <= old_updated_at THEN
        RAISE EXCEPTION 'updated_at trigger not working correctly';
    END IF;
    
    RAISE NOTICE 'PASS: updated_at trigger working correctly';
END $$;

-- ============================================================================
-- Cleanup test data
-- ============================================================================

DELETE FROM inventory WHERE offer_id = 'TEST-SKU-001';
DELETE FROM fact_orders WHERE offer_id = 'TEST-SKU-001';
DELETE FROM dim_products WHERE offer_id = 'TEST-SKU-001';

-- ============================================================================
-- Final validation summary
-- ============================================================================

SELECT 'VALIDATION COMPLETE: All tests passed successfully' as result;