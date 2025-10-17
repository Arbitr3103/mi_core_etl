<?php

namespace Replenishment;

use PDO;
use Exception;
use DateTime;
use DateInterval;

/**
 * ReplenishmentScheduler Class
 * 
 * Manages scheduling and automation for replenishment calculations.
 * Handles weekly calculation scheduling, execution logging, and email notifications.
 */
class ReplenishmentScheduler
{
    private PDO $pdo;
    private ReplenishmentRecommender $recommender;
    private array $config;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = array_merge([
            'schedule_enabled' => true,
            'weekly_day' => 'monday',           // Day of week for weekly calculation
            'weekly_time' => '06:00',           // Time for weekly calculation
            'email_enabled' => true,
            'email_recipients' => [],           // Array of email addresses
            'notification_email' => '',         // Single email address
            'report_format' => 'detailed',      // 'summary' or 'detailed'
            'max_execution_time' => 3600,      // 1 hour max execution
            'retry_attempts' => 3,
            'retry_delay' => 300,               // 5 minutes between retries
            'cleanup_days' => 30,               // Keep logs for 30 days
            'debug' => false
        ], $config);
        
        // Initialize recommender
        $this->recommender = new ReplenishmentRecommender($pdo, $config);
        
        // Create scheduler tables if needed
        $this->initializeSchedulerTables();
    }
    
    /**
     * Execute weekly calculation
     * 
     * @param bool $force Force execution even if not scheduled
     * @return array Execution results
     * @throws Exception If execution fails
     */
    public function executeWeeklyCalculation(bool $force = false): array
    {
        $executionId = $this->startExecution('weekly_calculation');
        $startTime = microtime(true);
        
        try {
            $this->log("Starting weekly replenishment calculation", ['force' => $force]);
            
            // Check if execution is allowed
            if (!$force && !$this->isExecutionAllowed()) {
                throw new Exception("Weekly calculation is not scheduled for execution at this time");
            }
            
            // Set execution time limit
            set_time_limit($this->config['max_execution_time']);
            
            // Generate weekly report
            $report = $this->recommender->generateWeeklyReport();
            
            // Send email notifications if enabled
            $emailResults = [];
            if ($this->config['email_enabled']) {
                $emailResults = $this->sendWeeklyReportEmail($report);
            }
            
            // Calculate execution metrics
            $executionTime = round(microtime(true) - $startTime, 2);
            $memoryUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
            
            // Complete execution log
            $results = [
                'status' => 'success',
                'execution_time' => $executionTime,
                'memory_usage_mb' => $memoryUsage,
                'recommendations_count' => count($report['all_recommendations']),
                'actionable_count' => count($report['actionable_recommendations']),
                'email_sent' => !empty($emailResults['success']),
                'email_recipients' => $emailResults['recipients'] ?? [],
                'report_data' => $report
            ];
            
            $this->completeExecution($executionId, $results);
            
            $this->log("Weekly calculation completed successfully", [
                'execution_time' => $executionTime,
                'recommendations' => count($report['all_recommendations']),
                'actionable' => count($report['actionable_recommendations'])
            ]);
            
            return $results;
            
        } catch (Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);
            $memoryUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
            
            $results = [
                'status' => 'error',
                'execution_time' => $executionTime,
                'memory_usage_mb' => $memoryUsage,
                'error_message' => $e->getMessage(),
                'recommendations_count' => 0,
                'actionable_count' => 0,
                'email_sent' => false
            ];
            
            $this->completeExecution($executionId, $results);
            
            $this->log("Weekly calculation failed", [
                'error' => $e->getMessage(),
                'execution_time' => $executionTime
            ]);
            
            // Send error notification
            if ($this->config['email_enabled']) {
                $this->sendErrorNotification($e->getMessage(), $results);
            }
            
            throw $e;
        }
    }
    
    /**
     * Check if execution is allowed based on schedule
     * 
     * @return bool True if execution is allowed
     */
    public function isExecutionAllowed(): bool
    {
        if (!$this->config['schedule_enabled']) {
            return false;
        }
        
        $now = new DateTime();
        $currentDay = strtolower($now->format('l')); // Full day name in lowercase
        $currentTime = $now->format('H:i');
        
        // Check if today is the scheduled day
        if ($currentDay !== strtolower($this->config['weekly_day'])) {
            return false;
        }
        
        // Check if current time is within execution window (±30 minutes)
        $scheduledTime = DateTime::createFromFormat('H:i', $this->config['weekly_time']);
        $windowStart = clone $scheduledTime;
        $windowStart->sub(new DateInterval('PT30M'));
        $windowEnd = clone $scheduledTime;
        $windowEnd->add(new DateInterval('PT30M'));
        
        $currentDateTime = DateTime::createFromFormat('H:i', $currentTime);
        
        return $currentDateTime >= $windowStart && $currentDateTime <= $windowEnd;
    }
    
    /**
     * Get next scheduled execution time
     * 
     * @return DateTime Next execution time
     */
    public function getNextExecutionTime(): DateTime
    {
        $now = new DateTime();
        $scheduledDay = strtolower($this->config['weekly_day']);
        $scheduledTime = $this->config['weekly_time'];
        
        // Find next occurrence of scheduled day
        $nextExecution = new DateTime();
        
        // If today is the scheduled day and time hasn't passed yet
        $currentDay = strtolower($now->format('l'));
        $currentTime = $now->format('H:i');
        
        if ($currentDay === $scheduledDay && $currentTime < $scheduledTime) {
            // Today at scheduled time
            $nextExecution->setTime(
                ...explode(':', $scheduledTime)
            );
        } else {
            // Next week on scheduled day
            $nextExecution->modify("next $scheduledDay");
            $nextExecution->setTime(
                ...explode(':', $scheduledTime)
            );
        }
        
        return $nextExecution;
    }
    
    /**
     * Send weekly report email
     * 
     * @param array $report Report data
     * @return array Email sending results
     */
    private function sendWeeklyReportEmail(array $report): array
    {
        try {
            $recipients = $this->getEmailRecipients();
            
            if (empty($recipients)) {
                $this->log("No email recipients configured for weekly report");
                return ['success' => false, 'error' => 'No recipients configured'];
            }
            
            $subject = $this->buildEmailSubject($report);
            $message = $this->buildEmailMessage($report);
            $headers = $this->buildEmailHeaders();
            
            $results = [
                'success' => true,
                'recipients' => [],
                'errors' => []
            ];
            
            foreach ($recipients as $recipient) {
                try {
                    $sent = mail($recipient, $subject, $message, $headers);
                    
                    if ($sent) {
                        $results['recipients'][] = $recipient;
                        $this->log("Weekly report sent to: $recipient");
                    } else {
                        $results['errors'][] = "Failed to send to: $recipient";
                        $this->log("Failed to send weekly report to: $recipient");
                    }
                } catch (Exception $e) {
                    $results['errors'][] = "Error sending to $recipient: " . $e->getMessage();
                    $this->log("Error sending weekly report to $recipient: " . $e->getMessage());
                }
            }
            
            $results['success'] = !empty($results['recipients']);
            
            return $results;
            
        } catch (Exception $e) {
            $this->log("Error sending weekly report email: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Send error notification email
     * 
     * @param string $errorMessage Error message
     * @param array $executionResults Execution results
     */
    private function sendErrorNotification(string $errorMessage, array $executionResults): void
    {
        try {
            $recipients = $this->getEmailRecipients();
            
            if (empty($recipients)) {
                return;
            }
            
            $subject = "[ERROR] Weekly Replenishment Calculation Failed - " . date('Y-m-d H:i');
            $message = $this->buildErrorEmailMessage($errorMessage, $executionResults);
            $headers = $this->buildEmailHeaders();
            
            foreach ($recipients as $recipient) {
                try {
                    mail($recipient, $subject, $message, $headers);
                    $this->log("Error notification sent to: $recipient");
                } catch (Exception $e) {
                    $this->log("Failed to send error notification to $recipient: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            $this->log("Error sending error notification: " . $e->getMessage());
        }
    }
    
    /**
     * Get email recipients list
     * 
     * @return array List of email addresses
     */
    private function getEmailRecipients(): array
    {
        $recipients = [];
        
        // Add configured recipients array
        if (!empty($this->config['email_recipients']) && is_array($this->config['email_recipients'])) {
            $recipients = array_merge($recipients, $this->config['email_recipients']);
        }
        
        // Add single notification email
        if (!empty($this->config['notification_email'])) {
            $recipients[] = $this->config['notification_email'];
        }
        
        // Try to get from environment variables
        $envEmail = $_ENV['REPLENISHMENT_NOTIFICATION_EMAIL'] ?? getenv('REPLENISHMENT_NOTIFICATION_EMAIL');
        if ($envEmail) {
            $recipients[] = $envEmail;
        }
        
        // Try to get from database configuration
        try {
            $stmt = $this->pdo->prepare("
                SELECT parameter_value 
                FROM replenishment_config 
                WHERE parameter_name = 'notification_email'
            ");
            $stmt->execute();
            $dbEmail = $stmt->fetchColumn();
            
            if ($dbEmail) {
                $recipients[] = $dbEmail;
            }
        } catch (Exception $e) {
            // Ignore database errors
        }
        
        // Remove duplicates and empty values
        $recipients = array_unique(array_filter($recipients));
        
        return $recipients;
    }
    
    /**
     * Build email subject for weekly report
     * 
     * @param array $report Report data
     * @return string Email subject
     */
    private function buildEmailSubject(array $report): string
    {
        $actionableCount = count($report['actionable_recommendations']);
        $totalCount = count($report['all_recommendations']);
        
        return "Weekly Replenishment Report - {$actionableCount} actionable recommendations ({$totalCount} total) - " . date('Y-m-d');
    }
    
    /**
     * Build email message for weekly report
     * 
     * @param array $report Report data
     * @return string Email message
     */
    private function buildEmailMessage(array $report): string
    {
        $message = "Weekly Replenishment Recommendations Report\n";
        $message .= "Generated: " . $report['generation_time'] . "\n";
        $message .= str_repeat("=", 50) . "\n\n";
        
        // Summary
        $summary = $report['summary'];
        $message .= "SUMMARY:\n";
        $message .= "• Total products analyzed: {$summary['total_products_analyzed']}\n";
        $message .= "• Actionable recommendations: {$summary['actionable_recommendations']}\n";
        $message .= "• Products with sufficient stock: {$summary['stock_sufficient_products']}\n";
        $message .= "• Total recommended quantity: {$summary['total_recommended_quantity']}\n";
        $message .= "• Average ADS: {$summary['average_ads']}\n";
        $message .= "• Actionable percentage: {$summary['actionable_percentage']}%\n\n";
        
        // Top recommendations
        if ($this->config['report_format'] === 'detailed') {
            $message .= "TOP RECOMMENDATIONS:\n";
            $message .= str_repeat("-", 30) . "\n";
            
            $topRecommendations = array_slice($report['actionable_recommendations'], 0, 20);
            
            foreach ($topRecommendations as $rec) {
                $message .= sprintf(
                    "• %s (ID: %d)\n  ADS: %.2f | Current: %d | Target: %d | Recommended: %d\n\n",
                    $rec['product_name'],
                    $rec['product_id'],
                    $rec['ads'],
                    $rec['current_stock'],
                    $rec['target_stock'],
                    $rec['recommended_quantity']
                );
            }
            
            if (count($report['actionable_recommendations']) > 20) {
                $remaining = count($report['actionable_recommendations']) - 20;
                $message .= "... and {$remaining} more recommendations\n\n";
            }
        }
        
        // Footer
        $message .= str_repeat("=", 50) . "\n";
        $message .= "This is an automated report from the Replenishment System.\n";
        $message .= "For detailed analysis, please access the replenishment dashboard.\n";
        $message .= "Report generated at: " . date('Y-m-d H:i:s') . "\n";
        
        return $message;
    }
    
    /**
     * Build error email message
     * 
     * @param string $errorMessage Error message
     * @param array $executionResults Execution results
     * @return string Email message
     */
    private function buildErrorEmailMessage(string $errorMessage, array $executionResults): string
    {
        $message = "Weekly Replenishment Calculation Error Report\n";
        $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
        $message .= str_repeat("=", 50) . "\n\n";
        
        $message .= "ERROR DETAILS:\n";
        $message .= "• Error: $errorMessage\n";
        $message .= "• Execution time: {$executionResults['execution_time']} seconds\n";
        $message .= "• Memory usage: {$executionResults['memory_usage_mb']} MB\n\n";
        
        $message .= "SYSTEM STATUS:\n";
        $message .= "• Status: {$executionResults['status']}\n";
        $message .= "• Recommendations generated: {$executionResults['recommendations_count']}\n";
        $message .= "• Actionable recommendations: {$executionResults['actionable_count']}\n\n";
        
        $message .= "NEXT STEPS:\n";
        $message .= "1. Check system logs for detailed error information\n";
        $message .= "2. Verify database connectivity and data integrity\n";
        $message .= "3. Consider running manual calculation to diagnose issues\n";
        $message .= "4. Contact system administrator if problem persists\n\n";
        
        $message .= str_repeat("=", 50) . "\n";
        $message .= "This is an automated error notification from the Replenishment System.\n";
        $message .= "Please investigate and resolve the issue promptly.\n";
        
        return $message;
    }
    
    /**
     * Build email headers
     * 
     * @return string Email headers
     */
    private function buildEmailHeaders(): string
    {
        $headers = [];
        $headers[] = "From: Replenishment System <noreply@replenishment-system.local>";
        $headers[] = "Reply-To: noreply@replenishment-system.local";
        $headers[] = "X-Mailer: Replenishment Scheduler";
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        $headers[] = "Content-Transfer-Encoding: 8bit";
        
        return implode("\r\n", $headers);
    }
    
    /**
     * Start execution log
     * 
     * @param string $executionType Type of execution
     * @return int Execution ID
     */
    private function startExecution(string $executionType): int
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO replenishment_scheduler_log (
                    execution_type, status, started_at
                ) VALUES (?, 'running', NOW())
            ");
            $stmt->execute([$executionType]);
            
            return $this->pdo->lastInsertId();
            
        } catch (Exception $e) {
            $this->log("Warning: Could not create execution log: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Complete execution log
     * 
     * @param int $executionId Execution ID
     * @param array $results Execution results
     */
    private function completeExecution(int $executionId, array $results): void
    {
        if ($executionId === 0) {
            return;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE replenishment_scheduler_log 
                SET status = :status,
                    execution_time_seconds = :execution_time,
                    memory_usage_mb = :memory_usage,
                    recommendations_count = :recommendations_count,
                    actionable_count = :actionable_count,
                    email_sent = :email_sent,
                    error_message = :error_message,
                    results_data = :results_data,
                    completed_at = NOW()
                WHERE id = :execution_id
            ");
            
            $stmt->execute([
                'execution_id' => $executionId,
                'status' => $results['status'],
                'execution_time' => $results['execution_time'],
                'memory_usage' => $results['memory_usage_mb'],
                'recommendations_count' => $results['recommendations_count'],
                'actionable_count' => $results['actionable_count'],
                'email_sent' => $results['email_sent'] ? 1 : 0,
                'error_message' => $results['error_message'] ?? null,
                'results_data' => json_encode($results, JSON_UNESCAPED_UNICODE)
            ]);
            
        } catch (Exception $e) {
            $this->log("Warning: Could not update execution log: " . $e->getMessage());
        }
    }
    
    /**
     * Get execution history
     * 
     * @param int $limit Number of records to return
     * @return array Execution history
     */
    public function getExecutionHistory(int $limit = 50): array
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    id,
                    execution_type,
                    status,
                    started_at,
                    completed_at,
                    execution_time_seconds,
                    memory_usage_mb,
                    recommendations_count,
                    actionable_count,
                    email_sent,
                    error_message,
                    TIMESTAMPDIFF(SECOND, started_at, completed_at) as actual_duration
                FROM replenishment_scheduler_log
                ORDER BY started_at DESC
                LIMIT ?
            ");
            
            $stmt->execute([$limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->log("Error getting execution history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get scheduler status
     * 
     * @return array Scheduler status information
     */
    public function getSchedulerStatus(): array
    {
        try {
            // Get last execution
            $stmt = $this->pdo->prepare("
                SELECT * FROM replenishment_scheduler_log 
                ORDER BY started_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            $lastExecution = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get execution statistics
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_executions,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_executions,
                    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed_executions,
                    AVG(execution_time_seconds) as avg_execution_time,
                    MAX(started_at) as last_execution_time
                FROM replenishment_scheduler_log
                WHERE started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt->execute();
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'scheduler_enabled' => $this->config['schedule_enabled'],
                'weekly_schedule' => [
                    'day' => $this->config['weekly_day'],
                    'time' => $this->config['weekly_time']
                ],
                'next_execution' => $this->getNextExecutionTime()->format('Y-m-d H:i:s'),
                'execution_allowed_now' => $this->isExecutionAllowed(),
                'email_enabled' => $this->config['email_enabled'],
                'email_recipients' => $this->getEmailRecipients(),
                'last_execution' => $lastExecution,
                'statistics' => $stats
            ];
            
        } catch (Exception $e) {
            $this->log("Error getting scheduler status: " . $e->getMessage());
            return [
                'error' => $e->getMessage(),
                'scheduler_enabled' => $this->config['schedule_enabled']
            ];
        }
    }
    
    /**
     * Cleanup old execution logs
     * 
     * @param int $daysToKeep Number of days to keep logs
     * @return int Number of records deleted
     */
    public function cleanupExecutionLogs(int $daysToKeep = null): int
    {
        $daysToKeep = $daysToKeep ?? $this->config['cleanup_days'];
        
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM replenishment_scheduler_log 
                WHERE started_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            
            $deletedCount = $stmt->rowCount();
            
            $this->log("Cleaned up execution logs", [
                'days_kept' => $daysToKeep,
                'records_deleted' => $deletedCount
            ]);
            
            return $deletedCount;
            
        } catch (Exception $e) {
            $this->log("Error cleaning up execution logs: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Initialize scheduler database tables
     */
    private function initializeSchedulerTables(): void
    {
        try {
            // Create scheduler log table
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS replenishment_scheduler_log (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    execution_type VARCHAR(50) NOT NULL,
                    status ENUM('running', 'success', 'error') NOT NULL,
                    started_at TIMESTAMP NOT NULL,
                    completed_at TIMESTAMP NULL,
                    execution_time_seconds DECIMAL(10,2) NULL,
                    memory_usage_mb DECIMAL(10,2) NULL,
                    recommendations_count INT DEFAULT 0,
                    actionable_count INT DEFAULT 0,
                    email_sent BOOLEAN DEFAULT FALSE,
                    error_message TEXT NULL,
                    results_data JSON NULL,
                    
                    INDEX idx_scheduler_log_started (started_at),
                    INDEX idx_scheduler_log_status (status),
                    INDEX idx_scheduler_log_type (execution_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                COMMENT='Execution log for replenishment scheduler'
            ");
            
        } catch (Exception $e) {
            $this->log("Warning: Could not create scheduler tables: " . $e->getMessage());
        }
    }
    
    /**
     * Log debug messages
     * 
     * @param string $message Message to log
     * @param array $context Additional context
     */
    private function log(string $message, array $context = []): void
    {
        $fullMessage = "[ReplenishmentScheduler] " . date('Y-m-d H:i:s') . " - $message";
        
        if (!empty($context)) {
            $fullMessage .= " | Context: " . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        if ($this->config['debug']) {
            echo $fullMessage . "\n";
        }
        
        error_log($fullMessage);
    }
}
?>