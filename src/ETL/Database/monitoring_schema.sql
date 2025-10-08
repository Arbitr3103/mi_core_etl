-- ===================================================================
-- ETL МОНИТОРИНГ - СХЕМА БАЗЫ ДАННЫХ
-- Создание таблиц для системы мониторинга ETL процессов
-- ===================================================================

-- Таблица для отслеживания сессий мониторинга ETL задач
CREATE TABLE IF NOT EXISTS etl_monitoring_sessions (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(100) NOT NULL UNIQUE COMMENT 'Уникальный ID сессии мониторинга',
    task_id VARCHAR(200) NOT NULL COMMENT 'ID ETL задачи',
    task_type VARCHAR(50) NOT NULL COMMENT 'Тип задачи (full_etl, incremental_etl, source_etl)',
    status ENUM('running', 'success', 'error', 'cancelled') NOT NULL DEFAULT 'running',
    current_step VARCHAR(200) DEFAULT NULL COMMENT 'Текущий шаг выполнения',
    records_processed INT DEFAULT 0 COMMENT 'Количество обработанных записей',
    records_total INT DEFAULT 0 COMMENT 'Общее количество записей для обработки',
    progress_data JSON DEFAULT NULL COMMENT 'Детальные данные о прогрессе',
    metadata JSON DEFAULT NULL COMMENT 'Метаданные задачи',
    results JSON DEFAULT NULL COMMENT 'Результаты выполнения задачи',
    started_at TIMESTAMP NOT NULL COMMENT 'Время начала выполнения',
    finished_at TIMESTAMP NULL COMMENT 'Время завершения выполнения',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    duration_seconds DECIMAL(10,2) DEFAULT NULL COMMENT 'Продолжительность выполнения в секундах',
    
    INDEX idx_session_id (session_id),
    INDEX idx_task_id (task_id),
    INDEX idx_task_type (task_type),
    INDEX idx_status (status),
    INDEX idx_started_at (started_at),
    INDEX idx_status_started (status, started_at),
    INDEX idx_task_type_status (task_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Сессии мониторинга ETL задач';

-- Таблица для хранения метрик производительности ETL процессов
CREATE TABLE IF NOT EXISTS etl_performance_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(100) NOT NULL COMMENT 'ID сессии мониторинга',
    task_type VARCHAR(50) NOT NULL COMMENT 'Тип задачи',
    duration_seconds DECIMAL(10,2) NOT NULL COMMENT 'Продолжительность выполнения',
    records_processed INT NOT NULL DEFAULT 0 COMMENT 'Количество обработанных записей',
    throughput_per_second DECIMAL(10,2) DEFAULT 0.00 COMMENT 'Пропускная способность (записей в секунду)',
    memory_usage_mb DECIMAL(10,2) DEFAULT NULL COMMENT 'Использование памяти в МБ',
    cpu_usage_percent DECIMAL(5,2) DEFAULT NULL COMMENT 'Использование CPU в процентах',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_session_id (session_id),
    INDEX idx_task_type (task_type),
    INDEX idx_created_at (created_at),
    INDEX idx_task_type_created (task_type, created_at),
    INDEX idx_throughput (throughput_per_second),
    
    FOREIGN KEY (session_id) REFERENCES etl_monitoring_sessions(session_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Метрики производительности ETL процессов';

-- Таблица для хранения уведомлений ETL системы
CREATE TABLE IF NOT EXISTS etl_notifications (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    alert_key VARCHAR(200) NOT NULL COMMENT 'Ключ алерта для предотвращения дублирования',
    notification_type ENUM('error', 'warning', 'performance', 'success', 'system', 'report') NOT NULL,
    message TEXT NOT NULL COMMENT 'Текст уведомления',
    priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    session_id VARCHAR(100) DEFAULT NULL COMMENT 'Связанная сессия мониторинга',
    task_id VARCHAR(200) DEFAULT NULL COMMENT 'Связанная задача',
    task_type VARCHAR(50) DEFAULT NULL COMMENT 'Тип связанной задачи',
    channels_sent JSON DEFAULT NULL COMMENT 'Каналы, через которые отправлено уведомление',
    sent_at TIMESTAMP DEFAULT NULL COMMENT 'Время отправки уведомления',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_alert_key (alert_key),
    INDEX idx_notification_type (notification_type),
    INDEX idx_priority (priority),
    INDEX idx_session_id (session_id),
    INDEX idx_created_at (created_at),
    INDEX idx_type_created (notification_type, created_at),
    
    FOREIGN KEY (session_id) REFERENCES etl_monitoring_sessions(session_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Уведомления ETL системы';

-- Таблица для хранения алертов и их статусов
CREATE TABLE IF NOT EXISTS etl_system_alerts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    alert_type VARCHAR(50) NOT NULL COMMENT 'Тип алерта (stuck_tasks, high_error_rate, performance_degradation)',
    severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
    title VARCHAR(200) NOT NULL COMMENT 'Заголовок алерта',
    description TEXT NOT NULL COMMENT 'Описание проблемы',
    alert_data JSON DEFAULT NULL COMMENT 'Дополнительные данные алерта',
    status ENUM('active', 'acknowledged', 'resolved', 'suppressed') DEFAULT 'active',
    first_detected_at TIMESTAMP NOT NULL COMMENT 'Время первого обнаружения',
    last_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL COMMENT 'Время разрешения алерта',
    acknowledged_by VARCHAR(100) DEFAULT NULL COMMENT 'Кто подтвердил алерт',
    resolved_by VARCHAR(100) DEFAULT NULL COMMENT 'Кто разрешил алерт',
    
    INDEX idx_alert_type (alert_type),
    INDEX idx_severity (severity),
    INDEX idx_status (status),
    INDEX idx_first_detected (first_detected_at),
    INDEX idx_status_severity (status, severity),
    INDEX idx_type_status (alert_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Системные алерты ETL';

-- Таблица для хранения конфигурации мониторинга
CREATE TABLE IF NOT EXISTS etl_monitoring_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    config_key VARCHAR(100) NOT NULL UNIQUE COMMENT 'Ключ конфигурации',
    config_value TEXT NOT NULL COMMENT 'Значение конфигурации',
    config_type ENUM('string', 'integer', 'float', 'boolean', 'json') DEFAULT 'string',
    description TEXT DEFAULT NULL COMMENT 'Описание параметра',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_config_key (config_key),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Конфигурация системы мониторинга ETL';

-- Таблица для хранения пользовательских dashboard настроек
CREATE TABLE IF NOT EXISTS etl_dashboard_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(100) NOT NULL COMMENT 'ID пользователя',
    dashboard_type VARCHAR(50) NOT NULL COMMENT 'Тип dashboard (main, analytics, alerts)',
    settings JSON NOT NULL COMMENT 'Настройки dashboard в JSON формате',
    is_default BOOLEAN DEFAULT FALSE COMMENT 'Является ли настройкой по умолчанию',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_user_dashboard (user_id, dashboard_type),
    INDEX idx_user_id (user_id),
    INDEX idx_dashboard_type (dashboard_type),
    INDEX idx_is_default (is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Пользовательские настройки dashboard';

-- ===================================================================
-- ВСТАВКА НАЧАЛЬНЫХ ДАННЫХ
-- ===================================================================

-- Конфигурация мониторинга по умолчанию
INSERT IGNORE INTO etl_monitoring_config (config_key, config_value, config_type, description) VALUES
('performance_threshold_seconds', '300', 'integer', 'Пороговое значение времени выполнения задачи в секундах'),
('error_threshold_count', '10', 'integer', 'Пороговое количество ошибок для отправки алерта'),
('notification_cooldown_minutes', '30', 'integer', 'Время ожидания между уведомлениями одного типа в минутах'),
('max_notifications_per_hour', '10', 'integer', 'Максимальное количество уведомлений в час'),
('metrics_retention_days', '90', 'integer', 'Количество дней хранения метрик производительности'),
('alert_retention_days', '30', 'integer', 'Количество дней хранения алертов'),
('dashboard_refresh_interval', '30', 'integer', 'Интервал обновления dashboard в секундах'),
('enable_email_notifications', 'true', 'boolean', 'Включить email уведомления'),
('enable_slack_notifications', 'false', 'boolean', 'Включить Slack уведомления'),
('enable_telegram_notifications', 'false', 'boolean', 'Включить Telegram уведомления'),
('email_recipients', '["admin@example.com"]', 'json', 'Список получателей email уведомлений'),
('critical_task_types', '["full_etl", "master_data_sync"]', 'json', 'Типы критических задач для особого мониторинга');

-- ===================================================================
-- ПРЕДСТАВЛЕНИЯ ДЛЯ УДОБСТВА РАБОТЫ
-- ===================================================================

-- Представление для мониторинга активных задач
CREATE OR REPLACE VIEW v_etl_active_tasks AS
SELECT 
    session_id,
    task_id,
    task_type,
    status,
    current_step,
    records_processed,
    records_total,
    CASE 
        WHEN records_total > 0 THEN ROUND((records_processed / records_total) * 100, 1)
        ELSE 0 
    END as progress_percent,
    TIMESTAMPDIFF(SECOND, started_at, NOW()) as running_seconds,
    TIMESTAMPDIFF(MINUTE, started_at, NOW()) as running_minutes,
    started_at,
    updated_at
FROM etl_monitoring_sessions 
WHERE status = 'running'
ORDER BY started_at ASC;

-- Представление для анализа производительности
CREATE OR REPLACE VIEW v_etl_performance_summary AS
SELECT 
    task_type,
    COUNT(*) as total_executions,
    COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_executions,
    COUNT(CASE WHEN status = 'error' THEN 1 END) as failed_executions,
    ROUND(COUNT(CASE WHEN status = 'success' THEN 1 END) * 100.0 / COUNT(*), 2) as success_rate_percent,
    ROUND(AVG(CASE WHEN status IN ('success', 'error') THEN duration_seconds END), 2) as avg_duration_seconds,
    ROUND(MIN(CASE WHEN status IN ('success', 'error') THEN duration_seconds END), 2) as min_duration_seconds,
    ROUND(MAX(CASE WHEN status IN ('success', 'error') THEN duration_seconds END), 2) as max_duration_seconds,
    ROUND(AVG(records_processed), 0) as avg_records_processed,
    SUM(records_processed) as total_records_processed,
    MAX(started_at) as last_execution
FROM etl_monitoring_sessions 
WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY task_type
ORDER BY total_executions DESC;

-- Представление для анализа ошибок
CREATE OR REPLACE VIEW v_etl_error_analysis AS
SELECT 
    task_type,
    DATE(started_at) as error_date,
    COUNT(*) as error_count,
    COUNT(DISTINCT task_id) as affected_tasks,
    GROUP_CONCAT(DISTINCT session_id ORDER BY started_at DESC LIMIT 5) as recent_session_ids,
    MAX(started_at) as last_error_time,
    ROUND(AVG(duration_seconds), 2) as avg_error_duration
FROM etl_monitoring_sessions 
WHERE status = 'error'
    AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY task_type, DATE(started_at)
ORDER BY error_date DESC, error_count DESC;

-- Представление для dashboard метрик
CREATE OR REPLACE VIEW v_etl_dashboard_metrics AS
SELECT 
    'tasks_24h' as metric_name,
    COUNT(*) as metric_value,
    'Задач за 24 часа' as metric_description
FROM etl_monitoring_sessions 
WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)

UNION ALL

SELECT 
    'success_rate_24h' as metric_name,
    ROUND(COUNT(CASE WHEN status = 'success' THEN 1 END) * 100.0 / NULLIF(COUNT(*), 0), 1) as metric_value,
    'Процент успеха за 24 часа' as metric_description
FROM etl_monitoring_sessions 
WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)

UNION ALL

SELECT 
    'avg_duration_24h' as metric_name,
    ROUND(AVG(CASE WHEN status IN ('success', 'error') THEN duration_seconds END), 1) as metric_value,
    'Среднее время выполнения за 24 часа (сек)' as metric_description
FROM etl_monitoring_sessions 
WHERE started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)

UNION ALL

SELECT 
    'active_tasks' as metric_name,
    COUNT(*) as metric_value,
    'Активных задач сейчас' as metric_description
FROM etl_monitoring_sessions 
WHERE status = 'running'

UNION ALL

SELECT 
    'errors_1h' as metric_name,
    COUNT(*) as metric_value,
    'Ошибок за последний час' as metric_description
FROM etl_monitoring_sessions 
WHERE status = 'error' 
    AND started_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);

-- ===================================================================
-- ПРОЦЕДУРЫ ДЛЯ ОБСЛУЖИВАНИЯ
-- ===================================================================

DELIMITER //

-- Процедура очистки старых данных мониторинга
CREATE PROCEDURE IF NOT EXISTS CleanupMonitoringData(IN days_to_keep INT)
BEGIN
    DECLARE sessions_deleted INT DEFAULT 0;
    DECLARE metrics_deleted INT DEFAULT 0;
    DECLARE notifications_deleted INT DEFAULT 0;
    
    -- Очистка старых сессий мониторинга
    DELETE FROM etl_monitoring_sessions 
    WHERE started_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY)
        AND status IN ('success', 'error', 'cancelled');
    
    SET sessions_deleted = ROW_COUNT();
    
    -- Очистка старых метрик производительности
    DELETE FROM etl_performance_metrics 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    
    SET metrics_deleted = ROW_COUNT();
    
    -- Очистка старых уведомлений
    DELETE FROM etl_notifications 
    WHERE created_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    
    SET notifications_deleted = ROW_COUNT();
    
    -- Логируем результаты очистки
    INSERT INTO etl_logs (source, level, message, created_at) 
    VALUES ('monitoring_cleanup', 'INFO', 
            CONCAT('Очистка мониторинга: сессий=', sessions_deleted, 
                   ', метрик=', metrics_deleted, 
                   ', уведомлений=', notifications_deleted), 
            NOW());
END //

-- Процедура обновления системных алертов
CREATE PROCEDURE IF NOT EXISTS UpdateSystemAlerts()
BEGIN
    DECLARE stuck_tasks_count INT DEFAULT 0;
    DECLARE high_error_rate_count INT DEFAULT 0;
    
    -- Проверяем зависшие задачи
    SELECT COUNT(*) INTO stuck_tasks_count
    FROM etl_monitoring_sessions 
    WHERE status = 'running' 
        AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR);
    
    -- Создаем или обновляем алерт о зависших задачах
    IF stuck_tasks_count > 0 THEN
        INSERT INTO etl_system_alerts 
        (alert_type, severity, title, description, alert_data, first_detected_at)
        VALUES 
        ('stuck_tasks', 'high', 'Обнаружены зависшие задачи', 
         CONCAT('Найдено ', stuck_tasks_count, ' задач, выполняющихся более часа'),
         JSON_OBJECT('stuck_count', stuck_tasks_count),
         NOW())
        ON DUPLICATE KEY UPDATE
            description = CONCAT('Найдено ', stuck_tasks_count, ' задач, выполняющихся более часа'),
            alert_data = JSON_OBJECT('stuck_count', stuck_tasks_count),
            last_updated_at = NOW();
    END IF;
    
    -- Проверяем высокую частоту ошибок
    SELECT COUNT(*) INTO high_error_rate_count
    FROM etl_monitoring_sessions 
    WHERE status = 'error' 
        AND started_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR);
    
    -- Создаем или обновляем алерт о высокой частоте ошибок
    IF high_error_rate_count >= 5 THEN
        INSERT INTO etl_system_alerts 
        (alert_type, severity, title, description, alert_data, first_detected_at)
        VALUES 
        ('high_error_rate', 'critical', 'Высокая частота ошибок', 
         CONCAT('Зафиксировано ', high_error_rate_count, ' ошибок за последний час'),
         JSON_OBJECT('error_count', high_error_rate_count),
         NOW())
        ON DUPLICATE KEY UPDATE
            description = CONCAT('Зафиксировано ', high_error_rate_count, ' ошибок за последний час'),
            alert_data = JSON_OBJECT('error_count', high_error_rate_count),
            last_updated_at = NOW();
    END IF;
END //

-- Процедура генерации отчета о производительности
CREATE PROCEDURE IF NOT EXISTS GeneratePerformanceReport(IN days_back INT)
BEGIN
    SELECT 
        'Общая статистика' as section,
        '' as task_type,
        COUNT(*) as total_tasks,
        COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_tasks,
        COUNT(CASE WHEN status = 'error' THEN 1 END) as failed_tasks,
        ROUND(COUNT(CASE WHEN status = 'success' THEN 1 END) * 100.0 / COUNT(*), 2) as success_rate,
        ROUND(AVG(CASE WHEN status IN ('success', 'error') THEN duration_seconds END), 2) as avg_duration,
        SUM(records_processed) as total_records
    FROM etl_monitoring_sessions 
    WHERE started_at >= DATE_SUB(NOW(), INTERVAL days_back DAY)
    
    UNION ALL
    
    SELECT 
        'По типам задач' as section,
        task_type,
        COUNT(*) as total_tasks,
        COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_tasks,
        COUNT(CASE WHEN status = 'error' THEN 1 END) as failed_tasks,
        ROUND(COUNT(CASE WHEN status = 'success' THEN 1 END) * 100.0 / COUNT(*), 2) as success_rate,
        ROUND(AVG(CASE WHEN status IN ('success', 'error') THEN duration_seconds END), 2) as avg_duration,
        SUM(records_processed) as total_records
    FROM etl_monitoring_sessions 
    WHERE started_at >= DATE_SUB(NOW(), INTERVAL days_back DAY)
    GROUP BY task_type
    ORDER BY section, total_tasks DESC;
END //

DELIMITER ;

-- ===================================================================
-- СОБЫТИЯ ДЛЯ АВТОМАТИЧЕСКОГО ОБСЛУЖИВАНИЯ
-- ===================================================================

-- Автоматическая очистка данных мониторинга каждый день в 3:00
CREATE EVENT IF NOT EXISTS etl_monitoring_daily_cleanup
ON SCHEDULE EVERY 1 DAY STARTS '2024-01-01 03:00:00'
DO
BEGIN
    CALL CleanupMonitoringData(90);
END;

-- Обновление системных алертов каждые 5 минут
CREATE EVENT IF NOT EXISTS etl_monitoring_alert_update
ON SCHEDULE EVERY 5 MINUTE
DO
BEGIN
    CALL UpdateSystemAlerts();
END;

-- ===================================================================
-- ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ ПРОИЗВОДИТЕЛЬНОСТИ
-- ===================================================================

-- Дополнительные составные индексы для быстрых запросов
ALTER TABLE etl_monitoring_sessions 
ADD INDEX idx_task_type_status_started (task_type, status, started_at),
ADD INDEX idx_status_duration (status, duration_seconds),
ADD INDEX idx_records_processed (records_processed);

ALTER TABLE etl_performance_metrics 
ADD INDEX idx_task_type_throughput (task_type, throughput_per_second),
ADD INDEX idx_duration_records (duration_seconds, records_processed);

ALTER TABLE etl_notifications 
ADD INDEX idx_type_priority_created (notification_type, priority, created_at),
ADD INDEX idx_alert_key_created (alert_key, created_at);

ALTER TABLE etl_system_alerts 
ADD INDEX idx_type_severity_status (alert_type, severity, status),
ADD INDEX idx_status_detected (status, first_detected_at);

-- ===================================================================
-- КОММЕНТАРИИ И ДОКУМЕНТАЦИЯ
-- ===================================================================

/*
ОПИСАНИЕ СХЕМЫ МОНИТОРИНГА ETL:

1. etl_monitoring_sessions - основная таблица для отслеживания выполнения ETL задач
   - Содержит полную информацию о каждой сессии выполнения
   - Отслеживает прогресс, статус и результаты
   - Поддерживает JSON метаданные для гибкости

2. etl_performance_metrics - метрики производительности
   - Детальные метрики для каждой завершенной задачи
   - Пропускная способность, использование ресурсов
   - Основа для анализа трендов производительности

3. etl_notifications - система уведомлений
   - Отслеживание отправленных уведомлений
   - Предотвращение спама через cooldown механизм
   - Поддержка различных каналов уведомлений

4. etl_system_alerts - системные алерты
   - Активные проблемы системы
   - Статусы подтверждения и разрешения
   - Автоматическое обнаружение проблем

5. etl_monitoring_config - конфигурация мониторинга
   - Централизованные настройки системы
   - Пороговые значения и параметры
   - Поддержка различных типов данных

6. etl_dashboard_settings - пользовательские настройки
   - Персонализация dashboard для пользователей
   - Сохранение предпочтений отображения
   - Настройки по умолчанию

ОСОБЕННОСТИ:
- Автоматическая очистка старых данных
- Представления для быстрого доступа к аналитике
- Процедуры для обслуживания и отчетности
- События для автоматического мониторинга
- Оптимизированные индексы для производительности
- Поддержка JSON для гибкого хранения метаданных
*/