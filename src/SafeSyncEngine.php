<?php
/**
 * SafeSyncEngine - Надежный движок синхронизации без ошибок
 * 
 * Обеспечивает безопасную синхронизацию данных между различными источниками
 * с проверкой типов данных, пакетной обработкой и детальным логированием.
 * 
 * Requirements: 3.1, 3.4, 8.1
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DataTypeNormalizer.php';
require_once __DIR__ . '/FallbackDataProvider.php';

class SafeSyncEngine {
    private $db;
    private $logger;
    private $dataTypeNormalizer;
    private $fallbackProvider;
    private $batchSize = 10;
    private $maxRetries = 3;
    private $retryDelay = 1; // seconds
    
    /**
     * Конструктор
     * 
     * @param PDO $db Подключение к базе данных
     * @param Logger|null $logger Логгер для записи событий
     */
    public function __construct($db = null, $logger = null) {
        $this->db = $db ?? $this->createDatabaseConnection();
        $this->logger = $logger ?? new SimpleLogger();
        $this->dataTypeNormalizer = new DataTypeNormalizer();
        $this->fallbackProvider = new FallbackDataProvider($this->db, $this->logger);
    }
    
    /**
     * Создает подключение к базе данных
     * 
     * @return PDO
     * @throws Exception если не удалось подключиться
     */
    private function createDatabaseConnection() {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            $this->logger->info('Database connection established successfully');
            
            return $pdo;
        } catch (PDOException $e) {
            $this->logger->error('Database connection failed: ' . $e->getMessage());
            throw new Exception('Failed to connect to database: ' . $e->getMessage());
        }
    }
    
    /**
     * Основной метод синхронизации названий товаров
     * 
     * @param int|null $limit Максимальное количество товаров для синхронизации
     * @return array Результаты синхронизации
     */
    public function syncProductNames($limit = null) {
        $this->logger->info('Starting product names synchronization', ['limit' => $limit]);
        
        $results = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        try {
            // Шаг 1: Безопасный поиск товаров, требующих синхронизации
            $products = $this->findProductsNeedingSync($limit);
            $results['total'] = count($products);
            
            $this->logger->info('Found products needing sync', ['count' => $results['total']]);
            
            if (empty($products)) {
                $this->logger->info('No products need synchronization');
                return $results;
            }
            
            // Шаг 2: Пакетная обработка для избежания timeout
            $batches = array_chunk($products, $this->batchSize);
            $batchNumber = 0;
            
            foreach ($batches as $batch) {
                $batchNumber++;
                $this->logger->info("Processing batch {$batchNumber}/{" . count($batches) . "}");
                
                $batchResults = $this->processBatch($batch);
                
                $results['success'] += $batchResults['success'];
                $results['failed'] += $batchResults['failed'];
                $results['skipped'] += $batchResults['skipped'];
                $results['errors'] = array_merge($results['errors'], $batchResults['errors']);
            }
            
            $this->logger->info('Synchronization completed', $results);
            
        } catch (Exception $e) {
            $this->handleSyncError($e, $results);
        }
        
        return $results;
    }
    
    /**
     * Находит товары, требующие синхронизации
     * 
     * Использует безопасный SQL запрос без проблем с DISTINCT и ORDER BY
     * 
     * @param int|null $limit Максимальное количество товаров
     * @return array Массив товаров для синхронизации
     */
    private function findProductsNeedingSync($limit = null) {
        try {
            // Безопасный запрос без ORDER BY проблем
            $sql = "
                SELECT DISTINCT 
                    pcr.id as cross_ref_id,
                    pcr.inventory_product_id,
                    pcr.ozon_product_id,
                    pcr.sku_ozon,
                    pcr.sync_status,
                    dp.name as current_name
                FROM product_cross_reference pcr
                LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
                WHERE (
                    dp.name LIKE 'Товар Ozon ID%' 
                    OR dp.name IS NULL 
                    OR pcr.sync_status = 'pending'
                )
                AND pcr.sync_status != 'failed'
            ";
            
            if ($limit !== null) {
                $sql .= " LIMIT :limit";
            }
            
            $stmt = $this->db->prepare($sql);
            
            if ($limit !== null) {
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            $products = $stmt->fetchAll();
            
            $this->logger->debug('Products query executed', [
                'count' => count($products),
                'limit' => $limit
            ]);
            
            return $products;
            
        } catch (PDOException $e) {
            $this->logger->error('Failed to find products needing sync', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            throw new Exception('Database query failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Обрабатывает пакет товаров
     * 
     * @param array $batch Массив товаров для обработки
     * @return array Результаты обработки пакета
     */
    private function processBatch($batch) {
        $results = [
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        foreach ($batch as $product) {
            try {
                $result = $this->processProduct($product);
                
                if ($result['status'] === 'success') {
                    $results['success']++;
                } elseif ($result['status'] === 'skipped') {
                    $results['skipped']++;
                } else {
                    $results['failed']++;
                    $results['errors'][] = $result['error'];
                }
                
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'product_id' => $product['inventory_product_id'] ?? 'unknown',
                    'error' => $e->getMessage()
                ];
                
                $this->logger->error('Failed to process product', [
                    'product' => $product,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $results;
    }
    
    /**
     * Обрабатывает один товар
     * 
     * @param array $product Данные товара
     * @return array Результат обработки
     */
    private function processProduct($product) {
        $productId = $product['inventory_product_id'];
        
        $this->logger->debug('Processing product', ['product_id' => $productId]);
        
        // Проверка типов данных перед обработкой
        if (!$this->validateProductData($product)) {
            return [
                'status' => 'skipped',
                'reason' => 'Invalid product data'
            ];
        }
        
        // Нормализация типов данных
        $normalizedProduct = $this->dataTypeNormalizer->normalizeProduct($product);
        
        // Получение названия товара с использованием fallback механизма
        $productName = $this->fallbackProvider->getProductName(
            $normalizedProduct['ozon_product_id'] ?? $normalizedProduct['inventory_product_id']
        );
        
        if (!$productName) {
            return [
                'status' => 'failed',
                'error' => 'Could not retrieve product name'
            ];
        }
        
        // Обновление данных в базе с retry логикой
        $updated = $this->updateProductWithRetry($normalizedProduct, $productName);
        
        if ($updated) {
            return ['status' => 'success'];
        } else {
            return [
                'status' => 'failed',
                'error' => 'Failed to update product in database'
            ];
        }
    }
    
    /**
     * Валидирует данные товара перед обработкой
     * 
     * @param array $product Данные товара
     * @return bool true если данные валидны
     */
    private function validateProductData($product) {
        // Проверяем наличие обязательных полей
        if (empty($product['inventory_product_id'])) {
            $this->logger->warning('Product missing inventory_product_id', ['product' => $product]);
            return false;
        }
        
        // Проверяем типы данных
        if (!$this->dataTypeNormalizer->isValidProductId($product['inventory_product_id'])) {
            $this->logger->warning('Invalid product ID format', [
                'product_id' => $product['inventory_product_id']
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Обновляет товар в базе данных с retry логикой
     * 
     * @param array $product Нормализованные данные товара
     * @param string $productName Название товара
     * @return bool true если обновление успешно
     */
    private function updateProductWithRetry($product, $productName) {
        $attempts = 0;
        $lastError = null;
        
        while ($attempts < $this->maxRetries) {
            $attempts++;
            
            try {
                return $this->updateProduct($product, $productName);
            } catch (Exception $e) {
                $lastError = $e;
                $this->logger->warning("Update attempt {$attempts} failed", [
                    'product_id' => $product['inventory_product_id'],
                    'error' => $e->getMessage()
                ]);
                
                if ($attempts < $this->maxRetries) {
                    sleep($this->retryDelay);
                }
            }
        }
        
        $this->logger->error('All update attempts failed', [
            'product_id' => $product['inventory_product_id'],
            'attempts' => $attempts,
            'last_error' => $lastError->getMessage()
        ]);
        
        return false;
    }
    
    /**
     * Обновляет товар в базе данных
     * 
     * @param array $product Данные товара
     * @param string $productName Название товара
     * @return bool true если обновление успешно
     */
    private function updateProduct($product, $productName) {
        $this->db->beginTransaction();
        
        try {
            // Обновляем cross_reference таблицу
            $sql = "
                UPDATE product_cross_reference
                SET 
                    cached_name = :product_name,
                    last_api_sync = NOW(),
                    sync_status = 'synced',
                    updated_at = NOW()
                WHERE id = :cross_ref_id
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':product_name' => $productName,
                ':cross_ref_id' => $product['cross_ref_id']
            ]);
            
            // Обновляем dim_products если есть связь
            if (!empty($product['sku_ozon'])) {
                $sql = "
                    UPDATE dim_products
                    SET 
                        name = :product_name,
                        updated_at = NOW()
                    WHERE sku_ozon = :sku_ozon
                ";
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':product_name' => $productName,
                    ':sku_ozon' => $product['sku_ozon']
                ]);
            }
            
            $this->db->commit();
            
            $this->logger->debug('Product updated successfully', [
                'product_id' => $product['inventory_product_id'],
                'name' => $productName
            ]);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            $this->logger->error('Failed to update product', [
                'product_id' => $product['inventory_product_id'],
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Обрабатывает ошибки синхронизации
     * 
     * @param Exception $e Исключение
     * @param array &$results Массив результатов для обновления
     */
    private function handleSyncError($e, &$results) {
        $this->logger->error('Synchronization error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        $results['errors'][] = [
            'type' => 'critical',
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Устанавливает размер пакета для обработки
     * 
     * @param int $size Размер пакета
     */
    public function setBatchSize($size) {
        $this->batchSize = max(1, (int)$size);
        $this->logger->info('Batch size updated', ['batch_size' => $this->batchSize]);
    }
    
    /**
     * Устанавливает максимальное количество повторных попыток
     * 
     * @param int $retries Количество попыток
     */
    public function setMaxRetries($retries) {
        $this->maxRetries = max(1, (int)$retries);
        $this->logger->info('Max retries updated', ['max_retries' => $this->maxRetries]);
    }
    
    /**
     * Получает статистику синхронизации
     * 
     * @return array Статистика
     */
    public function getSyncStatistics() {
        try {
            $sql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
                    SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed,
                    MAX(last_api_sync) as last_sync_time
                FROM product_cross_reference
            ";
            
            $stmt = $this->db->query($sql);
            $stats = $stmt->fetch();
            
            return [
                'total_products' => (int)$stats['total'],
                'synced' => (int)$stats['synced'],
                'pending' => (int)$stats['pending'],
                'failed' => (int)$stats['failed'],
                'last_sync_time' => $stats['last_sync_time'],
                'sync_percentage' => $stats['total'] > 0 
                    ? round(($stats['synced'] / $stats['total']) * 100, 2) 
                    : 0
            ];
            
        } catch (Exception $e) {
            $this->logger->error('Failed to get sync statistics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}

/**
 * SimpleLogger - Простой логгер для записи событий
 */
class SimpleLogger {
    private $logFile;
    private $logLevel;
    
    private $levels = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3
    ];
    
    public function __construct($logFile = null, $logLevel = 'INFO') {
        $this->logFile = $logFile ?? LOG_DIR . '/sync_engine_' . date('Y-m-d') . '.log';
        $this->logLevel = $logLevel;
        
        // Создаем директорию для логов если не существует
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    public function debug($message, $context = []) {
        $this->log('DEBUG', $message, $context);
    }
    
    public function info($message, $context = []) {
        $this->log('INFO', $message, $context);
    }
    
    public function warning($message, $context = []) {
        $this->log('WARNING', $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log('ERROR', $message, $context);
    }
    
    private function log($level, $message, $context = []) {
        if ($this->levels[$level] < $this->levels[$this->logLevel]) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
        
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
}
