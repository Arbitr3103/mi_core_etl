#!/usr/bin/env php
<?php
/**
 * API Key Management CLI Tool
 * 
 * Command-line tool for managing API keys for the Regional Analytics system.
 * 
 * Usage:
 *   php manage_api_keys.php generate "Key Name" [client_id] [rate_limit] [expiry_days]
 *   php manage_api_keys.php list [client_id]
 *   php manage_api_keys.php revoke <api_key>
 *   php manage_api_keys.php cleanup
 */

require_once __DIR__ . '/AuthenticationManager.php';

function showUsage() {
    echo "Regional Analytics API Key Management Tool\n";
    echo "=========================================\n\n";
    echo "Usage:\n";
    echo "  php manage_api_keys.php generate \"Key Name\" [client_id] [rate_limit] [expiry_days]\n";
    echo "  php manage_api_keys.php list [client_id]\n";
    echo "  php manage_api_keys.php revoke <api_key>\n";
    echo "  php manage_api_keys.php cleanup\n\n";
    echo "Examples:\n";
    echo "  php manage_api_keys.php generate \"Dashboard API Key\" 1 100 90\n";
    echo "  php manage_api_keys.php list 1\n";
    echo "  php manage_api_keys.php revoke ra_abc123...\n";
    echo "  php manage_api_keys.php cleanup\n\n";
}

function generateApiKey($args) {
    if (count($args) < 2) {
        echo "Error: Key name is required\n";
        showUsage();
        return;
    }
    
    $name = $args[1];
    $clientId = isset($args[2]) ? (int)$args[2] : 1;
    $rateLimit = isset($args[3]) ? (int)$args[3] : 100;
    $expiryDays = isset($args[4]) ? (int)$args[4] : 90;
    
    try {
        $auth = new AuthenticationManager();
        $keyData = $auth->generateApiKey($name, $clientId, $rateLimit, $expiryDays);
        
        echo "API Key Generated Successfully!\n";
        echo "==============================\n";
        echo "API Key: " . $keyData['api_key'] . "\n";
        echo "Name: " . $keyData['name'] . "\n";
        echo "Client ID: " . $keyData['client_id'] . "\n";
        echo "Rate Limit: " . $keyData['rate_limit_per_hour'] . " requests/hour\n";
        echo "Expires: " . ($keyData['expires_at'] ?? 'Never') . "\n";
        echo "Created: " . $keyData['created_at'] . "\n\n";
        echo "IMPORTANT: Save this API key securely. It cannot be retrieved again.\n";
        
    } catch (Exception $e) {
        echo "Error generating API key: " . $e->getMessage() . "\n";
    }
}

function listApiKeys($args) {
    $clientId = isset($args[1]) ? (int)$args[1] : 1;
    
    try {
        $auth = new AuthenticationManager();
        $keys = $auth->listApiKeys($clientId);
        
        if (empty($keys)) {
            echo "No API keys found for client ID: $clientId\n";
            return;
        }
        
        echo "API Keys for Client ID: $clientId\n";
        echo "================================\n\n";
        
        foreach ($keys as $key) {
            echo "ID: " . $key['id'] . "\n";
            echo "Name: " . $key['name'] . "\n";
            echo "API Key: " . $key['api_key'] . "\n";
            echo "Status: " . ($key['is_active'] ? 'Active' : 'Inactive') . "\n";
            echo "Rate Limit: " . $key['rate_limit_per_hour'] . " requests/hour\n";
            echo "Created: " . $key['created_at'] . "\n";
            echo "Expires: " . ($key['expires_at'] ?? 'Never') . "\n";
            echo "Last Used: " . ($key['last_used_at'] ?? 'Never') . "\n";
            echo "Usage Count: " . $key['usage_count'] . "\n";
            echo "---\n";
        }
        
    } catch (Exception $e) {
        echo "Error listing API keys: " . $e->getMessage() . "\n";
    }
}

function revokeApiKey($args) {
    if (count($args) < 2) {
        echo "Error: API key is required\n";
        showUsage();
        return;
    }
    
    $apiKey = $args[1];
    
    try {
        $auth = new AuthenticationManager();
        $success = $auth->revokeApiKey($apiKey);
        
        if ($success) {
            echo "API key revoked successfully: " . substr($apiKey, 0, 10) . "...\n";
        } else {
            echo "API key not found or already inactive: " . substr($apiKey, 0, 10) . "...\n";
        }
        
    } catch (Exception $e) {
        echo "Error revoking API key: " . $e->getMessage() . "\n";
    }
}

function cleanupApiKeys() {
    try {
        $auth = new AuthenticationManager();
        $auth->cleanup();
        echo "Cleanup completed successfully. Check logs for details.\n";
        
    } catch (Exception $e) {
        echo "Error during cleanup: " . $e->getMessage() . "\n";
    }
}

// Main execution
if ($argc < 2) {
    showUsage();
    exit(1);
}

$command = $argv[1];
$args = array_slice($argv, 1);

switch ($command) {
    case 'generate':
        generateApiKey($args);
        break;
        
    case 'list':
        listApiKeys($args);
        break;
        
    case 'revoke':
        revokeApiKey($args);
        break;
        
    case 'cleanup':
        cleanupApiKeys();
        break;
        
    default:
        echo "Unknown command: $command\n\n";
        showUsage();
        exit(1);
}
?>