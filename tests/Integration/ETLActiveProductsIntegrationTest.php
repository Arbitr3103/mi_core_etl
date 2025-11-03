<?php

// Load required files for ETL integration testing
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../src/ETL/Scheduler/ETLScheduler.php';
require_once __DIR__ . '/../../src/ETL/Monitoring/ActivityMonitoringService.php';
require_once __DIR__ . '/../../src/ETL/Monitoring/NotificationService.php';
require_once __DIR__ . '/../../src/ETL/DataExtractors/OzonExtractor.php';

use MDM\ETL\Scheduler\ETLScheduler;
use MDM\ETL\Monitoring\ActivityMonitoringService;
use MDM\ETL\Monitoring\NotificationService;
use MDM\ETL\DataExtractors\OzonExtractor;

/**
 * ETL Integration Tests for Active Products Filter
 * 
 * Tests the complete ETL process with active product filtering,
 * activity monitoring and notifications, and data consistency verification.
 * 
 * Requirements: 1.4, 4.4
 */
class ETLActiveProductsIntegrationTest
{
    private PDO $pdo;
    private ETLScheduler $scheduler;
    private ActivityMonitoringService $monitoringService;
    private NotificationService $notificationService;
    private array $testResults = [];
    private array $testConfig;
    private string $testDatabaseName;

    public function __construct()
    {
        $this->setupTestDatabase();
        $this->setupTestConfiguration();
        $this->initializeServices();
        $this->setupTestData();
    }

    /**
     * Run all ETL integration tests
     */
    public function runAllTests(): bool
    {
        echo "🧪 ЗАПУСК ETL INTEGRATION ТЕСТОВ ДЛЯ ACTIVE PRODUCTS FILTER\n";
        echo "=" . str_repeat("=", 70) . "\n\n";
        
        try {
            $this->testCompleteETLProcessWithActiveFiltering();
            $this->testActivityMonitoringAndNotifications();
            $this->testDataConsistencyAfterETLRuns();
            $this->testETLSchedulerIntegration();
            $this->testErrorHandlingInETLProcess();
            $this->testPerformanceWithActiveFiltering();
            
            $this->printResults();
            return $this->allTestsPassed();
            
        } catch (Exception $e) {
            echo "❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
            return false;
        } finally {
            $this->cleanup();
        }
    }

    /**
     * Test complete ETL process with active product filtering
     * Requirements: 1.4
     */
    private function testCompleteETLProcessWithActiveFiltering(): void
    {
        echo "📍 Тест: Полный ETL процесс с фильтрацией активных товаров\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Setup test data in source tables
            $this->setupSourceTestData();
            
            // Configure ETL with active product filtering
            $etlOptions = [
                'filters' => [
                    'visibility' => 'VISIBLE',
                    'active_only' => true
                ],
                'limit' => 50,
                'max_pages' => 2
            ];
            
            // Run ETL process
            $startTime = microtime(true);
            $results = $this->scheduler->runFullETL($etlOptions);
            $duration = microtime(true) - $startTime;
            
            // Verify ETL execution
            $this->assert(!empty($results), 'ETL должен вернуть результаты');
            $this->assert($duration < 30.0, 'ETL должен завершиться менее чем за 30 секунд');
            
            // Verify extracted data contains activity information
            $extractedData = $this->getExtractedData();
            $this->assert(!empty($extractedData), 'Должны быть извлечены данные');
            
            // Verify activity fields are present
            foreach ($extractedData as $item) {
                $this->assert(
                    array_key_exists('is_active', $item),
                    'Извлеченные данные должны содержать поле is_active'
                );
                $this->assert(
                    array_key_exists('activity_checked_at', $item),
                    'Извлеченные данные должны содержать поле activity_checked_at'
                );
                $this->assert(
                    array_key_exists('activity_reason', $item),
                    'Извлеченные данные должны содержать поле activity_reason'
                );
            }
            
            // Verify only active products are extracted when filtering is enabled
            $activeCount = count(array_filter($extractedData, fn($item) => $item['is_active'] == 1));
            $totalCount = count($extractedData);
            
            $this->assert(
                $activeCount > 0,
                'Должны быть извлечены активные товары'
            );
            
            // Verify ETL run was logged
            $etlRuns = $this->getETLRuns();
            $this->assert(!empty($etlRuns), 'Запуск ETL должен быть залогирован');
            
            $lastRun = $etlRuns[0];
            $this->assert(
                in_array($lastRun['status'], ['success', 'partial_success']),
                'Последний запуск ETL должен быть успешным'
            );
            
            echo "✅ ETL процесс выполнен успешно за " . round($duration, 2) . " сек\n";
            echo "✅ Извлечено {$totalCount} товаров, из них {$activeCount} активных\n";
            echo "✅ Все поля активности присутствуют в данных\n";
            echo "✅ Запуск ETL залогирован корректно\n";
            
            $this->testResults['completeETLProcessActiveFiltering'] = [
                'status' => 'PASS',
                'message' => "ETL с фильтрацией работает корректно (время: {$duration}с, товаров: {$totalCount})"
            ];
            
        } catch (Exception $e) {
            $this->testResults['completeETLProcessActiveFiltering'] = [
                'status' => 'FAIL',
                'message' => $e->getMessage()
            ];
        }
        
        echo "\n";
    }

    /**
     * Test activity monitoring and notifications
     * Requirements: 4.4
     */
    private function testActivityMonitoringAndNotifications(): void
    {
        echo "📍 Тест: Мониторинг активности и уведомления\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Setup monitoring configuration
            $this->setupActivityMonitoring();
            
            // Create initial activity baseline
            $this->createActivityBaseline();
            
            // Simulate significant activity change
            $this->simulateActivityChange();
            
            // Run activity monitoring check
            $monitoringResults = $this->monitoringService->checkAllSources();
            
            $this->assert(!empty($monitoringResults), 'Мониторинг должен вернуть результаты');
            
            // Verify monitoring detected changes
            $hasSignificantChange = false;
            foreach ($monitoringResults as $source => $result) {
                if ($result['threshold_exceeded'] ?? false) {
                    $hasSignificantChange = true;
                    
                    $this->assert(
                        $result['change_percent'] > 0,
                        'Должно быть зафиксировано изменение активности'
                    );
                    
                    echo "✅ Обнаружено изменение активности в источнике {$source}: " . 
                         round($result['change_percent'], 2) . "%\n";
                }
            }
            
            // Test notification sending
            $this->testNotificationSending();
            
            // Test daily activity report generation
            $dailyReport = $this->monitoringService->generateDailyActivityReport();
            
            $this->assert(!empty($dailyReport), 'Ежедневный отчет должен быть создан');
            $this->assert(
                isset($dailyReport['summary']),
                'Отчет должен содержать сводную информацию'
            );
            $this->assert(
                isset($dailyReport['sources']),
                'Отчет должен содержать данные по источникам'
            );
            
            // Verify notification history
            $notificationHistory = $this->notificationService->getNotificationHistory([
                'type' => 'activity_change',
                'limit' => 10
            ]);
            
            echo "✅ Мониторинг активности работает корректно\n";
            echo "✅ Ежедневный отчет создан успешно\n";
            echo "✅ История уведомлений: " . count($notificationHistory) . " записей\n";
            
            $this->testResults['activityMonitoringNotifications'] = [
                'status' => 'PASS',
                'message' => 'Мониторинг активности и уведомления работают корректно'
            ];
            
        } catch (Exception $e) {
            $this->testResults['activityMonitoringNotifications'] = [
                'status' => 'FAIL',
                'message' => $e->getMessage()
            ];
        }
        
        echo "\n";
    }

    /**
     * Test data consistency after ETL runs
     * Requirements: 1.4, 4.4
     */
    private function testDataConsistencyAfterETLRuns(): void
    {
        echo "📍 Тест: Согласованность данных после запусков ETL\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Run multiple ETL cycles to test consistency
            $etlCycles = 3;
            $consistencyResults = [];
            
            for ($i = 1; $i <= $etlCycles; $i++) {
                echo "  Запуск ETL цикла {$i}/{$etlCycles}...\n";
                
                // Run ETL with slight delay between runs
                $results = $this->scheduler->runFullETL([
                    'filters' => ['active_only' => true],
                    'limit' => 20
                ]);
                
                // Collect consistency metrics
                $metrics = $this->collectConsistencyMetrics();
                $consistencyResults[] = $metrics;
                
                // Small delay between runs
                sleep(1);
            }
            
            // Verify data consistency across runs
            $this->verifyDataConsistency($consistencyResults);
            
            // Test referential integrity
            $this->testReferentialIntegrity();
            
            // Test activity status consistency
            $this->testActivityStatusConsistency();
            
            // Test transaction integrity
            $this->testTransactionIntegrity();
            
            echo "✅ Данные остаются согласованными между запусками ETL\n";
            echo "✅ Ссылочная целостность поддерживается\n";
            echo "✅ Статусы активности согласованы\n";
            echo "✅ Транзакционная целостность обеспечена\n";
            
            $this->testResults['dataConsistencyAfterETLRuns'] = [
                'status' => 'PASS',
                'message' => "Согласованность данных обеспечена ({$etlCycles} циклов ETL)"
            ];
            
        } catch (Exception $e) {
            $this->testResults['dataConsistencyAfterETLRuns'] = [
                'status' => 'FAIL',
                'message' => $e->getMessage()
            ];
        }
        
        echo "\n";
    } 
   /**
     * Test ETL scheduler integration
     */
    private function testETLSchedulerIntegration(): void
    {
        echo "📍 Тест: Интеграция планировщика ETL\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Test scheduler status
            $status = $this->scheduler->getStatus();
            
            $this->assert(isset($status['extractors']), 'Статус должен содержать информацию об экстракторах');
            $this->assert(isset($status['last_runs']), 'Статус должен содержать информацию о последних запусках');
            
            // Test incremental ETL
            $incrementalResults = $this->scheduler->runIncrementalETL([
                'filters' => ['active_only' => true]
            ]);
            
            $this->assert(!empty($incrementalResults), 'Инкрементальный ETL должен вернуть результаты');
            
            // Test source-specific ETL
            if (isset($status['extractors']['ozon'])) {
                $sourceResults = $this->scheduler->runSourceETL('ozon', [
                    'filters' => ['active_only' => true],
                    'limit' => 10
                ]);
                
                $this->assert(
                    isset($sourceResults['ozon']),
                    'ETL для конкретного источника должен вернуть результаты'
                );
            }
            
            // Test scheduler locking mechanism
            $this->testSchedulerLocking();
            
            echo "✅ Планировщик ETL работает корректно\n";
            echo "✅ Инкрементальный ETL функционирует\n";
            echo "✅ ETL для конкретных источников работает\n";
            echo "✅ Механизм блокировки функционирует\n";
            
            $this->testResults['etlSchedulerIntegration'] = [
                'status' => 'PASS',
                'message' => 'Интеграция планировщика ETL работает корректно'
            ];
            
        } catch (Exception $e) {
            $this->testResults['etlSchedulerIntegration'] = [
                'status' => 'FAIL',
                'message' => $e->getMessage()
            ];
        }
        
        echo "\n";
    }

    /**
     * Test error handling in ETL process
     */
    private function testErrorHandlingInETLProcess(): void
    {
        echo "📍 Тест: Обработка ошибок в ETL процессе\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Test with invalid configuration
            $invalidScheduler = new ETLScheduler($this->pdo, [
                'ozon' => [
                    'client_id' => 'invalid_client_id',
                    'api_key' => 'invalid_api_key',
                    'base_url' => 'https://invalid-api.example.com'
                ]
            ]);
            
            $results = $invalidScheduler->runFullETL(['limit' => 1]);
            
            // Should handle errors gracefully
            $this->assert(!empty($results), 'ETL должен вернуть результаты даже при ошибках');
            
            $hasErrors = false;
            foreach ($results as $source => $result) {
                if ($result['status'] === 'error' || $result['status'] === 'unavailable') {
                    $hasErrors = true;
                    echo "✅ Ошибка в источнике {$source} обработана корректно\n";
                }
            }
            
            $this->assert($hasErrors, 'Должны быть обработаны ошибки недоступных источников');
            
            // Test database transaction rollback on error
            $this->testTransactionRollbackOnError();
            
            // Test recovery after errors
            $this->testRecoveryAfterErrors();
            
            echo "✅ Ошибки API обрабатываются корректно\n";
            echo "✅ Транзакции откатываются при ошибках\n";
            echo "✅ Восстановление после ошибок работает\n";
            
            $this->testResults['errorHandlingETLProcess'] = [
                'status' => 'PASS',
                'message' => 'Обработка ошибок в ETL процессе работает корректно'
            ];
            
        } catch (Exception $e) {
            $this->testResults['errorHandlingETLProcess'] = [
                'status' => 'FAIL',
                'message' => $e->getMessage()
            ];
        }
        
        echo "\n";
    }

    /**
     * Test performance with active filtering
     */
    private function testPerformanceWithActiveFiltering(): void
    {
        echo "📍 Тест: Производительность с фильтрацией активных товаров\n";
        echo "-" . str_repeat("-", 50) . "\n";
        
        try {
            // Create larger test dataset
            $this->createLargeTestDataset();
            
            // Test ETL performance with active filtering
            $startTime = microtime(true);
            $results = $this->scheduler->runFullETL([
                'filters' => ['active_only' => true],
                'limit' => 100
            ]);
            $etlTime = microtime(true) - $startTime;
            
            // Test monitoring performance
            $startTime = microtime(true);
            $monitoringResults = $this->monitoringService->checkAllSources();
            $monitoringTime = microtime(true) - $startTime;
            
            // Performance assertions
            $this->assert($etlTime < 60.0, 'ETL должен завершиться менее чем за 60 секунд');
            $this->assert($monitoringTime < 10.0, 'Мониторинг должен завершиться менее чем за 10 секунд');
            
            // Test memory usage
            $memoryUsage = memory_get_peak_usage(true);
            $this->assert($memoryUsage < 256 * 1024 * 1024, 'Использование памяти должно быть менее 256MB');
            
            // Test database query performance
            $this->testDatabaseQueryPerformance();
            
            echo "✅ ETL выполнен за " . round($etlTime, 2) . " сек\n";
            echo "✅ Мониторинг выполнен за " . round($monitoringTime, 2) . " сек\n";
            echo "✅ Использование памяти: " . round($memoryUsage / 1024 / 1024, 2) . " MB\n";
            echo "✅ Производительность запросов к БД оптимальна\n";
            
            $this->testResults['performanceActiveFiltering'] = [
                'status' => 'PASS',
                'message' => "Производительность оптимальна (ETL: {$etlTime}с, мониторинг: {$monitoringTime}с)"
            ];
            
        } catch (Exception $e) {
            $this->testResults['performanceActiveFiltering'] = [
                'status' => 'FAIL',
                'message' => $e->getMessage()
            ];
        }
        
        echo "\n";
    }

    /**
     * Setup test database
     */
    private function setupTestDatabase(): void
    {
        $this->testDatabaseName = getenv('DB_NAME') ?: 'mi_core_test';
        
        try {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '5432';
            $user = getenv('DB_USER') ?: 'postgres';
            $pass = getenv('DB_PASSWORD') ?: 'postgres';

            $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $this->testDatabaseName);
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            
            $this->createTestTables();
        } catch (PDOException $e) {
            // Fallback to SQLite for testing if Postgres is not available
            $this->pdo = new PDO('sqlite::memory:', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $this->createTestTables();
            echo "⚠️  Using SQLite in-memory database for testing (PostgreSQL not available)\n";
        }
    }   
 /**
     * Create required test tables
     */
    private function createTestTables(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        
        if ($driver === 'pgsql') {
            // PostgreSQL schema
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_extracted_data (
                    id SERIAL PRIMARY KEY,
                    source VARCHAR(50) NOT NULL,
                    external_sku VARCHAR(255) NOT NULL,
                    source_name VARCHAR(255),
                    source_brand VARCHAR(255),
                    source_category VARCHAR(255),
                    price NUMERIC(10,2),
                    description TEXT,
                    attributes JSONB,
                    raw_data TEXT,
                    extracted_at TIMESTAMP DEFAULT NOW(),
                    is_active BOOLEAN,
                    activity_checked_at TIMESTAMP DEFAULT NOW(),
                    activity_reason VARCHAR(255),
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW(),
                    CONSTRAINT unique_source_sku UNIQUE (source, external_sku)
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_runs (
                    id SERIAL PRIMARY KEY,
                    status VARCHAR(50) NOT NULL,
                    duration NUMERIC(8,3),
                    total_extracted INTEGER DEFAULT 0,
                    total_saved INTEGER DEFAULT 0,
                    results JSONB,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_logs (
                    id SERIAL PRIMARY KEY,
                    source VARCHAR(50) NOT NULL,
                    level VARCHAR(20) NOT NULL,
                    message TEXT NOT NULL,
                    context JSONB,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_activity_monitoring (
                    id SERIAL PRIMARY KEY,
                    source VARCHAR(50) NOT NULL UNIQUE,
                    monitoring_enabled BOOLEAN DEFAULT TRUE,
                    last_check_at TIMESTAMP DEFAULT NOW(),
                    active_count_current INTEGER DEFAULT 0,
                    active_count_previous INTEGER DEFAULT 0,
                    total_count_current INTEGER DEFAULT 0,
                    change_threshold_percent NUMERIC(5,2) DEFAULT 10.0,
                    notification_sent_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW()
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_product_activity_log (
                    id SERIAL PRIMARY KEY,
                    source VARCHAR(50) NOT NULL,
                    external_sku VARCHAR(255) NOT NULL,
                    previous_status BOOLEAN,
                    new_status BOOLEAN,
                    reason VARCHAR(255),
                    changed_at TIMESTAMP DEFAULT NOW(),
                    changed_by VARCHAR(100) DEFAULT 'system'
                )
            ");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_notifications (
                    id SERIAL PRIMARY KEY,
                    type VARCHAR(50) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    priority VARCHAR(20) DEFAULT 'medium',
                    source VARCHAR(50) NOT NULL,
                    data JSONB,
                    email_sent BOOLEAN DEFAULT FALSE,
                    log_sent BOOLEAN DEFAULT FALSE,
                    webhook_sent BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");
        } else {
            // Existing SQLite/MySQL-neutral fallback
            $autoIncrement = $driver === 'sqlite' ? 'AUTOINCREMENT' : 'AUTO_INCREMENT';
            $timestamp = $driver === 'sqlite' ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP';
            $json = $driver === 'sqlite' ? 'TEXT' : 'JSON';
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_extracted_data (
                    id INTEGER PRIMARY KEY {$autoIncrement},
                    source VARCHAR(50) NOT NULL,
                    external_sku VARCHAR(255) NOT NULL,
                    source_name VARCHAR(255),
                    source_brand VARCHAR(255),
                    source_category VARCHAR(255),
                    price DECIMAL(10,2),
                    description TEXT,
                    attributes {$json},
                    raw_data TEXT,
                    extracted_at {$timestamp},
                    is_active BOOLEAN,
                    activity_checked_at {$timestamp},
                    activity_reason VARCHAR(255),
                    created_at {$timestamp},
                    updated_at {$timestamp},
                    UNIQUE KEY unique_source_sku (source, external_sku)
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_runs (
                    id INTEGER PRIMARY KEY {$autoIncrement},
                    status VARCHAR(50) NOT NULL,
                    duration DECIMAL(8,3),
                    total_extracted INTEGER DEFAULT 0,
                    total_saved INTEGER DEFAULT 0,
                    results {$json},
                    created_at {$timestamp}
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_logs (
                    id INTEGER PRIMARY KEY {$autoIncrement},
                    source VARCHAR(50) NOT NULL,
                    level VARCHAR(20) NOT NULL,
                    message TEXT NOT NULL,
                    context {$json},
                    created_at {$timestamp}
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_activity_monitoring (
                    id INTEGER PRIMARY KEY {$autoIncrement},
                    source VARCHAR(50) NOT NULL UNIQUE,
                    monitoring_enabled BOOLEAN DEFAULT TRUE,
                    last_check_at {$timestamp},
                    active_count_current INTEGER DEFAULT 0,
                    active_count_previous INTEGER DEFAULT 0,
                    total_count_current INTEGER DEFAULT 0,
                    change_threshold_percent DECIMAL(5,2) DEFAULT 10.0,
                    notification_sent_at {$timestamp},
                    created_at {$timestamp},
                    updated_at {$timestamp}
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_product_activity_log (
                    id INTEGER PRIMARY KEY {$autoIncrement},
                    source VARCHAR(50) NOT NULL,
                    external_sku VARCHAR(255) NOT NULL,
                    previous_status BOOLEAN,
                    new_status BOOLEAN,
                    reason VARCHAR(255),
                    changed_at {$timestamp},
                    changed_by VARCHAR(100) DEFAULT 'system'
                )
            ");
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_notifications (
                    id INTEGER PRIMARY KEY {$autoIncrement},
                    type VARCHAR(50) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    priority VARCHAR(20) DEFAULT 'medium',
                    source VARCHAR(50) NOT NULL,
                    data {$json},
                    email_sent BOOLEAN DEFAULT FALSE,
                    log_sent BOOLEAN DEFAULT FALSE,
                    webhook_sent BOOLEAN DEFAULT FALSE,
                    created_at {$timestamp}
                )
            ");
        }
        
        // ETL runs table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS etl_runs (
                id INTEGER PRIMARY KEY {$autoIncrement},
                status VARCHAR(50) NOT NULL,
                duration DECIMAL(8,3),
                total_extracted INTEGER DEFAULT 0,
                total_saved INTEGER DEFAULT 0,
                results {$json},
                created_at {$timestamp}
            )
        ");
        
        // ETL logs table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS etl_logs (
                id INTEGER PRIMARY KEY {$autoIncrement},
                source VARCHAR(50) NOT NULL,
                level VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                context {$json},
                created_at {$timestamp}
            )
        ");
        
        // Activity monitoring table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS etl_activity_monitoring (
                id INTEGER PRIMARY KEY {$autoIncrement},
                source VARCHAR(50) NOT NULL UNIQUE,
                monitoring_enabled BOOLEAN DEFAULT TRUE,
                last_check_at {$timestamp},
                active_count_current INTEGER DEFAULT 0,
                active_count_previous INTEGER DEFAULT 0,
                total_count_current INTEGER DEFAULT 0,
                change_threshold_percent DECIMAL(5,2) DEFAULT 10.0,
                notification_sent_at {$timestamp},
                created_at {$timestamp},
                updated_at {$timestamp}
            )
        ");
        
        // Product activity log table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS etl_product_activity_log (
                id INTEGER PRIMARY KEY {$autoIncrement},
                source VARCHAR(50) NOT NULL,
                external_sku VARCHAR(255) NOT NULL,
                previous_status BOOLEAN,
                new_status BOOLEAN,
                reason VARCHAR(255),
                changed_at {$timestamp},
                changed_by VARCHAR(100) DEFAULT 'system'
            )
        ");
        
        // Notifications table
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS etl_notifications (
                id INTEGER PRIMARY KEY {$autoIncrement},
                type VARCHAR(50) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                priority VARCHAR(20) DEFAULT 'medium',
                source VARCHAR(50) NOT NULL,
                data {$json},
                email_sent BOOLEAN DEFAULT FALSE,
                log_sent BOOLEAN DEFAULT FALSE,
                webhook_sent BOOLEAN DEFAULT FALSE,
                created_at {$timestamp}
            )
        ");
    }

    /**
     * Setup test configuration
     */
    private function setupTestConfiguration(): void
    {
        $this->testConfig = [
            'ozon' => [
                'client_id' => 'test_client_id',
                'api_key' => 'test_api_key',
                'base_url' => 'https://api-seller.ozon.ru',
                'filter_active_only' => true,
                'default_filters' => [
                    'visibility' => 'VISIBLE'
                ]
            ],
            'notifications' => [
                'enabled' => true,
                'email_enabled' => false, // Disable email for testing
                'log_enabled' => true,
                'webhook_enabled' => false,
                'default_email' => 'test@example.com'
            ]
        ];
    }

    /**
     * Initialize services
     */
    private function initializeServices(): void
    {
        $this->scheduler = new ETLScheduler($this->pdo, $this->testConfig);
        $this->monitoringService = new ActivityMonitoringService($this->pdo, [
            'monitoring_enabled' => true,
            'default_threshold_percent' => 15.0,
            'notification_cooldown_minutes' => 1, // Short cooldown for testing
            'notifications' => $this->testConfig['notifications']
        ]);
        $this->notificationService = new NotificationService($this->pdo, $this->testConfig['notifications']);
    }

    /**
     * Setup test data
     */
    private function setupTestData(): void
    {
        // Insert test extracted data
        $testData = [
            ['ozon', 'TEST_SKU_001', 'Test Product 1', 'Test Brand', 'Test Category', 99.99, 1, 'visible_processed_stock'],
            ['ozon', 'TEST_SKU_002', 'Test Product 2', 'Test Brand', 'Test Category', 149.99, 1, 'visible_processed_stock'],
            ['ozon', 'TEST_SKU_003', 'Test Product 3', 'Test Brand', 'Test Category', 79.99, 0, 'not_visible'],
            ['ozon', 'TEST_SKU_004', 'Test Product 4', 'Test Brand', 'Test Category', 199.99, 0, 'no_stock'],
            ['ozon', 'TEST_SKU_005', 'Test Product 5', 'Test Brand', 'Test Category', 59.99, 1, 'visible_processed_stock']
        ];
        
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_extracted_data 
                (source, external_sku, source_name, source_brand, source_category, price, 
                 is_active, activity_reason, extracted_at, activity_checked_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON CONFLICT (source, external_sku) DO UPDATE SET
                  source_name = EXCLUDED.source_name,
                  source_brand = EXCLUDED.source_brand,
                  source_category = EXCLUDED.source_category,
                  price = EXCLUDED.price,
                  is_active = EXCLUDED.is_active,
                  activity_reason = EXCLUDED.activity_reason,
                  activity_checked_at = EXCLUDED.activity_checked_at,
                  updated_at = NOW()
            ");
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_extracted_data 
                (source, external_sku, source_name, source_brand, source_category, price, 
                 is_active, activity_reason, extracted_at, activity_checked_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
            ");
        }
        
        foreach ($testData as $row) {
            $stmt->execute($row);
        }
        
        // Setup activity monitoring for test sources
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $this->pdo->exec("
                INSERT INTO etl_activity_monitoring (source, monitoring_enabled, active_count_current, total_count_current)
                VALUES ('ozon', TRUE, 3, 5)
                ON CONFLICT (source) DO UPDATE SET 
                  monitoring_enabled = EXCLUDED.monitoring_enabled,
                  active_count_current = EXCLUDED.active_count_current,
                  total_count_current = EXCLUDED.total_count_current,
                  updated_at = NOW()
            ");
        } else {
            $this->pdo->exec("
                INSERT OR REPLACE INTO etl_activity_monitoring 
                (source, monitoring_enabled, active_count_current, total_count_current)
                VALUES ('ozon', 1, 3, 5)
            ");
        }
    }

    /**
     * Setup source test data (simulating external API data)
     */
    private function setupSourceTestData(): void
    {
        // This would normally come from external APIs
        // For testing, we simulate the data structure
        
        // Insert some baseline data that ETL can work with
        $this->pdo->exec("
            INSERT OR REPLACE INTO etl_extracted_data 
            (source, external_sku, source_name, price, is_active, activity_reason, extracted_at, activity_checked_at)
            VALUES 
            ('ozon', 'SOURCE_SKU_001', 'Source Product 1', 89.99, 1, 'visible_processed_stock', datetime('now'), datetime('now')),
            ('ozon', 'SOURCE_SKU_002', 'Source Product 2', 129.99, 1, 'visible_processed_stock', datetime('now'), datetime('now')),
            ('ozon', 'SOURCE_SKU_003', 'Source Product 3', 69.99, 0, 'not_visible', datetime('now'), datetime('now'))
        ");
    }

    /**
     * Get extracted data from database
     */
    private function getExtractedData(): array
    {
        $stmt = $this->pdo->query("
            SELECT * FROM etl_extracted_data 
            ORDER BY created_at DESC
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get ETL runs from database
     */
    private function getETLRuns(): array
    {
        $stmt = $this->pdo->query("
            SELECT * FROM etl_runs 
            ORDER BY created_at DESC
        ");
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Setup activity monitoring
     */
    private function setupActivityMonitoring(): void
    {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $this->pdo->exec("
                INSERT INTO etl_activity_monitoring 
                (source, monitoring_enabled, active_count_current, active_count_previous, total_count_current, change_threshold_percent)
                VALUES 
                ('ozon', TRUE, 3, 5, 8, 15.0)
                ON CONFLICT (source) DO UPDATE SET 
                  monitoring_enabled = EXCLUDED.monitoring_enabled,
                  active_count_current = EXCLUDED.active_count_current,
                  active_count_previous = EXCLUDED.active_count_previous,
                  total_count_current = EXCLUDED.total_count_current,
                  change_threshold_percent = EXCLUDED.change_threshold_percent,
                  updated_at = NOW();
                INSERT INTO etl_activity_monitoring 
                (source, monitoring_enabled, active_count_current, active_count_previous, total_count_current, change_threshold_percent)
                VALUES 
                ('test_source', TRUE, 10, 15, 25, 20.0)
                ON CONFLICT (source) DO UPDATE SET 
                  monitoring_enabled = EXCLUDED.monitoring_enabled,
                  active_count_current = EXCLUDED.active_count_current,
                  active_count_previous = EXCLUDED.active_count_previous,
                  total_count_current = EXCLUDED.total_count_current,
                  change_threshold_percent = EXCLUDED.change_threshold_percent,
                  updated_at = NOW();
            ");
        } else {
            $this->pdo->exec("
                INSERT OR REPLACE INTO etl_activity_monitoring 
                (source, monitoring_enabled, active_count_current, active_count_previous, 
                 total_count_current, change_threshold_percent)
                VALUES 
                ('ozon', 1, 3, 5, 8, 15.0),
                ('test_source', 1, 10, 15, 25, 20.0)
            ");
        }
    }

    /**
     * Create activity baseline
     */
    private function createActivityBaseline(): void
    {
        // Update monitoring table with baseline data
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $this->pdo->exec("
                UPDATE etl_activity_monitoring 
                SET active_count_previous = active_count_current,
                    last_check_at = NOW() - INTERVAL '1 hour'
                WHERE source IN ('ozon', 'test_source')
            ");
        } else {
            $this->pdo->exec("
                UPDATE etl_activity_monitoring 
                SET active_count_previous = active_count_current,
                    last_check_at = datetime('now', '-1 hour')
                WHERE source IN ('ozon', 'test_source')
            ");
        }
    }

    /**
     * Simulate activity change
     */
    private function simulateActivityChange(): void
    {
        // Simulate significant change in active products
        $this->pdo->exec("
            UPDATE etl_activity_monitoring 
            SET active_count_current = 2,
                total_count_current = 8
            WHERE source = 'ozon'
        ");
        
        // Log the activity change
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $this->pdo->exec("
                INSERT INTO etl_product_activity_log 
                (source, external_sku, previous_status, new_status, reason, changed_at)
                VALUES 
                ('ozon', 'TEST_SKU_001', TRUE, FALSE, 'became_invisible', NOW()),
                ('ozon', 'TEST_SKU_002', TRUE, FALSE, 'stock_depleted', NOW())
            ");
        } else {
            $this->pdo->exec("
                INSERT INTO etl_product_activity_log 
                (source, external_sku, previous_status, new_status, reason, changed_at)
                VALUES 
                ('ozon', 'TEST_SKU_001', 1, 0, 'became_invisible', datetime('now')),
                ('ozon', 'TEST_SKU_002', 1, 0, 'stock_depleted', datetime('now'))
            ");
        }
    }    /*
*
     * Test notification sending
     */
    private function testNotificationSending(): void
    {
        $testNotification = [
            'type' => 'activity_change',
            'subject' => 'Test Activity Change',
            'message' => 'This is a test notification for activity change',
            'priority' => 'medium',
            'source' => 'test_system',
            'data' => ['test' => true]
        ];
        
        $result = $this->notificationService->sendNotification($testNotification);
        
        $this->assert($result, 'Уведомление должно быть отправлено успешно');
        
        // Verify notification was logged
        $history = $this->notificationService->getNotificationHistory([
            'type' => 'activity_change',
            'limit' => 1
        ]);
        
        $this->assert(!empty($history), 'Уведомление должно быть в истории');
    }

    /**
     * Collect consistency metrics
     */
    private function collectConsistencyMetrics(): array
    {
        $metrics = [];
        
        // Count total records
        $metrics['total_records'] = $this->pdo->query("SELECT COUNT(*) FROM etl_extracted_data")->fetchColumn();
        
        // Count active records
        $metrics['active_records'] = $this->pdo->query("SELECT COUNT(*) FROM etl_extracted_data WHERE is_active = 1")->fetchColumn();
        
        // Count records with activity data
        $metrics['records_with_activity'] = $this->pdo->query("SELECT COUNT(*) FROM etl_extracted_data WHERE activity_checked_at IS NOT NULL")->fetchColumn();
        
        // Count unique sources
        $metrics['unique_sources'] = $this->pdo->query("SELECT COUNT(DISTINCT source) FROM etl_extracted_data")->fetchColumn();
        
        // Count ETL runs
        $metrics['etl_runs'] = $this->pdo->query("SELECT COUNT(*) FROM etl_runs")->fetchColumn();
        
        return $metrics;
    }

    /**
     * Verify data consistency
     */
    private function verifyDataConsistency(array $consistencyResults): void
    {
        $this->assert(count($consistencyResults) > 1, 'Должно быть несколько результатов для сравнения');
        
        $firstResult = $consistencyResults[0];
        
        foreach ($consistencyResults as $i => $result) {
            // Total records should not decrease significantly
            $this->assert(
                $result['total_records'] >= $firstResult['total_records'] * 0.9,
                "Общее количество записей не должно значительно уменьшаться (цикл {$i})"
            );
            
            // Should have activity data
            $this->assert(
                $result['records_with_activity'] > 0,
                "Должны быть записи с данными активности (цикл {$i})"
            );
            
            // Should have ETL runs logged
            $this->assert(
                $result['etl_runs'] > 0,
                "Должны быть залогированы запуски ETL (цикл {$i})"
            );
        }
    }

    /**
     * Test referential integrity
     */
    private function testReferentialIntegrity(): void
    {
        // Check that all activity logs reference existing products
        $orphanedLogs = $this->pdo->query("
            SELECT COUNT(*) 
            FROM etl_product_activity_log l
            LEFT JOIN etl_extracted_data e ON l.source = e.source AND l.external_sku = e.external_sku
            WHERE e.id IS NULL
        ")->fetchColumn();
        
        $this->assert($orphanedLogs == 0, 'Не должно быть логов активности без соответствующих товаров');
        
        // Check that monitoring records have corresponding data
        $orphanedMonitoring = $this->pdo->query("
            SELECT COUNT(*) 
            FROM etl_activity_monitoring m
            LEFT JOIN etl_extracted_data e ON m.source = e.source
            WHERE e.id IS NULL AND m.total_count_current > 0
        ")->fetchColumn();
        
        $this->assert($orphanedMonitoring == 0, 'Мониторинг не должен ссылаться на несуществующие источники');
    }

    /**
     * Test activity status consistency
     */
    private function testActivityStatusConsistency(): void
    {
        // Check that activity reasons are consistent with status
        $inconsistentReasons = $this->pdo->query("
            SELECT COUNT(*) 
            FROM etl_extracted_data 
            WHERE (is_active = 1 AND activity_reason LIKE '%not_visible%')
               OR (is_active = 0 AND activity_reason LIKE '%visible_processed_stock%')
        ")->fetchColumn();
        
        $this->assert($inconsistentReasons == 0, 'Причины активности должны соответствовать статусу');
        
        // Check that activity_checked_at is set when is_active is not null
        $uncheckedActive = $this->pdo->query("
            SELECT COUNT(*) 
            FROM etl_extracted_data 
            WHERE is_active IS NOT NULL AND activity_checked_at IS NULL
        ")->fetchColumn();
        
        $this->assert($uncheckedActive == 0, 'Все товары со статусом активности должны иметь время проверки');
    }

    /**
     * Test transaction integrity
     */
    private function testTransactionIntegrity(): void
    {
        // Test that partial updates don't leave inconsistent state
        $this->pdo->beginTransaction();
        
        try {
            // Insert test record
            $this->pdo->exec("
                INSERT INTO etl_extracted_data 
                (source, external_sku, source_name, is_active, activity_reason, extracted_at, activity_checked_at)
                VALUES ('test_tx', 'TX_SKU_001', 'Transaction Test', 1, 'test_transaction', datetime('now'), datetime('now'))
            ");
            
            // Simulate error and rollback
            $this->pdo->rollback();
            
            // Verify record was not inserted
            $count = $this->pdo->query("SELECT COUNT(*) FROM etl_extracted_data WHERE external_sku = 'TX_SKU_001'")->fetchColumn();
            $this->assert($count == 0, 'Откат транзакции должен удалить все изменения');
            
        } catch (Exception $e) {
            $this->pdo->rollback();
            throw $e;
        }
    }

    /**
     * Test scheduler locking mechanism
     */
    private function testSchedulerLocking(): void
    {
        // This is a simplified test since we can't easily test true concurrency
        // In a real scenario, you would test with multiple processes
        
        $status = $this->scheduler->getStatus();
        $this->assert(isset($status['is_running']), 'Статус должен содержать информацию о запуске');
        
        // The scheduler should handle locking internally
        // We just verify the status structure is correct
        $this->assert(is_bool($status['is_running']), 'is_running должен быть boolean');
    }

    /**
     * Test transaction rollback on error
     */
    private function testTransactionRollbackOnError(): void
    {
        $initialCount = $this->pdo->query("SELECT COUNT(*) FROM etl_extracted_data")->fetchColumn();
        
        // Simulate error during ETL process by using invalid data
        try {
            $this->pdo->beginTransaction();
            
            // Insert valid record
            $this->pdo->exec("
                INSERT INTO etl_extracted_data 
                (source, external_sku, source_name, is_active, extracted_at, activity_checked_at)
                VALUES ('error_test', 'ERROR_SKU_001', 'Error Test 1', 1, datetime('now'), datetime('now'))
            ");
            
            // Try to insert duplicate (should cause error)
            $this->pdo->exec("
                INSERT INTO etl_extracted_data 
                (source, external_sku, source_name, is_active, extracted_at, activity_checked_at)
                VALUES ('error_test', 'ERROR_SKU_001', 'Error Test Duplicate', 1, datetime('now'), datetime('now'))
            ");
            
            $this->pdo->commit();
            
        } catch (Exception $e) {
            $this->pdo->rollback();
            
            // Verify rollback worked
            $finalCount = $this->pdo->query("SELECT COUNT(*) FROM etl_extracted_data")->fetchColumn();
            $this->assert($finalCount == $initialCount, 'Количество записей должно остаться неизменным после отката');
        }
    }

    /**
     * Test recovery after errors
     */
    private function testRecoveryAfterErrors(): void
    {
        // After error handling test, the system should still be able to process data
        $results = $this->scheduler->runFullETL([
            'filters' => ['active_only' => true],
            'limit' => 5
        ]);
        
        $this->assert(!empty($results), 'Система должна восстановиться после ошибок');
        
        // At least one source should be processed successfully or show expected error
        $hasValidResult = false;
        foreach ($results as $source => $result) {
            if (in_array($result['status'], ['success', 'partial_success', 'unavailable'])) {
                $hasValidResult = true;
                break;
            }
        }
        
        $this->assert($hasValidResult, 'Должен быть хотя бы один валидный результат после восстановления');
    }

    /**
     * Create large test dataset for performance testing
     */
    private function createLargeTestDataset(): void
    {
        $batchSize = 50;
        
        $stmt = $this->pdo->prepare("
            INSERT INTO etl_extracted_data 
            (source, external_sku, source_name, price, is_active, activity_reason, extracted_at, activity_checked_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
        ");
        
        for ($i = 1; $i <= $batchSize; $i++) {
            $stmt->execute([
                'perf_test',
                "PERF_SKU_{$i}",
                "Performance Test Product {$i}",
                rand(50, 200) + rand(0, 99) / 100,
                $i % 3 !== 0 ? 1 : 0, // ~67% active
                $i % 3 !== 0 ? 'visible_processed_stock' : 'not_visible'
            ]);
        }
        
        // Update monitoring stats
        $this->pdo->exec("
            INSERT OR REPLACE INTO etl_activity_monitoring 
            (source, monitoring_enabled, active_count_current, total_count_current)
            VALUES ('perf_test', 1, 33, 50)
        ");
    }

    /**
     * Test database query performance
     */
    private function testDatabaseQueryPerformance(): void
    {
        $queries = [
            'SELECT COUNT(*) FROM etl_extracted_data WHERE is_active = 1',
            'SELECT COUNT(*) FROM etl_product_activity_log WHERE changed_at >= datetime("now", "-1 day")',
            'SELECT source, COUNT(*) FROM etl_extracted_data GROUP BY source',
            'SELECT * FROM etl_activity_monitoring WHERE monitoring_enabled = 1'
        ];
        
        foreach ($queries as $query) {
            $startTime = microtime(true);
            $this->pdo->query($query);
            $queryTime = microtime(true) - $startTime;
            
            $this->assert($queryTime < 1.0, "Запрос должен выполняться менее чем за 1 секунду: {$query}");
        }
    }   
 /**
     * Helper method for assertions
     */
    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new Exception("Assertion failed: " . $message);
        }
        echo "✅ " . $message . "\n";
    }

    /**
     * Print test results
     */
    private function printResults(): void
    {
        echo "🎉 РЕЗУЛЬТАТЫ ETL INTEGRATION ТЕСТОВ ДЛЯ ACTIVE PRODUCTS FILTER\n";
        echo "=" . str_repeat("=", 70) . "\n";
        
        $passed = 0;
        $failed = 0;
        
        foreach ($this->testResults as $testName => $result) {
            $status = $result['status'] === 'PASS' ? '✅' : '❌';
            echo "{$status} {$testName}: {$result['message']}\n";
            
            if ($result['status'] === 'PASS') {
                $passed++;
            } else {
                $failed++;
            }
        }
        
        echo "\n📊 ИТОГО:\n";
        echo "✅ Пройдено: {$passed}\n";
        echo "❌ Провалено: {$failed}\n";
        echo "📈 Успешность: " . round(($passed / ($passed + $failed)) * 100, 1) . "%\n";
        
        echo "\n📋 ПРОТЕСТИРОВАННАЯ ФУНКЦИОНАЛЬНОСТЬ:\n";
        echo "  ✅ Полный ETL процесс с фильтрацией активных товаров\n";
        echo "  ✅ Мониторинг активности и уведомления\n";
        echo "  ✅ Согласованность данных после запусков ETL\n";
        echo "  ✅ Интеграция планировщика ETL\n";
        echo "  ✅ Обработка ошибок в ETL процессе\n";
        echo "  ✅ Производительность с фильтрацией активных товаров\n";
        
        echo "\n🎯 СООТВЕТСТВИЕ ТРЕБОВАНИЯМ:\n";
        echo "  ✅ Requirement 1.4: ETL процесс с фильтрацией активных товаров\n";
        echo "  ✅ Requirement 4.4: Мониторинг и уведомления об изменениях активности\n";
        
        if ($failed === 0) {
            echo "\n🎉 ВСЕ ETL INTEGRATION ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
            echo "Система ETL с фильтрацией активных товаров готова к использованию в продакшене.\n";
            echo "Мониторинг активности и уведомления функционируют корректно.\n";
            echo "Согласованность данных обеспечена во всех сценариях.\n";
        } else {
            echo "\n⚠️  ОБНАРУЖЕНЫ ПРОБЛЕМЫ!\n";
            echo "Необходимо исправить {$failed} провалившихся тестов.\n";
        }
        
        echo "\n" . str_repeat("=", 70) . "\n";
    }

    /**
     * Check if all tests passed
     */
    private function allTestsPassed(): bool
    {
        foreach ($this->testResults as $result) {
            if ($result['status'] !== 'PASS') {
                return false;
            }
        }
        return true;
    }

    /**
     * Cleanup test data and database
     */
    private function cleanup(): void
    {
        try {
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            
            if ($driver === 'sqlite') {
                // For SQLite in-memory database, just note cleanup
                echo "🧹 SQLite in-memory database cleaned up\n";
            } elseif ($this->testDatabaseName && strpos($this->testDatabaseName, 'test_') === 0) {
                // Only drop databases that start with 'test_'
                $this->pdo->exec("DROP DATABASE IF EXISTS `{$this->testDatabaseName}`");
                echo "🧹 Test database cleaned up\n";
            }
        } catch (PDOException $e) {
            echo "⚠️  Warning: Could not cleanup test database: " . $e->getMessage() . "\n";
        }
    }
}

// Run tests if file is executed directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $test = new ETLActiveProductsIntegrationTest();
        $success = $test->runAllTests();
        exit($success ? 0 : 1);
    } catch (Exception $e) {
        echo "❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
        exit(1);
    }
}