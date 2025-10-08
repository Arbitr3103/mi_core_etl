<?php

namespace MDM\Controllers;

use MDM\Services\BackupService;
use MDM\Security\AuthorizationService;
use Exception;

class BackupController
{
    private $backupService;
    private $authzService;
    
    public function __construct(BackupService $backupService, AuthorizationService $authzService)
    {
        $this->backupService = $backupService;
        $this->authzService = $authzService;
    }
    
    /**
     * Show backup dashboard
     */
    public function index()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'backup.view');
            
            // Get backup jobs
            $jobs = $this->backupService->getBackupJobs();
            
            // Get recent executions
            $recentExecutions = $this->backupService->getBackupExecutions(null, 20);
            
            include __DIR__ . '/../Views/backup/index.php';
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /dashboard');
        }
    }
    
    /**
     * Show backup job details
     */
    public function job()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'backup.view');
            
            $jobId = $_GET['id'] ?? null;
            if (!$jobId) {
                throw new Exception('Job ID is required');
            }
            
            $jobs = $this->backupService->getBackupJobs(['id' => $jobId]);
            $job = $jobs[0] ?? null;
            
            if (!$job) {
                throw new Exception('Backup job not found');
            }
            
            // Get executions for this job
            $executions = $this->backupService->getBackupExecutions($jobId, 50);
            
            include __DIR__ . '/../Views/backup/job.php';
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /backup');
        }
    }
    
    /**
     * Execute backup manually
     */
    public function execute()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'backup.create');
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method');
            }
            
            $jobId = $_POST['job_id'] ?? null;
            if (!$jobId) {
                throw new Exception('Job ID is required');
            }
            
            // Execute backup
            $result = $this->backupService->executeBackup($jobId, $user['id'], 'manual');
            
            if ($result['success']) {
                if ($this->isAjaxRequest()) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'Backup executed successfully',
                        'execution_id' => $result['execution_id'],
                        'file_size' => $result['file_size']
                    ]);
                } else {
                    $_SESSION['success_message'] = 'Backup executed successfully';
                    header('Location: /backup/job?id=' . $jobId);
                }
            } else {
                throw new Exception($result['error']);
            }
            
        } catch (Exception $e) {
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            } else {
                $_SESSION['error_message'] = $e->getMessage();
                header('Location: /backup');
            }
        }
    }
    
    /**
     * Restore from backup
     */
    public function restore()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'backup.restore');
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method');
            }
            
            $backupExecutionId = $_POST['backup_execution_id'] ?? null;
            $targetDatabase = $_POST['target_database'] ?? null;
            $confirmRestore = $_POST['confirm_restore'] ?? false;
            
            if (!$backupExecutionId) {
                throw new Exception('Backup execution ID is required');
            }
            
            if (!$confirmRestore) {
                throw new Exception('Please confirm the restore operation');
            }
            
            $options = [];
            if ($targetDatabase) {
                $options['target_database'] = $targetDatabase;
            }
            
            // Execute restore
            $result = $this->backupService->restoreFromBackup($backupExecutionId, $user['id'], $options);
            
            if ($result['success']) {
                if ($this->isAjaxRequest()) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'Restore completed successfully',
                        'restore_id' => $result['restore_id']
                    ]);
                } else {
                    $_SESSION['success_message'] = 'Restore completed successfully';
                    header('Location: /backup');
                }
            } else {
                throw new Exception($result['error']);
            }
            
        } catch (Exception $e) {
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            } else {
                $_SESSION['error_message'] = $e->getMessage();
                header('Location: /backup');
            }
        }
    }
    
    /**
     * Verify backup
     */
    public function verify()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'backup.view');
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method');
            }
            
            $backupExecutionId = $_POST['backup_execution_id'] ?? null;
            $verificationType = $_POST['verification_type'] ?? 'integrity';
            
            if (!$backupExecutionId) {
                throw new Exception('Backup execution ID is required');
            }
            
            // Execute verification
            $result = $this->backupService->verifyBackup($backupExecutionId, $user['id'], $verificationType);
            
            if ($result['success']) {
                if ($this->isAjaxRequest()) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'result' => $result['result'],
                        'details' => $result['details'],
                        'message' => 'Verification completed: ' . $result['result']
                    ]);
                } else {
                    $_SESSION['success_message'] = 'Verification completed: ' . $result['result'];
                    header('Location: /backup');
                }
            } else {
                throw new Exception($result['error']);
            }
            
        } catch (Exception $e) {
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            } else {
                $_SESSION['error_message'] = $e->getMessage();
                header('Location: /backup');
            }
        }
    }
    
    /**
     * Download backup file
     */
    public function download()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'backup.view');
            
            $backupExecutionId = $_GET['execution_id'] ?? null;
            if (!$backupExecutionId) {
                throw new Exception('Backup execution ID is required');
            }
            
            // Get backup execution info
            $executions = $this->backupService->getBackupExecutions();
            $execution = null;
            
            foreach ($executions as $exec) {
                if ($exec['id'] == $backupExecutionId) {
                    $execution = $exec;
                    break;
                }
            }
            
            if (!$execution) {
                throw new Exception('Backup execution not found');
            }
            
            if ($execution['status'] !== 'completed') {
                throw new Exception('Cannot download incomplete backup');
            }
            
            $filePath = $execution['backup_file_path'];
            if (!file_exists($filePath)) {
                throw new Exception('Backup file not found');
            }
            
            // Set headers for file download
            $fileName = basename($filePath);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: 0');
            
            // Output file
            readfile($filePath);
            exit;
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /backup');
        }
    }
    
    /**
     * Show backup statistics
     */
    public function statistics()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'backup.view');
            
            // Get statistics
            $stats = $this->getBackupStatistics();
            
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode($stats);
            } else {
                include __DIR__ . '/../Views/backup/statistics.php';
            }
            
        } catch (Exception $e) {
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            } else {
                $_SESSION['error_message'] = $e->getMessage();
                header('Location: /backup');
            }
        }
    }
    
    /**
     * Get backup statistics
     */
    private function getBackupStatistics()
    {
        // This would be implemented in BackupService
        // For now, return mock data
        return [
            'total_backups' => 150,
            'successful_backups' => 145,
            'failed_backups' => 5,
            'total_size' => '2.5 GB',
            'last_backup' => '2024-01-08 02:00:00',
            'next_scheduled' => '2024-01-09 02:00:00'
        ];
    }
    
    /**
     * Get current user from session
     */
    private function getCurrentUser()
    {
        return $_SESSION['mdm_user'] ?? null;
    }
    
    /**
     * Check if request is AJAX
     */
    private function isAjaxRequest()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}