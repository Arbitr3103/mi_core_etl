<?php
/**
 * OzonSecurityMiddleware Class - Middleware для интеграции безопасности
 * 
 * Обеспечивает автоматическую проверку безопасности для всех запросов
 * к API аналитики Ozon. Интегрируется с существующими эндпоинтами.
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once 'OzonSecurityManager.php';

class OzonSecurityMiddleware {
    private $securityManager;
    private $config;
    
    public function __construct(OzonSecurityManager $securityManager, $config = []) {
        $this->securityManager = $securityManager;
        $this->config = array_merge([
            'require_authentication' => true,
            'enable_rate_limiting' => true,
            'log_all_requests' => true,
            'allowed_ips' => [], // Пустой массив = все IP разрешены
            'blocked_ips' => [],
            'session_timeout' => 3600, // 1 час
            'max_concurrent_sessions' => 5
        ], $config);
    }
    
    /**
     * Основной метод middleware - проверка безопасности запроса
     * 
     * @param string $action - действие/эндпоинт
     * @param array $requestData - данные запроса
     * @return array результат проверки
     * @throws SecurityException при нарушениях безопасности
     */
    public function checkRequest($action, $requestData = []) {
        $context = $this->buildRequestContext();
        
        try {
            // 1. Проверка IP адреса
            $this->checkIpAddress($context['ip_address']);
            
            // 2. Получение пользователя из запроса/сессии
            $userId = $this->extractUserId($requestData, $context);
            
            // 3. Проверка аутентификации
            if ($this->config['require_authentication']) {
                $this->checkAuthentication($userId, $context);
            }
            
            // 4. Определение операции по действию
            $operation = $this->mapActionToOperation($action);
            
            // 5. Проверка прав доступа и rate limiting
            $this->securityManager->checkAccess($userId, $operation, $context);
            
            // 6. Логирование успешного запроса
            if ($this->config['log_all_requests']) {
                $this->securityManager->logSecurityEvent(
                    'REQUEST_PROCESSED', 
                    $userId, 
                    $operation, 
                    $context,
                    ['action' => $action, 'request_size' => strlen(json_encode($requestData))]
                );
            }
            
            return [
                'success' => true,
                'user_id' => $userId,
                'operation' => $operation,
                'context' => $context
            ];
            
        } catch (SecurityException $e) {
            // Логируем нарушение безопасности
            $this->securityManager->logSecurityEvent(
                'SECURITY_VIOLATION',
                $userId ?? 'unknown',
                $operation ?? $action,
                $context,
                [
                    'error_type' => $e->getErrorType(),
                    'error_message' => $e->getMessage(),
                    'action' => $action
                ]
            );
            
            throw $e;
        }
    }
    
    /**
     * Построение контекста запроса
     * 
     * @return array контекст запроса
     */
    private function buildRequestContext() {
        return [
            'ip_address' => $this->getClientIpAddress(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'timestamp' => time(),
            'session_id' => session_id() ?: null
        ];
    }
    
    /**
     * Получение реального IP адреса клиента
     * 
     * @return string IP адрес
     */
    private function getClientIpAddress() {
        // Проверяем различные заголовки для получения реального IP
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];
        
        foreach ($ipHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                $ip = trim($ips[0]);
                
                // Валидация IP адреса
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    /**
     * Проверка IP адреса на разрешенные/заблокированные списки
     * 
     * @param string $ipAddress - IP адрес для проверки
     * @throws SecurityException при заблокированном IP
     */
    private function checkIpAddress($ipAddress) {
        // Проверка заблокированных IP
        if (!empty($this->config['blocked_ips'])) {
            foreach ($this->config['blocked_ips'] as $blockedIp) {
                if ($this->matchIpPattern($ipAddress, $blockedIp)) {
                    throw new SecurityException(
                        "IP адрес заблокирован: {$ipAddress}",
                        403,
                        'IP_BLOCKED'
                    );
                }
            }
        }
        
        // Проверка разрешенных IP (если список не пустой)
        if (!empty($this->config['allowed_ips'])) {
            $allowed = false;
            foreach ($this->config['allowed_ips'] as $allowedIp) {
                if ($this->matchIpPattern($ipAddress, $allowedIp)) {
                    $allowed = true;
                    break;
                }
            }
            
            if (!$allowed) {
                throw new SecurityException(
                    "IP адрес не в списке разрешенных: {$ipAddress}",
                    403,
                    'IP_NOT_ALLOWED'
                );
            }
        }
    }
    
    /**
     * Сопоставление IP адреса с паттерном (поддержка CIDR и wildcards)
     * 
     * @param string $ip - IP адрес
     * @param string $pattern - паттерн для сопоставления
     * @return bool true если IP соответствует паттерну
     */
    private function matchIpPattern($ip, $pattern) {
        // Точное совпадение
        if ($ip === $pattern) {
            return true;
        }
        
        // CIDR нотация (например, 192.168.1.0/24)
        if (strpos($pattern, '/') !== false) {
            list($subnet, $mask) = explode('/', $pattern);
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int)$mask);
            
            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }
        
        // Wildcard паттерн (например, 192.168.1.*)
        if (strpos($pattern, '*') !== false) {
            $pattern = str_replace('*', '.*', preg_quote($pattern, '/'));
            return preg_match("/^{$pattern}$/", $ip);
        }
        
        return false;
    }
    
    /**
     * Извлечение ID пользователя из запроса
     * 
     * @param array $requestData - данные запроса
     * @param array $context - контекст запроса
     * @return string ID пользователя
     */
    private function extractUserId($requestData, $context) {
        // Попытка получить user_id из различных источников
        
        // 1. Из параметров запроса
        if (!empty($requestData['user_id'])) {
            return (string)$requestData['user_id'];
        }
        
        // 2. Из GET параметров
        if (!empty($_GET['user_id'])) {
            return (string)$_GET['user_id'];
        }
        
        // 3. Из заголовков авторизации
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $userId = $this->extractUserFromAuthHeader($_SERVER['HTTP_AUTHORIZATION']);
            if ($userId) {
                return $userId;
            }
        }
        
        // 4. Из сессии
        if (!empty($_SESSION['user_id'])) {
            return (string)$_SESSION['user_id'];
        }
        
        // 5. Из cookies
        if (!empty($_COOKIE['user_id'])) {
            return (string)$_COOKIE['user_id'];
        }
        
        // 6. Генерируем временный ID на основе IP и User-Agent для анонимных пользователей
        return 'anonymous_' . md5($context['ip_address'] . $context['user_agent']);
    }
    
    /**
     * Извлечение пользователя из заголовка авторизации
     * 
     * @param string $authHeader - заголовок авторизации
     * @return string|null ID пользователя или null
     */
    private function extractUserFromAuthHeader($authHeader) {
        // Bearer token
        if (preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            $token = $matches[1];
            // Здесь должна быть логика декодирования JWT или проверки токена
            // Пока возвращаем простую реализацию
            return $this->validateAndDecodeToken($token);
        }
        
        // Basic auth
        if (preg_match('/Basic\s+(.+)/', $authHeader, $matches)) {
            $credentials = base64_decode($matches[1]);
            if (strpos($credentials, ':') !== false) {
                list($username, $password) = explode(':', $credentials, 2);
                // Здесь должна быть проверка логина/пароля
                return $this->validateBasicAuth($username, $password);
            }
        }
        
        return null;
    }
    
    /**
     * Валидация и декодирование токена
     * 
     * @param string $token - токен для проверки
     * @return string|null ID пользователя или null
     */
    private function validateAndDecodeToken($token) {
        // Простая реализация - в реальной системе здесь должна быть проверка JWT
        // или обращение к системе управления токенами
        
        if (strlen($token) < 10) {
            return null;
        }
        
        // Пример декодирования простого токена
        $decoded = base64_decode($token);
        if ($decoded && strpos($decoded, 'user:') === 0) {
            return substr($decoded, 5);
        }
        
        return null;
    }
    
    /**
     * Валидация базовой аутентификации
     * 
     * @param string $username - имя пользователя
     * @param string $password - пароль
     * @return string|null ID пользователя или null
     */
    private function validateBasicAuth($username, $password) {
        // Простая реализация - в реальной системе здесь должна быть проверка в БД
        // или интеграция с существующей системой аутентификации
        
        if (empty($username) || empty($password)) {
            return null;
        }
        
        // Пример простой проверки
        if ($username === 'admin' && $password === 'admin123') {
            return 'admin_user';
        }
        
        return $username; // Возвращаем username как user_id
    }
    
    /**
     * Проверка аутентификации пользователя
     * 
     * @param string $userId - ID пользователя
     * @param array $context - контекст запроса
     * @throws SecurityException при неудачной аутентификации
     */
    private function checkAuthentication($userId, $context) {
        if (empty($userId) || strpos($userId, 'anonymous_') === 0) {
            throw new SecurityException(
                'Требуется аутентификация для доступа к аналитическим данным',
                401,
                'AUTHENTICATION_REQUIRED'
            );
        }
        
        // Проверка активности сессии
        if (!empty($context['session_id'])) {
            $this->checkSessionValidity($userId, $context['session_id']);
        }
        
        // Проверка лимита одновременных сессий
        $this->checkConcurrentSessions($userId);
    }
    
    /**
     * Проверка валидности сессии
     * 
     * @param string $userId - ID пользователя
     * @param string $sessionId - ID сессии
     * @throws SecurityException при невалидной сессии
     */
    private function checkSessionValidity($userId, $sessionId) {
        // Проверяем время последней активности сессии
        $lastActivity = $_SESSION['last_activity'] ?? 0;
        $currentTime = time();
        
        if ($currentTime - $lastActivity > $this->config['session_timeout']) {
            throw new SecurityException(
                'Сессия истекла, требуется повторная аутентификация',
                401,
                'SESSION_EXPIRED'
            );
        }
        
        // Обновляем время последней активности
        $_SESSION['last_activity'] = $currentTime;
    }
    
    /**
     * Проверка лимита одновременных сессий
     * 
     * @param string $userId - ID пользователя
     * @throws SecurityException при превышении лимита
     */
    private function checkConcurrentSessions($userId) {
        // Простая реализация - в реальной системе нужно отслеживать активные сессии в БД
        $maxSessions = $this->config['max_concurrent_sessions'];
        
        if ($maxSessions <= 0) {
            return; // Лимит отключен
        }
        
        // Здесь должна быть логика подсчета активных сессий пользователя
        // Пока пропускаем эту проверку
    }
    
    /**
     * Сопоставление действия с операцией безопасности
     * 
     * @param string $action - действие/эндпоинт
     * @return string операция для проверки безопасности
     */
    private function mapActionToOperation($action) {
        $mapping = [
            'funnel-data' => OzonSecurityManager::OPERATION_VIEW_FUNNEL,
            'demographics' => OzonSecurityManager::OPERATION_VIEW_DEMOGRAPHICS,
            'campaigns' => OzonSecurityManager::OPERATION_VIEW_CAMPAIGNS,
            'export-data' => OzonSecurityManager::OPERATION_EXPORT_DATA,
            'settings' => OzonSecurityManager::OPERATION_MANAGE_SETTINGS,
            'clear-cache' => OzonSecurityManager::OPERATION_CLEAR_CACHE,
            'warm-cache' => OzonSecurityManager::OPERATION_CLEAR_CACHE,
            'cleanup-downloads' => OzonSecurityManager::OPERATION_MANAGE_SETTINGS
        ];
        
        return $mapping[$action] ?? OzonSecurityManager::OPERATION_VIEW_FUNNEL;
    }
    
    /**
     * Получение конфигурации безопасности для пользователя
     * 
     * @param string $userId - ID пользователя
     * @return array конфигурация безопасности
     */
    public function getUserSecurityConfig($userId) {
        try {
            $userLevel = $this->securityManager->getUserAccessLevel($userId);
            
            return [
                'user_id' => $userId,
                'access_level' => $userLevel,
                'permissions' => $this->getPermissionsForLevel($userLevel),
                'rate_limits' => $this->getRateLimitsForLevel($userLevel),
                'session_timeout' => $this->config['session_timeout']
            ];
            
        } catch (Exception $e) {
            return [
                'user_id' => $userId,
                'access_level' => OzonSecurityManager::ACCESS_LEVEL_NONE,
                'permissions' => [],
                'rate_limits' => [],
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Получение разрешений для уровня доступа
     * 
     * @param int $accessLevel - уровень доступа
     * @return array список разрешенных операций
     */
    private function getPermissionsForLevel($accessLevel) {
        $permissions = [];
        
        if ($accessLevel >= OzonSecurityManager::ACCESS_LEVEL_READ) {
            $permissions[] = OzonSecurityManager::OPERATION_VIEW_FUNNEL;
            $permissions[] = OzonSecurityManager::OPERATION_VIEW_DEMOGRAPHICS;
            $permissions[] = OzonSecurityManager::OPERATION_VIEW_CAMPAIGNS;
        }
        
        if ($accessLevel >= OzonSecurityManager::ACCESS_LEVEL_EXPORT) {
            $permissions[] = OzonSecurityManager::OPERATION_EXPORT_DATA;
        }
        
        if ($accessLevel >= OzonSecurityManager::ACCESS_LEVEL_ADMIN) {
            $permissions[] = OzonSecurityManager::OPERATION_MANAGE_SETTINGS;
            $permissions[] = OzonSecurityManager::OPERATION_CLEAR_CACHE;
        }
        
        return $permissions;
    }
    
    /**
     * Получение лимитов запросов для уровня доступа
     * 
     * @param int $accessLevel - уровень доступа
     * @return array лимиты запросов
     */
    private function getRateLimitsForLevel($accessLevel) {
        $baseLimits = [
            OzonSecurityManager::OPERATION_VIEW_FUNNEL => OzonSecurityManager::DEFAULT_RATE_LIMIT,
            OzonSecurityManager::OPERATION_VIEW_DEMOGRAPHICS => OzonSecurityManager::DEFAULT_RATE_LIMIT,
            OzonSecurityManager::OPERATION_VIEW_CAMPAIGNS => OzonSecurityManager::DEFAULT_RATE_LIMIT,
            OzonSecurityManager::OPERATION_EXPORT_DATA => OzonSecurityManager::EXPORT_RATE_LIMIT,
            OzonSecurityManager::OPERATION_MANAGE_SETTINGS => OzonSecurityManager::ADMIN_RATE_LIMIT,
            OzonSecurityManager::OPERATION_CLEAR_CACHE => OzonSecurityManager::ADMIN_RATE_LIMIT
        ];
        
        // Увеличиваем лимиты для более высоких уровней доступа
        $multiplier = 1;
        if ($accessLevel >= OzonSecurityManager::ACCESS_LEVEL_EXPORT) {
            $multiplier = 2;
        }
        if ($accessLevel >= OzonSecurityManager::ACCESS_LEVEL_ADMIN) {
            $multiplier = 5;
        }
        
        $adjustedLimits = [];
        foreach ($baseLimits as $operation => $limit) {
            $adjustedLimits[$operation] = $limit * $multiplier;
        }
        
        return $adjustedLimits;
    }
}
?>