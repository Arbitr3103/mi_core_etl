-- Скрипт для настройки данных для поддержки Wildberries
-- Добавляет необходимые записи в таблицы sources и clients

-- Добавляем источник Wildberries в таблицу sources
INSERT IGNORE INTO sources (code, name) VALUES ('WB', 'Wildberries Marketplace');

-- Добавляем клиента "ТД Манхэттен" в таблицу clients (если еще не существует)
INSERT IGNORE INTO clients (name) VALUES ('ТД Манхэттен');

-- Проверяем, что записи добавились
SELECT 'Источники данных:' as info;
SELECT id, code, name FROM sources ORDER BY id;

SELECT 'Клиенты:' as info;
SELECT id, name FROM clients ORDER BY id;

-- Дополнительно проверяем структуру таблицы fact_transactions
-- Убеждаемся, что есть поля client_id и source_id
DESCRIBE fact_transactions;
