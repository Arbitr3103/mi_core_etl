<?php

namespace MDM\Security;

use PDO;
use Exception;

class ActivityLogger
{
    private $db;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Log user activity
     */
    public function log($userId, $action, $resource = null, $resourceId = null, $details = [], $sessionId = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mdm_user_activity_log 
                (user_id, session_id, action, resource, resource_id, details, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $sessionId ?: ($_SESSION['mdm_session_id'] ?? null),
                $action,
                $resource,
                $resourceId,
                json_encode($details),
                $this->getClientIp(),
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            return $this->db->lastInsertId();
            
        } catch (Exception $e) {
            error_log("Activity logging error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log product-related activity
     */
    public function logProductActivity($userId, $action, $productId, $details = [])
    {
        return $this->log($userId, $action, 'product', $productId, $details);
    }
    
    /**
     * Log verification activity
     */
    public function logVerificationActivity($userId, $action, $verificationId, $details = [])
    {
        return $this->log($userId, $action, 'verification', $verificationId, $details);
    }
    
    /**
     * Log system configuration changes
     */
    public function logSystemActivity($userId, $action, $details = [])
    {
        return $this->log($userId, $action, 'system', null, $details);
    }
    
    /**
     * Log user management activity
     */
    public function logUserManagement($userId, $action, $targetUserId, $details = [])
    {
        return $this->log($userId, $action, 'user_management', $targetUserId, $details);
    }
    
    /**
     * Get activity log for user
     */
    public function getUserActivity($userId, $limit = 100, $offset = 0, $filters = [])
    {
        try {
            $whereConditions = ['user_id = ?'];
            $params = [$userId];
            
            // Add filters
            if (!empty($filters['action'])) {
                $whereConditions[] = 'action = ?';
                $params[] = $filters['action'];
            }
            
            if (!empty($filters['resource'])) {
                $whereConditions[] = 'resource = ?';
                $params[] = $filters['resource'];
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = 'created_at >= ?';
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = 'created_at <= ?';
                $params[] = $filters['date_to'];
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            $stmt = $this->db->prepare("
                SELECT * FROM mdm_user_activity_log
                WHERE {$whereClause}
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt->execute($params);
            
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode details JSON
            foreach ($activities as &$activity) {
                $activity['details'] = json_decode($activity['details'], true) ?: [];
            }
            
            return $activities;
            
        } catch (Exception $e) {
            error_log("Get user activity error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get system-wide activity log
     */
    public function getSystemActivity($limit = 100, $offset = 0, $filters = [])
    {
        try {
            $whereConditions = ['1=1'];
            $params = [];
            
            // Add filters
            if (!empty($filters['user_id'])) {
                $whereConditions[] = 'user_id = ?';
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['action'])) {
                $whereConditions[] = 'action = ?';
                $params[] = $filters['action'];
            }
            
            if (!empty($filters['resource'])) {
                $whereConditions[] = 'resource = ?';
                $params[] = $filters['resource'];
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = 'created_at >= ?';
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = 'created_at <= ?';
                $params[] = $filters['date_to'];
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            $stmt = $this->db->prepare("
                SELECT l.*, u.username, u.full_name
                FROM mdm_user_activity_log l
                LEFT JOIN mdm_users u ON l.user_id = u.id
                WHERE {$whereClause}
                ORDER BY l.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt->execute($params);
            
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode details JSON
            foreach ($activities as &$activity) {
                $activity['details'] = json_decode($activity['details'], true) ?: [];
            }
            
            return $activities;
            
        } catch (Exception $e) {
            error_log("Get system activity error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get activity statistics
     */
    public function getActivityStats($dateFrom = null, $dateTo = null)
    {
        try {
            $whereConditions = ['1=1'];
            $params = [];
            
            if ($dateFrom) {
                $whereConditions[] = 'created_at >= ?';
                $params[] = $dateFrom;
            }
            
            if ($dateTo) {
                $whereConditions[] = 'created_at <= ?';
                $params[] = $dateTo;
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            // Get activity by action
            $stmt = $this->db->prepare("
                SELECT action, COUNT(*) as count
                FROM mdm_user_activity_log
                WHERE {$whereClause}
                GROUP BY action
                ORDER BY count DESC
            ");
            $stmt->execute($params);
            $actionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get activity by user
            $stmt = $this->db->prepare("
                SELECT l.user_id, u.username, u.full_name, COUNT(*) as count
                FROM mdm_user_activity_log l
                LEFT JOIN mdm_users u ON l.user_id = u.id
                WHERE {$whereClause}
                GROUP BY l.user_id, u.username, u.full_name
                ORDER BY count DESC
                LIMIT 10
            ");
            $stmt->execute($params);
            $userStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get activity by resource
            $stmt = $this->db->prepare("
                SELECT resource, COUNT(*) as count
                FROM mdm_user_activity_log
                WHERE {$whereClause} AND resource IS NOT NULL
                GROUP BY resource
                ORDER BY count DESC
            ");
            $stmt->execute($params);
            $resourceStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'actions' => $actionStats,
                'users' => $userStats,
                'resources' => $resourceStats
            ];
            
        } catch (Exception $e) {
            error_log("Get activity stats error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clean old activity logs
     */
    public function cleanOldLogs($daysToKeep = 90)
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));
            
            $stmt = $this->db->prepare("
                DELETE FROM mdm_user_activity_log 
                WHERE created_at < ?
            ");
            $stmt->execute([$cutoffDate]);
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            error_log("Clean old logs error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get client IP address
     */
    private function getClientIp()
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}