<?php
/**
 * Адаптер для рекомендаций по пополнению - исправленная версия
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Прямое подключение к базе данных
function getDatabaseConnection() {
    try {
        $dsn = "mysql:host=localhost;port=3306;dbname=mi_core;charset=utf8mb4";
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, 'v_admin', 'Arbitr09102022!', $options);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }
}

function getRecommendations($pdo) {
    try {
        // Получаем статистику по товарам
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN total_stock <= 5 THEN 1 ELSE 0 END) as critical_count,
                SUM(CASE WHEN total_stock > 5 AND total_stock <= 20 THEN 1 ELSE 0 END) as low_count,
                SUM(CASE WHEN total_stock > 100 THEN 1 ELSE 0 END) as overstock_count,
                SUM(CASE WHEN total_stock = 0 THEN 1 ELSE 0 END) as out_of_stock_count
            FROM (
                SELECT 
                    i.sku,
                    SUM(i.current_stock) as total_stock
                FROM inventory_data i
                WHERE i.current_stock IS NOT NULL
                GROUP BY i.sku
            ) as product_totals
        ");
        
        $stmt->execute();
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$stats) {
            return [];
        }
        
        $recommendations = [];
        
        // Критические рекомендации
        if ($stats['critical_count'] > 0) {
            $recommendations[] = [
                'id' => 'critical_replenishment',
                'type' => 'urgent',
                'priority' => 1,
                'title' => 'Срочное пополнение критических товаров',
                'description' => "У вас {$stats['critical_count']} товаров с критическими остатками (≤5 единиц)" . 
                               ($stats['out_of_stock_count'] > 0 ? ", из них {$stats['out_of_stock_count']} полностью закончились" : ""),
                'message' => "У вас {$stats['critical_count']} товаров с критическими остатками (≤5 единиц). Требуется срочное пополнение.",
                'impact' => 'high',
                'urgency' => $stats['out_of_stock_count'] > 0 ? 'immediate' : 'urgent',
                'affected_products' => (int)$stats['critical_count']
            ];
        }
        
        // Рекомендации по избыткам
        if ($stats['overstock_count'] > 0) {
            $recommendations[] = [
                'id' => 'overstock_optimization',
                'type' => 'optimization',
                'priority' => 2,
                'title' => 'Оптимизация избыточных остатков',
                'description' => "У вас {$stats['overstock_count']} товаров с избытком (>100 единиц). Возможность освободить оборотные средства",
                'message' => "У вас {$stats['overstock_count']} товаров с избытком (>100 единиц). Рекомендуется провести акции.",
                'impact' => 'medium',
                'urgency' => 'planned',
                'affected_products' => (int)$stats['overstock_count']
            ];
        }
        
        // Рекомендации по плановому пополнению
        if ($stats['low_count'] > 0) {
            $recommendations[] = [
                'id' => 'planned_replenishment', 
                'type' => 'planning',
                'priority' => 3,
                'title' => 'Плановое пополнение товаров',
                'description' => "У вас {$stats['low_count']} товаров с низкими остатками (6-20 единиц). Рекомендуется запланировать пополнение",
                'message' => "У вас {$stats['low_count']} товаров с низкими остатками (6-20 единиц). Запланируйте пополнение.",
                'impact' => 'medium',
                'urgency' => 'planned',
                'affected_products' => (int)$stats['low_count']
            ];
        }
        
        return $recommendations;
        
    } catch (Exception $e) {
        error_log("Error in getRecommendations: " . $e->getMessage());
        throw $e;
    }
}

try {
    $pdo = getDatabaseConnection();
    
    $action = $_GET['action'] ?? 'recommendations';
    
    switch ($action) {
        case 'recommendations':
            $recommendations = getRecommendations($pdo);
            echo json_encode([
                'status' => 'success',
                'data' => $recommendations,
                'metadata' => [
                    'generated_at' => date('Y-m-d H:i:s'),
                    'total_recommendations' => count($recommendations)
                ]
            ]);
            break;
            
        default:
            echo json_encode([
                'status' => 'error',
                'message' => 'Неподдерживаемое действие: ' . $action
            ]);
    }
    
} catch (Exception $e) {
    error_log("Replenishment API Error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Внутренняя ошибка сервера',
        'details' => $e->getMessage()
    ]);
}
?>