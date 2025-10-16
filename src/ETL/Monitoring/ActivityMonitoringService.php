<?php

namespace MDM\ETL\Monitoring;

use Exception;
use PDO;

/**
 * Сервис мониторинга активности товаров
 * 
 * Отслеживает изменения в количестве активных товаров и отправляет уведомления
 * при значительных изменениях.
 */
class ActivityMonitoringService
{
    private PDO $pdo;
    private array $config;
    private NotificationService $notificationService;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'monitoring_enabled' => true,
            'default_threshold_percent' => 10.0,
            'notification_cooldown_minutes' => 60,
            'daily_report_enabled' => true,
            'daily_report_time' => '09:00',
            'log_retention_days' => 90
        ], $config);
        
        $this->notificationService = new NotificationService($pdo, $config['notifications'] ?? []);
    }
    
    /**
     * Проверка изменений активности товаров для всех источников
     * 
     * @return array Результаты проверки
     */
    public function checkAllSources(): array
    {
        $results = [];
        
        try {
            // Получаем список источников с включенным мониторингом
            $stmt = $this->pdo->query("
                SELECT source, change_threshold_percent, notification_sent_at
                FROM etl_activity_monitoring 
                WHERE monitoring_enabled = 1
            ");
            
            $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($sources as $sourceConfig) {
                $results[$sourceConfig['source']] = $this->checkSourceActivity(
                    $sourceConfig['source'],
                    $sourceConfig['change_threshold_percent'],
                    $sourceConfig['notification_sent_at']
                );
            }
            
            $this->log('INFO', 'Проверка активности товаров завершена', [
                'sources_checked' => count($sources),
                'notifications_sent' => count(array_filter($results, fn($r) => $r['notification_sent'] ?? false))
            ]);
            
            return $results;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка проверки активности товаров', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Проверка изменений активности товаров для конкретного источника
     * 
     * @param string $source Источник данных
     * @param float|null $thresholdPercent Порог изменения в процентах
     * @param string|null $lastNotificationTime Время последнего уведомления
     * @return array Результат проверки
     */
    public function checkSourceActivity(string $source, ?float $thresholdPercent = null, ?string $lastNotificationTime = null): array
    {
        try {
            // Получаем текущую статистику
            $currentStats = $this->getCurrentActivityStats($source);
            
            // Получаем предыдущую статистику
            $previousStats = $this->getPreviousActivityStats($source);
            
            $result = [
                'source' => $source,
                'current_stats' => $currentStats,
                'previous_stats' => $previousStats,
                'change_detected' => false,
                'change_percent' => 0,
                'threshold_exceeded' => false,
                'notification_sent' => false,
                'notification_suppressed' => false
            ];
            
            // Вычисляем изменение
            if ($previousStats['active_count'] > 0) {
                $result['change_percent'] = abs(
                    ($currentStats['active_count'] - $previousStats['active_count']) * 100.0 / $previousStats['active_count']
                );
                $result['change_detected'] = $result['change_percent'] > 0;
            }
            
            // Проверяем превышение порога
            $threshold = $thresholdPercent ?? $this->config['default_threshold_percent'];
            $result['threshold_exceeded'] = $result['change_percent'] > $threshold;
            
            // Проверяем необходимость отправки уведомления
            if ($result['threshold_exceeded']) {
                $cooldownExpired = $this->isNotificationCooldownExpired($lastNotificationTime);
                
                if ($cooldownExpired) {
                    $this->sendActivityChangeNotification($source, $result);
                    $result['notification_sent'] = true;
                    
                    // Обновляем время последнего уведомления
                    $this->updateLastNotificationTime($source);
                } else {
                    $result['notification_suppressed'] = true;
                    $this->log('INFO', 'Уведомление подавлено из-за cooldown периода', [
                        'source' => $source,
                        'last_notification' => $lastNotificationTime
                    ]);
                }
            }
            
            // Обновляем статистику мониторинга
            $this->updateMonitoringStats($source, $currentStats, $previousStats);
            
            return $result;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка проверки активности источника', [
                'source' => $source,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Получение текущей статистики активности товаров
     * 
     * @param string $source Источник данных
     * @return array Статистика
     */
    private function getCurrentActivityStats(string $source): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_count,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_count,
                COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_count,
                COUNT(CASE WHEN is_active IS NULL THEN 1 END) as unchecked_count,
                MAX(activity_checked_at) as last_check_time,
                MAX(extracted_at) as last_extraction_time
            FROM etl_extracted_data 
            WHERE source = ?
        ");
        
        $stmt->execute([$source]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Вычисляем процентные соотношения
        if ($stats['total_count'] > 0) {
            $stats['active_percentage'] = round(($stats['active_count'] / $stats['total_count']) * 100, 2);
            $stats['inactive_percentage'] = round(($stats['inactive_count'] / $stats['total_count']) * 100, 2);
            $stats['unchecked_percentage'] = round(($stats['unchecked_count'] / $stats['total_count']) * 100, 2);
        } else {
            $stats['active_percentage'] = 0;
            $stats['inactive_percentage'] = 0;
            $stats['unchecked_percentage'] = 0;
        }
        
        return $stats;
    }
    
    /**
     * Получение предыдущей статистики активности товаров
     * 
     * @param string $source Источник данных
     * @return array Статистика
     */
    private function getPreviousActivityStats(string $source): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                active_count_previous as active_count,
                total_count_current as total_count,
                last_check_at
            FROM etl_activity_monitoring 
            WHERE source = ?
        ");
        
        $stmt->execute([$source]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$stats) {
            return [
                'active_count' => 0,
                'total_count' => 0,
                'last_check_at' => null
            ];
        }
        
        return $stats;
    }
    
    /**
     * Проверка истечения cooldown периода для уведомлений
     * 
     * @param string|null $lastNotificationTime Время последнего уведомления
     * @return bool Истек ли cooldown период
     */
    private function isNotificationCooldownExpired(?string $lastNotificationTime): bool
    {
        if (!$lastNotificationTime) {
            return true;
        }
        
        $cooldownMinutes = $this->config['notification_cooldown_minutes'];
        $cooldownExpiry = strtotime($lastNotificationTime) + ($cooldownMinutes * 60);
        
        return time() > $cooldownExpiry;
    }
    
    /**
     * Отправка уведомления об изменении активности товаров
     * 
     * @param string $source Источник данных
     * @param array $changeData Данные об изменении
     */
    private function sendActivityChangeNotification(string $source, array $changeData): void
    {
        try {
            $current = $changeData['current_stats'];
            $previous = $changeData['previous_stats'];
            
            $subject = "Изменение активности товаров: $source";
            
            $message = $this->buildNotificationMessage($source, $changeData);
            
            // Отправляем уведомление
            $this->notificationService->sendNotification([
                'type' => 'activity_change',
                'source' => $source,
                'subject' => $subject,
                'message' => $message,
                'priority' => $changeData['change_percent'] > 50 ? 'high' : 'medium',
                'data' => $changeData
            ]);
            
            $this->log('INFO', 'Отправлено уведомление об изменении активности', [
                'source' => $source,
                'change_percent' => $changeData['change_percent'],
                'current_active' => $current['active_count'],
                'previous_active' => $previous['active_count']
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки уведомления об изменении активности', [
                'source' => $source,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Построение сообщения уведомления
     * 
     * @param string $source Источник данных
     * @param array $changeData Данные об изменении
     * @return string Сообщение
     */
    private function buildNotificationMessage(string $source, array $changeData): string
    {
        $current = $changeData['current_stats'];
        $previous = $changeData['previous_stats'];
        
        $changeDirection = $current['active_count'] > $previous['active_count'] ? 'увеличилось' : 'уменьшилось';
        $changeIcon = $current['active_count'] > $previous['active_count'] ? '📈' : '📉';
        
        $message = "🔔 Обнаружено значительное изменение активности товаров\n\n";
        $message .= "📊 Источник: $source\n";
        $message .= "📅 Время проверки: " . date('Y-m-d H:i:s') . "\n\n";
        
        $message .= "📈 Статистика изменений:\n";
        $message .= "• Активных товаров было: {$previous['active_count']}\n";
        $message .= "• Активных товаров стало: {$current['active_count']}\n";
        $message .= "• Изменение: $changeIcon " . sprintf('%.2f%%', $changeData['change_percent']) . "\n";
        $message .= "• Общее количество товаров: {$current['total_count']}\n\n";
        
        $message .= "📊 Текущее распределение:\n";
        $message .= "• Активные: {$current['active_count']} ({$current['active_percentage']}%)\n";
        $message .= "• Неактивные: {$current['inactive_count']} ({$current['inactive_percentage']}%)\n";
        $message .= "• Не проверены: {$current['unchecked_count']} ({$current['unchecked_percentage']}%)\n\n";
        
        if ($current['last_check_time']) {
            $message .= "🕒 Последняя проверка активности: {$current['last_check_time']}\n";
        }
        
        if ($current['last_extraction_time']) {
            $message .= "📥 Последнее извлечение данных: {$current['last_extraction_time']}\n";
        }
        
        $message .= "\n💡 Рекомендации:\n";
        
        if ($current['active_count'] < $previous['active_count']) {
            $message .= "• Проверьте настройки товаров в личном кабинете $source\n";
            $message .= "• Убедитесь в наличии остатков на складе\n";
            $message .= "• Проверьте корректность цен\n";
        } else {
            $message .= "• Увеличение активных товаров - положительная динамика\n";
            $message .= "• Убедитесь в корректности данных\n";
        }
        
        if ($current['unchecked_count'] > 0) {
            $message .= "• Запустите проверку активности для непроверенных товаров\n";
        }
        
        return $message;
    }
    
    /**
     * Обновление времени последнего уведомления
     * 
     * @param string $source Источник данных
     */
    private function updateLastNotificationTime(string $source): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE etl_activity_monitoring 
            SET notification_sent_at = NOW(), updated_at = NOW()
            WHERE source = ?
        ");
        
        $stmt->execute([$source]);
    }
    
    /**
     * Обновление статистики мониторинга
     * 
     * @param string $source Источник данных
     * @param array $currentStats Текущая статистика
     * @param array $previousStats Предыдущая статистика
     */
    private function updateMonitoringStats(string $source, array $currentStats, array $previousStats): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO etl_activity_monitoring 
            (source, last_check_at, active_count_current, active_count_previous, 
             total_count_current, monitoring_enabled)
            VALUES (?, NOW(), ?, ?, ?, 1)
            ON DUPLICATE KEY UPDATE
                last_check_at = NOW(),
                active_count_previous = active_count_current,
                active_count_current = VALUES(active_count_current),
                total_count_current = VALUES(total_count_current),
                updated_at = NOW()
        ");
        
        $stmt->execute([
            $source,
            $currentStats['active_count'],
            $previousStats['active_count'],
            $currentStats['total_count']
        ]);
    }
    
    /**
     * Создание ежедневного отчета об активности товаров
     * 
     * @return array Данные отчета
     */
    public function generateDailyActivityReport(): array
    {
        try {
            $reportData = [
                'report_date' => date('Y-m-d'),
                'generated_at' => date('Y-m-d H:i:s'),
                'sources' => [],
                'summary' => [
                    'total_sources' => 0,
                    'total_products' => 0,
                    'total_active' => 0,
                    'total_inactive' => 0,
                    'total_unchecked' => 0
                ]
            ];
            
            // Получаем статистику по всем источникам
            $stmt = $this->pdo->query("
                SELECT 
                    source,
                    total_products,
                    active_products,
                    inactive_products,
                    unchecked_products,
                    active_percentage,
                    last_activity_check,
                    last_extraction
                FROM v_etl_active_products_stats
                ORDER BY source
            ");
            
            $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($sources as $sourceStats) {
                $reportData['sources'][] = $sourceStats;
                
                $reportData['summary']['total_sources']++;
                $reportData['summary']['total_products'] += $sourceStats['total_products'];
                $reportData['summary']['total_active'] += $sourceStats['active_products'];
                $reportData['summary']['total_inactive'] += $sourceStats['inactive_products'];
                $reportData['summary']['total_unchecked'] += $sourceStats['unchecked_products'];
            }
            
            // Вычисляем общие проценты
            if ($reportData['summary']['total_products'] > 0) {
                $reportData['summary']['active_percentage'] = round(
                    ($reportData['summary']['total_active'] / $reportData['summary']['total_products']) * 100, 2
                );
            } else {
                $reportData['summary']['active_percentage'] = 0;
            }
            
            // Получаем изменения за последние 24 часа
            $reportData['recent_changes'] = $this->getRecentActivityChanges(24);
            
            // Отправляем отчет, если включено
            if ($this->config['daily_report_enabled']) {
                $this->sendDailyReport($reportData);
            }
            
            $this->log('INFO', 'Создан ежедневный отчет об активности товаров', [
                'total_sources' => $reportData['summary']['total_sources'],
                'total_products' => $reportData['summary']['total_products'],
                'total_active' => $reportData['summary']['total_active']
            ]);
            
            return $reportData;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка создания ежедневного отчета', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Получение недавних изменений активности товаров
     * 
     * @param int $hours Количество часов назад
     * @return array Изменения
     */
    private function getRecentActivityChanges(int $hours): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                source,
                COUNT(*) as total_changes,
                COUNT(CASE WHEN new_status = 1 THEN 1 END) as became_active,
                COUNT(CASE WHEN new_status = 0 THEN 1 END) as became_inactive,
                MIN(changed_at) as first_change,
                MAX(changed_at) as last_change
            FROM etl_product_activity_log 
            WHERE changed_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
            GROUP BY source
            ORDER BY total_changes DESC
        ");
        
        $stmt->execute([$hours]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Отправка ежедневного отчета
     * 
     * @param array $reportData Данные отчета
     */
    private function sendDailyReport(array $reportData): void
    {
        try {
            $subject = "Ежедневный отчет об активности товаров - " . $reportData['report_date'];
            $message = $this->buildDailyReportMessage($reportData);
            
            $this->notificationService->sendNotification([
                'type' => 'daily_report',
                'subject' => $subject,
                'message' => $message,
                'priority' => 'low',
                'data' => $reportData
            ]);
            
            $this->log('INFO', 'Отправлен ежедневный отчет об активности товаров');
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки ежедневного отчета', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Построение сообщения ежедневного отчета
     * 
     * @param array $reportData Данные отчета
     * @return string Сообщение
     */
    private function buildDailyReportMessage(array $reportData): string
    {
        $summary = $reportData['summary'];
        
        $message = "📊 Ежедневный отчет об активности товаров\n";
        $message .= "📅 Дата: {$reportData['report_date']}\n";
        $message .= "🕒 Создан: {$reportData['generated_at']}\n\n";
        
        $message .= "📈 Общая статистика:\n";
        $message .= "• Источников данных: {$summary['total_sources']}\n";
        $message .= "• Всего товаров: {$summary['total_products']}\n";
        $message .= "• Активных товаров: {$summary['total_active']} ({$summary['active_percentage']}%)\n";
        $message .= "• Неактивных товаров: {$summary['total_inactive']}\n";
        $message .= "• Не проверены: {$summary['total_unchecked']}\n\n";
        
        if (!empty($reportData['sources'])) {
            $message .= "📊 Статистика по источникам:\n";
            foreach ($reportData['sources'] as $source) {
                $message .= "• {$source['source']}: {$source['active_products']}/{$source['total_products']} активных ({$source['active_percentage']}%)\n";
            }
            $message .= "\n";
        }
        
        if (!empty($reportData['recent_changes'])) {
            $message .= "🔄 Изменения за последние 24 часа:\n";
            foreach ($reportData['recent_changes'] as $change) {
                $message .= "• {$change['source']}: {$change['total_changes']} изменений ";
                $message .= "(+{$change['became_active']} активных, -{$change['became_inactive']} неактивных)\n";
            }
            $message .= "\n";
        }
        
        $message .= "💡 Рекомендации:\n";
        
        if ($summary['total_unchecked'] > 0) {
            $message .= "• Запустите проверку активности для {$summary['total_unchecked']} непроверенных товаров\n";
        }
        
        if ($summary['active_percentage'] < 50) {
            $message .= "• Низкий процент активных товаров - проверьте настройки в личных кабинетах\n";
        }
        
        $message .= "• Регулярно обновляйте данные для актуальной статистики\n";
        
        return $message;
    }
    
    /**
     * Очистка устаревших логов активности
     * 
     * @return int Количество удаленных записей
     */
    public function cleanupOldActivityLogs(): int
    {
        try {
            $retentionDays = $this->config['log_retention_days'];
            
            $stmt = $this->pdo->prepare("
                DELETE FROM etl_product_activity_log 
                WHERE changed_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            $stmt->execute([$retentionDays]);
            $deletedCount = $stmt->rowCount();
            
            $this->log('INFO', 'Очищены устаревшие логи активности товаров', [
                'deleted_count' => $deletedCount,
                'retention_days' => $retentionDays
            ]);
            
            return $deletedCount;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка очистки устаревших логов активности', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Получение статистики мониторинга активности
     * 
     * @return array Статистика
     */
    public function getMonitoringStats(): array
    {
        try {
            $stats = [
                'monitoring_status' => [],
                'recent_notifications' => [],
                'activity_trends' => []
            ];
            
            // Статус мониторинга по источникам
            $stmt = $this->pdo->query("
                SELECT 
                    source,
                    monitoring_enabled,
                    last_check_at,
                    active_count_current,
                    active_count_previous,
                    total_count_current,
                    change_threshold_percent,
                    notification_sent_at
                FROM etl_activity_monitoring
                ORDER BY source
            ");
            
            $stats['monitoring_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Недавние уведомления (из логов)
            $stmt = $this->pdo->query("
                SELECT 
                    source,
                    level,
                    message,
                    created_at
                FROM etl_logs 
                WHERE source = 'activity_monitoring' 
                  AND level IN ('WARNING', 'INFO')
                  AND message LIKE '%уведомление%'
                ORDER BY created_at DESC 
                LIMIT 10
            ");
            
            $stats['recent_notifications'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Тренды активности (последние 7 дней)
            $stmt = $this->pdo->query("
                SELECT 
                    DATE(changed_at) as date,
                    source,
                    COUNT(CASE WHEN new_status = 1 THEN 1 END) as became_active,
                    COUNT(CASE WHEN new_status = 0 THEN 1 END) as became_inactive
                FROM etl_product_activity_log 
                WHERE changed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                GROUP BY DATE(changed_at), source
                ORDER BY date DESC, source
            ");
            
            $stats['activity_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return $stats;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения статистики мониторинга', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Логирование операций сервиса
     * 
     * @param string $level Уровень лога
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $fullMessage = $message;
        
        if (!empty($context)) {
            $fullMessage .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        error_log("[Activity Monitoring] [$level] $fullMessage");
        
        // Сохраняем в БД
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES ('activity_monitoring', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $level, 
                $message, 
                !empty($context) ? json_encode($context) : null
            ]);
        } catch (Exception $e) {
            // Игнорируем ошибки логирования
        }
    }
}