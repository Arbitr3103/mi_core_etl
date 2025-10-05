<?php
/**
 * Ozon API Settings Management Interface
 * 
 * Provides a secure interface for managing Ozon API connection settings
 * including Client ID, API Key, and connection testing functionality.
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once 'classes/OzonAnalyticsAPI.php';
require_once 'classes/OzonSecurityManager.php';
require_once 'classes/OzonSecurityMiddleware.php';

// Database connection configuration
$dbConfig = [
    'host' => 'localhost',
    'dbname' => 'mi_core_db',
    'username' => 'mi_core_user',
    'password' => 'secure_password_123'
];

// Initialize database connection
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
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}

// Initialize security system
$securityManager = new OzonSecurityManager($pdo);
$securityMiddleware = new OzonSecurityMiddleware($securityManager, [
    'require_authentication' => true,
    'enable_rate_limiting' => true,
    'log_all_requests' => true
]);

// Security check for settings access
$userId = 'admin'; // In real implementation, get from session/auth
try {
    $securityMiddleware->checkRequest('settings', $_POST);
} catch (SecurityException $e) {
    die("Доступ запрещен: " . $e->getMessage());
}

// Handle form submissions
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Log settings access attempt
    $securityManager->logSecurityEvent(
        'SETTINGS_ACCESS_ATTEMPT',
        $userId,
        OzonSecurityManager::OPERATION_MANAGE_SETTINGS,
        ['ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'],
        ['action' => $action]
    );
    
    switch ($action) {
        case 'save_settings':
            $result = saveApiSettings($pdo, $_POST, $userId, $securityManager);
            $message = $result['message'];
            $messageType = $result['type'];
            break;
            
        case 'test_connection':
            $result = testApiConnection($pdo, $_POST, $userId, $securityManager);
            $message = $result['message'];
            $messageType = $result['type'];
            break;
            
        case 'delete_settings':
            $result = deleteApiSettings($pdo, $_POST['settings_id'], $userId, $securityManager);
            $message = $result['message'];
            $messageType = $result['type'];
            break;
    }
}

// Get current settings
$currentSettings = getCurrentSettings($pdo);

/**
 * Save API settings with encryption and security logging
 */
function saveApiSettings($pdo, $data, $userId, $securityManager) {
    try {
        $clientId = trim($data['client_id'] ?? '');
        $apiKey = trim($data['api_key'] ?? '');
        $settingsId = intval($data['settings_id'] ?? 0);
        
        // Validation
        if (empty($clientId)) {
            return ['message' => 'Client ID не может быть пустым', 'type' => 'error'];
        }
        
        if (empty($apiKey)) {
            return ['message' => 'API Key не может быть пустым', 'type' => 'error'];
        }
        
        // Validate Client ID format (should be numeric)
        if (!is_numeric($clientId)) {
            return ['message' => 'Client ID должен содержать только цифры', 'type' => 'error'];
        }
        
        // Validate API Key format (should be alphanumeric with dashes)
        if (!preg_match('/^[a-zA-Z0-9\-_]+$/', $apiKey)) {
            return ['message' => 'API Key содержит недопустимые символы', 'type' => 'error'];
        }
        
        // Hash the API key for secure storage
        $apiKeyHash = password_hash($apiKey, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
        
        if ($settingsId > 0) {
            // Update existing settings
            $sql = "UPDATE ozon_api_settings SET 
                    client_id = :client_id, 
                    api_key_hash = :api_key_hash,
                    updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                'client_id' => $clientId,
                'api_key_hash' => $apiKeyHash,
                'id' => $settingsId
            ]);
        } else {
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
        }
        
        if ($result) {
            // Log successful settings update
            $securityManager->logSecurityEvent(
                'SETTINGS_UPDATED',
                $userId,
                OzonSecurityManager::OPERATION_MANAGE_SETTINGS,
                ['ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'],
                [
                    'client_id' => $clientId,
                    'settings_id' => $settingsId,
                    'action' => $settingsId > 0 ? 'update' : 'create'
                ]
            );
            
            return ['message' => 'Настройки успешно сохранены', 'type' => 'success'];
        } else {
            // Log failed settings update
            $securityManager->logSecurityEvent(
                'SETTINGS_UPDATE_FAILED',
                $userId,
                OzonSecurityManager::OPERATION_MANAGE_SETTINGS,
                ['ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'],
                [
                    'client_id' => $clientId,
                    'settings_id' => $settingsId,
                    'error' => 'Database operation failed'
                ]
            );
            
            return ['message' => 'Ошибка сохранения настроек', 'type' => 'error'];
        }
        
    } catch (PDOException $e) {
        error_log('Database error in saveApiSettings: ' . $e->getMessage());
        return ['message' => 'Ошибка базы данных: ' . $e->getMessage(), 'type' => 'error'];
    } catch (Exception $e) {
        error_log('General error in saveApiSettings: ' . $e->getMessage());
        return ['message' => 'Ошибка: ' . $e->getMessage(), 'type' => 'error'];
    }
}

/**
 * Test API connection with security logging
 */
function testApiConnection($pdo, $data, $userId, $securityManager) {
    try {
        $clientId = trim($data['client_id'] ?? '');
        $apiKey = trim($data['api_key'] ?? '');
        
        if (empty($clientId) || empty($apiKey)) {
            return ['message' => 'Для тестирования необходимо указать Client ID и API Key', 'type' => 'error'];
        }
        
        // Create OzonAnalyticsAPI instance for testing
        $ozonApi = new OzonAnalyticsAPI($clientId, $apiKey, $pdo);
        
        // Test authentication
        $token = $ozonApi->authenticate();
        
        if (!empty($token)) {
            // Log successful connection test
            $securityManager->logSecurityEvent(
                'API_CONNECTION_TEST_SUCCESS',
                $userId,
                OzonSecurityManager::OPERATION_MANAGE_SETTINGS,
                ['ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'],
                ['client_id' => $clientId]
            );
            
            return ['message' => 'Подключение к API Ozon успешно! Токен получен.', 'type' => 'success'];
        } else {
            // Log failed connection test
            $securityManager->logSecurityEvent(
                'API_CONNECTION_TEST_FAILED',
                $userId,
                OzonSecurityManager::OPERATION_MANAGE_SETTINGS,
                ['ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'],
                ['client_id' => $clientId, 'error' => 'No token received']
            );
            
            return ['message' => 'Не удалось получить токен доступа', 'type' => 'error'];
        }
        
    } catch (OzonAPIException $e) {
        $errorMessage = 'Ошибка API Ozon: ' . $e->getMessage();
        if ($e->getErrorType() === 'AUTHENTICATION_ERROR') {
            $errorMessage .= ' Проверьте правильность Client ID и API Key.';
        }
        return ['message' => $errorMessage, 'type' => 'error'];
    } catch (Exception $e) {
        return ['message' => 'Ошибка тестирования: ' . $e->getMessage(), 'type' => 'error'];
    }
}

/**
 * Delete API settings with security logging
 */
function deleteApiSettings($pdo, $settingsId, $userId, $securityManager) {
    try {
        $settingsId = intval($settingsId);
        
        if ($settingsId <= 0) {
            return ['message' => 'Некорректный ID настроек', 'type' => 'error'];
        }
        
        $sql = "DELETE FROM ozon_api_settings WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute(['id' => $settingsId]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Log successful settings deletion
            $securityManager->logSecurityEvent(
                'SETTINGS_DELETED',
                $userId,
                OzonSecurityManager::OPERATION_MANAGE_SETTINGS,
                ['ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'],
                ['settings_id' => $settingsId]
            );
            
            return ['message' => 'Настройки успешно удалены', 'type' => 'success'];
        } else {
            // Log failed settings deletion
            $securityManager->logSecurityEvent(
                'SETTINGS_DELETE_FAILED',
                $userId,
                OzonSecurityManager::OPERATION_MANAGE_SETTINGS,
                ['ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'],
                ['settings_id' => $settingsId, 'error' => 'Settings not found']
            );
            
            return ['message' => 'Настройки не найдены', 'type' => 'error'];
        }
        
    } catch (PDOException $e) {
        error_log('Database error in deleteApiSettings: ' . $e->getMessage());
        return ['message' => 'Ошибка базы данных: ' . $e->getMessage(), 'type' => 'error'];
    }
}

/**
 * Get current API settings
 */
function getCurrentSettings($pdo) {
    try {
        $sql = "SELECT id, client_id, is_active, created_at, updated_at 
                FROM ozon_api_settings 
                ORDER BY is_active DESC, updated_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log('Database error in getCurrentSettings: ' . $e->getMessage());
        return [];
    }
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
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>⚙️ Настройки Ozon API</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { 
            background: #f8f9fa; 
        }
        
        .settings-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .settings-header {
            background: linear-gradient(135deg, #0066cc 0%, #004499 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
        }
        
        .form-control:focus {
            border-color: #0066cc;
            box-shadow: 0 0 0 0.2rem rgba(0, 102, 204, 0.25);
        }
        
        .btn-ozon {
            background: linear-gradient(135deg, #0066cc 0%, #004499 100%);
            border: none;
            color: white;
        }
        
        .btn-ozon:hover {
            background: linear-gradient(135deg, #004499 0%, #003366 100%);
            color: white;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .connection-status {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .connection-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .connection-error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .security-info {
            background: #e7f3ff;
            border: 1px solid #b8daff;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .api-key-input {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: none;
            color: #6c757d;
            cursor: pointer;
        }
        
        .settings-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .loading-spinner {
            display: none;
        }
        
        .loading .loading-spinner {
            display: inline-block;
        }
        
        .loading .btn-text {
            display: none;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h1><i class="fas fa-cog"></i> Настройки Ozon API</h1>
                    <a href="dashboard_marketplace_enhanced.php?view=ozon-analytics" class="btn btn-outline-primary">
                        <i class="fas fa-arrow-left"></i> Вернуться к дашборду
                    </a>
                </div>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
            <i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Connection Status -->
        <div id="connectionStatusContainer">
            <!-- Connection status will be loaded here -->
        </div>

        <!-- Security Information -->
        <div class="security-info">
            <h6><i class="fas fa-shield-alt"></i> Информация о безопасности</h6>
            <ul class="mb-0">
                <li>API ключи хранятся в зашифрованном виде с использованием Argon2ID</li>
                <li>Токены доступа автоматически обновляются каждые 30 минут</li>
                <li>Все действия с настройками логируются</li>
                <li>Рекомендуется регулярно обновлять API ключи</li>
            </ul>
        </div>

        <div class="row">
            <!-- Settings Form -->
            <div class="col-md-8">
                <div class="settings-card">
                    <div class="settings-header">
                        <h4><i class="fas fa-key"></i> Настройки подключения к API</h4>
                        <p class="mb-0">Настройте параметры подключения к API Ozon для получения аналитических данных</p>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" id="settingsForm">
                            <input type="hidden" name="action" value="save_settings">
                            <input type="hidden" name="settings_id" id="settings_id" value="<?= !empty($currentSettings) ? $currentSettings[0]['id'] : '' ?>">
                            
                            <div class="mb-3">
                                <label for="client_id" class="form-label">
                                    <i class="fas fa-user"></i> Client ID <span class="text-danger">*</span>
                                </label>
                                <input type="text" 
                                       class="form-control" 
                                       id="client_id" 
                                       name="client_id" 
                                       value="<?= !empty($currentSettings) ? htmlspecialchars($currentSettings[0]['client_id']) : '' ?>"
                                       placeholder="Введите Client ID от Ozon"
                                       required>
                                <div class="form-text">
                                    Числовой идентификатор клиента, предоставленный Ozon
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="api_key" class="form-label">
                                    <i class="fas fa-lock"></i> API Key <span class="text-danger">*</span>
                                </label>
                                <div class="api-key-input">
                                    <input type="password" 
                                           class="form-control" 
                                           id="api_key" 
                                           name="api_key" 
                                           placeholder="Введите API Key от Ozon"
                                           required>
                                    <button type="button" class="toggle-password" onclick="togglePasswordVisibility()">
                                        <i class="fas fa-eye" id="toggleIcon"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    Секретный ключ API, предоставленный Ozon. Будет зашифрован при сохранении.
                                </div>
                            </div>
                            
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-ozon">
                                    <span class="loading-spinner spinner-border spinner-border-sm me-2"></span>
                                    <span class="btn-text"><i class="fas fa-save"></i> Сохранить настройки</span>
                                </button>
                                
                                <button type="button" class="btn btn-outline-success" id="testConnectionBtn">
                                    <span class="loading-spinner spinner-border spinner-border-sm me-2"></span>
                                    <span class="btn-text"><i class="fas fa-plug"></i> Тестировать подключение</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Current Settings -->
            <div class="col-md-4">
                <div class="settings-card">
                    <div class="card-header bg-light">
                        <h5><i class="fas fa-list"></i> Текущие настройки</h5>
                    </div>
                    <div class="card-body" id="settingsListContainer">
                        <!-- Settings list will be loaded here by JavaScript -->
                        <div class="text-center py-4">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Загрузка...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- API Documentation -->
                <div class="settings-card">
                    <div class="card-header bg-light">
                        <h5><i class="fas fa-book"></i> Справка</h5>
                    </div>
                    <div class="card-body">
                        <h6>Где получить API ключи?</h6>
                        <ol class="small">
                            <li>Войдите в <a href="https://seller.ozon.ru" target="_blank">личный кабинет Ozon</a></li>
                            <li>Перейдите в раздел "Настройки" → "API"</li>
                            <li>Создайте новый API ключ</li>
                            <li>Скопируйте Client ID и API Key</li>
                        </ol>
                        
                        <h6 class="mt-3">Требования к API ключам:</h6>
                        <ul class="small">
                            <li>Client ID должен содержать только цифры</li>
                            <li>API Key должен быть действующим</li>
                            <li>Аккаунт должен иметь права на аналитику</li>
                        </ul>
                        
                        <div class="mt-3">
                            <a href="https://docs.ozon.ru/api/seller/" target="_blank" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-external-link-alt"></i> Документация API
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Connection Test Modal -->
    <div class="modal fade" id="connectionTestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plug"></i> Тестирование подключения</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="connectionTestResult"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="src/js/OzonSettingsManager.js"></script>
</body>
</html>