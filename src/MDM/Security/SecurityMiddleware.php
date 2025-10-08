<?php

namespace MDM\Security;

use Exception;

class SecurityMiddleware
{
    private $authService;
    private $authzService;
    private $publicRoutes = [
        '/login',
        '/logout',
        '/api/health'
    ];
    
    public function __construct(AuthenticationService $authService, AuthorizationService $authzService)
    {
        $this->authService = $authService;
        $this->authzService = $authzService;
    }
    
    /**
     * Process request through security middleware
     */
    public function process($request, $next)
    {
        $path = $request['path'] ?? $_SERVER['REQUEST_URI'];
        $method = $request['method'] ?? $_SERVER['REQUEST_METHOD'];
        
        // Skip authentication for public routes
        if ($this->isPublicRoute($path)) {
            return $next($request);
        }
        
        // Check authentication
        $user = $this->authenticateRequest();
        if (!$user) {
            return $this->unauthorizedResponse();
        }
        
        // Add user to request
        $request['user'] = $user;
        
        // Check authorization for protected routes
        if (!$this->authorizeRequest($user, $path, $method)) {
            return $this->forbiddenResponse();
        }
        
        return $next($request);
    }
    
    /**
     * Check if route is public (no authentication required)
     */
    private function isPublicRoute($path)
    {
        foreach ($this->publicRoutes as $publicRoute) {
            if (strpos($path, $publicRoute) === 0) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Authenticate current request
     */
    private function authenticateRequest()
    {
        // Check session cookie
        $sessionId = $_COOKIE['mdm_session'] ?? null;
        if (!$sessionId) {
            // Check Authorization header for API requests
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                $sessionId = $matches[1];
            }
        }
        
        if (!$sessionId) {
            return null;
        }
        
        $session = $this->authService->validateSession($sessionId);
        if (!$session) {
            return null;
        }
        
        // Store session ID for logging
        $_SESSION['mdm_session_id'] = $sessionId;
        
        return [
            'id' => $session['user_id'],
            'username' => $session['username'],
            'email' => $session['email'],
            'full_name' => $session['full_name'],
            'session_id' => $sessionId
        ];
    }
    
    /**
     * Authorize request based on user permissions
     */
    private function authorizeRequest($user, $path, $method)
    {
        $permission = $this->getRequiredPermission($path, $method);
        
        if (!$permission) {
            return true; // No specific permission required
        }
        
        return $this->authzService->hasPermission($user['id'], $permission);
    }
    
    /**
     * Get required permission for path and method
     */
    private function getRequiredPermission($path, $method)
    {
        $permissions = [
            // User management
            'GET:/users' => 'users.view',
            'POST:/users' => 'users.create',
            'PUT:/users' => 'users.edit',
            'DELETE:/users' => 'users.delete',
            
            // Role management
            'GET:/roles' => 'roles.view',
            'POST:/roles' => 'roles.create',
            'PUT:/roles' => 'roles.edit',
            'DELETE:/roles' => 'roles.delete',
            
            // Product management
            'GET:/products' => 'products.view',
            'POST:/products' => 'products.create',
            'PUT:/products' => 'products.edit',
            'DELETE:/products' => 'products.delete',
            
            // Verification
            'GET:/verification' => 'verification.view',
            'POST:/verification/approve' => 'verification.approve',
            'POST:/verification/reject' => 'verification.reject',
            
            // Reports
            'GET:/reports' => 'reports.view',
            'GET:/reports/export' => 'reports.export',
            
            // System configuration
            'GET:/config' => 'system.configure',
            'POST:/config' => 'system.configure',
            
            // Audit logs
            'GET:/audit' => 'audit.view',
            
            // Backup management
            'GET:/backup' => 'backup.view',
            'POST:/backup' => 'backup.create',
            'POST:/backup/restore' => 'backup.restore'
        ];
        
        $key = $method . ':' . $path;
        
        // Check exact match first
        if (isset($permissions[$key])) {
            return $permissions[$key];
        }
        
        // Check pattern matches
        foreach ($permissions as $pattern => $permission) {
            if (preg_match('#^' . str_replace('*', '.*', $pattern) . '$#', $key)) {
                return $permission;
            }
        }
        
        return null;
    }
    
    /**
     * Return unauthorized response
     */
    private function unauthorizedResponse()
    {
        http_response_code(401);
        
        if ($this->isApiRequest()) {
            header('Content-Type: application/json');
            return json_encode([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ]);
        }
        
        // Redirect to login page
        header('Location: /login');
        exit;
    }
    
    /**
     * Return forbidden response
     */
    private function forbiddenResponse()
    {
        http_response_code(403);
        
        if ($this->isApiRequest()) {
            header('Content-Type: application/json');
            return json_encode([
                'error' => 'Forbidden',
                'message' => 'Insufficient permissions'
            ]);
        }
        
        // Show error page
        include __DIR__ . '/../Views/error.php';
        exit;
    }
    
    /**
     * Check if request is API request
     */
    private function isApiRequest()
    {
        $path = $_SERVER['REQUEST_URI'];
        $acceptHeader = $_SERVER['HTTP_ACCEPT'] ?? '';
        
        return strpos($path, '/api/') === 0 || 
               strpos($acceptHeader, 'application/json') !== false;
    }
    
    /**
     * Require specific permission for current user
     */
    public function requirePermission($permission)
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            throw new Exception('User not authenticated');
        }
        
        $this->authzService->requirePermission($user['id'], $permission);
    }
    
    /**
     * Get current authenticated user
     */
    public function getCurrentUser()
    {
        return $_SESSION['mdm_user'] ?? null;
    }
    
    /**
     * Set current user in session
     */
    public function setCurrentUser($user)
    {
        $_SESSION['mdm_user'] = $user;
    }
}