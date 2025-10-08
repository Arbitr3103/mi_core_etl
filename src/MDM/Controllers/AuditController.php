<?php

namespace MDM\Controllers;

use MDM\Services\AuditService;
use MDM\Security\AuthorizationService;
use Exception;

class AuditController
{
    private $auditService;
    private $authzService;
    
    public function __construct(AuditService $auditService, AuthorizationService $authzService)
    {
        $this->auditService = $auditService;
        $this->authzService = $authzService;
    }
    
    /**
     * Show audit dashboard
     */
    public function index()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'audit.view');
            
            // Get audit statistics
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
            $dateTo = $_GET['date_to'] ?? date('Y-m-d');
            
            $statistics = $this->auditService->getAuditStatistics($dateFrom, $dateTo);
            
            // Get recent audit entries
            $recentAudit = array_merge(
                $this->auditService->getMasterProductAudit(null, 20),
                $this->auditService->getSkuMappingAudit(null, null, 20)
            );
            
            // Sort by timestamp
            usort($recentAudit, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            
            $recentAudit = array_slice($recentAudit, 0, 20);
            
            include __DIR__ . '/../Views/audit/index.php';
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /dashboard');
        }
    }
    
    /**
     * Show master product audit
     */
    public function masterProducts()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'audit.view');
            
            $masterId = $_GET['master_id'] ?? null;
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = 50;
            $offset = ($page - 1) * $limit;
            
            $filters = [
                'operation' => $_GET['operation'] ?? null,
                'user_id' => $_GET['user_id'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null
            ];
            
            $auditLog = $this->auditService->getMasterProductAudit($masterId, $limit, $offset, $filters);
            
            include __DIR__ . '/../Views/audit/master_products.php';
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /audit');
        }
    }
    
    /**
     * Show SKU mapping audit
     */
    public function skuMappings()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'audit.view');
            
            $mappingId = $_GET['mapping_id'] ?? null;
            $masterId = $_GET['master_id'] ?? null;
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = 50;
            $offset = ($page - 1) * $limit;
            
            $filters = [
                'operation' => $_GET['operation'] ?? null,
                'source' => $_GET['source'] ?? null,
                'user_id' => $_GET['user_id'] ?? null,
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null
            ];
            
            $auditLog = $this->auditService->getSkuMappingAudit($mappingId, $masterId, $limit, $offset, $filters);
            
            include __DIR__ . '/../Views/audit/sku_mappings.php';
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /audit');
        }
    }
    
    /**
     * Show version history for master product
     */
    public function versions()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'audit.view');
            
            $masterId = $_GET['master_id'] ?? null;
            if (!$masterId) {
                throw new Exception('Master ID is required');
            }
            
            $versions = $this->auditService->getMasterProductVersions($masterId);
            
            include __DIR__ . '/../Views/audit/versions.php';
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /audit');
        }
    }
    
    /**
     * Rollback master product to specific version
     */
    public function rollback()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'products.edit');
            
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Invalid request method');
            }
            
            $masterId = $_POST['master_id'] ?? null;
            $toVersion = intval($_POST['to_version'] ?? 0);
            $reason = $_POST['reason'] ?? null;
            
            if (!$masterId || !$toVersion) {
                throw new Exception('Master ID and version are required');
            }
            
            $result = $this->auditService->rollbackMasterProduct($masterId, $toVersion, $user['id'], $reason);
            
            if ($result) {
                if ($this->isAjaxRequest()) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => 'Product successfully rolled back to version ' . $toVersion
                    ]);
                } else {
                    $_SESSION['success_message'] = 'Product successfully rolled back to version ' . $toVersion;
                    header('Location: /audit/versions?master_id=' . urlencode($masterId));
                }
            } else {
                throw new Exception('Failed to rollback product');
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
                header('Location: /audit');
            }
        }
    }
    
    /**
     * Export audit report
     */
    public function export()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'audit.view');
            
            $format = $_GET['format'] ?? 'csv';
            $filters = [
                'date_from' => $_GET['date_from'] ?? null,
                'date_to' => $_GET['date_to'] ?? null,
                'operation' => $_GET['operation'] ?? null,
                'user_id' => $_GET['user_id'] ?? null
            ];
            
            $report = $this->auditService->exportAuditReport($format, $filters);
            
            if ($report === false) {
                throw new Exception('Failed to generate audit report');
            }
            
            $filename = 'audit_report_' . date('Y-m-d_H-i-s') . '.' . $format;
            
            if ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo $report;
            } elseif ($format === 'json') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                echo $report;
            }
            
        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
            header('Location: /audit');
        }
    }
    
    /**
     * Get audit details via AJAX
     */
    public function getDetails()
    {
        try {
            $user = $this->getCurrentUser();
            $this->authzService->requirePermission($user['id'], 'audit.view');
            
            $type = $_GET['type'] ?? null;
            $id = $_GET['id'] ?? null;
            
            if (!$type || !$id) {
                throw new Exception('Type and ID are required');
            }
            
            $details = null;
            
            if ($type === 'master_product') {
                $auditLog = $this->auditService->getMasterProductAudit($id, 1);
                $details = $auditLog[0] ?? null;
            } elseif ($type === 'sku_mapping') {
                $auditLog = $this->auditService->getSkuMappingAudit($id, null, 1);
                $details = $auditLog[0] ?? null;
            }
            
            if (!$details) {
                throw new Exception('Audit entry not found');
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $details
            ]);
            
        } catch (Exception $e) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
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
     * Check if request is AJAX
     */
    private function isAjaxRequest()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }
}