<?php
/**
 * Base Middleware for mi_core_etl API
 * Provides common middleware functionality
 */

abstract class BaseMiddleware {
    protected $logger;
    
    public function __construct() {
        require_once __DIR__ . '/../../utils/Logger.php';
        $this->logger = Logger::getInstance();
    }
    
    /**
     * Handle the middleware
     */
    abstract public function handle($request, $next);
    
    /**
     * Send JSON response
     */
    protected function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    /**
     * Send error response
     */
    protected function errorResponse($message, $statusCode = 400, $details = null) {
        $response = [
            'success' => false,
            'error' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if ($details !== null) {
            $response['details'] = $details;
        }
        
        $this->jsonResponse($response, $statusCode);
    }
    
    /**
     * Get request headers
     */
    protected function getHeaders() {
        return getallheaders() ?: [];
    }
    
    /**
     * Get request method
     */
    protected function getMethod() {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
    
    /**
     * Get client IP address
     */
    protected function getClientIp() {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                return trim($ips[0]);
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Get user agent
     */
    protected function getUserAgent() {
        return $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    }
}