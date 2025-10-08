<?php

namespace MDM\ETL\Monitoring;

use Exception;
use PDO;

/**
 * Сервис уведомлений для ETL процессов
 * Отправляет уведомления об ошибках, проблемах производительности и других событиях
 */
class ETLNotificationService
{
    private PDO $pdo;
    private array $config;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'email_enabled' => true,
            'email_recipients' => ['admin@example.com'],
            'slack_enabled' => false,
            'slack_webhook_url' => '',
            'telegram_enabled' => false,
            'telegram_bot_token' => '',
            'telegram_chat_id' => '',
            'cooldown_minutes' => 30,
            'max_notifications_per_hour' => 10
        ], $config);
    }
    
    /**
     * Отправка уведомления об ошибке ETL задачи
     * 
     * @param string $sessionId ID сессии мониторинга
     * @param string $taskId ID задачи
     * @param string $taskType Тип задачи
     * @param string $errorMessage Сообщение об ошибке
     */
    public function sendErrorAlert(string $sessionId, string $taskId, string $taskType, string $errorMessage): void
    {
        try {
            $alertKey = "error_{$taskType}_{$taskId}";
            
            if ($this->shouldSendNotification($alertKey)) {
                $message = $this->formatErrorMessage($sessionId, $taskId, $taskType, $errorMessage);
                
                $this->sendNotification([
                    'type' => 'error',
                    'title' => 'ETL Task Error',
                    'message' => $message,
                    'priority' => 'high',
                    'session_id' => $sessionId,
                    'task_id' => $taskId,
                    'task_type' => $taskType
                ]);
                
                $this->recordNotification($alertKey, 'error', $message);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки уведомления об ошибке', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Отправка уведомления о проблемах производительности
     * 
     * @param string $sessionId ID сессии мониторинга
     * @param string $taskId ID задачи
     * @param string $taskType Тип задачи
     * @param int $runningSeconds Время выполнения в секундах
     */
    public function sendPerformanceAlert(string $sessionId, string $taskId, string $taskType, int $runningSeconds): void
    {
        try {
            $alertKey = "performance_{$taskType}_{$taskId}";
            
            if ($this->shouldSendNotification($alertKey)) {
                $message = $this->formatPerformanceMessage($sessionId, $taskId, $taskType, $runningSeconds);
                
                $this->sendNotification([
                    'type' => 'warning',
                    'title' => 'ETL Performance Warning',
                    'message' => $message,
                    'priority' => 'medium',
                    'session_id' => $sessionId,
                    'task_id' => $taskId,
                    'task_type' => $taskType
                ]);
                
                $this->recordNotification($alertKey, 'performance', $message);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки уведомления о производительности', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Отправка уведомления об успешном завершении критической задачи
     * 
     * @param string $sessionId ID сессии мониторинга
     * @param string $taskId ID задачи
     * @param string $taskType Тип задачи
     * @param array $results Результаты выполнения
     */
    public function sendSuccessNotification(string $sessionId, string $taskId, string $taskType, array $results): void
    {
        try {
            // Отправляем уведомления об успехе только для критических задач
            if (!$this->isCriticalTask($taskType)) {
                return;
            }
            
            $message = $this->formatSuccessMessage($sessionId, $taskId, $taskType, $results);
            
            $this->sendNotification([
                'type' => 'success',
                'title' => 'ETL Task Completed Successfully',
                'message' => $message,
                'priority' => 'low',
                'session_id' => $sessionId,
                'task_id' => $taskId,
                'task_type' => $taskType
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки уведомления об успехе', [
                'session_id' => $sessionId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Отправка еженедельного отчета о состоянии ETL системы
     */
    public function sendWeeklyReport(): void
    {
        try {
            $reportData = $this->generateWeeklyReportData();
            $message = $this->formatWeeklyReport($reportData);
            
            $this->sendNotification([
                'type' => 'report',
                'title' => 'Weekly ETL System Report',
                'message' => $message,
                'priority' => 'low'
            ]);
            
            $this->log('INFO', 'Отправлен еженедельный отчет', [
                'report_data' => $reportData
            ]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки еженедельного отчета', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Отправка уведомления о критических системных проблемах
     * 
     * @param string $alertType Тип алерта
     * @param string $message Сообщение
     * @param array $context Дополнительный контекст
     */
    public function sendSystemAlert(string $alertType, string $message, array $context = []): void
    {
        try {
            $alertKey = "system_{$alertType}";
            
            if ($this->shouldSendNotification($alertKey)) {
                $formattedMessage = $this->formatSystemAlert($alertType, $message, $context);
                
                $this->sendNotification([
                    'type' => 'system',
                    'title' => 'ETL System Alert',
                    'message' => $formattedMessage,
                    'priority' => 'high',
                    'alert_type' => $alertType,
                    'context' => $context
                ]);
                
                $this->recordNotification($alertKey, 'system', $formattedMessage);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки системного алерта', [
                'alert_type' => $alertType,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Проверка, нужно ли отправлять уведомление (с учетом cooldown)
     * 
     * @param string $alertKey Ключ алерта
     * @return bool Нужно ли отправлять
     */
    private function shouldSendNotification(string $alertKey): bool
    {
        try {
            // Проверяем cooldown для конкретного типа алерта
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as recent_count
                FROM etl_notifications 
                WHERE alert_key = ? 
                    AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt->execute([$alertKey, $this->config['cooldown_minutes']]);
            $recentCount = $stmt->fetch(PDO::FETCH_ASSOC)['recent_count'];
            
            if ($recentCount > 0) {
                return false;
            }
            
            // Проверяем общий лимит уведомлений в час
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as hourly_count
                FROM etl_notifications 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute();
            $hourlyCount = $stmt->fetch(PDO::FETCH_ASSOC)['hourly_count'];
            
            return $hourlyCount < $this->config['max_notifications_per_hour'];
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка проверки cooldown уведомлений', [
                'alert_key' => $alertKey,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Отправка уведомления через все настроенные каналы
     * 
     * @param array $notification Данные уведомления
     */
    private function sendNotification(array $notification): void
    {
        $sent = false;
        
        // Email уведомления
        if ($this->config['email_enabled']) {
            $sent = $this->sendEmailNotification($notification) || $sent;
        }
        
        // Slack уведомления
        if ($this->config['slack_enabled'] && !empty($this->config['slack_webhook_url'])) {
            $sent = $this->sendSlackNotification($notification) || $sent;
        }
        
        // Telegram уведомления
        if ($this->config['telegram_enabled'] && !empty($this->config['telegram_bot_token'])) {
            $sent = $this->sendTelegramNotification($notification) || $sent;
        }
        
        if ($sent) {
            $this->log('INFO', 'Уведомление отправлено', [
                'type' => $notification['type'],
                'title' => $notification['title']
            ]);
        }
    }
    
    /**
     * Отправка email уведомления
     * 
     * @param array $notification Данные уведомления
     * @return bool Успешность отправки
     */
    private function sendEmailNotification(array $notification): bool
    {
        try {
            $subject = "[ETL System] {$notification['title']}";
            $body = $this->formatEmailBody($notification);
            $headers = [
                'From: ETL System <noreply@example.com>',
                'Content-Type: text/html; charset=UTF-8',
                'X-Priority: ' . $this->getPriorityLevel($notification['priority'])
            ];
            
            $success = true;
            foreach ($this->config['email_recipients'] as $recipient) {
                $result = mail($recipient, $subject, $body, implode("\r\n", $headers));
                $success = $success && $result;
            }
            
            return $success;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки email', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Отправка Slack уведомления
     * 
     * @param array $notification Данные уведомления
     * @return bool Успешность отправки
     */
    private function sendSlackNotification(array $notification): bool
    {
        try {
            $payload = [
                'text' => $notification['title'],
                'attachments' => [
                    [
                        'color' => $this->getSlackColor($notification['type']),
                        'fields' => [
                            [
                                'title' => 'Message',
                                'value' => $notification['message'],
                                'short' => false
                            ]
                        ],
                        'footer' => 'ETL Monitoring System',
                        'ts' => time()
                    ]
                ]
            ];
            
            $ch = curl_init($this->config['slack_webhook_url']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки Slack уведомления', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Отправка Telegram уведомления
     * 
     * @param array $notification Данные уведомления
     * @return bool Успешность отправки
     */
    private function sendTelegramNotification(array $notification): bool
    {
        try {
            $message = "*{$notification['title']}*\n\n{$notification['message']}";
            
            $url = "https://api.telegram.org/bot{$this->config['telegram_bot_token']}/sendMessage";
            $data = [
                'chat_id' => $this->config['telegram_chat_id'],
                'text' => $message,
                'parse_mode' => 'Markdown'
            ];
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return $httpCode === 200;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки Telegram уведомления', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Запись уведомления в базу данных
     * 
     * @param string $alertKey Ключ алерта
     * @param string $type Тип уведомления
     * @param string $message Сообщение
     */
    private function recordNotification(string $alertKey, string $type, string $message): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_notifications 
                (alert_key, notification_type, message, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$alertKey, $type, $message]);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка записи уведомления в БД', [
                'alert_key' => $alertKey,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Форматирование сообщения об ошибке
     */
    private function formatErrorMessage(string $sessionId, string $taskId, string $taskType, string $errorMessage): string
    {
        return "ETL задача завершилась с ошибкой:\n\n" .
               "• Тип задачи: {$taskType}\n" .
               "• ID задачи: {$taskId}\n" .
               "• ID сессии: {$sessionId}\n" .
               "• Ошибка: {$errorMessage}\n" .
               "• Время: " . date('Y-m-d H:i:s');
    }
    
    /**
     * Форматирование сообщения о производительности
     */
    private function formatPerformanceMessage(string $sessionId, string $taskId, string $taskType, int $runningSeconds): string
    {
        $minutes = round($runningSeconds / 60, 1);
        
        return "ETL задача выполняется дольше обычного:\n\n" .
               "• Тип задачи: {$taskType}\n" .
               "• ID задачи: {$taskId}\n" .
               "• ID сессии: {$sessionId}\n" .
               "• Время выполнения: {$minutes} минут\n" .
               "• Статус: выполняется";
    }
    
    /**
     * Форматирование сообщения об успехе
     */
    private function formatSuccessMessage(string $sessionId, string $taskId, string $taskType, array $results): string
    {
        $processed = $results['records_processed'] ?? 0;
        $duration = $results['duration_seconds'] ?? 0;
        
        return "ETL задача успешно завершена:\n\n" .
               "• Тип задачи: {$taskType}\n" .
               "• ID задачи: {$taskId}\n" .
               "• Обработано записей: {$processed}\n" .
               "• Время выполнения: " . round($duration / 60, 1) . " минут";
    }
    
    /**
     * Форматирование системного алерта
     */
    private function formatSystemAlert(string $alertType, string $message, array $context): string
    {
        $formatted = "Системный алерт ETL:\n\n" .
                    "• Тип: {$alertType}\n" .
                    "• Сообщение: {$message}\n";
        
        if (!empty($context)) {
            $formatted .= "• Контекст: " . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n";
        }
        
        $formatted .= "• Время: " . date('Y-m-d H:i:s');
        
        return $formatted;
    }
    
    /**
     * Генерация данных для еженедельного отчета
     */
    private function generateWeeklyReportData(): array
    {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total_tasks,
                COUNT(CASE WHEN status = 'success' THEN 1 END) as successful_tasks,
                COUNT(CASE WHEN status = 'error' THEN 1 END) as failed_tasks,
                ROUND(AVG(duration_seconds), 2) as avg_duration,
                SUM(records_processed) as total_records_processed
            FROM etl_monitoring_sessions 
            WHERE started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Форматирование еженедельного отчета
     */
    private function formatWeeklyReport(array $data): string
    {
        $successRate = $data['total_tasks'] > 0 ? 
            round(($data['successful_tasks'] / $data['total_tasks']) * 100, 1) : 0;
        
        return "Еженедельный отчет ETL системы:\n\n" .
               "• Всего задач: {$data['total_tasks']}\n" .
               "• Успешных: {$data['successful_tasks']}\n" .
               "• С ошибками: {$data['failed_tasks']}\n" .
               "• Процент успеха: {$successRate}%\n" .
               "• Среднее время выполнения: " . round($data['avg_duration'] / 60, 1) . " минут\n" .
               "• Обработано записей: " . number_format($data['total_records_processed']);
    }
    
    /**
     * Форматирование тела email
     */
    private function formatEmailBody(array $notification): string
    {
        $color = $this->getEmailColor($notification['type']);
        
        return "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <div style='background-color: {$color}; color: white; padding: 10px; border-radius: 5px;'>
                <h2>{$notification['title']}</h2>
            </div>
            <div style='padding: 20px; background-color: #f9f9f9; margin-top: 10px;'>
                <pre style='white-space: pre-wrap; font-family: monospace;'>{$notification['message']}</pre>
            </div>
            <div style='margin-top: 20px; font-size: 12px; color: #666;'>
                Отправлено системой мониторинга ETL
            </div>
        </body>
        </html>";
    }
    
    /**
     * Получение цвета для Slack в зависимости от типа уведомления
     */
    private function getSlackColor(string $type): string
    {
        return match($type) {
            'error', 'system' => 'danger',
            'warning' => 'warning',
            'success' => 'good',
            default => '#36a64f'
        };
    }
    
    /**
     * Получение цвета для email в зависимости от типа уведомления
     */
    private function getEmailColor(string $type): string
    {
        return match($type) {
            'error', 'system' => '#dc3545',
            'warning' => '#ffc107',
            'success' => '#28a745',
            default => '#007bff'
        };
    }
    
    /**
     * Получение уровня приоритета для email
     */
    private function getPriorityLevel(string $priority): string
    {
        return match($priority) {
            'high' => '1',
            'medium' => '3',
            'low' => '5',
            default => '3'
        };
    }
    
    /**
     * Проверка, является ли задача критической
     */
    private function isCriticalTask(string $taskType): bool
    {
        $criticalTasks = ['full_etl', 'master_data_sync', 'critical_update'];
        return in_array($taskType, $criticalTasks);
    }
    
    /**
     * Логирование операций уведомлений
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES ('notifications', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $level, 
                $message, 
                !empty($context) ? json_encode($context) : null
            ]);
        } catch (Exception $e) {
            error_log("[ETL Notifications] Failed to log: " . $e->getMessage());
        }
    }
}