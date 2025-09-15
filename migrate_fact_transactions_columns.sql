-- Безопасная миграция для добавления client_id и source_id в таблицу fact_transactions
-- Выполнить на продуктивном сервере после обновления create_tables.sql

USE mi_core_db;

-- Проверяем текущее состояние таблицы
SELECT COUNT(*) as existing_transactions FROM fact_transactions;

-- ВАРИАНТ 1: Если в таблице есть данные - очищаем их (они будут пересозданы при следующем импорте)
-- ВНИМАНИЕ: Это удалит все существующие транзакции!
TRUNCATE TABLE fact_transactions;

-- Добавляем недостающие колонки client_id и source_id
ALTER TABLE fact_transactions
    ADD COLUMN client_id INT NOT NULL AFTER id,
    ADD COLUMN source_id INT NOT NULL AFTER client_id;

-- Добавляем внешние ключи для обеспечения целостности данных
ALTER TABLE fact_transactions
    ADD CONSTRAINT fk_ft_client FOREIGN KEY (client_id) REFERENCES clients(id),
    ADD CONSTRAINT fk_ft_source FOREIGN KEY (source_id) REFERENCES sources(id);

-- Проверяем обновленную структуру таблицы
DESCRIBE fact_transactions;

-- Показываем количество записей в таблице (должно быть 0 после TRUNCATE)
SELECT COUNT(*) as total_transactions FROM fact_transactions;

-- Показываем доступные client_id и source_id для справки
SELECT 'Available clients:' as info;
SELECT id, name FROM clients;

SELECT 'Available sources:' as info;
SELECT id, code, name FROM sources;
