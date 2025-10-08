-- Исправление схемы базы данных для корректной работы синхронизации
-- Выполнить: mysql -u root -p mi_core_db < fix_database_schema.sql

USE mi_core_db;

-- 1. Исправляем размер поля product_id в таблице inventory (если существует)
ALTER TABLE inventory MODIFY COLUMN product_id BIGINT;

-- 2. Исправляем размер поля product_id в таблице ozon_analytics (если существует)
ALTER TABLE ozon_analytics MODIFY COLUMN product_id BIGINT;

-- 3. Добавляем недостающие колонки в sync_logs
ALTER TABLE sync_logs 
ADD COLUMN IF NOT EXISTS api_requests_count INT DEFAULT 0,
ADD COLUMN IF NOT EXISTS warning_message TEXT DEFAULT NULL,
MODIFY COLUMN status VARCHAR(20) NOT NULL,
MODIFY COLUMN operation VARCHAR(50) DEFAULT 'sync';

-- 4. Создаем таблицу ozon_warehouses если не существует
CREATE TABLE IF NOT EXISTS ozon_warehouses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    warehouse_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    is_rfbs BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_warehouse (warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Даем права пользователю ingest_user на создание таблиц (временно)
GRANT CREATE ON mi_core_db.* TO 'ingest_user'@'localhost';
FLUSH PRIVILEGES;

-- Показываем результат
SELECT 'Database schema fixed successfully!' as status;