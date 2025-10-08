<?php

namespace MDM\Services;

/**
 * DataEnrichmentService - Сервис обогащения данных из внешних источников
 * 
 * Реализует интеграцию с API товарных данных, автоматическое заполнение
 * пустых атрибутов, кэширование результатов и fallback механизмы.
 */
class DataEnrichmentService
{
    /**
     * Типы внешних источников данных
     */
    const SOURCE_OZON_API = 'ozon_api';
    const SOURCE_WILDBERRIES_API = 'wildberries_api';
    const SOURCE_PRODUCT_DATABASE = 'product_database';
    const SOURCE_BARCODE_API = 'barcode_api';

    /**
     * Статусы обогащения
     */
    const ENRICHMENT_SUCCESS = 'success';
    const ENRICHMENT_PARTIAL = 'partial';
    const ENRICHMENT_FAILED = 'failed';
    const ENRICHMENT_CACHED = 'cached';

    /**
     * Конфигурация внешних источников
     */
    private array $sourceConfigs = [];

    /**
     * Кэш для результатов внешних запросов
     */
    private ?object $cache = null;

    /**
     * HTTP клиент для внешних запросов
     */
    private ?object $httpClient = null;

    /**
     * Логгер
     */
    private ?object $logger = null;

    /**
     * Время жизни кэша (в секундах)
     */
    private int $cacheLifetime = 3600; // 1 час

    /**
     * Максимальное время ожидания запроса (в секундах)
     */
    private int $requestTimeout = 30;

    /**
     * Конструктор
     * 
     * @param array $sourceConfigs Конфигурация источников данных
     * @param object|null $cache Объект кэша
     * @param object|null $httpClient HTTP клиент
     * @param object|null $logger Логгер
     */
    public function __construct(
        array $sourceConfigs = [],
        ?object $cache = null,
        ?object $httpClient = null,
        ?object $logger = null
    ) {
        $this->sourceConfigs = $sourceConfigs;
        $this->cache = $cache;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
    }

    /**
     * Обогатить данные товара из внешних источников
     * 
     * @param array $productData Исходные данные товара
     * @param array $sources Список источников для обогащения
     * @return array Результат обогащения
     */
    public function enrichProductData(array $productData, array $sources = []): array
    {
        if (empty($sources)) {
            $sources = array_keys($this->sourceConfigs);
        }

        $enrichedData = $productData;
        $enrichmentResults = [];

        foreach ($sources as $source) {
            try {
                $result = $this->enrichFromSource($enrichedData, $source);
                
                if ($result['status'] !== self::ENRICHMENT_FAILED) {
                    $enrichedData = array_merge($enrichedData, $result['data']);
                }
                
                $enrichmentResults[$source] = $result;
                
            } catch (\Exception $e) {
                $this->logError("Enrichment failed for source {$source}", $e);
                $enrichmentResults[$source] = [
                    'status' => self::ENRICHMENT_FAILED,
                    'error' => $e->getMessage(),
                    'data' => []
                ];
            }
        }

        return [
            'enriched_data' => $enrichedData,
            'enrichment_results' => $enrichmentResults,
            'overall_status' => $this->calculateOverallStatus($enrichmentResults)
        ];
    }

    /**
     * Обогатить данные из конкретного источника
     * 
     * @param array $productData Данные товара
     * @param string $source Источник данных
     * @return array Результат обогащения
     */
    private function enrichFromSource(array $productData, string $source): array
    {
        // Проверяем кэш
        $cacheKey = $this->generateCacheKey($productData, $source);
        $cachedResult = $this->getCachedResult($cacheKey);
        
        if ($cachedResult !== null) {
            return [
                'status' => self::ENRICHMENT_CACHED,
                'data' => $cachedResult,
                'source' => $source
            ];
        }

        // Получаем данные из источника
        $enrichedData = [];
        
        switch ($source) {
            case self::SOURCE_OZON_API:
                $enrichedData = $this->enrichFromOzonAPI($productData);
                break;
                
            case self::SOURCE_WILDBERRIES_API:
                $enrichedData = $this->enrichFromWildberriesAPI($productData);
                break;
                
            case self::SOURCE_PRODUCT_DATABASE:
                $enrichedData = $this->enrichFromProductDatabase($productData);
                break;
                
            case self::SOURCE_BARCODE_API:
                $enrichedData = $this->enrichFromBarcodeAPI($productData);
                break;
                
            default:
                throw new \InvalidArgumentException("Unknown source: {$source}");
        }

        // Кэшируем результат
        $this->cacheResult($cacheKey, $enrichedData);

        $status = empty($enrichedData) ? self::ENRICHMENT_FAILED : 
                 (count($enrichedData) < $this->getExpectedFieldsCount($source) ? 
                  self::ENRICHMENT_PARTIAL : self::ENRICHMENT_SUCCESS);

        return [
            'status' => $status,
            'data' => $enrichedData,
            'source' => $source
        ];
    }

    /**
     * Обогащение данных через Ozon API
     * 
     * @param array $productData Данные товара
     * @return array Обогащенные данные
     */
    private function enrichFromOzonAPI(array $productData): array
    {
        $config = $this->sourceConfigs[self::SOURCE_OZON_API] ?? [];
        
        if (empty($config['api_key']) || empty($config['client_id'])) {
            throw new \RuntimeException('Ozon API configuration is missing');
        }

        $enrichedData = [];
        
        // Поиск по артикулу или названию
        $searchQuery = $productData['sku'] ?? $productData['name'] ?? '';
        
        if (empty($searchQuery)) {
            return $enrichedData;
        }

        try {
            $response = $this->makeApiRequest($config['base_url'] . '/v2/product/info', [
                'headers' => [
                    'Client-Id' => $config['client_id'],
                    'Api-Key' => $config['api_key'],
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'product_id' => $searchQuery
                ]
            ]);

            if ($response && isset($response['result'])) {
                $product = $response['result'];
                
                $enrichedData = $this->mapOzonResponse($product);
            }
            
        } catch (\Exception $e) {
            $this->logError("Ozon API request failed", $e);
        }

        return $enrichedData;
    }

    /**
     * Обогащение данных через Wildberries API
     * 
     * @param array $productData Данные товара
     * @return array Обогащенные данные
     */
    private function enrichFromWildberriesAPI(array $productData): array
    {
        $config = $this->sourceConfigs[self::SOURCE_WILDBERRIES_API] ?? [];
        
        if (empty($config['api_key'])) {
            throw new \RuntimeException('Wildberries API configuration is missing');
        }

        $enrichedData = [];
        
        // Поиск по артикулу
        $sku = $productData['sku'] ?? '';
        
        if (empty($sku)) {
            return $enrichedData;
        }

        try {
            $response = $this->makeApiRequest($config['base_url'] . '/content/v1/cards/cursor/list', [
                'headers' => [
                    'Authorization' => $config['api_key'],
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'sort' => [
                        'cursor' => [
                            'limit' => 1
                        ],
                        'filter' => [
                            'withPhoto' => -1
                        ]
                    ]
                ]
            ]);

            if ($response && isset($response['data']['cards'])) {
                foreach ($response['data']['cards'] as $card) {
                    if (isset($card['vendorCode']) && $card['vendorCode'] === $sku) {
                        $enrichedData = $this->mapWildberriesResponse($card);
                        break;
                    }
                }
            }
            
        } catch (\Exception $e) {
            $this->logError("Wildberries API request failed", $e);
        }

        return $enrichedData;
    }

    /**
     * Обогащение данных из внутренней базы товаров
     * 
     * @param array $productData Данные товара
     * @return array Обогащенные данные
     */
    private function enrichFromProductDatabase(array $productData): array
    {
        // Заглушка для внутренней базы данных
        // В реальной реализации здесь будет запрос к базе данных
        
        $enrichedData = [];
        
        // Пример обогащения на основе бренда и категории
        $brand = $productData['brand'] ?? '';
        $category = $productData['category'] ?? '';
        
        if (!empty($brand) && !empty($category)) {
            // Здесь можно добавить типичные атрибуты для данной категории
            $enrichedData = $this->getDefaultAttributesForCategory($category);
        }

        return $enrichedData;
    }

    /**
     * Обогащение данных через API штрихкодов
     * 
     * @param array $productData Данные товара
     * @return array Обогащенные данные
     */
    private function enrichFromBarcodeAPI(array $productData): array
    {
        $config = $this->sourceConfigs[self::SOURCE_BARCODE_API] ?? [];
        $barcode = $productData['barcode'] ?? '';
        
        if (empty($barcode) || empty($config['api_key'])) {
            return [];
        }

        $enrichedData = [];
        
        try {
            $response = $this->makeApiRequest($config['base_url'] . '/lookup', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config['api_key']
                ],
                'query' => [
                    'upc' => $barcode
                ]
            ]);

            if ($response && isset($response['products'][0])) {
                $product = $response['products'][0];
                $enrichedData = $this->mapBarcodeResponse($product);
            }
            
        } catch (\Exception $e) {
            $this->logError("Barcode API request failed", $e);
        }

        return $enrichedData;
    }

    /**
     * Выполнить HTTP запрос к внешнему API
     * 
     * @param string $url URL для запроса
     * @param array $options Опции запроса
     * @return array|null Ответ API
     */
    private function makeApiRequest(string $url, array $options = []): ?array
    {
        if ($this->httpClient === null) {
            // Fallback на cURL если HTTP клиент не настроен
            return $this->makeCurlRequest($url, $options);
        }

        // Здесь будет использоваться настроенный HTTP клиент
        // Например, Guzzle или другой
        return null;
    }

    /**
     * Выполнить cURL запрос (fallback)
     * 
     * @param string $url URL для запроса
     * @param array $options Опции запроса
     * @return array|null Ответ API
     */
    private function makeCurlRequest(string $url, array $options = []): ?array
    {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        if (isset($options['headers'])) {
            $headers = [];
            foreach ($options['headers'] as $key => $value) {
                $headers[] = "{$key}: {$value}";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if (isset($options['json'])) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json']));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("cURL error: {$error}");
        }

        if ($httpCode >= 400) {
            throw new \RuntimeException("HTTP error: {$httpCode}");
        }

        return json_decode($response, true);
    }

    /**
     * Преобразовать ответ Ozon API
     * 
     * @param array $product Данные товара из Ozon API
     * @return array Стандартизированные данные
     */
    private function mapOzonResponse(array $product): array
    {
        return [
            'name' => $product['name'] ?? '',
            'brand' => $product['brand'] ?? '',
            'category' => $product['category_path'] ?? '',
            'description' => $product['description'] ?? '',
            'images' => $product['images'] ?? [],
            'attributes' => $product['attributes'] ?? [],
            'weight' => $product['weight'] ?? null,
            'dimensions' => $product['dimensions'] ?? null
        ];
    }

    /**
     * Преобразовать ответ Wildberries API
     * 
     * @param array $card Данные карточки из Wildberries API
     * @return array Стандартизированные данные
     */
    private function mapWildberriesResponse(array $card): array
    {
        return [
            'name' => $card['object'] ?? '',
            'brand' => $card['brand'] ?? '',
            'category' => $card['subjectName'] ?? '',
            'description' => $card['description'] ?? '',
            'vendor_code' => $card['vendorCode'] ?? '',
            'attributes' => $card['characteristics'] ?? []
        ];
    }

    /**
     * Преобразовать ответ Barcode API
     * 
     * @param array $product Данные товара из Barcode API
     * @return array Стандартизированные данные
     */
    private function mapBarcodeResponse(array $product): array
    {
        return [
            'name' => $product['title'] ?? '',
            'brand' => $product['brand'] ?? '',
            'category' => $product['category'] ?? '',
            'description' => $product['description'] ?? '',
            'barcode' => $product['upc'] ?? ''
        ];
    }

    /**
     * Получить атрибуты по умолчанию для категории
     * 
     * @param string $category Категория товара
     * @return array Атрибуты по умолчанию
     */
    private function getDefaultAttributesForCategory(string $category): array
    {
        $defaults = [
            'Смеси для выпечки' => [
                'type' => 'dry_mix',
                'dietary' => ['vegetarian'],
                'shelf_life' => '12 months'
            ],
            'Автозапчасти' => [
                'warranty' => '12 months',
                'material' => 'metal'
            ]
        ];

        return $defaults[$category] ?? [];
    }

    /**
     * Сгенерировать ключ кэша
     * 
     * @param array $productData Данные товара
     * @param string $source Источник данных
     * @return string Ключ кэша
     */
    private function generateCacheKey(array $productData, string $source): string
    {
        $identifier = $productData['sku'] ?? $productData['barcode'] ?? $productData['name'] ?? 'unknown';
        return "enrichment:{$source}:" . md5($identifier);
    }

    /**
     * Получить результат из кэша
     * 
     * @param string $cacheKey Ключ кэша
     * @return array|null Кэшированный результат
     */
    private function getCachedResult(string $cacheKey): ?array
    {
        if ($this->cache === null) {
            return null;
        }

        // Предполагаем, что кэш имеет метод get()
        if (method_exists($this->cache, 'get')) {
            return $this->cache->get($cacheKey);
        }

        return null;
    }

    /**
     * Сохранить результат в кэш
     * 
     * @param string $cacheKey Ключ кэша
     * @param array $data Данные для кэширования
     */
    private function cacheResult(string $cacheKey, array $data): void
    {
        if ($this->cache === null || empty($data)) {
            return;
        }

        // Предполагаем, что кэш имеет метод set()
        if (method_exists($this->cache, 'set')) {
            $this->cache->set($cacheKey, $data, $this->cacheLifetime);
        }
    }

    /**
     * Рассчитать общий статус обогащения
     * 
     * @param array $results Результаты обогащения по источникам
     * @return string Общий статус
     */
    private function calculateOverallStatus(array $results): string
    {
        $successCount = 0;
        $totalCount = count($results);

        foreach ($results as $result) {
            if (in_array($result['status'], [self::ENRICHMENT_SUCCESS, self::ENRICHMENT_CACHED])) {
                $successCount++;
            }
        }

        if ($successCount === $totalCount) {
            return self::ENRICHMENT_SUCCESS;
        } elseif ($successCount > 0) {
            return self::ENRICHMENT_PARTIAL;
        } else {
            return self::ENRICHMENT_FAILED;
        }
    }

    /**
     * Получить ожидаемое количество полей для источника
     * 
     * @param string $source Источник данных
     * @return int Ожидаемое количество полей
     */
    private function getExpectedFieldsCount(string $source): int
    {
        $expectedFields = [
            self::SOURCE_OZON_API => 5,
            self::SOURCE_WILDBERRIES_API => 4,
            self::SOURCE_PRODUCT_DATABASE => 3,
            self::SOURCE_BARCODE_API => 3
        ];

        return $expectedFields[$source] ?? 3;
    }

    /**
     * Логировать ошибку
     * 
     * @param string $message Сообщение об ошибке
     * @param \Exception $exception Исключение
     */
    private function logError(string $message, \Exception $exception): void
    {
        if ($this->logger && method_exists($this->logger, 'error')) {
            $this->logger->error($message, [
                'exception' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);
        }
    }

    /**
     * Установить конфигурацию источников
     * 
     * @param array $configs Конфигурация источников
     */
    public function setSourceConfigs(array $configs): void
    {
        $this->sourceConfigs = $configs;
    }

    /**
     * Установить время жизни кэша
     * 
     * @param int $lifetime Время жизни в секундах
     */
    public function setCacheLifetime(int $lifetime): void
    {
        $this->cacheLifetime = $lifetime;
    }

    /**
     * Установить таймаут запросов
     * 
     * @param int $timeout Таймаут в секундах
     */
    public function setRequestTimeout(int $timeout): void
    {
        $this->requestTimeout = $timeout;
    }
}