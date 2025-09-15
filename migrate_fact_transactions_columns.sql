-- Миграция для добавления client_id и source_id в таблицу fact_transactions
-- Выполнить на продуктивном сервере после обновления create_tables.sql

USE mi_core_db;

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

-- Показываем количество записей в таблице
SELECT COUNT(*) as total_transactions FROM fact_transactions;
