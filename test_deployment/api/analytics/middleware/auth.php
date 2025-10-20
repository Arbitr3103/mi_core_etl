<?php
/**
 * Authentication Middleware for Regional Analytics API
 * 
 * This middleware should be included at the top of protected API endpoints
 * to ensure proper authentication and rate limiting.
 */

require_once __DIR__ . '/../AuthenticationManager.php';

// Handle preflight OPTIONS requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setAnalyticsCorsHeaders();
    http_response_code(200);
    exit;
}

// Set CORS headers for all requests
setAnalyticsCorsHeaders();

// Authenticate the request
$authManager = new AuthenticationManager();
$apiKeyData = $authManager->authenticateRequest();

if (!$apiKeyData) {
    // Check if this is a development environment and allow bypass
    $isDevelopment = (
        isset($_SERVER['HTTP_HOST']) && 
        (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || 
         strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false)
    );
    
    if ($isDevelopment && isset($_GET['dev_bypass']) && $_GET['dev_bypass'] === 'true') {
        // Allow development bypass with warning
        logAnalyticsActivity('WARNING', 'Development authentication bypass used', [
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown'
        ]);
        
        // Create mock API key data for development
        $apiKeyData = [
            'id' => 0,
            'api_key' => 'dev_bypass',
            'name' => 'Development Bypass',
            'client_id' => 1,
            'is_active' => true,
            'rate_limit_per_hour' => 1000
        ];
    } else {
        sendAnalyticsErrorResponse(
            'Authentication required. Please provide a valid API key via X-API-Key header, Authorization Bearer token, or api_key parameter.',
            401,
            'AUTH_REQUIRED'
        );
    }
}

// Store authenticated API key data for use in endpoints
$GLOBALS['authenticated_api_key'] = $apiKeyData;

// Log successful authentication
logAnalyticsActivity('INFO', 'API request authenticated', [
    'api_key_name' => $apiKeyData['name'],
    'client_id' => $apiKeyData['client_id'],
    'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown'
]);
?>