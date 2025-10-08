-- Создание локальной мастер таблицы товаров
-- Копия структуры dim_products для локального использования

USE mi_core;

-- Создаем таблицу product_master
CREATE TABLE IF NOT EXISTS product_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    master_id INT NOT NULL COMMENT 'ID из dim_products',
    sku_ozon VARCHAR(255) NOT NULL COMMENT 'SKU товара в Ozon',
    sku_wb VARCHAR(50) COMMENT 'SKU товара в Wildberries',
    barcode VARCHAR(255) COMMENT 'Штрихкод товара',
    product_name VARCHAR(500) COMMENT 'Основное название товара',
    name VARCHAR(500) COMMENT 'Альтернативное название товара',
    brand VARCHAR(255) COMMENT 'Бренд товара',
    category VARCHAR(255) COMMENT 'Категория товара',
    cost_price DECIMAL(10,2) COMMENT 'Себестоимость товара',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Время последней синхронизации',
    
    -- Индексы для оптимизации
    UNIQUE KEY uk_sku_ozon (sku_ozon),
    UNIQUE KEY uk_master_id (master_id),
    INDEX idx_sku_wb (sku_wb),
    INDEX idx_brand (brand),
    INDEX idx_category (category),
    INDEX idx_synced_at (synced_at),
    INDEX idx_product_name (product_name(100)),
    INDEX idx_updated_at (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Локальная копия мастер таблицы товаров из dim_products';

-- Создаем представление для удобного доступа к данным
CREATE OR REPLACE VIEW v_product_info AS
SELECT 
    pm.id,
    pm.master_id,
    pm.sku_ozon,
    pm.sku_wb,
    pm.barcode,
    pm.product_name,
    pm.name as alternative_name,
    pm.brand,
    pm.category,
    pm.cost_price,
    -- Формируем полное название товара
    COALESCE(
        pm.product_name,
        pm.name,
        CONCAT('Товар ', pm.sku_ozon)
    ) as display_name,
    -- Формируем расширенное название с брендом и категорией
    CONCAT(
        COALESCE(pm.product_name, pm.name, pm.sku_ozon),
        CASE WHEN pm.brand IS NOT NULL AND pm.brand != '' 
             THEN CONCAT(' (', pm.brand, ')') 
             ELSE '' END,
        CASE WHEN pm.category IS NOT NULL AND pm.category != '' 
             THEN CONCAT(' [', pm.category, ']') 
             ELSE '' END
    ) as full_display_name,
    pm.created_at,
    pm.updated_at,
    pm.synced_at
FROM product_master pm;

-- Создаем представление для инвентаря с полной информацией о товарах
CREATE OR REPLACE VIEW v_inventory_with_products AS
SELECT 
    i.id,
    i.product_id,
    i.sku,
    i.source,
    i.warehouse_name,
    i.stock_type,
    i.current_stock,
    i.reserved_stock,
    i.available_stock,
    i.quantity_present,
    i.quantity_reserved,
    i.snapshot_date,
    i.last_sync_at,
    
    -- Информация о товаре из мастер таблицы
    pm.master_id,
    pm.product_name,
    pm.name as alternative_name,
    pm.brand,
    pm.category,
    pm.cost_price,
    
    -- Отображаемые названия
    COALESCE(
        pm.product_name,
        pm.name,
        CASE 
            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
            ELSE i.sku
        END
    ) as display_name,
    
    CONCAT(
        COALESCE(pm.product_name, pm.name, i.sku),
        CASE WHEN pm.brand IS NOT NULL AND pm.brand != '' 
             THEN CONCAT(' (', pm.brand, ')') 
             ELSE '' END,
        CASE WHEN pm.category IS NOT NULL AND pm.category != '' 
             THEN CONCAT(' [', pm.category, ']') 
             ELSE '' END
    ) as full_display_name,
    
    -- Источник названия
    CASE 
        WHEN pm.product_name IS NOT NULL THEN 'Мастер таблица'
        WHEN i.sku REGEXP '^[0-9]+$' THEN 'Fallback (числовой)'
        ELSE 'Fallback (текстовый)'
    END as name_source
    
FROM inventory_data i
LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
WHERE i.current_stock > 0;

-- Создаем функцию для получения информации о товаре по SKU
DELIMITER //
CREATE OR REPLACE FUNCTION get_product_display_name(input_sku VARCHAR(255))
RETURNS VARCHAR(1000)
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE result VARCHAR(1000);
    
    SELECT 
        CONCAT(
            COALESCE(pm.product_name, pm.name, input_sku),
            CASE WHEN pm.brand IS NOT NULL AND pm.brand != '' 
                 THEN CONCAT(' (', pm.brand, ')') 
                 ELSE '' END,
            CASE WHEN pm.category IS NOT NULL AND pm.category != '' 
                 THEN CONCAT(' [', pm.category, ']') 
                 ELSE '' END
        )
    INTO result
    FROM product_master pm
    WHERE pm.sku_ozon = input_sku
    LIMIT 1;
    
    -- Если товар не найден в мастер таблице, используем fallback
    IF result IS NULL THEN
        IF input_sku REGEXP '^[0-9]+$' THEN
            SET result = CONCAT('Товар артикул ', input_sku);
        ELSE
            SET result = input_sku;
        END IF;
    END IF;
    
    RETURN result;
END//
DELIMITER ;

-- Создаем процедуру для обновления статистики
DELIMITER //
CREATE OR REPLACE PROCEDURE update_product_master_stats()
BEGIN
    DECLARE total_products INT DEFAULT 0;
    DECLARE with_names INT DEFAULT 0;
    DECLARE with_brands INT DEFAULT 0;
    DECLARE with_categories INT DEFAULT 0;
    
    SELECT 
        COUNT(*),
        COUNT(CASE WHEN product_name IS NOT NULL AND product_name != '' THEN 1 END),
        COUNT(CASE WHEN brand IS NOT NULL AND brand != '' THEN 1 END),
        COUNT(CASE WHEN category IS NOT NULL AND category != '' THEN 1 END)
    INTO total_products, with_names, with_brands, with_categories
    FROM product_master;
    
    -- Можно добавить логирование статистики в отдельную таблицу
    SELECT 
        total_products as 'Всего товаров',
        with_names as 'С названиями',
        with_brands as 'С брендами',
        with_categories as 'С категориями',
        ROUND((with_names / total_products) * 100, 2) as 'Покрытие названиями %',
        NOW() as 'Время обновления';
END//
DELIMITER ;

-- Создаем триггер для автоматического обновления synced_at
DELIMITER //
CREATE OR REPLACE TRIGGER tr_product_master_update
    BEFORE UPDATE ON product_master
    FOR EACH ROW
BEGIN
    SET NEW.synced_at = CURRENT_TIMESTAMP;
END//
DELIMITER ;

-- Добавляем начальные тестовые данные (если нужно)
INSERT IGNORE INTO product_master (
    master_id, sku_ozon, product_name, brand, category
) VALUES 
(1, '257202054', 'Тестовый товар 1', 'TestBrand', 'TestCategory'),
(2, '161875896', 'Тестовый товар 2', 'TestBrand', 'TestCategory'),
(3, '161875313', 'Тестовый товар 3', 'TestBrand', 'TestCategory');

-- Показываем созданные объекты
SHOW TABLES LIKE '%product%';
SHOW CREATE VIEW v_product_info;
SELECT 'Таблица product_master создана успешно' as status;