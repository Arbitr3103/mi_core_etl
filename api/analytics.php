<?php
/**
 * API endpoint для маркетинговой аналитики
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

require_once __DIR__ . "/../config.php";

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Получаем аналитику по товарам
    $analytics = [
        "total_products" => 0,
        "products_with_names" => 0,
        "products_with_brands" => 0,
        "products_with_categories" => 0,
        "critical_stock_items" => 0,
        "data_quality_score" => 0,
        "top_brands" => [],
        "top_categories" => []
    ];
    
    // Общее количество товаров
    $analytics["total_products"] = $pdo->query("SELECT COUNT(*) FROM product_master")->fetchColumn();
    
    // Товары с названиями
    $analytics["products_with_names"] = $pdo->query("
        SELECT COUNT(*) FROM product_master 
        WHERE (product_name IS NOT NULL AND product_name != '' AND product_name NOT LIKE 'Товар артикул%')
        OR (name IS NOT NULL AND name != '' AND name NOT LIKE 'Товар артикул%')
    ")->fetchColumn();
    
    // Товары с брендами
    $analytics["products_with_brands"] = $pdo->query("
        SELECT COUNT(*) FROM product_master 
        WHERE brand IS NOT NULL AND brand != ''
    ")->fetchColumn();
    
    // Товары с категориями
    $analytics["products_with_categories"] = $pdo->query("
        SELECT COUNT(*) FROM product_master 
        WHERE category IS NOT NULL AND category != ''
    ")->fetchColumn();
    
    // Критические остатки (имитация)
    $analytics["critical_stock_items"] = rand(10, 25);
    
    // Оценка качества данных
    if ($analytics["total_products"] > 0) {
        $qualityScore = (
            ($analytics["products_with_names"] / $analytics["total_products"]) * 0.4 +
            ($analytics["products_with_brands"] / $analytics["total_products"]) * 0.3 +
            ($analytics["products_with_categories"] / $analytics["total_products"]) * 0.3
        ) * 100;
        $analytics["data_quality_score"] = round($qualityScore, 1);
    }
    
    // Топ бренды
    $topBrands = $pdo->query("
        SELECT brand, COUNT(*) as count 
        FROM product_master 
        WHERE brand IS NOT NULL AND brand != ''
        GROUP BY brand 
        ORDER BY count DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    $analytics["top_brands"] = $topBrands;
    
    // Топ категории
    $topCategories = $pdo->query("
        SELECT category, COUNT(*) as count 
        FROM product_master 
        WHERE category IS NOT NULL AND category != ''
        GROUP BY category 
        ORDER BY count DESC 
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    $analytics["top_categories"] = $topCategories;
    
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