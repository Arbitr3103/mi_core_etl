<?php

namespace MDM\Security;

use PDO;
use Exception;

class AuthenticationService
{
    private $db;
    private $sessionTimeout = 3600; // 1 hour
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Authenticate user with username/email and password
     */
    public function authenticate($login, $password)
    {
        try {
            // Find user by username or email
            $stmt = $this->db->prepare("
                SELECT id, username, email, password_hash, full_name, status 
                FROM mdm_users 
                WHERE (username = ? OR email = ?) AND status = 'active'
            ");
            $stmt->execute([$login, $login]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $this->logActivity(null, 'login_failed', 'authentication', null, [
                    'login_attempt' => $login,
                    'reason' => 'invalid_credentials'
                ]);
                return false;
            }
            
            // Update last login
            $this->updateLastLogin($user['id']);
            
            // Create session
            $sessionId = $this->createSession($user['id']);
            
            $this->logActivity($user['id'], 'login_success', 'authentication', null, [
                'session_id' => $sessionId
            ]);
            
            return [
                'user' => $user,
                'session_id' => $sessionId
            ];
            
        } catch (Exception $e) {
            error_log("Authentication error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create new session for user
     */
    private function createSession($userId)
    {
        $sessionId = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $this->sessionTimeout);
        
        $stmt = $this->db->prepare("
            INSERT INTO mdm_sessions (id, user_id, ip_address, user_agent, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $sessionId,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $expiresAt
        ]);
        
        return $sessionId;
    }
    
    /**
     * Validate session and return user data
     */
    public function validateSession($sessionId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT s.*, u.username, u.email, u.full_name, u.status
                FROM mdm_sessions s
                JOIN mdm_users u ON s.user_id = u.id
                WHERE s.id = ? AND s.expires_at > NOW() AND u.status = 'active'
            ");
            $stmt->execute([$sessionId]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$session) {
                return false;
            }
            
            // Update last activity
            $this->updateSessionActivity($sessionId);
            
            return $session;
            
        } catch (Exception $e) {
            error_log("Session validation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update session last activity
     */
    private function updateSessionActivity($sessionId)
    {
        $stmt = $this->db->prepare("
            UPDATE mdm_sessions 
            SET last_activity = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$sessionId]);
    }
    
    /**
     * Update user last login timestamp
     */
    private function updateLastLogin($userId)
    {
        $stmt = $this->db->prepare("
            UPDATE mdm_users 
            SET last_login = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
    }
    
    /**
     * Logout user and destroy session
     */
    public function logout($sessionId)
    {
        try {
            // Get session info for logging
            $session = $this->validateSession($sessionId);
            
            // Delete session
            $stmt = $this->db->prepare("DELETE FROM mdm_sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
            
            if ($session) {
                $this->logActivity($session['user_id'], 'logout', 'authentication', null, [
                    'session_id' => $sessionId
                ]);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Logout error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clean expired sessions
     */
    public function cleanExpiredSessions()
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM mdm_sessions WHERE expires_at < NOW()");
            $deletedCount = $stmt->execute();
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            error_log("Session cleanup error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Change user password
     */
    public function changePassword($userId, $currentPassword, $newPassword)
    {
        try {
            // Verify current password
            $stmt = $this->db->prepare("
                SELECT password_hash FROM mdm_users WHERE id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                $this->logActivity($userId, 'password_change_failed', 'authentication', null, [
                    'reason' => 'invalid_current_password'
                ]);
                return false;
            }
            
            // Update password
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $this->db->prepare("
                UPDATE mdm_users 
                SET password_hash = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$newPasswordHash, $userId]);
            
            $this->logActivity($userId, 'password_changed', 'authentication');
            
            return true;
            
        } catch (Exception $e) {
            error_log("Password change error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log user activity
     */
    private function logActivity($userId, $action, $resource = null, $resourceId = null, $details = [])
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mdm_user_activity_log 
                (user_id, session_id, action, resource, resource_id, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $_SESSION['mdm_session_id'] ?? null,
                $action,
                $resource,
                $resourceId,
                json_encode($details),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log("Activity logging error: " . $e->getMessage());
        }
    }
}