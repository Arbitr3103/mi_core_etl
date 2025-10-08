-- Простое создание локальной мастер таблицы товаров
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