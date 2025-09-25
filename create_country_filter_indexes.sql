-- SQL скрипт для создания индексов для оптимизации фильтра по странам
-- Выполняется в рамках задачи 6: Оптимизация производительности и кэширование

-- Индекс для связи марок и регионов (основной для фильтрации по странам)
CREATE INDEX IF NOT EXISTS idx_brands_region_id ON brands(region_id);

-- Индекс для связи моделей и марок (для фильтрации по модели -> марка -> страна)
CREATE INDEX IF NOT EXISTS idx_car_models_brand_id ON car_models(brand_id);

-- Индекс для связи спецификаций и моделей (для фильтрации товаров)
CREATE INDEX IF NOT EXISTS idx_car_specifications_model_id ON car_specifications(car_model_id);

-- Индекс для связи товаров и спецификаций (основной для фильтрации товаров)
CREATE INDEX IF NOT EXISTS idx_dim_products_specification_id ON dim_products(specification_id);

-- Составной индекс для годов выпуска (для быстрой фильтрации по году)
CREATE INDEX IF NOT EXISTS idx_car_specifications_years ON car_specifications(year_start, year_end);

-- Индекс для названий регионов (для быстрой сортировки стран)
CREATE INDEX IF NOT EXISTS idx_regions_name ON regions(name);

-- Индекс для названий марок (для быстрой сортировки)
CREATE INDEX IF NOT EXISTS idx_brands_name ON brands(name);

-- Индекс для названий моделей (для быстрой сортировки)
CREATE INDEX IF NOT EXISTS idx_car_models_name ON car_models(name);

-- Составной индекс для оптимизации запросов фильтрации товаров
-- Покрывает основные поля для JOIN операций
CREATE INDEX IF NOT EXISTS idx_products_filter_composite ON dim_products(specification_id, product_name);

-- Составной индекс для быстрого поиска стран по марке
CREATE INDEX IF NOT EXISTS idx_brands_region_composite ON brands(id, region_id);

-- Составной индекс для быстрого поиска стран по модели
CREATE INDEX IF NOT EXISTS idx_models_brand_composite ON car_models(id, brand_id);

-- Проверка созданных индексов
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX,
    INDEX_TYPE
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME IN ('brands', 'car_models', 'car_specifications', 'dim_products', 'regions')
  AND INDEX_NAME LIKE 'idx_%'
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;