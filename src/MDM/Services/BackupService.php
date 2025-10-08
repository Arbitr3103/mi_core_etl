<?php

namespace MDM\Services;

use PDO;
use Exception;

class BackupService
{
    private $db;
    private $backupBasePath;
    private $mysqlDumpPath;
    private $mysqlPath;
    
    public function __construct(PDO $db, $config = [])
    {
        $this->db = $db;
        $this->backupBasePath = $config['backup_path'] ?? '/tmp/mdm_backups';
        $this->mysqlDumpPath = $config['mysqldump_path'] ?? 'mysqldump';
        $this->mysqlPath = $config['mysql_path'] ?? 'mysql';
        
        // Ensure backup directory exists
        if (!is_dir($this->backupBasePath)) {
            mkdir($this->backupBasePath, 0755, true);
        }
    }
    
    /**
     * Execute backup job
     */
    public function executeBackup($jobId, $userId = null, $executionType = 'manual')
    {
        try {
            // Get job configuration
            $job = $this->getBackupJob($jobId);
            if (!$job) {
                throw new Exception("Backup job {$jobId} not found");
            }
            
            if ($job['status'] !== 'active') {
                throw new Exception("Backup job {$jobId} is not active");
            }
            
            // Create execution record
            $executionId = $this->createBackupExecution($jobId, $executionType, $userId);
            
            try {
                // Prepare backup path
                $backupDir = $this->prepareBackupDirectory($job['backup_path']);
                $timestamp = date('Y-m-d_H-i-s');
                $backupFileName = "{$job['job_name']}_{$timestamp}.sql";
                $backupFilePath = $backupDir . '/' . $backupFileName;
                
                // Get database connection info
                $dbConfig = $this->getDatabaseConfig();
                
                // Execute backup based on type
                switch ($job['backup_type']) {
                    case 'full':
                        $result = $this->executeFullBackup($backupFilePath, $job['tables_to_backup'], $dbConfig);
                        break;
                    case 'incremental':
                        $result = $this->executeIncrementalBackup($backupFilePath, $job['tables_to_backup'], $dbConfig, $jobId);
                        break;
                    case 'differential':
                        $result = $this->executeDifferentialBackup($backupFilePath, $job['tables_to_backup'], $dbConfig, $jobId);
                        break;
                    default:
                        throw new Exception("Unknown backup type: {$job['backup_type']}");
                }
                
                // Compress if enabled
                if ($job['compression_enabled']) {
                    $compressedPath = $this->compressBackup($backupFilePath);
                    if ($compressedPath) {
                        unlink($backupFilePath);
                        $backupFilePath = $compressedPath;
                    }
                }
                
                // Calculate checksum
                $checksum = hash_file('sha256', $backupFilePath);
                $fileSize = filesize($backupFilePath);
                
                // Update execution record
                $this->updateBackupExecution($executionId, [
                    'backup_file_path' => $backupFilePath,
                    'backup_file_size' => $fileSize,
                    'backup_checksum' => $checksum,
                    'tables_backed_up' => json_encode($job['tables_to_backup']),
                    'records_count' => json_encode($result['records_count']),
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s')
                ]);
                
                // Clean old backups
                $this->cleanOldBackups($jobId, $job['retention_days']);
                
                return [
                    'success' => true,
                    'execution_id' => $executionId,
                    'backup_file' => $backupFilePath,
                    'file_size' => $fileSize,
                    'checksum' => $checksum
                ];
                
            } catch (Exception $e) {
                // Update execution record with error
                $this->updateBackupExecution($executionId, [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => date('Y-m-d H:i:s')
                ]);
                
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("Backup execution error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Execute full backup
     */
    private function executeFullBackup($backupFilePath, $tables, $dbConfig)
    {
        $tablesStr = implode(' ', $tables);
        
        $command = sprintf(
            '%s --host=%s --port=%s --user=%s --password=%s --single-transaction --routines --triggers %s %s > %s',
            $this->mysqlDumpPath,
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database'],
            $tablesStr,
            escapeshellarg($backupFilePath)
        );
        
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Backup command failed with code {$returnCode}: " . implode("\n", $output));
        }
        
        // Count records in each table
        $recordsCounts = [];
        foreach ($tables as $table) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$table}");
            $stmt->execute();
            $recordsCounts[$table] = $stmt->fetchColumn();
        }
        
        return [
            'records_count' => $recordsCounts
        ];
    }
    
    /**
     * Execute incremental backup
     */
    private function executeIncrementalBackup($backupFilePath, $tables, $dbConfig, $jobId)
    {
        // Get last backup timestamp
        $lastBackup = $this->getLastSuccessfulBackup($jobId);
        $lastBackupTime = $lastBackup ? $lastBackup['started_at'] : '1970-01-01 00:00:00';
        
        $recordsCounts = [];
        $allSql = "-- Incremental backup from {$lastBackupTime}\n\n";
        
        foreach ($tables as $table) {
            // Check if table has updated_at column
            $hasUpdatedAt = $this->tableHasColumn($table, 'updated_at');
            $hasCreatedAt = $this->tableHasColumn($table, 'created_at');
            
            if ($hasUpdatedAt) {
                $whereClause = "WHERE updated_at > '{$lastBackupTime}'";
            } elseif ($hasCreatedAt) {
                $whereClause = "WHERE created_at > '{$lastBackupTime}'";
            } else {
                // For tables without timestamp columns, do full backup
                $whereClause = "";
            }
            
            // Get table structure
            $createTableSql = $this->getCreateTableSql($table, $dbConfig);
            $allSql .= "-- Table: {$table}\n";
            $allSql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $allSql .= $createTableSql . "\n\n";
            
            // Get data
            $stmt = $this->db->prepare("SELECT * FROM {$table} {$whereClause}");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $recordsCounts[$table] = count($rows);
            
            if (!empty($rows)) {
                $allSql .= "-- Data for table {$table}\n";
                $allSql .= $this->generateInsertStatements($table, $rows);
                $allSql .= "\n";
            }
        }
        
        file_put_contents($backupFilePath, $allSql);
        
        return [
            'records_count' => $recordsCounts
        ];
    }
    
    /**
     * Execute differential backup
     */
    private function executeDifferentialBackup($backupFilePath, $tables, $dbConfig, $jobId)
    {
        // Get last full backup timestamp
        $lastFullBackup = $this->getLastFullBackup($jobId);
        $lastBackupTime = $lastFullBackup ? $lastFullBackup['started_at'] : '1970-01-01 00:00:00';
        
        return $this->executeIncrementalBackup($backupFilePath, $tables, $dbConfig, $jobId);
    }
    
    /**
     * Restore from backup
     */
    public function restoreFromBackup($backupExecutionId, $userId, $options = [])
    {
        try {
            // Get backup execution info
            $backup = $this->getBackupExecution($backupExecutionId);
            if (!$backup) {
                throw new Exception("Backup execution {$backupExecutionId} not found");
            }
            
            if ($backup['status'] !== 'completed') {
                throw new Exception("Cannot restore from incomplete backup");
            }
            
            // Verify backup file exists
            if (!file_exists($backup['backup_file_path'])) {
                throw new Exception("Backup file not found: {$backup['backup_file_path']}");
            }
            
            // Verify checksum
            $currentChecksum = hash_file('sha256', $backup['backup_file_path']);
            if ($currentChecksum !== $backup['backup_checksum']) {
                throw new Exception("Backup file checksum mismatch - file may be corrupted");
            }
            
            // Create restore operation record
            $restoreId = $this->createRestoreOperation($backupExecutionId, $userId, $options);
            
            try {
                // Get database config
                $dbConfig = $this->getDatabaseConfig();
                
                // Decompress if needed
                $restoreFilePath = $backup['backup_file_path'];
                if (pathinfo($restoreFilePath, PATHINFO_EXTENSION) === 'gz') {
                    $restoreFilePath = $this->decompressBackup($backup['backup_file_path']);
                }
                
                // Execute restore
                $command = sprintf(
                    '%s --host=%s --port=%s --user=%s --password=%s %s < %s',
                    $this->mysqlPath,
                    $dbConfig['host'],
                    $dbConfig['port'],
                    $dbConfig['username'],
                    $dbConfig['password'],
                    $options['target_database'] ?? $dbConfig['database'],
                    escapeshellarg($restoreFilePath)
                );
                
                exec($command, $output, $returnCode);
                
                if ($returnCode !== 0) {
                    throw new Exception("Restore command failed with code {$returnCode}: " . implode("\n", $output));
                }
                
                // Clean up temporary decompressed file
                if ($restoreFilePath !== $backup['backup_file_path']) {
                    unlink($restoreFilePath);
                }
                
                // Update restore operation
                $this->updateRestoreOperation($restoreId, [
                    'status' => 'completed',
                    'completed_at' => date('Y-m-d H:i:s'),
                    'records_restored' => $backup['records_count']
                ]);
                
                return [
                    'success' => true,
                    'restore_id' => $restoreId
                ];
                
            } catch (Exception $e) {
                // Update restore operation with error
                $this->updateRestoreOperation($restoreId, [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'completed_at' => date('Y-m-d H:i:s')
                ]);
                
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("Restore error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Verify backup integrity
     */
    public function verifyBackup($backupExecutionId, $userId, $verificationType = 'integrity')
    {
        try {
            $backup = $this->getBackupExecution($backupExecutionId);
            if (!$backup) {
                throw new Exception("Backup execution {$backupExecutionId} not found");
            }
            
            $verificationDetails = [];
            $result = 'passed';
            
            switch ($verificationType) {
                case 'checksum':
                    $result = $this->verifyChecksum($backup, $verificationDetails);
                    break;
                case 'integrity':
                    $result = $this->verifyIntegrity($backup, $verificationDetails);
                    break;
                case 'restore_test':
                    $result = $this->verifyRestoreTest($backup, $verificationDetails);
                    break;
            }
            
            // Record verification result
            $stmt = $this->db->prepare("
                INSERT INTO mdm_backup_verifications 
                (backup_execution_id, verification_type, verification_result, verification_details, performed_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $backupExecutionId,
                $verificationType,
                $result,
                json_encode($verificationDetails),
                $userId
            ]);
            
            return [
                'success' => true,
                'result' => $result,
                'details' => $verificationDetails
            ];
            
        } catch (Exception $e) {
            error_log("Backup verification error: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get backup jobs
     */
    public function getBackupJobs($filters = [])
    {
        try {
            $whereConditions = ['1=1'];
            $params = [];
            
            if (!empty($filters['status'])) {
                $whereConditions[] = 'status = ?';
                $params[] = $filters['status'];
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            $stmt = $this->db->prepare("
                SELECT j.*, u.username as created_by_username
                FROM mdm_backup_jobs j
                LEFT JOIN mdm_users u ON j.created_by = u.id
                WHERE {$whereClause}
                ORDER BY j.created_at DESC
            ");
            
            $stmt->execute($params);
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON fields
            foreach ($jobs as &$job) {
                $job['tables_to_backup'] = json_decode($job['tables_to_backup'], true);
            }
            
            return $jobs;
            
        } catch (Exception $e) {
            error_log("Get backup jobs error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get backup executions
     */
    public function getBackupExecutions($jobId = null, $limit = 100, $offset = 0)
    {
        try {
            $whereConditions = ['1=1'];
            $params = [];
            
            if ($jobId) {
                $whereConditions[] = 'e.job_id = ?';
                $params[] = $jobId;
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            $stmt = $this->db->prepare("
                SELECT e.*, j.job_name, u.username as executed_by_username
                FROM mdm_backup_executions e
                JOIN mdm_backup_jobs j ON e.job_id = j.id
                LEFT JOIN mdm_users u ON e.executed_by = u.id
                WHERE {$whereClause}
                ORDER BY e.started_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt->execute($params);
            $executions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON fields and format file sizes
            foreach ($executions as &$execution) {
                $execution['tables_backed_up'] = json_decode($execution['tables_backed_up'], true);
                $execution['records_count'] = json_decode($execution['records_count'], true);
                $execution['backup_file_size_formatted'] = $this->formatFileSize($execution['backup_file_size']);
            }
            
            return $executions;
            
        } catch (Exception $e) {
            error_log("Get backup executions error: " . $e->getMessage());
            return [];
        }
    }
    
    // Helper methods...
    
    private function getBackupJob($jobId)
    {
        $stmt = $this->db->prepare("SELECT * FROM mdm_backup_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($job) {
            $job['tables_to_backup'] = json_decode($job['tables_to_backup'], true);
        }
        
        return $job;
    }
    
    private function createBackupExecution($jobId, $executionType, $userId)
    {
        $stmt = $this->db->prepare("
            INSERT INTO mdm_backup_executions (job_id, execution_type, executed_by)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$jobId, $executionType, $userId]);
        
        return $this->db->lastInsertId();
    }
    
    private function updateBackupExecution($executionId, $data)
    {
        $setParts = [];
        $params = [];
        
        foreach ($data as $field => $value) {
            $setParts[] = "{$field} = ?";
            $params[] = $value;
        }
        
        $params[] = $executionId;
        
        $stmt = $this->db->prepare("
            UPDATE mdm_backup_executions 
            SET " . implode(', ', $setParts) . "
            WHERE id = ?
        ");
        
        return $stmt->execute($params);
    }
    
    private function getDatabaseConfig()
    {
        // Extract database config from PDO DSN
        $dsn = $this->db->getAttribute(PDO::ATTR_CONNECTION_STATUS);
        
        // This is a simplified version - in real implementation,
        // you would get these from your database configuration
        return [
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'mdm_database',
            'username' => 'mdm_user',
            'password' => 'mdm_password'
        ];
    }
    
    private function prepareBackupDirectory($path)
    {
        $fullPath = $this->backupBasePath . '/' . trim($path, '/');
        
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0755, true);
        }
        
        return $fullPath;
    }
    
    private function compressBackup($filePath)
    {
        $compressedPath = $filePath . '.gz';
        
        $command = "gzip -c " . escapeshellarg($filePath) . " > " . escapeshellarg($compressedPath);
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($compressedPath)) {
            return $compressedPath;
        }
        
        return false;
    }
    
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
    
    private function cleanOldBackups($jobId, $retentionDays)
    {
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        
        // Get old backup files
        $stmt = $this->db->prepare("
            SELECT backup_file_path 
            FROM mdm_backup_executions 
            WHERE job_id = ? AND started_at < ? AND status = 'completed'
        ");
        $stmt->execute([$jobId, $cutoffDate]);
        $oldBackups = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Delete files
        foreach ($oldBackups as $filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        
        // Delete database records
        $stmt = $this->db->prepare("
            DELETE FROM mdm_backup_executions 
            WHERE job_id = ? AND started_at < ?
        ");
        $stmt->execute([$jobId, $cutoffDate]);
        
        return count($oldBackups);
    }
}