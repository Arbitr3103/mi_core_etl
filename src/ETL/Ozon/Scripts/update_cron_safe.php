#!/usr/bin/env php
<?php

/**
 * Safe Cron Update Script
 * 
 * Safely updates cron jobs by preserving existing non-Ozon ETL jobs
 * and adding/updating only Ozon ETL related jobs.
 * 
 * Usage:
 *   php update_cron_safe.php [options]
 * 
 * Options:
 *   --dry-run          Show what would be done without making changes
 *   --verbose          Enable verbose output
 *   --backup           Create backup of current crontab
 */

declare(strict_types=1);

// Ensure script is run from command line
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

// Load configuration
try {
    $config = require __DIR__ . '/../autoload.php';
    $cronConfig = require __DIR__ . '/../Config/cron_config.php';
} catch (Exception $e) {
    echo "Error loading configuration: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array {
    $options = [
        'dry_run' => false,
        'verbose' => false,
        'backup' => false
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        switch ($arg) {
            case '--dry-run':
                $options['dry_run'] = true;
                break;
            case '--verbose':
            case '-v':
                $options['verbose'] = true;
                break;
            case '--backup':
                $options['backup'] = true;
                break;
            case '--help':
            case '-h':
                showHelp();
                exit(0);
        }
    }

    return $options;
}

/**
 * Show help message
 */
function showHelp(): void {
    echo "Safe Cron Update Script\n\n";
    echo "This script safely updates cron jobs by preserving existing\n";
    echo "non-Ozon ETL jobs and adding/updating only Ozon ETL jobs.\n\n";
    echo "Usage: php update_cron_safe.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run    Show what would be done without making changes\n";
    echo "  --verbose    Enable verbose output\n";
    echo "  --backup     Create backup of current crontab\n";
    echo "  --help       Show this help message\n\n";
}

/**
 * Get current crontab
 */
function getCurrentCrontab(): array {
    exec('crontab -l 2>/dev/null', $currentCron, $returnCode);
    
    if ($returnCode !== 0) {
        return [];
    }
    
    return $currentCron;
}

/**
 * Backup current crontab
 */
function backupCrontab(array $options): ?string {
    if (!$options['backup']) {
        return null;
    }
    
    $currentCron = getCurrentCrontab();
    
    if (empty($currentCron)) {
        if ($options['verbose']) {
            echo "No existing crontab to backup\n";
        }
        return null;
    }
    
    $backupFile = '/tmp/crontab_backup_' . date('Y-m-d_H-i-s') . '.txt';
    
    if ($options['dry_run']) {
        echo "DRY RUN: Would create backup at {$backupFile}\n";
        return $backupFile;
    }
    
    file_put_contents($backupFile, implode("\n", $currentCron));
    
    if ($options['verbose']) {
        echo "Backup created: {$backupFile}\n";
    }
    
    return $backupFile;
}

/**
 * Filter out Ozon ETL jobs from existing crontab
 */
function filterOzonJobs(array $crontab): array {
    $filtered = [];
    $inOzonSection = false;
    
    foreach ($crontab as $line) {
        // Check for Ozon ETL markers
        if (strpos($line, '# Ozon ETL') !== false || 
            strpos($line, 'sync_products') !== false ||
            strpos($line, 'sync_sales') !== false ||
            strpos($line, 'sync_inventory') !== false ||
            strpos($line, 'monitor_etl') !== false ||
            strpos($line, 'health_check') !== false) {
            $inOzonSection = true;
            continue;
        }
        
        // Skip lines that are part of Ozon section
        if ($inOzonSection) {
            // End of Ozon section if we hit a non-comment, non-empty line that doesn't contain Ozon stuff
            if (!empty(trim($line)) && 
                strpos($line, '#') !== 0 && 
                strpos($line, 'ozon') === false &&
                strpos($line, 'sync_') === false &&
                strpos($line, 'monitor_') === false) {
                $inOzonSection = false;
                $filtered[] = $line;
            }
            continue;
        }
        
        $filtered[] = $line;
    }
    
    return $filtered;
}

/**
 * Generate Ozon ETL cron jobs
 */
function generateOzonCronJobs(array $cronConfig): array {
    $ozonJobs = [];
    
    // Add header
    $ozonJobs[] = '';
    $ozonJobs[] = '# Ozon ETL System - Generated on ' . date('Y-m-d H:i:s');
    $ozonJobs[] = '# Automated ETL processes for Ozon marketplace integration';
    $ozonJobs[] = '';
    
    // Project paths
    $projectRoot = $cronConfig['paths']['project_root'];
    $scriptsDir = $cronConfig['paths']['scripts_dir'];
    $phpBin = $cronConfig['paths']['php_binary'];
    $logDir = $cronConfig['logging']['job_log_dir'];
    
    // ETL Jobs
    foreach ($cronConfig['schedule']['jobs'] as $jobName => $job) {
        if (!$job['enabled']) {
            $ozonJobs[] = "# DISABLED: {$job['description']}";
            $ozonJobs[] = "# {$job['schedule']} cd {$projectRoot} && {$phpBin} {$scriptsDir}/{$job['script']} --verbose >> {$logDir}/{$jobName}_\$(date +\\%Y\\%m\\%d).log 2>&1";
        } else {
            $ozonJobs[] = "# {$job['description']}";
            $ozonJobs[] = "{$job['schedule']} cd {$projectRoot} && {$phpBin} {$scriptsDir}/{$job['script']} --verbose >> {$logDir}/{$jobName}_\$(date +\\%Y\\%m\\%d).log 2>&1";
        }
        $ozonJobs[] = '';
    }
    
    // Maintenance jobs
    $retentionDays = $cronConfig['logging']['log_retention_days'];
    $ozonJobs[] = '# Ozon ETL Maintenance';
    $ozonJobs[] = "# Log cleanup - daily at 1:30 AM (avoid conflict with Analytics cleanup)";
    $ozonJobs[] = "30 1 * * * find {$logDir} -name \"*.log\" -type f -mtime +{$retentionDays} -delete";
    
    return $ozonJobs;
}

/**
 * Update crontab safely
 */
function updateCrontabSafe(array $cronConfig, array $options): bool {
    // Get current crontab
    $currentCron = getCurrentCrontab();
    
    if ($options['verbose']) {
        echo "Current crontab has " . count($currentCron) . " lines\n";
    }
    
    // Filter out existing Ozon jobs
    $filteredCron = filterOzonJobs($currentCron);
    
    if ($options['verbose']) {
        echo "After filtering Ozon jobs: " . count($filteredCron) . " lines\n";
    }
    
    // Generate new Ozon jobs
    $ozonJobs = generateOzonCronJobs($cronConfig);
    
    if ($options['verbose']) {
        echo "Generated " . count($ozonJobs) . " new Ozon job lines\n";
    }
    
    // Combine filtered existing jobs with new Ozon jobs
    $newCrontab = array_merge($filteredCron, $ozonJobs);
    
    if ($options['dry_run']) {
        echo "\nDRY RUN - New crontab would be:\n";
        echo str_repeat('-', 60) . "\n";
        foreach ($newCrontab as $line) {
            echo $line . "\n";
        }
        echo str_repeat('-', 60) . "\n";
        return true;
    }
    
    // Create temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'ozon_cron_update_');
    file_put_contents($tempFile, implode("\n", $newCrontab));
    
    try {
        // Install new crontab
        exec("crontab {$tempFile}", $output, $returnCode);
        
        if ($returnCode === 0) {
            if ($options['verbose']) {
                echo "Crontab updated successfully!\n";
            }
            return true;
        } else {
            echo "Error updating crontab. Output:\n";
            echo implode("\n", $output) . "\n";
            return false;
        }
        
    } finally {
        // Clean up temporary file
        unlink($tempFile);
    }
}

/**
 * Show summary of changes
 */
function showSummary(array $cronConfig, array $options): void {
    echo "\nOzon ETL Cron Update Summary\n";
    echo str_repeat('=', 40) . "\n";
    
    if ($options['dry_run']) {
        echo "📋 Preview Mode - No changes made\n\n";
    } else {
        echo "✅ Crontab updated successfully\n\n";
    }
    
    echo "Ozon ETL Jobs:\n";
    foreach ($cronConfig['schedule']['jobs'] as $jobName => $job) {
        $status = $job['enabled'] ? '✅' : '❌';
        echo "  {$status} {$jobName}: {$job['schedule']}\n";
        echo "      {$job['description']}\n";
    }
    
    echo "\nLog Directory: {$cronConfig['logging']['job_log_dir']}\n";
    echo "Log Retention: {$cronConfig['logging']['log_retention_days']} days\n";
    
    if (!$options['dry_run']) {
        echo "\nNext Steps:\n";
        echo "  1. Monitor logs: ls -la {$cronConfig['logging']['job_log_dir']}\n";
        echo "  2. Check status: php src/ETL/Ozon/Scripts/process_monitor.php status\n";
        echo "  3. View all cron jobs: crontab -l\n";
    }
}

/**
 * Main execution function
 */
function main(): void {
    global $cronConfig;
    
    $options = parseArguments($_SERVER['argv']);
    
    try {
        echo "Ozon ETL Safe Cron Update\n";
        echo str_repeat('=', 30) . "\n\n";
        
        // Create backup if requested
        $backupFile = backupCrontab($options);
        
        // Update crontab
        $success = updateCrontabSafe($cronConfig, $options);
        
        if (!$success && !$options['dry_run']) {
            echo "❌ Failed to update crontab\n";
            
            if ($backupFile && file_exists($backupFile)) {
                echo "Backup available at: {$backupFile}\n";
                echo "To restore: crontab {$backupFile}\n";
            }
            
            exit(1);
        }
        
        // Show summary
        showSummary($cronConfig, $options);
        
        if ($backupFile && !$options['dry_run']) {
            echo "\nBackup created: {$backupFile}\n";
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        
        if ($options['verbose']) {
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        }
        
        exit(1);
    }
}

// Execute main function
main();