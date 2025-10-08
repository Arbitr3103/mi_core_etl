<?php

namespace MDM\Services;

use PDO;
use PDOException;

/**
 * Сервис для оптимизации производительности базы данных MDM
 */
class DatabaseOptimizationService
{
    private PDO $pdo;
    private array $config;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'cache_ttl' => 3600,
            'batch_size' => 1000,
            'max_query_time' => 5.0
        ], $config);
    }
    
    /**
     * Применить все оптимизации производительности
     */
    public function applyOptimizations(): array
    {
        $results = [];
        
        try {
            $results['indexes'] = $this->createOptimalIndexes();
            $results['partitions'] = $this->setupPartitioning();
            $results['procedures'] = $this->createStoredProcedures();
            $results['monitoring'] = $this->setupPerformanceMonitoring();
            
            return [
                'success' => true,
                'results' => $results,
                'message' => 'Все оптимизации успешно применены'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Создать оптимальные индексы
     */
    private function createOptimalIndexes(): array
    {
        $indexes = [
            // Индексы для master_products
            'idx_master_products_brand_category' => 
                'CREATE INDEX IF NOT EXISTS idx_master_products_brand_category ON master_products(canonical_brand, canonical_category)',
            'idx_master_products_name_fulltext' => 
                'CREATE FULLTEXT INDEX IF NOT EXISTS idx_master_products_name_fulltext ON master_products(canonical_name)',
            'idx_master_products_status_updated' => 
                'CREATE INDEX IF NOT EXISTS idx_master_products_status_updated ON master_products(status, updated_at)',
            
            // Индексы для sku_mapping
            'idx_sku_mapping_master_source' => 
                'CREATE INDEX IF NOT EXISTS idx_sku_mapping_master_source ON sku_mapping(master_id, source)',
            'idx_sku_mapping_confidence' => 
                'CREATE INDEX IF NOT EXISTS idx_sku_mapping_confidence ON sku_mapping(confidence_score DESC)',
            'idx_sku_mapping_verification' => 
                'CREATE INDEX IF NOT EXISTS idx_sku_mapping_verification ON sku_mapping(verification_status, created_at)',
            
            // Индексы для data_quality_metrics
            'idx_quality_metrics_name_date' => 
                'CREATE INDEX IF NOT EXISTS idx_quality_metrics_name_date ON data_quality_metrics(metric_name, calculation_date DESC)'
        ];
        
        $created = [];
        foreach ($indexes as $name => $sql) {
            try {
                $this->pdo->exec($sql);
                $created[] = $name;
            } catch (PDOException $e) {
                // Индекс уже существует или другая ошибка
                error_log("Failed to create index {$name}: " . $e->getMessage());
            }
        }
        
        return $created;
    }
    
    /**
     * Настроить партиционирование таблиц
     */
    private function setupPartitioning(): array
    {
        $partitions = [];
        
        // Проверяем, поддерживает ли MySQL партиционирование
        $stmt = $this->pdo->query("SHOW PLUGINS WHERE Name = 'partition'");
        if (!$stmt->fetch()) {
            return ['error' => 'Партиционирование не поддерживается'];
        }
        
        try {
            // Партиционирование sku_mapping по источникам
            $this->pdo->exec("
                ALTER TABLE sku_mapping 
                PARTITION BY LIST COLUMNS(source) (
                    PARTITION p_ozon VALUES IN ('ozon'),
                    PARTITION p_wildberries VALUES IN ('wildberries', 'wb'),
                    PARTITION p_internal VALUES IN ('internal', 'local'),
                    PARTITION p_other VALUES IN ('other', 'external')
                )
            ");
            $partitions[] = 'sku_mapping_by_source';
        } catch (PDOException $e) {
            // Таблица уже партиционирована или ошибка
            error_log("Partitioning sku_mapping failed: " . $e->getMessage());
        }
        
        return $partitions;
    }
    
    /**
     * Создать хранимые процедуры для оптимизации
     */
    private function createStoredProcedures(): array
    {
        $procedures = [];
        
        // Процедура поиска похожих товаров
        $findSimilarProcedure = "
        CREATE PROCEDURE IF NOT EXISTS sp_find_similar_products(
            IN p_name VARCHAR(500),
            IN p_brand VARCHAR(200),
            IN p_category VARCHAR(200),
            IN p_limit INT
        )
        BEGIN
            SELECT 
                mp.master_id,
                mp.canonical_name,
                mp.canonical_brand,
                mp.canonical_category,
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
        END
        ";
        
        try {
            $this->pdo->exec($findSimilarProcedure);
            $procedures[] = 'sp_find_similar_products';
        } catch (PDOException $e) {
            error_log("Failed to create sp_find_similar_products: " . $e->getMessage());
        }
        
        // Процедура обновления метрик качества
        $updateMetricsProcedure = "
        CREATE PROCEDURE IF NOT EXISTS sp_update_quality_metrics()
        BEGIN
            -- Очистка старых метрик
            DELETE FROM data_quality_metrics 
            WHERE calculation_date < DATE_SUB(NOW(), INTERVAL 30 DAY);
            
            -- Обновление метрик покрытия
            INSERT INTO data_quality_metrics (metric_name, metric_value, calculation_date)
            SELECT 'coverage_percentage', 
                   (COUNT(DISTINCT sm.master_id) * 100.0 / COUNT(DISTINCT mp.master_id)),
                   NOW()
            FROM master_products mp
            LEFT JOIN sku_mapping sm ON mp.master_id = sm.master_id
            WHERE mp.status = 'active';
            
            -- Обновление метрик автоверификации
            INSERT INTO data_quality_metrics (metric_name, metric_value, calculation_date)
            SELECT 'auto_verification_rate',
                   (COUNT(CASE WHEN verification_status = 'auto' THEN 1 END) * 100.0 / COUNT(*)),
                   NOW()
            FROM sku_mapping;
            
            -- Обновление средней уверенности
            INSERT INTO data_quality_metrics (metric_name, metric_value, calculation_date)
            SELECT 'avg_confidence_score',
                   AVG(confidence_score),
                   NOW()
            FROM sku_mapping
            WHERE verification_status IN ('auto', 'manual');
        END
        ";
        
        try {
            $this->pdo->exec($updateMetricsProcedure);
            $procedures[] = 'sp_update_quality_metrics';
        } catch (PDOException $e) {
            error_log("Failed to create sp_update_quality_metrics: " . $e->getMessage());
        }
        
        return $procedures;
    }
    
    /**
     * Настроить мониторинг производительности
     */
    private function setupPerformanceMonitoring(): array
    {
        $monitoring = [];
        
        // Создать таблицу для логирования медленных запросов
        $slowQueryTable = "
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
        )
        ";
        
        try {
            $this->pdo->exec($slowQueryTable);
            $monitoring[] = 'slow_query_log_table';
        } catch (PDOException $e) {
            error_log("Failed to create slow_query_log table: " . $e->getMessage());
        }
        
        // Создать представление для анализа производительности
        $performanceView = "
        CREATE OR REPLACE VIEW v_performance_analysis AS
        SELECT 
            DATE(timestamp) as date,
            COUNT(*) as slow_queries_count,
            AVG(query_time) as avg_query_time,
            MAX(query_time) as max_query_time,
            AVG(rows_examined) as avg_rows_examined
        FROM slow_query_log
        GROUP BY DATE(timestamp)
        ORDER BY date DESC
        ";
        
        try {
            $this->pdo->exec($performanceView);
            $monitoring[] = 'performance_analysis_view';
        } catch (PDOException $e) {
            error_log("Failed to create performance analysis view: " . $e->getMessage());
        }
        
        return $monitoring;
    }
    
    /**
     * Анализ производительности запросов
     */
    public function analyzeQueryPerformance(): array
    {
        try {
            // Получить статистику медленных запросов
            $stmt = $this->pdo->query("
                SELECT 
                    DATE(timestamp) as date,
                    COUNT(*) as slow_queries_count,
                    AVG(query_time) as avg_query_time,
                    MAX(query_time) as max_query_time
                FROM slow_query_log 
                WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(timestamp)
                ORDER BY date DESC
            ");
            
            $performance_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Получить топ медленных запросов
            $stmt = $this->pdo->query("
                SELECT 
                    sql_text,
                    AVG(query_time) as avg_time,
                    COUNT(*) as execution_count,
                    MAX(query_time) as max_time
                FROM slow_query_log 
                WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY sql_text
                ORDER BY avg_time DESC
                LIMIT 10
            ");
            
            $slow_queries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'success' => true,
                'performance_data' => $performance_data,
                'slow_queries' => $slow_queries
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Оптимизировать конкретный запрос
     */
    public function optimizeQuery(string $query): array
    {
        try {
            // Анализ плана выполнения запроса
            $stmt = $this->pdo->prepare("EXPLAIN " . $query);
            $stmt->execute();
            $explain = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $recommendations = [];
            
            foreach ($explain as $row) {
                // Проверка на отсутствие индексов
                if ($row['key'] === null && $row['rows'] > 1000) {
                    $recommendations[] = "Рекомендуется создать индекс для таблицы {$row['table']}";
                }
                
                // Проверка на полное сканирование таблицы
                if ($row['type'] === 'ALL' && $row['rows'] > 100) {
                    $recommendations[] = "Полное сканирование таблицы {$row['table']} - рассмотрите добавление WHERE условий или индексов";
                }
                
                // Проверка на использование временных таблиц
                if (isset($row['Extra']) && strpos($row['Extra'], 'Using temporary') !== false) {
                    $recommendations[] = "Запрос использует временные таблицы - рассмотрите оптимизацию ORDER BY или GROUP BY";
                }
            }
            
            return [
                'success' => true,
                'explain' => $explain,
                'recommendations' => $recommendations
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Получить рекомендации по оптимизации
     */
    public function getOptimizationRecommendations(): array
    {
        $recommendations = [];
        
        try {
            // Проверка размеров таблиц
            $stmt = $this->pdo->query("
                SELECT 
                    table_name,
                    table_rows,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                FROM information_schema.tables 
                WHERE table_schema = DATABASE()
                AND table_name IN ('master_products', 'sku_mapping', 'data_quality_metrics')
                ORDER BY size_mb DESC
            ");
            
            $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($tables as $table) {
                if ($table['size_mb'] > 100) {
                    $recommendations[] = "Таблица {$table['table_name']} занимает {$table['size_mb']} MB - рассмотрите архивирование старых данных";
                }
                
                if ($table['table_rows'] > 100000) {
                    $recommendations[] = "Таблица {$table['table_name']} содержит {$table['table_rows']} записей - рассмотрите партиционирование";
                }
            }
            
            // Проверка неиспользуемых индексов
            $stmt = $this->pdo->query("
                SELECT 
                    s.table_name,
                    s.index_name,
                    s.cardinality
                FROM information_schema.statistics s
                WHERE s.table_schema = DATABASE()
                AND s.cardinality < 10
                AND s.index_name != 'PRIMARY'
            ");
            
            $unused_indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($unused_indexes as $index) {
                $recommendations[] = "Индекс {$index['index_name']} в таблице {$index['table_name']} имеет низкую селективность";
            }
            
            return [
                'success' => true,
                'recommendations' => $recommendations,
                'table_stats' => $tables
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}