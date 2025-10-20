<?php
/**
 * Credential Management Script
 * 
 * Command-line tool for managing encrypted credentials
 * Usage: php manage_credentials.php [command] [options]
 */

require_once __DIR__ . '/CredentialManager.php';

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

class CredentialCLI {
    private $credentialManager;
    
    public function __construct() {
        $this->credentialManager = new CredentialManager();
    }
    
    /**
     * Display help information
     */
    public function showHelp() {
        echo "🔐 Credential Management Tool\n";
        echo str_repeat('=', 50) . "\n\n";
        
        echo "USAGE:\n";
        echo "  php manage_credentials.php [command] [options]\n\n";
        
        echo "COMMANDS:\n";
        echo "  store     Store a new credential\n";
        echo "  get       Retrieve a credential (masked)\n";
        echo "  list      List all credentials for a service\n";
        echo "  rotate    Rotate a credential\n";
        echo "  deactivate Deactivate a credential\n";
        echo "  migrate   Migrate environment variables to encrypted storage\n";
        echo "  check     Check for expiring credentials\n";
        echo "  cleanup   Clean up expired credentials\n";
        echo "  history   Show rotation history\n";
        echo "  help      Show this help message\n\n";
        
        echo "EXAMPLES:\n";
        echo "  # Store Ozon API key\n";
        echo "  php manage_credentials.php store ozon api_key 'your-api-key-here'\n\n";
        
        echo "  # List all Ozon credentials\n";
        echo "  php manage_credentials.php list ozon\n\n";
        
        echo "  # Check expiring credentials\n";
        echo "  php manage_credentials.php check\n\n";
        
        echo "  # Migrate from environment variables\n";
        echo "  php manage_credentials.php migrate\n\n";
    }
    
    /**
     * Store a credential
     */
    public function storeCredential($args) {
        if (count($args) < 3) {
            echo "❌ Error: Missing arguments\n";
            echo "Usage: store <service> <type> <value> [expiry_days]\n";
            return;
        }
        
        $service = $args[0];
        $type = $args[1];
        $value = $args[2];
        $expiryDays = isset($args[3]) ? intval($args[3]) : 90;
        
        echo "🔐 Storing credential for {$service}.{$type}...\n";
        
        $result = $this->credentialManager->storeCredential($service, $type, $value, $expiryDays);
        
        if ($result) {
            echo "✅ Credential stored successfully\n";
            echo "   Service: {$service}\n";
            echo "   Type: {$type}\n";
            echo "   Expires: " . ($expiryDays ? date('Y-m-d', strtotime("+{$expiryDays} days")) : 'Never') . "\n";
        } else {
            echo "❌ Failed to store credential\n";
        }
    }
    
    /**
     * Get a credential (masked for security)
     */
    public function getCredential($args) {
        if (count($args) < 2) {
            echo "❌ Error: Missing arguments\n";
            echo "Usage: get <service> <type>\n";
            return;
        }
        
        $service = $args[0];
        $type = $args[1];
        
        $info = $this->credentialManager->getCredentialInfo($service, $type);
        
        if ($info) {
            echo "🔍 Credential Information:\n";
            echo "   Service: {$info['service_name']}\n";
            echo "   Type: {$info['credential_type']}\n";
            echo "   Created: {$info['created_at']}\n";
            echo "   Updated: {$info['updated_at']}\n";
            echo "   Expires: " . ($info['expires_at'] ?: 'Never') . "\n";
            echo "   Active: " . ($info['is_active'] ? 'Yes' : 'No') . "\n";
            echo "   Rotations: {$info['rotation_count']}\n";
            echo "   Last Used: " . ($info['last_used_at'] ?: 'Never') . "\n";
            
            // Show masked value
            $value = $this->credentialManager->getCredential($service, $type);
            if ($value) {
                $maskedValue = substr($value, 0, 4) . str_repeat('*', max(0, strlen($value) - 8)) . substr($value, -4);
                echo "   Value: {$maskedValue}\n";
            }
        } else {
            echo "❌ Credential not found: {$service}.{$type}\n";
        }
    }
    
    /**
     * List credentials for a service
     */
    public function listCredentials($args) {
        if (count($args) < 1) {
            echo "❌ Error: Missing service name\n";
            echo "Usage: list <service>\n";
            return;
        }
        
        $service = $args[0];
        $credentials = $this->credentialManager->listServiceCredentials($service);
        
        if (empty($credentials)) {
            echo "📭 No credentials found for service: {$service}\n";
            return;
        }
        
        echo "📋 Credentials for {$service}:\n";
        echo str_repeat('-', 80) . "\n";
        printf("%-15s %-10s %-20s %-20s %-8s\n", 'Type', 'Active', 'Created', 'Expires', 'Rotations');
        echo str_repeat('-', 80) . "\n";
        
        foreach ($credentials as $cred) {
            printf("%-15s %-10s %-20s %-20s %-8s\n",
                $cred['credential_type'],
                $cred['is_active'] ? 'Yes' : 'No',
                substr($cred['created_at'], 0, 16),
                $cred['expires_at'] ? substr($cred['expires_at'], 0, 16) : 'Never',
                $cred['rotation_count']
            );
        }
    }
    
    /**
     * Rotate a credential
     */
    public function rotateCredential($args) {
        if (count($args) < 3) {
            echo "❌ Error: Missing arguments\n";
            echo "Usage: rotate <service> <type> <new_value> [reason]\n";
            return;
        }
        
        $service = $args[0];
        $type = $args[1];
        $newValue = $args[2];
        $reason = isset($args[3]) ? $args[3] : 'manual_rotation';
        
        echo "🔄 Rotating credential for {$service}.{$type}...\n";
        
        $result = $this->credentialManager->rotateCredential($service, $type, $newValue, $reason, 'cli_user');
        
        if ($result) {
            echo "✅ Credential rotated successfully\n";
        } else {
            echo "❌ Failed to rotate credential\n";
        }
    }
    
    /**
     * Deactivate a credential
     */
    public function deactivateCredential($args) {
        if (count($args) < 2) {
            echo "❌ Error: Missing arguments\n";
            echo "Usage: deactivate <service> <type>\n";
            return;
        }
        
        $service = $args[0];
        $type = $args[1];
        
        echo "⚠️  Are you sure you want to deactivate {$service}.{$type}? (y/N): ";
        $confirmation = trim(fgets(STDIN));
        
        if (strtolower($confirmation) !== 'y') {
            echo "❌ Operation cancelled\n";
            return;
        }
        
        $result = $this->credentialManager->deactivateCredential($service, $type);
        
        if ($result) {
            echo "✅ Credential deactivated successfully\n";
        } else {
            echo "❌ Failed to deactivate credential\n";
        }
    }
    
    /**
     * Migrate environment variables
     */
    public function migrateCredentials() {
        echo "🔄 Migrating environment variables to encrypted storage...\n";
        
        $results = $this->credentialManager->migrateEnvironmentCredentials();
        
        echo "✅ Migration completed:\n";
        echo "   Migrated: {$results['migrated']}\n";
        echo "   Skipped: {$results['skipped']}\n";
        
        if (!empty($results['errors'])) {
            echo "❌ Errors:\n";
            foreach ($results['errors'] as $error) {
                echo "   - {$error}\n";
            }
        }
    }
    
    /**
     * Check for expiring credentials
     */
    public function checkExpiring($args) {
        $warningDays = isset($args[0]) ? intval($args[0]) : 7;
        
        echo "⏰ Checking for credentials expiring in {$warningDays} days...\n";
        
        $expiring = $this->credentialManager->getExpiringCredentials($warningDays);
        
        if (empty($expiring)) {
            echo "✅ No credentials expiring soon\n";
            return;
        }
        
        echo "⚠️  Found " . count($expiring) . " expiring credentials:\n";
        echo str_repeat('-', 60) . "\n";
        printf("%-15s %-15s %-20s %-10s\n", 'Service', 'Type', 'Expires', 'Days Left');
        echo str_repeat('-', 60) . "\n";
        
        foreach ($expiring as $cred) {
            printf("%-15s %-15s %-20s %-10s\n",
                $cred['service_name'],
                $cred['credential_type'],
                substr($cred['expires_at'], 0, 16),
                $cred['days_until_expiry']
            );
        }
    }
    
    /**
     * Clean up expired credentials
     */
    public function cleanupExpired() {
        echo "🧹 Cleaning up expired credentials...\n";
        
        $count = $this->credentialManager->cleanupExpiredCredentials();
        
        if ($count > 0) {
            echo "✅ Cleaned up {$count} expired credentials\n";
        } else {
            echo "✅ No expired credentials to clean up\n";
        }
    }
    
    /**
     * Show rotation history
     */
    public function showHistory($args) {
        if (count($args) < 2) {
            echo "❌ Error: Missing arguments\n";
            echo "Usage: history <service> <type> [limit]\n";
            return;
        }
        
        $service = $args[0];
        $type = $args[1];
        $limit = isset($args[2]) ? intval($args[2]) : 10;
        
        $history = $this->credentialManager->getRotationHistory($service, $type, $limit);
        
        if (empty($history)) {
            echo "📭 No rotation history found for {$service}.{$type}\n";
            return;
        }
        
        echo "📜 Rotation history for {$service}.{$type}:\n";
        echo str_repeat('-', 70) . "\n";
        printf("%-20s %-15s %-30s\n", 'Date', 'Rotated By', 'Reason');
        echo str_repeat('-', 70) . "\n";
        
        foreach ($history as $entry) {
            printf("%-20s %-15s %-30s\n",
                substr($entry['rotated_at'], 0, 16),
                $entry['rotated_by'],
                $entry['rotation_reason']
            );
        }
    }
    
    /**
     * Run the CLI application
     */
    public function run($argv) {
        if (count($argv) < 2) {
            $this->showHelp();
            return;
        }
        
        $command = $argv[1];
        $args = array_slice($argv, 2);
        
        switch ($command) {
            case 'store':
                $this->storeCredential($args);
                break;
                
            case 'get':
                $this->getCredential($args);
                break;
                
            case 'list':
                $this->listCredentials($args);
                break;
                
            case 'rotate':
                $this->rotateCredential($args);
                break;
                
            case 'deactivate':
                $this->deactivateCredential($args);
                break;
                
            case 'migrate':
                $this->migrateCredentials();
                break;
                
            case 'check':
                $this->checkExpiring($args);
                break;
                
            case 'cleanup':
                $this->cleanupExpired();
                break;
                
            case 'history':
                $this->showHistory($args);
                break;
                
            case 'help':
            default:
                $this->showHelp();
                break;
        }
    }
}

// Run the CLI application
$cli = new CredentialCLI();
$cli->run($argv);

?>