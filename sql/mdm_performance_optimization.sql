-- MDM Performance Optimization Schema
-- Оптимальные индексы для частых запросов и партиционирование

-- ============================================================================
-- ИНДЕКСЫ ДЛЯ ОПТИМИЗАЦИИ ПРОИЗВОДИТЕЛЬНОСТИ
-- ============================================================================

-- Индексы для таблицы master_products
CREATE INDEX idx_master_products_brand_category ON master_products(canonical_brand, canonical_category);
CREATE INDEX idx_master_products_name_fulltext ON master_products(canonical_name);
CREATE INDEX idx_master_products_status_updated ON master_products(status, updated_at);
CREATE INDEX idx_master_products_created_date ON master_products(created_at);

-- Индексы для таблицы sku_mapping
CREATE INDEX idx_sku_mapping_master_source ON sku_mapping(master_id, source);
CREATE INDEX idx_sku_mapping_confidence ON sku_mapping(confidence_score DESC);
CREATE INDEX idx_sku_mapping_verification ON sku_mapping(verification_status, created_at);
CREATE INDEX idx_sku_mapping_source_name ON sku_mapping(source, source_name);
CREATE INDEX idx_sku_mapping_updated ON sku_mapping(updated_at);

-- Индексы для таблицы data_quality_metrics
CREATE INDEX idx_quality_metrics_name_date ON data_quality_metrics(metric_name, calculation_date DESC);
CREATE INDEX idx_quality_metrics_value ON data_quality_metrics(metric_value);

-- ============================================================================
-- ПАРТИЦИОНИРОВАНИЕ БОЛЬШИХ ТАБЛИЦ
-- ============================================================================

-- Партиционирование таблицы sku_mapping по источникам данных
ALTER TABLE sku_mapping 
PARTITION BY LIST COLUMNS(source) (
    PARTITION p_ozon VALUES IN ('ozon'),
    PARTITION p_wildberries VALUES IN ('wildberries', 'wb'),
    PARTITION p_internal VALUES IN ('internal', 'local'),
    PARTITION p_other VALUES IN ('other', 'external')
);

-- Партиционирование таблицы data_quality_metrics по датам
ALTER TABLE data_quality_metrics 
PARTITION BY RANGE (YEAR(calculation_date)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p2026 VALUES LESS THAN (2027),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- ============================================================================
-- ОПТИМИЗИРОВАННЫЕ ПРЕДСТАВЛЕНИЯ ДЛЯ ЧАСТЫХ ЗАПРОСОВ
-- ============================================================================

-- Материализованное представление для статистики по брендам
CREATE VIEW v_brand_statistics AS
SELECT 
    mp.canonical_brand,
    COUNT(DISTINCT mp.master_id) as total_products,
    COUNT(DISTINCT sm.external_sku) as total_skus,
    AVG(sm.confidence_score) as avg_confidence,
    COUNT(CASE WHEN sm.verification_status = 'auto' THEN 1 END) as auto_verified,
    COUNT(CASE WHEN sm.verification_status = 'manual' THEN 1 END) as manual_verified,
    COUNT(CASE WHEN sm.verification_status = 'pending' THEN 1 END) as pending_verification
FROM master_products mp
LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
WHERE mp.status = 'active'
GROUP BY mp.canonical_brand;

-- Представление для качества данных по источникам
CREATE VIEW v_source_quality AS
SELECT 
    sm.source,
    COUNT(*) as total_mappings,
    AVG(sm.confidence_score) as avg_confidence,
    COUNT(CASE WHEN sm.confidence_score >= 0.9 THEN 1 END) as high_confidence,
    COUNT(CASE WHEN sm.confidence_score BETWEEN 0.7 AND 0.89 THEN 1 END) as medium_confidence,
    COUNT(CASE WHEN sm.confidence_score < 0.7 THEN 1 END) as low_confidence,
    COUNT(CASE WHEN sm.verification_status = 'pending' THEN 1 END) as pending_review
FROM sku_mapping sm
GROUP BY sm.source;

-- ============================================================================
-- ОПТИМИЗИРОВАННЫЕ ХРАНИМЫЕ ПРОЦЕДУРЫ
-- ============================================================================

DELIMITER //

-- Процедура для быстрого поиска похожих товаров
CREATE PROCEDURE sp_find_similar_products(
    IN p_name VARCHAR(500),
    IN p_brand VARCHAR(200),
    IN p_category VARCHAR(200),
    IN p_limit INT DEFAULT 10
)
BEGIN
    SELECT 
        mp.master_id,
        mp.canonical_name,
        mp.canonical_brand,
        mp.canonical_category,
        -- Расчет similarity score
        (
            CASE WHEN mp.canonical_brand = p_brand THEN 30 ELSE 0 END +
            CASE WHEN mp.canonical_category = p_category THEN 20 ELSE 0 END +
            CASE WHEN MATCH(mp.canonical_name) AGAINST(p_name IN NATURAL LANGUAGE MODE) THEN 50 ELSE 0 END
        ) as similarity_score
    FROM master_products mp
    WHERE mp.status = 'active'
    AND (
        mp.canonical_brand = p_brand
        OR mp.canonical_category = p_category
        OR MATCH(mp.canonical_name) AGAINST(p_name IN NATURAL LANGUAGE MODE)
    )
    ORDER BY similarity_score DESC
    LIMIT p_limit;
END //

-- Процедура для обновления метрик качества данных
CREATE PROCEDURE sp_update_quality_metrics()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE metric_name VARCHAR(100);
    DECLARE metric_value DECIMAL(10,2);
    
    -- Курсор для различных метрик
    DECLARE metrics_cursor CURSOR FOR
        SELECT 'coverage_percentage', 
               (COUNT(DISTINCT sm.master_id) * 100.0 / COUNT(DISTINCT mp.master_id))
        FROM master_products mp
        LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
        WHERE mp.status = 'active'
        
        UNION ALL
        
        SELECT 'auto_verification_rate',
               (COUNT(CASE WHEN verification_status = 'auto' THEN 1 END) * 100.0 / COUNT(*))
        FROM sku_mapping
        
        UNION ALL
        
        SELECT 'avg_confidence_score',
               AVG(confidence_score)
        FROM sku_mapping
        WHERE verification_status IN ('auto', 'manual');
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Очистка старых метрик (старше 30 дней)
    DELETE FROM data_quality_metrics 
    WHERE calculation_date < DATE_SUB(NOW(), INTERVAL 30 DAY);
    
    -- Обновление метрик
    OPEN metrics_cursor;
    
    read_loop: LOOP
        FETCH metrics_cursor INTO metric_name, metric_value;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        INSERT INTO data_quality_metrics (metric_name, metric_value, calculation_date)
        VALUES (metric_name, metric_value, NOW());
    END LOOP;
    
    CLOSE metrics_cursor;
END //

DELIMITER ;

-- ============================================================================
-- НАСТРОЙКИ MYSQL ДЛЯ ОПТИМИЗАЦИИ
-- ============================================================================

-- Рекомендуемые настройки для my.cnf
/*
[mysqld]
# Увеличение размера буферного пула InnoDB
innodb_buffer_pool_size = 2G

# Оптимизация для записи
innodb_log_file_size = 256M
innodb_log_buffer_size = 64M
innodb_flush_log_at_trx_commit = 2

# Оптимизация для чтения
query_cache_size = 256M
query_cache_type = 1

# Настройки соединений
max_connections = 200
thread_cache_size = 50

# Временные таблицы
tmp_table_size = 256M
max_heap_table_size = 256M
*/

-- ============================================================================
-- МОНИТОРИНГ ПРОИЗВОДИТЕЛЬНОСТИ
-- ============================================================================

-- Таблица для логирования медленных запросов
CREATE TABLE IF NOT EXISTS slow_query_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    query_time DECIMAL(10,6),
    lock_time DECIMAL(10,6),
    rows_sent INT,
    rows_examined INT,
    sql_text TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_query_time (query_time),
    INDEX idx_timestamp (timestamp)
);

-- Представление для анализа производительности
CREATE VIEW v_performance_analysis AS
SELECT 
    DATE(timestamp) as date,
    COUNT(*) as slow_queries_count,
    AVG(query_time) as avg_query_time,
    MAX(query_time) as max_query_time,
    AVG(rows_examined) as avg_rows_examined
FROM slow_query_log
GROUP BY DATE(timestamp)
ORDER BY date DESC;