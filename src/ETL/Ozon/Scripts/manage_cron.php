#!/usr/bin/env php
<?php

/**
 * Ozon ETL Cron Management Script
 * 
 * Script for managing cron jobs for the Ozon ETL system.
 * Provides functionality to install, remove, and validate cron configuration.
 * 
 * Usage:
 *   php manage_cron.php install   - Install cron jobs
 *   php manage_cron.php remove    - Remove cron jobs
 *   php manage_cron.php status    - Show current cron status
 *   php manage_cron.php validate  - Validate cron configuration
 *   php manage_cron.php logs      - Show recent log entries
 * 
 * Requirements addressed:
 * - 5.1, 5.2, 5.3: Schedule ETL processes at specified times
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
 * Show help message
 */
function showHelp(): void {
    echo "Ozon ETL Cron Management Script\n\n";
    echo "Usage: php manage_cron.php <command> [options]\n\n";
    echo "Commands:\n";
    echo "  install   Install cron jobs for current user\n";
    echo "  remove    Remove all Ozon ETL cron jobs\n";
    echo "  status    Show current cron job status\n";
    echo "  validate  Validate cron configuration\n";
    echo "  logs      Show recent log entries\n";
    echo "  help      Show this help message\n\n";
    echo "Options:\n";
    echo "  --user=USER    Specify user for cron operations (requires sudo)\n";
    echo "  --dry-run      Show what would be done without making changes\n";
    echo "  --verbose      Enable verbose output\n\n";
}

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array {
    $options = [
        'command' => $argv[1] ?? 'help',
        'user' => null,
        'dry_run' => false,
        'verbose' => false
    ];

    for ($i = 2; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $options['verbose'] = true;
        } elseif (strpos($arg, '--user=') === 0) {
            $options['user'] = substr($arg, 7);
        }
    }

    return $options;
}

/**
 * Generate crontab content from configuration
 */
function generateCrontabContent(array $cronConfig): string {
    $content = "# Ozon ETL System - Generated on " . date('Y-m-d H:i:s') . "\n";
    $content .= "# DO NOT EDIT MANUALLY - Use manage_cron.php script\n\n";
    
    // Environment variables
    $content .= "SHELL=/bin/bash\n";
    $content .= "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n";
    $content .= "MAILTO=\"\"\n\n";
    
    // Project paths
    $projectRoot = $cronConfig['paths']['project_root'];
    $scriptsDir = $cronConfig['paths']['scripts_dir'];
    $phpBin = $cronConfig['paths']['php_binary'];
    $logDir = $cronConfig['logging']['job_log_dir'];
    
    $content .= "# Project Configuration\n";
    $content .= "PROJECT_ROOT={$projectRoot}\n";
    $content .= "SCRIPTS_DIR={$scriptsDir}\n";
    $content .= "LOG_DIR={$logDir}\n";
    $content .= "PHP_BIN={$phpBin}\n\n";
    
    // Ensure directories exist
    $content .= "# Ensure log directory exists\n";
    $content .= "@reboot mkdir -p {$logDir}\n\n";
    
    // ETL Jobs
    $content .= "# Ozon ETL Jobs\n";
    foreach ($cronConfig['schedule']['jobs'] as $jobName => $job) {
        if (!$job['enabled']) {
            $content .= "# DISABLED: {$job['description']}\n";
            $content .= "# {$job['schedule']} cd \${PROJECT_ROOT} && \${PHP_BIN} \${SCRIPTS_DIR}/{$job['script']} --verbose >> \${LOG_DIR}/{$jobName}_\$(date +\\%Y\\%m\\%d).log 2>&1\n\n";
        } else {
            $content .= "# {$job['description']}\n";
            $content .= "{$job['schedule']} cd \${PROJECT_ROOT} && \${PHP_BIN} \${SCRIPTS_DIR}/{$job['script']} --verbose >> \${LOG_DIR}/{$jobName}_\$(date +\\%Y\\%m\\%d).log 2>&1\n\n";
        }
    }
    
    // Maintenance jobs
    $content .= "# Maintenance Jobs\n";
    $retentionDays = $cronConfig['logging']['log_retention_days'];
    $content .= "# Log cleanup - daily at 1 AM\n";
    $content .= "0 1 * * * find \${LOG_DIR} -name \"*.log\" -type f -mtime +{$retentionDays} -delete\n\n";
    
    return $content;
}

/**
 * Install cron jobs
 */
function installCron(array $options, array $cronConfig): void {
    $verbose = $options['verbose'];
    $dryRun = $options['dry_run'];
    $user = $options['user'];
    
    if ($verbose) {
        echo "Installing Ozon ETL cron jobs...\n";
    }
    
    // Generate crontab content
    $crontabContent = generateCrontabContent($cronConfig);
    
    if ($dryRun) {
        echo "DRY RUN - Would install the following crontab:\n";
        echo str_repeat('-', 50) . "\n";
        echo $crontabContent;
        echo str_repeat('-', 50) . "\n";
        return;
    }
    
    // Create temporary file
    $tempFile = tempnam(sys_get_temp_dir(), 'ozon_etl_cron_');
    file_put_contents($tempFile, $crontabContent);
    
    try {
        // Install crontab
        $command = $user ? "sudo crontab -u {$user} {$tempFile}" : "crontab {$tempFile}";
        
        if ($verbose) {
            echo "Executing: {$command}\n";
        }
        
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            echo "Cron jobs installed successfully!\n";
            
            if ($verbose) {
                echo "Installed jobs:\n";
                foreach ($cronConfig['schedule']['jobs'] as $jobName => $job) {
                    $status = $job['enabled'] ? 'ENABLED' : 'DISABLED';
                    echo "  - {$jobName}: {$job['schedule']} ({$status})\n";
                }
            }
        } else {
            echo "Error installing cron jobs. Output:\n";
            echo implode("\n", $output) . "\n";
            exit(1);
        }
        
    } finally {
        // Clean up temporary file
        unlink($tempFile);
    }
}

/**
 * Remove cron jobs
 */
function removeCron(array $options): void {
    $verbose = $options['verbose'];
    $dryRun = $options['dry_run'];
    $user = $options['user'];
    
    if ($verbose) {
        echo "Removing Ozon ETL cron jobs...\n";
    }
    
    if ($dryRun) {
        echo "DRY RUN - Would remove all Ozon ETL cron jobs\n";
        return;
    }
    
    // Get current crontab
    $command = $user ? "sudo crontab -u {$user} -l" : "crontab -l";
    exec($command, $currentCron, $returnCode);
    
    if ($returnCode !== 0) {
        echo "No existing crontab found or error reading crontab.\n";
        return;
    }
    
    // Filter out Ozon ETL jobs
    $filteredCron = [];
    $inOzonSection = false;
    
    foreach ($currentCron as $line) {
        if (strpos($line, '# Ozon ETL System') !== false) {
            $inOzonSection = true;
            continue;
        }
        
        if ($inOzonSection && (empty(trim($line)) || strpos($line, '#') === 0)) {
            continue;
        }
        
        if ($inOzonSection && strpos($line, 'ozon') !== false) {
            continue;
        }
        
        $inOzonSection = false;
        $filteredCron[] = $line;
    }
    
    // Install filtered crontab
    $tempFile = tempnam(sys_get_temp_dir(), 'ozon_etl_cron_clean_');
    file_put_contents($tempFile, implode("\n", $filteredCron));
    
    try {
        $command = $user ? "sudo crontab -u {$user} {$tempFile}" : "crontab {$tempFile}";
        exec($command, $output, $returnCode);
        
        if ($returnCode === 0) {
            echo "Ozon ETL cron jobs removed successfully!\n";
        } else {
            echo "Error removing cron jobs.\n";
            exit(1);
        }
        
    } finally {
        unlink($tempFile);
    }
}

/**
 * Show cron status
 */
function showStatus(array $options, array $cronConfig): void {
    $user = $options['user'];
    $verbose = $options['verbose'];
    
    echo "Ozon ETL Cron Status\n";
    echo str_repeat('=', 50) . "\n\n";
    
    // Check if cron jobs are installed
    $command = $user ? "sudo crontab -u {$user} -l" : "crontab -l";
    exec($command, $currentCron, $returnCode);
    
    if ($returnCode !== 0) {
        echo "No crontab found for " . ($user ?: 'current user') . "\n";
        return;
    }
    
    // Check for Ozon ETL jobs
    $ozonJobs = array_filter($currentCron, function($line) {
        return strpos($line, 'sync_products') !== false || 
               strpos($line, 'sync_sales') !== false || 
               strpos($line, 'sync_inventory') !== false ||
               strpos($line, 'health_check') !== false;
    });
    
    if (empty($ozonJobs)) {
        echo "No Ozon ETL cron jobs found.\n";
        echo "Run 'php manage_cron.php install' to install them.\n";
        return;
    }
    
    echo "Installed Ozon ETL Jobs:\n";
    foreach ($ozonJobs as $job) {
        echo "  " . trim($job) . "\n";
    }
    
    if ($verbose) {
        echo "\nConfigured Jobs:\n";
        foreach ($cronConfig['schedule']['jobs'] as $jobName => $job) {
            $status = $job['enabled'] ? '✓' : '✗';
            echo "  {$status} {$jobName}: {$job['schedule']} - {$job['description']}\n";
        }
        
        echo "\nLog Directory: {$cronConfig['logging']['job_log_dir']}\n";
        echo "Lock Directory: {$cronConfig['paths']['lock_dir']}\n";
    }
}

/**
 * Validate configuration
 */
function validateConfig(array $cronConfig): void {
    echo "Validating Ozon ETL Cron Configuration\n";
    echo str_repeat('=', 50) . "\n\n";
    
    $errors = [];
    $warnings = [];
    
    // Check required directories
    $directories = [
        'Scripts Directory' => $cronConfig['paths']['scripts_dir'],
        'Log Directory' => $cronConfig['logging']['job_log_dir'],
        'Lock Directory' => $cronConfig['paths']['lock_dir'],
        'PID Directory' => $cronConfig['paths']['pid_dir']
    ];
    
    foreach ($directories as $name => $path) {
        if (!is_dir($path)) {
            if (is_writable(dirname($path))) {
                $warnings[] = "{$name} does not exist but can be created: {$path}";
            } else {
                $errors[] = "{$name} does not exist and cannot be created: {$path}";
            }
        } elseif (!is_writable($path)) {
            $errors[] = "{$name} is not writable: {$path}";
        }
    }
    
    // Check PHP binary
    $phpBin = $cronConfig['paths']['php_binary'];
    if (!is_executable($phpBin)) {
        $errors[] = "PHP binary is not executable: {$phpBin}";
    }
    
    // Check script files
    foreach ($cronConfig['schedule']['jobs'] as $jobName => $job) {
        $scriptPath = $cronConfig['paths']['scripts_dir'] . '/' . $job['script'];
        if (!file_exists($scriptPath)) {
            $errors[] = "Script file not found for job '{$jobName}': {$scriptPath}";
        } elseif (!is_executable($scriptPath)) {
            $warnings[] = "Script file is not executable for job '{$jobName}': {$scriptPath}";
        }
    }
    
    // Validate cron expressions
    foreach ($cronConfig['schedule']['jobs'] as $jobName => $job) {
        $schedule = $job['schedule'];
        $parts = explode(' ', $schedule);
        
        if (count($parts) !== 5) {
            $errors[] = "Invalid cron expression for job '{$jobName}': {$schedule}";
        }
    }
    
    // Show results
    if (empty($errors) && empty($warnings)) {
        echo "✓ Configuration is valid!\n";
    } else {
        if (!empty($errors)) {
            echo "Errors:\n";
            foreach ($errors as $error) {
                echo "  ✗ {$error}\n";
            }
            echo "\n";
        }
        
        if (!empty($warnings)) {
            echo "Warnings:\n";
            foreach ($warnings as $warning) {
                echo "  ⚠ {$warning}\n";
            }
            echo "\n";
        }
        
        if (!empty($errors)) {
            echo "Please fix the errors before installing cron jobs.\n";
            exit(1);
        }
    }
}

/**
 * Show recent log entries
 */
function showLogs(array $options, array $cronConfig): void {
    $logDir = $cronConfig['logging']['job_log_dir'];
    $verbose = $options['verbose'];
    
    echo "Recent Ozon ETL Log Entries\n";
    echo str_repeat('=', 50) . "\n\n";
    
    if (!is_dir($logDir)) {
        echo "Log directory does not exist: {$logDir}\n";
        return;
    }
    
    // Find recent log files
    $logFiles = glob($logDir . '/*.log');
    if (empty($logFiles)) {
        echo "No log files found in: {$logDir}\n";
        return;
    }
    
    // Sort by modification time (newest first)
    usort($logFiles, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    // Show recent entries from each job type
    $jobTypes = ['sync_products', 'sync_sales', 'sync_inventory', 'health_check'];
    
    foreach ($jobTypes as $jobType) {
        $jobLogs = array_filter($logFiles, function($file) use ($jobType) {
            return strpos(basename($file), $jobType) === 0;
        });
        
        if (empty($jobLogs)) {
            continue;
        }
        
        $latestLog = $jobLogs[0];
        echo "Latest {$jobType} log (" . basename($latestLog) . "):\n";
        
        if ($verbose) {
            // Show full log
            echo file_get_contents($latestLog);
        } else {
            // Show last 10 lines
            $lines = file($latestLog);
            $recentLines = array_slice($lines, -10);
            echo implode('', $recentLines);
        }
        
        echo "\n" . str_repeat('-', 30) . "\n\n";
    }
}

/**
 * Main execution function
 */
function main(): void {
    global $cronConfig;
    
    $options = parseArguments($_SERVER['argv']);
    
    switch ($options['command']) {
        case 'install':
            installCron($options, $cronConfig);
            break;
            
        case 'remove':
            removeCron($options);
            break;
            
        case 'status':
            showStatus($options, $cronConfig);
            break;
            
        case 'validate':
            validateConfig($cronConfig);
            break;
            
        case 'logs':
            showLogs($options, $cronConfig);
            break;
            
        case 'help':
        default:
            showHelp();
            break;
    }
}

// Execute main function
main();