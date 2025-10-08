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
                CASE
                    WHEN pm.product_name IS NOT NULL THEN pm.product_name
                    WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                    WHEN LENGTH(i.sku) > 50 THEN CONCAT(SUBSTRING(i.sku, 1, 47), '...')
                    ELSE i.sku
                END as display_name,
                CASE 
                    WHEN pm.product_name IS NOT NULL THEN 'Мастер таблица'
                    WHEN i.sku REGEXP '^[0-9]+$' THEN 'Числовой SKU'
                    ELSE 'Текстовое название'
                END as name_source
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
            WHERE i.current_stock > 0
            ORDER BY i.current_stock DESC
            LIMIT $limit OFFSET $offset
        ");
        
        $stmt->execute();
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
        // Улучшенные критические остатки с правильным определением складов
        $threshold = (int)($_GET['threshold'] ?? 5);
        
        $stmt = $pdo->prepare("
            SELECT 
                i.sku,
                i.current_stock,
                i.reserved_stock,
                CASE 
                    WHEN i.warehouse_name IS NOT NULL AND i.warehouse_name != '' THEN i.warehouse_name
                    WHEN i.source = 'Ozon_Analytics' THEN CONCAT('Склад аналитики (', i.stock_type, ')')
                    WHEN i.source = 'Ozon' AND i.stock_type IS NOT NULL THEN CONCAT('Основной склад (', i.stock_type, ')')
                    WHEN i.source = 'Ozon' THEN 'Основной склад Ozon'
                    ELSE CONCAT('Неопределенный склад (', COALESCE(i.source, 'Unknown'), ')')
                END as warehouse_display_name,
                i.source as data_source,
                i.stock_type,
                pm.product_name,
                pm.brand,
                pm.category,
                CASE
                    WHEN pm.product_name IS NOT NULL THEN pm.product_name
                    WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                    WHEN LENGTH(i.sku) > 50 THEN CONCAT(SUBSTRING(i.sku, 1, 47), '...')
                    ELSE i.sku
                END as display_name,
                CASE 
                    WHEN i.current_stock < 2 AND i.reserved_stock > 0 THEN 'Критично - есть заказы'
                    WHEN i.current_stock < 2 THEN 'Критично - нет заказов'
                    WHEN i.current_stock < 5 AND i.reserved_stock > 0 THEN 'Требует пополнения'
                    ELSE 'Низкий остаток'
                END as urgency_level,
                CASE 
                    WHEN i.source = 'Ozon_Analytics' THEN 'Детализированные данные'
                    WHEN i.source = 'Ozon' THEN 'Основные данные API'
                    ELSE 'Неизвестный источник'
                END as source_description
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
            WHERE i.current_stock > 0 AND i.current_stock < ?
            ORDER BY 
                CASE 
                    WHEN i.current_stock < 2 AND i.reserved_stock > 0 THEN 1
                    WHEN i.current_stock < 2 THEN 2
                    WHEN i.current_stock < 5 AND i.reserved_stock > 0 THEN 3
                    ELSE 4
                END,
                i.current_stock ASC
        ");
        
        $stmt->execute([intval($threshold)]);
        $critical_items = $stmt->fetchAll();
        
        // Расширенная статистика критических остатков
        $critical_stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_critical_items,
                COUNT(DISTINCT i.sku) as unique_products,
                COUNT(DISTINCT 
                    CASE 
                        WHEN i.warehouse_name IS NOT NULL AND i.warehouse_name != '' THEN i.warehouse_name
                        ELSE CONCAT(COALESCE(i.source, 'Unknown'), '_', COALESCE(i.stock_type, 'default'))
                    END
                ) as affected_warehouses,
                SUM(i.current_stock) as total_critical_stock,
                SUM(i.reserved_stock) as total_reserved_stock,
                COUNT(CASE WHEN i.current_stock < 2 AND i.reserved_stock > 0 THEN 1 END) as critical_with_orders,
                COUNT(CASE WHEN i.current_stock < 2 AND i.reserved_stock = 0 THEN 1 END) as critical_no_orders,
                COUNT(CASE WHEN i.source = 'Ozon_Analytics' THEN 1 END) as analytics_items,
                COUNT(CASE WHEN i.source = 'Ozon' THEN 1 END) as main_api_items
            FROM inventory_data i
            WHERE i.current_stock > 0 AND i.current_stock < ?
        ");
        $critical_stats_stmt->execute([intval($threshold)]);
        $critical_stats = $critical_stats_stmt->fetch();
        
        // Анализ по складам для критических остатков
        $warehouse_analysis_stmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN i.warehouse_name IS NOT NULL AND i.warehouse_name != '' THEN i.warehouse_name
                    WHEN i.source = 'Ozon_Analytics' THEN CONCAT('Аналитика: ', COALESCE(i.stock_type, 'Неизвестный тип'))
                    WHEN i.source = 'Ozon' THEN CONCAT('Основной: ', COALESCE(i.stock_type, 'Общий склад'))
                    ELSE CONCAT('Неопределенный: ', COALESCE(i.source, 'Unknown'))
                END as warehouse_display_name,
                i.source,
                i.stock_type,
                COUNT(*) as critical_items_count,
                COUNT(DISTINCT i.sku) as unique_products,
                SUM(i.current_stock) as total_stock,
                SUM(i.reserved_stock) as total_reserved,
                AVG(i.current_stock) as avg_stock
            FROM inventory_data i
            WHERE i.current_stock > 0 AND i.current_stock < ?
            GROUP BY 
                i.warehouse_name,
                i.source,
                i.stock_type
            ORDER BY critical_items_count DESC
        ");
        $warehouse_analysis_stmt->execute([intval($threshold)]);
        $warehouse_analysis = $warehouse_analysis_stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'critical_items' => $critical_items,
                'stats' => $critical_stats,
                'warehouse_analysis' => $warehouse_analysis,
                'threshold' => $threshold,
                'count' => count($critical_items),
                'analysis_time' => date('Y-m-d H:i:s')
            ]
        ]);
        break;
        
    case 'marketing':
        // Улучшенная маркетинговая аналитика
        
        // Общая статистика для маркетинга
        $stats_stmt = $pdo->query("
            SELECT 
                COUNT(DISTINCT i.sku) as total_unique_products,
                COUNT(DISTINCT pm.brand) as total_brands,
                COUNT(DISTINCT pm.category) as total_categories,
                SUM(i.current_stock) as total_inventory_stock,
                AVG(i.current_stock) as avg_stock_per_product,
                COUNT(CASE WHEN i.current_stock > 50 THEN 1 END) as well_stocked_products,
                COUNT(CASE WHEN i.current_stock BETWEEN 10 AND 50 THEN 1 END) as medium_stocked_products,
                COUNT(CASE WHEN i.current_stock < 10 AND i.current_stock > 0 THEN 1 END) as low_stocked_products
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
            WHERE i.current_stock > 0
        ");
        $stats = $stats_stmt->fetch();
        
        // Анализ по категориям (более важно для маркетинга чем склады)
        $category_stmt = $pdo->query("
            SELECT 
                COALESCE(pm.category, 'Без категории') as category,
                COUNT(DISTINCT i.sku) as products_count,
                SUM(i.current_stock) as total_stock,
                AVG(i.current_stock) as avg_stock,
                COUNT(CASE WHEN i.current_stock < 10 THEN 1 END) as low_stock_count,
                ROUND((COUNT(CASE WHEN i.current_stock < 10 THEN 1 END) * 100.0 / COUNT(*)), 1) as low_stock_percentage
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
            WHERE i.current_stock > 0
            GROUP BY pm.category
            ORDER BY total_stock DESC
            LIMIT 10
        ");
        $category_analysis = $category_stmt->fetchAll();
        
        // Анализ по брендам (топ бренды)
        $brand_stmt = $pdo->query("
            SELECT 
                COALESCE(pm.brand, 'Без бренда') as brand,
                COUNT(DISTINCT i.sku) as products_count,
                SUM(i.current_stock) as total_stock,
                AVG(i.current_stock) as avg_stock,
                COUNT(CASE WHEN i.current_stock < 10 THEN 1 END) as critical_products
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
            WHERE i.current_stock > 0
            GROUP BY pm.brand
            HAVING products_count >= 2
            ORDER BY total_stock DESC
            LIMIT 8
        ");
        $brand_analysis = $brand_stmt->fetchAll();
        
        // Топ товары с маркетинговой информацией
        $top_products_stmt = $pdo->query("
            SELECT 
                i.sku,
                CASE
                    WHEN pm.product_name IS NOT NULL AND pm.product_name NOT LIKE 'Товар артикул %' THEN pm.product_name
                    WHEN pn.product_name IS NOT NULL THEN pn.product_name
                    WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                    WHEN LENGTH(i.sku) > 50 THEN CONCAT(SUBSTRING(i.sku, 1, 47), '...')
                    ELSE i.sku
                END as product_name,
                COALESCE(pm.brand, 'Без бренда') as brand,
                COALESCE(pm.category, 'Без категории') as category,
                SUM(i.current_stock) as total_stock,
                SUM(i.reserved_stock) as total_reserved,
                COUNT(DISTINCT i.warehouse_name) as warehouses_count,
                CASE 
                    WHEN SUM(i.current_stock) > 100 THEN 'Высокие остатки'
                    WHEN SUM(i.current_stock) > 20 THEN 'Средние остатки'
                    ELSE 'Низкие остатки'
                END as stock_level,
                CASE 
                    WHEN SUM(i.reserved_stock) > 0 THEN 'Есть заказы'
                    ELSE 'Нет заказов'
                END as demand_status
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
            LEFT JOIN product_names pn ON i.sku = pn.sku
            WHERE i.current_stock > 0
            GROUP BY i.sku, pm.product_name, pm.brand, pm.category, pn.product_name
            ORDER BY total_stock DESC
            LIMIT 15
        ");
        $top_products = $top_products_stmt->fetchAll();
        
        // Товары требующие внимания маркетинга
        $attention_products_stmt = $pdo->query("
            SELECT 
                i.sku,
                CASE
                    WHEN pm.product_name IS NOT NULL AND pm.product_name NOT LIKE 'Товар артикул %' THEN pm.product_name
                    WHEN pn.product_name IS NOT NULL THEN pn.product_name
                    WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
                    WHEN LENGTH(i.sku) > 50 THEN CONCAT(SUBSTRING(i.sku, 1, 47), '...')
                    ELSE i.sku
                END as product_name,
                COALESCE(pm.brand, 'Без бренда') as brand,
                COALESCE(pm.category, 'Без категории') as category,
                SUM(i.current_stock) as total_stock,
                SUM(i.reserved_stock) as total_reserved,
                CASE 
                    WHEN SUM(i.current_stock) > 200 AND SUM(i.reserved_stock) = 0 THEN 'Избыток без спроса'
                    WHEN SUM(i.current_stock) < 5 AND SUM(i.reserved_stock) > 0 THEN 'Высокий спрос, мало товара'
                    WHEN SUM(i.current_stock) BETWEEN 5 AND 15 AND SUM(i.reserved_stock) > 0 THEN 'Нужно пополнение'
                    ELSE 'Требует анализа'
                END as marketing_action
            FROM inventory_data i
            LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
            LEFT JOIN product_names pn ON i.sku = pn.sku
            WHERE i.current_stock > 0
            AND (
                (i.current_stock > 200 AND i.reserved_stock = 0) OR
                (i.current_stock < 15 AND i.reserved_stock > 0) OR
                (i.current_stock < 5)
            )
            GROUP BY i.sku, pm.product_name, pm.brand, pm.category, pn.product_name
            ORDER BY 
                CASE 
                    WHEN SUM(i.current_stock) < 5 AND SUM(i.reserved_stock) > 0 THEN 1
                    WHEN SUM(i.current_stock) BETWEEN 5 AND 15 AND SUM(i.reserved_stock) > 0 THEN 2
                    WHEN SUM(i.current_stock) > 200 AND SUM(i.reserved_stock) = 0 THEN 3
                    ELSE 4
                END,
                total_stock DESC
            LIMIT 10
        ");
        $attention_products = $attention_products_stmt->fetchAll();
        
        // Маркетинговые KPI
        $kpi_stats = [
            'inventory_turnover_potential' => round(($stats['total_inventory_stock'] > 0 ? 
                array_sum(array_column($top_products, 'total_reserved')) / $stats['total_inventory_stock'] * 100 : 0), 2),
            'stock_efficiency' => round(($stats['well_stocked_products'] / $stats['total_unique_products'] * 100), 1),
            'critical_attention_needed' => count($attention_products),
            'category_diversity' => $stats['total_categories'],
            'brand_portfolio' => $stats['total_brands']
        ];
        
        echo json_encode([
            'success' => true,
            'data' => [
                'overall_stats' => array_merge($stats, $kpi_stats),
                'top_products' => $top_products,
                'attention_products' => $attention_products,
                'category_analysis' => $category_analysis,
                'brand_analysis' => $brand_analysis,
                'marketing_insights' => [
                    'well_performing_categories' => array_slice($category_analysis, 0, 3),
                    'underperforming_categories' => array_filter($category_analysis, function($cat) {
                        return $cat['low_stock_percentage'] > 50;
                    }),
                    'top_brands' => array_slice($brand_analysis, 0, 5)
                ],
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