<?php
/**
 * Тестовый скрипт для проверки системы мониторинга ETL
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/ETL/Monitoring/ETLMonitoringService.php';
require_once __DIR__ . '/src/ETL/Monitoring/ETLNotificationService.php';
require_once __DIR__ . '/src/ETL/Monitoring/ETLDashboardController.php';

use MDM\ETL\Monitoring\ETLMonitoringService;
use MDM\ETL\Monitoring\ETLNotificationService;
use MDM\ETL\Monitoring\ETLDashboardController;

try {
    // Подключение к базе данных
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "✓ Подключение к базе данных успешно\n";
    
    // Создание таблиц мониторинга (если не существуют)
    $schema = file_get_contents(__DIR__ . '/src/ETL/Database/monitoring_schema.sql');
    $statements = explode(';', $schema);
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
            } catch (Exception $e) {
                // Игнорируем ошибки создания таблиц (возможно, уже существуют)
            }
        }
    }
    
    echo "✓ Схема мониторинга проверена/создана\n";
    
    // Инициализация сервисов
    $monitoringService = new ETLMonitoringService($pdo, [
        'performance_threshold_seconds' => 60,
        'error_threshold_count' => 3,
        'notification_cooldown_minutes' => 5
    ]);
    
    $notificationService = new ETLNotificationService($pdo, [
        'email_enabled' => false, // Отключаем для теста
        'slack_enabled' => false,
        'telegram_enabled' => false
    ]);
    
    $dashboardController = new ETLDashboardController($pdo);
    
    echo "✓ Сервисы мониторинга инициализированы\n";
    
    // Тест 1: Создание и мониторинг успешной задачи
    echo "\n--- Тест 1: Успешная задача ---\n";
    
    $taskId = 'test_task_' . time();
    $sessionId = $monitoringService->startTaskMonitoring($taskId, 'test_etl', [
        'source' => 'test',
        'description' => 'Тестовая задача для проверки мониторинга'
    ]);
    
    echo "Запущен мониторинг задачи: {$sessionId}\n";
    
    // Симуляция прогресса выполнения
    for ($i = 1; $i <= 5; $i++) {
        sleep(1);
        $monitoringService->updateTaskProgress($sessionId, [
            'current_step' => "Шаг {$i} из 5",
            'records_processed' => $i * 20,
            'records_total' => 100,
            'additional_info' => "Обработка данных шага {$i}"
        ]);
        echo "Прогресс: {$i}/5 шагов завершено\n";
    }
    
    // Завершение задачи с успехом
    $monitoringService->finishTaskMonitoring($sessionId, 'success', [
        'total_records_processed' => 100,
        'duration_seconds' => 5,
        'summary' => 'Задача выполнена успешно'
    ]);
    
    echo "✓ Задача завершена успешно\n";
    
    // Тест 2: Создание задачи с ошибкой
    echo "\n--- Тест 2: Задача с ошибкой ---\n";
    
    $errorTaskId = 'error_task_' . time();
    $errorSessionId = $monitoringService->startTaskMonitoring($errorTaskId, 'test_error_etl', [
        'source' => 'test',
        'description' => 'Тестовая задача с ошибкой'
    ]);
    
    echo "Запущен мониторинг задачи с ошибкой: {$errorSessionId}\n";
    
    // Симуляция частичного выполнения
    $monitoringService->updateTaskProgress($errorSessionId, [
        'current_step' => 'Обработка данных',
        'records_processed' => 30,
        'records_total' => 100
    ]);
    
    sleep(1);
    
    // Завершение с ошибкой
    $monitoringService->finishTaskMonitoring($errorSessionId, 'error', [
        'error_message' => 'Тестовая ошибка: не удалось обработать данные',
        'records_processed' => 30,
        'error_code' => 'TEST_ERROR_001'
    ]);
    
    echo "✓ Задача завершена с ошибкой\n";
    
    // Тест 3: Получение статистики
    echo "\n--- Тест 3: Получение статистики ---\n";
    
    $activeTasks = $monitoringService->getActiveTasksStatus();
    echo "Активных задач: " . count($activeTasks) . "\n";
    
    $taskHistory = $monitoringService->getTaskHistory(10);
    echo "Записей в истории: " . count($taskHistory) . "\n";
    
    $performanceMetrics = $monitoringService->getPerformanceMetrics(1);
    echo "Метрики производительности:\n";
    if (!empty($performanceMetrics['overall_stats'])) {
        $stats = $performanceMetrics['overall_stats'];
        echo "  - Всего задач: {$stats['total_tasks']}\n";
        echo "  - Успешных: {$stats['successful_tasks']}\n";
        echo "  - С ошибками: {$stats['failed_tasks']}\n";
        echo "  - Среднее время: {$stats['avg_duration']}с\n";
    }
    
    $errorStats = $monitoringService->getErrorStatistics(1);
    echo "Статистика ошибок:\n";
    if (!empty($errorStats['error_overview'])) {
        $overview = $errorStats['error_overview'];
        echo "  - Всего ошибок: {$overview['total_errors']}\n";
    }
    
    // Тест 4: Системные алерты
    echo "\n--- Тест 4: Системные алерты ---\n";
    
    $alerts = $monitoringService->getSystemAlerts();
    echo "Активных алертов: " . count($alerts) . "\n";
    
    foreach ($alerts as $alert) {
        echo "  - [{$alert['severity']}] {$alert['message']}\n";
    }
    
    // Тест 5: Dashboard данные
    echo "\n--- Тест 5: Dashboard данные ---\n";
    
    $dashboardData = $dashboardController->getDashboardData();
    echo "Dashboard данные получены:\n";
    echo "  - Активных задач: " . count($dashboardData['active_tasks']) . "\n";
    echo "  - Алертов: " . count($dashboardData['system_alerts']) . "\n";
    echo "  - Последних задач: " . count($dashboardData['recent_tasks']) . "\n";
    echo "  - Состояние системы: " . ($dashboardData['system_health']['overall_status'] ?? 'unknown') . "\n";
    
    // Тест 6: Детали задачи
    echo "\n--- Тест 6: Детали задачи ---\n";
    
    $taskDetails = $dashboardController->getTaskDetails($sessionId);
    if ($taskDetails) {
        echo "Детали задачи {$sessionId}:\n";
        echo "  - Статус: {$taskDetails['status']}\n";
        echo "  - Тип: {$taskDetails['task_type']}\n";
        echo "  - Прогресс: {$taskDetails['progress_percent']}%\n";
        echo "  - Длительность: {$taskDetails['duration_seconds']}с\n";
    } else {
        echo "Не удалось получить детали задачи\n";
    }
    
    // Тест 7: Аналитические данные
    echo "\n--- Тест 7: Аналитические данные ---\n";
    
    $analyticsData = $dashboardController->getAnalyticsData(1);
    echo "Аналитические данные получены:\n";
    echo "  - Трендов производительности: " . count($analyticsData['performance_trends'] ?? []) . "\n";
    echo "  - Анализов ошибок: " . count($analyticsData['error_analysis'] ?? []) . "\n";
    echo "  - Анализов пропускной способности: " . count($analyticsData['throughput_analysis'] ?? []) . "\n";
    
    echo "\n✅ Все тесты мониторинга ETL выполнены успешно!\n";
    
    // Показываем URL для dashboard
    echo "\n📊 Для просмотра веб-интерфейса откройте:\n";
    echo "http://localhost/path/to/src/ETL/Monitoring/dashboard.php\n";
    
} catch (Exception $e) {
    echo "\n❌ Ошибка при тестировании: " . $e->getMessage() . "\n";
    echo "Трассировка:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}