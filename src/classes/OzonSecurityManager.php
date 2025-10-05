<?php
/**
 * OzonSecurityManager Class - Система безопасности для аналитики Ozon
 * 
 * Обеспечивает контроль доступа, логирование, аутентификацию и rate limiting
 * для всех операций с аналитическими данными Ozon.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonSecurityManager {
    private $pdo;
    private $logFile;
    private $rateLimitConfig;
    private $accessControlConfig;
    
    // Константы для уровней доступа
    const ACCESS_LEVEL_NONE = 0;
    const ACCESS_LEVEL_READ = 1;
    const ACCESS_LEVEL_EXPORT = 2;
    const ACCESS_LEVEL_ADMIN = 3;
    
    // Константы для типов операций
    const OPERATION_VIEW_FUNNEL = 'view_funnel';
    const OPERATION_VIEW_DEMOGRAPHICS = 'view_demographics';
    const OPERATION_VIEW_CAMPAIGNS = 'view_campaigns';
    const OPERATION_EXPORT_DATA = 'export_data';
    const OPERATION_MANAGE_SETTINGS = 'manage_settings';
    const OPERATION_CLEAR_CACHE = 'clear_cache';
    
    // Rate limiting константы
    const RATE_LIMIT_WINDOW = 3600; // 1 час в секундах
    const DEFAULT_RATE_LIMIT = 100; // запросов в час
    const EXPORT_RATE_LIMIT = 10; // экспортов в час
    const ADMIN_RATE_LIMIT = 50; // админских операций в час
    
    public function __construct(PDO $pdo, $logFile = 'ozon_security.log') {
        $this->pdo = $pdo;
        $this->logFile = $logFile;
        
        // Конфигурация rate limiting по типам операций
        $this->rateLimitConfig = [
            self::OPERATION_VIEW_FUNNEL => self::DEFAULT_RATE_LIMIT,
            self::OPERATION_VIEW_DEMOGRAPHICS => self::DEFAULT_RATE_LIMIT,
            self::OPERATION_VIEW_CAMPAIGNS => self::DEFAULT_RATE_LIMIT,
            self::OPERATION_EXPORT_DATA => self::EXPORT_RATE_LIMIT,
            self::OPERATION_MANAGE_SETTINGS => self::ADMIN_RATE_LIMIT,
            self::OPERATION_CLEAR_CACHE => self::ADMIN_RATE_LIMIT
        ];
        
        // Конфигурация контроля доступа
        $this->accessControlConfig = [
            self::OPERATION_VIEW_FUNNEL => self::ACCESS_LEVEL_READ,
            self::OPERATION_VIEW_DEMOGRAPHICS => self::ACCESS_LEVEL_READ,
            self::OPERATION_VIEW_CAMPAIGNS => self::ACCESS_LEVEL_READ,
            self::OPERATION_EXPORT_DATA => self::ACCESS_LEVEL_EXPORT,
            self::OPERATION_MANAGE_SETTINGS => self::ACCESS_LEVEL_ADMIN,
            self::OPERATION_CLEAR_CACHE => self::ACCESS_LEVEL_ADMIN
        ];
        
        $this->initializeSecurityTables();
    }
    
    /**
     * Проверка прав доступа пользователя к операции
     * 
     * @param string $userId - ID пользователя
     * @param string $operation - тип операции
     * @param array $context - дополнительный контекст (IP, User-Agent и т.д.)
     * @return bool true если доступ разрешен
     * @throws SecurityException при отказе в доступе
     */
    public function checkAccess($userId, $operation, $context = []) {
        try {
            // Получаем уровень доступа пользователя
            $userAccessLevel = $this->getUserAccessLevel($userId);
            
            // Получаем требуемый уровень для операции
            $requiredLevel = $this->accessControlConfig[$operation] ?? self::ACCESS_LEVEL_ADMIN;
            
            // Проверяем права доступа
            if ($userAccessLevel < $requiredLevel) {
                $this->logSecurityEvent('ACCESS_DENIED', $userId, $operation, $context, [
                    'user_level' => $userAccessLevel,
                    'required_level' => $requiredLevel
                ]);
                
                throw new SecurityException(
                    "Недостаточно прав для выполнения операции: {$operation}",
                    403,
                    'ACCESS_DENIED'
                );
            }
            
            // Проверяем rate limiting
            $this->checkRateLimit($userId, $operation, $context);
            
            // Логируем успешный доступ
            $this->logSecurityEvent('ACCESS_GRANTED', $userId, $operation, $context, [
                'user_level' => $userAccessLevel,
                'required_level' => $requiredLevel
            ]);
            
            return true;
            
        } catch (SecurityException $e) {
            throw $e;
        } catch (Exception $e) {
            $this->logSecurityEvent('ACCESS_ERROR', $userId, $operation, $context, [
                'error' => $e->getMessage()
            ]);
            
            throw new SecurityException(
                'Ошибка проверки прав доступа: ' . $e->getMessage(),
                500,
                'ACCESS_ERROR'
            );
        }
    }
    
    /**
     * Проверка rate limiting для пользователя и операции
     * 
     * @param string $userId - ID пользователя
     * @param string $operation - тип операции
     * @param array $context - контекст запроса
     * @throws SecurityException при превышении лимитов
     */
    public function checkRateLimit($userId, $operation, $context = []) {
        $limit = $this->rateLimitConfig[$operation] ?? self::DEFAULT_RATE_LIMIT;
        $window = self::RATE_LIMIT_WINDOW;
        $currentTime = time();
        $windowStart = $currentTime - $window;
        
        try {
            // Подсчитываем количество запросов в текущем окне
            $sql = "SELECT COUNT(*) as request_count 
                    FROM ozon_access_log 
                    WHERE user_id = :user_id 
                    AND operation = :operation 
                    AND created_at >= FROM_UNIXTIME(:window_start)
                    AND event_type IN ('ACCESS_GRANTED', 'RATE_LIMITED')";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'user_id' => $userId,
                'operation' => $operation,
                'window_start' => $windowStart
            ]);
            
            $result = $stmt->fetch();
            $requestCount = (int)($result['request_count'] ?? 0);
            
            if ($requestCount >= $limit) {
                $this->logSecurityEvent('RATE_LIMITED', $userId, $operation, $context, [
                    'request_count' => $requestCount,
                    'limit' => $limit,
                    'window_seconds' => $window
                ]);
                
                throw new SecurityException(
                    "Превышен лимит запросов для операции {$operation}: {$requestCount}/{$limit} за {$window} секунд",
                    429,
                    'RATE_LIMIT_EXCEEDED'
                );
            }
            
            // Обновляем счетчик запросов
            $this->updateRateLimitCounter($userId, $operation);
            
        } catch (SecurityException $e) {
            throw $e;
        } catch (Exception $e) {
            // В случае ошибки БД не блокируем запрос, но логируем
            $this->logSecurityEvent('RATE_LIMIT_ERROR', $userId, $operation, $context, [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Получение уровня доступа пользователя
     * 
     * @param string $userId - ID пользователя
     * @return int уровень доступа
     */
    private function getUserAccessLevel($userId) {
        try {
            // Проверяем в таблице пользователей Ozon Analytics
            $sql = "SELECT access_level FROM ozon_user_access WHERE user_id = :user_id AND is_active = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['user_id' => $userId]);
            
            $result = $stmt->fetch();
            
            if ($result) {
                return (int)$result['access_level'];
            }
            
            // Если пользователь не найден в специальной таблице, проверяем базовую аутентификацию
            return $this->getDefaultAccessLevel($userId);
            
        } catch (Exception $e) {
            // В случае ошибки возвращаем минимальный уровень доступа
            error_log("Ошибка получения уровня доступа для пользователя {$userId}: " . $e->getMessage());
            return self::ACCESS_LEVEL_NONE;
        }
    }
    
    /**
     * Получение базового уровня доступа для пользователя
     * 
     * @param string $userId - ID пользователя
     * @return int базовый уровень доступа
     */
    private function getDefaultAccessLevel($userId) {
        // Интеграция с существующей системой аутентификации
        // Пока возвращаем базовый уровень чтения для всех аутентифицированных пользователей
        
        if (empty($userId)) {
            return self::ACCESS_LEVEL_NONE;
        }
        
        // Проверяем сессию или другие механизмы аутентификации
        if ($this->isUserAuthenticated($userId)) {
            return self::ACCESS_LEVEL_READ;
        }
        
        return self::ACCESS_LEVEL_NONE;
    }
    
    /**
     * Проверка аутентификации пользователя
     * 
     * @param string $userId - ID пользователя
     * @return bool true если пользователь аутентифицирован
     */
    private function isUserAuthenticated($userId) {
        // Интеграция с существующей системой аутентификации дашборда
        // Проверяем сессию, токены или другие механизмы
        
        // Базовая проверка - если передан user_id, считаем аутентифицированным
        // В реальной системе здесь должна быть проверка сессии/токена
        return !empty($userId);
    }
    
    /**
     * Логирование событий безопасности
     * 
     * @param string $eventType - тип события
     * @param string $userId - ID пользователя
     * @param string $operation - операция
     * @param array $context - контекст
     * @param array $details - дополнительные детали
     */
    public function logSecurityEvent($eventType, $userId, $operation, $context = [], $details = []) {
        try {
            // Логирование в базу данных
            $this->logToDatabase($eventType, $userId, $operation, $context, $details);
            
            // Логирование в файл
            $this->logToFile($eventType, $userId, $operation, $context, $details);
            
        } catch (Exception $e) {
            // Критично важно не потерять логи безопасности
            error_log("Критическая ошибка логирования безопасности: " . $e->getMessage());
            
            // Попытка записи в файл как fallback
            try {
                $this->logToFile($eventType, $userId, $operation, $context, $details);
            } catch (Exception $fileError) {
                error_log("Ошибка записи в файл лога: " . $fileError->getMessage());
            }
        }
    }
    
    /**
     * Логирование в базу данных
     */
    private function logToDatabase($eventType, $userId, $operation, $context, $details) {
        $sql = "INSERT INTO ozon_access_log 
                (event_type, user_id, operation, ip_address, user_agent, context_data, details, created_at) 
                VALUES (:event_type, :user_id, :operation, :ip_address, :user_agent, :context_data, :details, NOW())";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'event_type' => $eventType,
            'user_id' => $userId,
            'operation' => $operation,
            'ip_address' => $context['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $context['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'context_data' => json_encode($context),
            'details' => json_encode($details)
        ]);
    }
    
    /**
     * Логирование в файл
     */
    private function logToFile($eventType, $userId, $operation, $context, $details) {
        $timestamp = date('Y-m-d H:i:s');
        $ipAddress = $context['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $logEntry = sprintf(
            "[%s] %s | User: %s | Operation: %s | IP: %s | Details: %s\n",
            $timestamp,
            $eventType,
            $userId,
            $operation,
            $ipAddress,
            json_encode($details)
        );
        
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Обновление счетчика rate limiting
     */
    private function updateRateLimitCounter($userId, $operation) {
        try {
            $sql = "INSERT INTO ozon_rate_limit_counters 
                    (user_id, operation, request_count, window_start, last_request) 
                    VALUES (:user_id, :operation, 1, FROM_UNIXTIME(:window_start), NOW())
                    ON DUPLICATE KEY UPDATE 
                    request_count = request_count + 1,
                    last_request = NOW()";
            
            $windowStart = time() - (time() % self::RATE_LIMIT_WINDOW);
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'user_id' => $userId,
                'operation' => $operation,
                'window_start' => $windowStart
            ]);
            
        } catch (Exception $e) {
            error_log("Ошибка обновления счетчика rate limiting: " . $e->getMessage());
        }
    }
    
    /**
     * Получение статистики безопасности
     * 
     * @param string $period - период ('hour', 'day', 'week', 'month')
     * @return array статистика
     */
    public function getSecurityStats($period = 'day') {
        $intervals = [
            'hour' => 'INTERVAL 1 HOUR',
            'day' => 'INTERVAL 1 DAY',
            'week' => 'INTERVAL 1 WEEK',
            'month' => 'INTERVAL 1 MONTH'
        ];
        
        $interval = $intervals[$period] ?? $intervals['day'];
        
        try {
            // Общая статистика событий
            $sql = "SELECT 
                        event_type,
                        COUNT(*) as event_count,
                        COUNT(DISTINCT user_id) as unique_users
                    FROM ozon_access_log 
                    WHERE created_at >= NOW() - {$interval}
                    GROUP BY event_type
                    ORDER BY event_count DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $eventStats = $stmt->fetchAll();
            
            // Статистика по операциям
            $sql = "SELECT 
                        operation,
                        COUNT(*) as operation_count,
                        COUNT(DISTINCT user_id) as unique_users
                    FROM ozon_access_log 
                    WHERE created_at >= NOW() - {$interval}
                    AND event_type = 'ACCESS_GRANTED'
                    GROUP BY operation
                    ORDER BY operation_count DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $operationStats = $stmt->fetchAll();
            
            // Топ пользователей по активности
            $sql = "SELECT 
                        user_id,
                        COUNT(*) as total_requests,
                        COUNT(CASE WHEN event_type = 'ACCESS_DENIED' THEN 1 END) as denied_requests,
                        COUNT(CASE WHEN event_type = 'RATE_LIMITED' THEN 1 END) as rate_limited_requests
                    FROM ozon_access_log 
                    WHERE created_at >= NOW() - {$interval}
                    GROUP BY user_id
                    ORDER BY total_requests DESC
                    LIMIT 10";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $userStats = $stmt->fetchAll();
            
            return [
                'period' => $period,
                'events' => $eventStats,
                'operations' => $operationStats,
                'top_users' => $userStats,
                'generated_at' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            throw new SecurityException('Ошибка получения статистики безопасности: ' . $e->getMessage());
        }
    }
    
    /**
     * Управление правами доступа пользователя
     * 
     * @param string $userId - ID пользователя
     * @param int $accessLevel - уровень доступа
     * @param string $adminUserId - ID администратора, выполняющего операцию
     * @return bool успех операции
     */
    public function setUserAccessLevel($userId, $accessLevel, $adminUserId) {
        // Проверяем права администратора
        $this->checkAccess($adminUserId, self::OPERATION_MANAGE_SETTINGS);
        
        try {
            $sql = "INSERT INTO ozon_user_access 
                    (user_id, access_level, granted_by, granted_at, is_active) 
                    VALUES (:user_id, :access_level, :granted_by, NOW(), 1)
                    ON DUPLICATE KEY UPDATE 
                    access_level = :access_level,
                    granted_by = :granted_by,
                    granted_at = NOW(),
                    is_active = 1";
            
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'user_id' => $userId,
                'access_level' => $accessLevel,
                'granted_by' => $adminUserId
            ]);
            
            // Логируем изменение прав
            $this->logSecurityEvent('ACCESS_LEVEL_CHANGED', $adminUserId, self::OPERATION_MANAGE_SETTINGS, [], [
                'target_user' => $userId,
                'new_access_level' => $accessLevel
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            throw new SecurityException('Ошибка изменения прав доступа: ' . $e->getMessage());
        }
    }
    
    /**
     * Инициализация таблиц безопасности
     */
    private function initializeSecurityTables() {
        try {
            // Таблица логов доступа
            $sql = "CREATE TABLE IF NOT EXISTS ozon_access_log (
                id INT PRIMARY KEY AUTO_INCREMENT,
                event_type VARCHAR(50) NOT NULL,
                user_id VARCHAR(100) NOT NULL,
                operation VARCHAR(100) NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                context_data JSON,
                details JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_operation (user_id, operation),
                INDEX idx_event_type (event_type),
                INDEX idx_created_at (created_at)
            )";
            $this->pdo->exec($sql);
            
            // Таблица прав доступа пользователей
            $sql = "CREATE TABLE IF NOT EXISTS ozon_user_access (
                user_id VARCHAR(100) PRIMARY KEY,
                access_level INT NOT NULL DEFAULT 0,
                granted_by VARCHAR(100),
                granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_active BOOLEAN DEFAULT TRUE,
                INDEX idx_access_level (access_level)
            )";
            $this->pdo->exec($sql);
            
            // Таблица счетчиков rate limiting
            $sql = "CREATE TABLE IF NOT EXISTS ozon_rate_limit_counters (
                user_id VARCHAR(100) NOT NULL,
                operation VARCHAR(100) NOT NULL,
                request_count INT DEFAULT 0,
                window_start TIMESTAMP NOT NULL,
                last_request TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, operation, window_start),
                INDEX idx_window_start (window_start)
            )";
            $this->pdo->exec($sql);
            
        } catch (Exception $e) {
            error_log("Ошибка инициализации таблиц безопасности: " . $e->getMessage());
        }
    }
    
    /**
     * Очистка устаревших записей
     * 
     * @param int $daysToKeep - количество дней для хранения логов
     * @return array статистика очистки
     */
    public function cleanupOldRecords($daysToKeep = 90) {
        try {
            $stats = [];
            
            // Очистка старых логов доступа
            $sql = "DELETE FROM ozon_access_log WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['days' => $daysToKeep]);
            $stats['deleted_access_logs'] = $stmt->rowCount();
            
            // Очистка старых счетчиков rate limiting
            $sql = "DELETE FROM ozon_rate_limit_counters WHERE window_start < DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $stats['deleted_rate_counters'] = $stmt->rowCount();
            
            return $stats;
            
        } catch (Exception $e) {
            throw new SecurityException('Ошибка очистки старых записей: ' . $e->getMessage());
        }
    }
}

/**
 * Исключение безопасности
 */
class SecurityException extends Exception {
    private $errorType;
    
    public function __construct($message, $code = 0, $errorType = 'SECURITY_ERROR') {
        parent::__construct($message, $code);
        $this->errorType = $errorType;
    }
    
    public function getErrorType() {
        return $this->errorType;
    }
}
?>