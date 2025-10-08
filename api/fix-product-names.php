<?php
/**
 * API endpoint для исправления товаров без названий
 */

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    exit(0);
}

require_once __DIR__ . "/../config.php";

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    // Находим товары без названий
    $problemProducts = $pdo->query("
        SELECT id, sku_ozon as sku, product_name, name, brand, category 
        FROM product_master 
        WHERE (product_name IS NULL OR product_name = '' OR product_name LIKE 'Товар артикул%')
        AND (name IS NULL OR name = '' OR name LIKE 'Товар артикул%')
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $fixed = 0;
    $errors = [];
    
    foreach ($problemProducts as $product) {
        try {
            // Генерируем название на основе доступных данных
            $newName = "";
            
            if (!empty($product["brand"])) {
                $newName .= $product["brand"] . " ";
            }
            
            if (!empty($product["category"])) {
                $newName .= $product["category"] . " ";
            }
            
            $newName .= "артикул " . $product["sku"];
            
            // Обновляем товар
            $stmt = $pdo->prepare("UPDATE product_master SET product_name = ?, name = ? WHERE id = ?");
            if ($stmt->execute([$newName, $newName, $product["id"]])) {
                $fixed++;
            }
            
        } catch (Exception $e) {
            $errors[] = "Ошибка для товара ID " . $product["id"] . ": " . $e->getMessage();
        }
    }
    
    echo json_encode([
        "status" => "success",
        "total_found" => count($problemProducts),
        "fixed" => $fixed,
        "errors" => $errors,
        "message" => "Исправлено $fixed товаров из " . count($problemProducts) . " найденных"
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage(),
        "fixed" => 0,
        "errors" => [$e->getMessage()]
    ], JSON_UNESCAPED_UNICODE);
}
?>