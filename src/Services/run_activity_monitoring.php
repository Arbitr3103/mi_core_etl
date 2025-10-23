#!/usr/bin/env php
<?php
/**
 * Скрипт мониторинга активности товаров
 * 
 * Использование:
 * php run_activity_monitoring.php [command] [options]
 * 
 * Команды:
 * - check [source] - проверить изменения активности
 * - report - создать ежедневный отчет
 * - cleanup - очистить устаревшие логи
 * - status - показать статус мониторинга
 * - test-notification - отправить тестовое уведомление
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/ETL/Monitoring/ActivityMonitoringService.php';
require_once __DIR__ . '/src/ETL/Monitoring/NotificationService.php';
require_once __DIR__ . '/src/ETL/Config/ETLConfigManager.php';

use MDM\ETL\Monitoring\ActivityMonitoringService;
use MDM\ETL\Monitoring\NotificationService;
use MDM\ETL\Config\ETLConfigManager;

class ActivityMonitoringCLI
{
    private PDO $pdo;
    private ActivityMonitoringService $monitoringService;
    private ETLConfigManager $configManager;
    
    public function __construct()
    {
        $this->pdo = getDatabaseConnection();
        $this->configManager = new ETLConfigManager($this->pdo);
        
        $config = $this->configManager->getETLConfig();
        $this->monitoringService = new ActivityMonitoringService($this->pdo, $config['scheduler'] ?? []);
    }
    
    /**
     * Главная функция обработки команд
     */
    public function run(array $argv): void
    {
        if (count($argv) < 2) {
            $this->showHelp();
            return;
        }
        
        $command = $argv[1];
        $args = array_slice($argv, 2);
        
        try {
            switch ($command) {
                case 'check':
                    $this->runActivityCheck($args);
                    break;
                    
                case 'report':
                    $this->generateDailyReport();
                    break;
                    
                case 'cleanup':
                    $this->cleanupOldLogs();
                    break;
                    
                case 'status':
                    $this->showMonitoringStatus();
                    break;
                    
                case 'test-notification':
                    $this->sendTestNotification($args);
                    break;
                    
                case 'help':
                case '--help':
                case '-h':
                    $this->showHelp();
                    break;
                    
                default:
                    $this->error("Неизвестная команда: $command");
                    $this->showHelp();
            }
        } catch (Exception $e) {
            $this->error("Ошибка выполнения команды: " . $e->getMessage());
            exit(1);
        }
    }
    
    /**
     * Запуск проверки активности товаров
     */
    private function runActivityCheck(array $args): void
    {
        $source = $args[0] ?? null;
        
        $this->info("🔍 Запуск проверки активности товаров...");
        
        if ($source) {
            $this->info("Проверка источника: $source");
            $result = $this->monitoringService->checkSourceActivity($source);
            $this->displaySourceCheckResult($result);
        } else {
            $this->info("Проверка всех источников");
            $results = $this->monitoringService->checkAllSources();
            
            foreach ($results as $sourceResult) {
                $this->displaySourceCheckResult($sourceResult);
                echo "\n";
            }
            
            $this->displayCheckSummary($results);
        }
    }
    
    /**
     * Отображение результата проверки источника
     */
    private function displaySourceCheckResult(array $result): void
    {
        $source = $result['source'];
        $current = $result['current_stats'];
        $previous = $result['previous_stats'];
        
        echo "📊 Источник: $source\n";
        echo "  Текущие активные товары: {$current['active_count']}/{$current['total_count']} ({$current['active_percentage']}%)\n";
        
        if ($result['change_detected']) {
            $changeIcon = $current['active_count'] > $previous['active_count'] ? '📈' : '📉';
            echo "  Изменение: $changeIcon " . sprintf('%.2f%%', $result['change_percent']) . 
                 " (было: {$previous['active_count']})\n";
            
            if ($result['threshold_exceeded']) {
                if ($result['notification_sent']) {
                    echo "  🔔 Уведомление отправлено (превышен порог)\n";
                } elseif ($result['notification_suppressed']) {
                    echo "  🔕 Уведомление подавлено (cooldown период)\n";
                } else {
                    echo "  ⚠️ Превышен порог, но уведомление не отправлено\n";
                }
            }
        } else {
            echo "  ✅ Изменений не обнаружено\n";
        }
        
        if ($current['last_check_time']) {
            echo "  🕒 Последняя проверка: {$current['last_check_time']}\n";
        }
    }
    
    /**
     * Отображение сводки проверки
     */
    private function displayCheckSummary(array $results): void
    {
        $totalSources = count($results);
        $sourcesWithChanges = count(array_filter($results, fn($r) => $r['change_detected']));
        $notificationsSent = count(array_filter($results, fn($r) => $r['notification_sent']));
        
        echo "📋 Сводка проверки:\n";
        echo "  • Проверено источников: $totalSources\n";
        echo "  • Источников с изменениями: $sourcesWithChanges\n";
        echo "  • Отправлено уведомлений: $notificationsSent\n";
    }
    
    /**
     * Создание ежедневного отчета
     */
    private function generateDailyReport(): void
    {
        $this->info("📊 Создание ежедневного отчета об активности товаров...");
        
        $reportData = $this->monitoringService->generateDailyActivityReport();
        
        $this->success("✅ Ежедневный отчет создан");
        
        echo "\n📈 Сводка отчета:\n";
        echo "  • Дата отчета: {$reportData['report_date']}\n";
        echo "  • Источников данных: {$reportData['summary']['total_sources']}\n";
        echo "  • Всего товаров: {$reportData['summary']['total_products']}\n";
        echo "  • Активных товаров: {$reportData['summary']['total_active']} ({$reportData['summary']['active_percentage']}%)\n";
        echo "  • Неактивных товаров: {$reportData['summary']['total_inactive']}\n";
        echo "  • Не проверены: {$reportData['summary']['total_unchecked']}\n";
        
        if (!empty($reportData['sources'])) {
            echo "\n📊 По источникам:\n";
            foreach ($reportData['sources'] as $source) {
                echo "  • {$source['source']}: {$source['active_products']}/{$source['total_products']} активных ({$source['active_percentage']}%)\n";
            }
        }
        
        if (!empty($reportData['recent_changes'])) {
            echo "\n🔄 Изменения за 24 часа:\n";
            foreach ($reportData['recent_changes'] as $change) {
                echo "  • {$change['source']}: {$change['total_changes']} изменений ";
                echo "(+{$change['became_active']} активных, -{$change['became_inactive']} неактивных)\n";
            }
        }
    }
    
    /**
     * Очистка устаревших логов
     */
    private function cleanupOldLogs(): void
    {
        $this->info("🧹 Очистка устаревших логов активности товаров...");
        
        $deletedCount = $this->monitoringService->cleanupOldActivityLogs();
        
        $this->success("✅ Очистка завершена. Удалено записей: $deletedCount");
    }
    
    /**
     * Показать статус мониторинга
     */
    private function showMonitoringStatus(): void
    {
        $this->info("📊 Статус системы мониторинга активности товаров");
        
        $stats = $this->monitoringService->getMonitoringStats();
        
        echo "\n🔧 Статус мониторинга по источникам:\n";
        foreach ($stats['monitoring_status'] as $status) {
            $enabledIcon = $status['monitoring_enabled'] ? '✅' : '❌';
            echo "  $enabledIcon {$status['source']}:\n";
            echo "    • Мониторинг: " . ($status['monitoring_enabled'] ? 'включен' : 'отключен') . "\n";
            echo "    • Активных товаров: {$status['active_count_current']}\n";
            echo "    • Было активных: {$status['active_count_previous']}\n";
            echo "    • Всего товаров: {$status['total_count_current']}\n";
            echo "    • Порог уведомлений: {$status['change_threshold_percent']}%\n";
            
            if ($status['last_check_at']) {
                echo "    • Последняя проверка: {$status['last_check_at']}\n";
            }
            
            if ($status['notification_sent_at']) {
                echo "    • Последнее уведомление: {$status['notification_sent_at']}\n";
            }
            
            echo "\n";
        }
        
        if (!empty($stats['recent_notifications'])) {
            echo "🔔 Недавние уведомления:\n";
            foreach (array_slice($stats['recent_notifications'], 0, 5) as $notification) {
                $levelIcon = $notification['level'] === 'WARNING' ? '⚠️' : 'ℹ️';
                echo "  $levelIcon [{$notification['created_at']}] {$notification['source']}: {$notification['message']}\n";
            }
            echo "\n";
        }
        
        if (!empty($stats['activity_trends'])) {
            echo "📈 Тренды активности (последние 7 дней):\n";
            $currentDate = '';
            foreach ($stats['activity_trends'] as $trend) {
                if ($trend['date'] !== $currentDate) {
                    if ($currentDate !== '') echo "\n";
                    echo "  📅 {$trend['date']}:\n";
                    $currentDate = $trend['date'];
                }
                echo "    • {$trend['source']}: +{$trend['became_active']} активных, -{$trend['became_inactive']} неактивных\n";
            }
        }
    }
    
    /**
     * Отправка тестового уведомления
     */
    private function sendTestNotification(array $args): void
    {
        $email = $args[0] ?? null;
        
        if ($email) {
            // Временно устанавливаем email для теста
            $this->configManager->setConfigValue('scheduler', 'activity_notification_email', $email, 'Test email for notifications');
        }
        
        $this->info("📧 Отправка тестового уведомления...");
        
        // Создаем тестовое уведомление
        $testNotification = [
            'type' => 'test',
            'subject' => 'Тестовое уведомление системы мониторинга активности товаров',
            'message' => "Это тестовое уведомление для проверки работы системы мониторинга активности товаров.\n\n" .
                        "Если вы получили это сообщение, значит система уведомлений работает корректно.\n\n" .
                        "Время отправки: " . date('Y-m-d H:i:s') . "\n" .
                        "Сервер: " . gethostname(),
            'priority' => 'low',
            'source' => 'test',
            'data' => [
                'test_mode' => true,
                'sent_from_cli' => true,
                'php_version' => PHP_VERSION,
                'server_time' => date('c')
            ]
        ];
        
        $notificationService = new NotificationService($this->pdo, [
            'enabled' => true,
            'email_enabled' => true,
            'log_enabled' => true
        ]);
        
        $success = $notificationService->sendNotification($testNotification);
        
        if ($success) {
            $this->success("✅ Тестовое уведомление отправлено успешно");
            
            if ($email) {
                echo "📧 Проверьте почтовый ящик: $email\n";
            } else {
                $configuredEmail = $this->configManager->getConfigValue('scheduler', 'activity_notification_email', '');
                if ($configuredEmail) {
                    echo "📧 Проверьте почтовый ящик: $configuredEmail\n";
                } else {
                    echo "⚠️ Email для уведомлений не настроен. Уведомление записано только в логи.\n";
                }
            }
        } else {
            $this->error("❌ Ошибка отправки тестового уведомления");
        }
    }
    
    /**
     * Показать справку
     */
    private function showHelp(): void
    {
        echo "Система мониторинга активности товаров\n\n";
        echo "Использование: php run_activity_monitoring.php <command> [options]\n\n";
        echo "Команды:\n";
        echo "  check [source]           Проверить изменения активности товаров\n";
        echo "  report                   Создать ежедневный отчет об активности\n";
        echo "  cleanup                  Очистить устаревшие логи активности\n";
        echo "  status                   Показать статус системы мониторинга\n";
        echo "  test-notification [email] Отправить тестовое уведомление\n";
        echo "  help                     Показать эту справку\n\n";
        echo "Примеры:\n";
        echo "  php run_activity_monitoring.php check                    # Проверить все источники\n";
        echo "  php run_activity_monitoring.php check ozon               # Проверить только Ozon\n";
        echo "  php run_activity_monitoring.php report                   # Создать ежедневный отчет\n";
        echo "  php run_activity_monitoring.php test-notification user@example.com  # Тест уведомлений\n";
        echo "  php run_activity_monitoring.php status                   # Показать статус\n\n";
        echo "Настройка:\n";
        echo "  Для настройки email уведомлений:\n";
        echo "  php etl_cli.php config scheduler activity_notification_email your@email.com\n\n";
        echo "  Для настройки порога уведомлений (в процентах):\n";
        echo "  php etl_cli.php config ozon change_threshold_percent 15\n\n";
    }
    
    /**
     * Вывод информационного сообщения
     */
    private function info(string $message): void
    {
        echo "ℹ️  $message\n";
    }
    
    /**
     * Вывод сообщения об успехе
     */
    private function success(string $message): void
    {
        echo "✅ $message\n";
    }
    
    /**
     * Вывод предупреждения
     */
    private function warning(string $message): void
    {
        echo "⚠️  $message\n";
    }
    
    /**
     * Вывод ошибки
     */
    private function error(string $message): void
    {
        echo "❌ $message\n";
    }
}

// Запуск CLI
if (php_sapi_name() === 'cli') {
    $cli = new ActivityMonitoringCLI();
    $cli->run($argv);
} else {
    echo "Этот скрипт должен запускаться из командной строки\n";
    exit(1);
}