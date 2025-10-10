<?php
/**
 * Sync Trigger API
 * 
 * Provides endpoints for manual synchronization of product names
 * from the dashboard interface.
 * 
 * Requirements: 3.2
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// Load configuration
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/SafeSyncEngine.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed'
    ]);
    exit();
}

// Parse request
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['action'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Action parameter required'
    ]);
    exit();
}

$action = $input['action'];

try {
    switch ($action) {
        case 'sync_all':
            handleSyncAll($pdo);
            break;
            
        case 'sync_product':
            handleSyncProduct($pdo, $input);
            break;
            
        case 'sync_failed':
            handleSyncFailed($pdo);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Unknown action'
            ]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Handle sync all pending products
 */
function handleSyncAll($pdo) {
    $syncEngine = new SafeSyncEngine($pdo);
    
    // Sync up to 100 pending products
    $results = $syncEngine->syncProductNames(100);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'total' => $results['total'],
            'success' => $results['success'],
            'failed' => $results['failed'],
            'skipped' => $results['skipped'],
            'errors' => $results['errors']
        ]
    ]);
}

/**
 * Handle sync single product
 */
function handleSyncProduct($pdo, $input) {
    if (!isset($input['product_id'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Product ID required'
        ]);
        return;
    }
    
    $productId = $input['product_id'];
    
    // Get product from cross-reference
    $stmt = $pdo->prepare("
        SELECT 
            pcr.id as cross_ref_id,
            pcr.inventory_product_id,
            pcr.ozon_product_id,
            pcr.sku_ozon,
            pcr.sync_status
        FROM product_cross_reference pcr
        WHERE pcr.inventory_product_id = :product_id
           OR pcr.ozon_product_id = :product_id
        LIMIT 1
    ");
    
    $stmt->execute([':product_id' => $productId]);
    $product = $stmt->fetch();
    
    if (!$product) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Product not found'
        ]);
        return;
    }
    
    // Use FallbackDataProvider to get product name
    require_once __DIR__ . '/../src/FallbackDataProvider.php';
    $fallbackProvider = new FallbackDataProvider($pdo);
    
    $productName = $fallbackProvider->getProductName($productId);
    
    if (!$productName) {
        echo json_encode([
            'success' => false,
            'error' => 'Could not retrieve product name'
        ]);
        return;
    }
    
    // Update product in database
    $pdo->beginTransaction();
    
    try {
        // Update cross_reference
        $stmt = $pdo->prepare("
            UPDATE product_cross_reference
            SET 
                cached_name = :product_name,
                last_api_sync = NOW(),
                sync_status = 'synced',
                updated_at = NOW()
            WHERE id = :cross_ref_id
        ");
        
        $stmt->execute([
            ':product_name' => $productName,
            ':cross_ref_id' => $product['cross_ref_id']
        ]);
        
        // Update dim_products if linked
        if (!empty($product['sku_ozon'])) {
            $stmt = $pdo->prepare("
                UPDATE dim_products
                SET 
                    name = :product_name,
                    updated_at = NOW()
                WHERE sku_ozon = :sku_ozon
            ");
            
            $stmt->execute([
                ':product_name' => $productName,
                ':sku_ozon' => $product['sku_ozon']
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'product_id' => $productId,
                'product_name' => $productName,
                'sync_status' => 'synced'
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Handle sync failed products
 */
function handleSyncFailed($pdo) {
    // Reset failed products to pending
    $stmt = $pdo->prepare("
        UPDATE product_cross_reference
        SET sync_status = 'pending'
        WHERE sync_status = 'failed'
    ");
    
    $stmt->execute();
    $resetCount = $stmt->rowCount();
    
    // Now sync them
    $syncEngine = new SafeSyncEngine($pdo);
    $results = $syncEngine->syncProductNames(50);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'reset_count' => $resetCount,
            'sync_results' => $results
        ]
    ]);
}
?>
