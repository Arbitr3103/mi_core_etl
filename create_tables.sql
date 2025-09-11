-- 1. Создание и выбор базы данных
CREATE DATABASE IF NOT EXISTS mi_core_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mi_core_db;

-- ПРАВА ВЫДАЮТСЯ ВРУЧНУЮ ПОСЛЕ СОЗДАНИЯ ПОЛЬЗОВАТЕЛЕЙ
-- GRANT SELECT, INSERT, UPDATE ON mi_core_db.* TO 'ingest_user'@'%';
-- GRANT ALL PRIVILEGES ON mi_core_db.* TO 'v_admin'@'%' WITH GRANT OPTION;
-- FLUSH PRIVILEGES;

-- 2. Таблица справочника товаров
CREATE TABLE IF NOT EXISTS dim_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku_ozon VARCHAR(255) NOT NULL UNIQUE,
    barcode VARCHAR(255),
    product_name VARCHAR(500),
    cost_price DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sku_ozon (sku_ozon),
    INDEX idx_barcode (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Таблица фактов заказов
CREATE TABLE IF NOT EXISTS fact_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NULL,  -- ИСПРАВЛЕНО: Сделано необязательным (NULL)
    order_id VARCHAR(255) NOT NULL,
    transaction_type VARCHAR(100) NOT NULL,
    sku VARCHAR(255) NOT NULL,
    qty INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    order_date DATE NOT NULL,
    cost_price DECIMAL(10,2),
    client_id INT NOT NULL DEFAULT 1,
    source_id INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_order_product (order_id, sku),
    FOREIGN KEY (product_id) REFERENCES dim_products(id),
    INDEX idx_order_id (order_id),
    INDEX idx_order_date (order_date),
    INDEX idx_sku (sku),
    INDEX idx_client_id (client_id),
    INDEX idx_source_id (source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Таблица финансовых транзакций
CREATE TABLE IF NOT EXISTS fact_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(255) NOT NULL UNIQUE,
    order_id VARCHAR(255),
    transaction_type VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    transaction_date DATE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_transaction_id (transaction_id),
    INDEX idx_order_id (order_id),
    INDEX idx_transaction_date (transaction_date),
    INDEX idx_transaction_type (transaction_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Таблица сырых событий из API
CREATE TABLE IF NOT EXISTS raw_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ext_id VARCHAR(255) NOT NULL,         -- ИСПРАВЛЕНО: event_id -> ext_id
    event_type VARCHAR(100) NOT NULL,
    payload JSON NOT NULL,                -- ИСПРАВЛЕНО: event_data -> payload
    ingested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- ИСПРАВЛЕНО: created_at -> ingested_at
    UNIQUE KEY unique_event (ext_id, event_type),
    INDEX idx_event_type (event_type),
    INDEX idx_ext_id (ext_id),
    INDEX idx_ingested_at (ingested_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;