<?php

namespace MDM\Security;

use PDO;
use Exception;

class AuthorizationService
{
    private $db;
    private $userPermissions = [];
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Check if user has specific permission
     */
    public function hasPermission($userId, $permission)
    {
        try {
            $permissions = $this->getUserPermissions($userId);
            return in_array($permission, $permissions);
            
        } catch (Exception $e) {
            error_log("Permission check error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user has any of the specified permissions
     */
    public function hasAnyPermission($userId, $permissions)
    {
        $userPermissions = $this->getUserPermissions($userId);
        return !empty(array_intersect($permissions, $userPermissions));
    }
    
    /**
     * Check if user has all specified permissions
     */
    public function hasAllPermissions($userId, $permissions)
    {
        $userPermissions = $this->getUserPermissions($userId);
        return empty(array_diff($permissions, $userPermissions));
    }
    
    /**
     * Get all permissions for user
     */
    public function getUserPermissions($userId)
    {
        if (isset($this->userPermissions[$userId])) {
            return $this->userPermissions[$userId];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT DISTINCT r.permissions
                FROM mdm_user_roles ur
                JOIN mdm_roles r ON ur.role_id = r.id
                JOIN mdm_users u ON ur.user_id = u.id
                WHERE ur.user_id = ? 
                AND u.status = 'active'
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
            ");
            $stmt->execute([$userId]);
            
            $permissions = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rolePermissions = json_decode($row['permissions'], true);
                if (is_array($rolePermissions)) {
                    $permissions = array_merge($permissions, $rolePermissions);
                }
            }
            
            $this->userPermissions[$userId] = array_unique($permissions);
            return $this->userPermissions[$userId];
            
        } catch (Exception $e) {
            error_log("Get user permissions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user roles
     */
    public function getUserRoles($userId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT r.role_name, r.display_name, r.description, ur.assigned_at, ur.expires_at
                FROM mdm_user_roles ur
                JOIN mdm_roles r ON ur.role_id = r.id
                WHERE ur.user_id = ?
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                ORDER BY r.display_name
            ");
            $stmt->execute([$userId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Get user roles error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Assign role to user
     */
    public function assignRole($userId, $roleId, $assignedBy, $expiresAt = null)
    {
        try {
            // Check if assignment already exists
            $stmt = $this->db->prepare("
                SELECT id FROM mdm_user_roles 
                WHERE user_id = ? AND role_id = ?
            ");
            $stmt->execute([$userId, $roleId]);
            
            if ($stmt->fetch()) {
                return false; // Role already assigned
            }
            
            // Assign role
            $stmt = $this->db->prepare("
                INSERT INTO mdm_user_roles (user_id, role_id, assigned_by, expires_at)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $roleId, $assignedBy, $expiresAt]);
            
            // Clear cached permissions
            unset($this->userPermissions[$userId]);
            
            // Log activity
            $this->logActivity($assignedBy, 'role_assigned', 'user_roles', $userId, [
                'role_id' => $roleId,
                'expires_at' => $expiresAt
            ]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Assign role error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove role from user
     */
    public function removeRole($userId, $roleId, $removedBy)
    {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM mdm_user_roles 
                WHERE user_id = ? AND role_id = ?
            ");
            $result = $stmt->execute([$userId, $roleId]);
            
            if ($stmt->rowCount() > 0) {
                // Clear cached permissions
                unset($this->userPermissions[$userId]);
                
                // Log activity
                $this->logActivity($removedBy, 'role_removed', 'user_roles', $userId, [
                    'role_id' => $roleId
                ]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Remove role error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create new role
     */
    public function createRole($roleName, $displayName, $description, $permissions, $createdBy)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mdm_roles (role_name, display_name, description, permissions)
                VALUES (?, ?, ?, ?)
            ");
            $result = $stmt->execute([
                $roleName,
                $displayName,
                $description,
                json_encode($permissions)
            ]);
            
            if ($result) {
                $roleId = $this->db->lastInsertId();
                
                // Log activity
                $this->logActivity($createdBy, 'role_created', 'roles', $roleId, [
                    'role_name' => $roleName,
                    'permissions' => $permissions
                ]);
                
                return $roleId;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("Create role error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update role permissions
     */
    public function updateRole($roleId, $displayName, $description, $permissions, $updatedBy)
    {
        try {
            $stmt = $this->db->prepare("
                UPDATE mdm_roles 
                SET display_name = ?, description = ?, permissions = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $result = $stmt->execute([
                $displayName,
                $description,
                json_encode($permissions),
                $roleId
            ]);
            
            if ($result) {
                // Clear all cached permissions since role changed
                $this->userPermissions = [];
                
                // Log activity
                $this->logActivity($updatedBy, 'role_updated', 'roles', $roleId, [
                    'permissions' => $permissions
                ]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Update role error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all available roles
     */
    public function getAllRoles()
    {
        try {
            $stmt = $this->db->prepare("
                SELECT id, role_name, display_name, description, permissions, created_at, updated_at
                FROM mdm_roles
                ORDER BY display_name
            ");
            $stmt->execute();
            
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode permissions JSON
            foreach ($roles as &$role) {
                $role['permissions'] = json_decode($role['permissions'], true) ?: [];
            }
            
            return $roles;
            
        } catch (Exception $e) {
            error_log("Get all roles error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if user can perform action on resource
     */
    public function canPerformAction($userId, $action, $resource = null)
    {
        $permission = $resource ? "{$resource}.{$action}" : $action;
        return $this->hasPermission($userId, $permission);
    }
    
    /**
     * Middleware function to check permissions
     */
    public function requirePermission($userId, $permission)
    {
        if (!$this->hasPermission($userId, $permission)) {
            // Log unauthorized access attempt
            $this->logActivity($userId, 'access_denied', 'authorization', null, [
                'required_permission' => $permission
            ]);
            
            throw new Exception("Access denied. Required permission: {$permission}");
        }
        
        return true;
    }
    
    /**
     * Log authorization activity
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