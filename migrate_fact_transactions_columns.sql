-- Универсальная миграция для добавления client_id и source_id в таблицу fact_transactions
-- Безопасно выполняется несколько раз, проверяет существование колонок и constraints

USE mi_core_db;

-- Проверяем текущее состояние таблицы
SELECT COUNT(*) as existing_transactions FROM fact_transactions;

-- Проверяем и добавляем колонку client_id если её нет
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'fact_transactions' AND COLUMN_NAME = 'client_id');

-- Если колонки нет и есть данные - очищаем таблицу
SET @has_data = (SELECT COUNT(*) FROM fact_transactions);
SET @sql = IF(@col_exists = 0 AND @has_data > 0, 'TRUNCATE TABLE fact_transactions', 'SELECT "Skipping TRUNCATE" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Добавляем client_id если его нет
SET @sql = IF(@col_exists = 0, 'ALTER TABLE fact_transactions ADD COLUMN client_id INT NOT NULL AFTER id', 'SELECT "Column client_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем и добавляем колонку source_id если её нет
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'fact_transactions' AND COLUMN_NAME = 'source_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE fact_transactions ADD COLUMN source_id INT NOT NULL AFTER client_id', 'SELECT "Column source_id already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем и добавляем FOREIGN KEY для client_id если его нет
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                  WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'fact_transactions' AND CONSTRAINT_NAME = 'fk_ft_client');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE fact_transactions ADD CONSTRAINT fk_ft_client FOREIGN KEY (client_id) REFERENCES clients(id)', 'SELECT "Constraint fk_ft_client already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем и добавляем FOREIGN KEY для source_id если его нет
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
                  WHERE TABLE_SCHEMA = 'mi_core_db' AND TABLE_NAME = 'fact_transactions' AND CONSTRAINT_NAME = 'fk_ft_source');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE fact_transactions ADD CONSTRAINT fk_ft_source FOREIGN KEY (source_id) REFERENCES sources(id)', 'SELECT "Constraint fk_ft_source already exists" AS message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем обновленную структуру таблицы
DESCRIBE fact_transactions;

-- Показываем количество записей в таблице
SELECT COUNT(*) as total_transactions FROM fact_transactions;

-- Показываем доступные client_id и source_id для справки
SELECT 'Available clients:' as info;
SELECT id, name FROM clients;

SELECT 'Available sources:' as info;
SELECT id, code, name FROM sources;
