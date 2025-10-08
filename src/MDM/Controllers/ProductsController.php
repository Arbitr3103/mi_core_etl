<?php

namespace MDM\Controllers;

use MDM\Services\ProductsService;
use MDM\Services\HistoryService;

/**
 * Products Controller for MDM System
 * Handles master product management operations
 */
class ProductsController
{
    private ProductsService $productsService;
    private HistoryService $historyService;

    public function __construct()
    {
        $this->productsService = new ProductsService();
        $this->historyService = new HistoryService();
    }

    /**
     * Display products management interface
     */
    public function index(): void
    {
        try {
            $statistics = $this->productsService->getProductStatistics();
            $this->renderProductsInterface($statistics);
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Get products list via AJAX
     */
    public function getProductsAjax(): void
    {
        header('Content-Type: application/json');
        
        try {
            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 20);
            $search = $_GET['search'] ?? '';
            $filter = $_GET['filter'] ?? 'all';
            $sortBy = $_GET['sort_by'] ?? 'updated_at';
            $sortOrder = $_GET['sort_order'] ?? 'desc';
            
            $result = $this->productsService->getProducts($page, $limit, $search, $filter, $sortBy, $sortOrder);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Get single product details
     */
    public function getProductDetails(): void
    {
        header('Content-Type: application/json');
        
        try {
            $masterId = $_GET['master_id'] ?? null;
            
            if (!$masterId) {
                throw new Exception('Master ID is required');
            }
            
            $product = $this->productsService->getProductDetails($masterId);
            $mappings = $this->productsService->getProductMappings($masterId);
            $history = $this->historyService->getProductHistory($masterId);
            
            echo json_encode([
                'success' => true, 
                'data' => [
                    'product' => $product,
                    'mappings' => $mappings,
                    'history' => $history
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Update product
     */
    public function updateProduct(): void
    {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $masterId = $input['master_id'] ?? null;
            $productData = $input['product_data'] ?? [];
            
            if (!$masterId) {
                throw new Exception('Master ID is required');
            }
            
            $result = $this->productsService->updateProduct($masterId, $productData);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Delete product
     */
    public function deleteProduct(): void
    {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $masterId = $input['master_id'] ?? null;
            
            if (!$masterId) {
                throw new Exception('Master ID is required');
            }
            
            $result = $this->productsService->deleteProduct($masterId);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Bulk update products
     */
    public function bulkUpdate(): void
    {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $masterIds = $input['master_ids'] ?? [];
            $updateData = $input['update_data'] ?? [];
            
            if (empty($masterIds)) {
                throw new Exception('No master IDs provided');
            }
            
            $result = $this->productsService->bulkUpdateProducts($masterIds, $updateData);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Merge products
     */
    public function mergeProducts(): void
    {
        header('Content-Type: application/json');
        
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            $primaryMasterId = $input['primary_master_id'] ?? null;
            $secondaryMasterIds = $input['secondary_master_ids'] ?? [];
            
            if (!$primaryMasterId || empty($secondaryMasterIds)) {
                throw new Exception('Primary and secondary master IDs are required');
            }
            
            $result = $this->productsService->mergeProducts($primaryMasterId, $secondaryMasterIds);
            echo json_encode(['success' => true, 'data' => $result]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Export products
     */
    public function exportProducts(): void
    {
        try {
            $format = $_GET['format'] ?? 'csv';
            $filter = $_GET['filter'] ?? 'all';
            $search = $_GET['search'] ?? '';
            
            $result = $this->productsService->exportProducts($format, $filter, $search);
            
            // Set appropriate headers for download
            $filename = 'master_products_' . date('Y-m-d_H-i-s') . '.' . $format;
            
            switch ($format) {
                case 'csv':
                    header('Content-Type: text/csv');
                    break;
                case 'xlsx':
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    break;
                case 'json':
                    header('Content-Type: application/json');
                    break;
            }
            
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $result;
        } catch (Exception $e) {
            $this->handleError($e);
        }
    }

    /**
     * Render products interface
     */
    private function renderProductsInterface(array $statistics): void
    {
        $pageTitle = 'Управление товарами - MDM System';
        $cssFiles = [
            '/src/MDM/assets/css/dashboard.css',
            '/src/MDM/assets/css/products.css'
        ];
        $jsFiles = [
            '/src/MDM/assets/js/products.js'
        ];

        include __DIR__ . '/../Views/products.php';
    }

    /**
     * Handle errors and display error page
     */
    private function handleError(Exception $e): void
    {
        error_log("Products Error: " . $e->getMessage());
        
        $errorData = [
            'message' => 'Ошибка управления товарами',
            'details' => $e->getMessage()
        ];
        
        include __DIR__ . '/../Views/error.php';
    }
}