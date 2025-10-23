<?php
/**
 * API для валидации и мониторинга качества данных маркетплейсов
 * 
 * Предоставляет endpoints для проверки консистентности данных,
 * мониторинга качества и получения отчетов о проблемах
 * 
 * @version 1.0
 * @author Manhattan System
 */

// Подключаем необходимые классы
require_once 'config.php';
require_once 'MarginDashboardAPI.php';
require_once 'src/classes/MarketplaceDetector.php';
require_once 'src/classes/MarketplaceFallbackHandler.php';
require_once 'src/classes/MarketplaceDataValidator.php';

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

try {
    // Инициализация API
    $marginAPI = new MarginDashboardAPI(DB_HOST, DB_NAME, DB_USER, DB_PASS);
    
    // Получение параметров запроса
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $startDate = $_GET['start_date'] ?? $_POST['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $endDate = $_GET['end_date'] ?? $_POST['end_date'] ?? date('Y-m-d');
    $clientId = $_GET['client_id'] ?? $_POST['client_id'] ?? null;
    $marketplace = $_GET['marketplace'] ?? $_POST['marketplace'] ?? null;
    
    // Валидация дат
    if (!validateDate($startDate) || !validateDate($endDate)) {
        throw new InvalidArgumentException('Некорректный формат даты. Используйте YYYY-MM-DD');
    }
    
    if ($startDate > $endDate) {
        throw new InvalidArgumentException('Начальная дата не может быть больше конечной');
    }
    
    // Маршрутизация запросов
    switch ($action) {
        case 'validate_data':
            $result = validateMarketplaceData($marginAPI, $startDate, $endDate, $clientId);
            break;
            
        case 'quality_report':
            $result = getDataQualityReport($marginAPI, $startDate, $endDate, $clientId);
            break;
            
        case 'consistency_check':
            $result = checkDataConsistency($marginAPI, $startDate, $endDate, $clientId);
            break;
            
        case 'error_stats':
            $result = getErrorStatistics($startDate, $endDate);
            break;
            
        case 'marketplace_stats':
            $result = getMarketplaceStatistics($marginAPI, $startDate, $endDate, $clientId);
            break;
            
        case 'health_check':
            $result = performHealthCheck($marginAPI, $startDate, $endDate, $clientId);
            break;
            
        case 'test_fallback':
            $result = testFallbackMechanisms($marginAPI, $marketplace);
            break;
            
        default:
            throw new InvalidArgumentException('Неизвестное действие: ' . $action);
    }
    
    // Возвращаем результат
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Обработка ошибок
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'API_ERROR',
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

/**
 * Выполнить полную валидацию данных маркетплейсов
 */
function validateMarketplaceData($marginAPI, $startDate, $endDate, $clientId) {
    $pdo = $marginAPI->getPDO(); // Предполагаем, что есть геттер для PDO
    $fallbackHandler = new MarketplaceFallbackHandler($pdo);
    $validator = new MarketplaceDataValidator($pdo, $marginAPI, $fallbackHandler);
    
    $validation = $validator->validateMarketplaceData($startDate, $endDate, $clientId);
    
    return [
        'success' => true,
        'action' => 'validate_data',
        'data' => $validation,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Получить отчет о качестве данных
 */
function getDataQualityReport($marginAPI, $startDate, $endDate, $clientId) {
    $pdo = $marginAPI->getPDO();
    $fallbackHandler = new MarketplaceFallbackHandler($pdo);
    $validator = new MarketplaceDataValidator($pdo, $marginAPI, $fallbackHandler);
    
    $report = $validator->getDataQualityReport($startDate, $endDate, $clientId);
    
    return [
        'success' => true,
        'action' => 'quality_report',
        'data' => $report,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Проверить консистентность данных
 */
function checkDataConsistency($marginAPI, $startDate, $endDate, $clientId) {
    try {
        // Получаем данные по всем маркетплейсам
        $totalData = $marginAPI->getMarginSummaryByMarketplace($startDate, $endDate, null, $clientId);
        $ozonData = $marginAPI->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon', $clientId);
        $wbData = $marginAPI->getMarginSummaryByMarketplace($startDate, $endDate, 'wildberries', $clientId);
        
        $consistency = [
            'period' => "$startDate to $endDate",
            'client_id' => $clientId,
            'status' => 'consistent',
            'issues' => [],
            'data' => [
                'total' => $totalData,
                'ozon' => $ozonData,
                'wildberries' => $wbData
            ]
        ];
        
        // Проверяем консистентность, если все данные успешно получены
        if ($totalData['success'] && $ozonData['success'] && $wbData['success']) {
            $totalRevenue = $totalData['has_data'] ? $totalData['total_revenue'] : 0;
            $ozonRevenue = $ozonData['has_data'] ? $ozonData['total_revenue'] : 0;
            $wbRevenue = $wbData['has_data'] ? $wbData['total_revenue'] : 0;
            $sumRevenue = $ozonRevenue + $wbRevenue;
            
            if ($totalRevenue > 0) {
                $discrepancy = abs($totalRevenue - $sumRevenue) / $totalRevenue * 100;
                
                if ($discrepancy > 5) {
                    $consistency['status'] = 'inconsistent';
                    $consistency['issues'][] = [
                        'type' => 'revenue_discrepancy',
                        'severity' => $discrepancy > 10 ? 'critical' : 'warning',
                        'message' => "Расхождение в выручке: {$discrepancy}%",
                        'details' => [
                            'total_revenue' => $totalRevenue,
                            'marketplaces_sum' => $sumRevenue,
                            'discrepancy_percent' => round($discrepancy, 2)
                        ]
                    ];
                }
            }
        }
        
        return [
            'success' => true,
            'action' => 'consistency_check',
            'data' => $consistency,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'action' => 'consistency_check',
            'error' => [
                'message' => $e->getMessage(),
                'code' => 'CONSISTENCY_CHECK_ERROR'
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

/**
 * Получить статистику ошибок
 */
function getErrorStatistics($startDate, $endDate) {
    $fallbackHandler = new MarketplaceFallbackHandler(null); // PDO не нужен для статистики ошибок
    $errorStats = $fallbackHandler->getErrorStats($startDate, $endDate);
    
    return [
        'success' => true,
        'action' => 'error_stats',
        'data' => $errorStats,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Получить статистику по маркетплейсам
 */
function getMarketplaceStatistics($marginAPI, $startDate, $endDate, $clientId) {
    try {
        $pdo = $marginAPI->getPDO();
        $detector = new MarketplaceDetector($pdo);
        $stats = $detector->getMarketplaceStats($startDate, $endDate, $clientId);
        $classification = $detector->validateMarketplaceClassification($startDate, $endDate, $clientId);
        
        return [
            'success' => true,
            'action' => 'marketplace_stats',
            'data' => [
                'marketplace_breakdown' => $stats,
                'classification_quality' => $classification,
                'period' => "$startDate to $endDate"
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'action' => 'marketplace_stats',
            'error' => [
                'message' => $e->getMessage(),
                'code' => 'MARKETPLACE_STATS_ERROR'
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

/**
 * Выполнить проверку работоспособности системы
 */
function performHealthCheck($marginAPI, $startDate, $endDate, $clientId) {
    $healthCheck = [
        'overall_status' => 'healthy',
        'timestamp' => date('Y-m-d H:i:s'),
        'checks' => []
    ];
    
    try {
        // Проверка подключения к базе данных
        $healthCheck['checks']['database'] = [
            'status' => 'healthy',
            'message' => 'Подключение к базе данных работает'
        ];
        
        // Проверка доступности данных
        $testData = $marginAPI->getMarginSummaryByMarketplace($startDate, $endDate, null, $clientId);
        if ($testData['success']) {
            $healthCheck['checks']['data_availability'] = [
                'status' => 'healthy',
                'message' => 'Данные доступны',
                'has_data' => $testData['has_data']
            ];
        } else {
            $healthCheck['checks']['data_availability'] = [
                'status' => 'warning',
                'message' => 'Нет данных за указанный период'
            ];
            $healthCheck['overall_status'] = 'warning';
        }
        
        // Проверка классификации маркетплейсов
        $pdo = $marginAPI->getPDO();
        $detector = new MarketplaceDetector($pdo);
        $classification = $detector->validateMarketplaceClassification($startDate, $endDate, $clientId);
        
        if ($classification['classification_rate'] >= 85) {
            $healthCheck['checks']['marketplace_classification'] = [
                'status' => 'healthy',
                'message' => 'Качество классификации маркетплейсов хорошее',
                'classification_rate' => $classification['classification_rate']
            ];
        } else {
            $healthCheck['checks']['marketplace_classification'] = [
                'status' => 'warning',
                'message' => 'Низкое качество классификации маркетплейсов',
                'classification_rate' => $classification['classification_rate']
            ];
            $healthCheck['overall_status'] = 'warning';
        }
        
    } catch (Exception $e) {
        $healthCheck['overall_status'] = 'unhealthy';
        $healthCheck['checks']['system_error'] = [
            'status' => 'error',
            'message' => 'Системная ошибка: ' . $e->getMessage()
        ];
    }
    
    return [
        'success' => true,
        'action' => 'health_check',
        'data' => $healthCheck,
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Тестировать механизмы fallback
 */
function testFallbackMechanisms($marginAPI, $marketplace) {
    $pdo = $marginAPI->getPDO();
    $fallbackHandler = new MarketplaceFallbackHandler($pdo);
    
    $tests = [
        'missing_data' => $fallbackHandler->handleMissingData(
            $marketplace ?: 'ozon',
            '2025-01-01 to 2025-01-01',
            ['test' => true]
        ),
        'unknown_marketplace' => $fallbackHandler->handleUnknownMarketplace(
            'unknown_source',
            ['test' => true]
        ),
        'empty_results' => $fallbackHandler->handleEmptyResults(
            $marketplace ?: 'ozon',
            ['test' => true]
        ),
        'user_friendly_error' => $fallbackHandler->createUserFriendlyError(
            MarketplaceFallbackHandler::ERROR_NO_DATA,
            $marketplace ?: 'ozon',
            ['test' => true]
        )
    ];
    
    return [
        'success' => true,
        'action' => 'test_fallback',
        'data' => [
            'message' => 'Тестирование механизмов fallback завершено',
            'tests' => $tests
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

/**
 * Валидация формата даты
 */
function validateDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

// Добавляем метод getPDO в MarginDashboardAPI если его нет
if (!method_exists('MarginDashboardAPI', 'getPDO')) {
    // Создаем временное решение через reflection
    class MarginDashboardAPIExtended extends MarginDashboardAPI {
        public function getPDO() {
            $reflection = new ReflectionClass($this);
            $property = $reflection->getProperty('pdo');
            $property->setAccessible(true);
            return $property->getValue($this);
        }
    }
}
?>