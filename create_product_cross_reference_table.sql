-- Создание таблицы product_cross_reference для решения проблем с типами данных
-- Эта таблица решает проблему несовместимости типов данных между разными эндпоинтами

CREATE TABLE IF NOT EXISTS product_cross_reference (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    
    -- Унифицированные VARCHAR поля для всех типов ID (решает проблему INT vs VARCHAR)
    inventory_product_id VARCHAR(50) NOT NULL COMMENT 'ID товара из inventory_data',
    analytics_product_id VARCHAR(50) NULL COMMENT 'ID товара из analytics API',
    ozon_product_id VARCHAR(50) NULL COMMENT 'ID товара из product info API',
    sku_ozon VARCHAR(50) NULL COMMENT 'SKU для совместимости с dim_products',
    
    -- Поля для кэширования названий товаров (fallback данные)
    cached_name VARCHAR(500) NULL COMMENT 'Кэшированное название товара',
    cached_brand VARCHAR(200) NULL COMMENT 'Кэшированный бренд товара',
    cached_category VARCHAR(200) NULL COMMENT 'Кэшированная категория товара',
    
    -- Поле sync_status для отслеживания состояния синхронизации
    sync_status ENUM('pending', 'synced', 'failed', 'needs_review') DEFAULT 'pending' COMMENT 'Статус синхронизации',
    last_sync_attempt TIMESTAMP NULL COMMENT 'Время последней попытки синхронизации',
    last_successful_sync TIMESTAMP NULL COMMENT 'Время последней успешной синхронизации',
    sync_error_message TEXT NULL COMMENT 'Сообщение об ошибке синхронизации',
    
    -- Метаданные
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Индексы для быстрого поиска по разным типам ID
    INDEX idx_inventory_product_id (inventory_product_id),
    INDEX idx_analytics_product_id (analytics_product_id),
    INDEX idx_ozon_product_id (ozon_product_id),
    INDEX idx_sku_ozon (sku_ozon),
    INDEX idx_sync_status (sync_status),
    INDEX idx_last_sync (last_successful_sync),
    
    -- Уникальные ограничения для предотвращения дублирования
    UNIQUE KEY uk_inventory_product_id (inventory_product_id),
    UNIQUE KEY uk_ozon_product_id (ozon_product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Таблица сопоставления ID товаров из разных источников';