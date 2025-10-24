#!/usr/bin/env php
<?php
/**
 * Setup Recovery Procedures Script
 * 
 * Initializes default recovery procedures for common ETL failure scenarios.
 * This script should be run once during system setup or when updating recovery procedures.
 * 
 * Usage:
 *   php setup_recovery_procedures.php [options]
 * 
 * Options:
 *   --reset           Reset all existing procedures and create new ones
 *   --update          Update existing procedures with new versions
 *   --list            List all current procedures
 *   --help            Show help message
 * 
 * @version 1.0
 * @author Manhattan System
 */

// Ensure script is run from command line
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

// Set error reporting and timezone
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

// Define paths
define('ROOT_DIR', dirname(dirname(__DIR__)));
define('SRC_DIR', ROOT_DIR . '/src');

// Include required files
require_once SRC_DIR . '/config/database.php';

/**
 * Recovery Procedures Setup Class
 */
class RecoveryProceduresSetup {
    
    private $pdo;
    private $options;
    
    /**
     * Constructor
     */
    public function __construct(array $options = []) {
        $this->options = $options;
        $this->initializeDatabase();
    }
    
    /**
     * Initialize database connection
     */
    private function initializeDatabase(): void {
        try {
            $config = include SRC_DIR . '/config/database.php';
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            
            echo "Database connection established successfully\n";
            
        } catch (Exception $e) {
            die("Failed to connect to database: " . $e->getMessage() . "\n");
        }
    }
    
    /**
     * Main execution method
     */
    public function run(): void {
        if (isset($this->options['help'])) {
            $this->showHelp();
            return;
        }
        
        if (isset($this->options['list'])) {
            $this->listProcedures();
            return;
        }
        
        if (isset($this->options['reset'])) {
            $this->resetProcedures();
        }
        
        $this->setupDefaultProcedures();
        
        echo "Recovery procedures setup completed successfully\n";
    }
    
    /**
     * Setup default recovery procedures
     */
    private function setupDefaultProcedures(): void {
        echo "Setting up default recovery procedures...\n";
        
        $procedures = $this->getDefaultProcedures();
        
        foreach ($procedures as $procedure) {
            $this->createOrUpdateProcedure($procedure);
        }
        
        echo "Created/updated " . count($procedures) . " recovery procedures\n";
    }
    
    /**
     * Get default recovery procedures
     */
    private function getDefaultProcedures(): array {
        return [
            [
                'procedure_name' => 'network_timeout_recovery',
                'trigger_condition' => 'network_timeout',
                'recovery_steps' => [
                    [
                        'type' => 'wait',
                        'params' => ['seconds' => 60],
                        'critical' => false,
                        'description' => 'Wait 60 seconds for network to stabilize'
                    ],
                    [
                        'type' => 'send_notification',
                        'params' => [
                            'message' => 'Network timeout detected, attempting recovery',
                            'severity' => 'warning'
                        ],
                        'critical' => false,
                        'description' => 'Notify administrators of network issues'
                    ]
                ]
            ],
            
            [
                'procedure_name' => 'api_rate_limit_recovery',
                'trigger_condition' => 'api_rate_limit',
                'recovery_steps' => [
                    [
                        'type' => 'wait',
                        'params' => ['seconds' => 300],
                        'critical' => true,
                        'description' => 'Wait 5 minutes for rate limit to reset'
                    ],
                    [
                        'type' => 'send_notification',
                        'params' => [
                            'message' => 'API rate limit exceeded, waiting for reset',
                            'severity' => 'info'
                        ],
                        'critical' => false,
                        'description' => 'Notify about rate limit hit'
                    ]
                ]
            ],
            
            [
                'procedure_name' => 'authentication_failed_recovery',
                'trigger_condition' => 'authentication_failed',
                'recovery_steps' => [
                    [
                        'type' => 'send_notification',
                        'params' => [
                            'message' => 'CRITICAL: API authentication failed - manual intervention required',
                            'severity' => 'critical'
                        ],
                        'critical' => true,
                        'description' => 'Send critical alert for authentication failure'
                    ],
                    [
                        'type' => 'clear_locks',
                        'params' => [],
                        'critical' => false,
                        'description' => 'Clear any existing locks to prevent deadlock'
                    ]
                ]
            ],
            
            [
                'procedure_name' => 'database_connection_recovery',
                'trigger_condition' => 'database_connection_lost',
                'recovery_steps' => [
                    [
                        'type' => 'wait',
                        'params' => ['seconds' => 30],
                        'critical' => false,
                        'description' => 'Wait for database connection to stabilize'
                    ],
                    [
                        'type' => 'clear_locks',
                        'params' => [],
                        'critical' => false,
                        'description' => 'Clear database locks that may be held'
                    ],
                    [
                        'type' => 'restart_etl',
                        'params' => ['delay_minutes' => 5],
                        'critical' => false,
                        'description' => 'Schedule ETL restart after 5 minutes'
                    ]
                ]
            ],
            
            [
                'procedure_name' => 'file_download_recovery',
                'trigger_condition' => 'file_download_failed',
                'recovery_steps' => [
                    [
                        'type' => 'cleanup_temp_data',
                        'params' => [],
                        'critical' => false,
                        'description' => 'Clean up any partial download files'
                    ],
                    [
                        'type' => 'wait',
                        'params' => ['seconds' => 120],
                        'critical' => false,
                        'description' => 'Wait 2 minutes before retry'
                    ]
                ]
            ],
            
            [
                'procedure_name' => 'report_generation_timeout_recovery',
                'trigger_condition' => 'report_generation_timeout',
                'recovery_steps' => [
                    [
                        'type' => 'send_notification',
                        'params' => [
                            'message' => 'Report generation timeout - may indicate Ozon API issues',
                            'severity' => 'warning'
                        ],
                        'critical' => false,
                        'description' => 'Notify about potential API issues'
                    ],
                    [
                        'type' => 'restart_etl',
                        'params' => ['delay_minutes' => 30],
                        'critical' => false,
                        'description' => 'Schedule ETL restart after 30 minutes'
                    ]
                ]
            ],
            
            [
                'procedure_name' => 'memory_exhausted_recovery',
                'trigger_condition' => 'memory_exhausted',
                'recovery_steps' => [
                    [
                        'type' => 'cleanup_temp_data',
                        'params' => [],
                        'critical' => true,
                        'description' => 'Clean up temporary data to free memory'
                    ],
                    [
                        'type' => 'clear_locks',
                        'params' => [],
                        'critical' => true,
                        'description' => 'Clear locks to prevent resource holding'
                    ],
                    [
                        'type' => 'send_notification',
                        'params' => [
                            'message' => 'CRITICAL: Memory exhausted - system may need more resources',
                            'severity' => 'critical'
                        ],
                        'critical' => true,
                        'description' => 'Send critical alert about memory issues'
                    ],
                    [
                        'type' => 'restart_etl',
                        'params' => ['delay_minutes' => 60],
                        'critical' => false,
                        'description' => 'Schedule ETL restart after 1 hour'
                    ]
                ]
            ],
            
            [
                'procedure_name' => 'disk_space_full_recovery',
                'trigger_condition' => 'disk_space_full',
                'recovery_steps' => [
                    [
                        'type' => 'cleanup_temp_data',
                        'params' => [],
                        'critical' => true,
                        'description' => 'Clean up temporary files to free disk space'
                    ],
                    [
                        'type' => 'send_notification',
                        'params' => [
                            'message' => 'CRITICAL: Disk space full - immediate attention required',
                            'severity' => 'critical'
                        ],
                        'critical' => true,
                        'description' => 'Send critical alert about disk space'
                    ]
                ]
            ],
            
            [
                'procedure_name' => 'generic_error_recovery',
                'trigger_condition' => 'any_error',
                'recovery_steps' => [
                    [
                        'type' => 'wait',
                        'params' => ['seconds' => 30],
                        'critical' => false,
                        'description' => 'Wait 30 seconds before any action'
                    ],
                    [
                        'type' => 'reset_retry_counters',
                        'params' => [],
                        'critical' => false,
                        'description' => 'Reset retry counters for fresh start'
                    ],
                    [
                        'type' => 'send_notification',
                        'params' => [
                            'message' => 'Generic error recovery procedure executed',
                            'severity' => 'info'
                        ],
                        'critical' => false,
                        'description' => 'Log recovery attempt'
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Create or update a recovery procedure
     */
    private function createOrUpdateProcedure(array $procedure): void {
        try {
            $updateMode = isset($this->options['update']);
            
            if ($updateMode) {
                // Update existing procedure
                $sql = "UPDATE ozon_etl_recovery_procedures 
                        SET recovery_steps = :recovery_steps, updated_at = NOW()
                        WHERE procedure_name = :procedure_name";
                
                $stmt = $this->pdo->prepare($sql);
                $result = $stmt->execute([
                    'procedure_name' => $procedure['procedure_name'],
                    'recovery_steps' => json_encode($procedure['recovery_steps'])
                ]);
                
                if ($stmt->rowCount() > 0) {
                    echo "Updated procedure: {$procedure['procedure_name']}\n";
                } else {
                    // Procedure doesn't exist, create it
                    $this->insertProcedure($procedure);
                }
            } else {
                // Insert or replace procedure
                $sql = "INSERT INTO ozon_etl_recovery_procedures 
                        (procedure_name, trigger_condition, recovery_steps, is_active)
                        VALUES (:procedure_name, :trigger_condition, :recovery_steps, TRUE)
                        ON DUPLICATE KEY UPDATE
                        recovery_steps = VALUES(recovery_steps),
                        is_active = TRUE,
                        updated_at = NOW()";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    'procedure_name' => $procedure['procedure_name'],
                    'trigger_condition' => $procedure['trigger_condition'],
                    'recovery_steps' => json_encode($procedure['recovery_steps'])
                ]);
                
                echo "Created/updated procedure: {$procedure['procedure_name']}\n";
            }
            
        } catch (Exception $e) {
            echo "Failed to create/update procedure {$procedure['procedure_name']}: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Insert new procedure
     */
    private function insertProcedure(array $procedure): void {
        $sql = "INSERT INTO ozon_etl_recovery_procedures 
                (procedure_name, trigger_condition, recovery_steps, is_active)
                VALUES (:procedure_name, :trigger_condition, :recovery_steps, TRUE)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'procedure_name' => $procedure['procedure_name'],
            'trigger_condition' => $procedure['trigger_condition'],
            'recovery_steps' => json_encode($procedure['recovery_steps'])
        ]);
        
        echo "Created new procedure: {$procedure['procedure_name']}\n";
    }
    
    /**
     * Reset all procedures
     */
    private function resetProcedures(): void {
        echo "Resetting all recovery procedures...\n";
        
        try {
            $sql = "DELETE FROM ozon_etl_recovery_procedures";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $deletedCount = $stmt->rowCount();
            echo "Deleted $deletedCount existing procedures\n";
            
        } catch (Exception $e) {
            echo "Failed to reset procedures: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * List all current procedures
     */
    private function listProcedures(): void {
        try {
            $sql = "SELECT procedure_name, trigger_condition, is_active, success_count, 
                           failure_count, last_executed_at, created_at
                    FROM ozon_etl_recovery_procedures 
                    ORDER BY procedure_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $procedures = $stmt->fetchAll();
            
            if (empty($procedures)) {
                echo "No recovery procedures found\n";
                return;
            }
            
            echo "\nCurrent Recovery Procedures:\n";
            echo str_repeat("=", 80) . "\n";
            
            foreach ($procedures as $procedure) {
                echo "Name: {$procedure['procedure_name']}\n";
                echo "Trigger: {$procedure['trigger_condition']}\n";
                echo "Active: " . ($procedure['is_active'] ? 'Yes' : 'No') . "\n";
                echo "Success/Failure: {$procedure['success_count']}/{$procedure['failure_count']}\n";
                echo "Last Executed: " . ($procedure['last_executed_at'] ?? 'Never') . "\n";
                echo "Created: {$procedure['created_at']}\n";
                
                // Show recovery steps
                $this->showProcedureSteps($procedure['procedure_name']);
                
                echo str_repeat("-", 80) . "\n";
            }
            
        } catch (Exception $e) {
            echo "Failed to list procedures: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Show recovery steps for a procedure
     */
    private function showProcedureSteps(string $procedureName): void {
        try {
            $sql = "SELECT recovery_steps FROM ozon_etl_recovery_procedures 
                    WHERE procedure_name = :procedure_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['procedure_name' => $procedureName]);
            
            $result = $stmt->fetch();
            
            if ($result) {
                $steps = json_decode($result['recovery_steps'], true);
                
                echo "Recovery Steps:\n";
                foreach ($steps as $index => $step) {
                    $stepNum = $index + 1;
                    $critical = $step['critical'] ? ' (CRITICAL)' : '';
                    echo "  $stepNum. {$step['type']}$critical\n";
                    
                    if (!empty($step['description'])) {
                        echo "     Description: {$step['description']}\n";
                    }
                    
                    if (!empty($step['params'])) {
                        echo "     Parameters: " . json_encode($step['params']) . "\n";
                    }
                }
            }
            
        } catch (Exception $e) {
            echo "Failed to show procedure steps: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Show help message
     */
    private function showHelp(): void {
        echo "Setup Recovery Procedures Script\n";
        echo "================================\n\n";
        echo "Usage: php setup_recovery_procedures.php [options]\n\n";
        echo "Options:\n";
        echo "  --reset           Reset all existing procedures and create new ones\n";
        echo "  --update          Update existing procedures with new versions\n";
        echo "  --list            List all current procedures\n";
        echo "  --help            Show this help message\n\n";
        echo "Examples:\n";
        echo "  php setup_recovery_procedures.php\n";
        echo "  php setup_recovery_procedures.php --reset\n";
        echo "  php setup_recovery_procedures.php --list\n";
        echo "  php setup_recovery_procedures.php --update\n\n";
        echo "This script sets up default recovery procedures for common ETL failure scenarios.\n";
        echo "Run it once during system setup or when updating recovery procedures.\n\n";
    }
}

// Parse command line arguments
$options = [];
$args = array_slice($argv, 1);

foreach ($args as $arg) {
    if (strpos($arg, '--') === 0) {
        $key = substr($arg, 2);
        $options[$key] = true;
    }
}

// Create and run the setup
try {
    $setup = new RecoveryProceduresSetup($options);
    $setup->run();
} catch (Exception $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}