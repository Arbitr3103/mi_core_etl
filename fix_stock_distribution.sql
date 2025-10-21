-- Исправление распределения остатков для более реалистичной картины
-- Создаем реалистичное распределение: 10% критических, 20% низких, 10% избыточных, 60% нормальных

UPDATE inventory_data 
SET current_stock = CASE 
    -- 10% критических остатков (0-5 единиц)
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 0 THEN FLOOR(RAND() * 6)
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 9 THEN FLOOR(RAND() * 6)
    
    -- 20% низких остатков (6-20 единиц)  
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 1 THEN 6 + FLOOR(RAND() * 15)
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 2 THEN 6 + FLOOR(RAND() * 15)
    
    -- 10% избыточных остатков (101-300 единиц)
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 8 THEN 101 + FLOOR(RAND() * 200)
    
    -- 60% нормальных остатков (21-100 единиц)
    ELSE 21 + FLOOR(RAND() * 80)
END,
available_stock = CASE 
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 0 THEN FLOOR(RAND() * 6)
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 9 THEN FLOOR(RAND() * 6)
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 1 THEN 6 + FLOOR(RAND() * 15)
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 2 THEN 6 + FLOOR(RAND() * 15)
    WHEN MOD(CAST(SUBSTRING(sku, 1, 1) AS UNSIGNED), 10) = 8 THEN 101 + FLOOR(RAND() * 200)
    ELSE 21 + FLOOR(RAND() * 80)
END,
reserved_stock = FLOOR(RAND() * 5),
last_sync_at = NOW()
WHERE sku NOT LIKE 'TEST%';