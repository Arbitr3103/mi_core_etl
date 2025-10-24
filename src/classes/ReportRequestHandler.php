<?php
/**
 * ReportRequestHandler Class - Управление запросами к API отчетов Ozon
 * 
 * Обрабатывает запросы на создание отчетов, проверку статуса и скачивание файлов
 * через API отчетов Ozon. Включает обработку ошибок и retry логику.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class ReportRequestHandler {
    
    // API endpoints для отчетов Ozon
    const ENDPOINT_CREATE_REPORT = '/v1/report/warehouse/stock';
    const ENDPOINT_REPORT_INFO = '/v1/report/info';
    
    // Константы для retry логики
    const MAX_RETRIES = 3;
    const RETRY_DELAYS = [30, 60, 120]; // секунды между попытками
    
    private $ozonAPI;
    private $logger;
    
    /**
     * Конструктор класса
     * 
     * @param OzonAnalyticsAPI $ozonAPI - экземпляр API класса Ozon
     */
    public function __construct(OzonAnalyticsAPI $ozonAPI) {
        $this->ozonAPI = $ozonAPI;
        $this->initializeLogger();
    }
    
    /**
     * Инициализация системы логирования
     */
    private function initializeLogger() {
        $this->logger = [
            'info' => function($message, $context = []) {
                error_log("[INFO] ReportRequestHandler: $message " . json_encode($context));
            },
            'warning' => function($message, $context = []) {
                error_log("[WARNING] ReportRequestHandler: $message " . json_encode($context));
            },
            'error' => function($message, $context = []) {
                error_log("[ERROR] ReportRequestHandler: $message " . json_encode($context));
            }
        ];
    }
    
    /**
     * Создание отчета по остаткам на складах
     * 
     * @param array $parameters - параметры отчета
     * @return string код отчета
     * @throws Exception при ошибках создания отчета
     */
    public function createWarehouseStockReport(array $parameters): string {
        ($this->logger['info'])("Creating warehouse stock report", [
            'parameters' => $parameters
        ]);
        
        // Валидируем параметры отчета
        $this->validateReportParameters($parameters);
        
        // Подготавливаем данные для API запроса
        $requestData = $this->prepareReportRequestData($parameters);
        
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < self::MAX_RETRIES) {
            try {
                ($this->logger['info'])("Attempting to create report", [
                    'attempt' => $attempt + 1,
                    'max_attempts' => self::MAX_RETRIES
                ]);
                
                // Выполняем запрос к API
                $response = $this->makeAPIRequest('POST', self::ENDPOINT_CREATE_REPORT, $requestData);
                
                // Извлекаем код отчета из ответа
                $reportCode = $this->extractReportCodeFromResponse($response);
                
                ($this->logger['info'])("Warehouse stock report created successfully", [
                    'report_code' => $reportCode,
                    'attempt' => $attempt + 1
                ]);
                
                return $reportCode;
                
            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;
                
                ($this->logger['warning'])("Report creation attempt failed", [
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode()
                ]);
                
                // Если это не последняя попытка, ждем перед повтором
                if ($attempt < self::MAX_RETRIES) {
                    $delay = self::RETRY_DELAYS[$attempt - 1] ?? 60;
                    ($this->logger['info'])("Waiting before retry", ['delay_seconds' => $delay]);
                    sleep($delay);
                }
            }
        }
        
        // Если все попытки неуспешны, выбрасываем последнее исключение
        ($this->logger['error'])("All report creation attempts failed", [
            'total_attempts' => self::MAX_RETRIES,
            'last_error' => $lastException->getMessage()
        ]);
        
        throw new Exception(
            "Failed to create warehouse stock report after " . self::MAX_RETRIES . " attempts: " . 
            $lastException->getMessage(),
            $lastException->getCode()
        );
    }
    
    /**
     * Получение информации о статусе отчета
     * 
     * @param string $reportCode - код отчета
     * @return array информация об отчете
     * @throws Exception при ошибках получения информации
     */
    public function getReportInfo(string $reportCode): array {
        ($this->logger['info'])("Getting report info", ['report_code' => $reportCode]);
        
        if (empty($reportCode)) {
            throw new InvalidArgumentException("Report code cannot be empty");
        }
        
        $requestData = [
            'code' => $reportCode
        ];
        
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < self::MAX_RETRIES) {
            try {
                ($this->logger['info'])("Attempting to get report info", [
                    'report_code' => $reportCode,
                    'attempt' => $attempt + 1
                ]);
                
                // Выполняем запрос к API
                $response = $this->makeAPIRequest('POST', self::ENDPOINT_REPORT_INFO, $requestData);
                
                // Обрабатываем ответ
                $reportInfo = $this->processReportInfoResponse($response, $reportCode);
                
                ($this->logger['info'])("Report info retrieved successfully", [
                    'report_code' => $reportCode,
                    'status' => $reportInfo['status'] ?? 'unknown',
                    'attempt' => $attempt + 1
                ]);
                
                return $reportInfo;
                
            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;
                
                ($this->logger['warning'])("Get report info attempt failed", [
                    'report_code' => $reportCode,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                // Для некоторых ошибок не нужно повторять попытки
                if ($this->isNonRetryableError($e)) {
                    break;
                }
                
                // Если это не последняя попытка, ждем перед повтором
                if ($attempt < self::MAX_RETRIES) {
                    $delay = min(30, $attempt * 10); // Более короткие задержки для проверки статуса
                    sleep($delay);
                }
            }
        }
        
        ($this->logger['error'])("Failed to get report info", [
            'report_code' => $reportCode,
            'total_attempts' => $attempt,
            'last_error' => $lastException->getMessage()
        ]);
        
        throw new Exception(
            "Failed to get report info for code $reportCode: " . $lastException->getMessage(),
            $lastException->getCode()
        );
    }
    
    /**
     * Скачивание файла отчета
     * 
     * @param string $downloadUrl - URL для скачивания
     * @return string содержимое файла
     * @throws Exception при ошибках скачивания
     */
    public function downloadReportFile(string $downloadUrl): string {
        ($this->logger['info'])("Downloading report file", ['download_url' => $downloadUrl]);
        
        if (empty($downloadUrl)) {
            throw new InvalidArgumentException("Download URL cannot be empty");
        }
        
        // Валидируем URL
        if (!filter_var($downloadUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException("Invalid download URL: $downloadUrl");
        }
        
        $attempt = 0;
        $lastException = null;
        
        while ($attempt < self::MAX_RETRIES) {
            try {
                ($this->logger['info'])("Attempting to download file", [
                    'download_url' => $downloadUrl,
                    'attempt' => $attempt + 1
                ]);
                
                // Скачиваем файл с помощью cURL
                $fileContent = $this->downloadFileWithCurl($downloadUrl);
                
                // Валидируем содержимое файла
                $this->validateDownloadedContent($fileContent);
                
                ($this->logger['info'])("Report file downloaded successfully", [
                    'download_url' => $downloadUrl,
                    'file_size' => strlen($fileContent),
                    'attempt' => $attempt + 1
                ]);
                
                return $fileContent;
                
            } catch (Exception $e) {
                $lastException = $e;
                $attempt++;
                
                ($this->logger['warning'])("File download attempt failed", [
                    'download_url' => $downloadUrl,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                // Если это не последняя попытка, ждем перед повтором
                if ($attempt < self::MAX_RETRIES) {
                    $delay = min(60, $attempt * 20); // Увеличиваем задержку для скачивания файлов
                    sleep($delay);
                }
            }
        }
        
        ($this->logger['error'])("Failed to download report file", [
            'download_url' => $downloadUrl,
            'total_attempts' => self::MAX_RETRIES,
            'last_error' => $lastException->getMessage()
        ]);
        
        throw new Exception(
            "Failed to download report file after " . self::MAX_RETRIES . " attempts: " . 
            $lastException->getMessage(),
            $lastException->getCode()
        );
    }
    
    /**
     * Валидация параметров отчета
     * 
     * @param array $parameters - параметры для валидации
     * @return bool true если параметры валидны
     * @throws InvalidArgumentException при некорректных параметрах
     */
    public function validateReportParameters(array $parameters): bool {
        // Проверяем обязательные параметры
        $requiredParams = ['report_type'];
        foreach ($requiredParams as $param) {
            if (!isset($parameters[$param]) || empty($parameters[$param])) {
                throw new InvalidArgumentException("Required parameter '$param' is missing or empty");
            }
        }
        
        // Проверяем тип отчета
        $validReportTypes = ['warehouse_stock'];
        if (!in_array($parameters['report_type'], $validReportTypes)) {
            throw new InvalidArgumentException(
                "Invalid report type: " . $parameters['report_type'] . 
                ". Valid types: " . implode(', ', $validReportTypes)
            );
        }
        
        // Валидируем даты если указаны
        if (isset($parameters['date_from']) && !$this->isValidDate($parameters['date_from'])) {
            throw new InvalidArgumentException("Invalid date_from format: " . $parameters['date_from']);
        }
        
        if (isset($parameters['date_to']) && !$this->isValidDate($parameters['date_to'])) {
            throw new InvalidArgumentException("Invalid date_to format: " . $parameters['date_to']);
        }
        
        // Проверяем логику дат
        if (isset($parameters['date_from']) && isset($parameters['date_to'])) {
            if (strtotime($parameters['date_from']) > strtotime($parameters['date_to'])) {
                throw new InvalidArgumentException("date_from cannot be later than date_to");
            }
        }
        
        ($this->logger['info'])("Report parameters validated successfully", $parameters);
        
        return true;
    }
    
    /**
     * Подготовка данных для API запроса создания отчета
     * 
     * @param array $parameters - параметры отчета
     * @return array данные для API запроса
     */
    private function prepareReportRequestData(array $parameters): array {
        $requestData = [
            'language' => 'DEFAULT', // Язык отчета
            'reportType' => 'ALL_ANALYTICS_DATA' // Тип отчета для Ozon API
        ];
        
        // Добавляем фильтры если указаны
        $filters = [];
        
        // Фильтр по датам
        if (isset($parameters['date_from']) && isset($parameters['date_to'])) {
            $filters['date'] = [
                'from' => $parameters['date_from'],
                'to' => $parameters['date_to']
            ];
        }
        
        // Фильтр по складам
        if (!empty($parameters['warehouse_filter'])) {
            $filters['warehouse'] = $parameters['warehouse_filter'];
        }
        
        // Фильтр по товарам
        if (!empty($parameters['product_filter'])) {
            $filters['offer_id'] = $parameters['product_filter'];
        }
        
        if (!empty($filters)) {
            $requestData['filter'] = $filters;
        }
        
        return $requestData;
    }
    
    /**
     * Выполнение API запроса
     * 
     * @param string $method - HTTP метод
     * @param string $endpoint - API endpoint
     * @param array $data - данные запроса
     * @return array ответ API
     * @throws Exception при ошибках запроса
     */
    private function makeAPIRequest(string $method, string $endpoint, array $data = []): array {
        try {
            // Используем существующий метод OzonAnalyticsAPI для выполнения запросов
            $url = OzonAnalyticsAPI::API_BASE_URL . $endpoint;
            
            // Получаем заголовки аутентификации
            $headers = [
                'Content-Type: application/json',
                'Client-Id: ' . $this->getClientId(),
                'Api-Key: ' . $this->getApiKey()
            ];
            
            // Выполняем запрос через cURL
            $response = $this->executeCurlRequest($method, $url, $data, $headers);
            
            return $response;
            
        } catch (Exception $e) {
            ($this->logger['error'])("API request failed", [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Выполнение cURL запроса
     * 
     * @param string $method - HTTP метод
     * @param string $url - URL запроса
     * @param array $data - данные запроса
     * @param array $headers - заголовки запроса
     * @return array ответ API
     * @throws Exception при ошибках запроса
     */
    private function executeCurlRequest(string $method, string $url, array $data, array $headers): array {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Manhattan-StockReports/1.0'
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
            throw new Exception("cURL error: $curlError");
        }
        
        // Обрабатываем HTTP коды ошибок
        $this->handleHttpResponse($httpCode, $response);
        
        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("JSON decode error: " . json_last_error_msg());
        }
        
        return $decodedResponse;
    }
    
    /**
     * Обработка HTTP ответа
     * 
     * @param int $httpCode - HTTP код ответа
     * @param string $response - тело ответа
     * @throws Exception при ошибках HTTP
     */
    private function handleHttpResponse(int $httpCode, string $response): void {
        switch ($httpCode) {
            case 200:
            case 201:
                return; // Успешный ответ
                
            case 400:
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['message'] ?? 'Bad request';
                throw new Exception("API error (400): $errorMessage", 400);
                
            case 401:
                throw new Exception("Authentication error (401): Invalid credentials", 401);
                
            case 403:
                throw new Exception("Access forbidden (403): Insufficient permissions", 403);
                
            case 404:
                throw new Exception("Not found (404): Resource not found", 404);
                
            case 429:
                throw new Exception("Rate limit exceeded (429): Too many requests", 429);
                
            case 500:
                throw new Exception("Internal server error (500): API server error", 500);
                
            case 503:
                throw new Exception("Service unavailable (503): API temporarily unavailable", 503);
                
            default:
                throw new Exception("HTTP error ($httpCode): Unexpected response code", $httpCode);
        }
    }
    
    /**
     * Извлечение кода отчета из ответа API
     * 
     * @param array $response - ответ API
     * @return string код отчета
     * @throws Exception если код отчета не найден
     */
    private function extractReportCodeFromResponse(array $response): string {
        // Проверяем различные возможные структуры ответа Ozon API
        if (isset($response['result']['code'])) {
            return $response['result']['code'];
        }
        
        if (isset($response['code'])) {
            return $response['code'];
        }
        
        if (isset($response['data']['code'])) {
            return $response['data']['code'];
        }
        
        // Если код не найден, выбрасываем исключение
        throw new Exception("Report code not found in API response: " . json_encode($response));
    }
    
    /**
     * Обработка ответа с информацией об отчете
     * 
     * @param array $response - ответ API
     * @param string $reportCode - код отчета
     * @return array обработанная информация об отчете
     */
    private function processReportInfoResponse(array $response, string $reportCode): array {
        $reportInfo = [
            'report_code' => $reportCode,
            'status' => 'UNKNOWN',
            'download_url' => null,
            'file_size' => null,
            'error_message' => null,
            'created_at' => null,
            'completed_at' => null
        ];
        
        // Извлекаем данные из различных возможных структур ответа
        $data = $response['result'] ?? $response['data'] ?? $response;
        
        // Статус отчета
        if (isset($data['status'])) {
            $reportInfo['status'] = strtoupper($data['status']);
        }
        
        // URL для скачивания
        if (isset($data['file'])) {
            $reportInfo['download_url'] = $data['file'];
        } elseif (isset($data['download_url'])) {
            $reportInfo['download_url'] = $data['download_url'];
        }
        
        // Размер файла
        if (isset($data['file_size'])) {
            $reportInfo['file_size'] = $data['file_size'];
        }
        
        // Сообщение об ошибке
        if (isset($data['error'])) {
            $reportInfo['error_message'] = $data['error'];
        }
        
        // Даты создания и завершения
        if (isset($data['created_at'])) {
            $reportInfo['created_at'] = $data['created_at'];
        }
        
        if (isset($data['completed_at'])) {
            $reportInfo['completed_at'] = $data['completed_at'];
        }
        
        return $reportInfo;
    }
    
    /**
     * Скачивание файла с помощью cURL
     * 
     * @param string $url - URL файла
     * @return string содержимое файла
     * @throws Exception при ошибках скачивания
     */
    private function downloadFileWithCurl(string $url): string {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300, // 5 минут для скачивания файла
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Manhattan-StockReports/1.0'
        ]);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        curl_close($ch);
        
        if ($content === false) {
            throw new Exception("Failed to download file: $curlError");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Failed to download file: HTTP $httpCode");
        }
        
        return $content;
    }
    
    /**
     * Валидация скачанного содержимого
     * 
     * @param string $content - содержимое файла
     * @throws Exception если содержимое некорректно
     */
    private function validateDownloadedContent(string $content): void {
        if (empty($content)) {
            throw new Exception("Downloaded file is empty");
        }
        
        // Проверяем минимальный размер файла (должен быть больше 10 байт)
        if (strlen($content) < 10) {
            throw new Exception("Downloaded file is too small: " . strlen($content) . " bytes");
        }
        
        // Проверяем, что это похоже на CSV файл
        $firstLine = strtok($content, "\n");
        if (empty($firstLine) || strpos($firstLine, ',') === false) {
            throw new Exception("Downloaded file does not appear to be a valid CSV");
        }
    }
    
    /**
     * Проверка, является ли ошибка неповторяемой
     * 
     * @param Exception $exception - исключение для проверки
     * @return bool true если ошибку не стоит повторять
     */
    private function isNonRetryableError(Exception $exception): bool {
        $nonRetryableCodes = [400, 401, 403, 404];
        return in_array($exception->getCode(), $nonRetryableCodes);
    }
    
    /**
     * Проверка валидности даты
     * 
     * @param string $date - дата для проверки
     * @return bool true если дата валидна
     */
    private function isValidDate(string $date): bool {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
    
    /**
     * Получение Client ID из OzonAnalyticsAPI
     * 
     * @return string Client ID
     */
    private function getClientId(): string {
        // Используем рефлексию для доступа к приватному свойству
        $reflection = new ReflectionClass($this->ozonAPI);
        $property = $reflection->getProperty('clientId');
        $property->setAccessible(true);
        return $property->getValue($this->ozonAPI);
    }
    
    /**
     * Получение API Key из OzonAnalyticsAPI
     * 
     * @return string API Key
     */
    private function getApiKey(): string {
        // Используем рефлексию для доступа к приватному свойству
        $reflection = new ReflectionClass($this->ozonAPI);
        $property = $reflection->getProperty('apiKey');
        $property->setAccessible(true);
        return $property->getValue($this->ozonAPI);
    }
}