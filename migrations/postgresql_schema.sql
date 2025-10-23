-- ===================================================================
-- PostgreSQL Schema Migration for mi_core_etl Project
-- Migrated from MySQL to PostgreSQL with optimizations
-- ===================================================================

-- Create database (run this separately as superuser if needed)
-- CREATE DATABASE mi_core_db WITH ENCODING 'UTF8' LC_COLLATE='en_US.UTF-8' LC_CTYPE='en_US.UTF-8';

-- Connect to the database
\c mi_core_db;

-- Enable required extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pg_trgm";
CREATE EXTENSION IF NOT EXISTS "btree_gin";

-- ===================================================================
-- BLOCK 1: BASIC REFERENCE AND SERVICE TABLES
-- ===================================================================

CREATE TABLE IF NOT EXISTS clients (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sources (
    id SERIAL PRIMARY KEY,
    code VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TYPE job_status AS ENUM ('success', 'failed', 'running');

CREATE TABLE IF NOT EXISTS job_runs (
    id BIGSERIAL PRIMARY KEY,
    job_name VARCHAR(100) NOT NULL,
    client_id INTEGER REFERENCES clients(id),
    started_at TIMESTAMP WITH TIME ZONE NOT NULL,
    finished_at TIMESTAMP WITH TIME ZONE,
    status job_status NOT NULL,
    rows_in INTEGER DEFAULT 0,
    rows_out INTEGER DEFAULT 0,
    error_message TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ===================================================================
-- BLOCK 2: ETL TABLES (OZON, WB, ETC.)
-- ===================================================================

CREATE TABLE IF NOT EXISTS dim_products (
    id SERIAL PRIMARY KEY,
    sku_ozon VARCHAR(255) UNIQUE,
    sku_wb VARCHAR(255),
    barcode VARCHAR(255),
    product_name VARCHAR(500),
    cost_price DECIMAL(10,2),
    margin_percent DECIMAL(5,2),
    sku_internal VARCHAR(255),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS raw_events (
    id SERIAL PRIMARY KEY,
    ext_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload JSONB NOT NULL,
    ingested_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (ext_id, event_type)
);

CREATE TABLE IF NOT EXISTS fact_orders (
    id SERIAL PRIMARY KEY,
    product_id INTEGER REFERENCES dim_products(id),
    order_id VARCHAR(255) NOT NULL,
    transaction_type VARCHAR(100) NOT NULL,
    sku VARCHAR(255) NOT NULL,
    qty INTEGER NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    order_date DATE NOT NULL,
    cost_price DECIMAL(10,2),
    client_id INTEGER NOT NULL REFERENCES clients(id),
    source_id INTEGER NOT NULL REFERENCES sources(id),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (order_id, sku)
);

CREATE TABLE IF NOT EXISTS fact_transactions (
    id SERIAL PRIMARY KEY,
    client_id INTEGER NOT NULL REFERENCES clients(id),
    source_id INTEGER NOT NULL REFERENCES sources(id),
    transaction_id VARCHAR(255) NOT NULL UNIQUE,
    order_id VARCHAR(255),
    transaction_type VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    transaction_date DATE NOT NULL,
    description TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS metrics_daily (
    id BIGSERIAL PRIMARY KEY,
    client_id INTEGER NOT NULL REFERENCES clients(id),
    metric_date DATE NOT NULL,
    orders_cnt INTEGER NOT NULL DEFAULT 0,
    revenue_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
    returns_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
    cogs_sum DECIMAL(18,4),
    shipping_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
    commission_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
    other_expenses_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
    profit_sum DECIMAL(18,4),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (client_id, metric_date)
);

CREATE TABLE IF NOT EXISTS system_settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description VARCHAR(255),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ===================================================================
-- BLOCK 3: CAR ORDERING FUNCTIONALITY TABLES
-- ===================================================================

CREATE TABLE IF NOT EXISTS regions (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS brands (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_rus VARCHAR(100),
    external_id VARCHAR(50),
    source VARCHAR(20) DEFAULT 'manual',
    region_id INTEGER REFERENCES regions(id),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (name),
    UNIQUE (external_id, source)
);

CREATE TABLE IF NOT EXISTS car_models (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_rus VARCHAR(100),
    external_id VARCHAR(50),
    source VARCHAR(20) DEFAULT 'manual',
    brand_id INTEGER NOT NULL REFERENCES brands(id),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (external_id, source)
);

CREATE TYPE fastener_type AS ENUM ('болт', 'гайка');

CREATE TABLE IF NOT EXISTS car_specifications (
    id SERIAL PRIMARY KEY,
    car_model_id INTEGER NOT NULL REFERENCES car_models(id),
    name VARCHAR(100),
    external_id VARCHAR(50),
    source VARCHAR(20) DEFAULT 'manual',
    year_start SMALLINT NOT NULL,
    year_end SMALLINT,
    pcd VARCHAR(50),
    dia DECIMAL(5,1),
    fastener_type fastener_type,
    fastener_params VARCHAR(50),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (external_id, source)
);

-- ===================================================================
-- BLOCK 4: INVENTORY MANAGEMENT TABLES
-- ===================================================================

CREATE TYPE stock_type AS ENUM ('FBO', 'FBS');
CREATE TYPE marketplace_source AS ENUM ('Ozon', 'Wildberries');

CREATE TABLE IF NOT EXISTS inventory (
    id SERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES dim_products(id) ON DELETE CASCADE,
    warehouse_name VARCHAR(255) NOT NULL,
    stock_type stock_type NOT NULL,
    quantity_present INTEGER DEFAULT 0,
    quantity_reserved INTEGER DEFAULT 0,
    source marketplace_source NOT NULL,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (product_id, warehouse_name, source)
);

CREATE TABLE IF NOT EXISTS stock_movements (
    id SERIAL PRIMARY KEY,
    movement_id VARCHAR(255) NOT NULL,
    product_id INTEGER NOT NULL REFERENCES dim_products(id) ON DELETE CASCADE,
    movement_date TIMESTAMP WITH TIME ZONE NOT NULL,
    movement_type VARCHAR(50) NOT NULL,
    quantity INTEGER NOT NULL,
    warehouse_name VARCHAR(255),
    order_id VARCHAR(255),
    source marketplace_source NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (movement_id, product_id, source)
);

-- ===================================================================
-- BLOCK 5: MDM SYSTEM TABLES
-- ===================================================================

CREATE TYPE product_status AS ENUM ('active', 'inactive', 'pending_review', 'merged');
CREATE TYPE verification_status AS ENUM ('auto', 'manual', 'pending', 'rejected');
CREATE TYPE match_decision AS ENUM ('auto_matched', 'manual_matched', 'new_master', 'rejected');
CREATE TYPE audit_action AS ENUM ('INSERT', 'UPDATE', 'DELETE', 'MERGE');

CREATE TABLE IF NOT EXISTS master_products (
    master_id VARCHAR(50) PRIMARY KEY,
    canonical_name VARCHAR(500) NOT NULL,
    canonical_brand VARCHAR(200),
    canonical_category VARCHAR(200),
    description TEXT,
    attributes JSONB,
    barcode VARCHAR(100),
    weight_grams INTEGER,
    dimensions_json JSONB,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    status product_status DEFAULT 'active',
    created_by VARCHAR(100),
    updated_by VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS sku_mapping (
    id BIGSERIAL PRIMARY KEY,
    master_id VARCHAR(50) NOT NULL REFERENCES master_products(master_id) ON DELETE CASCADE ON UPDATE CASCADE,
    external_sku VARCHAR(200) NOT NULL,
    source VARCHAR(50) NOT NULL,
    source_name VARCHAR(500),
    source_brand VARCHAR(200),
    source_category VARCHAR(200),
    source_price DECIMAL(10,2),
    source_attributes JSONB,
    confidence_score DECIMAL(3,2) CHECK (confidence_score IS NULL OR (confidence_score >= 0.0 AND confidence_score <= 1.0)),
    verification_status verification_status DEFAULT 'pending',
    match_method VARCHAR(100),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    verified_by VARCHAR(100),
    verified_at TIMESTAMP WITH TIME ZONE,
    UNIQUE (source, external_sku)
);

CREATE TABLE IF NOT EXISTS data_quality_metrics (
    id BIGSERIAL PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(10,4) NOT NULL,
    metric_percentage DECIMAL(5,2) CHECK (metric_percentage IS NULL OR (metric_percentage >= 0.0 AND metric_percentage <= 100.0)),
    total_records INTEGER NOT NULL DEFAULT 0,
    good_records INTEGER NOT NULL DEFAULT 0,
    bad_records INTEGER NOT NULL DEFAULT 0,
    source VARCHAR(50),
    category VARCHAR(100),
    calculation_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    details JSONB
);

CREATE TABLE IF NOT EXISTS matching_history (
    id BIGSERIAL PRIMARY KEY,
    external_sku VARCHAR(200) NOT NULL,
    source VARCHAR(50) NOT NULL,
    master_id VARCHAR(50) REFERENCES master_products(master_id) ON DELETE SET NULL ON UPDATE CASCADE,
    match_candidates JSONB,
    final_decision match_decision NOT NULL,
    confidence_score DECIMAL(3,2),
    match_method VARCHAR(100),
    processing_time_ms INTEGER,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    decided_by VARCHAR(100)
);

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGSERIAL PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_id VARCHAR(100) NOT NULL,
    action audit_action NOT NULL,
    old_values JSONB,
    new_values JSONB,
    changed_fields JSONB,
    user_id VARCHAR(100),
    ip_address INET,
    user_agent TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ===================================================================
-- BLOCK 6: REPLENISHMENT SYSTEM TABLES
-- ===================================================================

CREATE TABLE IF NOT EXISTS replenishment_recommendations (
    id BIGSERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES dim_products(id),
    warehouse_name VARCHAR(255) NOT NULL,
    current_stock INTEGER NOT NULL,
    recommended_quantity INTEGER NOT NULL,
    priority_score DECIMAL(5,2),
    reason TEXT,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(50) DEFAULT 'pending'
);

CREATE TABLE IF NOT EXISTS replenishment_alerts (
    id BIGSERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES dim_products(id),
    alert_type VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    severity VARCHAR(20) DEFAULT 'medium',
    is_resolved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP WITH TIME ZONE
);

CREATE TABLE IF NOT EXISTS replenishment_settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description TEXT,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- ===================================================================
-- BLOCK 7: INDEXES FOR PERFORMANCE OPTIMIZATION
-- ===================================================================

-- Basic indexes
CREATE INDEX IF NOT EXISTS idx_dim_products_sku_ozon ON dim_products(sku_ozon);
CREATE INDEX IF NOT EXISTS idx_dim_products_sku_wb ON dim_products(sku_wb);
CREATE INDEX IF NOT EXISTS idx_dim_products_name ON dim_products USING gin(to_tsvector('english', product_name));

-- Inventory indexes
CREATE INDEX IF NOT EXISTS idx_inventory_product_source ON inventory(product_id, source);
CREATE INDEX IF NOT EXISTS idx_inventory_warehouse ON inventory(warehouse_name);
CREATE INDEX IF NOT EXISTS idx_inventory_updated_at ON inventory(updated_at);
CREATE INDEX IF NOT EXISTS idx_inventory_quantity_present ON inventory(quantity_present);

-- Stock movements indexes
CREATE INDEX IF NOT EXISTS idx_stock_movements_product_date ON stock_movements(product_id, movement_date);
CREATE INDEX IF NOT EXISTS idx_stock_movements_source_type ON stock_movements(source, movement_type);
CREATE INDEX IF NOT EXISTS idx_stock_movements_date ON stock_movements(movement_date);
CREATE INDEX IF NOT EXISTS idx_stock_movements_order_id ON stock_movements(order_id);

-- Fact tables indexes
CREATE INDEX IF NOT EXISTS idx_fact_orders_product_id ON fact_orders(product_id);
CREATE INDEX IF NOT EXISTS idx_fact_orders_order_date ON fact_orders(order_date);
CREATE INDEX IF NOT EXISTS idx_fact_orders_client_source ON fact_orders(client_id, source_id);

-- MDM indexes
CREATE INDEX IF NOT EXISTS idx_master_products_name ON master_products USING gin(to_tsvector('english', canonical_name));
CREATE INDEX IF NOT EXISTS idx_master_products_brand ON master_products(canonical_brand);
CREATE INDEX IF NOT EXISTS idx_master_products_category ON master_products(canonical_category);
CREATE INDEX IF NOT EXISTS idx_master_products_status ON master_products(status);

CREATE INDEX IF NOT EXISTS idx_sku_mapping_master_id ON sku_mapping(master_id);
CREATE INDEX IF NOT EXISTS idx_sku_mapping_external_sku ON sku_mapping(external_sku);
CREATE INDEX IF NOT EXISTS idx_sku_mapping_source ON sku_mapping(source);
CREATE INDEX IF NOT EXISTS idx_sku_mapping_verification_status ON sku_mapping(verification_status);

-- Composite indexes for complex queries
CREATE INDEX IF NOT EXISTS idx_inventory_stock_status ON inventory(
    CASE 
        WHEN quantity_present <= 5 THEN 'critical'
        WHEN quantity_present <= 20 THEN 'low_stock'
        WHEN quantity_present > 100 THEN 'overstock'
        ELSE 'normal'
    END,
    warehouse_name
);

-- ===================================================================
-- BLOCK 8: VIEWS FOR COMMON QUERIES
-- ===================================================================

-- Inventory with products view
CREATE OR REPLACE VIEW v_inventory_with_products AS
SELECT 
    i.id,
    i.product_id,
    dp.sku_ozon,
    dp.sku_wb,
    dp.product_name,
    dp.cost_price,
    i.warehouse_name,
    i.stock_type,
    i.quantity_present,
    i.quantity_reserved,
    (i.quantity_present + i.quantity_reserved) as total_quantity,
    i.source,
    i.updated_at,
    CASE 
        WHEN i.quantity_present <= 5 THEN 'critical'
        WHEN i.quantity_present <= 20 THEN 'low_stock'
        WHEN i.quantity_present > 100 THEN 'overstock'
        ELSE 'normal'
    END as stock_status
FROM inventory i
JOIN dim_products dp ON i.product_id = dp.id;

-- Stock movements with products view
CREATE OR REPLACE VIEW v_movements_with_products AS
SELECT 
    sm.id,
    sm.movement_id,
    sm.product_id,
    dp.sku_ozon,
    dp.sku_wb,
    dp.product_name,
    sm.movement_date,
    sm.movement_type,
    sm.quantity,
    sm.warehouse_name,
    sm.order_id,
    sm.source,
    sm.created_at
FROM stock_movements sm
JOIN dim_products dp ON sm.product_id = dp.id;

-- Product turnover view (last 30 days)
CREATE OR REPLACE VIEW v_product_turnover_30d AS
SELECT 
    dp.id as product_id,
    dp.sku_ozon,
    dp.sku_wb,
    dp.product_name,
    COALESCE(SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END), 0) as total_sold_30d,
    COALESCE(AVG(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END), 0) as avg_daily_sales,
    COUNT(DISTINCT DATE(sm.movement_date)) as active_days,
    COALESCE(SUM(i.quantity_present), 0) as current_stock,
    CASE 
        WHEN SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) > 0 
        THEN COALESCE(SUM(i.quantity_present), 0) / (SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) / 30.0)
        ELSE NULL 
    END as days_of_stock
FROM dim_products dp
LEFT JOIN stock_movements sm ON dp.id = sm.product_id 
    AND sm.movement_date >= CURRENT_DATE - INTERVAL '30 days'
    AND sm.movement_type IN ('sale', 'order')
LEFT JOIN inventory i ON dp.id = i.product_id
GROUP BY dp.id, dp.sku_ozon, dp.sku_wb, dp.product_name;

-- Dashboard inventory view
CREATE OR REPLACE VIEW v_dashboard_inventory AS
SELECT
    p.id,
    p.sku_ozon as sku,
    p.product_name as name,
    i.quantity_present as current_stock,
    i.quantity_present as available_stock,
    i.quantity_reserved as reserved_stock,
    i.warehouse_name,
    CASE 
        WHEN i.quantity_present <= 5 THEN 'critical'
        WHEN i.quantity_present <= 20 THEN 'low_stock'
        WHEN i.quantity_present > 100 THEN 'overstock'
        ELSE 'normal'
    END as stock_status,
    i.updated_at as last_updated,
    p.cost_price as price,
    'Auto Parts' as category
FROM dim_products p
JOIN inventory i ON p.id = i.product_id
WHERE p.sku_ozon IS NOT NULL
ORDER BY
    CASE 
        WHEN i.quantity_present <= 5 THEN 1
        WHEN i.quantity_present <= 20 THEN 2
        WHEN i.quantity_present > 100 THEN 3
        ELSE 4
    END,
    i.quantity_present ASC,
    p.product_name;

-- Metrics views
CREATE OR REPLACE VIEW v_metrics_by_client_day AS
SELECT
    c.name AS client_name,
    m.*
FROM metrics_daily m
JOIN clients c ON c.id = m.client_id;

CREATE OR REPLACE VIEW v_metrics_all_clients_day AS
SELECT
    m.metric_date,
    SUM(m.orders_cnt) AS orders_cnt,
    SUM(m.revenue_sum) AS revenue_sum,
    SUM(m.returns_sum) AS returns_sum,
    SUM(COALESCE(m.cogs_sum,0)) AS cogs_sum,
    SUM(m.shipping_sum) AS shipping_sum,
    SUM(m.commission_sum) AS commission_sum,
    SUM(m.other_expenses_sum) AS other_expenses_sum,
    SUM(COALESCE(m.profit_sum,0)) AS profit_sum
FROM metrics_daily m
GROUP BY m.metric_date
ORDER BY m.metric_date;

-- MDM views
CREATE OR REPLACE VIEW v_master_products_with_stats AS
SELECT 
    mp.master_id,
    mp.canonical_name,
    mp.canonical_brand,
    mp.canonical_category,
    mp.status,
    mp.created_at,
    mp.updated_at,
    COUNT(sm.id) as total_mappings,
    COUNT(CASE WHEN sm.verification_status = 'auto' THEN 1 END) as auto_mappings,
    COUNT(CASE WHEN sm.verification_status = 'manual' THEN 1 END) as manual_mappings,
    COUNT(CASE WHEN sm.verification_status = 'pending' THEN 1 END) as pending_mappings,
    AVG(sm.confidence_score) as avg_confidence_score,
    STRING_AGG(DISTINCT sm.source, ', ') as sources
FROM master_products mp
LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
GROUP BY mp.master_id, mp.canonical_name, mp.canonical_brand, 
         mp.canonical_category, mp.status, mp.created_at, mp.updated_at;

-- ===================================================================
-- BLOCK 9: FUNCTIONS AND TRIGGERS
-- ===================================================================

-- Function to update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ language 'plpgsql';

-- Apply updated_at triggers to relevant tables
CREATE TRIGGER update_clients_updated_at BEFORE UPDATE ON clients FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_sources_updated_at BEFORE UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_dim_products_updated_at BEFORE UPDATE ON dim_products FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_fact_orders_updated_at BEFORE UPDATE ON fact_orders FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_brands_updated_at BEFORE UPDATE ON brands FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_car_models_updated_at BEFORE UPDATE ON car_models FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_car_specifications_updated_at BEFORE UPDATE ON car_specifications FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_inventory_updated_at BEFORE UPDATE ON inventory FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_master_products_updated_at BEFORE UPDATE ON master_products FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_sku_mapping_updated_at BEFORE UPDATE ON sku_mapping FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_system_settings_updated_at BEFORE UPDATE ON system_settings FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_replenishment_settings_updated_at BEFORE UPDATE ON replenishment_settings FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_replenishment_recommendations_updated_at BEFORE UPDATE ON replenishment_recommendations FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- Function to generate unique Master ID
CREATE OR REPLACE FUNCTION generate_master_id()
RETURNS VARCHAR(50) AS $$
DECLARE
    counter INTEGER := 1;
    temp_id VARCHAR(50);
    id_exists INTEGER;
BEGIN
    LOOP
        temp_id := 'MASTER_' || LPAD(counter::TEXT, 8, '0');
        SELECT COUNT(*) INTO id_exists FROM master_products WHERE master_id = temp_id;
        IF id_exists = 0 THEN
            RETURN temp_id;
        END IF;
        counter := counter + 1;
    END LOOP;
END;
$$ LANGUAGE plpgsql;

-- ===================================================================
-- BLOCK 10: INITIAL DATA
-- ===================================================================

-- Insert initial clients
INSERT INTO clients (name) VALUES 
('Default Client'),
('Ozon Client'),
('Wildberries Client')
ON CONFLICT (name) DO NOTHING;

-- Insert initial sources
INSERT INTO sources (code, name) VALUES 
('ozon', 'Ozon Marketplace'),
('wb', 'Wildberries Marketplace'),
('internal', 'Internal System')
ON CONFLICT (code) DO NOTHING;

-- Insert initial system settings
INSERT INTO system_settings (setting_key, setting_value, description) VALUES 
('system_version', '2.0.0', 'Current system version'),
('database_type', 'postgresql', 'Database type'),
('migration_date', CURRENT_TIMESTAMP::TEXT, 'Date of PostgreSQL migration'),
('auto_backup_enabled', 'true', 'Enable automatic backups'),
('log_retention_days', '30', 'Number of days to retain logs')
ON CONFLICT (setting_key) DO UPDATE SET 
    setting_value = EXCLUDED.setting_value,
    updated_at = CURRENT_TIMESTAMP;

-- Insert initial replenishment settings
INSERT INTO replenishment_settings (setting_key, setting_value, description) VALUES 
('critical_stock_threshold', '5', 'Stock level considered critical'),
('low_stock_threshold', '20', 'Stock level considered low'),
('overstock_threshold', '100', 'Stock level considered overstock'),
('default_lead_time_days', '14', 'Default lead time for replenishment'),
('safety_stock_multiplier', '1.5', 'Safety stock multiplier')
ON CONFLICT (setting_key) DO UPDATE SET 
    setting_value = EXCLUDED.setting_value,
    updated_at = CURRENT_TIMESTAMP;

-- ===================================================================
-- COMPLETION MESSAGE
-- ===================================================================

SELECT 'PostgreSQL schema migration completed successfully!' as status,
       CURRENT_TIMESTAMP as completed_at;