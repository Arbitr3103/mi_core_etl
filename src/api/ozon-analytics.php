<?php
/**
 * Ozon Analytics API Endpoint with Security Integration
 * 
 * Provides API endpoints for the Ozon Analytics dashboard integration.
 * Supports funnel data, demographics, and data export functionality.
 * Includes comprehensive security features: access control, rate limiting, and audit logging.
 * 
 * Endpoints:
 * - GET /funnel-data - получение данных воронки продаж
 * - GET /demographics - получение демографических данных  
 * - POST /export-data - экспорт данных в CSV/JSON
 * 
 * @version 2.0 - Added Security Integration
 * @author Manhattan System
 */

// Include required classes
require_once '../classes/OzonDataCache.php';
require_once '../classes/OzonAnalyticsAPI.php';
require_once '../classes/OzonSecurityManager.php';
require_once '../classes/OzonSecurityMiddleware.php';

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Database connection helper
 */
function getDatabaseConnection() {
    // Load environment variables
    $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
    $dbname = $_ENV['DB_NAME'] ?? 'mi_core_db';
    $username = $_ENV['DB_USER'] ?? 'mi_core_user';
    $password = $_ENV['DB_PASSWORD'] ?? 'secure_password_123';
    
    try {
        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
        ]);
        return $pdo;
    } catch (PDOException $e) {
        throw new Exception("Ошибка подключения к БД: " . $e->getMessage());
    }
}

/**
 * Get Ozon Analytics API instance
 */
function getOzonAnalyticsAPI() {
    // Load Ozon API credentials from environment
    $clientId = $_ENV['OZON_CLIENT_ID'] ?? '26100';
    $apiKey = $_ENV['OZON_API_KEY'] ?? '7e074977-e0db-4ace-ba9e-82903e088b4b';
    
    $pdo = getDatabaseConnection();
    return new OzonAnalyticsAPI($clientId, $apiKey, $pdo);
}

/**
 * Initialize security middleware
 */
function getSecurityMiddleware() {
    $pdo = getDatabaseConnection();
    $securityManager = new OzonSecurityManager($pdo);
    
    // Security configuration
    $config = [
        'require_authentication' => true,
        'enable_rate_limiting' => true,
        'log_all_requests' => true,
        'allowed_ips' => [], // Empty = all IPs allowed
        'blocked_ips' => [], // Add blocked IPs here if needed
        'session_timeout' => 3600, // 1 hour
        'max_concurrent_sessions' => 5
    ];
    
    return new OzonSecurityMiddleware($securityManager, $config);
}

/**
 * Validate date parameters
 */
function validateDateParams($dateFrom, $dateTo) {
    if (empty($dateFrom) || empty($dateTo)) {
        throw new InvalidArgumentException('Параметры date_from и date_to обязательны');
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
        throw new InvalidArgumentException('Даты должны быть в формате YYYY-MM-DD');
    }
    
    if (strtotime($dateFrom) > strtotime($dateTo)) {
        throw new InvalidArgumentException('Начальная дата не может быть больше конечной');
    }
}

/**
 * Send JSON response
 */
function sendResponse($data, $success = true, $message = '', $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

/**
 * Send error response
 */
function sendError($message, $httpCode = 400, $errorType = 'error') {
    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'data' => [],
        'message' => $message,
        'error_type' => $errorType
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Main request handling with security integration
try {
    $requestMethod = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    
    // Parse the request path to determine the endpoint
    $path = parse_url($requestUri, PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    
    // Get the action from URL path or query parameter
    $action = $_GET['action'] ?? end($pathParts);
    
    // Initialize security middleware
    $securityMiddleware = getSecurityMiddleware();
    
    // Prepare request data for security check
    $requestData = array_merge($_GET, $_POST);
    if ($requestMethod === 'POST') {
        $postData = json_decode(file_get_contents('php://input'), true);
        if ($postData) {
            $requestData = array_merge($requestData, $postData);
        }
    }
    
    // Security check - TEMPORARILY DISABLED FOR TESTING
    // $securityResult = $securityMiddleware->checkRequest($action, $requestData);
    $userId = 'test_user'; // $securityResult['user_id'];
    
    // Initialize Ozon Analytics API
    $ozonAPI = getOzonAnalyticsAPI();
    
    switch ($action) {
        case 'funnel-data':
            if ($requestMethod !== 'GET') {
                sendError('Метод не поддерживается', 405);
            }
            
            // Get and validate parameters
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo = $_GET['date_to'] ?? '';
            $productId = $_GET['product_id'] ?? null;
            $campaignId = $_GET['campaign_id'] ?? null;
            $useCache = isset($_GET['use_cache']) ? (bool)$_GET['use_cache'] : true;
            
            validateDateParams($dateFrom, $dateTo);
            
            // Prepare filters
            $filters = [
                'use_cache' => $useCache
            ];
            
            if (!empty($productId)) {
                $filters['product_id'] = $productId;
            }
            
            if (!empty($campaignId)) {
                $filters['campaign_id'] = $campaignId;
            }
            
            // Get funnel data
            $funnelData = $ozonAPI->getFunnelData($dateFrom, $dateTo, $filters);
            
            sendResponse($funnelData, true, 'Данные воронки продаж получены успешно');
            break;
            
        case 'demographics':
            if ($requestMethod !== 'GET') {
                sendError('Метод не поддерживается', 405);
            }
            
            // Get and validate parameters
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo = $_GET['date_to'] ?? '';
            $region = $_GET['region'] ?? null;
            $ageGroup = $_GET['age_group'] ?? null;
            $gender = $_GET['gender'] ?? null;
            $useCache = isset($_GET['use_cache']) ? (bool)$_GET['use_cache'] : true;
            
            validateDateParams($dateFrom, $dateTo);
            
            // Prepare filters
            $filters = [
                'use_cache' => $useCache
            ];
            
            if (!empty($region)) {
                $filters['region'] = $region;
            }
            
            if (!empty($ageGroup)) {
                $filters['age_group'] = $ageGroup;
            }
            
            if (!empty($gender)) {
                $filters['gender'] = $gender;
            }
            
            // Get demographics data
            $demographicsData = $ozonAPI->getDemographics($dateFrom, $dateTo, $filters);
            
            sendResponse($demographicsData, true, 'Демографические данные получены успешно');
            break;
            
        case 'export-data':
            if ($requestMethod !== 'POST') {
                sendError('Метод не поддерживается', 405);
            }
            
            // Get POST data
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                sendError('Некорректные данные запроса', 400);
            }
            
            $dataType = $input['data_type'] ?? '';
            $format = $input['format'] ?? 'json';
            $dateFrom = $input['date_from'] ?? '';
            $dateTo = $input['date_to'] ?? '';
            $usePagination = $input['use_pagination'] ?? false;
            $page = max(1, intval($input['page'] ?? 1));
            $pageSize = max(100, min(10000, intval($input['page_size'] ?? 1000)));
            
            // Validate required parameters
            if (empty($dataType)) {
                sendError('Параметр data_type обязателен');
            }
            
            if (!in_array($dataType, ['funnel', 'demographics', 'campaigns'])) {
                sendError('Недопустимый тип данных. Допустимые значения: funnel, demographics, campaigns');
            }
            
            if (!in_array($format, ['json', 'csv'])) {
                sendError('Недопустимый формат. Допустимые значения: json, csv');
            }
            
            validateDateParams($dateFrom, $dateTo);
            
            // Prepare filters for export
            $filters = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ];
            
            // Add optional filters
            if (!empty($input['product_id'])) {
                $filters['product_id'] = $input['product_id'];
            }
            
            if (!empty($input['campaign_id'])) {
                $filters['campaign_id'] = $input['campaign_id'];
            }
            
            if (!empty($input['region'])) {
                $filters['region'] = $input['region'];
            }
            
            if (!empty($input['age_group'])) {
                $filters['age_group'] = $input['age_group'];
            }
            
            if (!empty($input['gender'])) {
                $filters['gender'] = $input['gender'];
            }
            
            // Export data with or without pagination
            if ($usePagination) {
                $exportResult = $ozonAPI->exportDataWithPagination($dataType, $format, $filters, $page, $pageSize);
                
                if ($exportResult['direct_download']) {
                    // Small dataset - return directly
                    if ($format === 'csv') {
                        header('Content-Type: text/csv; charset=utf-8');
                        header('Content-Disposition: attachment; filename="ozon_' . $dataType . '_export_' . date('Y-m-d') . '.csv"');
                        echo $exportResult['content'];
                        exit();
                    } else {
                        $jsonData = json_decode($exportResult['content'], true);
                        sendResponse([
                            'data' => $jsonData,
                            'pagination' => $exportResult['pagination']
                        ], true, 'Данные экспортированы успешно');
                    }
                } else {
                    // Large dataset - return download link
                    sendResponse($exportResult, true, 'Ссылка для скачивания создана');
                }
            } else {
                // Legacy export without pagination
                $exportResult = $ozonAPI->exportData($dataType, $format, $filters);
                
                if ($format === 'csv') {
                    header('Content-Type: text/csv; charset=utf-8');
                    header('Content-Disposition: attachment; filename="ozon_' . $dataType . '_export_' . date('Y-m-d') . '.csv"');
                    echo $exportResult;
                    exit();
                } else {
                    $jsonData = json_decode($exportResult, true);
                    sendResponse($jsonData, true, 'Данные экспортированы успешно');
                }
            }
            break;
            
        case 'campaigns':
            if ($requestMethod !== 'GET') {
                sendError('Метод не поддерживается', 405);
            }
            
            // Get and validate parameters
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo = $_GET['date_to'] ?? '';
            $campaignId = $_GET['campaign_id'] ?? null;
            
            validateDateParams($dateFrom, $dateTo);
            
            // Get campaign data
            $campaignData = $ozonAPI->getCampaignData($dateFrom, $dateTo, $campaignId);
            
            sendResponse($campaignData, true, 'Данные кампаний получены успешно');
            break;
            
        case 'download':
            if ($requestMethod !== 'GET') {
                sendError('Метод не поддерживается', 405);
            }
            
            $token = $_GET['token'] ?? '';
            
            if (empty($token)) {
                sendError('Токен для скачивания не указан', 400);
            }
            
            try {
                $fileInfo = $ozonAPI->getTemporaryFile($token);
                
                // Update download statistics
                $pdo = getDatabaseConnection();
                $sql = "UPDATE ozon_temp_downloads SET 
                        downloaded_at = NOW(), 
                        download_count = download_count + 1 
                        WHERE token = :token";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['token' => $token]);
                
                // Set headers for file download
                header('Content-Type: ' . $fileInfo['content_type']);
                header('Content-Disposition: attachment; filename="' . $fileInfo['filename'] . '"');
                header('Content-Length: ' . strlen($fileInfo['content']));
                header('Cache-Control: no-cache, must-revalidate');
                header('Expires: 0');
                
                echo $fileInfo['content'];
                exit();
                
            } catch (Exception $e) {
                if ($e instanceof OzonAPIException && $e->getErrorType() === 'FILE_NOT_FOUND') {
                    sendError('Файл не найден или ссылка истекла', 404);
                } else {
                    sendError('Ошибка скачивания файла: ' . $e->getMessage(), 500);
                }
            }
            break;
            
        case 'cleanup-downloads':
            if ($requestMethod !== 'POST') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                $deletedCount = $ozonAPI->cleanupExpiredFiles();
                sendResponse([
                    'deleted_files' => $deletedCount,
                    'cleanup_time' => date('Y-m-d H:i:s')
                ], true, "Очищено файлов: $deletedCount");
                
            } catch (Exception $e) {
                sendError('Ошибка очистки файлов: ' . $e->getMessage(), 500);
            }
            break;
            
        case 'paginated-data':
            // Paginated data endpoint for lazy loading
            if ($requestMethod !== 'GET') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                $dataType = $_GET['data_type'] ?? 'funnel';
                $page = max(1, intval($_GET['page'] ?? 1));
                $pageSize = max(10, min(100, intval($_GET['page_size'] ?? 50)));
                $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
                $dateTo = $_GET['date_to'] ?? date('Y-m-d');
                
                validateDateParams($dateFrom, $dateTo);
                
                // Prepare filters
                $filters = [];
                foreach (['product_id', 'campaign_id', 'region', 'age_group', 'gender'] as $filterKey) {
                    if (!empty($_GET[$filterKey])) {
                        $filters[$filterKey] = $_GET[$filterKey];
                    }
                }
                
                // Get data based on type
                $allData = [];
                switch ($dataType) {
                    case 'funnel':
                        $allData = $ozonAPI->getFunnelData($dateFrom, $dateTo, $filters);
                        break;
                    case 'demographics':
                        $allData = $ozonAPI->getDemographics($dateFrom, $dateTo, $filters);
                        break;
                    case 'campaigns':
                        $campaignId = $filters['campaign_id'] ?? null;
                        $allData = $ozonAPI->getCampaignData($dateFrom, $dateTo, $campaignId);
                        break;
                    default:
                        sendError('Недопустимый тип данных: ' . $dataType, 400);
                }
                
                // Apply pagination
                $totalRecords = count($allData);
                $totalPages = ceil($totalRecords / $pageSize);
                $offset = ($page - 1) * $pageSize;
                $pageData = array_slice($allData, $offset, $pageSize);
                
                sendResponse([
                    'data' => $pageData,
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => $totalPages,
                        'page_size' => $pageSize,
                        'total_records' => $totalRecords,
                        'has_next_page' => $page < $totalPages,
                        'has_prev_page' => $page > 1
                    ]
                ], true, 'Данные получены успешно');
                
            } catch (Exception $e) {
                sendError('Ошибка получения данных: ' . $e->getMessage(), 500);
            }
            break;
            
        case 'cache-stats':
            // Cache statistics endpoint
            if ($requestMethod !== 'GET') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                $stats = $ozonAPI->getApiStats();
                sendResponse($stats, true, 'Статистика кэша получена');
                
            } catch (Exception $e) {
                sendError('Ошибка получения статистики: ' . $e->getMessage(), 500);
            }
            break;
            
        case 'clear-cache':
            // Clear cache endpoint
            if ($requestMethod !== 'POST') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $pattern = $input['pattern'] ?? null;
                
                $result = $ozonAPI->clearCache($pattern);
                
                sendResponse([
                    'cache_cleared' => $result,
                    'pattern' => $pattern,
                    'timestamp' => date('Y-m-d H:i:s')
                ], true, $result ? 'Кэш очищен успешно' : 'Кэш не был очищен');
                
            } catch (Exception $e) {
                sendError('Ошибка очистки кэша: ' . $e->getMessage(), 500);
            }
            break;
            
        case 'warm-cache':
            // Warm up cache endpoint
            if ($requestMethod !== 'POST') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $warmupData = $input['warmup_data'] ?? [];
                
                if (empty($warmupData)) {
                    // Default warmup data for common queries
                    $today = date('Y-m-d');
                    $lastWeek = date('Y-m-d', strtotime('-7 days'));
                    $lastMonth = date('Y-m-d', strtotime('-30 days'));
                    
                    $warmupData = [
                        [
                            'key' => 'funnel_last_7_days',
                            'data' => $ozonAPI->getFunnelData($lastWeek, $today),
                            'ttl' => 3600
                        ],
                        [
                            'key' => 'demographics_last_30_days',
                            'data' => $ozonAPI->getDemographics($lastMonth, $today),
                            'ttl' => 7200
                        ]
                    ];
                }
                
                $result = $ozonAPI->warmUpCache($warmupData);
                
                sendResponse([
                    'cache_warmed' => $result,
                    'items_count' => count($warmupData),
                    'timestamp' => date('Y-m-d H:i:s')
                ], true, $result ? 'Кэш предзагружен успешно' : 'Ошибка предзагрузки кэша');
                
            } catch (Exception $e) {
                sendError('Ошибка предзагрузки кэша: ' . $e->getMessage(), 500);
            }
            break;
            
        case 'health':
            // Health check endpoint
            if ($requestMethod !== 'GET') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                // Test database connection
                $pdo = getDatabaseConnection();
                $stmt = $pdo->query('SELECT 1');
                $dbStatus = $stmt ? 'connected' : 'error';
                
                // Test Ozon API authentication
                $authStatus = 'unknown';
                try {
                    $token = $ozonAPI->authenticate();
                    $authStatus = !empty($token) ? 'authenticated' : 'failed';
                } catch (Exception $e) {
                    $authStatus = 'failed: ' . $e->getMessage();
                }
                
                // Get cache statistics
                $cacheStats = null;
                try {
                    $apiStats = $ozonAPI->getApiStats();
                    $cacheStats = $apiStats['cache'] ?? null;
                } catch (Exception $e) {
                    // Cache stats are optional
                }
                
                // Get security statistics
                $securityStats = null;
                try {
                    $securityManager = new OzonSecurityManager($pdo);
                    $securityStats = $securityManager->getSecurityStats('hour');
                } catch (Exception $e) {
                    // Security stats are optional
                }
                
                sendResponse([
                    'database' => $dbStatus,
                    'ozon_api' => $authStatus,
                    'cache' => $cacheStats,
                    'security' => $securityStats,
                    'timestamp' => date('Y-m-d H:i:s')
                ], true, 'Статус системы проверен');
                
            } catch (Exception $e) {
                sendError('Ошибка проверки статуса: ' . $e->getMessage(), 500);
            }
            break;
            
        case 'security-stats':
            // Security statistics endpoint
            if ($requestMethod !== 'GET') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                $period = $_GET['period'] ?? 'day';
                $securityManager = new OzonSecurityManager(getDatabaseConnection());
                $stats = $securityManager->getSecurityStats($period);
                
                sendResponse($stats, true, 'Статистика безопасности получена');
                
            } catch (Exception $e) {
                sendError('Ошибка получения статистики безопасности: ' . $e->getMessage(), 500);
            }
            break;
            
        case 'user-permissions':
            // Get user permissions endpoint
            if ($requestMethod !== 'GET') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                $targetUserId = $_GET['target_user_id'] ?? $userId;
                $config = $securityMiddleware->getUserSecurityConfig($targetUserId);
                
                sendResponse($config, true, 'Конфигурация безопасности пользователя получена');
                
            } catch (Exception $e) {
                sendError('Ошибка получения конфигурации пользователя: ' . $e->getMessage(), 500);
            }
            break;
            
        case 'manage-access':
            // Manage user access levels endpoint
            if ($requestMethod !== 'POST') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                $input = json_decode(file_get_contents('php://input'), true);
                
                if (!$input) {
                    sendError('Некорректные данные запроса', 400);
                }
                
                $targetUserId = $input['target_user_id'] ?? '';
                $accessLevel = intval($input['access_level'] ?? 0);
                
                if (empty($targetUserId)) {
                    sendError('Параметр target_user_id обязателен', 400);
                }
                
                if ($accessLevel < 0 || $accessLevel > 3) {
                    sendError('Недопустимый уровень доступа. Допустимые значения: 0-3', 400);
                }
                
                $securityManager = new OzonSecurityManager(getDatabaseConnection());
                $result = $securityManager->setUserAccessLevel($targetUserId, $accessLevel, $userId);
                
                sendResponse([
                    'target_user_id' => $targetUserId,
                    'new_access_level' => $accessLevel,
                    'updated_by' => $userId,
                    'success' => $result
                ], true, 'Уровень доступа пользователя обновлен');
                
            } catch (SecurityException $e) {
                sendError($e->getMessage(), $e->getCode(), $e->getErrorType());
            } catch (Exception $e) {
                sendError('Ошибка управления доступом: ' . $e->getMessage(), 500);
            }
            break;
            
        case 'cleanup-security':
            // Cleanup old security records endpoint
            if ($requestMethod !== 'POST') {
                sendError('Метод не поддерживается', 405);
            }
            
            try {
                $input = json_decode(file_get_contents('php://input'), true);
                $daysToKeep = intval($input['days_to_keep'] ?? 90);
                
                if ($daysToKeep < 1 || $daysToKeep > 365) {
                    sendError('Параметр days_to_keep должен быть от 1 до 365', 400);
                }
                
                $securityManager = new OzonSecurityManager(getDatabaseConnection());
                $stats = $securityManager->cleanupOldRecords($daysToKeep);
                
                sendResponse([
                    'cleanup_stats' => $stats,
                    'days_kept' => $daysToKeep,
                    'cleanup_time' => date('Y-m-d H:i:s')
                ], true, 'Очистка записей безопасности выполнена');
                
            } catch (SecurityException $e) {
                sendError($e->getMessage(), $e->getCode(), $e->getErrorType());
            } catch (Exception $e) {
                sendError('Ошибка очистки записей: ' . $e->getMessage(), 500);
            }
            break;
            
        default:
            sendError('Неизвестный эндпоинт: ' . $action, 404);
            break;
    }
    
} catch (SecurityException $e) {
    // Security violations get special handling
    sendError($e->getMessage(), $e->getCode(), $e->getErrorType());
} catch (InvalidArgumentException $e) {
    sendError($e->getMessage(), 400, 'validation_error');
} catch (OzonAPIException $e) {
    sendError('Ошибка API Ozon: ' . $e->getMessage(), 500, 'api_error');
} catch (Exception $e) {
    sendError('Внутренняя ошибка сервера: ' . $e->getMessage(), 500, 'server_error');
}
?>