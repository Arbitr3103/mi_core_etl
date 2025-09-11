-- Создание базы данных и таблиц для проекта mi_core_etl

-- 1. Создание базы данных (если не существует) - эта строка корректна
CREATE DATABASE IF NOT EXISTS mi_core_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Выбираем базу данных для дальнейших операций
USE mi_core_db;

-- 3. Создание пользователя ingest_user (если не существует)
-- ВАЖНО: Замените 'your_secure_password' на ваш надежный пароль
-- ИСПОЛЬЗУЕМ '%' для доступа откуда угодно, чтобы не было проблем с localhost
CREATE USER IF NOT EXISTS 'ingest_user'@'%' IDENTIFIED BY 'xK9#mQ7$vN2@pL!rT4wY';

-- 4. Предоставляем права пользователю ingest_user на НАШУ базу данных mi_core_db
-- Это позволит ему читать, записывать и обновлять данные
GRANT SELECT, INSERT, UPDATE ON mi_core_db.* TO 'ingest_user'@'%';

-- 5. Создание пользователя v_admin (если не существует)
-- ВАЖНО: Замените 'ВАШ_НОВЫЙ_ПАРОЛЬ_ДЛЯ_АДМИНА' на надежный пароль
CREATE USER IF NOT EXISTS 'v_admin'@'%' IDENTIFIED BY 'qwer123';

-- 6. Даем пользователю v_admin ПОЛНЫЕ права на mi_core_db
GRANT ALL PRIVILEGES ON mi_core_db.* TO 'v_admin'@'%' WITH GRANT OPTION;

-- 7. Даем пользователю v_admin права на просмотр ВСЕХ баз (для удобства, если понадобится)
GRANT SELECT ON *.* TO 'v_admin'@'%';

-- 8. Применяем все изменения прав
FLUSH PRIVILEGES;

-- Таблица справочника товаров
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

-- Таблица фактов заказов
CREATE TABLE IF NOT EXISTS fact_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
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

-- Таблица финансовых транзакций
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

-- Таблица сырых событий из API
CREATE TABLE IF NOT EXISTS raw_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(255) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    event_data JSON NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_event (event_id, event_type),
    INDEX idx_event_type (event_type),
    INDEX idx_event_id (event_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
