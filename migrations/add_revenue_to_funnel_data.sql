-- Добавление поля revenue в таблицу ozon_funnel_data
-- Это поле необходимо для хранения данных о выручке из Ozon API

-- Проверяем, существует ли уже поле revenue
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ozon_funnel_data'
      AND column_name = 'revenue'
);

-- Добавляем поле revenue только если его нет
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE ozon_funnel_data ADD COLUMN revenue DECIMAL(15,2) DEFAULT 0.00 COMMENT "Revenue from Ozon API" AFTER orders',
    'SELECT "Поле revenue уже существует в таблице ozon_funnel_data" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем результат
SELECT 
    CASE 
        WHEN @column_exists = 0 THEN '✅ Поле revenue успешно добавлено в таблицу ozon_funnel_data'
        ELSE 'ℹ️ Поле revenue уже существовало в таблице ozon_funnel_data'
    END as result;

-- Показываем текущую структуру таблицы
SELECT 
    column_name,
    data_type,
    is_nullable,
    column_default,
    column_comment
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ozon_funnel_data'
ORDER BY ordinal_position;