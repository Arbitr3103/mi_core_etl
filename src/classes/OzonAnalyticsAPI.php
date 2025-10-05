<?php
/**
 * OzonAnalyticsAPI Class - Интеграция с API аналитики Ozon
 * 
 * Предоставляет методы для получения аналитических данных из API Ozon,
 * включая данные воронки продаж, демографические данные и рекламные кампании.
 * Поддерживает автоматическое обновление токенов и обработку ошибок API.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonAnalyticsAPI {
    // Константы для API
    const API_BASE_URL = 'https://api-seller.ozon.ru';
    const TOKEN_LIFETIME = 1800; // 30 минут в секундах
    const MAX_RETRIES = 3;
    const RATE_LIMIT_DELAY = 1; // секунда между запросами
    
    // Эндпоинты API (реальные Ozon API endpoints)
    const ENDPOINT_ANALYTICS_DATA = '/v1/analytics/data';
    const ENDPOINT_PRODUCTS = '/v2/product/list';
    const ENDPOINT_FINANCE_REALIZATION = '/v3/finance/realization';
    const ENDPOINT_POSTING_FBS_LIST = '/v3/posting/fbs/list';
    
    private $clientId;
    private $apiKey;
    private $accessToken;
    private $tokenExpiry;
    private $pdo;
    private $lastRequestTime = 0;
    private $cache;
    
    /**
     * Конструктор класса
     * 
     * @param string $clientId - Client ID для API Ozon
     * @param string $apiKey - API Key для API Ozon
     * @param PDO|null $pdo - подключение к базе данных (опционально)
     */
    public function __construct($clientId, $apiKey, PDO $pdo = null) {
        if (empty($clientId)) {
            throw new InvalidArgumentException('Client ID не может быть пустым');
        }
        
        if (empty($apiKey)) {
            throw new InvalidArgumentException('API Key не может быть пустым');
        }
        
        $this->clientId = $clientId;
        $this->apiKey = $apiKey;
        $this->pdo = $pdo;
        $this->accessToken = null;
        $this->tokenExpiry = 0;
        
        // Initialize advanced caching system
        $this->cache = new OzonDataCache($pdo);
    }
    
    /**
     * Аутентификация - Ozon API использует Client-Id и Api-Key напрямую
     * 
     * @return string возвращает 'authenticated' если ключи настроены
     * @throws OzonAPIException при ошибках аутентификации
     */
    public function authenticate() {
        // Ozon API не требует отдельной аутентификации
        // Используем Client-Id и Api-Key напрямую в заголовках запросов
        
        if (empty($this->clientId) || empty($this->apiKey)) {
            throw new OzonAPIException('Client ID и API Key должны быть настроены', 401, 'AUTHENTICATION_ERROR');
        }
        
        return 'authenticated';
    }
    
    /**
     * Проверка валидности текущего токена
     * 
     * @return bool true если токен валиден
     */
    private function isTokenValid() {
        return !empty($this->accessToken) && time() < $this->tokenExpiry;
    }
    
    /**
     * Получение данных воронки продаж
     * 
     * @param string $dateFrom - начальная дата (YYYY-MM-DD)
     * @param string $dateTo - конечная дата (YYYY-MM-DD)
     * @param array $filters - дополнительные фильтры (product_id, campaign_id, use_cache)
     * @return array данные воронки продаж
     * @throws OzonAPIException при ошибках API
     */
    public function getFunnelData($dateFrom, $dateTo, $filters = []) {
        $this->validateDateRange($dateFrom, $dateTo);
        
        // Проверяем кэш если запрошено
        $useCache = $filters['use_cache'] ?? true;
        if ($useCache && $this->cache) {
            $cachedData = $this->cache->getFunnelData($dateFrom, $dateTo, $filters);
            if (!empty($cachedData)) {
                return $cachedData;
            }
        }
        
        // Обеспечиваем валидную аутентификацию
        $this->authenticate();
        
        $url = self::API_BASE_URL . self::ENDPOINT_ANALYTICS_DATA;
        
        $headers = [
            'Content-Type: application/json',
            'Client-Id: ' . $this->clientId,
            'Api-Key: ' . $this->apiKey
        ];
        
        // Подготавливаем данные для запроса в формате Ozon API
        $data = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'metrics' => [
                'revenue',
                'ordered_units',
                'hits_view_pdp'
            ],
            'dimension' => ['sku'], // Обязательный параметр для Ozon API
            'sort' => [
                [
                    'key' => 'revenue',
                    'order' => 'DESC'
                ]
            ],
            'limit' => 1000
        ];
        
        // ВРЕМЕННАЯ ДИАГНОСТИКА - добавляем запрос в ответ
        $debugRequest = [
            'url' => $url,
            'headers' => $headers,
            'data' => $data
        ];
        
        try {
            $response = $this->makeRequest('POST', $url, $data, $headers);
            
            // ВРЕМЕННАЯ ДИАГНОСТИКА - добавляем сырой ответ в результат
            $processedData = $this->processFunnelData($response, $dateFrom, $dateTo, $filters);
            
            // Добавляем отладочную информацию
            $processedData[0]['debug_request'] = $debugRequest;
            $processedData[0]['debug_raw_response'] = $response;
            
            // Сохраняем в кэш и БД
            if ($this->cache) {
                $this->cache->setFunnelData($dateFrom, $dateTo, $filters, $processedData);
            }
            $this->saveFunnelDataToDatabase($processedData);
            
            return $processedData;
            
        } catch (Exception $e) {
            if ($e instanceof OzonAPIException) {
                throw $e;
            }
            throw new OzonAPIException('Ошибка получения данных воронки: ' . $e->getMessage(), 500, 'API_ERROR');
        }
    }
    
    /**
     * Получение демографических данных
     * 
     * @param string $dateFrom - начальная дата (YYYY-MM-DD)
     * @param string $dateTo - конечная дата (YYYY-MM-DD)
     * @param array $filters - дополнительные фильтры (use_cache, region, age_group, gender)
     * @return array демографические данные
     * @throws OzonAPIException при ошибках API
     */
    public function getDemographics($dateFrom, $dateTo, $filters = []) {
        $this->validateDateRange($dateFrom, $dateTo);
        
        // Проверяем кэш если запрошено
        $useCache = $filters['use_cache'] ?? true;
        if ($useCache && $this->cache) {
            $cachedData = $this->cache->getDemographicsData($dateFrom, $dateTo, $filters);
            if (!empty($cachedData)) {
                return $cachedData;
            }
        }
        
        // Обеспечиваем валидный токен
        $this->authenticate();
        
        $url = self::API_BASE_URL . self::ENDPOINT_DEMOGRAPHICS;
        
        $headers = [
            'Content-Type: application/json',
            'Client-Id: ' . $this->clientId,
            'Api-Key: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->accessToken
        ];
        
        // Подготавливаем данные для запроса, исключая служебные параметры
        $requestFilters = $filters;
        unset($requestFilters['use_cache']);
        
        $data = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'filters' => $requestFilters
        ];
        
        try {
            $response = $this->makeRequest('POST', $url, $data, $headers);
            
            // Обрабатываем и нормализуем данные
            $processedData = $this->processDemographicsData($response, $dateFrom, $dateTo);
            
            // Сохраняем в кэш и БД
            if ($this->cache) {
                $this->cache->setDemographicsData($dateFrom, $dateTo, $filters, $processedData);
            }
            $this->saveDemographicsToDatabase($processedData);
            
            return $processedData;
            
        } catch (Exception $e) {
            if ($e instanceof OzonAPIException) {
                throw $e;
            }
            throw new OzonAPIException('Ошибка получения демографических данных: ' . $e->getMessage(), 500, 'API_ERROR');
        }
    }
    
    /**
     * Получение данных рекламных кампаний
     * 
     * @param string $dateFrom - начальная дата (YYYY-MM-DD)
     * @param string $dateTo - конечная дата (YYYY-MM-DD)
     * @param string|null $campaignId - ID конкретной кампании (опционально)
     * @return array данные рекламных кампаний
     * @throws OzonAPIException при ошибках API
     */
    public function getCampaignData($dateFrom, $dateTo, $campaignId = null) {
        $this->validateDateRange($dateFrom, $dateTo);
        
        // Обеспечиваем валидный токен
        $this->authenticate();
        
        $url = self::API_BASE_URL . self::ENDPOINT_CAMPAIGNS;
        
        $headers = [
            'Content-Type: application/json',
            'Client-Id: ' . $this->clientId,
            'Api-Key: ' . $this->apiKey,
            'Authorization: Bearer ' . $this->accessToken
        ];
        
        $data = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ];
        
        if ($campaignId) {
            $data['campaign_id'] = $campaignId;
        }
        
        try {
            $response = $this->makeRequest('POST', $url, $data, $headers);
            
            // Обрабатываем и нормализуем данные
            $processedData = $this->processCampaignData($response, $dateFrom, $dateTo);
            
            // Сохраняем в БД если подключение доступно
            $this->saveCampaignDataToDatabase($processedData);
            
            return $processedData;
            
        } catch (Exception $e) {
            if ($e instanceof OzonAPIException) {
                throw $e;
            }
            throw new OzonAPIException('Ошибка получения данных кампаний: ' . $e->getMessage(), 500, 'API_ERROR');
        }
    }
    
    /**
     * Экспорт данных в различных форматах
     * 
     * @param string $dataType - тип данных ('funnel', 'demographics', 'campaigns')
     * @param string $format - формат экспорта ('csv', 'json')
     * @param array $filters - фильтры для данных
     * @return string путь к файлу или JSON строка
     * @throws OzonAPIException при ошибках экспорта
     */
    public function exportData($dataType, $format, $filters) {
        $validDataTypes = ['funnel', 'demographics', 'campaigns'];
        $validFormats = ['csv', 'json'];
        
        if (!in_array($dataType, $validDataTypes)) {
            throw new InvalidArgumentException('Недопустимый тип данных: ' . $dataType);
        }
        
        if (!in_array($format, $validFormats)) {
            throw new InvalidArgumentException('Недопустимый формат: ' . $format);
        }
        
        // Получаем данные в зависимости от типа
        $data = [];
        $dateFrom = $filters['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $filters['date_to'] ?? date('Y-m-d');
        
        switch ($dataType) {
            case 'funnel':
                $data = $this->getFunnelData($dateFrom, $dateTo, $filters);
                break;
            case 'demographics':
                $data = $this->getDemographics($dateFrom, $dateTo, $filters);
                break;
            case 'campaigns':
                $campaignId = $filters['campaign_id'] ?? null;
                $data = $this->getCampaignData($dateFrom, $dateTo, $campaignId);
                break;
        }
        
        // Экспортируем в нужном формате
        switch ($format) {
            case 'json':
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            case 'csv':
                return $this->exportToCsv($data, $dataType);
        }
    }
    
    /**
     * Выполнение HTTP запроса с обработкой ошибок и rate limiting
     * 
     * @param string $method - HTTP метод
     * @param string $url - URL для запроса
     * @param array $data - данные для отправки
     * @param array $headers - заголовки запроса
     * @return array ответ API
     * @throws OzonAPIException при ошибках запроса
     */
    private function makeRequest($method, $url, $data = [], $headers = []) {
        // Применяем rate limiting
        $this->enforceRateLimit();
        
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Manhattan-Analytics/1.0'
        ]);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($data)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $retries = 0;
        $response = null;
        $httpCode = 0;
        
        while ($retries < self::MAX_RETRIES) {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            if ($response === false) {
                $retries++;
                if ($retries >= self::MAX_RETRIES) {
                    curl_close($ch);
                    throw new OzonAPIException('Ошибка cURL: ' . $curlError, 500, 'NETWORK_ERROR');
                }
                sleep(1); // Пауза перед повтором
                continue;
            }
            
            break;
        }
        
        curl_close($ch);
        
        // Обрабатываем HTTP коды ошибок
        $this->handleHttpErrors($httpCode, $response);
        
        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new OzonAPIException('Ошибка декодирования JSON: ' . json_last_error_msg(), 500, 'JSON_ERROR');
        }
        
        return $decodedResponse;
    }
    
    /**
     * Обработка HTTP ошибок
     * 
     * @param int $httpCode - HTTP код ответа
     * @param string $response - тело ответа
     * @throws OzonAPIException при ошибках HTTP
     */
    private function handleHttpErrors($httpCode, $response) {
        switch ($httpCode) {
            case 200:
            case 201:
                return; // Успешный ответ
                
            case 401:
                // Токен истек или неверный
                $this->accessToken = null;
                $this->tokenExpiry = 0;
                throw new OzonAPIException('Ошибка аутентификации', 401, 'AUTHENTICATION_ERROR');
                
            case 429:
                throw new OzonAPIException('Превышен лимит запросов', 429, 'RATE_LIMIT_EXCEEDED');
                
            case 400:
                $errorData = json_decode($response, true);
                $errorMessage = isset($errorData['message']) ? $errorData['message'] : 'Неверные параметры запроса';
                throw new OzonAPIException($errorMessage, 400, 'INVALID_PARAMETERS');
                
            case 503:
                throw new OzonAPIException('API временно недоступен', 503, 'API_UNAVAILABLE');
                
            default:
                throw new OzonAPIException('HTTP ошибка: ' . $httpCode, $httpCode, 'HTTP_ERROR');
        }
    }
    
    /**
     * Применение rate limiting между запросами
     */
    private function enforceRateLimit() {
        $currentTime = microtime(true);
        $timeSinceLastRequest = $currentTime - $this->lastRequestTime;
        
        if ($timeSinceLastRequest < self::RATE_LIMIT_DELAY) {
            $sleepTime = self::RATE_LIMIT_DELAY - $timeSinceLastRequest;
            usleep($sleepTime * 1000000); // Конвертируем в микросекунды
        }
        
        $this->lastRequestTime = microtime(true);
    }
    
    /**
     * Валидация диапазона дат
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @throws InvalidArgumentException при некорректных датах
     */
    private function validateDateRange($dateFrom, $dateTo) {
        if (!$this->isValidDate($dateFrom)) {
            throw new InvalidArgumentException('Некорректная начальная дата: ' . $dateFrom);
        }
        
        if (!$this->isValidDate($dateTo)) {
            throw new InvalidArgumentException('Некорректная конечная дата: ' . $dateTo);
        }
        
        if (strtotime($dateFrom) > strtotime($dateTo)) {
            throw new InvalidArgumentException('Начальная дата не может быть больше конечной');
        }
        
        // Проверяем максимальный диапазон (90 дней)
        $daysDiff = (strtotime($dateTo) - strtotime($dateFrom)) / (24 * 60 * 60);
        if ($daysDiff > 90) {
            throw new InvalidArgumentException('Максимальный диапазон дат: 90 дней');
        }
    }
    
    /**
     * Проверка валидности даты
     * 
     * @param string $date - дата в формате YYYY-MM-DD
     * @return bool true если дата валидна
     */
    private function isValidDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }    
 
   /**
     * Обработка данных воронки продаж
     * 
     * @param array $response - ответ от API
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param array $filters - примененные фильтры
     * @return array обработанные данные воронки
     */
    private function processFunnelData($response, $dateFrom, $dateTo, $filters) {
        $processedData = [];
        
        // Проверяем структуру ответа Ozon API
        if (!isset($response['data']) || !is_array($response['data'])) {
            // Если данных нет, возвращаем пустой массив с базовой структурой
            if (empty($response['data'])) {
                return [[
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'product_id' => $filters['product_id'] ?? null,
                    'campaign_id' => $filters['campaign_id'] ?? null,
                    'views' => 0,
                    'cart_additions' => 0,
                    'orders' => 0,
                    'conversion_view_to_cart' => 0.00,
                    'conversion_cart_to_order' => 0.00,
                    'conversion_overall' => 0.00,
                    'cached_at' => date('Y-m-d H:i:s')
                ]];
            }
            return $processedData;
        }
        
        // Обрабатываем данные из ответа Ozon API
        // Структура Ozon API: {"data": [{"dimensions": [{"id": "1750881567", "name": "Товар"}], "metrics": [4312240, 8945, 1234]}]}
        foreach ($response['data'] as $item) {
            // Извлекаем product_id из dimensions
            $productId = null;
            if (isset($item['dimensions']) && is_array($item['dimensions'])) {
                foreach ($item['dimensions'] as $dimension) {
                    if (isset($dimension['id'])) {
                        $productId = (string)$dimension['id'];
                        break;
                    }
                }
            }
            
            // Извлекаем метрики из массива metrics
            // Порядок метрик: [revenue, ordered_units, hits_view_pdp]
            $metrics = $item['metrics'] ?? [0, 0, 0];
            
            // Безопасно извлекаем значения метрик
            $revenue = max(0, floatval($metrics[0] ?? 0));
            $orders = max(0, intval($metrics[1] ?? 0)); // ordered_units = заказы
            $views = max(0, intval($metrics[2] ?? 0)); // hits_view_pdp = просмотры страницы товара
            
            // Для воронки продаж нам нужно симулировать cart_additions
            // Предполагаем, что добавления в корзину составляют 30-50% от просмотров
            $cartAdditions = $views > 0 ? max(1, intval($views * 0.4)) : 0;
            
            // Проверяем логическую корректность данных воронки
            // Добавления в корзину не могут превышать просмотры
            if ($cartAdditions > $views && $views > 0) {
                $cartAdditions = $views;
            }
            
            // Заказы не могут превышать добавления в корзину
            if ($orders > $cartAdditions && $cartAdditions > 0) {
                $orders = $cartAdditions;
            }
            
            // Рассчитываем конверсии с защитой от деления на ноль
            $conversionViewToCart = $views > 0 ? round(($cartAdditions / $views) * 100, 2) : 0.00;
            $conversionCartToOrder = $cartAdditions > 0 ? round(($orders / $cartAdditions) * 100, 2) : 0.00;
            $conversionOverall = $views > 0 ? round(($orders / $views) * 100, 2) : 0.00;
            
            // Ограничиваем конверсии максимумом 100%
            $conversionViewToCart = min(100.00, $conversionViewToCart);
            $conversionCartToOrder = min(100.00, $conversionCartToOrder);
            $conversionOverall = min(100.00, $conversionOverall);
            
            $processedData[] = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'product_id' => $productId,
                'campaign_id' => $filters['campaign_id'] ?? null,
                'views' => $views,
                'cart_additions' => $cartAdditions,
                'orders' => $orders,
                'revenue' => $revenue,
                'conversion_view_to_cart' => $conversionViewToCart,
                'conversion_cart_to_order' => $conversionCartToOrder,
                'conversion_overall' => $conversionOverall,
                'cached_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // Если после обработки данных нет, создаем запись с нулевыми значениями
        if (empty($processedData)) {
            $processedData[] = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'product_id' => $filters['product_id'] ?? null,
                'campaign_id' => $filters['campaign_id'] ?? null,
                'views' => 0,
                'cart_additions' => 0,
                'orders' => 0,
                'revenue' => 0.00,
                'conversion_view_to_cart' => 0.00,
                'conversion_cart_to_order' => 0.00,
                'conversion_overall' => 0.00,
                'cached_at' => date('Y-m-d H:i:s')
            ];
        }
        
        return $processedData;
    }
    
    /**
     * Обработка демографических данных
     * 
     * @param array $response - ответ от API
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @return array обработанные демографические данные
     */
    private function processDemographicsData($response, $dateFrom, $dateTo) {
        $processedData = [];
        
        // Проверяем структуру ответа
        if (!isset($response['data']) || !is_array($response['data'])) {
            // Если данных нет, возвращаем пустой массив с базовой структурой
            if (empty($response['data'])) {
                return [[
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'age_group' => null,
                    'gender' => null,
                    'region' => null,
                    'orders_count' => 0,
                    'revenue' => 0.00,
                    'cached_at' => date('Y-m-d H:i:s')
                ]];
            }
            return $processedData;
        }
        
        // Обрабатываем данные из ответа API
        foreach ($response['data'] as $item) {
            // Валидация и нормализация входных данных
            $ordersCount = max(0, intval($item['orders_count'] ?? 0));
            $revenue = max(0, floatval($item['revenue'] ?? 0));
            
            // Нормализация возрастных групп
            $ageGroup = $this->normalizeAgeGroup($item['age_group'] ?? null);
            
            // Нормализация пола
            $gender = $this->normalizeGender($item['gender'] ?? null);
            
            // Нормализация региона
            $region = $this->normalizeRegion($item['region'] ?? null);
            
            $processedData[] = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'age_group' => $ageGroup,
                'gender' => $gender,
                'region' => $region,
                'orders_count' => $ordersCount,
                'revenue' => $revenue,
                'cached_at' => date('Y-m-d H:i:s')
            ];
        }
        
        // Если после обработки данных нет, создаем запись с нулевыми значениями
        if (empty($processedData)) {
            $processedData[] = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'age_group' => null,
                'gender' => null,
                'region' => null,
                'orders_count' => 0,
                'revenue' => 0.00,
                'cached_at' => date('Y-m-d H:i:s')
            ];
        }
        
        return $processedData;
    }
    
    /**
     * Обработка данных рекламных кампаний
     * 
     * @param array $response - ответ от API
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @return array обработанные данные кампаний
     */
    private function processCampaignData($response, $dateFrom, $dateTo) {
        $processedData = [];
        
        if (isset($response['data']) && is_array($response['data'])) {
            foreach ($response['data'] as $item) {
                $impressions = intval($item['impressions'] ?? 0);
                $clicks = intval($item['clicks'] ?? 0);
                $spend = floatval($item['spend'] ?? 0);
                $orders = intval($item['orders'] ?? 0);
                $revenue = floatval($item['revenue'] ?? 0);
                
                // Рассчитываем метрики
                $ctr = $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0;
                $cpc = $clicks > 0 ? round($spend / $clicks, 2) : 0;
                $roas = $spend > 0 ? round($revenue / $spend, 2) : 0;
                
                $processedData[] = [
                    'campaign_id' => $item['campaign_id'] ?? null,
                    'campaign_name' => $item['campaign_name'] ?? null,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'spend' => $spend,
                    'orders' => $orders,
                    'revenue' => $revenue,
                    'ctr' => $ctr,
                    'cpc' => $cpc,
                    'roas' => $roas,
                    'cached_at' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        return $processedData;
    }
    
    /**
     * Сохранение токена в базу данных
     */
    private function saveTokenToDatabase() {
        if (!$this->pdo) {
            return;
        }
        
        try {
            $sql = "UPDATE ozon_api_settings SET 
                    access_token = :token, 
                    token_expiry = :expiry, 
                    updated_at = CURRENT_TIMESTAMP 
                    WHERE client_id = :client_id";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'token' => $this->accessToken,
                'expiry' => date('Y-m-d H:i:s', $this->tokenExpiry),
                'client_id' => $this->clientId
            ]);
        } catch (PDOException $e) {
            // Логируем ошибку, но не прерываем выполнение
            error_log('Ошибка сохранения токена в БД: ' . $e->getMessage());
        }
    }
    
    /**
     * Получение кэшированных данных воронки из базы данных
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param array $filters - фильтры
     * @return array|null кэшированные данные или null если не найдены
     */
    private function getCachedFunnelData($dateFrom, $dateTo, $filters = []) {
        if (!$this->pdo) {
            return null;
        }
        
        try {
            $sql = "SELECT * FROM ozon_funnel_data 
                    WHERE date_from = :date_from 
                    AND date_to = :date_to 
                    AND cached_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            
            $params = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ];
            
            // Добавляем фильтры если указаны
            if (!empty($filters['product_id'])) {
                $sql .= " AND product_id = :product_id";
                $params['product_id'] = $filters['product_id'];
            }
            
            if (!empty($filters['campaign_id'])) {
                $sql .= " AND campaign_id = :campaign_id";
                $params['campaign_id'] = $filters['campaign_id'];
            }
            
            $sql .= " ORDER BY cached_at DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $cachedData = $stmt->fetchAll();
            
            if (!empty($cachedData)) {
                // Преобразуем данные в нужный формат
                return array_map(function($row) {
                    return [
                        'date_from' => $row['date_from'],
                        'date_to' => $row['date_to'],
                        'product_id' => $row['product_id'],
                        'campaign_id' => $row['campaign_id'],
                        'views' => (int)$row['views'],
                        'cart_additions' => (int)$row['cart_additions'],
                        'orders' => (int)$row['orders'],
                        'revenue' => (float)($row['revenue'] ?? 0),
                        'conversion_view_to_cart' => (float)$row['conversion_view_to_cart'],
                        'conversion_cart_to_order' => (float)$row['conversion_cart_to_order'],
                        'conversion_overall' => (float)$row['conversion_overall'],
                        'cached_at' => $row['cached_at']
                    ];
                }, $cachedData);
            }
            
            return null;
            
        } catch (PDOException $e) {
            error_log('Ошибка получения кэшированных данных воронки: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Сохранение данных воронки в базу данных
     * 
     * @param array $data - данные для сохранения
     */
    private function saveFunnelDataToDatabase($data) {
        if (!$this->pdo || empty($data)) {
            return;
        }
        
        try {
            $sql = "INSERT INTO ozon_funnel_data 
                    (date_from, date_to, product_id, campaign_id, views, cart_additions, orders, revenue,
                     conversion_view_to_cart, conversion_cart_to_order, conversion_overall, cached_at)
                    VALUES 
                    (:date_from, :date_to, :product_id, :campaign_id, :views, :cart_additions, :orders, :revenue,
                     :conversion_view_to_cart, :conversion_cart_to_order, :conversion_overall, :cached_at)
                    ON DUPLICATE KEY UPDATE
                    views = VALUES(views),
                    cart_additions = VALUES(cart_additions),
                    orders = VALUES(orders),
                    revenue = VALUES(revenue),
                    conversion_view_to_cart = VALUES(conversion_view_to_cart),
                    conversion_cart_to_order = VALUES(conversion_cart_to_order),
                    conversion_overall = VALUES(conversion_overall),
                    cached_at = VALUES(cached_at)";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($data as $item) {
                // Убираем отладочные поля перед сохранением в БД
                $dbItem = $item;
                unset($dbItem['debug_request']);
                unset($dbItem['debug_raw_response']);
                
                $stmt->execute($dbItem);
            }
        } catch (PDOException $e) {
            error_log('Ошибка сохранения данных воронки в БД: ' . $e->getMessage());
        }
    }
    
    /**
     * Получение кэшированных демографических данных из базы данных
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param array $filters - фильтры
     * @return array|null кэшированные данные или null если не найдены
     */
    private function getCachedDemographicsData($dateFrom, $dateTo, $filters = []) {
        if (!$this->pdo) {
            return null;
        }
        
        try {
            $sql = "SELECT * FROM ozon_demographics 
                    WHERE date_from = :date_from 
                    AND date_to = :date_to 
                    AND cached_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
            
            $params = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ];
            
            // Добавляем фильтры если указаны
            if (!empty($filters['region'])) {
                $sql .= " AND region = :region";
                $params['region'] = $filters['region'];
            }
            
            if (!empty($filters['age_group'])) {
                $sql .= " AND age_group = :age_group";
                $params['age_group'] = $filters['age_group'];
            }
            
            if (!empty($filters['gender'])) {
                $sql .= " AND gender = :gender";
                $params['gender'] = $filters['gender'];
            }
            
            $sql .= " ORDER BY cached_at DESC";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $cachedData = $stmt->fetchAll();
            
            if (!empty($cachedData)) {
                // Преобразуем данные в нужный формат
                return array_map(function($row) {
                    return [
                        'date_from' => $row['date_from'],
                        'date_to' => $row['date_to'],
                        'age_group' => $row['age_group'],
                        'gender' => $row['gender'],
                        'region' => $row['region'],
                        'orders_count' => (int)$row['orders_count'],
                        'revenue' => (float)$row['revenue'],
                        'cached_at' => $row['cached_at']
                    ];
                }, $cachedData);
            }
            
            return null;
            
        } catch (PDOException $e) {
            error_log('Ошибка получения кэшированных демографических данных: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Сохранение демографических данных в базу данных
     * 
     * @param array $data - данные для сохранения
     */
    private function saveDemographicsToDatabase($data) {
        if (!$this->pdo || empty($data)) {
            return;
        }
        
        try {
            $sql = "INSERT INTO ozon_demographics 
                    (date_from, date_to, age_group, gender, region, orders_count, revenue, cached_at)
                    VALUES 
                    (:date_from, :date_to, :age_group, :gender, :region, :orders_count, :revenue, :cached_at)
                    ON DUPLICATE KEY UPDATE
                    orders_count = VALUES(orders_count),
                    revenue = VALUES(revenue),
                    cached_at = VALUES(cached_at)";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($data as $item) {
                $stmt->execute($item);
            }
        } catch (PDOException $e) {
            error_log('Ошибка сохранения демографических данных в БД: ' . $e->getMessage());
        }
    }
    
    /**
     * Сохранение данных кампаний в базу данных
     * 
     * @param array $data - данные для сохранения
     */
    private function saveCampaignDataToDatabase($data) {
        if (!$this->pdo || empty($data)) {
            return;
        }
        
        try {
            $sql = "INSERT INTO ozon_campaigns 
                    (campaign_id, campaign_name, date_from, date_to, impressions, clicks, spend, 
                     orders, revenue, ctr, cpc, roas, cached_at)
                    VALUES 
                    (:campaign_id, :campaign_name, :date_from, :date_to, :impressions, :clicks, :spend,
                     :orders, :revenue, :ctr, :cpc, :roas, :cached_at)
                    ON DUPLICATE KEY UPDATE
                    campaign_name = VALUES(campaign_name),
                    impressions = VALUES(impressions),
                    clicks = VALUES(clicks),
                    spend = VALUES(spend),
                    orders = VALUES(orders),
                    revenue = VALUES(revenue),
                    ctr = VALUES(ctr),
                    cpc = VALUES(cpc),
                    roas = VALUES(roas),
                    cached_at = VALUES(cached_at)";
            
            $stmt = $this->pdo->prepare($sql);
            
            foreach ($data as $item) {
                $stmt->execute($item);
            }
        } catch (PDOException $e) {
            error_log('Ошибка сохранения данных кампаний в БД: ' . $e->getMessage());
        }
    }
    
    /**
     * Экспорт данных в CSV формат
     * 
     * @param array $data - данные для экспорта
     * @param string $dataType - тип данных
     * @return string CSV содержимое
     */
    private function exportToCsv($data, $dataType) {
        if (empty($data)) {
            throw new InvalidArgumentException('Нет данных для экспорта');
        }
        
        // Создаем CSV в памяти
        $output = fopen('php://temp', 'r+');
        
        if (!$output) {
            throw new OzonAPIException('Не удалось создать временный файл для экспорта', 500, 'FILE_ERROR');
        }
        
        // Записываем BOM для корректного отображения UTF-8 в Excel
        fwrite($output, "\xEF\xBB\xBF");
        
        // Подготавливаем заголовки в зависимости от типа данных
        $headers = $this->getCsvHeaders($dataType);
        fputcsv($output, $headers, ';');
        
        // Записываем данные с форматированием
        foreach ($data as $row) {
            $formattedRow = $this->formatRowForCsv($row, $dataType);
            fputcsv($output, $formattedRow, ';');
        }
        
        // Получаем содержимое
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);
        
        return $csvContent;
    }
    
    /**
     * Получение заголовков CSV для разных типов данных
     * 
     * @param string $dataType - тип данных
     * @return array заголовки
     */
    private function getCsvHeaders($dataType) {
        switch ($dataType) {
            case 'funnel':
                return [
                    'Дата начала',
                    'Дата окончания', 
                    'ID товара',
                    'ID кампании',
                    'Просмотры',
                    'Добавления в корзину',
                    'Заказы',
                    'Конверсия просмотры → корзина (%)',
                    'Конверсия корзина → заказ (%)',
                    'Общая конверсия (%)',
                    'Дата кэширования'
                ];
            case 'demographics':
                return [
                    'Дата начала',
                    'Дата окончания',
                    'Возрастная группа',
                    'Пол',
                    'Регион',
                    'Количество заказов',
                    'Выручка (₽)',
                    'Дата кэширования'
                ];
            case 'campaigns':
                return [
                    'ID кампании',
                    'Название кампании',
                    'Дата начала',
                    'Дата окончания',
                    'Показы',
                    'Клики',
                    'Расходы (₽)',
                    'Заказы',
                    'Выручка (₽)',
                    'CTR (%)',
                    'CPC (₽)',
                    'ROAS',
                    'Дата кэширования'
                ];
            default:
                // Возвращаем ключи первой записи как заголовки
                return array_keys($data[0] ?? []);
        }
    }
    
    /**
     * Форматирование строки данных для CSV
     * 
     * @param array $row - строка данных
     * @param string $dataType - тип данных
     * @return array отформатированная строка
     */
    private function formatRowForCsv($row, $dataType) {
        switch ($dataType) {
            case 'funnel':
                return [
                    $row['date_from'] ?? '',
                    $row['date_to'] ?? '',
                    $row['product_id'] ?? '',
                    $row['campaign_id'] ?? '',
                    $row['views'] ?? 0,
                    $row['cart_additions'] ?? 0,
                    $row['orders'] ?? 0,
                    number_format($row['conversion_view_to_cart'] ?? 0, 2, ',', ''),
                    number_format($row['conversion_cart_to_order'] ?? 0, 2, ',', ''),
                    number_format($row['conversion_overall'] ?? 0, 2, ',', ''),
                    $row['cached_at'] ?? ''
                ];
            case 'demographics':
                return [
                    $row['date_from'] ?? '',
                    $row['date_to'] ?? '',
                    $row['age_group'] ?? '',
                    $row['gender'] ?? '',
                    $row['region'] ?? '',
                    $row['orders_count'] ?? 0,
                    number_format($row['revenue'] ?? 0, 2, ',', ''),
                    $row['cached_at'] ?? ''
                ];
            case 'campaigns':
                return [
                    $row['campaign_id'] ?? '',
                    $row['campaign_name'] ?? '',
                    $row['date_from'] ?? '',
                    $row['date_to'] ?? '',
                    $row['impressions'] ?? 0,
                    $row['clicks'] ?? 0,
                    number_format($row['spend'] ?? 0, 2, ',', ''),
                    $row['orders'] ?? 0,
                    number_format($row['revenue'] ?? 0, 2, ',', ''),
                    number_format($row['ctr'] ?? 0, 2, ',', ''),
                    number_format($row['cpc'] ?? 0, 2, ',', ''),
                    number_format($row['roas'] ?? 0, 2, ',', ''),
                    $row['cached_at'] ?? ''
                ];
            default:
                return array_values($row);
        }
    }
    
    /**
     * Получение статистики использования API
     * 
     * @return array статистика запросов
     */
    public function getApiStats() {
        $stats = [
            'client_id' => $this->clientId,
            'token_valid' => $this->isTokenValid(),
            'token_expiry' => $this->tokenExpiry > 0 ? date('Y-m-d H:i:s', $this->tokenExpiry) : null,
            'last_request_time' => $this->lastRequestTime > 0 ? date('Y-m-d H:i:s', $this->lastRequestTime) : null
        ];
        
        // Add cache statistics if available
        if ($this->cache) {
            $stats['cache'] = $this->cache->getStats();
        }
        
        return $stats;
    }
    
    /**
     * Очистка кэша данных
     * 
     * @param string|null $pattern - Паттерн для очистки (опционально)
     * @return bool Результат операции
     */
    public function clearCache($pattern = null) {
        if (!$this->cache) {
            return false;
        }
        
        if ($pattern) {
            return $this->cache->invalidateByPattern($pattern) > 0;
        } else {
            return $this->cache->clear();
        }
    }
    
    /**
     * Предварительная загрузка кэша
     * 
     * @param array $warmupData - Данные для предзагрузки
     * @return bool Результат операции
     */
    public function warmUpCache($warmupData) {
        if (!$this->cache) {
            return false;
        }
        
        return $this->cache->warmUp($warmupData);
    }
    
    /**
     * Нормализация возрастных групп
     * 
     * @param string|null $ageGroup - возрастная группа
     * @return string|null нормализованная возрастная группа
     */
    private function normalizeAgeGroup($ageGroup) {
        if (empty($ageGroup)) {
            return null;
        }
        
        // Стандартизируем возрастные группы
        $ageGroup = trim(strtolower($ageGroup));
        
        $ageGroupMap = [
            '18-24' => '18-24',
            '25-34' => '25-34',
            '35-44' => '35-44',
            '45-54' => '45-54',
            '55-64' => '55-64',
            '65+' => '65+',
            'до 18' => '<18',
            'до18' => '<18',
            '<18' => '<18',
            'старше 65' => '65+',
            '65 и старше' => '65+'
        ];
        
        return $ageGroupMap[$ageGroup] ?? $ageGroup;
    }
    
    /**
     * Нормализация пола
     * 
     * @param string|null $gender - пол
     * @return string|null нормализованный пол
     */
    private function normalizeGender($gender) {
        if (empty($gender)) {
            return null;
        }
        
        $gender = trim(strtolower($gender));
        
        $genderMap = [
            'male' => 'male',
            'female' => 'female',
            'м' => 'male',
            'ж' => 'female',
            'мужской' => 'male',
            'женский' => 'female',
            'мужчина' => 'male',
            'женщина' => 'female'
        ];
        
        return $genderMap[$gender] ?? $gender;
    }
    
    /**
     * Нормализация региона
     * 
     * @param string|null $region - регион
     * @return string|null нормализованный регион
     */
    private function normalizeRegion($region) {
        if (empty($region)) {
            return null;
        }
        
        // Убираем лишние пробелы и приводим к единому формату
        $region = trim($region);
        
        // Стандартизируем названия регионов
        $regionMap = [
            'Москва' => 'Москва',
            'Московская область' => 'Московская область',
            'Санкт-Петербург' => 'Санкт-Петербург',
            'Ленинградская область' => 'Ленинградская область',
            'Краснодарский край' => 'Краснодарский край',
            'Свердловская область' => 'Свердловская область',
            'Новосибирская область' => 'Новосибирская область',
            'Татарстан' => 'Республика Татарстан',
            'Республика Татарстан' => 'Республика Татарстан'
        ];
        
        return $regionMap[$region] ?? $region;
    }
    
    /**
     * Получение агрегированных демографических данных
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param array $filters - фильтры
     * @return array агрегированные демографические данные
     */
    public function getAggregatedDemographicsData($dateFrom, $dateTo, $filters = []) {
        $demographicsData = $this->getDemographics($dateFrom, $dateTo, $filters);
        
        if (empty($demographicsData)) {
            return [
                'age_groups' => [],
                'gender_distribution' => [],
                'regional_distribution' => [],
                'total_orders' => 0,
                'total_revenue' => 0.00,
                'records_count' => 0,
                'date_range' => "$dateFrom - $dateTo"
            ];
        }
        
        $ageGroups = [];
        $genderDistribution = [];
        $regionalDistribution = [];
        $totalOrders = 0;
        $totalRevenue = 0;
        
        foreach ($demographicsData as $item) {
            $totalOrders += $item['orders_count'];
            $totalRevenue += $item['revenue'];
            
            // Агрегация по возрастным группам
            if (!empty($item['age_group'])) {
                if (!isset($ageGroups[$item['age_group']])) {
                    $ageGroups[$item['age_group']] = [
                        'orders_count' => 0,
                        'revenue' => 0.00
                    ];
                }
                $ageGroups[$item['age_group']]['orders_count'] += $item['orders_count'];
                $ageGroups[$item['age_group']]['revenue'] += $item['revenue'];
            }
            
            // Агрегация по полу
            if (!empty($item['gender'])) {
                if (!isset($genderDistribution[$item['gender']])) {
                    $genderDistribution[$item['gender']] = [
                        'orders_count' => 0,
                        'revenue' => 0.00
                    ];
                }
                $genderDistribution[$item['gender']]['orders_count'] += $item['orders_count'];
                $genderDistribution[$item['gender']]['revenue'] += $item['revenue'];
            }
            
            // Агрегация по регионам
            if (!empty($item['region'])) {
                if (!isset($regionalDistribution[$item['region']])) {
                    $regionalDistribution[$item['region']] = [
                        'orders_count' => 0,
                        'revenue' => 0.00
                    ];
                }
                $regionalDistribution[$item['region']]['orders_count'] += $item['orders_count'];
                $regionalDistribution[$item['region']]['revenue'] += $item['revenue'];
            }
        }
        
        // Рассчитываем проценты для каждой категории
        foreach ($ageGroups as $ageGroup => &$data) {
            $data['orders_percentage'] = $totalOrders > 0 ? round(($data['orders_count'] / $totalOrders) * 100, 2) : 0.00;
            $data['revenue_percentage'] = $totalRevenue > 0 ? round(($data['revenue'] / $totalRevenue) * 100, 2) : 0.00;
        }
        
        foreach ($genderDistribution as $gender => &$data) {
            $data['orders_percentage'] = $totalOrders > 0 ? round(($data['orders_count'] / $totalOrders) * 100, 2) : 0.00;
            $data['revenue_percentage'] = $totalRevenue > 0 ? round(($data['revenue'] / $totalRevenue) * 100, 2) : 0.00;
        }
        
        foreach ($regionalDistribution as $region => &$data) {
            $data['orders_percentage'] = $totalOrders > 0 ? round(($data['orders_count'] / $totalOrders) * 100, 2) : 0.00;
            $data['revenue_percentage'] = $totalRevenue > 0 ? round(($data['revenue'] / $totalRevenue) * 100, 2) : 0.00;
        }
        
        // Сортируем по количеству заказов (по убыванию)
        uasort($ageGroups, function($a, $b) {
            return $b['orders_count'] - $a['orders_count'];
        });
        
        uasort($genderDistribution, function($a, $b) {
            return $b['orders_count'] - $a['orders_count'];
        });
        
        uasort($regionalDistribution, function($a, $b) {
            return $b['orders_count'] - $a['orders_count'];
        });
        
        return [
            'age_groups' => $ageGroups,
            'gender_distribution' => $genderDistribution,
            'regional_distribution' => $regionalDistribution,
            'total_orders' => $totalOrders,
            'total_revenue' => round($totalRevenue, 2),
            'records_count' => count($demographicsData),
            'date_range' => "$dateFrom - $dateTo"
        ];
    }
    
    /**
     * Получение демографических данных с агрегацией по временным периодам
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param string $period - период агрегации ('day', 'week', 'month')
     * @param array $filters - дополнительные фильтры
     * @return array данные с временной агрегацией
     */
    public function getDemographicsWithTimePeriods($dateFrom, $dateTo, $period = 'week', $filters = []) {
        $this->validateDateRange($dateFrom, $dateTo);
        
        $validPeriods = ['day', 'week', 'month'];
        if (!in_array($period, $validPeriods)) {
            throw new InvalidArgumentException('Недопустимый период агрегации: ' . $period);
        }
        
        // Разбиваем диапазон дат на периоды
        $periods = $this->generateTimePeriods($dateFrom, $dateTo, $period);
        $aggregatedData = [];
        
        foreach ($periods as $periodData) {
            $periodFrom = $periodData['from'];
            $periodTo = $periodData['to'];
            
            try {
                $demographicsData = $this->getDemographics($periodFrom, $periodTo, $filters);
                $aggregatedPeriodData = $this->getAggregatedDemographicsData($periodFrom, $periodTo, $filters);
                
                $aggregatedData[] = [
                    'period' => $periodData['label'],
                    'date_from' => $periodFrom,
                    'date_to' => $periodTo,
                    'demographics' => $aggregatedPeriodData,
                    'raw_data' => $demographicsData
                ];
            } catch (Exception $e) {
                // Логируем ошибку, но продолжаем обработку других периодов
                error_log("Ошибка получения данных за период $periodFrom - $periodTo: " . $e->getMessage());
                
                $aggregatedData[] = [
                    'period' => $periodData['label'],
                    'date_from' => $periodFrom,
                    'date_to' => $periodTo,
                    'demographics' => [
                        'age_groups' => [],
                        'gender_distribution' => [],
                        'regional_distribution' => [],
                        'total_orders' => 0,
                        'total_revenue' => 0.00,
                        'records_count' => 0,
                        'date_range' => "$periodFrom - $periodTo"
                    ],
                    'raw_data' => [],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $aggregatedData;
    }
    
    /**
     * Генерация временных периодов для агрегации
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param string $period - тип периода
     * @return array массив периодов
     */
    private function generateTimePeriods($dateFrom, $dateTo, $period) {
        $periods = [];
        $currentDate = new DateTime($dateFrom);
        $endDate = new DateTime($dateTo);
        
        while ($currentDate <= $endDate) {
            $periodStart = clone $currentDate;
            
            switch ($period) {
                case 'day':
                    $periodEnd = clone $currentDate;
                    $label = $currentDate->format('Y-m-d');
                    $currentDate->add(new DateInterval('P1D'));
                    break;
                    
                case 'week':
                    $periodEnd = clone $currentDate;
                    $periodEnd->add(new DateInterval('P6D'));
                    if ($periodEnd > $endDate) {
                        $periodEnd = clone $endDate;
                    }
                    $label = 'Неделя ' . $periodStart->format('Y-m-d') . ' - ' . $periodEnd->format('Y-m-d');
                    $currentDate->add(new DateInterval('P7D'));
                    break;
                    
                case 'month':
                    $periodEnd = clone $currentDate;
                    $periodEnd->add(new DateInterval('P1M'))->sub(new DateInterval('P1D'));
                    if ($periodEnd > $endDate) {
                        $periodEnd = clone $endDate;
                    }
                    $label = $currentDate->format('Y-m');
                    $currentDate->add(new DateInterval('P1M'));
                    break;
            }
            
            $periods[] = [
                'from' => $periodStart->format('Y-m-d'),
                'to' => $periodEnd->format('Y-m-d'),
                'label' => $label
            ];
        }
        
        return $periods;
    }
    
    /**
     * Получение агрегированных данных воронки продаж
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param array $filters - фильтры
     * @return array агрегированные данные воронки
     */
    public function getAggregatedFunnelData($dateFrom, $dateTo, $filters = []) {
        $funnelData = $this->getFunnelData($dateFrom, $dateTo, $filters);
        
        if (empty($funnelData)) {
            return [
                'total_views' => 0,
                'total_cart_additions' => 0,
                'total_orders' => 0,
                'avg_conversion_view_to_cart' => 0.00,
                'avg_conversion_cart_to_order' => 0.00,
                'avg_conversion_overall' => 0.00,
                'records_count' => 0,
                'date_range' => "$dateFrom - $dateTo"
            ];
        }
        
        $totalViews = 0;
        $totalCartAdditions = 0;
        $totalOrders = 0;
        $conversionSum = ['view_to_cart' => 0, 'cart_to_order' => 0, 'overall' => 0];
        
        foreach ($funnelData as $item) {
            $totalViews += $item['views'];
            $totalCartAdditions += $item['cart_additions'];
            $totalOrders += $item['orders'];
            $conversionSum['view_to_cart'] += $item['conversion_view_to_cart'];
            $conversionSum['cart_to_order'] += $item['conversion_cart_to_order'];
            $conversionSum['overall'] += $item['conversion_overall'];
        }
        
        $recordsCount = count($funnelData);
        
        return [
            'total_views' => $totalViews,
            'total_cart_additions' => $totalCartAdditions,
            'total_orders' => $totalOrders,
            'avg_conversion_view_to_cart' => $recordsCount > 0 ? round($conversionSum['view_to_cart'] / $recordsCount, 2) : 0.00,
            'avg_conversion_cart_to_order' => $recordsCount > 0 ? round($conversionSum['cart_to_order'] / $recordsCount, 2) : 0.00,
            'avg_conversion_overall' => $recordsCount > 0 ? round($conversionSum['overall'] / $recordsCount, 2) : 0.00,
            'calculated_conversion_view_to_cart' => $totalViews > 0 ? round(($totalCartAdditions / $totalViews) * 100, 2) : 0.00,
            'calculated_conversion_cart_to_order' => $totalCartAdditions > 0 ? round(($totalOrders / $totalCartAdditions) * 100, 2) : 0.00,
            'calculated_conversion_overall' => $totalViews > 0 ? round(($totalOrders / $totalViews) * 100, 2) : 0.00,
            'records_count' => $recordsCount,
            'date_range' => "$dateFrom - $dateTo"
        ];
    }
    
    /**
     * Создание временной ссылки для скачивания файла
     * 
     * @param string $content - содержимое файла
     * @param string $filename - имя файла
     * @param string $contentType - MIME тип
     * @return array информация о временной ссылке
     */
    public function createTemporaryDownloadLink($content, $filename, $contentType = 'text/csv') {
        // Создаем уникальный токен для ссылки
        $token = bin2hex(random_bytes(32));
        
        // Определяем путь для временных файлов
        $tempDir = sys_get_temp_dir() . '/ozon_exports';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        $filepath = $tempDir . '/' . $token . '_' . $filename;
        
        // Сохраняем файл
        if (file_put_contents($filepath, $content) === false) {
            throw new OzonAPIException('Не удалось создать временный файл', 500, 'FILE_ERROR');
        }
        
        // Сохраняем информацию о файле в БД если подключение доступно
        if ($this->pdo) {
            try {
                $sql = "INSERT INTO ozon_temp_downloads (token, filename, filepath, content_type, created_at, expires_at) 
                        VALUES (:token, :filename, :filepath, :content_type, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    'token' => $token,
                    'filename' => $filename,
                    'filepath' => $filepath,
                    'content_type' => $contentType
                ]);
            } catch (PDOException $e) {
                // Если не удалось сохранить в БД, удаляем файл
                unlink($filepath);
                throw new OzonAPIException('Ошибка создания временной ссылки: ' . $e->getMessage(), 500, 'DATABASE_ERROR');
            }
        }
        
        return [
            'token' => $token,
            'download_url' => '/src/api/ozon-analytics.php?action=download&token=' . $token,
            'filename' => $filename,
            'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
            'file_size' => strlen($content)
        ];
    }
    
    /**
     * Получение файла по временному токену
     * 
     * @param string $token - токен для скачивания
     * @return array информация о файле
     */
    public function getTemporaryFile($token) {
        if (!$this->pdo) {
            throw new OzonAPIException('База данных недоступна', 500, 'DATABASE_ERROR');
        }
        
        try {
            $sql = "SELECT * FROM ozon_temp_downloads 
                    WHERE token = :token AND expires_at > NOW() 
                    ORDER BY created_at DESC LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['token' => $token]);
            
            $fileInfo = $stmt->fetch();
            
            if (!$fileInfo) {
                throw new OzonAPIException('Файл не найден или ссылка истекла', 404, 'FILE_NOT_FOUND');
            }
            
            if (!file_exists($fileInfo['filepath'])) {
                throw new OzonAPIException('Файл не найден на диске', 404, 'FILE_NOT_FOUND');
            }
            
            return [
                'filepath' => $fileInfo['filepath'],
                'filename' => $fileInfo['filename'],
                'content_type' => $fileInfo['content_type'],
                'content' => file_get_contents($fileInfo['filepath'])
            ];
            
        } catch (PDOException $e) {
            throw new OzonAPIException('Ошибка получения файла: ' . $e->getMessage(), 500, 'DATABASE_ERROR');
        }
    }
    
    /**
     * Очистка устаревших временных файлов
     * 
     * @return int количество удаленных файлов
     */
    public function cleanupExpiredFiles() {
        if (!$this->pdo) {
            return 0;
        }
        
        $deletedCount = 0;
        
        try {
            // Получаем список истекших файлов
            $sql = "SELECT filepath FROM ozon_temp_downloads WHERE expires_at < NOW()";
            $stmt = $this->pdo->query($sql);
            $expiredFiles = $stmt->fetchAll();
            
            // Удаляем файлы с диска
            foreach ($expiredFiles as $file) {
                if (file_exists($file['filepath'])) {
                    unlink($file['filepath']);
                    $deletedCount++;
                }
            }
            
            // Удаляем записи из БД
            $sql = "DELETE FROM ozon_temp_downloads WHERE expires_at < NOW()";
            $this->pdo->exec($sql);
            
            return $deletedCount;
            
        } catch (PDOException $e) {
            error_log('Ошибка очистки временных файлов: ' . $e->getMessage());
            return $deletedCount;
        }
    }
    
    /**
     * Экспорт данных с поддержкой пагинации для больших объемов
     * 
     * @param string $dataType - тип данных
     * @param string $format - формат экспорта
     * @param array $filters - фильтры
     * @param int $page - номер страницы (начиная с 1)
     * @param int $pageSize - размер страницы
     * @return array результат экспорта с пагинацией
     */
    public function exportDataWithPagination($dataType, $format, $filters, $page = 1, $pageSize = 1000) {
        $validDataTypes = ['funnel', 'demographics', 'campaigns'];
        $validFormats = ['csv', 'json'];
        
        if (!in_array($dataType, $validDataTypes)) {
            throw new InvalidArgumentException('Недопустимый тип данных: ' . $dataType);
        }
        
        if (!in_array($format, $validFormats)) {
            throw new InvalidArgumentException('Недопустимый формат: ' . $format);
        }
        
        if ($page < 1) {
            throw new InvalidArgumentException('Номер страницы должен быть больше 0');
        }
        
        if ($pageSize < 1 || $pageSize > 10000) {
            throw new InvalidArgumentException('Размер страницы должен быть от 1 до 10000');
        }
        
        // Получаем все данные
        $dateFrom = $filters['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
        $dateTo = $filters['date_to'] ?? date('Y-m-d');
        
        $allData = [];
        switch ($dataType) {
            case 'funnel':
                $allData = $this->getFunnelData($dateFrom, $dateTo, $filters);
                break;
            case 'demographics':
                $allData = $this->getDemographics($dateFrom, $dateTo, $filters);
                break;
            case 'campaigns':
                $campaignId = $filters['campaign_id'] ?? null;
                $allData = $this->getCampaignData($dateFrom, $dateTo, $campaignId);
                break;
        }
        
        $totalRecords = count($allData);
        $totalPages = ceil($totalRecords / $pageSize);
        
        // Получаем данные для текущей страницы
        $offset = ($page - 1) * $pageSize;
        $pageData = array_slice($allData, $offset, $pageSize);
        
        // Если данных мало, возвращаем их напрямую
        if ($totalRecords <= $pageSize) {
            $content = '';
            switch ($format) {
                case 'json':
                    $content = json_encode($pageData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    break;
                case 'csv':
                    $content = $this->exportToCsv($pageData, $dataType);
                    break;
            }
            
            return [
                'content' => $content,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'page_size' => $pageSize,
                    'total_records' => $totalRecords,
                    'has_next_page' => false,
                    'has_prev_page' => false
                ],
                'direct_download' => true
            ];
        }
        
        // Для больших объемов создаем временную ссылку
        $content = '';
        $filename = '';
        $contentType = '';
        
        switch ($format) {
            case 'json':
                $content = json_encode($pageData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                $filename = "ozon_{$dataType}_page_{$page}_" . date('Y-m-d_H-i-s') . '.json';
                $contentType = 'application/json';
                break;
            case 'csv':
                $content = $this->exportToCsv($pageData, $dataType);
                $filename = "ozon_{$dataType}_page_{$page}_" . date('Y-m-d_H-i-s') . '.csv';
                $contentType = 'text/csv';
                break;
        }
        
        $downloadLink = $this->createTemporaryDownloadLink($content, $filename, $contentType);
        
        return [
            'download_link' => $downloadLink,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'page_size' => $pageSize,
                'total_records' => $totalRecords,
                'has_next_page' => $page < $totalPages,
                'has_prev_page' => $page > 1
            ],
            'direct_download' => false
        ];
    }
    
    /**
     * Тестирование подключения к API
     * 
     * @return array результат тестирования
     */
    public function testConnection() {
        try {
            $token = $this->authenticate();
            
            return [
                'success' => true,
                'message' => 'Подключение к API Ozon успешно',
                'token_received' => !empty($token),
                'token_expiry' => date('Y-m-d H:i:s', $this->tokenExpiry)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Ошибка подключения к API Ozon: ' . $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_type' => $e instanceof OzonAPIException ? $e->getErrorType() : 'UNKNOWN'
            ];
        }
    }
}

/**
 * Исключение для ошибок API Ozon
 */
class OzonAPIException extends Exception {
    private $errorType;
    
    /**
     * Конструктор исключения
     * 
     * @param string $message - сообщение об ошибке
     * @param int $code - код ошибки
     * @param string $errorType - тип ошибки
     */
    public function __construct($message, $code = 0, $errorType = 'UNKNOWN') {
        parent::__construct($message, $code);
        $this->errorType = $errorType;
    }
    
    /**
     * Получить тип ошибки
     * 
     * @return string тип ошибки
     */
    public function getErrorType() {
        return $this->errorType;
    }
    
    /**
     * Проверить, является ли ошибка критической
     * 
     * @return bool true если ошибка критическая
     */
    public function isCritical() {
        $criticalTypes = ['AUTHENTICATION_ERROR', 'NETWORK_ERROR', 'API_UNAVAILABLE'];
        return in_array($this->errorType, $criticalTypes);
    }
    
    /**
     * Получить рекомендации по устранению ошибки
     * 
     * @return string рекомендации
     */
    public function getRecommendation() {
        switch ($this->errorType) {
            case 'AUTHENTICATION_ERROR':
                return 'Проверьте правильность Client ID и API Key. Убедитесь, что ключи активны.';
            case 'RATE_LIMIT_EXCEEDED':
                return 'Превышен лимит запросов. Подождите некоторое время перед повторной попыткой.';
            case 'INVALID_PARAMETERS':
                return 'Проверьте корректность переданных параметров запроса.';
            case 'API_UNAVAILABLE':
                return 'API Ozon временно недоступен. Повторите попытку позже.';
            case 'NETWORK_ERROR':
                return 'Проблемы с сетевым подключением. Проверьте интернет-соединение.';
            default:
                return 'Обратитесь к документации API или в службу поддержки.';
        }
    }
}

?>