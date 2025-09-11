-- Миграция схемы БД для поддержки BaseBuy API
-- Добавляет необходимые поля в существующие таблицы

-- Обновление таблицы brands
ALTER TABLE brands 
ADD COLUMN IF NOT EXISTS external_id VARCHAR(50),
ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT 'basebuy',
ADD COLUMN IF NOT EXISTS name_rus VARCHAR(255),
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Добавляем уникальный индекс для external_id + source
ALTER TABLE brands 
ADD UNIQUE KEY IF NOT EXISTS unique_external_source (external_id, source);

-- Обновление таблицы car_models
ALTER TABLE car_models 
ADD COLUMN IF NOT EXISTS external_id VARCHAR(50),
ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT 'basebuy',
ADD COLUMN IF NOT EXISTS name_rus VARCHAR(255),
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Добавляем уникальный индекс для external_id + source
ALTER TABLE car_models 
ADD UNIQUE KEY IF NOT EXISTS unique_external_source (external_id, source);

-- Обновление таблицы car_specifications
ALTER TABLE car_specifications 
ADD COLUMN IF NOT EXISTS external_id VARCHAR(50),
ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT 'basebuy',
ADD COLUMN IF NOT EXISTS name_rus VARCHAR(255),
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Добавляем уникальный индекс для external_id + source
ALTER TABLE car_specifications 
ADD UNIQUE KEY IF NOT EXISTS unique_external_source (external_id, source);

-- Проверяем результат миграции
SELECT 'brands' as table_name, COUNT(*) as column_count 
FROM information_schema.columns 
WHERE table_name = 'brands' AND table_schema = DATABASE()
UNION ALL
SELECT 'car_models' as table_name, COUNT(*) as column_count 
FROM information_schema.columns 
WHERE table_name = 'car_models' AND table_schema = DATABASE()
UNION ALL
SELECT 'car_specifications' as table_name, COUNT(*) as column_count 
FROM information_schema.columns 
WHERE table_name = 'car_specifications' AND table_schema = DATABASE();
