-- PostgreSQL fixtures: sample data for Activity Tracking integration tests

-- Clean previous test data (idempotent fixtures)
DELETE FROM product_activity_log;
DELETE FROM products;

-- Insert sample products
INSERT INTO products (id, external_sku, name, is_active, activity_checked_at, activity_reason)
VALUES
  ('test_product_001', 'TEST_SKU_001', 'Test Product 1', FALSE, NOW(), 'Initial test data'),
  ('test_product_002', 'TEST_SKU_002', 'Test Product 2', FALSE, NOW(), 'Initial test data'),
  ('test_product_003', 'TEST_SKU_003', 'Test Product 3', FALSE, NOW(), 'Initial test data');

-- Minimal seed for activity log (optional)
INSERT INTO product_activity_log (product_id, external_sku, previous_status, new_status, reason, changed_by)
VALUES
  ('test_product_001', 'TEST_SKU_001', NULL, FALSE, 'Seed initial status', 'seed');
