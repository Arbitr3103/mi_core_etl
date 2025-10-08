<?php
/**
 * Простой API для управления остатками товаров с мастер таблицей
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Подключение к БД
function loadEnvConfig() {
    $envFile = __DIR__ . '/../.env';
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                list($key, $value) = explode('=', $line, 2);
                $value = trim($value, '"\'');
                $_ENV[trim($key)] = $value;
            }
        }
    }
}

loadEnvConfig();

try {
    $pdo = new PDO(
        "mysql:host=" . ($_ENV['DB_HOST'] ?? 'localhost') . ";dbname=mi_core;charset=utf8mb4",
        $_ENV['DB_USER'] ?? 'v_admin',
        $_ENV['DB_PASSWORD'] ?? '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$action = $_GET['action'] ?? 'status';

switch ($action) {
    case 'test':
        // Тест API
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_data WHERE current_stock > 0");
        $inventory_count = $stmt->fetch()['count'];
        
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM product_master");
        $master_count = $stmt->fetch()['count'];
        
        echo json_encode([
            'success' => true,
            'data' => [
                'api_working' => true,
                'inventory_records' => (int)$inventory_count,
                'master_products' => (int)$master_count,
                'test_time' => date('Y-m-d H:i:s')
            ]
        ]);
        break;
        
    case 'products':
        // Получение товаров с названиями
        $limit = min((int)($_GET['limit'] ?? 20), 100);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $stmt = $pdo->prepare("
            SELECT 
                i.sku,
                i.current_stock,
                i.warehouse_name,
                i.source,
                pm.product_name,
                pm.brand,
                pm.category,
                COALESCE(
                    pm.product_name,
                    CASE 
                        WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                        ELSE i.sku
                    END
                ) as display_name,
                CASE 
                    WHEN pm.product_name IS NOT NULL THEN 'Мастер таблица'
                    ELSE 'Fallback'
                END as name_source
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
            WHERE i.current_stock > 0
            ORDER BY i.current_stock DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$limit, $offset]);
        $products = $stmt->fetchAll();
        
        // Группируем по SKU
        $grouped = [];
        foreach ($products as $product) {
            $sku = $product['sku'];
            if (!isset($grouped[$sku])) {
                $grouped[$sku] = [
                    'sku' => $sku,
                    'display_name' => $product['display_name'],
                    'brand' => $product['brand'],
                    'category' => $product['category'],
                    'name_source' => $product['name_source'],
                    'total_stock' => 0,
                    'warehouses' => []
                ];
            }
            
            $grouped[$sku]['total_stock'] += (int)$product['current_stock'];
            $grouped[$sku]['warehouses'][] = [
                'warehouse_name' => $product['warehouse_name'],
                'current_stock' => (int)$product['current_stock'],
                'source' => $product['source']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'products' => array_values($grouped),
                'total_count' => count($grouped),
                'master_table_used' => true
            ]
        ]);
        break;
        
    case 'critical':
        // Критические остатки
        $threshold = (int)($_GET['threshold'] ?? 5);
        
        $stmt = $pdo->prepare("
            SELECT 
                i.sku,
                i.current_stock,
                i.warehouse_name,
                pm.product_name,
                pm.brand,
                COALESCE(
                    pm.product_name,
                    CASE 
                        WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                        ELSE i.sku
                    END
                ) as display_name
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
            WHERE i.current_stock > 0 AND i.current_stock < ?
            ORDER BY i.current_stock ASC
        ");
        
        $stmt->execute([$threshold]);
        $critical_items = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'critical_items' => $critical_items,
                'threshold' => $threshold,
                'count' => count($critical_items)
            ]
        ]);
        break;
        
    case 'marketing':
        // Маркетинговая аналитика
        $stmt = $pdo->query("
            SELECT 
                pm.brand,
                COUNT(DISTINCT i.sku) as products_count,
                SUM(i.current_stock) as total_stock
            FROM inventory_data i
            JOIN product_master pm ON i.sku = pm.sku_ozon
            WHERE i.current_stock > 0
            AND pm.brand IS NOT NULL
            GROUP BY pm.brand
            ORDER BY total_stock DESC
            LIMIT 10
        ");
        
        $brand_analysis = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'brand_analysis' => $brand_analysis,
                'analysis_time' => date('Y-m-d H:i:s')
            ]
        ]);
        break;
        
    case 'stats':
        // Статистика
        $stmt = $pdo->query("
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN i.current_stock > 0 THEN 1 END) as products_in_stock,
                SUM(i.current_stock) as total_stock,
                COUNT(pm.product_name) as products_with_names,
                COUNT(DISTINCT pm.brand) as unique_brands
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
        ");
        
        $overview = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'overview' => $overview,
                'master_table_coverage' => [
                    'total_products' => (int)$overview['total_products'],
                    'with_master_names' => (int)$overview['products_with_names'],
                    'coverage_percent' => $overview['total_products'] > 0 ? 
                        round(($overview['products_with_names'] / $overview['total_products']) * 100, 1) : 0
                ]
            ]
        ]);
        break;
        
    default:
        // Статус по умолчанию
        $stmt = $pdo->query("SELECT * FROM sync_logs ORDER BY started_at DESC LIMIT 1");
        $last_sync = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'last_sync' => $last_sync,
                'master_table_integration' => true
            ]
        ]);
        break;
}
?>