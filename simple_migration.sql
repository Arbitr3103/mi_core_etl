-- Простая миграция для добавления поля revenue в DBeaver
-- Скопируйте и выполните эту команду в SQL редакторе DBeaver

-- Добавляем поле revenue в таблицу ozon_funnel_data
ALTER TABLE ozon_funnel_data 
ADD COLUMN revenue DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Revenue from Ozon API' 
AFTER orders;

-- Проверяем результат
DESCRIBE ozon_funnel_data;