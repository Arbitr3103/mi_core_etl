-- PostgreSQL fixtures: initialize schema for Activity Tracking integration tests

-- Create products table (minimal subset used by tests) if not exists
CREATE TABLE IF NOT EXISTS products (
  id VARCHAR(255) PRIMARY KEY,
  external_sku VARCHAR(255) NOT NULL UNIQUE,
  name VARCHAR(500),
  brand VARCHAR(200),
  category VARCHAR(200),
  price NUMERIC(10,2),
  stock_quantity INTEGER DEFAULT 0,
  marketplace VARCHAR(50),
  created_at TIMESTAMP DEFAULT NOW(),
  updated_at TIMESTAMP DEFAULT NOW(),
  -- activity tracking columns
  is_active BOOLEAN DEFAULT FALSE,
  activity_checked_at TIMESTAMP NULL,
  activity_reason VARCHAR(500) NULL
);

-- Helpful indexes for tests
CREATE INDEX IF NOT EXISTS idx_is_active ON products (is_active);
CREATE INDEX IF NOT EXISTS idx_activity_checked_at ON products (activity_checked_at);
CREATE INDEX IF NOT EXISTS idx_active_checked ON products (is_active, activity_checked_at);
CREATE INDEX IF NOT EXISTS idx_marketplace ON products (marketplace);
CREATE INDEX IF NOT EXISTS idx_created_at ON products (created_at);
CREATE INDEX IF NOT EXISTS idx_updated_at ON products (updated_at);

-- Create product_activity_log table if not exists
CREATE TABLE IF NOT EXISTS product_activity_log (
  id SERIAL PRIMARY KEY,
  product_id VARCHAR(255) NOT NULL,
  external_sku VARCHAR(255) NOT NULL,
  previous_status BOOLEAN NULL,
  new_status BOOLEAN NOT NULL,
  reason VARCHAR(500) NOT NULL,
  changed_at TIMESTAMP DEFAULT NOW(),
  changed_by VARCHAR(100) NULL,
  metadata JSONB NULL
);

-- Indexes used in tests/queries
CREATE INDEX IF NOT EXISTS idx_product_id ON product_activity_log (product_id);
CREATE INDEX IF NOT EXISTS idx_external_sku ON product_activity_log (external_sku);
CREATE INDEX IF NOT EXISTS idx_changed_at ON product_activity_log (changed_at);
CREATE INDEX IF NOT EXISTS idx_new_status ON product_activity_log (new_status);
CREATE INDEX IF NOT EXISTS idx_product_recent_changes ON product_activity_log (product_id, changed_at);
CREATE INDEX IF NOT EXISTS idx_status_changes_recent ON product_activity_log (previous_status, new_status, changed_at);
CREATE INDEX IF NOT EXISTS idx_user_activity ON product_activity_log (changed_by, changed_at);
