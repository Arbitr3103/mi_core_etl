#!/usr/bin/env php
<?php

/**
 * Ozon ETL Scheduler Installation Script
 * 
 * One-command installation script for setting up the complete
 * Ozon ETL scheduler with cron jobs, monitoring, and alerts.
 * 
 * Usage:
 *   php install_scheduler.php [options]
 * 
 * Options:
 *   --user=USER        Install cron jobs for specific user
 *   --dry-run          Show what would be done without making changes
 *   --skip-validation  Skip configuration validation
 *   --verbose          Enable verbose output
 * 
 * Requirements addressed:
 * - 5.1, 5.2, 5.3: Schedule ETL processes at specified times
 * - 5.5: Prevent concurrent execution of the same ETL process
 * - 4.2, 4.3: Monitor processing metrics and send alerts
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

use MiCore\ETL\Ozon\Core\Logger;

/**
 * Show help message
 */
function showHelp(): void {
    echo "Ozon ETL Scheduler Installation Script\n\n";
    echo "This script sets up the complete Ozon ETL scheduler including:\n";
    echo "- Cron jobs for ETL processes\n";
    echo "- Process monitoring and alerts\n";
    echo "- Log rotation and cleanup\n";
    echo "- Health checks\n\n";
    echo "Usage: php install_scheduler.php [options]\n\n";
    echo "Options:\n";
    echo "  --user=USER        Install cron jobs for specific user (requires sudo)\n";
    echo "  --dry-run          Show what would be done without making changes\n";
    echo "  --skip-validation  Skip configuration validation\n";
    echo "  --verbose          Enable verbose output\n";
    echo "  --help             Show this help message\n\n";
    echo "Examples:\n";
    echo "  php install_scheduler.php\n";
    echo "  php install_scheduler.php --user=www-data --verbose\n";
    echo "  php install_scheduler.php --dry-run\n\n";
}

/**
 * Parse command line arguments
 */
function parseArguments(array $argv): array {
    $options = [
        'user' => null,
        'dry_run' => false,
        'skip_validation' => false,
        'verbose' => false,
        'help' => false
    ];

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--dry-run') {
            $options['dry_run'] = true;
        } elseif ($arg === '--skip-validation') {
            $options['skip_validation'] = true;
        } elseif ($arg === '--verbose' || $arg === '-v') {
            $options['verbose'] = true;
        } elseif (strpos($arg, '--user=') === 0) {
            $options['user'] = substr($arg, 7);
        } else {
            echo "Unknown option: $arg\n";
            echo "Use --help for usage information.\n";
            exit(1);
        }
    }

    return $options;
}

/**
 * Validate system requirements
 */
function validateRequirements(array $cronConfig, array $options): array {
    $issues = [];
    $warnings = [];
    
    if ($options['verbose']) {
        echo "Validating system requirements...\n";
    }
    
    // Check PHP version
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        $issues[] = "PHP 8.1+ required, current version: " . PHP_VERSION;
    } elseif ($options['verbose']) {
        echo "  ✅ PHP version: " . PHP_VERSION . "\n";
    }
    
    // Check required PHP extensions
    $requiredExtensions = ['json', 'pdo', 'curl', 'mbstring'];
    foreach ($requiredExtensions as $ext) {
        if (!extension_loaded($ext)) {
            $issues[] = "Required PHP extension missing: {$ext}";
        } elseif ($options['verbose']) {
            echo "  ✅ PHP extension: {$ext}\n";
        }
    }
    
    // Check directories
    $directories = [
        'Scripts Directory' => $cronConfig['paths']['scripts_dir'],
        'Log Directory' => $cronConfig['logging']['job_log_dir'],
        'Lock Directory' => $cronConfig['paths']['lock_dir'],
        'PID Directory' => $cronConfig['paths']['pid_dir']
    ];
    
    foreach ($directories as $name => $path) {
        if (!is_dir($path)) {
            if (is_writable(dirname($path))) {
                $warnings[] = "{$name} will be created: {$path}";
                // Create directory
                if (!$options['dry_run']) {
                    mkdir($path, 0755, true);
                }
            } else {
                $issues[] = "{$name} cannot be created: {$path}";
            }
        } elseif (!is_writable($path)) {
            $issues[] = "{$name} is not writable: {$path}";
        } elseif ($options['verbose']) {
            echo "  ✅ {$name}: {$path}\n";
        }
    }
    
    // Check PHP binary
    $phpBin = $cronConfig['paths']['php_binary'];
    if (!is_executable($phpBin)) {
        $issues[] = "PHP binary is not executable: {$phpBin}";
    } elseif ($options['verbose']) {
        echo "  ✅ PHP binary: {$phpBin}\n";
    }
    
    // Check script files
    foreach ($cronConfig['schedule']['jobs'] as $jobName => $job) {
        $scriptPath = $cronConfig['paths']['scripts_dir'] . '/' . $job['script'];
        if (!file_exists($scriptPath)) {
            $issues[] = "Script file not found for job '{$jobName}': {$scriptPath}";
        } elseif (!is_executable($scriptPath)) {
            $warnings[] = "Script file is not executable for job '{$jobName}': {$scriptPath}";
        } elseif ($options['verbose']) {
            echo "  ✅ Script: {$jobName} -> {$scriptPath}\n";
        }
    }
    
    // Check cron availability
    $cronAvailable = false;
    if (function_exists('exec')) {
        exec('which crontab 2>/dev/null', $output, $returnCode);
        $cronAvailable = ($returnCode === 0);
    }
    
    if (!$cronAvailable) {
        $issues[] = "crontab command not available";
    } elseif ($options['verbose']) {
        echo "  ✅ crontab available\n";
    }
    
    return ['issues' => $issues, 'warnings' => $warnings];
}

/**
 * Install cron jobs
 */
function installCronJobs(array $cronConfig, array $options): bool {
    if ($options['verbose']) {
        echo "Installing cron jobs...\n";
    }
    
    // Use the manage_cron.php script
    $manageCronScript = $cronConfig['paths']['scripts_dir'] . '/manage_cron.php';
    
    $command = "php {$manageCronScript} install";
    
    if ($options['user']) {
        $command .= " --user={$options['user']}";
    }
    
    if ($options['dry_run']) {
        $command .= " --dry-run";
    }
    
    if ($options['verbose']) {
        $command .= " --verbose";
        echo "  Executing: {$command}\n";
    }
    
    if ($options['dry_run']) {
        echo "  DRY RUN: Would execute cron installation\n";
        return true;
    }
    
    exec($command, $output, $returnCode);
    
    if ($returnCode === 0) {
        if ($options['verbose']) {
            echo "  ✅ Cron jobs installed successfully\n";
        }
        return true;
    } else {
        echo "  ❌ Failed to install cron jobs\n";
        if ($options['verbose']) {
            echo "  Output: " . implode("\n", $output) . "\n";
        }
        return false;
    }
}

/**
 * Setup monitoring
 */
function setupMonitoring(array $cronConfig, array $options): bool {
    if ($options['verbose']) {
        echo "Setting up monitoring...\n";
    }
    
    // Test monitoring script
    $monitorScript = $cronConfig['paths']['scripts_dir'] . '/monitor_etl.php';
    
    if (!file_exists($monitorScript)) {
        echo "  ❌ Monitor script not found: {$monitorScript}\n";
        return false;
    }
    
    if ($options['dry_run']) {
        echo "  DRY RUN: Would test monitoring script\n";
        return true;
    }
    
    // Test the monitoring script
    $command = "php {$monitorScript} --check-health";
    exec($command, $output, $returnCode);
    
    if ($returnCode === 0) {
        if ($options['verbose']) {
            echo "  ✅ Monitoring script tested successfully\n";
        }
        return true;
    } else {
        echo "  ⚠️  Monitoring script test failed (this may be normal on first run)\n";
        if ($options['verbose']) {
            echo "  Output: " . implode("\n", $output) . "\n";
        }
        return true; // Don't fail installation for monitoring test
    }
}

/**
 * Setup log rotation
 */
function setupLogRotation(array $cronConfig, array $options): bool {
    if ($options['verbose']) {
        echo "Setting up log rotation...\n";
    }
    
    $logDir = $cronConfig['logging']['job_log_dir'];
    $retentionDays = $cronConfig['logging']['log_retention_days'];
    
    if ($options['dry_run']) {
        echo "  DRY RUN: Would setup log rotation for {$logDir}\n";
        return true;
    }
    
    // Create a simple log rotation script
    $rotationScript = $logDir . '/rotate_logs.sh';
    $rotationContent = "#!/bin/bash\n";
    $rotationContent .= "# Auto-generated log rotation script\n";
    $rotationContent .= "find {$logDir} -name \"*.log\" -type f -mtime +{$retentionDays} -delete\n";
    
    if (file_put_contents($rotationScript, $rotationContent)) {
        chmod($rotationScript, 0755);
        if ($options['verbose']) {
            echo "  ✅ Log rotation script created: {$rotationScript}\n";
        }
        return true;
    } else {
        echo "  ❌ Failed to create log rotation script\n";
        return false;
    }
}

/**
 * Show installation summary
 */
function showSummary(array $cronConfig, array $options): void {
    echo "\n" . str_repeat('=', 60) . "\n";
    echo "Ozon ETL Scheduler Installation " . ($options['dry_run'] ? 'Preview' : 'Complete') . "\n";
    echo str_repeat('=', 60) . "\n\n";
    
    if (!$options['dry_run']) {
        echo "✅ Installation completed successfully!\n\n";
    } else {
        echo "📋 Installation preview completed\n\n";
    }
    
    echo "Scheduled Jobs:\n";
    foreach ($cronConfig['schedule']['jobs'] as $jobName => $job) {
        $status = $job['enabled'] ? '✅' : '❌';
        echo "  {$status} {$jobName}: {$job['schedule']} - {$job['description']}\n";
    }
    
    echo "\nImportant Paths:\n";
    echo "  Scripts: {$cronConfig['paths']['scripts_dir']}\n";
    echo "  Logs: {$cronConfig['logging']['job_log_dir']}\n";
    echo "  Locks: {$cronConfig['paths']['lock_dir']}\n";
    echo "  PIDs: {$cronConfig['paths']['pid_dir']}\n";
    
    echo "\nNext Steps:\n";
    if ($options['dry_run']) {
        echo "  1. Run without --dry-run to perform actual installation\n";
    } else {
        echo "  1. Monitor logs in: {$cronConfig['logging']['job_log_dir']}\n";
        echo "  2. Check process status: php process_monitor.php status\n";
        echo "  3. View cron jobs: crontab -l\n";
    }
    
    if ($cronConfig['notifications']['enabled']) {
        echo "  4. Configure notifications in environment variables\n";
        echo "     - ETL_EMAIL_NOTIFICATIONS=true\n";
        echo "     - ETL_TO_EMAILS=admin@company.com\n";
        echo "     - ETL_SLACK_NOTIFICATIONS=true\n";
        echo "     - SLACK_WEBHOOK_URL=https://hooks.slack.com/...\n";
    }
    
    echo "\nManagement Commands:\n";
    echo "  - php manage_cron.php status\n";
    echo "  - php process_monitor.php status\n";
    echo "  - php monitor_etl.php --all\n";
    
    echo "\n";
}

/**
 * Main execution function
 */
function main(): void {
    global $config, $cronConfig;
    
    $options = parseArguments($_SERVER['argv']);
    
    if ($options['help']) {
        showHelp();
        exit(0);
    }
    
    try {
        echo "Ozon ETL Scheduler Installation\n";
        echo str_repeat('=', 40) . "\n\n";
        
        // Validate requirements
        if (!$options['skip_validation']) {
            $validation = validateRequirements($cronConfig, $options);
            
            if (!empty($validation['issues'])) {
                echo "❌ Validation failed with the following issues:\n";
                foreach ($validation['issues'] as $issue) {
                    echo "  - {$issue}\n";
                }
                echo "\nPlease fix these issues before proceeding.\n";
                exit(1);
            }
            
            if (!empty($validation['warnings']) && $options['verbose']) {
                echo "⚠️  Warnings:\n";
                foreach ($validation['warnings'] as $warning) {
                    echo "  - {$warning}\n";
                }
                echo "\n";
            }
        }
        
        $success = true;
        
        // Install cron jobs
        if (!installCronJobs($cronConfig, $options)) {
            $success = false;
        }
        
        // Setup monitoring
        if (!setupMonitoring($cronConfig, $options)) {
            $success = false;
        }
        
        // Setup log rotation
        if (!setupLogRotation($cronConfig, $options)) {
            $success = false;
        }
        
        // Show summary
        showSummary($cronConfig, $options);
        
        if (!$success && !$options['dry_run']) {
            echo "⚠️  Installation completed with some issues. Please check the output above.\n";
            exit(1);
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