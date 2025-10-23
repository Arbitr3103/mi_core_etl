<?php
/**
 * CORS Middleware for mi_core_etl API
 * Handles Cross-Origin Resource Sharing
 */

require_once __DIR__ . '/BaseMiddleware.php';

class CorsMiddleware extends BaseMiddleware {
    private $allowedOrigins = [];
    private $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
    private $allowedHeaders = ['Content-Type', 'Authorization', 'X-API-Key', 'X-Requested-With'];
    private $maxAge = 86400; // 24 hours
    
    public function __construct() {
        parent::__construct();
        $this->loadCorsConfig();
    }
    
    /**
     * Load CORS configuration
     */
    private function loadCorsConfig() {
        // Load allowed origins from environment
        $origins = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
        if ($origins) {
            $this->allowedOrigins = explode(',', $origins);
        } else {
            // Default allowed origins for development
            $this->allowedOrigins = [
                'http://localhost:3000',
                'http://localhost:5173',
                'http://127.0.0.1:3000',
                'http://127.0.0.1:5173',
                '*' // Allow all origins in development
            ];
        }
        
        // Load allowed methods
        $methods = $_ENV['CORS_ALLOWED_METHODS'] ?? '';
        if ($methods) {
            $this->allowedMethods = explode(',', $methods);
        }
        
        // Load allowed headers
        $headers = $_ENV['CORS_ALLOWED_HEADERS'] ?? '';
        if ($headers) {
            $this->allowedHeaders = explode(',', $headers);
        }
        
        // Load max age
        $maxAge = $_ENV['CORS_MAX_AGE'] ?? '';
        if ($maxAge && is_numeric($maxAge)) {
            $this->maxAge = (int)$maxAge;
        }
    }
    
    /**
     * Handle CORS
     */
    public function handle($request, $next) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $method = $this->getMethod();
        
        // Set CORS headers
        $this->setCorsHeaders($origin);
        
        // Handle preflight OPTIONS request
        if ($method === 'OPTIONS') {
            $this->handlePreflightRequest();
            return;
        }
        
        // Log CORS request
        $this->logger->debug('CORS request processed', [
            'origin' => $origin,
            'method' => $method,
            'allowed' => $this->isOriginAllowed($origin)
        ]);
        
        return $next($request);
    }
    
    /**
     * Set CORS headers
     */
    private function setCorsHeaders($origin) {
        // Set Access-Control-Allow-Origin
        if ($this->isOriginAllowed($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } elseif (in_array('*', $this->allowedOrigins)) {
            header('Access-Control-Allow-Origin: *');
        }
        
        // Set other CORS headers
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        header('Access-Control-Max-Age: ' . $this->maxAge);
        header('Access-Control-Allow-Credentials: true');
        
        // Expose headers that the client can access
        header('Access-Control-Expose-Headers: X-Total-Count, X-Page-Count, X-Current-Page');
    }
    
    /**
     * Handle preflight OPTIONS request
     */
    private function handlePreflightRequest() {
        $requestMethod = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '';
        $requestHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '';
        
        // Validate requested method
        if ($requestMethod && !in_array($requestMethod, $this->allowedMethods)) {
            $this->logger->warning('CORS preflight: method not allowed', [
                'requested_method' => $requestMethod,
                'allowed_methods' => $this->allowedMethods
            ]);
            http_response_code(405);
            exit;
        }
        
        // Validate requested headers
        if ($requestHeaders) {
            $headers = array_map('trim', explode(',', $requestHeaders));
            foreach ($headers as $header) {
                if (!in_array($header, $this->allowedHeaders)) {
                    $this->logger->warning('CORS preflight: header not allowed', [
                        'requested_header' => $header,
                        'allowed_headers' => $this->allowedHeaders
                    ]);
                    http_response_code(400);
                    exit;
                }
            }
        }
        
        $this->logger->debug('CORS preflight request handled', [
            'requested_method' => $requestMethod,
            'requested_headers' => $requestHeaders
        ]);
        
        http_response_code(204);
        exit;
    }
    
    /**
     * Check if origin is allowed
     */
    private function isOriginAllowed($origin) {
        if (empty($origin)) {
            return false;
        }
        
        // Check for exact match
        if (in_array($origin, $this->allowedOrigins)) {
            return true;
        }
        
        // Check for wildcard
        if (in_array('*', $this->allowedOrigins)) {
            return true;
        }
        
        // Check for pattern matches (e.g., *.example.com)
        foreach ($this->allowedOrigins as $allowedOrigin) {
            if (strpos($allowedOrigin, '*') !== false) {
                $pattern = str_replace('*', '.*', preg_quote($allowedOrigin, '/'));
                if (preg_match('/^' . $pattern . '$/', $origin)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get CORS configuration for debugging
     */
    public function getConfig() {
        return [
            'allowed_origins' => $this->allowedOrigins,
            'allowed_methods' => $this->allowedMethods,
            'allowed_headers' => $this->allowedHeaders,
            'max_age' => $this->maxAge
        ];
    }
}