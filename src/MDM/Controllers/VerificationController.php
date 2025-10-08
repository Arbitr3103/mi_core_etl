<?php

namespace MDM\Controllers;

use MDM\Services\VerificationService;
use MDM\Services\MatchingService;

/**
 * Verification Controller for MDM System
 * Handles manual verification of product matches
 */
class VerificationController
{
    private VerificationService $verificationService;
    private MatchingService $matchingService;

    public function __construct()
    {
        $this->verificationService = new VerificationService();
        $this->matchingService = new MatchingService();
    }

    /**
     * Display verification interface
     */
    public function index(): void
    {
        try {
            $pendingItems = $this->verificationService->getPendingVerificationItems();
            $statistics = $this->verificationService->getVerificationStatistics();
            
            $this->renderVerificationInterface($pendingItems, $statistics);
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Get pending items via AJAX
     */
    public function getPendingItemsAjax(): void
    {
        header('Content-Type: application/json');
        
        try {
            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 20);
            $filter = $_GET['filter'] ?? 'all';
            
            $result = $this->verificationService->getPendingVerificationItems($page, $limit, $filter);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get product details for comparison
     */
    public function getProductDetails(): void
    {
        header('Content-Type: application/json');
        
        try {
            $skuMappingId = $_GET['sku_mapping_id'] ?? null;
            
            if (!$skuMappingId) {
                throw new Exception('SKU mapping ID is required');
            }
            
            $details = $this->verificationService->getProductComparisonDetails($skuMappingId);
            echo json_encode(['success' => true, 'data' => $details]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Find similar products for matching
     */
    public function findSimilarProducts(): void
    {
        header('Content-Type: application/json');
        
        try {
            $skuMappingId = $_GET['sku_mapping_id'] ?? null;
            
            if (!$skuMappingId) {
                throw new Exception('SKU mapping ID is required');
            }
            
            $similarProducts = $this->matchingService->findSimilarProducts($skuMappingId);
            echo json_encode(['success' => true, 'data' => $similarProducts]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Approve product match
     */
    public function approveMatch(): void
    {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $skuMappingId = $input['sku_mapping_id'] ?? null;
            $masterId = $input['master_id'] ?? null;
            
            if (!$skuMappingId) {
                throw new Exception('SKU mapping ID is required');
            }
            
            $result = $this->verificationService->approveMatch($skuMappingId, $masterId);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Reject product match
     */
    public function rejectMatch(): void
    {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $skuMappingId = $input['sku_mapping_id'] ?? null;
            $reason = $input['reason'] ?? '';
            
            if (!$skuMappingId) {
                throw new Exception('SKU mapping ID is required');
            }
            
            $result = $this->verificationService->rejectMatch($skuMappingId, $reason);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Create new master product
     */
    public function createNewMaster(): void
    {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $skuMappingId = $input['sku_mapping_id'] ?? null;
            $masterData = $input['master_data'] ?? [];
            
            if (!$skuMappingId) {
                throw new Exception('SKU mapping ID is required');
            }
            
            $result = $this->verificationService->createNewMasterProduct($skuMappingId, $masterData);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Bulk approve matches
     */
    public function bulkApprove(): void
    {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $skuMappingIds = $input['sku_mapping_ids'] ?? [];
            
            if (empty($skuMappingIds)) {
                throw new Exception('No SKU mapping IDs provided');
            }
            
            $result = $this->verificationService->bulkApproveMatches($skuMappingIds);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Render verification interface
     */
    private function renderVerificationInterface(array $pendingItems, array $statistics): void
    {
        $pageTitle = 'Верификация товаров - MDM System';
        $cssFiles = [
            '/src/MDM/assets/css/dashboard.css',
            '/src/MDM/assets/css/verification.css'
        ];
        $jsFiles = [
            '/src/MDM/assets/js/verification.js'
        ];

        include __DIR__ . '/../Views/verification.php';
    }

    /**
     * Handle errors and display error page
     */
    private function handleError(Exception $e): void
    {
        error_log("Verification Error: " . $e->getMessage());
        
        $errorData = [
            'message' => 'Ошибка загрузки интерфейса верификации',
            'details' => $e->getMessage()
        ];
        
        include __DIR__ . '/../Views/error.php';
    }
}