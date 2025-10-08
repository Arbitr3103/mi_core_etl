<?php

namespace MDM\ETL\DataExtractors;

use Exception;
use PDO;

/**
 * Базовый класс для всех экстракторов данных
 * Предоставляет общую функциональность для извлечения данных из источников
 */
abstract class BaseExtractor
{
    protected PDO $pdo;
    protected array $config;
    protected int $maxRetries;
    protected float $retryDelay;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->retryDelay = $config['retry_delay'] ?? 1.0;
    }
    
    /**
     * Извлечение данных из источника
     * 
     * @param array $filters Фильтры для извлечения данных
     * @return array Извлеченные данные
     */
    abstract public function extract(array $filters = []): array;
    
    /**
     * Проверка доступности источника данных
     * 
     * @return bool
     */
    abstract public function isAvailable(): bool;
    
    /**
     * Получение имени источника
     * 
     * @return string
     */
    abstract public function getSourceName(): string;
    
    /**
     * Выполнение операции с повторными попытками
     * 
     * @param callable $operation Операция для выполнения
     * @return mixed Результат операции
     * @throws Exception
     */
    protected function executeWithRetry(callable $operation)
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $operation();
            } catch (Exception $e) {
                $lastException = $e;
                
                if ($attempt < $this->maxRetries) {
                    $this->logRetry($attempt, $e->getMessage());
                    sleep($this->retryDelay * $attempt); // Экспоненциальная задержка
                }
            }
        }
        
        throw new Exception(
            "Операция не удалась после {$this->maxRetries} попыток. Последняя ошибка: " . 
            $lastException->getMessage(),
            0,
            $lastException
        );
    }
    
    /**
     * Логирование повторной попытки
     * 
     * @param int $attempt Номер попытки
     * @param string $error Сообщение об ошибке
     */
    protected function logRetry(int $attempt, string $error): void
    {
        $source = $this->getSourceName();
        $message = "[$source] Попытка $attempt/{$this->maxRetries} не удалась: $error";
        
        error_log($message);
        
        // Сохраняем в БД если возможно
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO etl_logs (source, level, message, created_at) VALUES (?, 'WARNING', ?, NOW())"
            );
            $stmt->execute([$source, $message]);
        } catch (Exception $e) {
            // Игнорируем ошибки логирования
        }
    }
    
    /**
     * Логирование сообщения
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param array $context Дополнительный контекст
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $source = $this->getSourceName();
        $fullMessage = $message;
        
        if (!empty($context)) {
            $fullMessage .= ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }
        
        error_log("[$source] [$level] $fullMessage");
        
        // Сохраняем в БД
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO etl_logs (source, level, message, context, created_at) VALUES (?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $source, 
                $level, 
                $message, 
                !empty($context) ? json_encode($context) : null
            ]);
        } catch (Exception $e) {
            // Игнорируем ошибки логирования
        }
    }
    
    /**
     * Валидация извлеченных данных
     * 
     * @param array $data Данные для валидации
     * @return bool
     */
    protected function validateData(array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        
        // Базовая валидация - проверяем что каждый элемент содержит обязательные поля
        foreach ($data as $item) {
            if (!is_array($item)) {
                return false;
            }
            
            // Проверяем наличие базовых полей
            if (!isset($item['external_sku']) || empty($item['external_sku'])) {
                return false;
            }
            
            if (!isset($item['name']) || empty($item['name'])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Нормализация данных товара
     * 
     * @param array $rawData Сырые данные
     * @return array Нормализованные данные
     */
    protected function normalizeProductData(array $rawData): array
    {
        return [
            'external_sku' => $this->sanitizeString($rawData['external_sku'] ?? ''),
            'source' => $this->getSourceName(),
            'source_name' => $this->sanitizeString($rawData['name'] ?? ''),
            'source_brand' => $this->sanitizeString($rawData['brand'] ?? ''),
            'source_category' => $this->sanitizeString($rawData['category'] ?? ''),
            'price' => $this->sanitizePrice($rawData['price'] ?? 0),
            'description' => $this->sanitizeString($rawData['description'] ?? ''),
            'attributes' => $this->sanitizeAttributes($rawData['attributes'] ?? []),
            'extracted_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Очистка строковых данных
     * 
     * @param string $value Значение для очистки
     * @return string
     */
    protected function sanitizeString(string $value): string
    {
        // Удаляем лишние пробелы
        $value = trim($value);
        
        // Удаляем множественные пробелы
        $value = preg_replace('/\s+/', ' ', $value);
        
        // Удаляем управляющие символы
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        
        return $value;
    }
    
    /**
     * Очистка цены
     * 
     * @param mixed $price Цена для очистки
     * @return float
     */
    protected function sanitizePrice($price): float
    {
        if (is_string($price)) {
            // Удаляем все кроме цифр, точки и запятой
            $price = preg_replace('/[^\d.,]/', '', $price);
            // Заменяем запятую на точку
            $price = str_replace(',', '.', $price);
        }
        
        return max(0, floatval($price));
    }
    
    /**
     * Очистка атрибутов
     * 
     * @param array $attributes Атрибуты для очистки
     * @return array
     */
    protected function sanitizeAttributes(array $attributes): array
    {
        $cleaned = [];
        
        foreach ($attributes as $key => $value) {
            $cleanKey = $this->sanitizeString($key);
            
            if (is_string($value)) {
                $cleanValue = $this->sanitizeString($value);
            } elseif (is_numeric($value)) {
                $cleanValue = $value;
            } elseif (is_array($value)) {
                $cleanValue = $this->sanitizeAttributes($value);
            } else {
                $cleanValue = $value;
            }
            
            if (!empty($cleanKey) && !empty($cleanValue)) {
                $cleaned[$cleanKey] = $cleanValue;
            }
        }
        
        return $cleaned;
    }
}