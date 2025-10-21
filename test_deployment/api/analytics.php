<?php
/**
 * API endpoint для маркетинговой аналитики
 */

// Настройка обработки ошибок
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Обработка OPTIONS запроса
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Пытаемся подключить конфигурацию
$config_loaded = false;
$config_paths = [
    __DIR__ . "/../config.php",
    __DIR__ . "/../../config.php",
    dirname(__DIR__) . "/config.php"
];

foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        require_once $config_path;
        $config_loaded = true;
        break;
    }
}

if (!$config_loaded) {
    // Fallback конфигурация
    define('DB_HOST', 'localhost');
    define('DB_USER', 'root');
    define('DB_PASSWORD', '');
    define('DB_NAME', 'mi_core');
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Получаем аналитику по товарам с использованием product_cross_reference
    $analytics = [
        "total_products" => 0,
        "products_with_names" => 0,
        "products_with_real_names" => 0,
        "products_with_brands" => 0,
        "products_with_categories" => 0,
        "critical_stock_items" => 0,
        "sync_status" => [],
        "data_quality_score" => 0,
        "top_brands" => [],
        "top_categories" => []
    ];
    
    // Check if product_cross_reference table exists
    $tableExists = $pdo->query("SHOW TABLES LIKE 'product_cross_reference'")->rowCount() > 0;
    
    if ($tableExists) {
        // Use cross-reference table for better data quality
        $analytics["total_products"] = $pdo->query("
            SELECT COUNT(DISTINCT pcr.inventory_product_id) 
            FROM product_cross_reference pcr
        ")->fetchColumn();
        
        // Products with any names (including placeholders)
        $analytics["products_with_names"] = $pdo->query("
            SELECT COUNT(DISTINCT pcr.inventory_product_id)
            FROM product_cross_reference pcr
            WHERE pcr.cached_name IS NOT NULL AND pcr.cached_name != ''
        ")->fetchColumn();
        
        // Products with real names (not placeholders) - NEW METRIC
        $analytics["products_with_real_names"] = $pdo->query("
            SELECT COUNT(DISTINCT pcr.inventory_product_id)
            FROM product_cross_reference pcr
            WHERE pcr.cached_name IS NOT NULL 
              AND pcr.cached_name != ''
              AND pcr.cached_name NOT LIKE 'Товар%ID%'
              AND pcr.cached_name NOT LIKE 'Product ID%'
              AND pcr.sync_status = 'synced'
        ")->fetchColumn();
        
        // Products with brands from cache
        $analytics["products_with_brands"] = $pdo->query("
            SELECT COUNT(DISTINCT pcr.inventory_product_id)
            FROM product_cross_reference pcr
            WHERE pcr.cached_brand IS NOT NULL AND pcr.cached_brand != ''
        ")->fetchColumn();
        
        // Sync status breakdown - NEW METRIC
        $syncStatusQuery = $pdo->query("
            SELECT 
                sync_status,
                COUNT(*) as count
            FROM product_cross_reference
            GROUP BY sync_status
        ");
        
        while ($row = $syncStatusQuery->fetch()) {
            $analytics["sync_status"][$row['sync_status']] = (int)$row['count'];
        }
        
        // Top brands from cross-reference cache
        $topBrands = $pdo->query("
            SELECT 
                pcr.cached_brand as brand, 
                COUNT(*) as count 
            FROM product_cross_reference pcr
            WHERE pcr.cached_brand IS NOT NULL AND pcr.cached_brand != ''
            GROUP BY pcr.cached_brand 
            ORDER BY count DESC 
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        $analytics["top_brands"] = $topBrands;
        
    } else {
        // Fallback to dim_products if cross-reference doesn't exist
        $analytics["total_products"] = $pdo->query("SELECT COUNT(*) FROM dim_products")->fetchColumn();
        
        $analytics["products_with_names"] = $pdo->query("
            SELECT COUNT(*) FROM dim_products 
            WHERE (product_name IS NOT NULL AND product_name != '' AND product_name NOT LIKE 'Товар артикул%')
            OR (name IS NOT NULL AND name != '' AND name NOT LIKE 'Товар артикул%')
        ")->fetchColumn();
        
        $analytics["products_with_real_names"] = $analytics["products_with_names"];
        
        $analytics["products_with_brands"] = $pdo->query("
            SELECT COUNT(*) FROM dim_products 
            WHERE brand IS NOT NULL AND brand != ''
        ")->fetchColumn();
        
        $topBrands = $pdo->query("
            SELECT brand, COUNT(*) as count 
            FROM dim_products 
            WHERE brand IS NOT NULL AND brand != ''
            GROUP BY brand 
            ORDER BY count DESC 
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        $analytics["top_brands"] = $topBrands;
    }
    
    // Categories from dim_products (unchanged)
    $analytics["products_with_categories"] = $pdo->query("
        SELECT COUNT(*) FROM dim_products 
        WHERE category IS NOT NULL AND category != ''
    ")->fetchColumn();
    
    $topCategories = $pdo->query("
        SELECT category, COUNT(*) as count 
        FROM dim_products 
        WHERE category IS NOT NULL AND category != ''
        GROUP BY category 
        ORDER BY count DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    $analytics["top_categories"] = $topCategories;
    
    // Critical stock items from inventory
    $criticalStockQuery = $pdo->query("
        SELECT COUNT(DISTINCT product_id) as count
        FROM inventory_data
        WHERE quantity_present > 0 AND quantity_present < 5
    ");
    $analytics["critical_stock_items"] = (int)$criticalStockQuery->fetchColumn();
    
    // Enhanced data quality score calculation
    if ($analytics["total_products"] > 0) {
        $qualityScore = (
            ($analytics["products_with_real_names"] / $analytics["total_products"]) * 0.5 +
            ($analytics["products_with_brands"] / $analytics["total_products"]) * 0.3 +
            ($analytics["products_with_categories"] / $analytics["total_products"]) * 0.2
        ) * 100;
        $analytics["data_quality_score"] = round($qualityScore, 1);
    }
    
    echo json_encode($analytics, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "error" => $e->getMessage(),
        "total_products" => 0,
        "data_quality_score" => 0
    ], JSON_UNESCAPED_UNICODE);
}
?>