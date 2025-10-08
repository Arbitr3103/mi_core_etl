<?php

namespace MDM\Services;

use PDO;
use Exception;

class AuditService
{
    private $db;
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Set audit context for database operations
     */
    public function setAuditContext($userId, $sessionId = null, $ipAddress = null)
    {
        try {
            $this->db->exec("SET @audit_user_id = " . ($userId ? $userId : 'NULL'));
            $this->db->exec("SET @audit_session_id = " . ($sessionId ? "'{$sessionId}'" : 'NULL'));
            $this->db->exec("SET @audit_ip_address = " . ($ipAddress ? "'{$ipAddress}'" : 'NULL'));
            
        } catch (Exception $e) {
            error_log("Set audit context error: " . $e->getMessage());
        }
    }
    
    /**
     * Get audit log for master products
     */
    public function getMasterProductAudit($masterId = null, $limit = 100, $offset = 0, $filters = [])
    {
        try {
            $whereConditions = ['1=1'];
            $params = [];
            
            if ($masterId) {
                $whereConditions[] = 'a.master_id = ?';
                $params[] = $masterId;
            }
            
            if (!empty($filters['operation'])) {
                $whereConditions[] = 'a.operation = ?';
                $params[] = $filters['operation'];
            }
            
            if (!empty($filters['user_id'])) {
                $whereConditions[] = 'a.user_id = ?';
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = 'a.created_at >= ?';
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = 'a.created_at <= ?';
                $params[] = $filters['date_to'];
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            $stmt = $this->db->prepare("
                SELECT a.*, u.username, u.full_name
                FROM mdm_master_products_audit a
                LEFT JOIN mdm_users u ON a.user_id = u.id
                WHERE {$whereClause}
                ORDER BY a.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt->execute($params);
            
            $auditLog = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON fields
            foreach ($auditLog as &$entry) {
                $entry['old_values'] = $entry['old_values'] ? json_decode($entry['old_values'], true) : null;
                $entry['new_values'] = $entry['new_values'] ? json_decode($entry['new_values'], true) : null;
                $entry['changed_fields'] = $entry['changed_fields'] ? json_decode($entry['changed_fields'], true) : null;
            }
            
            return $auditLog;
            
        } catch (Exception $e) {
            error_log("Get master product audit error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get audit log for SKU mappings
     */
    public function getSkuMappingAudit($mappingId = null, $masterId = null, $limit = 100, $offset = 0, $filters = [])
    {
        try {
            $whereConditions = ['1=1'];
            $params = [];
            
            if ($mappingId) {
                $whereConditions[] = 'a.mapping_id = ?';
                $params[] = $mappingId;
            }
            
            if ($masterId) {
                $whereConditions[] = 'a.master_id = ?';
                $params[] = $masterId;
            }
            
            if (!empty($filters['operation'])) {
                $whereConditions[] = 'a.operation = ?';
                $params[] = $filters['operation'];
            }
            
            if (!empty($filters['source'])) {
                $whereConditions[] = 'a.source = ?';
                $params[] = $filters['source'];
            }
            
            if (!empty($filters['user_id'])) {
                $whereConditions[] = 'a.user_id = ?';
                $params[] = $filters['user_id'];
            }
            
            if (!empty($filters['date_from'])) {
                $whereConditions[] = 'a.created_at >= ?';
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereConditions[] = 'a.created_at <= ?';
                $params[] = $filters['date_to'];
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            $stmt = $this->db->prepare("
                SELECT a.*, u.username, u.full_name
                FROM mdm_sku_mapping_audit a
                LEFT JOIN mdm_users u ON a.user_id = u.id
                WHERE {$whereClause}
                ORDER BY a.created_at DESC
                LIMIT ? OFFSET ?
            ");
            
            $params[] = $limit;
            $params[] = $offset;
            
            $stmt->execute($params);
            
            $auditLog = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON fields
            foreach ($auditLog as &$entry) {
                $entry['old_values'] = $entry['old_values'] ? json_decode($entry['old_values'], true) : null;
                $entry['new_values'] = $entry['new_values'] ? json_decode($entry['new_values'], true) : null;
                $entry['changed_fields'] = $entry['changed_fields'] ? json_decode($entry['changed_fields'], true) : null;
            }
            
            return $auditLog;
            
        } catch (Exception $e) {
            error_log("Get SKU mapping audit error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get version history for master product
     */
    public function getMasterProductVersions($masterId)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT v.*, u.username, u.full_name
                FROM mdm_master_products_versions v
                LEFT JOIN mdm_users u ON v.created_by = u.id
                WHERE v.master_id = ?
                ORDER BY v.version_number DESC
            ");
            $stmt->execute([$masterId]);
            
            $versions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode attributes JSON
            foreach ($versions as &$version) {
                $version['attributes'] = $version['attributes'] ? json_decode($version['attributes'], true) : null;
            }
            
            return $versions;
            
        } catch (Exception $e) {
            error_log("Get master product versions error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get specific version of master product
     */
    public function getMasterProductVersion($masterId, $versionNumber)
    {
        try {
            $stmt = $this->db->prepare("
                SELECT v.*, u.username, u.full_name
                FROM mdm_master_products_versions v
                LEFT JOIN mdm_users u ON v.created_by = u.id
                WHERE v.master_id = ? AND v.version_number = ?
            ");
            $stmt->execute([$masterId, $versionNumber]);
            
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($version) {
                $version['attributes'] = $version['attributes'] ? json_decode($version['attributes'], true) : null;
            }
            
            return $version;
            
        } catch (Exception $e) {
            error_log("Get master product version error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Rollback master product to specific version
     */
    public function rollbackMasterProduct($masterId, $toVersion, $userId, $reason = null)
    {
        try {
            $this->db->beginTransaction();
            
            // Get target version data
            $targetVersion = $this->getMasterProductVersion($masterId, $toVersion);
            if (!$targetVersion) {
                throw new Exception("Version {$toVersion} not found for master product {$masterId}");
            }
            
            // Get current version for rollback log
            $currentVersion = $this->getMasterProductVersion($masterId, null);
            
            // Set audit context
            $this->setAuditContext($userId, $_SESSION['mdm_session_id'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null);
            
            // Update master product with target version data
            $stmt = $this->db->prepare("
                UPDATE master_products 
                SET canonical_name = ?, canonical_brand = ?, canonical_category = ?,
                    description = ?, attributes = ?, status = ?, updated_at = NOW()
                WHERE master_id = ?
            ");
            
            $stmt->execute([
                $targetVersion['canonical_name'],
                $targetVersion['canonical_brand'],
                $targetVersion['canonical_category'],
                $targetVersion['description'],
                json_encode($targetVersion['attributes']),
                $targetVersion['status'],
                $masterId
            ]);
            
            // Log rollback operation
            $stmt = $this->db->prepare("
                INSERT INTO mdm_rollback_operations 
                (operation_type, target_id, from_version, to_version, rollback_reason, 
                 rollback_data, performed_by, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $rollbackData = [
                'master_id' => $masterId,
                'from_data' => $currentVersion,
                'to_data' => $targetVersion
            ];
            
            $stmt->execute([
                'master_product',
                $masterId,
                $currentVersion['version_number'] ?? null,
                $toVersion,
                $reason,
                json_encode($rollbackData),
                $userId,
                'completed'
            ]);
            
            $this->db->commit();
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Rollback master product error: " . $e->getMessage());
            
            // Log failed rollback
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO mdm_rollback_operations 
                    (operation_type, target_id, to_version, rollback_reason, 
                     performed_by, status, error_message)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    'master_product',
                    $masterId,
                    $toVersion,
                    $reason,
                    $userId,
                    'failed',
                    $e->getMessage()
                ]);
            } catch (Exception $logError) {
                error_log("Failed to log rollback error: " . $logError->getMessage());
            }
            
            return false;
        }
    }
    
    /**
     * Get audit statistics
     */
    public function getAuditStatistics($dateFrom = null, $dateTo = null)
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
            
            // Master products audit stats
            $stmt = $this->db->prepare("
                SELECT operation, COUNT(*) as count
                FROM mdm_master_products_audit
                WHERE {$whereClause}
                GROUP BY operation
            ");
            $stmt->execute($params);
            $masterProductStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // SKU mapping audit stats
            $stmt = $this->db->prepare("
                SELECT operation, COUNT(*) as count
                FROM mdm_sku_mapping_audit
                WHERE {$whereClause}
                GROUP BY operation
            ");
            $stmt->execute($params);
            $skuMappingStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Most active users
            $stmt = $this->db->prepare("
                SELECT u.username, u.full_name, COUNT(*) as total_operations
                FROM (
                    SELECT user_id FROM mdm_master_products_audit WHERE {$whereClause}
                    UNION ALL
                    SELECT user_id FROM mdm_sku_mapping_audit WHERE {$whereClause}
                ) a
                LEFT JOIN mdm_users u ON a.user_id = u.id
                WHERE a.user_id IS NOT NULL
                GROUP BY a.user_id, u.username, u.full_name
                ORDER BY total_operations DESC
                LIMIT 10
            ");
            $stmt->execute(array_merge($params, $params));
            $activeUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'master_products' => $masterProductStats,
                'sku_mappings' => $skuMappingStats,
                'active_users' => $activeUsers
            ];
            
        } catch (Exception $e) {
            error_log("Get audit statistics error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Clean old audit records
     */
    public function cleanOldAuditRecords($daysToKeep = 365)
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$daysToKeep} days"));
            
            // Clean master products audit
            $stmt = $this->db->prepare("
                DELETE FROM mdm_master_products_audit 
                WHERE created_at < ?
            ");
            $stmt->execute([$cutoffDate]);
            $masterProductsDeleted = $stmt->rowCount();
            
            // Clean SKU mapping audit
            $stmt = $this->db->prepare("
                DELETE FROM mdm_sku_mapping_audit 
                WHERE created_at < ?
            ");
            $stmt->execute([$cutoffDate]);
            $skuMappingDeleted = $stmt->rowCount();
            
            // Clean old versions (keep at least 5 versions per product)
            $stmt = $this->db->prepare("
                DELETE v1 FROM mdm_master_products_versions v1
                WHERE v1.created_at < ? 
                AND v1.is_current = FALSE
                AND (
                    SELECT COUNT(*) 
                    FROM mdm_master_products_versions v2 
                    WHERE v2.master_id = v1.master_id 
                    AND v2.version_number > v1.version_number
                ) >= 5
            ");
            $stmt->execute([$cutoffDate]);
            $versionsDeleted = $stmt->rowCount();
            
            return [
                'master_products_audit' => $masterProductsDeleted,
                'sku_mapping_audit' => $skuMappingDeleted,
                'versions_deleted' => $versionsDeleted
            ];
            
        } catch (Exception $e) {
            error_log("Clean old audit records error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Export audit report
     */
    public function exportAuditReport($format = 'csv', $filters = [])
    {
        try {
            // Get audit data based on filters
            $auditData = [];
            
            // Master products audit
            $masterProductAudit = $this->getMasterProductAudit(null, 10000, 0, $filters);
            foreach ($masterProductAudit as $entry) {
                $auditData[] = [
                    'type' => 'master_product',
                    'id' => $entry['master_id'],
                    'operation' => $entry['operation'],
                    'user' => $entry['username'] ?: 'System',
                    'timestamp' => $entry['created_at'],
                    'changes' => implode(', ', $entry['changed_fields'] ?: [])
                ];
            }
            
            // SKU mapping audit
            $skuMappingAudit = $this->getSkuMappingAudit(null, null, 10000, 0, $filters);
            foreach ($skuMappingAudit as $entry) {
                $auditData[] = [
                    'type' => 'sku_mapping',
                    'id' => $entry['external_sku'],
                    'operation' => $entry['operation'],
                    'user' => $entry['username'] ?: 'System',
                    'timestamp' => $entry['created_at'],
                    'changes' => implode(', ', $entry['changed_fields'] ?: [])
                ];
            }
            
            // Sort by timestamp
            usort($auditData, function($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });
            
            if ($format === 'csv') {
                return $this->exportToCsv($auditData);
            } elseif ($format === 'json') {
                return json_encode($auditData, JSON_PRETTY_PRINT);
            }
            
            return $auditData;
            
        } catch (Exception $e) {
            error_log("Export audit report error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Export data to CSV format
     */
    private function exportToCsv($data)
    {
        if (empty($data)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        
        // Write header
        fputcsv($output, array_keys($data[0]));
        
        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
}