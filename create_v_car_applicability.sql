-- ===================================================================
-- СОЗДАНИЕ ПРЕДСТАВЛЕНИЯ v_car_applicability
-- Представление для удобной работы с применимостью товаров к автомобилям
-- ===================================================================

USE mi_core_db;

CREATE OR REPLACE VIEW v_car_applicability AS
SELECT 
    -- Идентификаторы
    cs.id as specification_id,
    cs.car_model_id,
    cm.brand_id,
    r.id as region_id,
    
    -- Информация о регионе
    r.name as region_name,
    
    -- Информация о марке
    b.id as brand_id,
    b.name as brand_name,
    
    -- Информация о модели
    cm.id as model_id,
    cm.name as model_name,
    
    -- Информация о спецификации (годы производства)
    cs.year_start,
    cs.year_end,
    
    -- Технические характеристики для фильтрации
    cs.pcd,
    cs.dia,
    cs.fastener_type,
    cs.fastener_params,
    
    -- Удобные поля для фильтрации
    CONCAT(b.name, ' ', cm.name) as full_car_name,
    
    -- Период производства в удобном формате
    CASE 
        WHEN cs.year_end IS NULL THEN CONCAT(cs.year_start, ' - н.в.')
        WHEN cs.year_start = cs.year_end THEN CAST(cs.year_start AS CHAR)
        ELSE CONCAT(cs.year_start, ' - ', cs.year_end)
    END as production_period,
    
    -- Флаги для удобной фильтрации
    CASE WHEN cs.pcd IS NOT NULL AND cs.pcd != '' THEN 1 ELSE 0 END as has_pcd,
    CASE WHEN cs.dia IS NOT NULL THEN 1 ELSE 0 END as has_dia,
    CASE WHEN cs.fastener_type IS NOT NULL THEN 1 ELSE 0 END as has_fastener_info

FROM car_specifications cs
JOIN car_models cm ON cs.car_model_id = cm.id
JOIN brands b ON cm.brand_id = b.id
LEFT JOIN regions r ON b.region_id = r.id

ORDER BY 
    r.name,
    b.name,
    cm.name,
    cs.year_start;

-- ===================================================================
-- КОММЕНТАРИИ К ПРЕДСТАВЛЕНИЮ
-- ===================================================================

/*
НАЗНАЧЕНИЕ:
Представление v_car_applicability предоставляет полную информацию о применимости 
товаров к автомобилям, объединяя данные из таблиц regions, brands, car_models 
и car_specifications.

ОСНОВНЫЕ ВОЗМОЖНОСТИ:
1. Фильтрация по маркам и моделям автомобилей
2. Фильтрация по техническим характеристикам (PCD, DIA, тип крепежа)
3. Фильтрация по годам производства
4. Поиск по полному названию автомобиля
5. Группировка по регионам/типам транспорта

ПРИМЕРЫ ИСПОЛЬЗОВАНИЯ:

-- Получить все автомобили с известным PCD
SELECT * FROM v_car_applicability WHERE has_pcd = 1;

-- Найти автомобили BMW с PCD 5x120
SELECT * FROM v_car_applicability 
WHERE brand_name = 'BMW' AND pcd = '5x120';

-- Получить все модели определенной марки
SELECT DISTINCT model_name, production_period 
FROM v_car_applicability 
WHERE brand_name = 'Toyota'
ORDER BY model_name;

-- Фильтр по году производства
SELECT * FROM v_car_applicability 
WHERE year_start <= 2020 AND (year_end IS NULL OR year_end >= 2020);

-- Поиск по части названия
SELECT * FROM v_car_applicability 
WHERE full_car_name LIKE '%BMW X5%';

*/