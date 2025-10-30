<?php
/**
 * Comprehensive Error Logging API Endpoint
 * 
 * Enhanced endpoint for receiving and processing error logs from all components
 * (React frontend, PHP backend, Python ETL)
 * 
 * Requirements: 7.1, 7.2, 7.3, 7.4
 */

require_once __DIR__ . '/classes/ErrorLogger.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Trace-ID');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Initialize error logger with configuration
$logger_config = [
    'log_path' => __DIR__ . '/../logs',
    'max_log_size' => '50MB',
    'max_log_files' => 30,
    'log_level' => $_ENV['LOG_LEVEL'] ?? 'info',
    'alerts' => [
        'email' => $_ENV['ALERT_EMAIL'] ?? '',
        'slack_webhook' => $_ENV['SLACK_WEBHOOK_URL'] ?? '',
        'telegram' => [
            'bot_token' => $_ENV['TELEGRAM_BOT_TOKEN'] ?? '',
            'chat_id' => $_ENV['TELEGRAM_CHAT_ID'] ?? ''
        ]
    ]
];

$error_logger = new ErrorLogger($logger_config);

// Handle GET requests - return log statistics
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $component = $_GET['component'] ?? null;
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
        
        $stats = $error_logger->getLogStats($component, $days);
        
        echo json_encode([
            'success' => true,
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to retrieve log statistics'
        ]);
    }
    exit;
}

// Handle POST requests - log error
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get JSON input
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON input: ' . json_last_error_msg());
        }
        
        // Validate required fields
        if (!isset($data['level']) || !isset($data['message'])) {
            throw new Exception('Missing required fields: level, message');
        }
        
        // Extract log data
        $level = strtolower($data['level']);
        $message = $data['message'];
        $context = $data['context'] ?? [];
        $component = $data['component'] ?? 'unknown';
        
        // Add source information to context
        if (isset($data['source'])) {
            $context['source'] = $data['source'];
        }
        
        // Add error details if present
        if (isset($data['error'])) {
            $context['error_details'] = $data['error'];
        }
        
        // Add stack trace if present
        if (isset($data['stack'])) {
            $context['stack_trace'] = $data['stack'];
        }
        
        // Add component stack for React errors
        if (isset($data['componentStack'])) {
            $context['component_stack'] = $data['componentStack'];
        }
        
        // Add client information for frontend errors
        if (isset($data['client'])) {
            $context['client'] = $data['client'];
        }
        
        // Generate trace ID if not provided
        if (!isset($_SERVER['HTTP_X_TRACE_ID'])) {
            $_SERVER['HTTP_X_TRACE_ID'] = $data['traceId'] ?? uniqid('trace_', true);
        }
        
        // Log the error
        $success = $error_logger->log($level, $message, $context, $component);
        
        if (!$success) {
            throw new Exception('Failed to write log entry');
        }
        
        // Return success response
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Error logged successfully',
            'trace_id' => $_SERVER['HTTP_X_TRACE_ID']
        ]);
        
    } catch (Exception $e) {
        // Log the logging error
        error_log("Comprehensive error logging endpoint failed: " . $e->getMessage());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to log error: ' . $e->getMessage(),
            'trace_id' => $_SERVER['HTTP_X_TRACE_ID'] ?? uniqid('err_', true)
        ]);
    }
    exit;
}

// Method not allowed
http_response_code(405);
echo json_encode([
    'success' => false,
    'error' => 'Method not allowed'
]);
