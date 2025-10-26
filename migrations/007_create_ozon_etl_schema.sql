-- Migration: 007_create_ozon_etl_schema.sql
-- Description: Create complete database schema for Ozon ETL System
-- Requirements: 1.1, 1.2, 1.4
-- Date: 2025-10-26

-- ============================================================================
-- Products dimension table
-- Stores product catalog data from Ozon API
-- ============================================================================
CREATE TABLE IF NOT EXISTS dim_products (
    id SERIAL PRIMARY KEY,
    product_id BIGINT UNIQUE NOT NULL,
    offer_id VARCHAR(255) UNIQUE NOT NULL,
    name TEXT,
    fbo_sku VARCHAR(255),
    fbs_sku VARCHAR(255),
    status VARCHAR(50),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- ============================================================================
-- Sales fact table
-- Stores order data for calculating sales velocity (ADS)
-- ============================================================================
CREATE TABLE IF NOT EXISTS fact_orders (
    id SERIAL PRIMARY KEY,
    posting_number VARCHAR(255) NOT NULL,
    offer_id VARCHAR(255) NOT NULL,
    quantity INTEGER NOT NULL,
    price DECIMAL(10,2),
    warehouse_id VARCHAR(255),
    in_process_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(posting_number, offer_id)
);

-- ============================================================================
-- Inventory table
-- Stores current stock levels across all warehouses
-- ============================================================================
CREATE TABLE IF NOT EXISTS inventory (
    id SERIAL PRIMARY KEY,
    offer_id VARCHAR(255) NOT NULL,
    warehouse_name VARCHAR(255) NOT NULL,
    item_name TEXT,
    present INTEGER DEFAULT 0,
    reserved INTEGER DEFAULT 0,
    available INTEGER DEFAULT 0,
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(offer_id, warehouse_name)
);

-- ============================================================================
-- ETL execution log table
-- Monitors ETL process execution and performance
-- ============================================================================
CREATE TABLE IF NOT EXISTS etl_execution_log (
    id SERIAL PRIMARY KEY,
    etl_class VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL,
    records_processed INTEGER,
    duration_seconds DECIMAL(10,3),
    error_message TEXT,
    started_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);

-- ============================================================================
-- Performance optimization indexes
-- ============================================================================

-- Indexes for dim_products table
CREATE INDEX IF NOT EXISTS idx_dim_products_offer_id ON dim_products(offer_id);
CREATE INDEX IF NOT EXISTS idx_dim_products_product_id ON dim_products(product_id);
CREATE INDEX IF NOT EXISTS idx_dim_products_status ON dim_products(status);
CREATE INDEX IF NOT EXISTS idx_dim_products_updated_at ON dim_products(updated_at);

-- Indexes for fact_orders table
CREATE INDEX IF NOT EXISTS idx_fact_orders_offer_id ON fact_orders(offer_id);
CREATE INDEX IF NOT EXISTS idx_fact_orders_posting_number ON fact_orders(posting_number);
CREATE INDEX IF NOT EXISTS idx_fact_orders_in_process_at ON fact_orders(in_process_at);
CREATE INDEX IF NOT EXISTS idx_fact_orders_warehouse_id ON fact_orders(warehouse_id);
CREATE INDEX IF NOT EXISTS idx_fact_orders_date_range ON fact_orders(in_process_at, offer_id);

-- Indexes for inventory table
CREATE INDEX IF NOT EXISTS idx_inventory_offer_id ON inventory(offer_id);
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse_name ON inventory(warehouse_name);
CREATE INDEX IF NOT EXISTS idx_inventory_available ON inventory(available);
CREATE INDEX IF NOT EXISTS idx_inventory_updated_at ON inventory(updated_at);

-- Indexes for etl_execution_log table
CREATE INDEX IF NOT EXISTS idx_etl_log_class ON etl_execution_log(etl_class);
CREATE INDEX IF NOT EXISTS idx_etl_log_status ON etl_execution_log(status);
CREATE INDEX IF NOT EXISTS idx_etl_log_started_at ON etl_execution_log(started_at);

-- ============================================================================
-- Foreign key constraints for data integrity
-- ============================================================================

-- Link fact_orders to dim_products via offer_id
ALTER TABLE fact_orders 
ADD CONSTRAINT IF NOT EXISTS fk_fact_orders_offer_id 
FOREIGN KEY (offer_id) REFERENCES dim_products(offer_id) 
ON DELETE CASCADE ON UPDATE CASCADE;

-- Link inventory to dim_products via offer_id
ALTER TABLE inventory 
ADD CONSTRAINT IF NOT EXISTS fk_inventory_offer_id 
FOREIGN KEY (offer_id) REFERENCES dim_products(offer_id) 
ON DELETE CASCADE ON UPDATE CASCADE;

-- ============================================================================
-- Triggers for automatic timestamp updates
-- ============================================================================

-- Function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Trigger for dim_products
DROP TRIGGER IF EXISTS update_dim_products_updated_at ON dim_products;
CREATE TRIGGER update_dim_products_updated_at
    BEFORE UPDATE ON dim_products
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- Trigger for inventory
DROP TRIGGER IF EXISTS update_inventory_updated_at ON inventory;
CREATE TRIGGER update_inventory_updated_at
    BEFORE UPDATE ON inventory
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- Views for common queries
-- ============================================================================

-- View for products with current inventory
CREATE OR REPLACE VIEW v_products_with_inventory AS
SELECT 
    p.product_id,
    p.offer_id,
    p.name,
    p.status,
    i.warehouse_name,
    i.present,
    i.reserved,
    i.available,
    i.updated_at as inventory_updated_at
FROM dim_products p
LEFT JOIN inventory i ON p.offer_id = i.offer_id
WHERE p.status = 'active' OR p.status IS NULL;

-- View for sales summary (last 30 days)
CREATE OR REPLACE VIEW v_sales_summary_30d AS
SELECT 
    p.offer_id,
    p.name,
    COUNT(o.id) as order_count,
    SUM(o.quantity) as total_quantity,
    AVG(o.quantity) as avg_quantity_per_order,
    SUM(o.price * o.quantity) as total_revenue,
    MIN(o.in_process_at) as first_sale_date,
    MAX(o.in_process_at) as last_sale_date
FROM dim_products p
INNER JOIN fact_orders o ON p.offer_id = o.offer_id
WHERE o.in_process_at >= NOW() - INTERVAL '30 days'
GROUP BY p.offer_id, p.name;

-- View for ETL monitoring dashboard
CREATE OR REPLACE VIEW v_etl_monitoring AS
SELECT 
    etl_class,
    status,
    COUNT(*) as execution_count,
    AVG(duration_seconds) as avg_duration,
    MAX(started_at) as last_execution,
    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as error_count
FROM etl_execution_log
WHERE started_at >= NOW() - INTERVAL '7 days'
GROUP BY etl_class, status
ORDER BY last_execution DESC;

-- ============================================================================
-- Comments for documentation
-- ============================================================================

COMMENT ON TABLE dim_products IS 'Product catalog dimension table storing Ozon product data';
COMMENT ON COLUMN dim_products.product_id IS 'Ozon internal product ID';
COMMENT ON COLUMN dim_products.offer_id IS 'Seller SKU - primary key for linking data';
COMMENT ON COLUMN dim_products.fbo_sku IS 'FBO (Fulfillment by Ozon) SKU';
COMMENT ON COLUMN dim_products.fbs_sku IS 'FBS (Fulfillment by Seller) SKU';

COMMENT ON TABLE fact_orders IS 'Sales fact table storing order line items';
COMMENT ON COLUMN fact_orders.posting_number IS 'Ozon order number';
COMMENT ON COLUMN fact_orders.offer_id IS 'Seller SKU linking to dim_products';
COMMENT ON COLUMN fact_orders.in_process_at IS 'Order processing timestamp';

COMMENT ON TABLE inventory IS 'Current inventory levels by warehouse';
COMMENT ON COLUMN inventory.offer_id IS 'Seller SKU linking to dim_products';
COMMENT ON COLUMN inventory.warehouse_name IS 'Ozon warehouse name';
COMMENT ON COLUMN inventory.present IS 'Total stock present';
COMMENT ON COLUMN inventory.reserved IS 'Reserved stock (orders in process)';
COMMENT ON COLUMN inventory.available IS 'Available stock (present - reserved)';

COMMENT ON TABLE etl_execution_log IS 'ETL process execution monitoring and logging';
COMMENT ON COLUMN etl_execution_log.etl_class IS 'ETL component class name';
COMMENT ON COLUMN etl_execution_log.records_processed IS 'Number of records processed in this execution';
COMMENT ON COLUMN etl_execution_log.duration_seconds IS 'Execution time in seconds';

-- ============================================================================
-- Grant permissions (adjust as needed for your environment)
-- ============================================================================

-- Grant permissions to application user (replace 'app_user' with actual username)
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dim_products TO app_user;
-- GRANT SELECT, INSERT, UPDATE, DELETE ON fact_orders TO app_user;
-- GRANT SELECT, INSERT, UPDATE, DELETE ON inventory TO app_user;
-- GRANT SELECT, INSERT, UPDATE, DELETE ON etl_execution_log TO app_user;
-- GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_user;

-- ============================================================================
-- Migration completion log
-- ============================================================================

INSERT INTO etl_execution_log (
    etl_class, 
    status, 
    records_processed, 
    duration_seconds, 
    started_at, 
    completed_at
) VALUES (
    'Migration_007_OzonETLSchema',
    'success',
    4, -- 4 tables created
    0,
    NOW(),
    NOW()
);