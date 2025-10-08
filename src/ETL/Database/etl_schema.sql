-- ===================================================================
-- ETL СИСТЕМА - СХЕМА БАЗЫ ДАННЫХ
-- Создание таблиц для системы извлечения, трансформации и загрузки данных
-- ===================================================================

-- Таблица для хранения извлеченных данных из внешних источников
CREATE TABLE IF NOT EXISTS etl_extracted_data (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL COMMENT 'Источник данных (ozon, wildberries, internal)',
    external_sku VARCHAR(200) NOT NULL COMMENT 'SKU товара во внешней системе',
    source_name VARCHAR(500) NOT NULL COMMENT 'Название товара в источнике',
    source_brand VARCHAR(200) DEFAULT NULL COMMENT 'Бренд товара в источнике',
    source_category VARCHAR(200) DEFAULT NULL COMMENT 'Категория товара в источнике',
    price DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Цена товара',
    description TEXT DEFAULT NULL COMMENT 'Описание товара',
    attributes JSON DEFAULT NULL COMMENT 'Дополнительные атрибуты товара',
    raw_data JSON DEFAULT NULL COMMENT 'Сырые данные из API',
    extracted_at TIMESTAMP NOT NULL COMMENT 'Время извлечения данных',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_source_sku (source, external_sku),
    INDEX idx_source (source),
    INDEX idx_extracted_at (extracted_at),
    INDEX idx_source_brand (source, source_brand),
    INDEX idx_source_category (source, source_category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Извлеченные данные из внешних источников';

-- Таблица для логирования ETL процессов
CREATE TABLE IF NOT EXISTS etl_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL COMMENT 'Источник лога (ozon, wildberries, internal, scheduler)',
    level ENUM('DEBUG', 'INFO', 'WARNING', 'ERROR') NOT NULL DEFAULT 'INFO',
    message TEXT NOT NULL COMMENT 'Сообщение лога',
    context JSON DEFAULT NULL COMMENT 'Дополнительный контекст',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_source_level (source, level),
    INDEX idx_created_at (created_at),
    INDEX idx_level (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Логи ETL процессов';

-- Таблица для отслеживания запусков ETL
CREATE TABLE IF NOT EXISTS etl_runs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    status ENUM('success', 'partial_success', 'error') NOT NULL,
    duration DECIMAL(8,2) NOT NULL COMMENT 'Продолжительность выполнения в секундах',
    total_extracted INT DEFAULT 0 COMMENT 'Общее количество извлеченных записей',
    total_saved INT DEFAULT 0 COMMENT 'Общее количество сохраненных записей',
    results JSON DEFAULT NULL COMMENT 'Детальные результаты по источникам',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='История запусков ETL процессов';

-- Таблица для хранения конфигурации ETL
CREATE TABLE IF NOT EXISTS etl_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL COMMENT 'Источник данных',
    config_key VARCHAR(100) NOT NULL COMMENT 'Ключ конфигурации',
    config_value TEXT NOT NULL COMMENT 'Значение конфигурации',
    is_encrypted BOOLEAN DEFAULT FALSE COMMENT 'Зашифровано ли значение',
    description TEXT DEFAULT NULL COMMENT 'Описание параметра',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_source_key (source, config_key),
    INDEX idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Конфигурация ETL процессов';

-- Таблица для отслеживания состояния синхронизации
CREATE TABLE IF NOT EXISTS etl_sync_state (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL COMMENT 'Источник данных',
    last_sync_at TIMESTAMP NULL COMMENT 'Время последней успешной синхронизации',
    last_cursor VARCHAR(500) DEFAULT NULL COMMENT 'Курсор для инкрементальной синхронизации',
    sync_status ENUM('idle', 'running', 'error') DEFAULT 'idle',
    error_message TEXT DEFAULT NULL COMMENT 'Сообщение об ошибке',
    metadata JSON DEFAULT NULL COMMENT 'Дополнительные метаданные',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_source (source),
    INDEX idx_sync_status (sync_status),
    INDEX idx_last_sync_at (last_sync_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Состояние синхронизации с внешними источниками';

-- Таблица для хранения правил трансформации данных
CREATE TABLE IF NOT EXISTS etl_transformation_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL COMMENT 'Источник данных',
    field_name VARCHAR(100) NOT NULL COMMENT 'Название поля для трансформации',
    rule_type ENUM('cleanup', 'normalize', 'mapping', 'validation') NOT NULL,
    rule_config JSON NOT NULL COMMENT 'Конфигурация правила',
    is_active BOOLEAN DEFAULT TRUE,
    priority INT DEFAULT 0 COMMENT 'Приоритет выполнения (больше = выше)',
    description TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_source_field (source, field_name),
    INDEX idx_rule_type (rule_type),
    INDEX idx_is_active (is_active),
    INDEX idx_priority (priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Правила трансформации данных';

-- Таблица для кэширования результатов API запросов
CREATE TABLE IF NOT EXISTS etl_api_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL COMMENT 'Источник API',
    cache_key VARCHAR(255) NOT NULL COMMENT 'Ключ кэша',
    cache_data JSON NOT NULL COMMENT 'Кэшированные данные',
    expires_at TIMESTAMP NOT NULL COMMENT 'Время истечения кэша',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_source_key (source, cache_key),
    INDEX idx_expires_at (expires_at),
    INDEX idx_source (source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Кэш API запросов';

-- Таблица для отслеживания ошибок и повторных попыток
CREATE TABLE IF NOT EXISTS etl_retry_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(50) NOT NULL COMMENT 'Источник данных',
    operation_type VARCHAR(50) NOT NULL COMMENT 'Тип операции (extract, transform, load)',
    operation_data JSON NOT NULL COMMENT 'Данные операции',
    error_message TEXT NOT NULL COMMENT 'Сообщение об ошибке',
    retry_count INT DEFAULT 0 COMMENT 'Количество попыток',
    max_retries INT DEFAULT 3 COMMENT 'Максимальное количество попыток',
    next_retry_at TIMESTAMP NOT NULL COMMENT 'Время следующей попытки',
    status ENUM('pending', 'processing', 'success', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_source_status (source, status),
    INDEX idx_next_retry_at (next_retry_at),
    INDEX idx_operation_type (operation_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Очередь повторных попыток для неудачных операций';

-- ===================================================================
-- ВСТАВКА НАЧАЛЬНЫХ ДАННЫХ
-- ===================================================================

-- Конфигурация по умолчанию для Ozon
INSERT IGNORE INTO etl_config (source, config_key, config_value, description) VALUES
('ozon', 'rate_limit_requests_per_second', '10', 'Максимальное количество запросов в секунду'),
('ozon', 'rate_limit_delay', '0.1', 'Задержка между запросами в секундах'),
('ozon', 'max_retries', '3', 'Максимальное количество повторных попыток'),
('ozon', 'timeout', '30', 'Таймаут запроса в секундах'),
('ozon', 'batch_size', '1000', 'Размер батча для обработки');

-- Конфигурация по умолчанию для Wildberries
INSERT IGNORE INTO etl_config (source, config_key, config_value, description) VALUES
('wildberries', 'rate_limit_requests_per_minute', '100', 'Максимальное количество запросов в минуту'),
('wildberries', 'rate_limit_delay', '0.6', 'Задержка между запросами в секундах'),
('wildberries', 'max_retries', '3', 'Максимальное количество повторных попыток'),
('wildberries', 'timeout', '30', 'Таймаут запроса в секундах'),
('wildberries', 'batch_size', '100', 'Размер батча для обработки');

-- Конфигурация по умолчанию для внутренних систем
INSERT IGNORE INTO etl_config (source, config_key, config_value, description) VALUES
('internal', 'batch_size', '5000', 'Размер батча для обработки'),
('internal', 'max_retries', '2', 'Максимальное количество повторных попыток'),
('internal', 'query_timeout', '60', 'Таймаут SQL запроса в секундах');

-- Общие настройки планировщика
INSERT IGNORE INTO etl_config (source, config_key, config_value, description) VALUES
('scheduler', 'full_sync_interval', '24', 'Интервал полной синхронизации в часах'),
('scheduler', 'incremental_sync_interval', '1', 'Интервал инкрементальной синхронизации в часах'),
('scheduler', 'max_parallel_jobs', '3', 'Максимальное количество параллельных задач'),
('scheduler', 'cleanup_logs_days', '30', 'Количество дней хранения логов');

-- Правила трансформации по умолчанию
INSERT IGNORE INTO etl_transformation_rules (source, field_name, rule_type, rule_config, description, priority) VALUES
('*', 'source_name', 'cleanup', '{"remove_extra_spaces": true, "remove_control_chars": true}', 'Очистка названий товаров', 10),
('*', 'source_brand', 'normalize', '{"to_lowercase": false, "trim": true}', 'Нормализация брендов', 10),
('*', 'source_category', 'normalize', '{"to_lowercase": false, "trim": true}', 'Нормализация категорий', 10),
('*', 'price', 'validation', '{"min_value": 0, "max_value": 1000000}', 'Валидация цены', 5);

-- Инициализация состояния синхронизации
INSERT IGNORE INTO etl_sync_state (source, sync_status) VALUES
('ozon', 'idle'),
('wildberries', 'idle'),
('internal', 'idle');

-- ===================================================================
-- ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ ПРОИЗВОДИТЕЛЬНОСТИ
-- ===================================================================

-- Дополнительные индексы для таблицы извлеченных данных
ALTER TABLE etl_extracted_data 
ADD INDEX idx_source_name_brand (source, source_name(100), source_brand),
ADD INDEX idx_price_range (source, price),
ADD INDEX idx_updated_at (updated_at);

-- Индексы для быстрого поиска в логах
ALTER TABLE etl_logs 
ADD INDEX idx_source_created (source, created_at),
ADD INDEX idx_message_text (message(100));

-- Составные индексы для отчетности
ALTER TABLE etl_runs 
ADD INDEX idx_status_created (status, created_at),
ADD INDEX idx_duration (duration);

-- ===================================================================
-- ПРОЦЕДУРЫ ДЛЯ ОБСЛУЖИВАНИЯ
-- ===================================================================

DELIMITER //

-- Процедура очистки старых логов
CREATE PROCEDURE IF NOT EXISTS CleanupETLLogs(IN days_to_keep INT)
BEGIN
    DECLARE rows_deleted INT DEFAULT 0;
    
    DELETE FROM etl_logs 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    
    SET rows_deleted = ROW_COUNT();
    
    INSERT INTO etl_logs (source, level, message, created_at) 
    VALUES ('maintenance', 'INFO', CONCAT('Очищено старых логов: ', rows_deleted), NOW());
END //

-- Процедура очистки старого кэша
CREATE PROCEDURE IF NOT EXISTS CleanupETLCache()
BEGIN
    DECLARE rows_deleted INT DEFAULT 0;
    
    DELETE FROM etl_api_cache 
    WHERE expires_at < NOW();
    
    SET rows_deleted = ROW_COUNT();
    
    INSERT INTO etl_logs (source, level, message, created_at) 
    VALUES ('maintenance', 'INFO', CONCAT('Очищено устаревшего кэша: ', rows_deleted), NOW());
END //

-- Процедура получения статистики ETL
CREATE PROCEDURE IF NOT EXISTS GetETLStatistics(IN days_back INT)
BEGIN
    SELECT 
        source,
        COUNT(*) as total_records,
        COUNT(DISTINCT external_sku) as unique_products,
        MAX(extracted_at) as last_extraction,
        AVG(price) as avg_price
    FROM etl_extracted_data 
    WHERE extracted_at >= DATE_SUB(NOW(), INTERVAL days_back DAY)
    GROUP BY source
    ORDER BY total_records DESC;
    
    SELECT 
        DATE(created_at) as run_date,
        COUNT(*) as runs_count,
        AVG(duration) as avg_duration,
        SUM(total_extracted) as total_extracted,
        SUM(total_saved) as total_saved
    FROM etl_runs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL days_back DAY)
    GROUP BY DATE(created_at)
    ORDER BY run_date DESC;
END //

DELIMITER ;

-- ===================================================================
-- СОБЫТИЯ ДЛЯ АВТОМАТИЧЕСКОГО ОБСЛУЖИВАНИЯ
-- ===================================================================

-- Автоматическая очистка логов каждый день в 2:00
CREATE EVENT IF NOT EXISTS etl_daily_cleanup
ON SCHEDULE EVERY 1 DAY STARTS '2024-01-01 02:00:00'
DO
BEGIN
    CALL CleanupETLLogs(30);
    CALL CleanupETLCache();
END;

-- Включаем планировщик событий
SET GLOBAL event_scheduler = ON;

-- ===================================================================
-- ПРЕДСТАВЛЕНИЯ ДЛЯ УДОБСТВА РАБОТЫ
-- ===================================================================

-- Представление для мониторинга ETL процессов
CREATE OR REPLACE VIEW v_etl_monitoring AS
SELECT 
    ss.source,
    ss.last_sync_at,
    ss.sync_status,
    ss.error_message,
    COUNT(ed.id) as extracted_records,
    MAX(ed.extracted_at) as last_extraction,
    COUNT(DISTINCT ed.external_sku) as unique_products
FROM etl_sync_state ss
LEFT JOIN etl_extracted_data ed ON ss.source = ed.source 
    AND ed.extracted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY ss.source, ss.last_sync_at, ss.sync_status, ss.error_message;

-- Представление для анализа качества данных
CREATE OR REPLACE VIEW v_etl_data_quality AS
SELECT 
    source,
    COUNT(*) as total_records,
    COUNT(CASE WHEN source_name IS NULL OR source_name = '' THEN 1 END) as missing_names,
    COUNT(CASE WHEN source_brand IS NULL OR source_brand = '' THEN 1 END) as missing_brands,
    COUNT(CASE WHEN source_category IS NULL OR source_category = '' THEN 1 END) as missing_categories,
    COUNT(CASE WHEN price = 0 OR price IS NULL THEN 1 END) as missing_prices,
    ROUND(AVG(price), 2) as avg_price,
    MIN(extracted_at) as first_extraction,
    MAX(extracted_at) as last_extraction
FROM etl_extracted_data
GROUP BY source;

-- Представление для отчетов об ошибках
CREATE OR REPLACE VIEW v_etl_error_summary AS
SELECT 
    source,
    level,
    DATE(created_at) as error_date,
    COUNT(*) as error_count,
    GROUP_CONCAT(DISTINCT SUBSTRING(message, 1, 100) SEPARATOR '; ') as sample_messages
FROM etl_logs 
WHERE level IN ('ERROR', 'WARNING')
    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY source, level, DATE(created_at)
ORDER BY error_date DESC, error_count DESC;

-- ===================================================================
-- КОММЕНТАРИИ И ДОКУМЕНТАЦИЯ
-- ===================================================================

/*
ОПИСАНИЕ СХЕМЫ ETL СИСТЕМЫ:

1. etl_extracted_data - основная таблица для хранения извлеченных данных
   - Содержит нормализованные данные из всех источников
   - Уникальность по комбинации source + external_sku
   - JSON поля для гибкого хранения атрибутов и сырых данных

2. etl_logs - централизованное логирование всех ETL процессов
   - Структурированные логи с уровнями важности
   - JSON контекст для дополнительной информации

3. etl_runs - история запусков ETL процессов
   - Отслеживание производительности и результатов
   - Статистика по каждому запуску

4. etl_config - конфигурация ETL процессов
   - Централизованное управление настройками
   - Поддержка шифрования чувствительных данных

5. etl_sync_state - состояние синхронизации с источниками
   - Отслеживание последней синхронизации
   - Поддержка инкрементальных обновлений

6. etl_transformation_rules - правила трансформации данных
   - Гибкая система правил для очистки и нормализации
   - Приоритизация и активация/деактивация правил

7. etl_api_cache - кэширование API запросов
   - Снижение нагрузки на внешние API
   - Автоматическое истечение кэша

8. etl_retry_queue - очередь повторных попыток
   - Обработка временных ошибок
   - Экспоненциальная задержка между попытками

ИСПОЛЬЗОВАНИЕ:
- Все таблицы используют UTF-8 кодировку для поддержки международных символов
- Индексы оптимизированы для частых запросов
- Автоматические процедуры обслуживания
- Представления для мониторинга и отчетности
*/