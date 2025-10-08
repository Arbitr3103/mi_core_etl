<?php

namespace MDM\ETL\DataExtractors;

use Exception;
use PDO;

/**
 * Экстрактор данных из Wildberries API
 * Извлекает данные о товарах из API Wildberries для последующей обработки в MDM системе
 */
class WildberriesExtractor extends BaseExtractor
{
    private string $apiToken;
    private array $baseUrls;
    private array $rateLimits;
    private float $lastRequestTime = 0;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        parent::__construct($pdo, $config);
        
        $this->apiToken = $config['api_token'] ?? '';
        $this->baseUrls = $config['base_urls'] ?? [
            'suppliers' => 'https://suppliers-api.wildberries.ru',
            'content' => 'https://content-api.wildberries.ru',
            'statistics' => 'https://statistics-api.wildberries.ru'
        ];
        $this->rateLimits = $config['rate_limits'] ?? [
            'requests_per_minute' => 100,
            'delay_between_requests' => 0.6
        ];
        
        if (empty($this->apiToken)) {
            throw new Exception('Wildberries API token is required');
        }
    }
    
    /**
     * Извлечение данных товаров из Wildberries API
     * 
     * @param array $filters Фильтры для извлечения
     * @return array Данные товаров
     */
    public function extract(array $filters = []): array
    {
        $this->log('INFO', 'Начало извлечения данных из Wildberries API', $filters);
        
        try {
            $products = $this->executeWithRetry(function() use ($filters) {
                return $this->fetchProducts($filters);
            });
            
            $normalizedProducts = [];
            foreach ($products as $product) {
                $normalizedProducts[] = $this->normalizeWbProduct($product);
            }
            
            if (!$this->validateData($normalizedProducts)) {
                throw new Exception('Валидация извлеченных данных не прошла');
            }
            
            $this->log('INFO', 'Успешно извлечено товаров', ['count' => count($normalizedProducts)]);
            
            return $normalizedProducts;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка извлечения данных из Wildberries API', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            throw $e;
        }
    }
    
    /**
     * Проверка доступности Wildberries API
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->makeRequest('GET', 'content', '/content/v1/cards/cursor/list', [
                'sort' => [
                    'cursor' => [
                        'limit' => 1
                    ]
                ]
            ]);
            
            return isset($response['data']);
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Wildberries API недоступен', ['error' => $e->getMessage()]);
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
        return 'wildberries';
    }
    
    /**
     * Получение списка товаров из Wildberries API
     * 
     * @param array $filters Фильтры
     * @return array
     */
    private function fetchProducts(array $filters): array
    {
        $allProducts = [];
        $limit = $filters['limit'] ?? 1000;
        $maxPages = $filters['max_pages'] ?? 10;
        $currentPage = 0;
        $cursor = null;
        
        do {
            $requestData = [
                'sort' => [
                    'cursor' => [
                        'limit' => min($limit, 100) // WB API лимит
                    ]
                ]
            ];
            
            if ($cursor) {
                $requestData['sort']['cursor']['updatedAt'] = $cursor['updatedAt'];
                $requestData['sort']['cursor']['nmID'] = $cursor['nmID'];
            }
            
            // Добавляем фильтры если есть
            if (!empty($filters['updated_after'])) {
                $requestData['filter'] = [
                    'withPhoto' => -1 // Все товары
                ];
            }
            
            $response = $this->makeRequest('POST', 'content', '/content/v1/cards/cursor/list', $requestData);
            
            if (!isset($response['data']['cards'])) {
                break;
            }
            
            $products = $response['data']['cards'];
            $allProducts = array_merge($allProducts, $products);
            
            // Получаем cursor для следующей страницы
            $cursor = $response['data']['cursor'] ?? null;
            $currentPage++;
            
            $this->log('INFO', 'Получена страница товаров', [
                'page' => $currentPage,
                'products_count' => count($products),
                'total_products' => count($allProducts)
            ]);
            
        } while ($cursor && $currentPage < $maxPages && count($allProducts) < $limit);
        
        return $allProducts;
    }
    
    /**
     * Нормализация данных товара из Wildberries
     * 
     * @param array $wbProduct Данные товара из WB API
     * @return array Нормализованные данные
     */
    private function normalizeWbProduct(array $wbProduct): array
    {
        // Получаем основную информацию о товаре
        $nmID = $wbProduct['nmID'] ?? '';
        $vendorCode = $wbProduct['vendorCode'] ?? '';
        
        // Используем vendorCode как основной SKU, если есть, иначе nmID
        $externalSku = !empty($vendorCode) ? $vendorCode : $nmID;
        
        return [
            'external_sku' => $externalSku,
            'source' => $this->getSourceName(),
            'source_name' => $this->sanitizeString($wbProduct['object'] ?? ''),
            'source_brand' => $this->sanitizeString($wbProduct['brand'] ?? ''),
            'source_category' => $this->extractWbCategory($wbProduct),
            'price' => $this->extractWbPrice($wbProduct),
            'description' => $this->sanitizeString($wbProduct['description'] ?? ''),
            'attributes' => $this->extractWbAttributes($wbProduct),
            'extracted_at' => date('Y-m-d H:i:s'),
            'raw_data' => json_encode($wbProduct, JSON_UNESCAPED_UNICODE)
        ];
    }
    
    /**
     * Извлечение категории товара из данных WB
     * 
     * @param array $wbProduct Данные товара
     * @return string Категория
     */
    private function extractWbCategory(array $wbProduct): string
    {
        // Пробуем разные поля для категории
        if (!empty($wbProduct['subjectName'])) {
            return $this->sanitizeString($wbProduct['subjectName']);
        }
        
        if (!empty($wbProduct['categoryName'])) {
            return $this->sanitizeString($wbProduct['categoryName']);
        }
        
        return '';
    }
    
    /**
     * Извлечение цены товара из данных WB
     * 
     * @param array $wbProduct Данные товара
     * @return float Цена
     */
    private function extractWbPrice(array $wbProduct): float
    {
        // Пробуем разные поля для цены
        if (!empty($wbProduct['sizes'])) {
            foreach ($wbProduct['sizes'] as $size) {
                if (!empty($size['price'])) {
                    return $this->sanitizePrice($size['price']);
                }
            }
        }
        
        if (!empty($wbProduct['price'])) {
            return $this->sanitizePrice($wbProduct['price']);
        }
        
        return 0.0;
    }
    
    /**
     * Извлечение атрибутов товара из данных WB
     * 
     * @param array $wbProduct Данные товара
     * @return array Атрибуты товара
     */
    private function extractWbAttributes(array $wbProduct): array
    {
        $attributes = [];
        
        // Основные атрибуты
        if (!empty($wbProduct['nmID'])) {
            $attributes['wb_nm_id'] = $wbProduct['nmID'];
        }
        
        if (!empty($wbProduct['vendorCode'])) {
            $attributes['vendor_code'] = $wbProduct['vendorCode'];
        }
        
        if (!empty($wbProduct['imtID'])) {
            $attributes['imt_id'] = $wbProduct['imtID'];
        }
        
        // Характеристики товара
        if (!empty($wbProduct['characteristics'])) {
            foreach ($wbProduct['characteristics'] as $char) {
                if (!empty($char['name']) && !empty($char['value'])) {
                    $key = $this->sanitizeString($char['name']);
                    $value = $this->sanitizeString($char['value']);
                    
                    if (!empty($key) && !empty($value)) {
                        $attributes[$key] = $value;
                    }
                }
            }
        }
        
        // Размеры и штрихкоды
        if (!empty($wbProduct['sizes'])) {
            $sizes = [];
            $barcodes = [];
            
            foreach ($wbProduct['sizes'] as $size) {
                if (!empty($size['techSize'])) {
                    $sizes[] = $size['techSize'];
                }
                
                if (!empty($size['skus'])) {
                    foreach ($size['skus'] as $sku) {
                        if (!empty($sku['barcode'])) {
                            $barcodes[] = $sku['barcode'];
                        }
                    }
                }
            }
            
            if (!empty($sizes)) {
                $attributes['sizes'] = array_unique($sizes);
            }
            
            if (!empty($barcodes)) {
                $attributes['barcodes'] = array_unique($barcodes);
            }
        }
        
        // Медиа файлы
        if (!empty($wbProduct['mediaFiles'])) {
            $photos = [];
            $videos = [];
            
            foreach ($wbProduct['mediaFiles'] as $media) {
                if ($media['mediaType'] === 'image') {
                    $photos[] = $media['mediaUrl'] ?? '';
                } elseif ($media['mediaType'] === 'video') {
                    $videos[] = $media['mediaUrl'] ?? '';
                }
            }
            
            if (!empty($photos)) {
                $attributes['photos'] = array_filter($photos);
            }
            
            if (!empty($videos)) {
                $attributes['videos'] = array_filter($videos);
            }
        }
        
        // Статус товара
        if (isset($wbProduct['isProhibited'])) {
            $attributes['is_prohibited'] = $wbProduct['isProhibited'];
        }
        
        return $this->sanitizeAttributes($attributes);
    }
    
    /**
     * Выполнение HTTP запроса к Wildberries API
     * 
     * @param string $method HTTP метод
     * @param string $service Сервис API (content, suppliers, statistics)
     * @param string $endpoint Эндпоинт API
     * @param array $data Данные запроса
     * @return array Ответ API
     * @throws Exception
     */
    private function makeRequest(string $method, string $service, string $endpoint, array $data = []): array
    {
        $this->enforceRateLimit();
        
        if (!isset($this->baseUrls[$service])) {
            throw new Exception("Unknown Wildberries API service: $service");
        }
        
        $url = $this->baseUrls[$service] . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: ' . $this->apiToken
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
        } elseif ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
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
                throw new Exception('Wildberries API authentication failed');
                
            case 429:
                throw new Exception('Wildberries API rate limit exceeded');
                
            case 400:
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['message'] ?? 'Bad request';
                throw new Exception('Wildberries API error: ' . $errorMessage);
                
            case 503:
                throw new Exception('Wildberries API temporarily unavailable');
                
            default:
                throw new Exception('Wildberries API HTTP error: ' . $httpCode);
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