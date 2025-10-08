<?php
/**
 * API endpoint для исправления товаров без названий
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
    
    // Находим товары без названий
    $problemProducts = $pdo->query("
        SELECT id, sku_ozon as sku, product_name, name, brand, category 
        FROM dim_products 
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
            $stmt = $pdo->prepare("UPDATE dim_products SET product_name = ?, name = ? WHERE id = ?");
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