-- ===================================================================
-- БЕЗОПАСНАЯ СХЕМА ДЛЯ СИСТЕМЫ ПОПОЛНЕНИЯ СКЛАДА
-- Совместима со всеми версиями MySQL 5.7+
-- ===================================================================

-- ===================================================================
-- 1. СОЗДАНИЕ ОСНОВНОЙ ТАБЛИЦЫ РЕКОМЕНДАЦИЙ ПО ПОПОЛНЕНИЮ
-- ===================================================================

DROP TABLE IF EXISTS replenishment_recommendations;
CREATE TABLE replenishment_recommendations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- Информация о товаре
    product_id INT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    product_name VARCHAR(500),
    source VARCHAR(50) NOT NULL,
    
    -- Текущее состояние запасов
    current_stock INT DEFAULT 0,
    reserved_stock INT DEFAULT 0,
    available_stock INT DEFAULT 0,
    
    -- Метрики продаж
    daily_sales_rate_7d DECIMAL(10,2) DEFAULT 0.00,
    daily_sales_rate_14d DECIMAL(10,2) DEFAULT 0.00,
    daily_sales_rate_30d DECIMAL(10,2) DEFAULT 0.00,
    
    -- Прогнозы и рекомендации
    days_until_stockout INT NULL,
    recommended_order_quantity INT DEFAULT 0,
    recommended_order_value DECIMAL(12,2) NULL,
    
    -- Приоритизация
    priority_level ENUM('CRITICAL', 'HIGH', 'MEDIUM', 'LOW') DEFAULT 'LOW',
    urgency_score DECIMAL(5,2) DEFAULT 0.00,
    
    -- Даты и тренды
    last_sale_date DATE NULL,
    last_restock_date DATE NULL,
    sales_trend ENUM('GROWING', 'STABLE', 'DECLINING', 'NO_DATA') DEFAULT 'NO_DATA',
    inventory_turnover_days INT NULL,
    
    -- Настройки товара
    min_stock_level INT DEFAULT 0,
    reorder_point INT DEFAULT 0,
    lead_time_days INT DEFAULT 14,
    
    -- Метаданные
    analysis_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Основные индексы
    KEY idx_product_analysis (product_id, analysis_date),
    KEY idx_sku_analysis (sku, analysis_date),
    KEY idx_priority_urgency (priority_level, urgency_score),
    KEY idx_source_analysis (source, analysis_date),
    KEY idx_stockout_days (days_until_stockout),
    
    -- Уникальный ключ
    UNIQUE KEY uk_product_analysis_date (product_id, analysis_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 2. СОЗДАНИЕ ТАБЛИЦЫ АЛЕРТОВ
-- ===================================================================

DROP TABLE IF EXISTS replenishment_alerts;
CREATE TABLE replenishment_alerts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- Информация о товаре
    product_id INT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    product_name VARCHAR(500),
    
    -- Тип и уровень алерта
    alert_type ENUM('STOCKOUT_CRITICAL', 'STOCKOUT_WARNING', 'SLOW_MOVING', 'OVERSTOCKED', 'NO_SALES') NOT NULL,
    alert_level ENUM('CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO') DEFAULT 'MEDIUM',
    
    -- Содержание алерта
    message TEXT NOT NULL,
    current_stock INT DEFAULT 0,
    days_until_stockout INT NULL,
    recommended_action TEXT,
    
    -- Статус обработки
    status ENUM('NEW', 'ACKNOWLEDGED', 'RESOLVED', 'IGNORED') DEFAULT 'NEW',
    acknowledged_by VARCHAR(255) NULL,
    acknowledged_at TIMESTAMP NULL,
    
    -- Метаданные
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Основные индексы
    KEY idx_product_alerts (product_id),
    KEY idx_sku_alerts (sku),
    KEY idx_alert_level_status (alert_level, status),
    KEY idx_alert_type (alert_type),
    KEY idx_created_date (created_at),
    KEY idx_status_acknowledged (status, acknowledged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 3. СОЗДАНИЕ ТАБЛИЦЫ ТОВАРОВ
-- ===================================================================

DROP TABLE IF EXISTS dim_products;
CREATE TABLE dim_products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(255) NOT NULL UNIQUE,
    product_name VARCHAR(500),
    source VARCHAR(50) NOT NULL,
    
    -- Финансовые данные
    cost_price DECIMAL(10,2) NULL,
    selling_price DECIMAL(10,2) NULL,
    current_price DECIMAL(10,2) NULL,
    
    -- Настройки пополнения
    min_stock_level INT DEFAULT 0,
    max_stock_level INT DEFAULT 0,
    reorder_point INT DEFAULT 0,
    lead_time_days INT DEFAULT 14,
    safety_stock_days INT DEFAULT 7,
    
    -- Статус
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Метаданные
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Основные индексы
    KEY idx_sku (sku),
    KEY idx_source_active (source, is_active),
    KEY idx_active_products (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 4. СОЗДАНИЕ ТАБЛИЦЫ ПРОДАЖ
-- ===================================================================

DROP TABLE IF EXISTS sales_data;
CREATE TABLE sales_data (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- Информация о товаре
    product_id INT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    source VARCHAR(50) NOT NULL,
    
    -- Данные о продаже
    sale_date DATE NOT NULL,
    quantity_sold INT NOT NULL DEFAULT 0,
    sale_price DECIMAL(10,2) NULL,
    total_amount DECIMAL(12,2) NULL,
    
    -- Метаданные
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Основные индексы
    KEY idx_product_date (product_id, sale_date),
    KEY idx_sku_date (sku, sale_date),
    KEY idx_source_date (source, sale_date),
    KEY idx_sale_date (sale_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 5. СОЗДАНИЕ ТАБЛИЦЫ ОСТАТКОВ
-- ===================================================================

DROP TABLE IF EXISTS inventory_data;
CREATE TABLE inventory_data (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- Информация о товаре
    product_id INT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    source VARCHAR(50) NOT NULL,
    
    -- Данные об остатках
    snapshot_date DATE NOT NULL,
    current_stock INT DEFAULT 0,
    reserved_stock INT DEFAULT 0,
    available_stock INT DEFAULT 0,
    
    -- Метаданные
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    -- Основные индексы
    KEY idx_product_snapshot (product_id, snapshot_date),
    KEY idx_sku_snapshot (sku, snapshot_date),
    KEY idx_source_snapshot (source, snapshot_date),
    KEY idx_snapshot_date (snapshot_date),
    
    -- Уникальный ключ
    UNIQUE KEY uk_product_snapshot (product_id, snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 6. СОЗДАНИЕ ТАБЛИЦЫ НАСТРОЕК СИСТЕМЫ
-- ===================================================================

DROP TABLE IF EXISTS replenishment_settings;
CREATE TABLE replenishment_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    
    -- Категория и ключ настройки
    category VARCHAR(50) NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL,
    setting_type ENUM('STRING', 'INTEGER', 'DECIMAL', 'BOOLEAN', 'JSON') DEFAULT 'STRING',
    
    -- Описание
    description TEXT NULL,
    
    -- Статус
    is_active BOOLEAN DEFAULT TRUE,
    
    -- Метаданные
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Уникальный ключ
    UNIQUE KEY uk_category_key (category, setting_key),
    
    -- Основные индексы
    KEY idx_category (category),
    KEY idx_active_settings (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 7. ВСТАВКА БАЗОВЫХ НАСТРОЕК
-- ===================================================================

INSERT INTO replenishment_settings (category, setting_key, setting_value, setting_type, description) VALUES
-- Настройки анализа
('ANALYSIS', 'critical_stockout_threshold', '3', 'INTEGER', 'Критический порог дней до исчерпания запасов'),
('ANALYSIS', 'high_priority_threshold', '7', 'INTEGER', 'Высокий приоритет - дней до исчерпания'),
('ANALYSIS', 'max_recommended_order_multiplier', '3.0', 'DECIMAL', 'Максимальный множитель для рекомендуемого заказа'),
('ANALYSIS', 'default_lead_time_days', '14', 'INTEGER', 'Время поставки по умолчанию (дни)'),
('ANALYSIS', 'default_safety_stock_days', '7', 'INTEGER', 'Страховой запас по умолчанию (дни)'),

-- Настройки уведомлений
('NOTIFICATIONS', 'enable_email_alerts', 'false', 'BOOLEAN', 'Включить email уведомления'),
('NOTIFICATIONS', 'alert_email_recipients', '[]', 'JSON', 'Список получателей email уведомлений'),

-- Настройки системы
('SYSTEM', 'max_analysis_products', '10000', 'INTEGER', 'Максимальное количество товаров для анализа'),
('SYSTEM', 'analysis_batch_size', '1000', 'INTEGER', 'Размер батча для обработки'),
('SYSTEM', 'log_level', 'INFO', 'STRING', 'Уровень логирования'),
('SYSTEM', 'data_retention_days', '90', 'INTEGER', 'Срок хранения данных анализа (дни)');

-- ===================================================================
-- 8. ДОБАВЛЕНИЕ ТЕСТОВЫХ ДАННЫХ
-- ===================================================================

-- Добавляем тестовые товары
INSERT INTO dim_products (sku, product_name, source, cost_price, selling_price, min_stock_level, is_active) VALUES
('TEST-001', 'Тестовый товар 1', 'test', 100.00, 150.00, 10, TRUE),
('TEST-002', 'Тестовый товар 2', 'test', 200.00, 280.00, 15, TRUE),
('TEST-003', 'Тестовый товар 3', 'test', 50.00, 75.00, 20, TRUE),
('DEMO-001', 'Демо товар 1', 'demo', 300.00, 450.00, 5, TRUE),
('DEMO-002', 'Демо товар 2', 'demo', 150.00, 225.00, 8, TRUE);

-- Добавляем тестовые остатки
INSERT INTO inventory_data (product_id, sku, source, snapshot_date, current_stock, available_stock) 
SELECT product_id, sku, source, CURDATE(), 
    CASE 
        WHEN sku LIKE 'TEST%' THEN 5 
        ELSE 3 
    END,
    CASE 
        WHEN sku LIKE 'TEST%' THEN 5 
        ELSE 3 
    END
FROM dim_products;

-- Добавляем тестовые продажи за последние 30 дней
INSERT INTO sales_data (product_id, sku, source, sale_date, quantity_sold, sale_price) 
SELECT 
    p.product_id, 
    p.sku, 
    p.source, 
    DATE_SUB(CURDATE(), INTERVAL FLOOR(RAND() * 30) DAY),
    FLOOR(RAND() * 3) + 1,
    p.selling_price
FROM dim_products p
CROSS JOIN (SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) AS numbers;

-- ===================================================================
-- ЗАВЕРШЕНИЕ СОЗДАНИЯ СХЕМЫ
-- ===================================================================

-- Показываем созданные таблицы
SELECT 'Безопасная схема создана успешно!' as status;

SELECT 
    TABLE_NAME as 'Таблица',
    TABLE_ROWS as 'Строк'
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;