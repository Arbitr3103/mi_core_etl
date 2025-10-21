<?php
/**
 * Пример REST API контроллера для системы маржинальности
 * 
 * Использование:
 * GET /api/margins/summary?start_date=2025-09-01&end_date=2025-09-19&client_id=1
 * GET /api/margins/daily?start_date=2025-09-01&end_date=2025-09-19
 * GET /api/margins/products/top?limit=20&min_revenue=1000
 * GET /api/margins/products/low?threshold=15&min_revenue=1000
 * GET /api/margins/clients?start_date=2025-09-01&end_date=2025-09-19
 * GET /api/margins/coverage
 */

// Подключаем основной класс
require_once 'MarginAPI.php';

// Настройка заголовков для API
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

class MarginController {
    private $api;
    
    public function __construct() {
        try {
            $this->api = new MarginAPI();
        } catch (Exception $e) {
            $this->sendError(500, 'Ошибка подключения к базе данных: ' . $e->getMessage());
        }
    }
    
    /**
     * Роутинг запросов
     */
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $pathParts = explode('/', trim($path, '/'));
        
        // Убираем префикс api из пути
        if ($pathParts[0] === 'api') {
            array_shift($pathParts);
        }
        if ($pathParts[0] === 'margins') {
            array_shift($pathParts);
        }
        
        try {
            switch ($method) {
                case 'GET':
                    $this->handleGetRequest($pathParts);
                    break;
                default:
                    $this->sendError(405, 'Метод не поддерживается');
            }
        } catch (Exception $e) {
            $this->sendError(500, $e->getMessage());
        }
    }
    
    /**
     * Обработка GET запросов
     */
    private function handleGetRequest($pathParts) {
        $endpoint = $pathParts[0] ?? '';
        
        switch ($endpoint) {
            case 'summary':
                $this->getSummary();
                break;
                
            case 'daily':
                $this->getDailyMargins();
                break;
                
            case 'products':
                $subEndpoint = $pathParts[1] ?? '';
                if ($subEndpoint === 'top') {
                    $this->getTopProducts();
                } elseif ($subEndpoint === 'low') {
                    $this->getLowMarginProducts();
                } else {
                    $this->sendError(404, 'Эндпоинт не найден');
                }
                break;
                
            case 'clients':
                $this->getMarginsByClient();
                break;
                
            case 'coverage':
                $this->getCoverageStats();
                break;
                
            default:
                $this->sendError(404, 'Эндпоинт не найден');
        }
    }
    
    /**
     * GET /api/margins/summary
     */
    private function getSummary() {
        $startDate = $this->getParam('start_date', date('Y-m-01'));
        $endDate = $this->getParam('end_date', date('Y-m-d'));
        $clientId = $this->getParam('client_id', null, 'int');
        
        $result = $this->api->getSummaryMargin($startDate, $endDate, $clientId);
        $this->sendSuccess($result);
    }
    
    /**
     * GET /api/margins/daily
     */
    private function getDailyMargins() {
        $startDate = $this->getParam('start_date', date('Y-m-01'));
        $endDate = $this->getParam('end_date', date('Y-m-d'));
        $clientId = $this->getParam('client_id', null, 'int');
        
        $result = $this->api->getDailyMargins($startDate, $endDate, $clientId);
        $this->sendSuccess([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'data' => $result
        ]);
    }
    
    /**
     * GET /api/margins/products/top
     */
    private function getTopProducts() {
        $limit = $this->getParam('limit', 20, 'int');
        $startDate = $this->getParam('start_date', null);
        $endDate = $this->getParam('end_date', null);
        $minRevenue = $this->getParam('min_revenue', 0, 'float');
        
        $result = $this->api->getTopProductsByMargin($limit, $startDate, $endDate, $minRevenue);
        $this->sendSuccess([
            'filters' => [
                'limit' => $limit,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'min_revenue' => $minRevenue
            ],
            'data' => $result
        ]);
    }
    
    /**
     * GET /api/margins/products/low
     */
    private function getLowMarginProducts() {
        $threshold = $this->getParam('threshold', 15.0, 'float');
        $minRevenue = $this->getParam('min_revenue', 1000.0, 'float');
        $limit = $this->getParam('limit', 20, 'int');
        
        $result = $this->api->getLowMarginProducts($threshold, $minRevenue, $limit);
        $this->sendSuccess([
            'filters' => [
                'margin_threshold' => $threshold,
                'min_revenue' => $minRevenue,
                'limit' => $limit
            ],
            'data' => $result
        ]);
    }
    
    /**
     * GET /api/margins/clients
     */
    private function getMarginsByClient() {
        $startDate = $this->getParam('start_date', date('Y-m-01'));
        $endDate = $this->getParam('end_date', date('Y-m-d'));
        
        $result = $this->api->getMarginsByClient($startDate, $endDate);
        $this->sendSuccess([
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ],
            'data' => $result
        ]);
    }
    
    /**
     * GET /api/margins/coverage
     */
    private function getCoverageStats() {
        $result = $this->api->getCostCoverageStats();
        $this->sendSuccess($result);
    }
    
    /**
     * Получить параметр из запроса
     */
    private function getParam($name, $default = null, $type = 'string') {
        $value = $_GET[$name] ?? $default;
        
        if ($value === null) {
            return null;
        }
        
        switch ($type) {
            case 'int':
                return (int)$value;
            case 'float':
                return (float)$value;
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            default:
                return $value;
        }
    }
    
    /**
     * Отправить успешный ответ
     */
    private function sendSuccess($data) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    
    /**
     * Отправить ошибку
     */
    private function sendError($code, $message) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message
            ],
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
}

// Запуск контроллера
$controller = new MarginController();
$controller->handleRequest();
?>
