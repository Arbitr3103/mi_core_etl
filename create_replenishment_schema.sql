-- ===================================================================
-- СОЗДАНИЕ СХЕМЫ ДЛЯ СИСТЕМЫ ПОПОЛНЕНИЯ СКЛАДА
-- ===================================================================

USE mi_core_db;

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
    current_stock INT NOT NULL DEFAULT 0,
    reserved_stock INT NOT NULL DEFAULT 0,
    available_stock INT NOT NULL DEFAULT 0,
    
    -- Анализ продаж (среднедневные продажи)
    daily_sales_rate_7d DECIMAL(10,2) NOT NULL DEFAULT 0,
    daily_sales_rate_14d DECIMAL(10,2) NOT NULL DEFAULT 0,
    daily_sales_rate_30d DECIMAL(10,2) NOT NULL DEFAULT 0,
    
    -- Прогнозы и рекомендации
    days_until_stockout INT NULL COMMENT 'Дни до исчерпания запасов',
    recommended_order_quantity INT NOT NULL DEFAULT 0,
    recommended_order_value DECIMAL(12,2) NULL COMMENT 'Стоимость рекомендуемого заказа',
    
    -- Приоритизация
    priority_level ENUM('CRITICAL', 'HIGH', 'MEDIUM', 'LOW') NOT NULL DEFAULT 'LOW',
    urgency_score DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Оценка срочности 0-100',
    
    -- Дополнительная аналитика
    last_sale_date DATE NULL COMMENT 'Дата последней продажи',
    last_restock_date DATE NULL COMMENT 'Дата последнего пополнения',
    sales_trend ENUM('GROWING', 'STABLE', 'DECLINING', 'NO_DATA') DEFAULT 'NO_DATA',
    inventory_turnover_days INT NULL COMMENT 'Оборачиваемость в днях',
    
    -- Настройки товара
    min_stock_level INT DEFAULT 0 COMMENT 'Минимальный уровень запасов',
    reorder_point INT DEFAULT 0 COMMENT 'Точка перезаказа',
    lead_time_days INT DEFAULT 14 COMMENT 'Время поставки в днях',
    
    -- Метаданные
    analysis_date DATE NOT NULL COMMENT 'Дата анализа',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Индексы для оптимизации
    INDEX idx_product_analysis (product_id, analysis_date),
    INDEX idx_priority_urgency (priority_level, urgency_score DESC),
    INDEX idx_source_date (source, analysis_date),
    INDEX idx_stockout_days (days_until_stockout ASC),
    INDEX idx_analysis_date (analysis_date DESC),
    
    -- Внешние ключи
    CONSTRAINT fk_replenishment_product 
        FOREIGN KEY (product_id) REFERENCES dim_products(id) 
        ON DELETE CASCADE ON UPDATE CASCADE,
        
    -- Уникальность: один анализ на товар в день
    UNIQUE KEY uk_product_date (product_id, source, analysis_date)
    
) ENGINE=InnoDB COMMENT='Рекомендации по пополнению запасов';

-- ===================================================================
-- 2. РАСШИРЕНИЕ ТАБЛИЦЫ ТОВАРОВ
-- ===================================================================

-- Добавляем колонки для настроек пополнения в dim_products
ALTER TABLE dim_products 
ADD COLUMN IF NOT EXISTS min_stock_level INT DEFAULT 0 COMMENT 'Минимальный уровень запасов',
ADD COLUMN IF NOT EXISTS max_stock_level INT DEFAULT 0 COMMENT 'Максимальный уровень запасов',
ADD COLUMN IF NOT EXISTS reorder_point INT DEFAULT 0 COMMENT 'Точка перезаказа',
ADD COLUMN IF NOT EXISTS lead_time_days INT DEFAULT 14 COMMENT 'Время поставки в днях',
ADD COLUMN IF NOT EXISTS safety_stock_days INT DEFAULT 7 COMMENT 'Страховой запас в днях',
ADD COLUMN IF NOT EXISTS supplier_id INT DEFAULT NULL COMMENT 'ID поставщика',
ADD COLUMN IF NOT EXISTS is_active_for_replenishment BOOLEAN DEFAULT TRUE COMMENT 'Активен для анализа пополнения',
ADD COLUMN IF NOT EXISTS category VARCHAR(100) DEFAULT NULL COMMENT 'Категория товара',
ADD COLUMN IF NOT EXISTS abc_class ENUM('A', 'B', 'C') DEFAULT NULL COMMENT 'ABC классификация';

-- ===================================================================
-- 3. ТАБЛИЦА ИСТОРИИ АЛЕРТОВ
-- ===================================================================

CREATE TABLE IF NOT EXISTS replenishment_alerts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- Связь с товаром
    product_id INT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    product_name VARCHAR(500),
    
    -- Тип и уровень алерта
    alert_type ENUM('STOCKOUT_CRITICAL', 'STOCKOUT_WARNING', 'SLOW_MOVING', 'OVERSTOCKED', 'NO_SALES') NOT NULL,
    alert_level ENUM('CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO') NOT NULL,
    
    -- Детали алерта
    message TEXT NOT NULL,
    current_stock INT NOT NULL,
    days_until_stockout INT NULL,
    recommended_action VARCHAR(500) NULL,
    
    -- Статус обработки
    status ENUM('NEW', 'ACKNOWLEDGED', 'IN_PROGRESS', 'RESOLVED', 'IGNORED') DEFAULT 'NEW',
    acknowledged_by VARCHAR(100) NULL,
    acknowledged_at TIMESTAMP NULL,
    resolved_at TIMESTAMP NULL,
    
    -- Метаданные
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Индексы
    INDEX idx_product_alert (product_id, alert_type),
    INDEX idx_status_level (status, alert_level),
    INDEX idx_created_date (created_at DESC),
    
    -- Внешние ключи
    CONSTRAINT fk_alert_product 
        FOREIGN KEY (product_id) REFERENCES dim_products(id) 
        ON DELETE CASCADE ON UPDATE CASCADE
        
) ENGINE=InnoDB COMMENT='История алертов по пополнению';

-- ===================================================================
-- 4. ТАБЛИЦА НАСТРОЕК СИСТЕМЫ
-- ===================================================================

CREATE TABLE IF NOT EXISTS replenishment_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    setting_type ENUM('STRING', 'INTEGER', 'DECIMAL', 'BOOLEAN', 'JSON') DEFAULT 'STRING',
    description VARCHAR(500) NULL,
    category VARCHAR(50) DEFAULT 'GENERAL',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_category_active (category, is_active)
    
) ENGINE=InnoDB COMMENT='Настройки системы пополнения';

-- ===================================================================
-- 5. ВСТАВКА НАСТРОЕК ПО УМОЛЧАНИЮ
-- ===================================================================

INSERT INTO replenishment_settings (setting_key, setting_value, setting_type, description, category) VALUES
('default_lead_time_days', '14', 'INTEGER', 'Время поставки по умолчанию в днях', 'INVENTORY'),
('default_safety_stock_days', '7', 'INTEGER', 'Страховой запас по умолчанию в днях', 'INVENTORY'),
('critical_stockout_threshold', '3', 'INTEGER', 'Критический порог до исчерпания запасов (дни)', 'ALERTS'),
('high_priority_threshold', '7', 'INTEGER', 'Высокий приоритет до исчерпания запасов (дни)', 'ALERTS'),
('slow_moving_threshold_days', '30', 'INTEGER', 'Порог для медленно движущихся товаров (дни без продаж)', 'ANALYTICS'),
('overstocked_threshold_days', '90', 'INTEGER', 'Порог для избыточных запасов (дни оборачиваемости)', 'ANALYTICS'),
('min_sales_history_days', '14', 'INTEGER', 'Минимальная история продаж для анализа (дни)', 'ANALYTICS'),
('max_recommended_order_multiplier', '3.0', 'DECIMAL', 'Максимальный множитель для рекомендуемого заказа', 'INVENTORY'),
('enable_email_alerts', 'true', 'BOOLEAN', 'Включить email уведомления', 'NOTIFICATIONS'),
('alert_email_recipients', '[]', 'JSON', 'Список получателей email алертов', 'NOTIFICATIONS')
ON DUPLICATE KEY UPDATE 
    setting_value = VALUES(setting_value),
    updated_at = CURRENT_TIMESTAMP;

-- ===================================================================
-- 6. СОЗДАНИЕ ПРЕДСТАВЛЕНИЙ ДЛЯ УДОБСТВА
-- ===================================================================

-- Представление для критических товаров
CREATE OR REPLACE VIEW v_critical_stock AS
SELECT 
    rr.product_id,
    rr.sku,
    rr.product_name,
    rr.source,
    rr.current_stock,
    rr.available_stock,
    rr.days_until_stockout,
    rr.daily_sales_rate_7d,
    rr.recommended_order_quantity,
    rr.priority_level,
    rr.urgency_score,
    rr.analysis_date
FROM replenishment_recommendations rr
WHERE rr.priority_level IN ('CRITICAL', 'HIGH')
    AND rr.analysis_date = (
        SELECT MAX(analysis_date) 
        FROM replenishment_recommendations 
        WHERE product_id = rr.product_id AND source = rr.source
    )
ORDER BY rr.urgency_score DESC, rr.days_until_stockout ASC;

-- Представление для медленно движущихся товаров
CREATE OR REPLACE VIEW v_slow_moving_inventory AS
SELECT 
    rr.product_id,
    rr.sku,
    rr.product_name,
    rr.source,
    rr.current_stock,
    rr.last_sale_date,
    DATEDIFF(CURDATE(), rr.last_sale_date) as days_since_last_sale,
    rr.daily_sales_rate_30d,
    rr.inventory_turnover_days,
    rr.analysis_date
FROM replenishment_recommendations rr
WHERE rr.sales_trend = 'DECLINING' 
    OR rr.last_sale_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    OR rr.daily_sales_rate_30d = 0
ORDER BY days_since_last_sale DESC;

-- Представление для сводной аналитики
CREATE OR REPLACE VIEW v_inventory_summary AS
SELECT 
    source,
    COUNT(*) as total_products,
    SUM(CASE WHEN priority_level = 'CRITICAL' THEN 1 ELSE 0 END) as critical_products,
    SUM(CASE WHEN priority_level = 'HIGH' THEN 1 ELSE 0 END) as high_priority_products,
    SUM(CASE WHEN days_until_stockout <= 7 THEN 1 ELSE 0 END) as products_stockout_week,
    SUM(current_stock) as total_current_stock,
    SUM(recommended_order_quantity) as total_recommended_orders,
    AVG(daily_sales_rate_7d) as avg_daily_sales_rate,
    analysis_date
FROM replenishment_recommendations
WHERE analysis_date = (SELECT MAX(analysis_date) FROM replenishment_recommendations)
GROUP BY source, analysis_date;

-- ===================================================================
-- 7. СОЗДАНИЕ ИНДЕКСОВ ДЛЯ ОПТИМИЗАЦИИ СУЩЕСТВУЮЩИХ ТАБЛИЦ
-- ===================================================================

-- Индексы для таблицы fact_orders (если еще не созданы)
CREATE INDEX IF NOT EXISTS idx_orders_product_date_type 
ON fact_orders(product_id, order_date, transaction_type);

CREATE INDEX IF NOT EXISTS idx_orders_date_range 
ON fact_orders(order_date DESC);

-- Индексы для таблицы inventory (если еще не созданы)
CREATE INDEX IF NOT EXISTS idx_inventory_product_source_updated 
ON inventory(product_id, source, updated_at);

CREATE INDEX IF NOT EXISTS idx_inventory_quantity_levels 
ON inventory(quantity_present, quantity_reserved);

-- ===================================================================
-- 8. ПРОЦЕДУРЫ ДЛЯ ОБСЛУЖИВАНИЯ
-- ===================================================================

DELIMITER //

-- Процедура очистки старых рекомендаций
CREATE PROCEDURE CleanupOldRecommendations(IN days_to_keep INT DEFAULT 30)
BEGIN
    DELETE FROM replenishment_recommendations 
    WHERE analysis_date < DATE_SUB(CURDATE(), INTERVAL days_to_keep DAY);
    
    SELECT ROW_COUNT() as deleted_records;
END //

-- Процедура очистки старых алертов
CREATE PROCEDURE CleanupOldAlerts(IN days_to_keep INT DEFAULT 90)
BEGIN
    DELETE FROM replenishment_alerts 
    WHERE status IN ('RESOLVED', 'IGNORED') 
        AND updated_at < DATE_SUB(CURDATE(), INTERVAL days_to_keep DAY);
    
    SELECT ROW_COUNT() as deleted_alerts;
END //

DELIMITER ;

-- ===================================================================
-- 9. ПРОВЕРКА СОЗДАННОЙ СТРУКТУРЫ
-- ===================================================================

-- Показываем созданные таблицы
SELECT 'СОЗДАННЫЕ ТАБЛИЦЫ:' as info;
SHOW TABLES LIKE '%replenishment%';

-- Показываем структуру основной таблицы
SELECT 'СТРУКТУРА ТАБЛИЦЫ replenishment_recommendations:' as info;
DESCRIBE replenishment_recommendations;

-- Показываем созданные представления
SELECT 'СОЗДАННЫЕ ПРЕДСТАВЛЕНИЯ:' as info;
SHOW FULL TABLES WHERE Table_type = 'VIEW' AND Tables_in_mi_core_db LIKE 'v_%';

-- Показываем настройки по умолчанию
SELECT 'НАСТРОЙКИ ПО УМОЛЧАНИЮ:' as info;
SELECT setting_key, setting_value, description 
FROM replenishment_settings 
ORDER BY category, setting_key;

SELECT '✅ СХЕМА СИСТЕМЫ ПОПОЛНЕНИЯ СОЗДАНА УСПЕШНО!' as result;