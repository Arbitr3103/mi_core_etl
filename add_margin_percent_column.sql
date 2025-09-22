-- ===================================================================
-- МИГРАЦИЯ: ДОБАВЛЕНИЕ ПОДДЕРЖКИ РАСЧЕТА ПРОЦЕНТА МАРЖИНАЛЬНОСТИ
-- ===================================================================

USE mi_core_db;

-- 1. Проверяем текущую структуру таблицы metrics_daily
SELECT 'Текущая структура metrics_daily:' as info;
DESCRIBE metrics_daily;

-- 2. Проверяем, существует ли уже колонка margin_percent
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'mi_core_db' 
        AND TABLE_NAME = 'metrics_daily' 
        AND COLUMN_NAME = 'margin_percent'
);

-- 3. Добавляем колонку margin_percent если её нет
SET @sql = IF(
    @col_exists = 0, 
    'ALTER TABLE metrics_daily ADD COLUMN margin_percent DECIMAL(8,4) NULL COMMENT "Процент маржинальности" AFTER profit_sum',
    'SELECT "Колонка margin_percent уже существует" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Проверяем результат
SELECT 'Обновленная структура metrics_daily:' as info;
DESCRIBE metrics_daily;

-- ===================================================================
-- СОЗДАНИЕ ИНДЕКСОВ ДЛЯ ОПТИМИЗАЦИИ ПРОИЗВОДИТЕЛЬНОСТИ
-- ===================================================================

-- 5. Создаем индексы для fact_orders если их нет
-- Индекс для быстрого поиска по дате и клиенту
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'mi_core_db' 
        AND TABLE_NAME = 'fact_orders' 
        AND INDEX_NAME = 'idx_fact_orders_date_client'
);

SET @sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_fact_orders_date_client ON fact_orders(order_date, client_id)',
    'SELECT "Индекс idx_fact_orders_date_client уже существует" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Индекс для JOIN с dim_products
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'mi_core_db' 
        AND TABLE_NAME = 'fact_orders' 
        AND INDEX_NAME = 'idx_fact_orders_product'
);

SET @sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_fact_orders_product ON fact_orders(product_id)',
    'SELECT "Индекс idx_fact_orders_product уже существует" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Создаем индексы для fact_transactions если их нет
-- Индекс для JOIN по order_id
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'mi_core_db' 
        AND TABLE_NAME = 'fact_transactions' 
        AND INDEX_NAME = 'idx_fact_transactions_order'
);

SET @sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_fact_transactions_order ON fact_transactions(order_id)',
    'SELECT "Индекс idx_fact_transactions_order уже существует" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Индекс для фильтрации по дате и типу транзакции
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'mi_core_db' 
        AND TABLE_NAME = 'fact_transactions' 
        AND INDEX_NAME = 'idx_fact_transactions_date_type'
);

SET @sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_fact_transactions_date_type ON fact_transactions(transaction_date, transaction_type)',
    'SELECT "Индекс idx_fact_transactions_date_type уже существует" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 7. Создаем индекс для dim_products если его нет
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = 'mi_core_db' 
        AND TABLE_NAME = 'dim_products' 
        AND INDEX_NAME = 'idx_dim_products_cost'
);

SET @sql = IF(
    @index_exists = 0,
    'CREATE INDEX idx_dim_products_cost ON dim_products(id, cost_price)',
    'SELECT "Индекс idx_dim_products_cost уже существует" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ===================================================================
-- ПРОВЕРКА РЕЗУЛЬТАТОВ
-- ===================================================================

-- 8. Показываем все созданные индексы
SELECT 'Индексы для fact_orders:' as info;
SHOW INDEX FROM fact_orders WHERE Key_name LIKE 'idx_%';

SELECT 'Индексы для fact_transactions:' as info;
SHOW INDEX FROM fact_transactions WHERE Key_name LIKE 'idx_%';

SELECT 'Индексы для dim_products:' as info;
SHOW INDEX FROM dim_products WHERE Key_name LIKE 'idx_%';

-- 9. Финальная проверка структуры metrics_daily
SELECT 'Финальная структура metrics_daily:' as info;
DESCRIBE metrics_daily;

SELECT '✅ Миграция завершена успешно!' as result;