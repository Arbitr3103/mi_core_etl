<?php
/**
 * Error Logging API Endpoint
 * 
 * Receives error logs from the React frontend and stores them for debugging
 * 
 * Requirements: 2.1, 2.2, 7.1
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $errorLog = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON input');
    }
    
    // Validate required fields
    $requiredFields = ['errorId', 'timestamp', 'error', 'url'];
    foreach ($requiredFields as $field) {
        if (!isset($errorLog[$field])) {
            throw new Exception("Missing required field: {$field}");
        }
    }
    
    // Prepare log directory
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    // Create log file path (one file per day)
    $logFile = $logDir . '/react-errors-' . date('Y-m-d') . '.log';
    
    // Format log entry
    $logEntry = [
        'logged_at' => date('Y-m-d H:i:s'),
        'error_id' => $errorLog['errorId'],
        'timestamp' => $errorLog['timestamp'],
        'error' => $errorLog['error'],
        'stack' => $errorLog['stack'] ?? null,
        'component_stack' => $errorLog['componentStack'] ?? null,
        'url' => $errorLog['url'],
        'user_agent' => $errorLog['userAgent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
        'viewport' => $errorLog['viewport'] ?? null,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown'
    ];
    
    // Write to log file
    $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    $result = file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
    
    if ($result === false) {
        throw new Exception('Failed to write to log file');
    }
    
    // Also log to PHP error log for critical errors
    if (strpos(strtolower($errorLog['error']), 'critical') !== false || 
        strpos(strtolower($errorLog['error']), 'fatal') !== false) {
        error_log("CRITICAL React Error [{$errorLog['errorId']}]: {$errorLog['error']}");
    }
    
    // Return success response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Error logged successfully',
        'error_id' => $errorLog['errorId']
    ]);
    
} catch (Exception $e) {
    // Log the logging error (meta!)
    error_log("Error logging endpoint failed: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to log error: ' . $e->getMessage()
    ]);
}
