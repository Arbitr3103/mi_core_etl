<?php
// CORS
$allowedOrigins = getenv('ALLOWED_ORIGINS') ?: '*'; // Например: https://site.ru,https://admin.site.ru
if ($allowedOrigins === '*') {
    header('Access-Control-Allow-Origin: *');
} else {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $origins = array_map('trim', explode(',', $allowedOrigins));
    if ($origin && in_array($origin, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/RecommendationsAPI.php';

// Формат ответа по умолчанию — JSON (кроме экспорта)
$isExport = isset($_GET['action']) && $_GET['action'] === 'export';
if (!$isExport) {
    header('Content-Type: application/json; charset=utf-8');
}

// Конфигурация подключения к БД (рекомендуется через ENV)
$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_NAME = getenv('DB_NAME') ?: 'mi_core_db';
$DB_USER = getenv('DB_USER') ?: 'username';
$DB_PASS = getenv('DB_PASS') ?: 'password';

try {
    $api = new RecommendationsAPI($DB_HOST, $DB_NAME, $DB_USER, $DB_PASS);

    $action = $_GET['action'] ?? 'summary';
    $marketplace = !empty($_GET['marketplace']) ? trim($_GET['marketplace']) : null;

    switch ($action) {
        case 'summary':
            $data = $api->getSummary($marketplace);
            echo json_encode([
                'success' => true, 
                'data' => $data,
                'meta' => [
                    'marketplace' => $marketplace,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;

        case 'list':
            $status = $_GET['status'] ?? null; // urgent | normal | low_priority
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $search = $_GET['search'] ?? null;

            $rows = $api->getRecommendations($status, $limit, $offset, $search, $marketplace);
            echo json_encode([
                'success' => true,
                'data' => $rows,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($rows)
                ],
                'meta' => [
                    'marketplace' => $marketplace,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;

        case 'export':
            $status = $_GET['status'] ?? null;
            $csv = $api->exportCSV($status, $marketplace);
            header('Content-Type: text/csv; charset=utf-8');
            $filename = 'stock_recommendations';
            if ($marketplace) {
                $filename .= '_' . $marketplace;
            }
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
            echo $csv;
            break;

        case 'turnover_top':
            // Топ по оборачиваемости из v_product_turnover_30d
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            $order = $_GET['order'] ?? 'ASC';
            $rows = $api->getTurnoverTop($limit, $order, $marketplace);
            echo json_encode([
                'success' => true, 
                'data' => $rows,
                'meta' => [
                    'marketplace' => $marketplace,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;
            
        case 'separated_view':
            // Get recommendations for both marketplaces in a single response
            $status = $_GET['status'] ?? null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
            $search = $_GET['search'] ?? null;
            
            $data = $api->getRecommendationsByMarketplace($status, $limit, $offset, $search);
            echo json_encode([
                'success' => true,
                'data' => $data,
                'meta' => [
                    'view_mode' => 'separated',
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    if (!$isExport) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        echo 'Error: ' . $e->getMessage();
    }
}
