<?php
require_once 'config.php';

header('Content-Type: application/json');

try {
    $pdo = getDatabaseConnection();
    
    $action = $_GET['action'] ?? 'dashboard';
    
    if ($action === 'dashboard') {
        // Простой запрос данных
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM inventory_data WHERE current_stock > 0");
        $result = $stmt->fetch();
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'total_products' => (int)$result['total'],
                'message' => 'Система работает!'
            ]
        ]);
    } else {
        echo json_encode([
            'status' => 'success',
            'message' => 'API работает'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>