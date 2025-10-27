#!/usr/bin/env php
<?php

/**
 * Cron Management Script
 * 
 * Manages cron jobs for the Ozon ETL system with dependency management
 * and proper sequencing. Safely updates cron configuration while preserving
 * existing non-ETL jobs.
 * 
 * Requirements addressed:
 * - 5.1: Update cron jobs to run ProductETL before InventoryETL with proper timing
 * - 5.2: Update monitoring to track both ETL components separately
 * 
 * Usage:
 *   php manage_cron.php [command] [options]
 * 
 * Commands:
 *   install     Install/update ETL cron jobs
 *   remove      Remove ETL cron jobs
 *   status      Show current cron job status
 *   validate    Validate cron configuration
 *   backup      Create backup of current crontab
 *   restore     Restore from backup
 * 
 * Options:
 *   --dry-run          Show what would be done without making changes
 *   --verbose          Enable verbose output
 *   --backup           Create backup before making changes
 *   --force            Force operation without confirmation
 *   --config=FILE      Use custom configuration file
 *   --help             Show this help message
 */

declare(strict_types=1);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Change to script directory
chdir(__DIR__);

// Load configuration
try {
    require_once __DIR__ . '/../autoload.php';
} catch (Exception $e) {
    echo "Error loading dependencies: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array
{
    $options = [
        'command' => null,
        'dry_run' => false,
        'verbose' => false,
        'backup' => false,
        'force' => false,
        'config_file' => null,
        'help' => false
    ];
    
    // Get command (first non-option argument)
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if (strpos($arg, '--') !== 0 && $options['command'] === null) {
            $options['command'] = $arg;
            continue;
        }
        
        switch ($arg) {
            case '--dry-run':
                $options['dry_run'] = true;
                break;
            case '--verbose':
                $options['verbose'] = true;
                break;
            case '--backup':
                $options['backup'] = true;
                break;
            case '--force':
                $options['force'] = true;
                break;
            case '--help':
                $options['help'] = true;
                break;
            default:
                if (strpos($arg, '--config=') === 0) {
                    $options['config_file'] = substr($arg, 9);
                } else {
                    echo "Unknown option: $arg\n";
                    exit(1);
                }
        }
    }
    
    return $options;
}

/**
 * Show help message
 */
function showHelp(): void
{
    echo "Cron Management Script for Ozon ETL System\n";
    echo "==========================================\n\n";
    echo "Manages cron jobs for the Ozon ETL system with dependency management\n";
    echo "and proper sequencing. Safely updates cron configuration while preserving\n";
    echo "existing non-ETL jobs.\n\n";
    echo "Usage:\n";
    echo "  php manage_cron.php [command] [options]\n\n";
    echo "Commands:\n";
    echo "  install     Install/update ETL cron jobs\n";
    echo "  remove      Remove ETL cron jobs\n";
    echo "  status      Show current cron job status\n";
    echo "  validate    Validate cron configuration\n";
    echo "  backup      Create backup of current crontab\n";
    echo "  restore     Restore from backup\n\n";
    echo "Options:\n";
    echo "  --dry-run          Show what would be done without making changes\n";
    echo "  --verbose          Enable verbose output\n";
    echo "  --backup           Create backup before making changes\n";
    echo "  --force            Force operation without confirmation\n";
    echo "  --config=FILE      Use custom configuration file\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php manage_cron.php install --verbose --backup\n";
    echo "  php manage_cron.php status\n";
    echo "  php manage_cron.php remove --dry-run\n";
    echo "  php manage_cron.php validate --config=/path/to/config.php\n\n";
}

/**
 * Get current crontab content
 */
function getCurrentCrontab(): array
{
    $output = [];
    $returnCode = 0;
    
    exec('crontab -l 2>/dev/null', $output, $returnCode);
    
    if ($returnCode !== 0) {
        return []; // No crontab exists
    }
    
    return $output;
}

/**
 * Set crontab content
 */
function setCrontab(array $lines): bool
{
    $tempFile = tempnam(sys_get_temp_dir(), 'crontab_');
    
    if (file_put_contents($tempFile, implode("\n", $lines) . "\n") === false) {
        return false;
    }
    
    $output = [];
    $returnCode = 0;
    
    exec("crontab '$tempFile' 2>&1", $output, $returnCode);
    
    unlink($tempFile);
    
    return $returnCode === 0;
}

/**
 * Create backup of current crontab
 */
function createCrontabBackup(bool $verbose = false): string
{
    $backupDir = __DIR__ . '/../Logs/cron_backups';
    
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $backupFile = $backupDir . '/crontab_backup_' . date('Y-m-d_H-i-s') . '.txt';
    $currentCrontab = getCurrentCrontab();
    
    if (file_put_contents($backupFile, implode("\n", $currentCrontab) . "\n") === false) {
        throw new Exception("Failed to create backup file: $backupFile");
    }
    
    if ($verbose) {
        echo "Backup created: $backupFile\n";
    }
    
    return $backupFile;
}

/**
 * Generate ETL cron jobs from configuration
 */
function generateETLCronJobs(array $cronConfig, bool $verbose = false): array
{
    $cronJobs = [];
    
    // Add header comment
    $cronJobs[] = '';
    $cronJobs[] = '# ============================================================================';
    $cronJobs[] = '# Ozon ETL System Cron Jobs';
    $cronJobs[] = '# Generated on: ' . date('Y-m-d H:i:s');
    $cronJobs[] = '# DO NOT EDIT MANUALLY - Use manage_cron.php script';
    $cronJobs[] = '# ============================================================================';
    
    // Process ETL execution jobs
    if (isset($cronConfig['etl_execution'])) {
        $cronJobs[] = '';
        $cronJobs[] = '# ETL Execution Jobs';
        
        foreach ($cronConfig['etl_execution'] as $jobName => $jobConfig) {
            if (!($jobConfig['enabled'] ?? false) || empty($jobConfig['schedule'])) {
                continue;
            }
            
            $cronJobs[] = '';
            $cronJobs[] = "# {$jobConfig['description']}";
            
            // Build command with logging and error handling
            $command = $jobConfig['command'];
            
            // Add log redirection
            if (!empty($jobConfig['log_file'])) {
                $logFile = strtr($jobConfig['log_file'], [
                    '%Y' => date('Y'),
                    '%m' => date('m'),
                    '%d' => date('d')
                ]);
                $command .= " >> $logFile 2>&1";
            }
            
            $cronLine = $jobConfig['schedule'] . ' ' . $command;
            $cronJobs[] = $cronLine;
            
            if ($verbose) {
                echo "Added ETL job: $jobName\n";
                echo "  Schedule: {$jobConfig['schedule']}\n";
                echo "  Command: {$jobConfig['command']}\n";
            }
        }
    }
    
    // Process monitoring jobs
    if (isset($cronConfig['monitoring'])) {
        $cronJobs[] = '';
        $cronJobs[] = '# Monitoring Jobs';
        
        foreach ($cronConfig['monitoring'] as $jobName => $jobConfig) {
            if (!($jobConfig['enabled'] ?? false)) {
                continue;
            }
            
            $cronJobs[] = '';
            $cronJobs[] = "# {$jobConfig['description']}";
            
            // Build command with logging
            $command = $jobConfig['command'];
            
            if (!empty($jobConfig['log_file'])) {
                $logFile = strtr($jobConfig['log_file'], [
                    '%Y' => date('Y'),
                    '%m' => date('m'),
                    '%d' => date('d')
                ]);
                $command .= " >> $logFile 2>&1";
            }
            
            $cronLine = $jobConfig['schedule'] . ' ' . $command;
            $cronJobs[] = $cronLine;
            
            if ($verbose) {
                echo "Added monitoring job: $jobName\n";
                echo "  Schedule: {$jobConfig['schedule']}\n";
            }
        }
    }
    
    // Process maintenance jobs
    if (isset($cronConfig['maintenance'])) {
        $cronJobs[] = '';
        $cronJobs[] = '# Maintenance Jobs';
        
        foreach ($cronConfig['maintenance'] as $jobName => $jobConfig) {
            if (!($jobConfig['enabled'] ?? false)) {
                continue;
            }
            
            $cronJobs[] = '';
            $cronJobs[] = "# {$jobConfig['description']}";
            
            // Build command with logging
            $command = $jobConfig['command'];
            
            if (!empty($jobConfig['log_file'])) {
                $logFile = strtr($jobConfig['log_file'], [
                    '%Y' => date('Y'),
                    '%m' => date('m'),
                    '%d' => date('d')
                ]);
                $command .= " >> $logFile 2>&1";
            }
            
            $cronLine = $jobConfig['schedule'] . ' ' . $command;
            $cronJobs[] = $cronLine;
            
            if ($verbose) {
                echo "Added maintenance job: $jobName\n";
            }
        }
    }
    
    $cronJobs[] = '';
    $cronJobs[] = '# End of Ozon ETL System Cron Jobs';
    $cronJobs[] = '# ============================================================================';
    
    return $cronJobs;
}

/**
 * Remove ETL cron jobs from crontab
 */
function removeETLCronJobs(array $currentCrontab): array
{
    $filteredCrontab = [];
    $inETLSection = false;
    
    foreach ($currentCrontab as $line) {
        // Check for start of ETL section
        if (strpos($line, '# Ozon ETL System Cron Jobs') !== false) {
            $inETLSection = true;
            continue;
        }
        
        // Check for end of ETL section
        if ($inETLSection && strpos($line, '# End of Ozon ETL System Cron Jobs') !== false) {
            $inETLSection = false;
            continue;
        }
        
        // Skip lines in ETL section
        if ($inETLSection) {
            continue;
        }
        
        $filteredCrontab[] = $line;
    }
    
    return $filteredCrontab;
}

/**
 * Install ETL cron jobs
 */
function installCronJobs(array $cronConfig, array $options): int
{
    try {
        if ($options['verbose']) {
            echo "Installing ETL cron jobs...\n";
        }
        
        // Create backup if requested
        if ($options['backup']) {
            $backupFile = createCrontabBackup($options['verbose']);
            if ($options['verbose']) {
                echo "Backup created: $backupFile\n";
            }
        }
        
        // Get current crontab
        $currentCrontab = getCurrentCrontab();
        
        // Remove existing ETL jobs
        $filteredCrontab = removeETLCronJobs($currentCrontab);
        
        // Generate new ETL jobs
        $etlJobs = generateETLCronJobs($cronConfig, $options['verbose']);
        
        // Combine filtered crontab with new ETL jobs
        $newCrontab = array_merge($filteredCrontab, $etlJobs);
        
        if ($options['dry_run']) {
            echo "DRY RUN - Would install the following cron jobs:\n";
            echo "================================================\n";
            foreach ($etlJobs as $line) {
                echo "$line\n";
            }
            return 0;
        }
        
        // Confirm installation unless forced
        if (!$options['force']) {
            echo "This will install/update ETL cron jobs. Continue? (y/N): ";
            $response = trim(fgets(STDIN));
            if (strtolower($response) !== 'y') {
                echo "Installation cancelled.\n";
                return 0;
            }
        }
        
        // Install new crontab
        if (!setCrontab($newCrontab)) {
            throw new Exception("Failed to install crontab");
        }
        
        if ($options['verbose']) {
            echo "ETL cron jobs installed successfully.\n";
        }
        
        return 0;
        
    } catch (Exception $e) {
        echo "Error installing cron jobs: " . $e->getMessage() . "\n";
        return 1;
    }
}

/**
 * Remove ETL cron jobs
 */
function removeCronJobs(array $options): int
{
    try {
        if ($options['verbose']) {
            echo "Removing ETL cron jobs...\n";
        }
        
        // Create backup if requested
        if ($options['backup']) {
            $backupFile = createCrontabBackup($options['verbose']);
        }
        
        // Get current crontab
        $currentCrontab = getCurrentCrontab();
        
        // Remove ETL jobs
        $filteredCrontab = removeETLCronJobs($currentCrontab);
        
        if ($options['dry_run']) {
            echo "DRY RUN - Would remove ETL cron jobs.\n";
            echo "Remaining cron jobs:\n";
            foreach ($filteredCrontab as $line) {
                if (trim($line) !== '') {
                    echo "$line\n";
                }
            }
            return 0;
        }
        
        // Confirm removal unless forced
        if (!$options['force']) {
            echo "This will remove all ETL cron jobs. Continue? (y/N): ";
            $response = trim(fgets(STDIN));
            if (strtolower($response) !== 'y') {
                echo "Removal cancelled.\n";
                return 0;
            }
        }
        
        // Install filtered crontab
        if (!setCrontab($filteredCrontab)) {
            throw new Exception("Failed to update crontab");
        }
        
        if ($options['verbose']) {
            echo "ETL cron jobs removed successfully.\n";
        }
        
        return 0;
        
    } catch (Exception $e) {
        echo "Error removing cron jobs: " . $e->getMessage() . "\n";
        return 1;
    }
}

/**
 * Show cron job status
 */
function showStatus(array $options): int
{
    try {
        echo "Cron Job Status\n";
        echo "===============\n\n";
        
        $currentCrontab = getCurrentCrontab();
        
        if (empty($currentCrontab)) {
            echo "No crontab found for current user.\n";
            return 0;
        }
        
        $inETLSection = false;
        $etlJobs = [];
        $otherJobs = [];
        
        foreach ($currentCrontab as $line) {
            if (strpos($line, '# Ozon ETL System Cron Jobs') !== false) {
                $inETLSection = true;
                continue;
            }
            
            if ($inETLSection && strpos($line, '# End of Ozon ETL System Cron Jobs') !== false) {
                $inETLSection = false;
                continue;
            }
            
            if ($inETLSection) {
                $etlJobs[] = $line;
            } else {
                $otherJobs[] = $line;
            }
        }
        
        echo "ETL Cron Jobs:\n";
        if (empty($etlJobs)) {
            echo "  No ETL cron jobs found.\n";
        } else {
            foreach ($etlJobs as $line) {
                if (trim($line) !== '' && strpos($line, '#') !== 0) {
                    echo "  $line\n";
                }
            }
        }
        
        echo "\nOther Cron Jobs:\n";
        if (empty($otherJobs)) {
            echo "  No other cron jobs found.\n";
        } else {
            $activeOtherJobs = array_filter($otherJobs, function($line) {
                return trim($line) !== '' && strpos(trim($line), '#') !== 0;
            });
            
            if (empty($activeOtherJobs)) {
                echo "  No other active cron jobs found.\n";
            } else {
                foreach ($activeOtherJobs as $line) {
                    echo "  $line\n";
                }
            }
        }
        
        echo "\nTotal cron jobs: " . count($currentCrontab) . "\n";
        echo "ETL jobs: " . count(array_filter($etlJobs, function($line) {
            return trim($line) !== '' && strpos(trim($line), '#') !== 0;
        })) . "\n";
        
        return 0;
        
    } catch (Exception $e) {
        echo "Error showing status: " . $e->getMessage() . "\n";
        return 1;
    }
}

/**
 * Validate cron configuration
 */
function validateConfiguration(array $cronConfig, array $options): int
{
    try {
        if ($options['verbose']) {
            echo "Validating cron configuration...\n";
        }
        
        $errors = [];
        $warnings = [];
        
        // Validate ETL execution jobs
        if (isset($cronConfig['etl_execution'])) {
            foreach ($cronConfig['etl_execution'] as $jobName => $jobConfig) {
                if (!isset($jobConfig['command'])) {
                    $errors[] = "ETL job '$jobName' missing command";
                }
                
                if (!isset($jobConfig['description'])) {
                    $warnings[] = "ETL job '$jobName' missing description";
                }
                
                if (isset($jobConfig['schedule']) && !empty($jobConfig['schedule'])) {
                    // Basic cron schedule validation
                    $parts = explode(' ', $jobConfig['schedule']);
                    if (count($parts) !== 5) {
                        $errors[] = "ETL job '$jobName' has invalid cron schedule format";
                    }
                }
            }
        }
        
        // Validate monitoring jobs
        if (isset($cronConfig['monitoring'])) {
            foreach ($cronConfig['monitoring'] as $jobName => $jobConfig) {
                if (!isset($jobConfig['command'])) {
                    $errors[] = "Monitoring job '$jobName' missing command";
                }
                
                if (!isset($jobConfig['schedule'])) {
                    $errors[] = "Monitoring job '$jobName' missing schedule";
                }
            }
        }
        
        // Validate directories
        $logDir = $cronConfig['logging']['log_directory'] ?? '/tmp';
        if (!is_dir($logDir) && !mkdir($logDir, 0755, true)) {
            $errors[] = "Cannot create log directory: $logDir";
        }
        
        $lockDir = $cronConfig['locks']['lock_directory'] ?? '/tmp';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true)) {
            $errors[] = "Cannot create lock directory: $lockDir";
        }
        
        // Report results
        if (!empty($errors)) {
            echo "Validation FAILED with errors:\n";
            foreach ($errors as $error) {
                echo "  ERROR: $error\n";
            }
        }
        
        if (!empty($warnings)) {
            echo "Validation warnings:\n";
            foreach ($warnings as $warning) {
                echo "  WARNING: $warning\n";
            }
        }
        
        if (empty($errors) && empty($warnings)) {
            echo "Configuration validation PASSED - no issues found.\n";
        } elseif (empty($errors)) {
            echo "Configuration validation PASSED with warnings.\n";
        }
        
        return empty($errors) ? 0 : 1;
        
    } catch (Exception $e) {
        echo "Error validating configuration: " . $e->getMessage() . "\n";
        return 1;
    }
}

/**
 * Main execution function
 */
function main(): int
{
    global $argv;
    
    $options = parseArguments($argv);
    
    // Show help if requested or no command provided
    if ($options['help'] || $options['command'] === null) {
        showHelp();
        return 0;
    }
    
    // Load configuration
    try {
        $configFile = $options['config_file'] ?? __DIR__ . '/../Config/cron_config.php';
        
        if (!file_exists($configFile)) {
            throw new Exception("Configuration file not found: $configFile");
        }
        
        $cronConfig = require $configFile;
        
    } catch (Exception $e) {
        echo "Error loading configuration: " . $e->getMessage() . "\n";
        return 1;
    }
    
    // Execute command
    switch ($options['command']) {
        case 'install':
            return installCronJobs($cronConfig, $options);
            
        case 'remove':
            return removeCronJobs($options);
            
        case 'status':
            return showStatus($options);
            
        case 'validate':
            return validateConfiguration($cronConfig, $options);
            
        case 'backup':
            try {
                $backupFile = createCrontabBackup($options['verbose']);
                echo "Backup created: $backupFile\n";
                return 0;
            } catch (Exception $e) {
                echo "Error creating backup: " . $e->getMessage() . "\n";
                return 1;
            }
            
        default:
            echo "Unknown command: {$options['command']}\n";
            echo "Use --help for usage information.\n";
            return 1;
    }
}

// Execute main function
exit(main());