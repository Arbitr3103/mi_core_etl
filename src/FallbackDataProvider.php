<?php
/**
 * FallbackDataProvider - Провайдер резервных данных
 * 
 * Обеспечивает получение данных о товарах с использованием кэша
 * и автоматическим созданием временных названий при недоступности API.
 * 
 * Requirements: 3.3, 3.4, 8.4
 */

require_once __DIR__ . '/../config.php';

class FallbackDataProvider {
    private $db;
    private $logger;
    private $cacheEnabled = true;
    private $apiTimeout = 30;
    private $apiRetries = 3;
    
    /**
     * Конструктор
     * 
     * @param PDO $db Подключение к базе данных
     * @param object|null $logger Логгер для записи событий
     */
    public function __construct($db, $logger = null) {
        $this->db = $db;
        $this->logger = $logger;
    }
    
    /**
     * Получает название товара с использованием fallback механизмов
     * 
     * Порядок попыток:
     * 1. Кэш из product_cross_reference
     * 2. API запрос к Ozon
     * 3. Временное название
     * 
     * @param string $productId ID товара
     * @param string $source Источник данных ('ozon', 'inventory', 'analytics')
     * @return string|null Название товара или null
     */
    public function getProductName($productId, $source = 'ozon') {
        $this->log('debug', 'Getting product name', [
            'product_id' => $productId,
            'source' => $source
        ]);
        
        // 1. Попробовать получить из кэша
        if ($this->cacheEnabled) {
            $cachedName = $this->getCachedName($productId);
            if ($cachedName && !$this->isPlaceholderName($cachedName)) {
                $this->log('debug', 'Using cached name', [
                    'product_id' => $productId,
                    'name' => $cachedName
                ]);
                return $cachedName;
            }
        }
        
        // 2. Попробовать получить из API
        try {
            $apiName = $this->fetchFromAPI($productId, $source);
            if ($apiName) {
                // Кэшируем полученное название
                $this->cacheProductName($productId, $apiName);
                
                $this->log('info', 'Retrieved name from API', [
                    'product_id' => $productId,
                    'name' => $apiName
                ]);
                
                return $apiName;
            }
        } catch (Exception $e) {
            $this->log('warning', 'API request failed', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
        }
        
        // 3. Использовать временное название
        $temporaryName = $this->createTemporaryName($productId);
        
        $this->log('info', 'Using temporary name', [
            'product_id' => $productId,
            'name' => $temporaryName
        ]);
        
        return $temporaryName;
    }
    
    /**
     * Получает название из кэша
     * 
     * @param string $productId ID товара
     * @return string|null Кэшированное название или null
     */
    public function getCachedName($productId) {
        try {
            $sql = "
                SELECT cached_name, last_api_sync
                FROM product_cross_reference
                WHERE inventory_product_id = :product_id
                   OR ozon_product_id = :product_id
                   OR analytics_product_id = :product_id
                LIMIT 1
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':product_id' => (string)$productId]);
            $result = $stmt->fetch();
            
            if ($result && !empty($result['cached_name'])) {
                return $result['cached_name'];
            }
            
            return null;
            
        } catch (PDOException $e) {
            $this->log('error', 'Failed to get cached name', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Кэширует название товара
     * 
     * @param string $productId ID товара
     * @param string $productName Название товара
     * @param array $additionalData Дополнительные данные (бренд, категория и т.д.)
     * @return bool true если кэширование успешно
     */
    public function cacheProductName($productId, $productName, $additionalData = []) {
        try {
            $sql = "
                UPDATE product_cross_reference
                SET 
                    cached_name = :product_name,
                    cached_brand = :brand,
                    last_api_sync = NOW(),
                    sync_status = 'synced',
                    updated_at = NOW()
                WHERE inventory_product_id = :product_id
                   OR ozon_product_id = :product_id
                   OR analytics_product_id = :product_id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':product_name' => $productName,
                ':brand' => $additionalData['brand'] ?? null,
                ':product_id' => (string)$productId
            ]);
            
            $rowsAffected = $stmt->rowCount();
            
            if ($rowsAffected > 0) {
                $this->log('debug', 'Product name cached', [
                    'product_id' => $productId,
                    'name' => $productName,
                    'rows_affected' => $rowsAffected
                ]);
                return true;
            }
            
            // Если запись не найдена, создаем новую
            return $this->createCacheEntry($productId, $productName, $additionalData);
            
        } catch (PDOException $e) {
            $this->log('error', 'Failed to cache product name', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Создает новую запись в кэше
     * 
     * @param string $productId ID товара
     * @param string $productName Название товара
     * @param array $additionalData Дополнительные данные
     * @return bool true если создание успешно
     */
    private function createCacheEntry($productId, $productName, $additionalData = []) {
        try {
            $sql = "
                INSERT INTO product_cross_reference (
                    inventory_product_id,
                    ozon_product_id,
                    cached_name,
                    cached_brand,
                    last_api_sync,
                    sync_status
                ) VALUES (
                    :product_id,
                    :product_id,
                    :product_name,
                    :brand,
                    NOW(),
                    'synced'
                )
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':product_id' => (string)$productId,
                ':product_name' => $productName,
                ':brand' => $additionalData['brand'] ?? null
            ]);
            
            $this->log('info', 'Created new cache entry', [
                'product_id' => $productId,
                'name' => $productName
            ]);
            
            return true;
            
        } catch (PDOException $e) {
            $this->log('error', 'Failed to create cache entry', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Получает данные из API
     * 
     * @param string $productId ID товара
     * @param string $source Источник данных
     * @return string|null Название товара или null
     */
    private function fetchFromAPI($productId, $source = 'ozon') {
        if ($source === 'ozon') {
            return $this->fetchFromOzonAPI($productId);
        }
        
        return null;
    }
    
    /**
     * Получает данные из Ozon API
     * 
     * @param string $productId ID товара
     * @return string|null Название товара или null
     */
    private function fetchFromOzonAPI($productId) {
        if (empty(OZON_CLIENT_ID) || empty(OZON_API_KEY)) {
            $this->log('warning', 'Ozon API credentials not configured');
            return null;
        }
        
        $attempts = 0;
        $lastError = null;
        
        while ($attempts < $this->apiRetries) {
            $attempts++;
            
            try {
                $url = OZON_API_BASE_URL . '/v2/product/info';
                
                $data = [
                    'product_id' => (int)$productId
                ];
                
                $headers = [
                    'Client-Id: ' . OZON_CLIENT_ID,
                    'Api-Key: ' . OZON_API_KEY,
                    'Content-Type: application/json'
                ];
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->apiTimeout);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                
                if ($curlError) {
                    throw new Exception("CURL error: {$curlError}");
                }
                
                if ($httpCode !== 200) {
                    throw new Exception("HTTP error: {$httpCode}");
                }
                
                $result = json_decode($response, true);
                
                if (isset($result['result']['name'])) {
                    return $result['result']['name'];
                }
                
                if (isset($result['error'])) {
                    throw new Exception("API error: " . json_encode($result['error']));
                }
                
                return null;
                
            } catch (Exception $e) {
                $lastError = $e;
                $this->log('warning', "API attempt {$attempts} failed", [
                    'product_id' => $productId,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempts < $this->apiRetries) {
                    sleep(1); // Задержка перед повторной попыткой
                }
            }
        }
        
        $this->log('error', 'All API attempts failed', [
            'product_id' => $productId,
            'attempts' => $attempts,
            'last_error' => $lastError ? $lastError->getMessage() : 'Unknown error'
        ]);
        
        return null;
    }
    
    /**
     * Создает временное название для товара
     * 
     * @param string $productId ID товара
     * @return string Временное название
     */
    private function createTemporaryName($productId) {
        return "Товар ID {$productId} (требует обновления)";
    }
    
    /**
     * Проверяет, является ли название заглушкой
     * 
     * @param string $name Название товара
     * @return bool true если это заглушка
     */
    private function isPlaceholderName($name) {
        $placeholderPatterns = [
            '/^Товар Ozon ID/i',
            '/^Товар ID.*требует обновления/i',
            '/^Product ID/i',
            '/^Unknown Product/i'
        ];
        
        foreach ($placeholderPatterns as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Обновляет кэш при успешном API запросе
     * 
     * @param array $products Массив товаров с данными из API
     * @return int Количество обновленных записей
     */
    public function updateCacheFromAPIResponse($products) {
        $updated = 0;
        
        foreach ($products as $product) {
            if (empty($product['id']) || empty($product['name'])) {
                continue;
            }
            
            $additionalData = [
                'brand' => $product['brand'] ?? null,
                'category' => $product['category'] ?? null
            ];
            
            if ($this->cacheProductName($product['id'], $product['name'], $additionalData)) {
                $updated++;
            }
        }
        
        $this->log('info', 'Cache updated from API response', [
            'total_products' => count($products),
            'updated' => $updated
        ]);
        
        return $updated;
    }
    
    /**
     * Получает статистику кэша
     * 
     * @return array Статистика кэша
     */
    public function getCacheStatistics() {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN cached_name IS NOT NULL AND cached_name != '' THEN 1 ELSE 0 END) as cached,
                    SUM(CASE WHEN cached_name LIKE 'Товар%ID%' THEN 1 ELSE 0 END) as placeholders,
                    AVG(TIMESTAMPDIFF(HOUR, last_api_sync, NOW())) as avg_cache_age_hours
                FROM product_cross_reference
            ";
            
            $stmt = $this->db->query($sql);
            $stats = $stmt->fetch();
            
            return [
                'total_entries' => (int)$stats['total'],
                'cached_names' => (int)$stats['cached'],
                'placeholder_names' => (int)$stats['placeholders'],
                'real_names' => (int)$stats['cached'] - (int)$stats['placeholders'],
                'avg_cache_age_hours' => round((float)$stats['avg_cache_age_hours'], 2),
                'cache_hit_rate' => $stats['total'] > 0 
                    ? round((($stats['cached'] - $stats['placeholders']) / $stats['total']) * 100, 2)
                    : 0
            ];
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to get cache statistics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Очищает устаревший кэш
     * 
     * @param int $maxAgeHours Максимальный возраст кэша в часах
     * @return int Количество очищенных записей
     */
    public function clearStaleCache($maxAgeHours = 168) { // 7 дней по умолчанию
        try {
            $sql = "
                UPDATE product_cross_reference
                SET sync_status = 'pending'
                WHERE last_api_sync < DATE_SUB(NOW(), INTERVAL :max_age HOUR)
                  AND sync_status = 'synced'
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':max_age' => $maxAgeHours]);
            
            $cleared = $stmt->rowCount();
            
            $this->log('info', 'Stale cache cleared', [
                'max_age_hours' => $maxAgeHours,
                'cleared' => $cleared
            ]);
            
            return $cleared;
            
        } catch (Exception $e) {
            $this->log('error', 'Failed to clear stale cache', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Включает или отключает кэширование
     * 
     * @param bool $enabled true для включения кэша
     */
    public function setCacheEnabled($enabled) {
        $this->cacheEnabled = (bool)$enabled;
        $this->log('info', 'Cache enabled status changed', [
            'enabled' => $this->cacheEnabled
        ]);
    }
    
    /**
     * Устанавливает таймаут для API запросов
     * 
     * @param int $timeout Таймаут в секундах
     */
    public function setApiTimeout($timeout) {
        $this->apiTimeout = max(5, (int)$timeout);
        $this->log('info', 'API timeout updated', [
            'timeout' => $this->apiTimeout
        ]);
    }
    
    /**
     * Логирует сообщение
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param array $context Контекст
     */
    private function log($level, $message, $context = []) {
        if ($this->logger) {
            $this->logger->$level($message, $context);
        }
    }
}
