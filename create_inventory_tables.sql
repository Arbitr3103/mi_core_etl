-- ===================================================================
-- МИГРАЦИЯ: Создание таблиц для системы управления складом
-- Дата: 20 сентября 2025
-- Описание: Добавляет таблицы inventory и stock_movements для отслеживания
--           остатков и движений товаров с маркетплейсов Ozon и Wildberries
-- ===================================================================

USE mi_core_db;

-- ===================================================================
-- ТАБЛИЦА 1: inventory (Текущие остатки на складах)
-- ===================================================================
CREATE TABLE IF NOT EXISTS inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,                 -- Внешний ключ на dim_products.id
    warehouse_name VARCHAR(255) NOT NULL,    -- Название склада (например, "Хоругвино" или "FBS-Склад-Москва")
    stock_type ENUM('FBO', 'FBS') NOT NULL,  -- Тип склада
    quantity_present INT DEFAULT 0,          -- Доступно к продаже
    quantity_reserved INT DEFAULT 0,         -- Зарезервировано
    source ENUM('Ozon', 'Wildberries') NOT NULL, -- Источник данных
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, -- Дата обновления записи
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Дата создания записи
    
    -- Индексы и ограничения
    UNIQUE KEY unique_product_warehouse (product_id, warehouse_name, source),
    INDEX idx_source_warehouse (source, warehouse_name),
    INDEX idx_product_source (product_id, source),
    INDEX idx_updated_at (updated_at),
    
    FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Текущие остатки товаров на складах маркетплейсов';

-- ===================================================================
-- ТАБЛИЦА 2: stock_movements (История движений товаров)
-- ===================================================================
CREATE TABLE IF NOT EXISTS stock_movements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    movement_id VARCHAR(255) NOT NULL,       -- Уникальный ID операции из системы маркетплейса
    product_id INT NOT NULL,                 -- Внешний ключ на dim_products.id
    movement_date TIMESTAMP NOT NULL,        -- Дата и время операции
    movement_type VARCHAR(50) NOT NULL,      -- Тип движения (sale, return, disposal, loss, etc.)
    quantity INT NOT NULL,                   -- Количество. Отрицательное для расхода, положительное для прихода
    warehouse_name VARCHAR(255),             -- Склад, на котором произошло движение
    order_id VARCHAR(255),                   -- ID заказа (если применимо)
    source ENUM('Ozon', 'Wildberries') NOT NULL, -- Источник данных
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, -- Дата добавления записи в нашу БД
    
    -- Индексы и ограничения
    UNIQUE KEY unique_movement (movement_id, product_id, source),
    INDEX idx_product_date (product_id, movement_date),
    INDEX idx_source_type (source, movement_type),
    INDEX idx_movement_date (movement_date),
    INDEX idx_order_id (order_id),
    
    FOREIGN KEY (product_id) REFERENCES dim_products(id) ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='История движений товаров по складам';

-- ===================================================================
-- ПРЕДСТАВЛЕНИЯ ДЛЯ УДОБНОГО АНАЛИЗА
-- ===================================================================

-- Представление: Текущие остатки с информацией о товарах
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
    i.updated_at
FROM inventory i
JOIN dim_products dp ON i.product_id = dp.id;

-- Представление: Движения товаров с информацией о товарах
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

-- Представление: Оборачиваемость товаров за последние 30 дней
CREATE OR REPLACE VIEW v_product_turnover_30d AS
SELECT 
    dp.id as product_id,
    dp.sku_ozon,
    dp.sku_wb,
    dp.product_name,
    SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as total_sold_30d,
    AVG(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as avg_daily_sales,
    COUNT(DISTINCT DATE(sm.movement_date)) as active_days,
    COALESCE(SUM(i.quantity_present), 0) as current_stock,
    CASE 
        WHEN SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) > 0 
        THEN COALESCE(SUM(i.quantity_present), 0) / (SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) / 30.0)
        ELSE NULL 
    END as days_of_stock
FROM dim_products dp
LEFT JOIN stock_movements sm ON dp.id = sm.product_id 
    AND sm.movement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    AND sm.movement_type IN ('sale', 'order')
LEFT JOIN inventory i ON dp.id = i.product_id
GROUP BY dp.id, dp.sku_ozon, dp.sku_wb, dp.product_name;

-- ===================================================================
-- ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ ЗАПРОСОВ
-- ===================================================================

-- Дополнительные индексы для быстрого поиска
ALTER TABLE inventory ADD INDEX idx_quantity_present (quantity_present);
ALTER TABLE stock_movements ADD INDEX idx_quantity (quantity);
ALTER TABLE stock_movements ADD INDEX idx_movement_type_date (movement_type, movement_date);

-- ===================================================================
-- ЗАВЕРШЕНИЕ МИГРАЦИИ
-- ===================================================================

-- Проверяем создание таблиц
SELECT 
    TABLE_NAME,
    TABLE_ROWS,
    CREATE_TIME
FROM information_schema.TABLES 
WHERE TABLE_SCHEMA = 'mi_core_db' 
AND TABLE_NAME IN ('inventory', 'stock_movements')
ORDER BY TABLE_NAME;
