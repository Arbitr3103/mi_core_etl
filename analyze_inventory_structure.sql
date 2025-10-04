-- Анализ структуры таблиц для остатков товаров
-- Выполните эти запросы в DBeaver для понимания структуры данных

-- 1. Поиск таблиц, связанных с остатками
SHOW TABLES LIKE '%inventory%';
SHOW TABLES LIKE '%stock%';
SHOW TABLES LIKE '%остат%';
SHOW TABLES LIKE '%warehouse%';
SHOW TABLES LIKE '%склад%';

-- 2. Проверим структуру таблицы dim_products (уже знаем что есть)
DESCRIBE dim_products;

-- 3. Поиск таблиц с остатками через информационную схему
SELECT TABLE_NAME, TABLE_COMMENT 
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'mi_core_db' 
AND (TABLE_NAME LIKE '%inventory%' 
     OR TABLE_NAME LIKE '%stock%' 
     OR TABLE_NAME LIKE '%warehouse%'
     OR TABLE_COMMENT LIKE '%остат%'
     OR TABLE_COMMENT LIKE '%склад%');

-- 4. Посмотрим на все таблицы в базе
SELECT TABLE_NAME, TABLE_ROWS, TABLE_COMMENT 
FROM INFORMATION_SCHEMA.TABLES 
WHERE TABLE_SCHEMA = 'mi_core_db' 
ORDER BY TABLE_NAME;

-- 5. Проверим, есть ли поля остатков в существующих таблицах
SELECT COLUMN_NAME, TABLE_NAME, DATA_TYPE, COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'mi_core_db' 
AND (COLUMN_NAME LIKE '%stock%' 
     OR COLUMN_NAME LIKE '%inventory%' 
     OR COLUMN_NAME LIKE '%quantity%'
     OR COLUMN_NAME LIKE '%qty%'
     OR COLUMN_NAME LIKE '%остат%'
     OR COLUMN_NAME LIKE '%количеств%');