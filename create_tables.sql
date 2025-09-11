-- ===================================================================
-- ФИНАЛЬНЫЙ СКРИПТ СОЗДАНИЯ ВСЕЙ СТРУКТУРЫ БАЗЫ ДАННЫХ mi_core_db
-- ===================================================================

-- 1. Создание и выбор базы данных
CREATE DATABASE IF NOT EXISTS mi_core_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mi_core_db;

-- ===================================================================
-- БЛОК 1: ОСНОВНЫЕ СПРАВОЧНИКИ И СЛУЖЕБНЫЕ ТАБЛИЦЫ
-- ===================================================================

CREATE TABLE IF NOT EXISTS clients (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sources (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS job_runs (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_name      VARCHAR(100) NOT NULL,
  client_id     INT NULL,
  started_at    DATETIME NOT NULL,
  finished_at   DATETIME NULL,
  status        ENUM('success','failed','running') NOT NULL,
  rows_in       INT DEFAULT 0,
  rows_out      INT DEFAULT 0,
  error_message TEXT NULL,
  CONSTRAINT fk_job_client_ref FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

-- ===================================================================
-- БЛОК 2: ТАБЛИЦЫ ДЛЯ ETL (OZON, WB И Т.Д.)
-- ===================================================================

CREATE TABLE IF NOT EXISTS dim_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku_ozon VARCHAR(255) UNIQUE,
    barcode VARCHAR(255),
    product_name VARCHAR(500),
    cost_price DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS raw_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ext_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,
    ingested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_event (ext_id, event_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fact_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NULL,
    order_id VARCHAR(255) NOT NULL,
    transaction_type VARCHAR(100) NOT NULL,
    sku VARCHAR(255) NOT NULL,
    qty INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    order_date DATE NOT NULL,
    cost_price DECIMAL(10,2),
    client_id INT NOT NULL,
    source_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_order_product (order_id, sku),
    CONSTRAINT fk_fo_product FOREIGN KEY (product_id) REFERENCES dim_products(id),
    CONSTRAINT fk_fo_client FOREIGN KEY (client_id) REFERENCES clients(id),
    CONSTRAINT fk_fo_source FOREIGN KEY (source_id) REFERENCES sources(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fact_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(255) NOT NULL UNIQUE,
    order_id VARCHAR(255),
    transaction_type VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    transaction_date DATE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS metrics_daily (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  metric_date DATE NOT NULL,
  orders_cnt INT NOT NULL DEFAULT 0,
  revenue_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  returns_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  cogs_sum DECIMAL(18,4) NULL,
  shipping_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  commission_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  other_expenses_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
  profit_sum DECIMAL(18,4) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_metrics (client_id, metric_date),
  CONSTRAINT fk_metrics_client_ref FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    description VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ===================================================================
-- БЛОК 3: ТАБЛИЦЫ ДЛЯ ФУНКЦИОНАЛА "ЗАКАЗ ПО АВТО"
-- ===================================================================

CREATE TABLE IF NOT EXISTS regions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS brands (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_rus VARCHAR(100) NULL,
    external_id VARCHAR(50) NULL,
    source VARCHAR(20) DEFAULT 'manual',
    region_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_brand_name (name),
    UNIQUE KEY uk_brand_external (external_id, source),
    CONSTRAINT fk_brand_region_ref FOREIGN KEY (region_id) REFERENCES regions(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS car_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    name_rus VARCHAR(100) NULL,
    external_id VARCHAR(50) NULL,
    source VARCHAR(20) DEFAULT 'manual',
    brand_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_model_external (external_id, source),
    CONSTRAINT fk_model_brand_ref FOREIGN KEY (brand_id) REFERENCES brands(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS car_specifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    car_model_id INT NOT NULL,
    name VARCHAR(100) NULL,
    external_id VARCHAR(50) NULL,
    source VARCHAR(20) DEFAULT 'manual',
    year_start SMALLINT NOT NULL,
    year_end SMALLINT NULL,
    pcd VARCHAR(50) NULL,
    dia DECIMAL(5,1) NULL,
    fastener_type ENUM('болт', 'гайка') NULL,
    fastener_params VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_spec_external (external_id, source),
    CONSTRAINT fk_spec_model_ref FOREIGN KEY (car_model_id) REFERENCES car_models(id)
) ENGINE=InnoDB;


-- ===================================================================
-- БЛОК 4: ПРЕДСТАВЛЕНИЯ (VIEWS)
-- ===================================================================

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