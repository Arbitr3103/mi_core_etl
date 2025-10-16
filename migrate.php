<?php

require_once 'vendor/autoload.php';

use MDM\Database\DatabaseConnection;
use MDM\Migrations\MigrationManager;
use MDM\Migrations\Migrations\Migration_001_CreateInitialSchema;
use MDM\Migrations\Migrations\Migration_002_CreateAuditTables;
use MDM\Migrations\Migrations\Migration_003_CreateViewsAndTriggers;
use MDM\Migrations\Migrations\Migration_004_CreateProductActivityTables;

/**
 * MDM System Migration Runner
 * 
 * Command-line tool for managing database migrations.
 */

// Configuration
$config = [
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => $_ENV['DB_PORT'] ?? 3306,
    'database' => $_ENV['DB_NAME'] ?? 'mdm_system',
    'username' => $_ENV['DB_USER'] ?? 'root',
    'password' => $_ENV['DB_PASS'] ?? '',
    'charset' => 'utf8mb4'
];

// Parse command line arguments
$command = $argv[1] ?? 'help';
$options = array_slice($argv, 2);

try {
    // Set database configuration
    DatabaseConnection::setConfig($config);
    
    // Create database if it doesn't exist
    if (!DatabaseConnection::databaseExists()) {
        echo "Database '{$config['database']}' does not exist. Creating...\n";
        DatabaseConnection::createDatabase();
        echo "Database created successfully.\n";
    }
    
    // Get PDO connection
    $pdo = DatabaseConnection::getInstance();
    
    // Create migration manager
    $migrationManager = new MigrationManager($pdo);
    
    // Register migrations
    $migrationManager->addMigrations([
        new Migration_001_CreateInitialSchema(),
        new Migration_002_CreateAuditTables(),
        new Migration_003_CreateViewsAndTriggers(),
        new Migration_004_CreateProductActivityTables()
    ]);
    
    // Execute command
    switch ($command) {
        case 'migrate':
        case 'up':
            echo "Running migrations...\n";
            $results = $migrationManager->migrate();
            
            if (isset($results['message'])) {
                echo $results['message'] . "\n";
            } else {
                foreach ($results as $result) {
                    echo "✓ {$result['version']}: {$result['description']} ({$result['execution_time_ms']}ms)\n";
                }
                echo "\nMigrations completed successfully!\n";
            }
            break;
            
        case 'rollback':
        case 'down':
            $targetVersion = $options[0] ?? null;
            echo "Rolling back migrations" . ($targetVersion ? " to version {$targetVersion}" : " (last migration)") . "...\n";
            
            $results = $migrationManager->rollback($targetVersion);
            
            if (isset($results['message'])) {
                echo $results['message'] . "\n";
            } else {
                foreach ($results as $result) {
                    echo "✓ Rolled back {$result['version']}: {$result['description']} ({$result['execution_time_ms']}ms)\n";
                }
                echo "\nRollback completed successfully!\n";
            }
            break;
            
        case 'status':
            echo "Migration Status:\n";
            echo "================\n";
            
            $status = $migrationManager->getStatus();
            
            echo "Total migrations: {$status['total_migrations']}\n";
            echo "Executed: {$status['executed_migrations']}\n";
            echo "Pending: {$status['pending_migrations']}\n\n";
            
            if (!empty($status['executed'])) {
                echo "Executed Migrations:\n";
                foreach ($status['executed'] as $migration) {
                    echo "  ✓ {$migration['version']}: {$migration['description']}\n";
                    echo "    Executed: {$migration['executed_at']} ({$migration['execution_time_ms']}ms)\n";
                }
                echo "\n";
            }
            
            if (!empty($status['pending'])) {
                echo "Pending Migrations:\n";
                foreach ($status['pending'] as $migration) {
                    echo "  ○ {$migration['version']}: {$migration['description']}\n";
                    if (!empty($migration['dependencies'])) {
                        echo "    Dependencies: " . implode(', ', $migration['dependencies']) . "\n";
                    }
                }
                echo "\n";
            }
            break;
            
        case 'validate':
            echo "Validating migrations...\n";
            
            $errors = $migrationManager->validate();
            
            if (empty($errors)) {
                echo "✓ All migrations are valid!\n";
            } else {
                echo "✗ Validation errors found:\n";
                foreach ($errors as $error) {
                    echo "  - {$error}\n";
                }
                exit(1);
            }
            break;
            
        case 'reset':
            echo "WARNING: This will reset ALL migrations and DROP ALL DATA!\n";
            echo "Are you sure you want to continue? (yes/no): ";
            
            $handle = fopen("php://stdin", "r");
            $confirmation = trim(fgets($handle));
            fclose($handle);
            
            if (strtolower($confirmation) === 'yes') {
                echo "Resetting all migrations...\n";
                $migrationManager->reset();
                echo "All migrations reset successfully!\n";
            } else {
                echo "Reset cancelled.\n";
            }
            break;
            
        case 'create':
            $migrationName = $options[0] ?? null;
            if (!$migrationName) {
                echo "Usage: php migrate.php create <migration_name>\n";
                exit(1);
            }
            
            createMigrationTemplate($migrationName);
            break;
            
        case 'help':
        default:
            showHelp();
            break;
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Show help information
 */
function showHelp(): void
{
    echo "MDM System Migration Tool\n";
    echo "========================\n\n";
    echo "Usage: php migrate.php <command> [options]\n\n";
    echo "Commands:\n";
    echo "  migrate, up              Run all pending migrations\n";
    echo "  rollback, down [version] Rollback migrations (to specific version)\n";
    echo "  status                   Show migration status\n";
    echo "  validate                 Validate all migrations\n";
    echo "  reset                    Reset all migrations (DANGEROUS)\n";
    echo "  create <name>            Create new migration template\n";
    echo "  help                     Show this help message\n\n";
    echo "Examples:\n";
    echo "  php migrate.php migrate                    # Run all pending migrations\n";
    echo "  php migrate.php rollback                   # Rollback last migration\n";
    echo "  php migrate.php rollback 001_initial      # Rollback to specific version\n";
    echo "  php migrate.php status                     # Show current status\n";
    echo "  php migrate.php create add_new_field       # Create new migration\n\n";
    echo "Environment Variables:\n";
    echo "  DB_HOST     Database host (default: localhost)\n";
    echo "  DB_PORT     Database port (default: 3306)\n";
    echo "  DB_NAME     Database name (default: mdm_system)\n";
    echo "  DB_USER     Database username (default: root)\n";
    echo "  DB_PASS     Database password (default: empty)\n";
}

/**
 * Create a new migration template
 */
function createMigrationTemplate(string $name): void
{
    $timestamp = date('Y_m_d_H_i_s');
    $className = 'Migration_' . $timestamp . '_' . ucfirst(camelCase($name));
    $version = $timestamp . '_' . snake_case($name);
    $description = ucfirst(str_replace('_', ' ', $name));
    
    $template = "<?php

namespace MDM\\Migrations\\Migrations;

use MDM\\Migrations\\BaseMigration;
use PDO;

/**
 * Migration: {$description}
 */
class {$className} extends BaseMigration
{
    public function __construct()
    {
        parent::\__construct(
            version: '{$version}',
            description: '{$description}',
            dependencies: [] // Add dependencies here if needed
        );
    }

    public function up(PDO \$pdo): bool
    {
        \$this->log('Executing {$description}...');

        // Add your migration logic here
        // Example:
        // \$sql = \"ALTER TABLE example_table ADD COLUMN new_field VARCHAR(255)\";
        // \$this->executeSql(\$pdo, \$sql);

        \$this->log('{$description} completed successfully');
        return true;
    }

    public function down(PDO \$pdo): bool
    {
        \$this->log('Rolling back {$description}...');

        // Add your rollback logic here
        // Example:
        // \$sql = \"ALTER TABLE example_table DROP COLUMN new_field\";
        // \$this->executeSql(\$pdo, \$sql);

        \$this->log('{$description} rollback completed');
        return true;
    }

    public function canExecute(PDO \$pdo): bool
    {
        // Add validation logic here
        return true;
    }

    public function canRollback(PDO \$pdo): bool
    {
        // Add rollback validation logic here
        return true;
    }
}
";

    $filename = "src/Migrations/Migrations/{$className}.php";
    
    if (file_exists($filename)) {
        echo "Migration file already exists: {$filename}\n";
        exit(1);
    }
    
    if (file_put_contents($filename, $template)) {
        echo "Migration created successfully: {$filename}\n";
        echo "Don't forget to register it in the migration runner!\n";
    } else {
        echo "Failed to create migration file: {$filename}\n";
        exit(1);
    }
}

/**
 * Convert string to camelCase
 */
function camelCase(string $string): string
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $string))));
}

/**
 * Convert string to snake_case
 */
function snake_case(string $string): string
{
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $string));
}