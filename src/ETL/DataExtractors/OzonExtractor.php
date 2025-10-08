<?php

namespace MDM\ETL\DataExtractors;

use Exception;
use PDO;

/**
 * Экстрактор данных из Ozon API
 * Извлекает данные о товарах из API Ozon для последующей обработки в MDM системе
 */
class OzonExtractor extends BaseExtractor
{
    private string $clientId;
    private string $apiKey;
    private string $baseUrl;
    private array $rateLimits;
    private float $lastRequestTime = 0;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        parent::__construct($pdo, $config);
        
        $this->clientId = $config['client_id'] ?? '';
        $this->apiKey = $config['api_key'] ?? '';
        $this->baseUrl = $config['base_url'] ?? 'https://api-seller.ozon.ru';
        $this->rateLimits = $config['rate_limits'] ?? [
            'requests_per_second' => 10,
            'delay_between_requests' => 0.1
        ];
        
        if (empty($this->clientId) || empty($this->apiKey)) {
            throw new Exception('Ozon API credentials are required');
        }
    }
    
    /**
     * Извлечение данных товаров из Ozon API
     * 
     * @param array $filters Фильтры для извлечения
     * @return array Данные товаров
     */
    public function extract(array $filters = []): array
    {
        $this->log('INFO', 'Начало извлечения данных из Ozon API', $filters);
        
        try {
            $products = $this->executeWithRetry(function() use ($filters) {
                return $this->fetchProducts($filters);
            });
            
            $normalizedProducts = [];
            foreach ($products as $product) {
                $normalizedProducts[] = $this->normalizeOzonProduct($product);
            }
            
            if (!$this->validateData($normalizedProducts)) {
                throw new Exception('Валидация извлеченных данных не прошла');
            }
            
            $this->log('INFO', 'Успешно извлечено товаров', ['count' => count($normalizedProducts)]);
            
            return $normalizedProducts;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка извлечения данных из Ozon API', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            throw $e;
        }
    }
    
    /**
     * Проверка доступности Ozon API
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->makeRequest('POST', '/v2/product/list', [
                'limit' => 1,
                'last_id' => '',
                'filter' => []
            ]);
            
            return isset($response['result']);
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Ozon API недоступен', ['error' => $e->getMessage()]);
            return false;
        }
    }
    
    /**
     * Получение имени источника
     * 
     * @return string
     */
    public function getSourceName(): string
    {
        return 'ozon';
    }
    
    /**
     * Получение списка товаров из Ozon API
     * 
     * @param array $filters Фильтры
     * @return array
     */
    private function fetchProducts(array $filters): array
    {
        $allProducts = [];
        $lastId = '';
        $limit = $filters['limit'] ?? 1000;
        $maxPages = $filters['max_pages'] ?? 10;
        $currentPage = 0;
        
        do {
            $requestData = [
                'limit' => min($limit, 1000), // Ozon API лимит
                'last_id' => $lastId,
                'filter' => $this->buildOzonFilters($filters)
            ];
            
            $response = $this->makeRequest('POST', '/v2/product/list', $requestData);
            
            if (!isset($response['result']['items'])) {
                break;
            }
            
            $products = $response['result']['items'];
            $allProducts = array_merge($allProducts, $products);
            
            // Получаем last_id для следующей страницы
            $lastId = $response['result']['last_id'] ?? '';
            $currentPage++;
            
            $this->log('INFO', 'Получена страница товаров', [
                'page' => $currentPage,
                'products_count' => count($products),
                'total_products' => count($allProducts)
            ]);
            
        } while (!empty($lastId) && $currentPage < $maxPages && count($allProducts) < $limit);
        
        return $allProducts;
    }
    
    /**
     * Построение фильтров для Ozon API
     * 
     * @param array $filters Входные фильтры
     * @return array Фильтры для Ozon API
     */
    private function buildOzonFilters(array $filters): array
    {
        $ozonFilters = [];
        
        if (!empty($filters['visibility'])) {
            $ozonFilters['visibility'] = $filters['visibility'];
        }
        
        if (!empty($filters['offer_id'])) {
            $ozonFilters['offer_id'] = $filters['offer_id'];
        }
        
        if (!empty($filters['product_id'])) {
            $ozonFilters['product_id'] = $filters['product_id'];
        }
        
        return $ozonFilters;
    }
    
    /**
     * Нормализация данных товара из Ozon
     * 
     * @param array $ozonProduct Данные товара из Ozon API
     * @return array Нормализованные данные
     */
    private function normalizeOzonProduct(array $ozonProduct): array
    {
        // Получаем дополнительную информацию о товаре если нужно
        $productInfo = $this->getProductInfo($ozonProduct['product_id'] ?? '');
        
        return [
            'external_sku' => $ozonProduct['offer_id'] ?? $ozonProduct['product_id'] ?? '',
            'source' => $this->getSourceName(),
            'source_name' => $this->sanitizeString($productInfo['name'] ?? $ozonProduct['name'] ?? ''),
            'source_brand' => $this->sanitizeString($productInfo['brand'] ?? ''),
            'source_category' => $this->sanitizeString($productInfo['category'] ?? ''),
            'price' => $this->sanitizePrice($productInfo['price'] ?? $ozonProduct['price'] ?? 0),
            'description' => $this->sanitizeString($productInfo['description'] ?? ''),
            'attributes' => $this->extractOzonAttributes($ozonProduct, $productInfo),
            'extracted_at' => date('Y-m-d H:i:s'),
            'raw_data' => json_encode($ozonProduct, JSON_UNESCAPED_UNICODE)
        ];
    }
    
    /**
     * Получение детальной информации о товаре
     * 
     * @param string $productId ID товара
     * @return array Информация о товаре
     */
    private function getProductInfo(string $productId): array
    {
        if (empty($productId)) {
            return [];
        }
        
        try {
            $response = $this->makeRequest('POST', '/v2/product/info', [
                'product_id' => intval($productId)
            ]);
            
            return $response['result'] ?? [];
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Не удалось получить детальную информацию о товаре', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Извлечение атрибутов товара из данных Ozon
     * 
     * @param array $ozonProduct Основные данные товара
     * @param array $productInfo Детальная информация
     * @return array Атрибуты товара
     */
    private function extractOzonAttributes(array $ozonProduct, array $productInfo): array
    {
        $attributes = [];
        
        // Основные атрибуты
        if (!empty($ozonProduct['barcode'])) {
            $attributes['barcode'] = $ozonProduct['barcode'];
        }
        
        if (!empty($productInfo['weight'])) {
            $attributes['weight'] = $productInfo['weight'];
        }
        
        if (!empty($productInfo['dimensions'])) {
            $attributes['dimensions'] = $productInfo['dimensions'];
        }
        
        // Атрибуты из массива attributes
        if (!empty($productInfo['attributes'])) {
            foreach ($productInfo['attributes'] as $attr) {
                if (!empty($attr['attribute_name']) && !empty($attr['value'])) {
                    $key = $this->sanitizeString($attr['attribute_name']);
                    $value = $this->sanitizeString($attr['value']);
                    
                    if (!empty($key) && !empty($value)) {
                        $attributes[$key] = $value;
                    }
                }
            }
        }
        
        // Статус товара
        if (!empty($ozonProduct['visible'])) {
            $attributes['visible'] = $ozonProduct['visible'];
        }
        
        if (!empty($ozonProduct['status'])) {
            $attributes['status'] = $ozonProduct['status'];
        }
        
        return $this->sanitizeAttributes($attributes);
    }
    
    /**
     * Выполнение HTTP запроса к Ozon API
     * 
     * @param string $method HTTP метод
     * @param string $endpoint Эндпоинт API
     * @param array $data Данные запроса
     * @return array Ответ API
     * @throws Exception
     */
    private function makeRequest(string $method, string $endpoint, array $data = []): array
    {
        $this->enforceRateLimit();
        
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Client-Id: ' . $this->clientId,
            'Api-Key: ' . $this->apiKey
        ];
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'MDM-ETL/1.0'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception('cURL error: ' . $curlError);
        }
        
        $this->handleHttpErrors($httpCode, $response);
        
        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON decode error: ' . json_last_error_msg());
        }
        
        return $decodedResponse;
    }
    
    /**
     * Обработка HTTP ошибок
     * 
     * @param int $httpCode HTTP код
     * @param string $response Тело ответа
     * @throws Exception
     */
    private function handleHttpErrors(int $httpCode, string $response): void
    {
        switch ($httpCode) {
            case 200:
            case 201:
                return;
                
            case 401:
                throw new Exception('Ozon API authentication failed');
                
            case 429:
                throw new Exception('Ozon API rate limit exceeded');
                
            case 400:
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['message'] ?? 'Bad request';
                throw new Exception('Ozon API error: ' . $errorMessage);
                
            case 503:
                throw new Exception('Ozon API temporarily unavailable');
                
            default:
                throw new Exception('Ozon API HTTP error: ' . $httpCode);
        }
    }
    
    /**
     * Применение ограничений скорости запросов
     */
    private function enforceRateLimit(): void
    {
        $currentTime = microtime(true);
        $timeSinceLastRequest = $currentTime - $this->lastRequestTime;
        
        $minDelay = $this->rateLimits['delay_between_requests'];
        
        if ($timeSinceLastRequest < $minDelay) {
            $sleepTime = $minDelay - $timeSinceLastRequest;
            usleep($sleepTime * 1000000);
        }
        
        $this->lastRequestTime = microtime(true);
    }
}