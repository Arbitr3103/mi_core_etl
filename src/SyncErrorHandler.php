<?php
/**
 * SyncErrorHandler - Обработчик ошибок синхронизации
 * 
 * Обеспечивает детальное логирование ошибок, retry логику
 * и graceful degradation при недоступности данных.
 * 
 * Requirements: 3.4, 8.1
 */

class SyncErrorHandler {
    private $logger;
    private $db;
    private $errorLog = [];
    private $maxRetries = 3;
    private $retryDelay = 1; // seconds
    private $exponentialBackoff = true;
    
    /**
     * Конструктор
     * 
     * @param PDO $db Подключение к базе данных
     * @param object|null $logger Логгер
     */
    public function __construct($db, $logger = null) {
        $this->db = $db;
        $this->logger = $logger;
    }
    
    /**
     * Выполняет операцию с retry логикой
     * 
     * @param callable $operation Операция для выполнения
     * @param array $context Контекст операции для логирования
     * @return mixed Результат операции
     * @throws Exception если все попытки неудачны
     */
    public function executeWithRetry($operation, $context = []) {
        $attempts = 0;
        $lastException = null;
        
        while ($attempts < $this->maxRetries) {
            $attempts++;
            
            try {
                $this->log('debug', "Attempt {$attempts}/{$this->maxRetries}", $context);
                
                $result = $operation();
                
                if ($attempts > 1) {
                    $this->log('info', "Operation succeeded on attempt {$attempts}", $context);
                }
                
                return $result;
                
            } catch (Exception $e) {
                $lastException = $e;
                
                $this->log('warning', "Attempt {$attempts} failed: " . $e->getMessage(), array_merge($context, [
                    'exception_type' => get_class($e),
                    'exception_code' => $e->getCode()
                ]));
                
                // Записываем ошибку в лог
                $this->recordError($e, $context, $attempts);
                
                // Если это последняя попытка, не ждем
                if ($attempts >= $this->maxRetries) {
                    break;
                }
                
                // Определяем, стоит ли повторять попытку
                if (!$this->shouldRetry($e)) {
                    $this->log('info', 'Error is not retryable, aborting', $context);
                    break;
                }
                
                // Ждем перед следующей попыткой
                $delay = $this->calculateRetryDelay($attempts);
                $this->log('debug', "Waiting {$delay} seconds before retry", $context);
                sleep($delay);
            }
        }
        
        // Все попытки неудачны
        $this->log('error', "All {$attempts} attempts failed", array_merge($context, [
            'last_error' => $lastException->getMessage()
        ]));
        
        throw new Exception(
            "Operation failed after {$attempts} attempts: " . $lastException->getMessage(),
            0,
            $lastException
        );
    }
    
    /**
     * Обрабатывает ошибку с graceful degradation
     * 
     * @param Exception $e Исключение
     * @param array $context Контекст
     * @param mixed $fallbackValue Значение по умолчанию
     * @return mixed Fallback значение
     */
    public function handleWithFallback($e, $context = [], $fallbackValue = null) {
        $this->log('warning', 'Using fallback due to error: ' . $e->getMessage(), $context);
        
        $this->recordError($e, $context);
        
        return $fallbackValue;
    }
    
    /**
     * Определяет, стоит ли повторять попытку для данной ошибки
     * 
     * @param Exception $e Исключение
     * @return bool true если стоит повторить
     */
    private function shouldRetry($e) {
        // Не повторяем для критических ошибок
        $nonRetryableErrors = [
            'authentication',
            'authorization',
            'invalid_credentials',
            'permission_denied',
            'not_found',
            'invalid_data'
        ];
        
        $errorMessage = strtolower($e->getMessage());
        
        foreach ($nonRetryableErrors as $pattern) {
            if (strpos($errorMessage, $pattern) !== false) {
                return false;
            }
        }
        
        // Повторяем для временных ошибок
        $retryableErrors = [
            'timeout',
            'connection',
            'network',
            'temporary',
            'unavailable',
            'too many requests',
            'rate limit',
            'deadlock',
            'lock wait timeout'
        ];
        
        foreach ($retryableErrors as $pattern) {
            if (strpos($errorMessage, $pattern) !== false) {
                return true;
            }
        }
        
        // По умолчанию повторяем для PDO ошибок
        if ($e instanceof PDOException) {
            // Проверяем код ошибки MySQL
            $retryableCodes = [
                1205, // Lock wait timeout
                1213, // Deadlock
                2002, // Connection refused
                2003, // Can't connect
                2006, // MySQL server has gone away
                2013  // Lost connection
            ];
            
            return in_array($e->getCode(), $retryableCodes);
        }
        
        // По умолчанию повторяем
        return true;
    }
    
    /**
     * Вычисляет задержку перед следующей попыткой
     * 
     * @param int $attempt Номер попытки
     * @return int Задержка в секундах
     */
    private function calculateRetryDelay($attempt) {
        if (!$this->exponentialBackoff) {
            return $this->retryDelay;
        }
        
        // Экспоненциальная задержка: 1, 2, 4, 8, ...
        return min($this->retryDelay * pow(2, $attempt - 1), 30);
    }
    
    /**
     * Записывает ошибку в лог
     * 
     * @param Exception $e Исключение
     * @param array $context Контекст
     * @param int $attempt Номер попытки
     */
    private function recordError($e, $context = [], $attempt = 1) {
        $errorRecord = [
            'timestamp' => date('Y-m-d H:i:s'),
            'attempt' => $attempt,
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context' => $context
        ];
        
        $this->errorLog[] = $errorRecord;
        
        // Сохраняем критические ошибки в БД
        if ($attempt >= $this->maxRetries) {
            $this->saveErrorToDatabase($errorRecord);
        }
    }
    
    /**
     * Сохраняет ошибку в базу данных
     * 
     * @param array $errorRecord Запись об ошибке
     */
    private function saveErrorToDatabase($errorRecord) {
        try {
            // Проверяем наличие таблицы sync_errors
            $this->ensureErrorTableExists();
            
            $sql = "
                INSERT INTO sync_errors (
                    error_type,
                    error_message,
                    error_code,
                    context,
                    attempts,
                    created_at
                ) VALUES (
                    :error_type,
                    :error_message,
                    :error_code,
                    :context,
                    :attempts,
                    NOW()
                )
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':error_type' => $errorRecord['type'],
                ':error_message' => substr($errorRecord['message'], 0, 500),
                ':error_code' => $errorRecord['code'],
                ':context' => json_encode($errorRecord['context'], JSON_UNESCAPED_UNICODE),
                ':attempts' => $errorRecord['attempt']
            ]);
            
        } catch (Exception $e) {
            // Не можем сохранить в БД, только логируем
            $this->log('error', 'Failed to save error to database: ' . $e->getMessage());
        }
    }
    
    /**
     * Создает таблицу для хранения ошибок если не существует
     */
    private function ensureErrorTableExists() {
        $sql = "
            CREATE TABLE IF NOT EXISTS sync_errors (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                error_type VARCHAR(255) NOT NULL,
                error_message TEXT,
                error_code VARCHAR(50),
                context JSON,
                attempts INT DEFAULT 1,
                resolved BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created_at (created_at),
                INDEX idx_resolved (resolved)
            )
        ";
        
        $this->db->exec($sql);
    }
    
    /**
     * Получает все записанные ошибки
     * 
     * @return array Массив ошибок
     */
    public function getErrorLog() {
        return $this->errorLog;
    }
    
    /**
     * Очищает лог ошибок
     */
    public function clearErrorLog() {
        $this->errorLog = [];
    }
    
    /**
     * Получает статистику ошибок
     * 
     * @return array Статистика
     */
    public function getErrorStatistics() {
        $stats = [
            'total_errors' => count($this->errorLog),
            'by_type' => [],
            'retryable' => 0,
            'non_retryable' => 0
        ];
        
        foreach ($this->errorLog as $error) {
            $type = $error['type'];
            
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = 0;
            }
            $stats['by_type'][$type]++;
            
            // Создаем временное исключение для проверки
            try {
                throw new $type($error['message'], $error['code']);
            } catch (Exception $e) {
                if ($this->shouldRetry($e)) {
                    $stats['retryable']++;
                } else {
                    $stats['non_retryable']++;
                }
            }
        }
        
        return $stats;
    }
    
    /**
     * Получает последние ошибки из базы данных
     * 
     * @param int $limit Количество ошибок
     * @param bool $unresolvedOnly Только нерешенные ошибки
     * @return array Массив ошибок
     */
    public function getRecentErrors($limit = 10, $unresolvedOnly = true) {
        try {
            $sql = "
                SELECT *
                FROM sync_errors
                WHERE 1=1
            ";
            
            if ($unresolvedOnly) {
                $sql .= " AND resolved = FALSE";
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT :limit";
            
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to get recent errors: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Помечает ошибку как решенную
     * 
     * @param int $errorId ID ошибки
     * @return bool true если успешно
     */
    public function markErrorAsResolved($errorId) {
        try {
            $sql = "UPDATE sync_errors SET resolved = TRUE WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $errorId]);
            
            return true;
        } catch (Exception $e) {
            $this->log('error', 'Failed to mark error as resolved: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Устанавливает максимальное количество попыток
     * 
     * @param int $retries Количество попыток
     */
    public function setMaxRetries($retries) {
        $this->maxRetries = max(1, (int)$retries);
    }
    
    /**
     * Устанавливает задержку между попытками
     * 
     * @param int $delay Задержка в секундах
     */
    public function setRetryDelay($delay) {
        $this->retryDelay = max(0, (int)$delay);
    }
    
    /**
     * Включает/выключает экспоненциальную задержку
     * 
     * @param bool $enabled true для включения
     */
    public function setExponentialBackoff($enabled) {
        $this->exponentialBackoff = (bool)$enabled;
    }
    
    /**
     * Логирует сообщение
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    private function log($level, $message, $context = []) {
        if ($this->logger && method_exists($this->logger, $level)) {
            $this->logger->$level($message, $context);
        }
    }
}
