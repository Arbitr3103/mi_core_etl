-- ===================================================================
-- СОЗДАНИЕ СХЕМЫ ДЛЯ СИСТЕМЫ ПОПОЛНЕНИЯ СКЛАДА
-- Отдельная база данных: replenishment_db
-- ===================================================================

-- Убираем USE - будем подключаться напрямую к нужной БД
-- USE replenishment_db; -- Не нужно, так как подключаемся через mysql -u user -p database

-- ===================================================================
-- 1. СОЗДАНИЕ ОСНОВНОЙ ТАБЛИЦЫ РЕКОМЕНДАЦИЙ ПО ПОПОЛНЕНИЮ
-- ===================================================================

CREATE TABLE IF NOT EXISTS replenishment_recommendations (
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
    
    -- Индексы
    INDEX idx_product_analysis (product_id, analysis_date),
    INDEX idx_sku_analysis (sku, analysis_date),
    INDEX idx_priority_urgency (priority_level, urgency_score DESC),
    INDEX idx_source_analysis (source, analysis_date),
    INDEX idx_stockout_days (days_until_stockout),
    
    -- Уникальный ключ для предотвращения дублей
    UNIQUE KEY uk_product_analysis_date (product_id, analysis_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 2. СОЗДАНИЕ ТАБЛИЦЫ АЛЕРТОВ
-- ===================================================================

CREATE TABLE IF NOT EXISTS replenishment_alerts (
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
    
    -- Индексы
    INDEX idx_product_alerts (product_id),
    INDEX idx_sku_alerts (sku),
    INDEX idx_alert_level_status (alert_level, status),
    INDEX idx_alert_type (alert_type),
    INDEX idx_created_date (created_at),
    INDEX idx_status_acknowledged (status, acknowledged_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 3. СОЗДАНИЕ ТАБЛИЦЫ ТОВАРОВ (упрощенная версия)
-- ===================================================================

CREATE TABLE IF NOT EXISTS dim_products (
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
    
    -- Индексы
    INDEX idx_sku (sku),
    INDEX idx_source_active (source, is_active),
    INDEX idx_active_products (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 4. СОЗДАНИЕ ТАБЛИЦЫ ПРОДАЖ (для анализа)
-- ===================================================================

CREATE TABLE IF NOT EXISTS sales_data (
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
    
    -- Индексы
    INDEX idx_product_date (product_id, sale_date),
    INDEX idx_sku_date (sku, sale_date),
    INDEX idx_source_date (source, sale_date),
    INDEX idx_sale_date (sale_date),
    
    -- Внешний ключ
    FOREIGN KEY (product_id) REFERENCES dim_products(product_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 5. СОЗДАНИЕ ТАБЛИЦЫ ОСТАТКОВ (для анализа)
-- ===================================================================

CREATE TABLE IF NOT EXISTS inventory_data (
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
    
    -- Индексы
    INDEX idx_product_snapshot (product_id, snapshot_date),
    INDEX idx_sku_snapshot (sku, snapshot_date),
    INDEX idx_source_snapshot (source, snapshot_date),
    INDEX idx_snapshot_date (snapshot_date),
    
    -- Уникальный ключ
    UNIQUE KEY uk_product_snapshot (product_id, snapshot_date),
    
    -- Внешний ключ
    FOREIGN KEY (product_id) REFERENCES dim_products(product_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 6. СОЗДАНИЕ ТАБЛИЦЫ НАСТРОЕК СИСТЕМЫ
-- ===================================================================

CREATE TABLE IF NOT EXISTS replenishment_settings (
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
    
    -- Индексы
    INDEX idx_category (category),
    INDEX idx_active_settings (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================================================================
-- 7. ВСТАВКА БАЗОВЫХ НАСТРОЕК
-- ===================================================================

INSERT IGNORE INTO replenishment_settings (category, setting_key, setting_value, setting_type, description) VALUES
-- Настройки анализа
('ANALYSIS', 'critical_stockout_threshold', '3', 'INTEGER', 'Критический порог дней до исчерпания запасов'),
('ANALYSIS', 'high_priority_threshold', '7', 'INTEGER', 'Высокий приоритет - дней до исчерпания'),
('ANALYSIS', 'max_recommended_order_multiplier', '3.0', 'DECIMAL', 'Максимальный множитель для рекомендуемого заказа'),
('ANALYSIS', 'default_lead_time_days', '14', 'INTEGER', 'Время поставки по умолчанию (дни)'),
('ANALYSIS', 'default_safety_stock_days', '7', 'INTEGER', 'Страховой запас по умолчанию (дни)'),

-- Настройки уведомлений
('NOTIFICATIONS', 'enable_email_alerts', 'false', 'BOOLEAN', 'Включить email уведомления'),
('NOTIFICATIONS', 'alert_email_recipients', '[]', 'JSON', 'Список получателей email уведомлений'),
('NOTIFICATIONS', 'email_smtp_host', '', 'STRING', 'SMTP сервер для отправки email'),
('NOTIFICATIONS', 'email_smtp_port', '587', 'INTEGER', 'Порт SMTP сервера'),

-- Настройки системы
('SYSTEM', 'max_analysis_products', '10000', 'INTEGER', 'Максимальное количество товаров для анализа'),
('SYSTEM', 'analysis_batch_size', '1000', 'INTEGER', 'Размер батча для обработки'),
('SYSTEM', 'log_level', 'INFO', 'STRING', 'Уровень логирования'),
('SYSTEM', 'data_retention_days', '90', 'INTEGER', 'Срок хранения данных анализа (дни)');

-- ===================================================================
-- 8. СОЗДАНИЕ ПРЕДСТАВЛЕНИЙ ДЛЯ УДОБСТВА
-- ===================================================================

-- Представление для активных критических рекомендаций
CREATE OR REPLACE VIEW v_critical_recommendations AS
SELECT 
    rr.*,
    dp.cost_price,
    dp.selling_price
FROM replenishment_recommendations rr
LEFT JOIN dim_products dp ON rr.product_id = dp.product_id
WHERE rr.priority_level IN ('CRITICAL', 'HIGH')
    AND rr.analysis_date = (
        SELECT MAX(analysis_date) 
        FROM replenishment_recommendations rr2 
        WHERE rr2.product_id = rr.product_id
    )
    AND dp.is_active = TRUE
ORDER BY rr.urgency_score DESC, rr.days_until_stockout ASC;

-- Представление для активных алертов
CREATE OR REPLACE VIEW v_active_alerts AS
SELECT 
    ra.*,
    dp.cost_price,
    dp.selling_price
FROM replenishment_alerts ra
LEFT JOIN dim_products dp ON ra.product_id = dp.product_id
WHERE ra.status = 'NEW'
    AND ra.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND dp.is_active = TRUE
ORDER BY 
    CASE ra.alert_level
        WHEN 'CRITICAL' THEN 4
        WHEN 'HIGH' THEN 3
        WHEN 'MEDIUM' THEN 2
        WHEN 'LOW' THEN 1
        ELSE 0
    END DESC,
    ra.created_at DESC;

-- ===================================================================
-- 9. СОЗДАНИЕ ПРОЦЕДУР ДЛЯ ОЧИСТКИ СТАРЫХ ДАННЫХ
-- ===================================================================

DELIMITER //

CREATE PROCEDURE CleanOldRecommendations(IN retention_days INT)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    
    START TRANSACTION;
    
    -- Удаляем старые рекомендации
    DELETE FROM replenishment_recommendations 
    WHERE analysis_date < DATE_SUB(CURDATE(), INTERVAL retention_days DAY);
    
    -- Удаляем старые алерты
    DELETE FROM replenishment_alerts 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL retention_days DAY)
        AND status IN ('RESOLVED', 'IGNORED');
    
    COMMIT;
    
    SELECT ROW_COUNT() as deleted_rows;
END //

DELIMITER ;

-- ===================================================================
-- 10. СОЗДАНИЕ ИНДЕКСОВ ДЛЯ ПРОИЗВОДИТЕЛЬНОСТИ
-- ===================================================================

-- Дополнительные индексы для оптимизации запросов
CREATE INDEX IF NOT EXISTS idx_recommendations_priority_date 
    ON replenishment_recommendations(priority_level, analysis_date DESC);

CREATE INDEX IF NOT EXISTS idx_recommendations_urgency 
    ON replenishment_recommendations(urgency_score DESC);

CREATE INDEX IF NOT EXISTS idx_alerts_level_created 
    ON replenishment_alerts(alert_level, created_at DESC);

CREATE INDEX IF NOT EXISTS idx_products_active_source 
    ON dim_products(is_active, source);

-- ===================================================================
-- ЗАВЕРШЕНИЕ СОЗДАНИЯ СХЕМЫ
-- ===================================================================

-- Показываем созданные таблицы
SELECT 'Schema created successfully!' as status;
SHOW TABLES;