-- Миграция для добавления поддержки client_id и source_id в таблицу fact_transactions
-- Необходимо для корректной работы с данными Wildberries

-- Добавляем поля client_id и source_id в таблицу fact_transactions
ALTER TABLE fact_transactions 
ADD COLUMN client_id INT NULL AFTER description,
ADD COLUMN source_id INT NULL AFTER client_id;

-- Добавляем внешние ключи
ALTER TABLE fact_transactions
ADD CONSTRAINT fk_ft_client FOREIGN KEY (client_id) REFERENCES clients(id),
ADD CONSTRAINT fk_ft_source FOREIGN KEY (source_id) REFERENCES sources(id);

-- Обновляем существующие записи, устанавливая client_id = 1 и source_id = 2 (Ozon)
-- Предполагаем, что все существующие транзакции относятся к Ozon
UPDATE fact_transactions 
SET client_id = 1, source_id = 2 
WHERE client_id IS NULL AND source_id IS NULL;

-- Делаем поля обязательными после заполнения данных
ALTER TABLE fact_transactions 
MODIFY COLUMN client_id INT NOT NULL,
MODIFY COLUMN source_id INT NOT NULL;

-- Проверяем результат
DESCRIBE fact_transactions;
