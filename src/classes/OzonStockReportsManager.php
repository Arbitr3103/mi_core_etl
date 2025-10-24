<?php
/**
 * OzonStockReportsManager Class - Главный оркестратор процесса получения остатков через API отчетов
 * 
 * Управляет полным циклом ETL для получения точных остатков товаров на конкретных FBO-складах Ozon.
 * Использует асинхронный подход через API отчетов для получения детализированных данных по каждому складу.
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once __DIR__ . '/OzonAnalyticsAPI.php';
require_once __DIR__ . '/ReportRequestHandler.php';
require_once __DIR__ . '/ReportStatusMonitor.php';
require_once __DIR__ . '/CSVReportProcessor.php';
require_once __DIR__ . '/InventoryDataUpdater.php';
require_once __DIR__ . '/StockAlertManager.php';

class OzonStockReportsManager {
    
    private $pdo;
    private $ozonAPI;
    private $reportRequestHandler;
    private $reportStatusMonitor;
    private $csvProcessor;
    private $inventoryUpdater;
    private $stockAlertManager;
    private $logger;
    
    /**
     * Конструктор класса
     * 
     * @param PDO $pdo - подключение к базе данных
     * @param OzonAnalyticsAPI $ozonAPI - экземпляр API класса Ozon
     */
    public function __construct(PDO $pdo, OzonAnalyticsAPI $ozonAPI) {
        $this->pdo = $pdo;
        $this->ozonAPI = $ozonAPI;
        
        // Инициализируем компоненты системы
        $this->reportRequestHandler = new ReportRequestHandler($ozonAPI);
        $this->reportStatusMonitor = new ReportStatusMonitor($ozonAPI, $pdo);
        $this->csvProcessor = new CSVReportProcessor($pdo);
        $this->inventoryUpdater = new InventoryDataUpdater($pdo);
        $this->stockAlertManager = new StockAlertManager($pdo);
        
        $this->initializeLogger();
    }
    
    /**
     * Инициализация системы логирования
     */
    private function initializeLogger() {
        $this->logger = [
            'info' => function($message, $context = []) {
                error_log("[INFO] OzonStockReportsManager: $message " . json_encode($context));
            },
            'warning' => function($message, $context = []) {
                error_log("[WARNING] OzonStockReportsManager: $message " . json_encode($context));
            },
            'error' => function($message, $context = []) {
                error_log("[ERROR] OzonStockReportsManager: $message " . json_encode($context));
            }
        ];
    }
    
    /**
     * Запуск полного цикла ETL для получения остатков через API отчетов
     * 
     * @param array $filters - фильтры для отчета (опционально)
     * @return array результат выполнения ETL процесса
     * @throws Exception при критических ошибках
     */
    public function executeStockReportsETL(array $filters = []): array {
        $startTime = microtime(true);
        $etlId = uniqid('etl_', true);
        
        ($this->logger['info'])("Starting stock reports ETL process", [
            'etl_id' => $etlId,
            'filters' => $filters,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        $result = [
            'etl_id' => $etlId,
            'status' => 'started',
            'start_time' => date('Y-m-d H:i:s'),
            'steps' => [],
            'statistics' => [
                'reports_requested' => 0,
                'reports_completed' => 0,
                'records_processed' => 0,
                'records_updated' => 0,
                'alerts_generated' => 0,
                'errors_count' => 0
            ]
        ];
        
        try {
            // Шаг 1: Запрос генерации отчета
            ($this->logger['info'])("Step 1: Requesting warehouse stock report", ['etl_id' => $etlId]);
            $reportCode = $this->requestWarehouseStockReport($filters);
            
            $result['steps'][] = [
                'step' => 1,
                'name' => 'request_report',
                'status' => 'completed',
                'report_code' => $reportCode,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            $result['statistics']['reports_requested'] = 1;
            
            // Шаг 2: Мониторинг готовности отчета
            ($this->logger['info'])("Step 2: Monitoring report status", [
                'etl_id' => $etlId,
                'report_code' => $reportCode
            ]);
            
            $reportStatus = $this->monitorReportStatus($reportCode);
            
            $result['steps'][] = [
                'step' => 2,
                'name' => 'monitor_status',
                'status' => $reportStatus['status'] === 'SUCCESS' ? 'completed' : 'failed',
                'report_status' => $reportStatus,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            if ($reportStatus['status'] !== 'SUCCESS') {
                throw new Exception("Report generation failed: " . ($reportStatus['error_message'] ?? 'Unknown error'));
            }
            
            // Шаг 3: Обработка готового отчета
            ($this->logger['info'])("Step 3: Processing completed report", [
                'etl_id' => $etlId,
                'report_code' => $reportCode
            ]);
            
            $processResult = $this->processCompletedReport($reportCode);
            
            $result['steps'][] = [
                'step' => 3,
                'name' => 'process_report',
                'status' => 'completed',
                'process_result' => $processResult,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            $result['statistics']['reports_completed'] = 1;
            $result['statistics']['records_processed'] = $processResult['records_processed'] ?? 0;
            $result['statistics']['records_updated'] = $processResult['records_updated'] ?? 0;
            
            // Шаг 4: Анализ критических остатков и генерация уведомлений
            ($this->logger['info'])("Step 4: Analyzing stock levels and generating alerts", ['etl_id' => $etlId]);
            
            $alertsResult = $this->generateStockAlerts();
            
            $result['steps'][] = [
                'step' => 4,
                'name' => 'generate_alerts',
                'status' => 'completed',
                'alerts_result' => $alertsResult,
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
            $result['statistics']['alerts_generated'] = $alertsResult['alerts_count'] ?? 0;
            
            // Завершение ETL процесса
            $duration = microtime(true) - $startTime;
            $result['status'] = 'completed';
            $result['end_time'] = date('Y-m-d H:i:s');
            $result['duration_seconds'] = round($duration, 2);
            
            ($this->logger['info'])("Stock reports ETL process completed successfully", [
                'etl_id' => $etlId,
                'duration_seconds' => $result['duration_seconds'],
                'statistics' => $result['statistics']
            ]);
            
            // Сохраняем результат ETL в базу данных
            $this->saveETLResult($result);
            
            return $result;
            
        } catch (Exception $e) {
            $duration = microtime(true) - $startTime;
            $result['status'] = 'failed';
            $result['end_time'] = date('Y-m-d H:i:s');
            $result['duration_seconds'] = round($duration, 2);
            $result['error'] = $e->getMessage();
            $result['statistics']['errors_count'] = 1;
            
            ($this->logger['error'])("Stock reports ETL process failed", [
                'etl_id' => $etlId,
                'error' => $e->getMessage(),
                'duration_seconds' => $result['duration_seconds']
            ]);
            
            // Сохраняем результат с ошибкой
            $this->saveETLResult($result);
            
            throw $e;
        }
    }
    
    /**
     * Запрос генерации отчета по остаткам на складах
     * 
     * @param array $filters - фильтры для отчета
     * @return string код отчета
     * @throws Exception при ошибках запроса
     */
    public function requestWarehouseStockReport(array $filters = []): string {
        try {
            // Подготавливаем параметры для запроса отчета
            $reportParameters = $this->prepareReportParameters($filters);
            
            ($this->logger['info'])("Requesting warehouse stock report", [
                'parameters' => $reportParameters
            ]);
            
            // Отправляем запрос через ReportRequestHandler
            $reportCode = $this->reportRequestHandler->createWarehouseStockReport($reportParameters);
            
            // Сохраняем информацию о запросе в базу данных
            $this->saveReportRequest($reportCode, $reportParameters);
            
            ($this->logger['info'])("Warehouse stock report requested successfully", [
                'report_code' => $reportCode,
                'parameters' => $reportParameters
            ]);
            
            return $reportCode;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to request warehouse stock report", [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            throw $e;
        }
    }
    
    /**
     * Мониторинг статуса отчета до готовности
     * 
     * @param string $reportCode - код отчета
     * @return array статус отчета
     * @throws Exception при ошибках мониторинга
     */
    public function monitorReportStatus(string $reportCode): array {
        try {
            ($this->logger['info'])("Starting report status monitoring", [
                'report_code' => $reportCode
            ]);
            
            // Используем ReportStatusMonitor для ожидания готовности отчета
            $statusResult = $this->reportStatusMonitor->waitForReportCompletion($reportCode, 60);
            
            ($this->logger['info'])("Report status monitoring completed", [
                'report_code' => $reportCode,
                'final_status' => $statusResult['status'],
                'monitoring_duration' => $statusResult['monitoring_duration'] ?? 0
            ]);
            
            return $statusResult;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Report status monitoring failed", [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Обработка готового отчета
     * 
     * @param string $reportCode - код отчета
     * @return array результат обработки
     * @throws Exception при ошибках обработки
     */
    public function processCompletedReport(string $reportCode): array {
        try {
            ($this->logger['info'])("Starting report processing", [
                'report_code' => $reportCode
            ]);
            
            // Получаем информацию об отчете
            $reportInfo = $this->reportRequestHandler->getReportInfo($reportCode);
            
            if (empty($reportInfo['download_url'])) {
                throw new Exception("Download URL not available for report: $reportCode");
            }
            
            // Скачиваем CSV файл отчета
            $csvContent = $this->reportRequestHandler->downloadReportFile($reportInfo['download_url']);
            
            // Обрабатываем CSV данные
            $stockData = $this->csvProcessor->parseWarehouseStockCSV($csvContent);
            
            ($this->logger['info'])("CSV report parsed successfully", [
                'report_code' => $reportCode,
                'records_count' => count($stockData)
            ]);
            
            // Обновляем данные в базе данных
            $updateResult = $this->inventoryUpdater->updateInventoryFromReport($stockData);
            
            // Обновляем статус отчета
            $this->updateReportStatus($reportCode, 'PROCESSED', [
                'records_processed' => count($stockData),
                'records_updated' => $updateResult['updated_count'] ?? 0,
                'processed_at' => date('Y-m-d H:i:s')
            ]);
            
            $result = [
                'report_code' => $reportCode,
                'records_processed' => count($stockData),
                'records_updated' => $updateResult['updated_count'] ?? 0,
                'update_details' => $updateResult,
                'processed_at' => date('Y-m-d H:i:s')
            ];
            
            ($this->logger['info'])("Report processing completed successfully", $result);
            
            return $result;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Report processing failed", [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
            
            // Обновляем статус отчета с ошибкой
            $this->updateReportStatus($reportCode, 'ERROR', [
                'error_message' => $e->getMessage(),
                'failed_at' => date('Y-m-d H:i:s')
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Генерация уведомлений о критических остатках
     * 
     * @return array результат генерации уведомлений
     */
    public function generateStockAlerts(): array {
        try {
            ($this->logger['info'])("Starting stock alerts generation");
            
            // Анализируем уровни остатков
            $criticalStockData = $this->stockAlertManager->analyzeStockLevels([]);
            
            // Генерируем уведомления
            $alerts = $this->stockAlertManager->generateCriticalStockAlerts();
            
            // Отправляем уведомления
            $notificationResult = $this->stockAlertManager->sendStockAlertNotifications($alerts);
            
            $result = [
                'alerts_count' => count($alerts),
                'critical_items_count' => count($criticalStockData),
                'notifications_sent' => $notificationResult,
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
            ($this->logger['info'])("Stock alerts generation completed", $result);
            
            return $result;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Stock alerts generation failed", [
                'error' => $e->getMessage()
            ]);
            
            return [
                'alerts_count' => 0,
                'error' => $e->getMessage(),
                'generated_at' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * Получение истории отчетов
     * 
     * @param int $days - количество дней для выборки
     * @return array история отчетов
     */
    public function getReportHistory(int $days = 30): array {
        try {
            $sql = "SELECT 
                        report_code,
                        report_type,
                        status,
                        request_parameters,
                        records_processed,
                        error_message,
                        requested_at,
                        completed_at,
                        processed_at
                    FROM ozon_stock_reports 
                    WHERE requested_at >= CURRENT_TIMESTAMP - INTERVAL ':days days'
                    ORDER BY requested_at DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['days' => $days]);
            
            $reports = $stmt->fetchAll();
            
            // Декодируем JSON параметры
            foreach ($reports as &$report) {
                if (!empty($report['request_parameters'])) {
                    $report['request_parameters'] = json_decode($report['request_parameters'], true);
                }
            }
            
            return $reports;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get report history", [
                'error' => $e->getMessage(),
                'days' => $days
            ]);
            
            return [];
        }
    }
    
    /**
     * Подготовка параметров для запроса отчета
     * 
     * @param array $filters - пользовательские фильтры
     * @return array параметры отчета
     */
    private function prepareReportParameters(array $filters): array {
        $parameters = [
            'report_type' => 'warehouse_stock',
            'date_from' => $filters['date_from'] ?? date('Y-m-d', strtotime('-1 day')),
            'date_to' => $filters['date_to'] ?? date('Y-m-d'),
            'include_zero_stock' => $filters['include_zero_stock'] ?? false,
            'warehouse_filter' => $filters['warehouse_filter'] ?? null,
            'product_filter' => $filters['product_filter'] ?? null
        ];
        
        return $parameters;
    }
    
    /**
     * Сохранение запроса отчета в базу данных
     * 
     * @param string $reportCode - код отчета
     * @param array $parameters - параметры запроса
     */
    private function saveReportRequest(string $reportCode, array $parameters): void {
        try {
            $sql = "INSERT INTO ozon_stock_reports 
                    (report_code, report_type, status, request_parameters, requested_at)
                    VALUES (:report_code, :report_type, :status, :request_parameters, CURRENT_TIMESTAMP)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'report_code' => $reportCode,
                'report_type' => 'warehouse_stock',
                'status' => 'REQUESTED',
                'request_parameters' => json_encode($parameters)
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to save report request to database", [
                'report_code' => $reportCode,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Обновление статуса отчета
     * 
     * @param string $reportCode - код отчета
     * @param string $status - новый статус
     * @param array $additionalData - дополнительные данные
     */
    private function updateReportStatus(string $reportCode, string $status, array $additionalData = []): void {
        try {
            $updateFields = ['status = :status'];
            $params = [
                'report_code' => $reportCode,
                'status' => $status
            ];
            
            // Добавляем дополнительные поля для обновления
            if (isset($additionalData['records_processed'])) {
                $updateFields[] = 'records_processed = :records_processed';
                $params['records_processed'] = $additionalData['records_processed'];
            }
            
            if (isset($additionalData['error_message'])) {
                $updateFields[] = 'error_message = :error_message';
                $params['error_message'] = $additionalData['error_message'];
            }
            
            if ($status === 'SUCCESS') {
                $updateFields[] = 'completed_at = CURRENT_TIMESTAMP';
            }
            
            if ($status === 'PROCESSED') {
                $updateFields[] = 'processed_at = CURRENT_TIMESTAMP';
            }
            
            $sql = "UPDATE ozon_stock_reports SET " . implode(', ', $updateFields) . 
                   " WHERE report_code = :report_code";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to update report status", [
                'report_code' => $reportCode,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Сохранение результата ETL процесса
     * 
     * @param array $result - результат ETL
     */
    private function saveETLResult(array $result): void {
        try {
            // Сохраняем основную информацию о ETL процессе
            $sql = "INSERT INTO ozon_etl_history 
                    (etl_id, status, start_time, end_time, duration_seconds, 
                     reports_requested, reports_completed, records_processed, 
                     records_updated, alerts_generated, errors_count, 
                     steps_data, error_message)
                    VALUES 
                    (:etl_id, :status, :start_time, :end_time, :duration_seconds,
                     :reports_requested, :reports_completed, :records_processed,
                     :records_updated, :alerts_generated, :errors_count,
                     :steps_data, :error_message)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'etl_id' => $result['etl_id'],
                'status' => $result['status'],
                'start_time' => $result['start_time'],
                'end_time' => $result['end_time'] ?? null,
                'duration_seconds' => $result['duration_seconds'] ?? null,
                'reports_requested' => $result['statistics']['reports_requested'],
                'reports_completed' => $result['statistics']['reports_completed'],
                'records_processed' => $result['statistics']['records_processed'],
                'records_updated' => $result['statistics']['records_updated'],
                'alerts_generated' => $result['statistics']['alerts_generated'],
                'errors_count' => $result['statistics']['errors_count'],
                'steps_data' => json_encode($result['steps']),
                'error_message' => $result['error'] ?? null
            ]);
            
        } catch (Exception $e) {
            ($this->logger['warning'])("Failed to save ETL result to database", [
                'etl_id' => $result['etl_id'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получение статистики ETL процессов
     * 
     * @param int $days - количество дней для анализа
     * @return array статистика
     */
    public function getETLStatistics(int $days = 7): array {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_runs,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as successful_runs,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_runs,
                        AVG(duration_seconds) as avg_duration,
                        SUM(records_processed) as total_records_processed,
                        SUM(records_updated) as total_records_updated,
                        SUM(alerts_generated) as total_alerts_generated
                    FROM ozon_etl_history 
                    WHERE start_time >= CURRENT_TIMESTAMP - INTERVAL ':days days'";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['days' => $days]);
            
            $stats = $stmt->fetch();
            
            // Рассчитываем дополнительные метрики
            $stats['success_rate'] = $stats['total_runs'] > 0 
                ? round(($stats['successful_runs'] / $stats['total_runs']) * 100, 2) 
                : 0;
            
            $stats['avg_duration'] = round($stats['avg_duration'] ?? 0, 2);
            
            return $stats;
            
        } catch (Exception $e) {
            ($this->logger['error'])("Failed to get ETL statistics", [
                'error' => $e->getMessage(),
                'days' => $days
            ]);
            
            return [
                'total_runs' => 0,
                'successful_runs' => 0,
                'failed_runs' => 0,
                'success_rate' => 0,
                'avg_duration' => 0,
                'total_records_processed' => 0,
                'total_records_updated' => 0,
                'total_alerts_generated' => 0
            ];
        }
    }
}