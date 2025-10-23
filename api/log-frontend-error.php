<?php
/**
 * Frontend Error Logging Endpoint
 * 
 * Receives and logs frontend errors from the client-side error tracking system
 */

require_once __DIR__ . '/../config/error_logging_production.php';

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
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    // Validate required fields
    if (!isset($data['type']) || !isset($data['error'])) {
        throw new Exception('Missing required fields: type, error');
    }
    
    // Get global error logger
    global $error_logger;
    
    // Prepare error context
    $context = [
        'frontend_error' => true,
        'error_type' => $data['type'],
        'error_data' => $data['error'],
        'client_context' => $data['context'] ?? [],
        'server_time' => date('Y-m-d H:i:s'),
        'client_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    // Determine log level based on error type
    $log_level = 'error';
    switch ($data['type']) {
        case 'javascript_error':
        case 'react_error':
            $log_level = 'error';
            break;
        case 'unhandled_promise_rejection':
            $log_level = 'warning';
            break;
        case 'api_error':
            $log_level = 'error';
            break;
        case 'performance_issue':
            $log_level = 'warning';
            break;
        case 'user_action':
            $log_level = 'info';
            break;
        default:
            $log_level = 'error';
    }
    
    // Create error message
    $error_message = sprintf(
        'Frontend %s: %s',
        $data['type'],
        $data['error']['message'] ?? 'Unknown error'
    );
    
    // Log the error
    $error_logger->logError($log_level, $error_message, $context);
    
    // Response
    echo json_encode([
        'success' => true,
        'message' => 'Error logged successfully',
        'error_id' => uniqid('fe_')
    ]);
    
} catch (Exception $e) {
    // Log the logging error
    if (isset($error_logger)) {
        $error_logger->logError('critical', 'Frontend error logging failed: ' . $e->getMessage(), [
            'input_data' => $input ?? 'no input',
            'exception' => $e->getTraceAsString()
        ]);
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to log error',
        'error_id' => uniqid('err_')
    ]);
}
?>