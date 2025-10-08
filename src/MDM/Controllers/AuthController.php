<?php

namespace MDM\Controllers;

use MDM\Security\AuthenticationService;
use MDM\Security\AuthorizationService;
use MDM\Security\ActivityLogger;
use Exception;

class AuthController
{
    private $authService;
    private $authzService;
    private $logger;
    
    public function __construct(AuthenticationService $authService, AuthorizationService $authzService, ActivityLogger $logger)
    {
        $this->authService = $authService;
        $this->authzService = $authzService;
        $this->logger = $logger;
    }
    
    /**
     * Show login form
     */
    public function showLogin()
    {
        // If already logged in, redirect to dashboard
        if ($this->isLoggedIn()) {
            header('Location: /dashboard');
            exit;
        }
        
        include __DIR__ . '/../Views/login.php';
    }
    
    /**
     * Process login
     */
    public function login()
    {
        try {
            $login = $_POST['login'] ?? '';
            $password = $_POST['password'] ?? '';
            $remember = isset($_POST['remember']);
            
            if (empty($login) || empty($password)) {
                throw new Exception('Логин и пароль обязательны');
            }
            
            $result = $this->authService->authenticate($login, $password);
            
            if (!$result) {
                throw new Exception('Неверный логин или пароль');
            }
            
            // Set session cookie
            $cookieOptions = [
                'expires' => $remember ? time() + (30 * 24 * 3600) : 0, // 30 days if remember
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict'
            ];
            
            setcookie('mdm_session', $result['session_id'], $cookieOptions);
            
            // Store user in session
            $_SESSION['mdm_user'] = $result['user'];
            $_SESSION['mdm_session_id'] = $result['session_id'];
            
            // Return success response
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'redirect' => '/dashboard'
                ]);
            } else {
                header('Location: /dashboard');
            }
            
        } catch (Exception $e) {
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            } else {
                $_SESSION['login_error'] = $e->getMessage();
                header('Location: /login');
            }
        }
    }
    
    /**
     * Logout user
     */
    public function logout()
    {
        $sessionId = $_COOKIE['mdm_session'] ?? null;
        
        if ($sessionId) {
            $this->authService->logout($sessionId);
            
            // Clear session cookie
            setcookie('mdm_session', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }
        
        // Clear session
        session_destroy();
        
        header('Location: /login');
    }
    
    /**
     * Show user profile
     */
    public function profile()
    {
        $user = $this->getCurrentUser();
        if (!$user) {
            header('Location: /login');
            exit;
        }
        
        // Get user roles
        $roles = $this->authzService->getUserRoles($user['id']);
        
        // Get recent activity
        $activities = $this->logger->getUserActivity($user['id'], 20);
        
        include __DIR__ . '/../Views/profile.php';
    }
    
    /**
     * Change password
     */
    public function changePassword()
    {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                throw new Exception('Пользователь не авторизован');
            }
            
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                throw new Exception('Все поля обязательны');
            }
            
            if ($newPassword !== $confirmPassword) {
                throw new Exception('Новые пароли не совпадают');
            }
            
            if (strlen($newPassword) < 8) {
                throw new Exception('Пароль должен содержать минимум 8 символов');
            }
            
            $result = $this->authService->changePassword($user['id'], $currentPassword, $newPassword);
            
            if (!$result) {
                throw new Exception('Неверный текущий пароль');
            }
            
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'message' => 'Пароль успешно изменен'
                ]);
            } else {
                $_SESSION['success_message'] = 'Пароль успешно изменен';
                header('Location: /profile');
            }
            
        } catch (Exception $e) {
            if ($this->isAjaxRequest()) {
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]);
            } else {
                $_SESSION['error_message'] = $e->getMessage();
                header('Location: /profile');
            }
        }
    }
    
    /**
     * Get current user from session
     */
    private function getCurrentUser()
    {
        return $_SESSION['mdm_user'] ?? null;
    }
    
    /**
     * Check if user is logged in
     */
    private function isLoggedIn()
    {
        return !empty($_SESSION['mdm_user']);
    }
    
    /**
     * Check if request is AJAX
     */
    private function isAjaxRequest()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}