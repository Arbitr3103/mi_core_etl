#!/usr/bin/env php
<?php
/**
 * MDM Async Update Processor
 * Processes queued report updates and cache invalidations
 */

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load environment configuration
function loadEnvConfig() {
    $envFile = __DIR__ . '/.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $value = trim($value, '"\'');
                $_ENV[trim($key)] = $value;
            }
        }
    }
}

loadEnvConfig();

// Database connection
try {
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=mi_core;charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    echo "Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Include required services
require_once __DIR__ . '/src/MDM/Services/ReportUpdateService.php';
require_once __DIR__ . '/src/MDM/Services/CacheService.php';
require_once __DIR__ . '/src/MDM/Services/NotificationService.php';

// Parse command line arguments
$options = getopt('h', ['help', 'batch-size:', 'daemon', 'interval:', 'verbose', 'cleanup']);

if (isset($options['h']) || isset($options['help'])) {
    showHelp();
    exit(0);
}

$batchSize = (int)($options['batch-size'] ?? 10);
$isDaemon = isset($options['daemon']);
$interval = (int)($options['interval'] ?? 60); // seconds
$verbose = isset($options['verbose']);
$cleanup = isset($options['cleanup']);

// Initialize services
$cacheService = new MDM\Services\CacheService();
$notificationService = new MDM\Services\NotificationService([
    'enabled' => true,
    'channels' => [
        'log' => ['enabled' => true, 'log_file' => __DIR__ . '/logs/mdm_async.log']
    ]
]);
$reportUpdateService = new MDM\Services\ReportUpdateService($pdo, $cacheService, $notificationService);

// Main execution
if ($cleanup) {
    performCleanup($pdo, $cacheService, $verbose);
} elseif ($isDaemon) {
    runDaemon($reportUpdateService, $batchSize, $interval, $verbose);
} else {
    runSingleBatch($reportUpdateService, $batchSize, $verbose);
}

/**
 * Show help message
 */
function showHelp() {
    echo "MDM Async Update Processor\n";
    echo "Usage: php mdm_async_processor.php [options]\n\n";
    echo "Options:\n";
    echo "  -h, --help           Show this help message\n";
    echo "  --batch-size=N       Number of tasks to process per batch (default: 10)\n";
    echo "  --daemon             Run as daemon (continuous processing)\n";
    echo "  --interval=N         Daemon sleep interval in seconds (default: 60)\n";
    echo "  --verbose            Enable verbose output\n";
    echo "  --cleanup            Clean up old tasks and cache\n\n";
    echo "Examples:\n";
    echo "  php mdm_async_processor.php                    # Process one batch\n";
    echo "  php mdm_async_processor.php --daemon           # Run as daemon\n";
    echo "  php mdm_async_processor.php --cleanup          # Clean up old data\n";
    echo "  php mdm_async_processor.php --verbose          # Verbose output\n";
}

/**
 * Run single batch processing
 */
function runSingleBatch($reportUpdateService, $batchSize, $verbose) {
    if ($verbose) {
        echo "[" . date('Y-m-d H:i:s') . "] Starting single batch processing (batch size: $batchSize)\n";
    }
    
    $processed = $reportUpdateService->processAsyncUpdateQueue($batchSize);
    
    if ($verbose) {
        echo "[" . date('Y-m-d H:i:s') . "] Processed $processed tasks\n";
    }
    
    // Also recalculate data quality metrics
    if ($verbose) {
        echo "[" . date('Y-m-d H:i:s') . "] Recalculating data quality metrics\n";
    }
    
    $metrics = $reportUpdateService->recalculateDataQualityMetrics();
    
    if ($verbose && $metrics) {
        echo "[" . date('Y-m-d H:i:s') . "] Updated " . count($metrics) . " quality metrics\n";
    }
    
    echo "Batch processing completed. Processed $processed tasks.\n";
}

/**
 * Run as daemon
 */
function runDaemon($reportUpdateService, $batchSize, $interval, $verbose) {
    if ($verbose) {
        echo "[" . date('Y-m-d H:i:s') . "] Starting daemon mode (batch size: $batchSize, interval: {$interval}s)\n";
    }
    
    // Set up signal handlers for graceful shutdown
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGTERM, 'signalHandler');
        pcntl_signal(SIGINT, 'signalHandler');
    }
    
    $running = true;
    $totalProcessed = 0;
    $cycles = 0;
    
    while ($running) {
        $cycleStart = time();
        $cycles++;
        
        if ($verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] Processing cycle #$cycles\n";
        }
        
        try {
            // Process async updates
            $processed = $reportUpdateService->processAsyncUpdateQueue($batchSize);
            $totalProcessed += $processed;
            
            if ($verbose && $processed > 0) {
                echo "[" . date('Y-m-d H:i:s') . "] Processed $processed tasks\n";
            }
            
            // Recalculate data quality metrics every 10 cycles
            if ($cycles % 10 == 0) {
                if ($verbose) {
                    echo "[" . date('Y-m-d H:i:s') . "] Recalculating data quality metrics (cycle #$cycles)\n";
                }
                
                $metrics = $reportUpdateService->recalculateDataQualityMetrics();
                
                if ($verbose && $metrics) {
                    echo "[" . date('Y-m-d H:i:s') . "] Updated " . count($metrics) . " quality metrics\n";
                }
            }
            
            // Clean up every 100 cycles
            if ($cycles % 100 == 0) {
                if ($verbose) {
                    echo "[" . date('Y-m-d H:i:s') . "] Performing cleanup (cycle #$cycles)\n";
                }
                
                performCleanup($reportUpdateService->getPdo(), null, $verbose);
            }
            
        } catch (Exception $e) {
            echo "[" . date('Y-m-d H:i:s') . "] Error in processing cycle: " . $e->getMessage() . "\n";
        }
        
        // Handle signals
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        // Check if we should continue running
        if (isset($GLOBALS['shutdown'])) {
            $running = false;
            break;
        }
        
        // Sleep for the specified interval
        $cycleTime = time() - $cycleStart;
        $sleepTime = max(0, $interval - $cycleTime);
        
        if ($verbose && $sleepTime > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] Sleeping for {$sleepTime}s\n";
        }
        
        if ($sleepTime > 0) {
            sleep($sleepTime);
        }
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Daemon shutting down. Total processed: $totalProcessed tasks in $cycles cycles.\n";
}

/**
 * Perform cleanup operations
 */
function performCleanup($pdo, $cacheService = null, $verbose = false) {
    if ($verbose) {
        echo "[" . date('Y-m-d H:i:s') . "] Starting cleanup operations\n";
    }
    
    try {
        // Clean up old async tasks (older than 7 days)
        $stmt = $pdo->prepare("
            DELETE FROM async_update_queue 
            WHERE status IN ('completed', 'failed') 
            AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $deletedTasks = $stmt->rowCount();
        
        if ($verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] Deleted $deletedTasks old async tasks\n";
        }
        
        // Clean up old change logs (older than 30 days)
        $stmt = $pdo->prepare("
            DELETE FROM data_change_log 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute();
        $deletedLogs = $stmt->rowCount();
        
        if ($verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] Deleted $deletedLogs old change logs\n";
        }
        
        // Clean up old data quality metrics (keep only latest 1000 per metric)
        $stmt = $pdo->query("
            SELECT DISTINCT metric_name FROM data_quality_metrics
        ");
        $metrics = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $deletedMetrics = 0;
        foreach ($metrics as $metric) {
            $stmt = $pdo->prepare("
                DELETE FROM data_quality_metrics 
                WHERE metric_name = ? 
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM data_quality_metrics 
                        WHERE metric_name = ? 
                        ORDER BY calculation_date DESC 
                        LIMIT 1000
                    ) AS keep_metrics
                )
            ");
            $stmt->execute([$metric, $metric]);
            $deletedMetrics += $stmt->rowCount();
        }
        
        if ($verbose) {
            echo "[" . date('Y-m-d H:i:s') . "] Deleted $deletedMetrics old quality metrics\n";
        }
        
        // Clean up cache if service is available
        if ($cacheService) {
            $cleaned = $cacheService->cleanup();
            if ($verbose) {
                echo "[" . date('Y-m-d H:i:s') . "] Cleaned up $cleaned expired cache files\n";
            }
        }
        
        echo "Cleanup completed. Deleted: $deletedTasks tasks, $deletedLogs logs, $deletedMetrics metrics\n";
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Cleanup error: " . $e->getMessage() . "\n";
    }
}

/**
 * Signal handler for graceful shutdown
 */
function signalHandler($signal) {
    echo "\n[" . date('Y-m-d H:i:s') . "] Received signal $signal, shutting down gracefully...\n";
    $GLOBALS['shutdown'] = true;
}

/**
 * Get process statistics
 */
function getProcessStats($pdo) {
    $stats = [];
    
    // Pending tasks
    $stmt = $pdo->query("SELECT COUNT(*) FROM async_update_queue WHERE status = 'pending'");
    $stats['pending_tasks'] = $stmt->fetchColumn();
    
    // Processing tasks
    $stmt = $pdo->query("SELECT COUNT(*) FROM async_update_queue WHERE status = 'processing'");
    $stats['processing_tasks'] = $stmt->fetchColumn();
    
    // Failed tasks
    $stmt = $pdo->query("SELECT COUNT(*) FROM async_update_queue WHERE status = 'failed'");
    $stats['failed_tasks'] = $stmt->fetchColumn();
    
    // Recent changes (last hour)
    $stmt = $pdo->query("SELECT COUNT(*) FROM data_change_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stats['recent_changes'] = $stmt->fetchColumn();
    
    return $stats;
}
?>