#!/usr/bin/env php
<?php

/**
 * MDM Backup Scheduler
 * 
 * This script should be run via cron to execute scheduled backups
 * 
 * Usage:
 *   php bin/backup_scheduler.php [--job-id=ID] [--dry-run] [--verbose]
 * 
 * Cron example:
 *   # Run every hour to check for scheduled backups
 *   0 * * * * /usr/bin/php /path/to/mdm/bin/backup_scheduler.php
 */

require_once __DIR__ . '/../config.php';

use MDM\Services\BackupService;
use PDO;

class BackupScheduler
{
    private $db;
    private $backupService;
    private $verbose = false;
    private $dryRun = false;
    
    public function __construct()
    {
        // Initialize database connection
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME,
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        
        // Initialize backup service
        $this->backupService = new BackupService($this->db, [
            'backup_path' => BACKUP_BASE_PATH ?? '/var/backups/mdm',
            'mysqldump_path' => MYSQLDUMP_PATH ?? 'mysqldump',
            'mysql_path' => MYSQL_PATH ?? 'mysql'
        ]);
    }
    
    /**
     * Run the scheduler
     */
    public function run($options = [])
    {
        $this->verbose = $options['verbose'] ?? false;
        $this->dryRun = $options['dry-run'] ?? false;
        
        $this->log("Starting backup scheduler...");
        
        if (isset($options['job-id'])) {
            // Execute specific job
            $this->executeJob($options['job-id']);
        } else {
            // Check all scheduled jobs
            $this->checkScheduledJobs();
        }
        
        $this->log("Backup scheduler completed.");
    }
    
    /**
     * Check for scheduled jobs that need to run
     */
    private function checkScheduledJobs()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, job_name, schedule_cron, backup_type
                FROM mdm_backup_jobs 
                WHERE status = 'active' 
                AND schedule_cron IS NOT NULL 
                AND schedule_cron != ''
            ");
            $stmt->execute();
            $jobs = $stmt->fetchAll();
            
            $this->log("Found " . count($jobs) . " active scheduled jobs");
            
            foreach ($jobs as $job) {
                if ($this->shouldRunJob($job)) {
                    $this->log("Job '{$job['job_name']}' is due to run");
                    $this->executeJob($job['id']);
                } else {
                    $this->log("Job '{$job['job_name']}' is not due to run", true);
                }
            }
            
        } catch (Exception $e) {
            $this->log("Error checking scheduled jobs: " . $e->getMessage());
        }
    }
    
    /**
     * Check if job should run based on cron schedule
     */
    private function shouldRunJob($job)
    {
        $cronExpression = $job['schedule_cron'];
        
        // Parse cron expression (minute hour day month weekday)
        $cronParts = explode(' ', $cronExpression);
        if (count($cronParts) !== 5) {
            $this->log("Invalid cron expression for job '{$job['job_name']}': {$cronExpression}");
            return false;
        }
        
        $now = new DateTime();
        
        // Check if we should run based on cron schedule
        // This is a simplified cron parser - in production, use a proper cron library
        return $this->matchesCronExpression($cronParts, $now);
    }
    
    /**
     * Simple cron expression matcher
     */
    private function matchesCronExpression($cronParts, $dateTime)
    {
        $minute = (int)$dateTime->format('i');
        $hour = (int)$dateTime->format('H');
        $day = (int)$dateTime->format('d');
        $month = (int)$dateTime->format('n');
        $weekday = (int)$dateTime->format('w');
        
        $values = [$minute, $hour, $day, $month, $weekday];
        
        for ($i = 0; $i < 5; $i++) {
            $cronPart = $cronParts[$i];
            $value = $values[$i];
            
            if ($cronPart === '*') {
                continue;
            }
            
            if (strpos($cronPart, '/') !== false) {
                // Handle step values (e.g., */5)
                list($range, $step) = explode('/', $cronPart);
                if ($range === '*') {
                    if ($value % (int)$step !== 0) {
                        return false;
                    }
                }
            } elseif (strpos($cronPart, ',') !== false) {
                // Handle lists (e.g., 1,3,5)
                $allowedValues = array_map('intval', explode(',', $cronPart));
                if (!in_array($value, $allowedValues)) {
                    return false;
                }
            } elseif (strpos($cronPart, '-') !== false) {
                // Handle ranges (e.g., 1-5)
                list($start, $end) = array_map('intval', explode('-', $cronPart));
                if ($value < $start || $value > $end) {
                    return false;
                }
            } else {
                // Handle exact values
                if ((int)$cronPart !== $value) {
                    return false;
                }
            }
        }
        
        return true;
    }
    
    /**
     * Execute specific backup job
     */
    private function executeJob($jobId)
    {
        try {
            $this->log("Executing backup job ID: {$jobId}");
            
            if ($this->dryRun) {
                $this->log("DRY RUN: Would execute backup job {$jobId}");
                return;
            }
            
            // Check if job is already running
            if ($this->isJobRunning($jobId)) {
                $this->log("Job {$jobId} is already running, skipping");
                return;
            }
            
            $result = $this->backupService->executeBackup($jobId, null, 'scheduled');
            
            if ($result['success']) {
                $this->log("Backup job {$jobId} completed successfully");
                $this->log("Backup file: {$result['backup_file']}");
                $this->log("File size: " . $this->formatFileSize($result['file_size']));
                
                // Send notification if configured
                $this->sendNotification($jobId, 'success', $result);
                
            } else {
                $this->log("Backup job {$jobId} failed: {$result['error']}");
                
                // Send error notification
                $this->sendNotification($jobId, 'error', $result);
            }
            
        } catch (Exception $e) {
            $this->log("Error executing backup job {$jobId}: " . $e->getMessage());
            
            // Send error notification
            $this->sendNotification($jobId, 'error', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Check if backup job is currently running
     */
    private function isJobRunning($jobId)
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) 
            FROM mdm_backup_executions 
            WHERE job_id = ? AND status = 'running'
        ");
        $stmt->execute([$jobId]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Send notification about backup result
     */
    private function sendNotification($jobId, $type, $result)
    {
        // Get job info
        $stmt = $this->db->prepare("SELECT job_name FROM mdm_backup_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $jobName = $stmt->fetchColumn();
        
        $subject = "MDM Backup " . ($type === 'success' ? 'Completed' : 'Failed') . ": {$jobName}";
        
        if ($type === 'success') {
            $message = "Backup job '{$jobName}' completed successfully.\n\n";
            $message .= "Backup file: {$result['backup_file']}\n";
            $message .= "File size: " . $this->formatFileSize($result['file_size']) . "\n";
            $message .= "Checksum: {$result['checksum']}\n";
        } else {
            $message = "Backup job '{$jobName}' failed.\n\n";
            $message .= "Error: {$result['error']}\n";
        }
        
        $message .= "\nTime: " . date('Y-m-d H:i:s') . "\n";
        
        // Log notification (in production, send email or other notification)
        $this->log("NOTIFICATION: {$subject}");
        $this->log($message);
        
        // You can implement actual email sending here
        // mail(BACKUP_NOTIFICATION_EMAIL, $subject, $message);
    }
    
    /**
     * Format file size
     */
    private function formatFileSize($bytes)
    {
        if ($bytes === null) return 'N/A';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Log message
     */
    private function log($message, $verboseOnly = false)
    {
        if ($verboseOnly && !$this->verbose) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}";
        
        echo $logMessage . "\n";
        
        // Also log to file if configured
        if (defined('BACKUP_LOG_FILE')) {
            file_put_contents(BACKUP_LOG_FILE, $logMessage . "\n", FILE_APPEND | LOCK_EX);
        }
    }
}

// Parse command line arguments
$options = [];
$args = array_slice($argv, 1);

foreach ($args as $arg) {
    if (strpos($arg, '--') === 0) {
        $arg = substr($arg, 2);
        if (strpos($arg, '=') !== false) {
            list($key, $value) = explode('=', $arg, 2);
            $options[$key] = $value;
        } else {
            $options[$arg] = true;
        }
    }
}

// Show help
if (isset($options['help']) || isset($options['h'])) {
    echo "MDM Backup Scheduler\n\n";
    echo "Usage: php backup_scheduler.php [options]\n\n";
    echo "Options:\n";
    echo "  --job-id=ID    Execute specific backup job\n";
    echo "  --dry-run      Show what would be done without executing\n";
    echo "  --verbose      Show detailed output\n";
    echo "  --help, -h     Show this help message\n\n";
    echo "Examples:\n";
    echo "  php backup_scheduler.php --verbose\n";
    echo "  php backup_scheduler.php --job-id=1 --dry-run\n";
    exit(0);
}

// Run scheduler
try {
    $scheduler = new BackupScheduler();
    $scheduler->run($options);
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}