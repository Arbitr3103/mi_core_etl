<?php

namespace MDM\ETL\Monitoring;

use Exception;
use PDO;

/**
 * Сервис уведомлений
 * 
 * Отправляет уведомления по email, в логи и другими способами
 */
class NotificationService
{
    private PDO $pdo;
    private array $config;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'enabled' => true,
            'email_enabled' => true,
            'log_enabled' => true,
            'webhook_enabled' => false,
            'default_email' => '',
            'smtp_host' => '',
            'smtp_port' => 587,
            'smtp_username' => '',
            'smtp_password' => '',
            'smtp_encryption' => 'tls',
            'from_email' => 'noreply@mdm-system.local',
            'from_name' => 'MDM ETL System',
            'webhook_url' => '',
            'webhook_timeout' => 10,
            'max_retry_attempts' => 3,
            'retry_delay_seconds' => 60
        ], $config);
    }
    
    /**
     * Отправка уведомления
     * 
     * @param array $notification Данные уведомления
     * @return bool Успешность отправки
     */
    public function sendNotification(array $notification): bool
    {
        if (!$this->config['enabled']) {
            $this->log('DEBUG', 'Уведомления отключены в конфигурации');
            return false;
        }
        
        try {
            $notification = $this->validateAndNormalizeNotification($notification);
            
            $results = [];
            
            // Отправка по email
            if ($this->config['email_enabled'] && !empty($this->getNotificationEmail())) {
                $results['email'] = $this->sendEmailNotification($notification);
            }
            
            // Логирование уведомления
            if ($this->config['log_enabled']) {
                $results['log'] = $this->logNotification($notification);
            }
            
            // Отправка webhook
            if ($this->config['webhook_enabled'] && !empty($this->config['webhook_url'])) {
                $results['webhook'] = $this->sendWebhookNotification($notification);
            }
            
            // Сохранение в базу данных
            $this->saveNotificationRecord($notification, $results);
            
            $success = !empty(array_filter($results));
            
            $this->log('INFO', 'Уведомление обработано', [
                'type' => $notification['type'],
                'success' => $success,
                'methods' => array_keys(array_filter($results))
            ]);
            
            return $success;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки уведомления', [
                'error' => $e->getMessage(),
                'notification_type' => $notification['type'] ?? 'unknown'
            ]);
            return false;
        }
    }
    
    /**
     * Валидация и нормализация данных уведомления
     * 
     * @param array $notification Данные уведомления
     * @return array Нормализованные данные
     */
    private function validateAndNormalizeNotification(array $notification): array
    {
        $required = ['type', 'subject', 'message'];
        
        foreach ($required as $field) {
            if (empty($notification[$field])) {
                throw new Exception("Обязательное поле '$field' отсутствует в уведомлении");
            }
        }
        
        return array_merge([
            'priority' => 'medium',
            'source' => 'system',
            'data' => [],
            'created_at' => date('Y-m-d H:i:s')
        ], $notification);
    }
    
    /**
     * Получение email адреса для уведомлений
     * 
     * @return string Email адрес
     */
    private function getNotificationEmail(): string
    {
        // Приоритет: переменная окружения > конфигурация > база данных
        $email = $_ENV['NOTIFICATION_EMAIL'] ?? 
                 getenv('NOTIFICATION_EMAIL') ?? 
                 $this->config['default_email'];
        
        if (empty($email)) {
            // Пытаемся получить из конфигурации ETL
            try {
                $stmt = $this->pdo->prepare("
                    SELECT config_value 
                    FROM etl_config 
                    WHERE source = 'scheduler' AND config_key = 'activity_notification_email'
                ");
                $stmt->execute();
                $email = $stmt->fetchColumn() ?: '';
            } catch (Exception $e) {
                // Игнорируем ошибки получения из БД
            }
        }
        
        return $email;
    }
    
    /**
     * Отправка уведомления по email
     * 
     * @param array $notification Данные уведомления
     * @return bool Успешность отправки
     */
    private function sendEmailNotification(array $notification): bool
    {
        try {
            $to = $this->getNotificationEmail();
            
            if (empty($to)) {
                $this->log('WARNING', 'Email для уведомлений не настроен');
                return false;
            }
            
            $subject = $this->buildEmailSubject($notification);
            $message = $this->buildEmailMessage($notification);
            $headers = $this->buildEmailHeaders();
            
            // Используем простую отправку через mail() или PHPMailer если настроен SMTP
            if (!empty($this->config['smtp_host'])) {
                return $this->sendSMTPEmail($to, $subject, $message);
            } else {
                return mail($to, $subject, $message, $headers);
            }
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки email уведомления', [
                'error' => $e->getMessage(),
                'to' => $to ?? 'unknown'
            ]);
            return false;
        }
    }
    
    /**
     * Построение заголовка email
     * 
     * @param array $notification Данные уведомления
     * @return string Заголовок
     */
    private function buildEmailSubject(array $notification): string
    {
        $priority = strtoupper($notification['priority']);
        $priorityPrefix = $priority === 'HIGH' ? '[URGENT] ' : 
                         ($priority === 'MEDIUM' ? '[ALERT] ' : '');
        
        return $priorityPrefix . $notification['subject'];
    }
    
    /**
     * Построение тела email сообщения
     * 
     * @param array $notification Данные уведомления
     * @return string Тело сообщения
     */
    private function buildEmailMessage(array $notification): string
    {
        $message = $notification['message'] . "\n\n";
        
        $message .= "---\n";
        $message .= "Детали уведомления:\n";
        $message .= "• Тип: {$notification['type']}\n";
        $message .= "• Приоритет: {$notification['priority']}\n";
        $message .= "• Источник: {$notification['source']}\n";
        $message .= "• Время: {$notification['created_at']}\n";
        
        if (!empty($notification['data'])) {
            $message .= "• Дополнительные данные: " . json_encode($notification['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        
        $message .= "\n---\n";
        $message .= "Это автоматическое уведомление от системы MDM ETL.\n";
        $message .= "Для отключения уведомлений обратитесь к администратору системы.\n";
        
        return $message;
    }
    
    /**
     * Построение заголовков email
     * 
     * @return string Заголовки
     */
    private function buildEmailHeaders(): string
    {
        $headers = [];
        $headers[] = "From: {$this->config['from_name']} <{$this->config['from_email']}>";
        $headers[] = "Reply-To: {$this->config['from_email']}";
        $headers[] = "X-Mailer: MDM ETL System";
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: 8bit";
        
        return implode("\r\n", $headers);
    }
    
    /**
     * Отправка email через SMTP
     * 
     * @param string $to Получатель
     * @param string $subject Заголовок
     * @param string $message Сообщение
     * @return bool Успешность отправки
     */
    private function sendSMTPEmail(string $to, string $subject, string $message): bool
    {
        // Простая реализация SMTP отправки
        // В реальном проекте лучше использовать PHPMailer или SwiftMailer
        
        try {
            $smtp = fsockopen($this->config['smtp_host'], $this->config['smtp_port'], $errno, $errstr, 10);
            
            if (!$smtp) {
                throw new Exception("Не удалось подключиться к SMTP серверу: $errstr ($errno)");
            }
            
            // Простая SMTP сессия
            $this->smtpCommand($smtp, null, '220'); // Приветствие
            $this->smtpCommand($smtp, "EHLO " . gethostname(), '250');
            
            if ($this->config['smtp_encryption'] === 'tls') {
                $this->smtpCommand($smtp, "STARTTLS", '220');
                stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->smtpCommand($smtp, "EHLO " . gethostname(), '250');
            }
            
            if (!empty($this->config['smtp_username'])) {
                $this->smtpCommand($smtp, "AUTH LOGIN", '334');
                $this->smtpCommand($smtp, base64_encode($this->config['smtp_username']), '334');
                $this->smtpCommand($smtp, base64_encode($this->config['smtp_password']), '235');
            }
            
            $this->smtpCommand($smtp, "MAIL FROM: <{$this->config['from_email']}>", '250');
            $this->smtpCommand($smtp, "RCPT TO: <$to>", '250');
            $this->smtpCommand($smtp, "DATA", '354');
            
            $emailData = "Subject: $subject\r\n";
            $emailData .= $this->buildEmailHeaders() . "\r\n\r\n";
            $emailData .= $message . "\r\n.\r\n";
            
            $this->smtpCommand($smtp, $emailData, '250');
            $this->smtpCommand($smtp, "QUIT", '221');
            
            fclose($smtp);
            
            return true;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка SMTP отправки', [
                'error' => $e->getMessage(),
                'to' => $to
            ]);
            
            if (isset($smtp) && is_resource($smtp)) {
                fclose($smtp);
            }
            
            return false;
        }
    }
    
    /**
     * Выполнение SMTP команды
     * 
     * @param resource $smtp SMTP соединение
     * @param string|null $command Команда
     * @param string $expectedCode Ожидаемый код ответа
     */
    private function smtpCommand($smtp, ?string $command, string $expectedCode): void
    {
        if ($command !== null) {
            fwrite($smtp, $command . "\r\n");
        }
        
        $response = fgets($smtp, 512);
        
        if (strpos($response, $expectedCode) !== 0) {
            throw new Exception("SMTP ошибка: $response");
        }
    }
    
    /**
     * Логирование уведомления
     * 
     * @param array $notification Данные уведомления
     * @return bool Успешность логирования
     */
    private function logNotification(array $notification): bool
    {
        try {
            $logLevel = $this->getLogLevelForPriority($notification['priority']);
            $logMessage = "[{$notification['type']}] {$notification['subject']}";
            
            $this->log($logLevel, $logMessage, [
                'notification_data' => $notification
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Ошибка логирования уведомления: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Получение уровня лога для приоритета
     * 
     * @param string $priority Приоритет
     * @return string Уровень лога
     */
    private function getLogLevelForPriority(string $priority): string
    {
        switch (strtolower($priority)) {
            case 'high':
                return 'ERROR';
            case 'medium':
                return 'WARNING';
            case 'low':
            default:
                return 'INFO';
        }
    }
    
    /**
     * Отправка webhook уведомления
     * 
     * @param array $notification Данные уведомления
     * @return bool Успешность отправки
     */
    private function sendWebhookNotification(array $notification): bool
    {
        try {
            $webhookData = [
                'type' => $notification['type'],
                'subject' => $notification['subject'],
                'message' => $notification['message'],
                'priority' => $notification['priority'],
                'source' => $notification['source'],
                'timestamp' => $notification['created_at'],
                'data' => $notification['data']
            ];
            
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $this->config['webhook_url'],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($webhookData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->config['webhook_timeout'],
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'User-Agent: MDM-ETL-Notifications/1.0'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            curl_close($ch);
            
            if ($response === false) {
                throw new Exception("cURL ошибка: $error");
            }
            
            if ($httpCode < 200 || $httpCode >= 300) {
                throw new Exception("HTTP ошибка: $httpCode");
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка отправки webhook уведомления', [
                'error' => $e->getMessage(),
                'webhook_url' => $this->config['webhook_url']
            ]);
            return false;
        }
    }
    
    /**
     * Сохранение записи уведомления в базу данных
     * 
     * @param array $notification Данные уведомления
     * @param array $results Результаты отправки
     */
    private function saveNotificationRecord(array $notification, array $results): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_notifications 
                (type, subject, message, priority, source, data, 
                 email_sent, log_sent, webhook_sent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $notification['type'],
                $notification['subject'],
                $notification['message'],
                $notification['priority'],
                $notification['source'],
                json_encode($notification['data'], JSON_UNESCAPED_UNICODE),
                $results['email'] ?? false,
                $results['log'] ?? false,
                $results['webhook'] ?? false
            ]);
            
        } catch (Exception $e) {
            // Создаем таблицу если не существует
            $this->createNotificationsTableIfNotExists();
            
            // Повторяем попытку
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO etl_notifications 
                    (type, subject, message, priority, source, data, 
                     email_sent, log_sent, webhook_sent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $notification['type'],
                    $notification['subject'],
                    $notification['message'],
                    $notification['priority'],
                    $notification['source'],
                    json_encode($notification['data'], JSON_UNESCAPED_UNICODE),
                    $results['email'] ?? false,
                    $results['log'] ?? false,
                    $results['webhook'] ?? false
                ]);
            } catch (Exception $e2) {
                $this->log('ERROR', 'Ошибка сохранения записи уведомления', [
                    'error' => $e2->getMessage()
                ]);
            }
        }
    }
    
    /**
     * Создание таблицы уведомлений если не существует
     */
    private function createNotificationsTableIfNotExists(): void
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS etl_notifications (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    type VARCHAR(50) NOT NULL,
                    subject VARCHAR(255) NOT NULL,
                    message TEXT NOT NULL,
                    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
                    source VARCHAR(50) NOT NULL,
                    data JSON NULL,
                    email_sent BOOLEAN DEFAULT FALSE,
                    log_sent BOOLEAN DEFAULT FALSE,
                    webhook_sent BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    
                    INDEX idx_notifications_type (type),
                    INDEX idx_notifications_priority (priority),
                    INDEX idx_notifications_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Записи отправленных уведомлений ETL системы'
            ");
        } catch (Exception $e) {
            // Игнорируем ошибки создания таблицы
        }
    }
    
    /**
     * Получение истории уведомлений
     * 
     * @param array $filters Фильтры
     * @return array История уведомлений
     */
    public function getNotificationHistory(array $filters = []): array
    {
        try {
            $sql = "SELECT * FROM etl_notifications WHERE 1=1";
            $params = [];
            
            if (!empty($filters['type'])) {
                $sql .= " AND type = ?";
                $params[] = $filters['type'];
            }
            
            if (!empty($filters['priority'])) {
                $sql .= " AND priority = ?";
                $params[] = $filters['priority'];
            }
            
            if (!empty($filters['source'])) {
                $sql .= " AND source = ?";
                $params[] = $filters['source'];
            }
            
            if (!empty($filters['date_from'])) {
                $sql .= " AND created_at >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $sql .= " AND created_at <= ?";
                $params[] = $filters['date_to'];
            }
            
            $sql .= " ORDER BY created_at DESC";
            
            if (!empty($filters['limit'])) {
                $sql .= " LIMIT ?";
                $params[] = (int)$filters['limit'];
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка получения истории уведомлений', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            return [];
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
        
        error_log("[Notification Service] [$level] $fullMessage");
        
        // Сохраняем в БД
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO etl_logs (source, level, message, context, created_at) 
                VALUES ('notification_service', ?, ?, ?, NOW())
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