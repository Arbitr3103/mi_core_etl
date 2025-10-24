<?php
/**
 * BusinessMetricsMonitor Class - Мониторинг бизнес-метрик для Ozon Stock Reports
 * 
 * Отслеживает критические бизнес-метрики, такие как уровни запасов,
 * свежесть данных, полнота информации и тренды движения товаров.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class BusinessMetricsMonitor {
    
    private $pdo;
    private $config;
    private $logger;
    
    // Константы для бизнес-метрик
    const CRITICAL_STOCK_THRESHOLD = 10;     // Критический уровень остатков
    const LOW_STOCK_THRESHOLD = 50;          // Низкий уровень остатков
    const DATA_FRESHNESS_HOURS = 24;         // Максимальный возраст данных в часах
    const COMPLETENESS_THRESHOLD = 95;       // Минимальный процент полноты данных
    const TREND_ANALYSIS_DAYS = 30;          // Период анализа трендов в днях
    
    /**
     * Конструктор
     * 
     * @param PDO $pdo - подключение к базе данных
     * @param array $config - конфигурация мониторинга
     */
    public function __construct(PDO $pdo, array $config = []) {
        $this->pdo = $pdo;
        $this->config = array_merge($this->getDefaultConfig(), $config);
        $this->initializeLogger();
        $this->initializeBusinessMetricsTables();
    }
    
    /**
     * Получение конфигурации по умолчанию
     */
    private function getDefaultConfig(): array {
        return [
            'critical_stock_threshold' => self::CRITICAL_STOCK_THRESHOLD,
            'low_stock_threshold' => self::LOW_STOCK_THRESHOLD,
            'data_freshness_hours' => self::DATA_FRESHNESS_HOURS,
            'completeness_threshold' => self::COMPLETENESS_THRESHOLD,
            'trend_analysis_days' => self::TREND_ANALYSIS_DAYS,
            'enable_alerts' => true,
            'alert_channels' => ['database', 'log'],
            'warehouse_priorities' => [
                'Хоругвино' => 'high',
                'Тверь' => 'high',
                'Подольск' => 'medium',
                'Екатеринбург' => 'medium'
            ]
        ];
    }
    
    /**
     * Инициализация логгера
     */
    private function initializeLogger(): void {
        $this->logger = [
            'info' => function($message, $context = []) {
                error_log("[INFO] BusinessMetricsMonitor: $message " . json_encode($context));
            },
            'warning' => function($message, $context = []) {
                error_log("[WARNING] BusinessMetricsMonitor: $message " . json_encode($context));
            },
            'error' => function($message, $context = []) {
                error_log("[ERROR] BusinessMetricsMonitor: $message " . json_encode($context));
            }
        ];
    }
    
    /**
     * Инициализация таблиц для бизнес-метрик
     */
    private function initializeBusinessMetricsTables(): void {
        try {
            // Таблица для хранения бизнес-метрик
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS business_metrics_snapshots (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    snapshot_date DATE NOT NULL,
                    metric_type ENUM('stock_levels', 'data_freshness', 'completeness', 'trends') NOT NULL,
                    warehouse_name VARCHAR(255) NULL,
                    metric_value DECIMAL(10,2) NOT NULL,
                    metric_details JSON NULL,
                    alert_level ENUM('normal', 'warning', 'critical') DEFAULT 'normal',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    UNIQUE KEY unique_snapshot (snapshot_date, metric_type, warehouse_name),
                    INDEX idx_snapshot_date (snapshot_date),
                    INDEX idx_metric_type (metric_type),
                    INDEX idx_alert_level (alert_level),
                    INDEX idx_warehouse_name (warehouse_name)
                ) ENGINE=InnoDB
            ");
            
            // Таблица для критических уведомлений по запасам
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS critical_stock_alerts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    sku VARCHAR(255) NOT NULL,
                    warehouse_name VARCHAR(255) NOT NULL,
                    current_stock INT NOT NULL,
                    threshold_type ENUM('critical', 'low') NOT NULL,
                    threshold_value INT NOT NULL,
                    sales_velocity DECIMAL(8,2) NULL,
                    days_of_stock DECIMAL(8,2) NULL,
                    alert_status ENUM('active', 'acknowledged', 'resolved') DEFAULT 'active',
                    first_detected TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    resolved_at TIMESTAMP NULL,
                    
                    UNIQUE KEY unique_alert (product_id, warehouse_name, threshold_type),
                    INDEX idx_alert_status (alert_status),
                    INDEX idx_warehouse_name (warehouse_name),
                    INDEX idx_threshold_type (threshold_type),
                    INDEX idx_first_detected (first_detected)
                ) ENGINE=InnoDB
            ");
            
            // Таблица для трендов движения товаров
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS stock_movement_trends (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    product_id INT NOT NULL,
                    sku VARCHAR(255) NOT NULL,
                    warehouse_name VARCHAR(255) NOT NULL,
                    analysis_date DATE NOT NULL,
                    period_days INT NOT NULL,
                    avg_daily_sales DECIMAL(8,2) NULL,
                    stock_turnover_rate DECIMAL(8,4) NULL,
                    trend_direction ENUM('increasing', 'stable', 'decreasing') NULL,
                    trend_strength DECIMAL(5,2) NULL,
                    seasonality_factor DECIMAL(5,2) NULL,
                    forecast_accuracy DECIMAL(5,2) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    UNIQUE KEY unique_trend (product_id, warehouse_name, analysis_date, period_days),
                    INDEX idx_analysis_date (analysis_date),
                    INDEX idx_trend_direction (trend_direction),
                    INDEX idx_warehouse_name (warehouse_name)
                ) ENGINE=InnoDB
            ");
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to initialize business metrics tables", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Выполнение полного анализа бизнес-метрик
     * 
     * @return array результаты анализа
     */
    public function performBusinessMetricsAnalysis(): array {
        ($this->logger['info'])("Starting business metrics analysis");
        
        $analysis = [
            'timestamp' => date('Y-m-d H:i:s'),
            'metrics' => [],
            'alerts' => [],
            'recommendations' => []
        ];
        
        try {
            // Анализ уровней запасов
            $stockAnalysis = $this->analyzeStockLevels();
            $analysis['metrics']['stock_levels'] = $stockAnalysis;
            
            // Анализ свежести данных
            $freshnessAnalysis = $this->analyzeDataFreshness();
            $analysis['metrics']['data_freshness'] = $freshnessAnalysis;
            
            // Анализ полноты данных
            $completenessAnalysis = $this->analyzeDataCompleteness();
            $analysis['metrics']['data_completeness'] = $completenessAnalysis;
            
            // Анализ трендов движения товаров
            $trendsAnalysis = $this->analyzeStockMovementTrends();
            $analysis['metrics']['movement_trends'] = $trendsAnalysis;
            
            // Генерация уведомлений
            $analysis['alerts'] = $this->generateBusinessAlerts($analysis['metrics']);
            
            // Генерация рекомендаций
            $analysis['recommendations'] = $this->generateBusinessRecommendations($analysis['metrics']);
            
            // Сохранение снимка метрик
            $this->saveMetricsSnapshot($analysis['metrics']);
            
            ($this->logger['info'])("Business metrics analysis completed", [
                'metrics_count' => count($analysis['metrics']),
                'alerts_count' => count($analysis['alerts'])
            ]);
            
        } catch (Exception $e) {
            $analysis['error'] = $e->getMessage();
            ($this->logger['error'])("Business metrics analysis failed", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $analysis;
    }
    
    /**
     * Анализ уровней запасов по складам
     */
    private function analyzeStockLevels(): array {
        $analysis = [
            'total_products' => 0,
            'critical_stock_count' => 0,
            'low_stock_count' => 0,
            'zero_stock_count' => 0,
            'warehouses' => [],
            'critical_products' => []
        ];
        
        try {
            // Общая статистика по уровням запасов
            $sql = "SELECT 
                        warehouse_name,
                        COUNT(*) as total_products,
                        COUNT(CASE WHEN current_stock = 0 THEN 1 END) as zero_stock,
                        COUNT(CASE WHEN current_stock > 0 AND current_stock <= :critical_threshold THEN 1 END) as critical_stock,
                        COUNT(CASE WHEN current_stock > :critical_threshold AND current_stock <= :low_threshold THEN 1 END) as low_stock,
                        AVG(current_stock) as avg_stock,
                        SUM(current_stock) as total_stock
                    FROM inventory 
                    WHERE report_source = 'API_REPORTS'
                    AND last_report_update >= DATE_SUB(NOW(), INTERVAL :freshness_hours HOUR)
                    GROUP BY warehouse_name
                    ORDER BY warehouse_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'critical_threshold' => $this->config['critical_stock_threshold'],
                'low_threshold' => $this->config['low_stock_threshold'],
                'freshness_hours' => $this->config['data_freshness_hours']
            ]);
            
            $warehouseStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($warehouseStats as $warehouse) {
                $analysis['total_products'] += $warehouse['total_products'];
                $analysis['critical_stock_count'] += $warehouse['critical_stock'];
                $analysis['low_stock_count'] += $warehouse['low_stock'];
                $analysis['zero_stock_count'] += $warehouse['zero_stock'];
                
                $analysis['warehouses'][$warehouse['warehouse_name']] = [
                    'total_products' => $warehouse['total_products'],
                    'zero_stock' => $warehouse['zero_stock'],
                    'critical_stock' => $warehouse['critical_stock'],
                    'low_stock' => $warehouse['low_stock'],
                    'avg_stock' => round($warehouse['avg_stock'], 2),
                    'total_stock' => $warehouse['total_stock'],
                    'priority' => $this->config['warehouse_priorities'][$warehouse['warehouse_name']] ?? 'low'
                ];
            }
            
            // Получение списка критических товаров
            $criticalSql = "SELECT 
                                i.product_id,
                                i.sku,
                                i.warehouse_name,
                                i.current_stock,
                                i.reserved_stock,
                                i.available_stock,
                                i.last_report_update
                            FROM inventory i
                            WHERE i.report_source = 'API_REPORTS'
                            AND i.current_stock <= :critical_threshold
                            AND i.last_report_update >= DATE_SUB(NOW(), INTERVAL :freshness_hours HOUR)
                            ORDER BY i.current_stock ASC, i.warehouse_name
                            LIMIT 100";
            
            $stmt = $this->pdo->prepare($criticalSql);
            $stmt->execute([
                'critical_threshold' => $this->config['critical_stock_threshold'],
                'freshness_hours' => $this->config['data_freshness_hours']
            ]);
            
            $analysis['critical_products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Создание уведомлений о критических остатках
            $this->createCriticalStockAlerts($analysis['critical_products']);
            
        } catch (Exception $e) {
            $analysis['error'] = $e->getMessage();
        }
        
        return $analysis;
    }
    
    /**
     * Анализ свежести данных
     */
    private function analyzeDataFreshness(): array {
        $analysis = [
            'overall_freshness_score' => 0,
            'warehouses' => [],
            'stale_data_count' => 0,
            'fresh_data_count' => 0
        ];
        
        try {
            $sql = "SELECT 
                        warehouse_name,
                        COUNT(*) as total_records,
                        COUNT(CASE WHEN last_report_update >= DATE_SUB(NOW(), INTERVAL :freshness_hours HOUR) THEN 1 END) as fresh_records,
                        COUNT(CASE WHEN last_report_update < DATE_SUB(NOW(), INTERVAL :freshness_hours HOUR) THEN 1 END) as stale_records,
                        MAX(last_report_update) as latest_update,
                        MIN(last_report_update) as oldest_update,
                        AVG(TIMESTAMPDIFF(HOUR, last_report_update, NOW())) as avg_age_hours
                    FROM inventory 
                    WHERE report_source = 'API_REPORTS'
                    GROUP BY warehouse_name
                    ORDER BY warehouse_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['freshness_hours' => $this->config['data_freshness_hours']]);
            
            $warehouseStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $totalFresh = 0;
            $totalRecords = 0;
            
            foreach ($warehouseStats as $warehouse) {
                $freshnessPercent = $warehouse['total_records'] > 0 
                    ? round(($warehouse['fresh_records'] / $warehouse['total_records']) * 100, 2)
                    : 0;
                
                $analysis['warehouses'][$warehouse['warehouse_name']] = [
                    'total_records' => $warehouse['total_records'],
                    'fresh_records' => $warehouse['fresh_records'],
                    'stale_records' => $warehouse['stale_records'],
                    'freshness_percent' => $freshnessPercent,
                    'latest_update' => $warehouse['latest_update'],
                    'oldest_update' => $warehouse['oldest_update'],
                    'avg_age_hours' => round($warehouse['avg_age_hours'], 2)
                ];
                
                $totalFresh += $warehouse['fresh_records'];
                $totalRecords += $warehouse['total_records'];
                $analysis['stale_data_count'] += $warehouse['stale_records'];
            }
            
            $analysis['fresh_data_count'] = $totalFresh;
            $analysis['overall_freshness_score'] = $totalRecords > 0 
                ? round(($totalFresh / $totalRecords) * 100, 2)
                : 0;
            
        } catch (Exception $e) {
            $analysis['error'] = $e->getMessage();
        }
        
        return $analysis;
    }
    
    /**
     * Анализ полноты данных
     */
    private function analyzeDataCompleteness(): array {
        $analysis = [
            'overall_completeness_score' => 0,
            'missing_data_issues' => [],
            'warehouses' => []
        ];
        
        try {
            // Проверка полноты данных по складам
            $sql = "SELECT 
                        warehouse_name,
                        COUNT(*) as total_records,
                        COUNT(CASE WHEN sku IS NULL OR sku = '' THEN 1 END) as missing_sku,
                        COUNT(CASE WHEN current_stock IS NULL THEN 1 END) as missing_stock,
                        COUNT(CASE WHEN last_report_update IS NULL THEN 1 END) as missing_update_time,
                        COUNT(CASE WHEN product_id IS NULL THEN 1 END) as missing_product_id
                    FROM inventory 
                    WHERE report_source = 'API_REPORTS'
                    GROUP BY warehouse_name
                    ORDER BY warehouse_name";
            
            $stmt = $this->pdo->query($sql);
            $warehouseStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $totalRecords = 0;
            $totalComplete = 0;
            
            foreach ($warehouseStats as $warehouse) {
                $missingFields = $warehouse['missing_sku'] + $warehouse['missing_stock'] + 
                               $warehouse['missing_update_time'] + $warehouse['missing_product_id'];
                
                $completeRecords = $warehouse['total_records'] - $missingFields;
                $completenessPercent = $warehouse['total_records'] > 0 
                    ? round(($completeRecords / $warehouse['total_records']) * 100, 2)
                    : 0;
                
                $analysis['warehouses'][$warehouse['warehouse_name']] = [
                    'total_records' => $warehouse['total_records'],
                    'complete_records' => $completeRecords,
                    'completeness_percent' => $completenessPercent,
                    'missing_issues' => [
                        'sku' => $warehouse['missing_sku'],
                        'stock' => $warehouse['missing_stock'],
                        'update_time' => $warehouse['missing_update_time'],
                        'product_id' => $warehouse['missing_product_id']
                    ]
                ];
                
                $totalRecords += $warehouse['total_records'];
                $totalComplete += $completeRecords;
                
                // Добавляем проблемы с отсутствующими данными
                if ($warehouse['missing_sku'] > 0) {
                    $analysis['missing_data_issues'][] = "Missing SKU in {$warehouse['warehouse_name']}: {$warehouse['missing_sku']} records";
                }
                if ($warehouse['missing_stock'] > 0) {
                    $analysis['missing_data_issues'][] = "Missing stock data in {$warehouse['warehouse_name']}: {$warehouse['missing_stock']} records";
                }
            }
            
            $analysis['overall_completeness_score'] = $totalRecords > 0 
                ? round(($totalComplete / $totalRecords) * 100, 2)
                : 0;
            
        } catch (Exception $e) {
            $analysis['error'] = $e->getMessage();
        }
        
        return $analysis;
    }
    
    /**
     * Анализ трендов движения товаров
     */
    private function analyzeStockMovementTrends(): array {
        $analysis = [
            'trend_summary' => [],
            'top_movers' => [],
            'slow_movers' => [],
            'seasonal_patterns' => []
        ];
        
        try {
            // Анализ трендов за последние 30 дней
            $sql = "SELECT 
                        i.warehouse_name,
                        COUNT(DISTINCT i.product_id) as total_products,
                        AVG(i.current_stock) as avg_current_stock,
                        COUNT(CASE WHEN i.current_stock > LAG(i.current_stock) OVER (PARTITION BY i.product_id, i.warehouse_name ORDER BY i.last_report_update) THEN 1 END) as increasing_stock,
                        COUNT(CASE WHEN i.current_stock < LAG(i.current_stock) OVER (PARTITION BY i.product_id, i.warehouse_name ORDER BY i.last_report_update) THEN 1 END) as decreasing_stock
                    FROM inventory i
                    WHERE i.report_source = 'API_REPORTS'
                    AND i.last_report_update >= DATE_SUB(NOW(), INTERVAL :trend_days DAY)
                    GROUP BY i.warehouse_name
                    ORDER BY i.warehouse_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['trend_days' => $this->config['trend_analysis_days']]);
            
            $trendStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($trendStats as $trend) {
                $analysis['trend_summary'][$trend['warehouse_name']] = [
                    'total_products' => $trend['total_products'],
                    'avg_stock' => round($trend['avg_current_stock'], 2),
                    'increasing_trend' => $trend['increasing_stock'] ?? 0,
                    'decreasing_trend' => $trend['decreasing_stock'] ?? 0
                ];
            }
            
            // Поиск быстро движущихся товаров (высокий оборот)
            $fastMoversSQL = "SELECT 
                                i.sku,
                                i.warehouse_name,
                                i.current_stock,
                                COUNT(*) as update_frequency,
                                STDDEV(i.current_stock) as stock_volatility
                            FROM inventory i
                            WHERE i.report_source = 'API_REPORTS'
                            AND i.last_report_update >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                            GROUP BY i.sku, i.warehouse_name
                            HAVING stock_volatility > 10
                            ORDER BY stock_volatility DESC
                            LIMIT 20";
            
            $stmt = $this->pdo->query($fastMoversSQL);
            $analysis['top_movers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Поиск медленно движущихся товаров (низкий оборот)
            $slowMoversSQL = "SELECT 
                                i.sku,
                                i.warehouse_name,
                                i.current_stock,
                                i.last_report_update,
                                DATEDIFF(NOW(), i.last_report_update) as days_unchanged
                            FROM inventory i
                            WHERE i.report_source = 'API_REPORTS'
                            AND i.current_stock > 0
                            AND i.last_report_update < DATE_SUB(NOW(), INTERVAL 14 DAY)
                            ORDER BY days_unchanged DESC
                            LIMIT 20";
            
            $stmt = $this->pdo->query($slowMoversSQL);
            $analysis['slow_movers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $analysis['error'] = $e->getMessage();
        }
        
        return $analysis;
    }
    
    /**
     * Создание уведомлений о критических остатках
     */
    private function createCriticalStockAlerts(array $criticalProducts): void {
        try {
            foreach ($criticalProducts as $product) {
                $thresholdType = $product['current_stock'] == 0 ? 'critical' : 
                    ($product['current_stock'] <= $this->config['critical_stock_threshold'] ? 'critical' : 'low');
                
                $sql = "INSERT INTO critical_stock_alerts 
                        (product_id, sku, warehouse_name, current_stock, threshold_type, threshold_value, alert_status)
                        VALUES (:product_id, :sku, :warehouse_name, :current_stock, :threshold_type, :threshold_value, 'active')
                        ON DUPLICATE KEY UPDATE 
                        current_stock = VALUES(current_stock),
                        last_updated = NOW(),
                        alert_status = CASE 
                            WHEN alert_status = 'resolved' THEN 'active'
                            ELSE alert_status
                        END";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    'product_id' => $product['product_id'],
                    'sku' => $product['sku'],
                    'warehouse_name' => $product['warehouse_name'],
                    'current_stock' => $product['current_stock'],
                    'threshold_type' => $thresholdType,
                    'threshold_value' => $thresholdType === 'critical' ? 
                        $this->config['critical_stock_threshold'] : $this->config['low_stock_threshold']
                ]);
            }
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to create critical stock alerts", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Генерация бизнес-уведомлений
     */
    private function generateBusinessAlerts(array $metrics): array {
        $alerts = [];
        
        try {
            // Уведомления о критических остатках
            if (isset($metrics['stock_levels']['critical_stock_count']) && 
                $metrics['stock_levels']['critical_stock_count'] > 0) {
                
                $alerts[] = [
                    'type' => 'critical_stock',
                    'severity' => 'high',
                    'title' => 'Critical Stock Levels Detected',
                    'message' => "Found {$metrics['stock_levels']['critical_stock_count']} products with critical stock levels",
                    'details' => $metrics['stock_levels']['critical_products']
                ];
            }
            
            // Уведомления о свежести данных
            if (isset($metrics['data_freshness']['overall_freshness_score']) && 
                $metrics['data_freshness']['overall_freshness_score'] < 90) {
                
                $alerts[] = [
                    'type' => 'data_freshness',
                    'severity' => 'medium',
                    'title' => 'Data Freshness Issue',
                    'message' => "Data freshness score is {$metrics['data_freshness']['overall_freshness_score']}% (threshold: 90%)",
                    'details' => $metrics['data_freshness']['warehouses']
                ];
            }
            
            // Уведомления о полноте данных
            if (isset($metrics['data_completeness']['overall_completeness_score']) && 
                $metrics['data_completeness']['overall_completeness_score'] < $this->config['completeness_threshold']) {
                
                $alerts[] = [
                    'type' => 'data_completeness',
                    'severity' => 'medium',
                    'title' => 'Data Completeness Issue',
                    'message' => "Data completeness score is {$metrics['data_completeness']['overall_completeness_score']}% (threshold: {$this->config['completeness_threshold']}%)",
                    'details' => $metrics['data_completeness']['missing_data_issues']
                ];
            }
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to generate business alerts", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $alerts;
    }
    
    /**
     * Генерация бизнес-рекомендаций
     */
    private function generateBusinessRecommendations(array $metrics): array {
        $recommendations = [];
        
        try {
            // Рекомендации по запасам
            if (isset($metrics['stock_levels']['critical_stock_count']) && 
                $metrics['stock_levels']['critical_stock_count'] > 10) {
                
                $recommendations[] = "Consider immediate replenishment for {$metrics['stock_levels']['critical_stock_count']} products with critical stock levels";
            }
            
            // Рекомендации по свежести данных
            if (isset($metrics['data_freshness']['stale_data_count']) && 
                $metrics['data_freshness']['stale_data_count'] > 100) {
                
                $recommendations[] = "Review ETL processes - {$metrics['data_freshness']['stale_data_count']} records have stale data";
            }
            
            // Рекомендации по трендам
            if (isset($metrics['movement_trends']['slow_movers']) && 
                count($metrics['movement_trends']['slow_movers']) > 10) {
                
                $recommendations[] = "Review slow-moving inventory - " . count($metrics['movement_trends']['slow_movers']) . " products haven't moved in 14+ days";
            }
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to generate business recommendations", [
                'error' => $e->getMessage()
            ]);
        }
        
        return $recommendations;
    }
    
    /**
     * Сохранение снимка метрик
     */
    private function saveMetricsSnapshot(array $metrics): void {
        try {
            $snapshotDate = date('Y-m-d');
            
            // Сохранение метрик по уровням запасов
            if (isset($metrics['stock_levels']['warehouses'])) {
                foreach ($metrics['stock_levels']['warehouses'] as $warehouseName => $data) {
                    $this->saveMetricSnapshot($snapshotDate, 'stock_levels', $warehouseName, 
                        $data['critical_stock'], $data);
                }
            }
            
            // Сохранение метрик по свежести данных
            if (isset($metrics['data_freshness']['warehouses'])) {
                foreach ($metrics['data_freshness']['warehouses'] as $warehouseName => $data) {
                    $this->saveMetricSnapshot($snapshotDate, 'data_freshness', $warehouseName, 
                        $data['freshness_percent'], $data);
                }
            }
            
            // Сохранение метрик по полноте данных
            if (isset($metrics['data_completeness']['warehouses'])) {
                foreach ($metrics['data_completeness']['warehouses'] as $warehouseName => $data) {
                    $this->saveMetricSnapshot($snapshotDate, 'completeness', $warehouseName, 
                        $data['completeness_percent'], $data);
                }
            }
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to save metrics snapshot", [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сохранение отдельной метрики
     */
    private function saveMetricSnapshot(string $date, string $type, string $warehouse, float $value, array $details): void {
        try {
            $alertLevel = 'normal';
            
            // Определение уровня уведомления
            if ($type === 'stock_levels' && $value > 10) {
                $alertLevel = 'critical';
            } elseif ($type === 'data_freshness' && $value < 90) {
                $alertLevel = 'warning';
            } elseif ($type === 'completeness' && $value < $this->config['completeness_threshold']) {
                $alertLevel = 'warning';
            }
            
            $sql = "INSERT INTO business_metrics_snapshots 
                    (snapshot_date, metric_type, warehouse_name, metric_value, metric_details, alert_level)
                    VALUES (:date, :type, :warehouse, :value, :details, :alert_level)
                    ON DUPLICATE KEY UPDATE 
                    metric_value = VALUES(metric_value),
                    metric_details = VALUES(metric_details),
                    alert_level = VALUES(alert_level)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'date' => $date,
                'type' => $type,
                'warehouse' => $warehouse,
                'value' => $value,
                'details' => json_encode($details),
                'alert_level' => $alertLevel
            ]);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to save metric snapshot", [
                'error' => $e->getMessage(),
                'type' => $type,
                'warehouse' => $warehouse
            ]);
        }
    }
    
    /**
     * Получение активных критических уведомлений
     * 
     * @param int $limit максимальное количество уведомлений
     * @return array список активных уведомлений
     */
    public function getActiveCriticalAlerts(int $limit = 50): array {
        try {
            $sql = "SELECT 
                        csa.*,
                        DATEDIFF(NOW(), csa.first_detected) as days_active
                    FROM critical_stock_alerts csa
                    WHERE csa.alert_status = 'active'
                    ORDER BY 
                        CASE csa.threshold_type 
                            WHEN 'critical' THEN 1 
                            WHEN 'low' THEN 2 
                        END,
                        csa.current_stock ASC,
                        csa.first_detected ASC
                    LIMIT :limit";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['limit' => $limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get active critical alerts", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение исторических трендов метрик
     * 
     * @param string $metricType тип метрики
     * @param int $days количество дней
     * @return array исторические данные
     */
    public function getMetricsTrends(string $metricType, int $days = 30): array {
        try {
            $sql = "SELECT 
                        snapshot_date,
                        warehouse_name,
                        metric_value,
                        alert_level
                    FROM business_metrics_snapshots
                    WHERE metric_type = :metric_type
                    AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    ORDER BY snapshot_date DESC, warehouse_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'metric_type' => $metricType,
                'days' => $days
            ]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get metrics trends", [
                'error' => $e->getMessage(),
                'metric_type' => $metricType
            ]);
            return [];
        }
    }
    
    /**
     * Разрешение уведомления о критических остатках
     * 
     * @param int $alertId ID уведомления
     * @param string $reason причина разрешения
     * @return bool успешность операции
     */
    public function resolveStockAlert(int $alertId, string $reason = ''): bool {
        try {
            $sql = "UPDATE critical_stock_alerts 
                    SET alert_status = 'resolved', 
                        resolved_at = NOW()
                    WHERE id = :alert_id";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute(['alert_id' => $alertId]);
            
            if ($result) {
                ($this->logger['info'])("Stock alert resolved", [
                    'alert_id' => $alertId,
                    'reason' => $reason
                ]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to resolve stock alert", [
                'alert_id' => $alertId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}