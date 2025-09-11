-- Миграция схемы БД для поддержки BaseBuy API
-- Добавляет необходимые поля в существующие таблицы с проверкой существования

DELIMITER $$

-- Процедура для безопасного добавления колонки
DROP PROCEDURE IF EXISTS AddColumnIfNotExists$$
CREATE PROCEDURE AddColumnIfNotExists(
    IN table_name VARCHAR(64),
    IN column_name VARCHAR(64),
    IN column_definition TEXT
)
BEGIN
    DECLARE column_exists INT DEFAULT 0;
    
    -- Проверяем существование колонки
    SELECT COUNT(*) INTO column_exists
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = table_name
      AND column_name = column_name;
    
    -- Добавляем колонку только если её нет
    IF column_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE ', table_name, ' ADD COLUMN ', column_name, ' ', column_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SELECT CONCAT('✅ Добавлена колонка ', column_name, ' в таблицу ', table_name) as result;
    ELSE
        SELECT CONCAT('ℹ️ Колонка ', column_name, ' уже существует в таблице ', table_name) as result;
    END IF;
END$$

-- Процедура для безопасного добавления индекса
DROP PROCEDURE IF EXISTS AddIndexIfNotExists$$
CREATE PROCEDURE AddIndexIfNotExists(
    IN table_name VARCHAR(64),
    IN index_name VARCHAR(64),
    IN index_definition TEXT
)
BEGIN
    DECLARE index_exists INT DEFAULT 0;
    
    -- Проверяем существование индекса
    SELECT COUNT(*) INTO index_exists
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = table_name
      AND index_name = index_name;
    
    -- Добавляем индекс только если его нет
    IF index_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE ', table_name, ' ADD ', index_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
        SELECT CONCAT('✅ Добавлен индекс ', index_name, ' в таблицу ', table_name) as result;
    ELSE
        SELECT CONCAT('ℹ️ Индекс ', index_name, ' уже существует в таблице ', table_name) as result;
    END IF;
END$$

DELIMITER ;

-- Применяем миграцию для таблицы brands
SELECT '🔄 Обновляем таблицу brands...' as status;
CALL AddColumnIfNotExists('brands', 'external_id', 'VARCHAR(50)');
CALL AddColumnIfNotExists('brands', 'source', 'VARCHAR(50) DEFAULT "basebuy"');
CALL AddColumnIfNotExists('brands', 'name_rus', 'VARCHAR(255)');
CALL AddColumnIfNotExists('brands', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
CALL AddColumnIfNotExists('brands', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL AddIndexIfNotExists('brands', 'unique_external_source', 'UNIQUE KEY unique_external_source (external_id, source)');

-- Применяем миграцию для таблицы car_models
SELECT '🔄 Обновляем таблицу car_models...' as status;
CALL AddColumnIfNotExists('car_models', 'external_id', 'VARCHAR(50)');
CALL AddColumnIfNotExists('car_models', 'source', 'VARCHAR(50) DEFAULT "basebuy"');
CALL AddColumnIfNotExists('car_models', 'name_rus', 'VARCHAR(255)');
CALL AddColumnIfNotExists('car_models', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
CALL AddColumnIfNotExists('car_models', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL AddIndexIfNotExists('car_models', 'unique_external_source', 'UNIQUE KEY unique_external_source (external_id, source)');

-- Применяем миграцию для таблицы car_specifications
SELECT '🔄 Обновляем таблицу car_specifications...' as status;
CALL AddColumnIfNotExists('car_specifications', 'external_id', 'VARCHAR(50)');
CALL AddColumnIfNotExists('car_specifications', 'source', 'VARCHAR(50) DEFAULT "basebuy"');
CALL AddColumnIfNotExists('car_specifications', 'name_rus', 'VARCHAR(255)');
CALL AddColumnIfNotExists('car_specifications', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
CALL AddColumnIfNotExists('car_specifications', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
CALL AddIndexIfNotExists('car_specifications', 'unique_external_source', 'UNIQUE KEY unique_external_source (external_id, source)');

-- Удаляем временные процедуры
DROP PROCEDURE IF EXISTS AddColumnIfNotExists;
DROP PROCEDURE IF EXISTS AddIndexIfNotExists;

-- Проверяем результат миграции
SELECT '📊 Результаты миграции:' as status;
SELECT 
    'brands' as table_name, 
    COUNT(*) as total_columns,
    SUM(CASE WHEN column_name IN ('external_id', 'source', 'name_rus', 'created_at', 'updated_at') THEN 1 ELSE 0 END) as new_columns
FROM information_schema.columns 
WHERE table_name = 'brands' AND table_schema = DATABASE()
UNION ALL
SELECT 
    'car_models' as table_name, 
    COUNT(*) as total_columns,
    SUM(CASE WHEN column_name IN ('external_id', 'source', 'name_rus', 'created_at', 'updated_at') THEN 1 ELSE 0 END) as new_columns
FROM information_schema.columns 
WHERE table_name = 'car_models' AND table_schema = DATABASE()
UNION ALL
SELECT 
    'car_specifications' as table_name, 
    COUNT(*) as total_columns,
    SUM(CASE WHEN column_name IN ('external_id', 'source', 'name_rus', 'created_at', 'updated_at') THEN 1 ELSE 0 END) as new_columns
FROM information_schema.columns 
WHERE table_name = 'car_specifications' AND table_schema = DATABASE();

SELECT '✅ Миграция завершена!' as status;
