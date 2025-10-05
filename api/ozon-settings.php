<?php
/**
 * Ozon Settings API Endpoint
 * 
 * Provides API endpoints for managing Ozon API settings
 * including validation, testing, and secure storage.
 * 
 * @version 1.0
 * @author Manhattan System
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../classes/OzonAnalyticsAPI.php';

// Database connection configuration
$dbConfig = [
    'host' => 'localhost',
    'dbname' => 'mi_core_db',
    'username' => 'mi_core_user',
    'password' => 'secure_password_123'
];

try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};charset=utf8mb4",
        $dbConfig['username'],
        $dbConfig['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database connection failed',
        'message' => 'Ошибка подключения к базе данных'
    ]);
    exit;
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGetRequest($pdo, $action);
            break;
            
        case 'POST':
            handlePostRequest($pdo, $action);
            break;
            
        case 'PUT':
            handlePutRequest($pdo, $action);
            break;
            
        case 'DELETE':
            handleDeleteRequest($pdo, $action);
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'error' => 'Method not allowed',
                'message' => 'Метод не поддерживается'
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}

/**
 * Handle GET requests
 */
function handleGetRequest($pdo, $action) {
    switch ($action) {
        case 'get_settings':
            getSettings($pdo);
            break;
            
        case 'get_connection_status':
            getConnectionStatus($pdo);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'message' => 'Неизвестное действие'
            ]);
    }
}

/**
 * Handle POST requests
 */
function handlePostRequest($pdo, $action) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Fallback to $_POST if JSON parsing fails
    if (json_last_error() !== JSON_ERROR_NONE) {
        $input = $_POST;
    }
    
    switch ($action) {
        case 'save_settings':
            saveSettings($pdo, $input);
            break;
            
        case 'test_connection':
            testConnection($pdo, $input);
            break;
            
        case 'validate_credentials':
            validateCredentials($input);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'message' => 'Неизвестное действие'
            ]);
    }
}

/**
 * Handle PUT requests
 */
function handlePutRequest($pdo, $action) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    switch ($action) {
        case 'update_settings':
            updateSettings($pdo, $input);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'message' => 'Неизвестное действие'
            ]);
    }
}

/**
 * Handle DELETE requests
 */
function handleDeleteRequest($pdo, $action) {
    $settingsId = $_GET['id'] ?? null;
    
    switch ($action) {
        case 'delete_settings':
            deleteSettings($pdo, $settingsId);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action',
                'message' => 'Неизвестное действие'
            ]);
    }
}

/**
 * Get current settings
 */
function getSettings($pdo) {
    try {
        $sql = "SELECT id, client_id, is_active, created_at, updated_at,
                       CASE WHEN token_expiry > NOW() THEN 1 ELSE 0 END as has_valid_token
                FROM ozon_api_settings 
                ORDER BY is_active DESC, updated_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $settings = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $settings
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error',
            'message' => 'Ошибка получения настроек'
        ]);
    }
}

/**
 * Save new settings
 */
function saveSettings($pdo, $data) {
    try {
        $clientId = trim($data['client_id'] ?? '');
        $apiKey = trim($data['api_key'] ?? '');
        
        // Validation
        $validation = validateCredentials(['client_id' => $clientId, 'api_key' => $apiKey]);
        if (!$validation['success']) {
            echo json_encode($validation);
            return;
        }
        
        // Hash the API key for secure storage
        $apiKeyHash = password_hash($apiKey, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
        
        // Deactivate all existing settings first
        $pdo->exec("UPDATE ozon_api_settings SET is_active = FALSE");
        
        // Insert new settings
        $sql = "INSERT INTO ozon_api_settings (client_id, api_key_hash, is_active) 
                VALUES (:client_id, :api_key_hash, TRUE)";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            'client_id' => $clientId,
            'api_key_hash' => $apiKeyHash
        ]);
        
        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Настройки успешно сохранены',
                'settings_id' => $pdo->lastInsertId()
            ]);
        } else {
            throw new Exception('Не удалось сохранить настройки');
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error',
            'message' => 'Ошибка сохранения настроек: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation error',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Update existing settings
 */
function updateSettings($pdo, $data) {
    try {
        $settingsId = intval($data['settings_id'] ?? 0);
        $clientId = trim($data['client_id'] ?? '');
        $apiKey = trim($data['api_key'] ?? '');
        
        if ($settingsId <= 0) {
            throw new Exception('Некорректный ID настроек');
        }
        
        // Validation
        $validation = validateCredentials(['client_id' => $clientId, 'api_key' => $apiKey]);
        if (!$validation['success']) {
            echo json_encode($validation);
            return;
        }
        
        // Hash the API key for secure storage
        $apiKeyHash = password_hash($apiKey, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
        
        // Update existing settings
        $sql = "UPDATE ozon_api_settings SET 
                client_id = :client_id, 
                api_key_hash = :api_key_hash,
                access_token = NULL,
                token_expiry = NULL,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            'client_id' => $clientId,
            'api_key_hash' => $apiKeyHash,
            'id' => $settingsId
        ]);
        
        if ($result && $stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Настройки успешно обновлены'
            ]);
        } else {
            throw new Exception('Настройки не найдены или не изменились');
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error',
            'message' => 'Ошибка обновления настроек: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation error',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Delete settings
 */
function deleteSettings($pdo, $settingsId) {
    try {
        $settingsId = intval($settingsId);
        
        if ($settingsId <= 0) {
            throw new Exception('Некорректный ID настроек');
        }
        
        $sql = "DELETE FROM ozon_api_settings WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute(['id' => $settingsId]);
        
        if ($result && $stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Настройки успешно удалены'
            ]);
        } else {
            throw new Exception('Настройки не найдены');
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error',
            'message' => 'Ошибка удаления настроек: ' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Validation error',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * Test API connection
 */
function testConnection($pdo, $data) {
    try {
        $clientId = trim($data['client_id'] ?? '');
        $apiKey = trim($data['api_key'] ?? '');
        
        if (empty($clientId) || empty($apiKey)) {
            throw new Exception('Для тестирования необходимо указать Client ID и API Key');
        }
        
        // Create OzonAnalyticsAPI instance for testing
        $ozonApi = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);
        
        // Test authentication
        $token = $ozonApi->authenticate();
        
        if (!empty($token)) {
            echo json_encode([
                'success' => true,
                'message' => 'Подключение к API Ozon успешно! Токен получен.',
                'connection_status' => 'connected',
                'token_length' => strlen($token)
            ]);
        } else {
            throw new Exception('Не удалось получить токен доступа');
        }
        
    } catch (OzonAPIException $e) {
        $errorMessage = 'Ошибка API Ozon: ' . $e->getMessage();
        if ($e->getErrorType() === 'AUTHENTICATION_ERROR') {
            $errorMessage .= ' Проверьте правильность Client ID и API Key.';
        }
        
        echo json_encode([
            'success' => false,
            'error' => 'API error',
            'message' => $errorMessage,
            'connection_status' => 'failed',
            'error_type' => $e->getErrorType()
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Connection error',
            'message' => 'Ошибка тестирования: ' . $e->getMessage(),
            'connection_status' => 'failed'
        ]);
    }
}

/**
 * Get connection status for existing settings
 */
function getConnectionStatus($pdo) {
    try {
        $sql = "SELECT id, client_id, 
                       CASE WHEN token_expiry > NOW() THEN 'connected' ELSE 'expired' END as status,
                       token_expiry
                FROM ozon_api_settings 
                WHERE is_active = TRUE 
                ORDER BY updated_at DESC 
                LIMIT 1";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        $setting = $stmt->fetch();
        
        if ($setting) {
            echo json_encode([
                'success' => true,
                'data' => [
                    'settings_id' => $setting['id'],
                    'client_id' => $setting['client_id'],
                    'status' => $setting['status'],
                    'token_expiry' => $setting['token_expiry']
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'data' => [
                    'status' => 'not_configured'
                ]
            ]);
        }
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error',
            'message' => 'Ошибка получения статуса подключения'
        ]);
    }
}

/**
 * Validate credentials format
 */
function validateCredentials($data) {
    $clientId = trim($data['client_id'] ?? '');
    $apiKey = trim($data['api_key'] ?? '');
    
    // Check if fields are not empty
    if (empty($clientId)) {
        return [
            'success' => false,
            'error' => 'Validation error',
            'message' => 'Client ID не может быть пустым',
            'field' => 'client_id'
        ];
    }
    
    if (empty($apiKey)) {
        return [
            'success' => false,
            'error' => 'Validation error',
            'message' => 'API Key не может быть пустым',
            'field' => 'api_key'
        ];
    }
    
    // Validate Client ID format (should be numeric)
    if (!is_numeric($clientId)) {
        return [
            'success' => false,
            'error' => 'Validation error',
            'message' => 'Client ID должен содержать только цифры',
            'field' => 'client_id'
        ];
    }
    
    // Validate Client ID length (reasonable range)
    if (strlen($clientId) < 3 || strlen($clientId) > 20) {
        return [
            'success' => false,
            'error' => 'Validation error',
            'message' => 'Client ID должен содержать от 3 до 20 цифр',
            'field' => 'client_id'
        ];
    }
    
    // Validate API Key format (should be alphanumeric with dashes/underscores)
    if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $apiKey)) {
        return [
            'success' => false,
            'error' => 'Validation error',
            'message' => 'API Key содержит недопустимые символы',
            'field' => 'api_key'
        ];
    }
    
    // Validate API Key length (reasonable range)
    if (strlen($apiKey) < 10 || strlen($apiKey) > 100) {
        return [
            'success' => false,
            'error' => 'Validation error',
            'message' => 'API Key должен содержать от 10 до 100 символов',
            'field' => 'api_key'
        ];
    }
    
    return [
        'success' => true,
        'message' => 'Данные прошли валидацию'
    ];
}

/**
 * Custom exception for Ozon API errors
 */
class OzonAPIException extends Exception {
    private $errorType;
    
    public function __construct($message, $code = 0, $errorType = 'GENERAL_ERROR') {
        parent::__construct($message, $code);
        $this->errorType = $errorType;
    }
    
    public function getErrorType() {
        return $this->errorType;
    }
}
?>