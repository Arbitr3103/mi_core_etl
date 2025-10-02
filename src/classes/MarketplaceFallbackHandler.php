<?php
/**
 * MarketplaceFallbackHandler Class - Обработка ошибок и резервные механизмы для маркетплейсов
 * 
 * Предоставляет методы для обработки ошибок, отсутствующих данных и создания
 * пользовательских сообщений при работе с данными маркетплейсов
 * 
 * @version 1.0
 * @author Manhattan System
 */

class MarketplaceFallbackHandler {
    
    // Типы ошибок
    const ERROR_NO_DATA = 'NO_DATA';
    const ERROR_MARKETPLACE_NOT_FOUND = 'MARKETPLACE_NOT_FOUND';
    const ERROR_INVALID_MARKETPLACE = 'INVALID_MARKETPLACE';
    const ERROR_DATA_INCONSISTENCY = 'DATA_INCONSISTENCY';
    const ERROR_DATABASE_ERROR = 'DATABASE_ERROR';
    const ERROR_VALIDATION_FAILED = 'VALIDATION_FAILED';
    
    // Уровни критичности
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';
    
    private $pdo;
    private $logFile;
    
    /**
     * Конструктор класса
     * 
     * @param PDO $pdo - подключение к базе данных
     * @param string $logFile - путь к файлу логов
     */
    public function __construct(PDO $pdo, $logFile = 'marketplace_errors.log') {
        $this->pdo = $pdo;
        $this->logFile = $logFile;
    }
    
    /**
     * Обработать отсутствие данных по маркетплейсу
     * 
     * @param string $marketplace - маркетплейс
     * @param string $period - период запроса
     * @param array $context - дополнительный контекст
     * @return array структурированный ответ с fallback данными
     */
    public function handleMissingData($marketplace, $period, $context = []) {
        $this->logError(self::ERROR_NO_DATA, "No data found for marketplace: $marketplace, period: $period", $context);
        
        $marketplaceName = MarketplaceDetector::getMarketplaceName($marketplace);
        $marketplaceIcon = MarketplaceDetector::getMarketplaceIcon($marketplace);
        
        return [
            'success' => true,
            'has_data' => false,
            'marketplace' => $marketplace,
            'marketplace_name' => $marketplaceName,
            'marketplace_icon' => $marketplaceIcon,
            'period' => $period,
            'message' => "Данные по маркетплейсу «{$marketplaceName}» за указанный период отсутствуют",
            'user_message' => "За выбранный период нет данных по {$marketplaceName}. Попробуйте выбрать другой период или проверьте настройки импорта данных.",
            'fallback_data' => $this->createEmptyMarketplaceData($marketplace),
            'suggestions' => $this->getSuggestionsForMissingData($marketplace, $period, $context),
            'error_code' => self::ERROR_NO_DATA,
            'severity' => self::SEVERITY_MEDIUM
        ];
    }
    
    /**
     * Обработать неопределенный маркетплейс
     * 
     * @param string|null $source - источник данных
     * @param array $context - дополнительный контекст
     * @return string классификация маркетплейса с fallback
     */
    public function handleUnknownMarketplace($source, $context = []) {
        $this->logError(self::ERROR_MARKETPLACE_NOT_FOUND, "Unknown marketplace for source: $source", $context);
        
        // Попытка определить маркетплейс по дополнительным признакам
        $detectedMarketplace = $this->attemptMarketplaceDetection($source, $context);
        
        if ($detectedMarketplace !== MarketplaceDetector::UNKNOWN) {
            $this->logInfo("Successfully detected marketplace: $detectedMarketplace for source: $source");
            return $detectedMarketplace;
        }
        
        // Если не удалось определить, возвращаем UNKNOWN
        return MarketplaceDetector::UNKNOWN;
    }
    
    /**
     * Обработать пустые результаты запроса
     * 
     * @param string $marketplace - маркетплейс
     * @param array $queryParams - параметры запроса
     * @return array структурированный ответ с пустыми данными
     */
    public function handleEmptyResults($marketplace, $queryParams = []) {
        $this->logError(self::ERROR_NO_DATA, "Empty results for marketplace: $marketplace", $queryParams);
        
        $marketplaceName = MarketplaceDetector::getMarketplaceName($marketplace);
        
        return [
            'success' => true,
            'has_data' => false,
            'marketplace' => $marketplace,
            'marketplace_name' => $marketplaceName,
            'data' => $this->createEmptyMarketplaceData($marketplace),
            'message' => "По маркетплейсу «{$marketplaceName}» нет данных для отображения",
            'user_message' => "Нет данных для отображения по {$marketplaceName}. Возможно, данные еще не загружены или период не содержит продаж.",
            'suggestions' => [
                'Проверьте настройки импорта данных',
                'Убедитесь, что период содержит даты с продажами',
                'Обратитесь к администратору системы'
            ]
        ];
    }
    
    /**
     * Обработать ошибку валидации данных
     * 
     * @param string $validationType - тип валидации
     * @param array $errors - список ошибок валидации
     * @param array $context - контекст ошибки
     * @return array структурированный ответ об ошибке
     */
    public function handleValidationError($validationType, $errors, $context = []) {
        $this->logError(self::ERROR_VALIDATION_FAILED, "Validation failed: $validationType", [
            'errors' => $errors,
            'context' => $context
        ]);
        
        return [
            'success' => false,
            'error_code' => self::ERROR_VALIDATION_FAILED,
            'error_type' => $validationType,
            'message' => 'Ошибка валидации данных',
            'user_message' => 'Обнаружены проблемы с качеством данных. Обратитесь к администратору системы.',
            'errors' => $errors,
            'severity' => self::SEVERITY_HIGH,
            'suggestions' => [
                'Проверьте корректность настроек импорта',
                'Убедитесь в целостности данных в источниках',
                'Обратитесь к службе поддержки'
            ]
        ];
    }
    
    /**
     * Обработать ошибку базы данных
     * 
     * @param Exception $exception - исключение базы данных
     * @param array $context - контекст запроса
     * @return array структурированный ответ об ошибке
     */
    public function handleDatabaseError($exception, $context = []) {
        $this->logError(self::ERROR_DATABASE_ERROR, "Database error: " . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
            'context' => $context
        ]);
        
        return [
            'success' => false,
            'error_code' => self::ERROR_DATABASE_ERROR,
            'message' => 'Ошибка при обращении к базе данных',
            'user_message' => 'Временные проблемы с доступом к данным. Попробуйте повторить запрос через несколько минут.',
            'severity' => self::SEVERITY_CRITICAL,
            'suggestions' => [
                'Попробуйте обновить страницу',
                'Проверьте подключение к интернету',
                'Обратитесь к администратору, если проблема повторяется'
            ]
        ];
    }
    
    /**
     * Создать пустую структуру данных для маркетплейса
     * 
     * @param string $marketplace - маркетплейс
     * @return array пустая структура данных
     */
    private function createEmptyMarketplaceData($marketplace) {
        return [
            'marketplace' => $marketplace,
            'marketplace_name' => MarketplaceDetector::getMarketplaceName($marketplace),
            'marketplace_icon' => MarketplaceDetector::getMarketplaceIcon($marketplace),
            'kpi' => [
                'total_revenue' => 0,
                'total_orders' => 0,
                'total_profit' => 0,
                'avg_margin_percent' => null,
                'unique_products' => 0,
                'days_count' => 0
            ],
            'top_products' => [],
            'daily_chart' => [],
            'recommendations' => [],
            'has_data' => false
        ];
    }
    
    /**
     * Получить предложения для случая отсутствующих данных
     * 
     * @param string $marketplace - маркетплейс
     * @param string $period - период
     * @param array $context - контекст
     * @return array список предложений
     */
    private function getSuggestionsForMissingData($marketplace, $period, $context) {
        $suggestions = [];
        
        // Базовые предложения
        $suggestions[] = 'Проверьте правильность выбранного периода';
        $suggestions[] = 'Убедитесь, что данные по маркетплейсу импортируются корректно';
        
        // Специфичные для маркетплейса предложения
        switch ($marketplace) {
            case MarketplaceDetector::OZON:
                $suggestions[] = 'Проверьте настройки API интеграции с Ozon';
                $suggestions[] = 'Убедитесь, что SKU товаров корректно заполнены в поле sku_ozon';
                break;
                
            case MarketplaceDetector::WILDBERRIES:
                $suggestions[] = 'Проверьте настройки API интеграции с Wildberries';
                $suggestions[] = 'Убедитесь, что SKU товаров корректно заполнены в поле sku_wb';
                break;
        }
        
        // Проверяем, есть ли данные в соседних периодах
        if ($this->hasDataInAdjacentPeriods($marketplace, $period)) {
            $suggestions[] = 'Попробуйте расширить период поиска - в соседних датах есть данные';
        }
        
        $suggestions[] = 'Обратитесь к администратору системы для диагностики';
        
        return $suggestions;
    }
    
    /**
     * Попытка определить маркетплейс по дополнительным признакам
     * 
     * @param string|null $source - источник
     * @param array $context - дополнительный контекст
     * @return string определенный маркетплейс
     */
    private function attemptMarketplaceDetection($source, $context) {
        // Попытка по URL или домену
        if (!empty($context['url'])) {
            if (strpos(strtolower($context['url']), 'ozon') !== false) {
                return MarketplaceDetector::OZON;
            }
            if (strpos(strtolower($context['url']), 'wildberries') !== false || 
                strpos(strtolower($context['url']), 'wb.ru') !== false) {
                return MarketplaceDetector::WILDBERRIES;
            }
        }
        
        // Попытка по дополнительным полям
        if (!empty($context['external_id'])) {
            // Ozon обычно использует числовые ID
            if (is_numeric($context['external_id']) && strlen($context['external_id']) > 6) {
                return MarketplaceDetector::OZON;
            }
        }
        
        // Попытка по паттернам в названии источника
        if (!empty($source)) {
            $source = strtolower($source);
            
            // Дополнительные паттерны для Ozon
            if (preg_match('/о[зс]он|ozon|озон/u', $source)) {
                return MarketplaceDetector::OZON;
            }
            
            // Дополнительные паттерны для Wildberries
            if (preg_match('/в[ао]лдберр|wildberr|вб|wb/u', $source)) {
                return MarketplaceDetector::WILDBERRIES;
            }
        }
        
        return MarketplaceDetector::UNKNOWN;
    }
    
    /**
     * Проверить наличие данных в соседних периодах
     * 
     * @param string $marketplace - маркетплейс
     * @param string $period - текущий период
     * @return bool есть ли данные в соседних периодах
     */
    private function hasDataInAdjacentPeriods($marketplace, $period) {
        try {
            // Парсим период (предполагаем формат "YYYY-MM-DD to YYYY-MM-DD")
            $dates = explode(' to ', $period);
            if (count($dates) !== 2) {
                return false;
            }
            
            $startDate = new DateTime($dates[0]);
            $endDate = new DateTime($dates[1]);
            
            // Проверяем неделю до и после
            $beforeStart = clone $startDate;
            $beforeStart->sub(new DateInterval('P7D'));
            $beforeEnd = clone $startDate;
            $beforeEnd->sub(new DateInterval('P1D'));
            
            $afterStart = clone $endDate;
            $afterStart->add(new DateInterval('P1D'));
            $afterEnd = clone $endDate;
            $afterEnd->add(new DateInterval('P7D'));
            
            // Проверяем наличие данных в предыдущей неделе
            if ($this->hasDataInPeriod($marketplace, $beforeStart->format('Y-m-d'), $beforeEnd->format('Y-m-d'))) {
                return true;
            }
            
            // Проверяем наличие данных в следующей неделе
            if ($this->hasDataInPeriod($marketplace, $afterStart->format('Y-m-d'), $afterEnd->format('Y-m-d'))) {
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            $this->logError('PERIOD_CHECK_ERROR', 'Error checking adjacent periods: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Проверить наличие данных в указанном периоде
     * 
     * @param string $marketplace - маркетплейс
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @return bool есть ли данные
     */
    private function hasDataInPeriod($marketplace, $startDate, $endDate) {
        try {
            $sql = "
                SELECT COUNT(*) as count
                FROM fact_orders fo
                JOIN sources s ON fo.source_id = s.id
                LEFT JOIN dim_products dp ON fo.product_id = dp.id
                WHERE fo.order_date BETWEEN :start_date AND :end_date
                    AND fo.transaction_type IN ('продажа', 'sale', 'order')
            ";
            
            $params = [
                'start_date' => $startDate,
                'end_date' => $endDate
            ];
            
            // Добавляем фильтр по маркетплейсу
            switch ($marketplace) {
                case MarketplaceDetector::OZON:
                    $sql .= " AND (s.code LIKE '%ozon%' OR s.name LIKE '%ozon%' OR s.name LIKE '%озон%' OR 
                             (dp.sku_ozon IS NOT NULL AND fo.sku = dp.sku_ozon))";
                    break;
                    
                case MarketplaceDetector::WILDBERRIES:
                    $sql .= " AND (s.code LIKE '%wb%' OR s.code LIKE '%wildberries%' OR s.name LIKE '%wildberries%' OR 
                             s.name LIKE '%вб%' OR (dp.sku_wb IS NOT NULL AND fo.sku = dp.sku_wb))";
                    break;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            return $result['count'] > 0;
        } catch (Exception $e) {
            $this->logError('DATA_CHECK_ERROR', 'Error checking data in period: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Создать пользовательское сообщение об ошибке
     * 
     * @param string $errorCode - код ошибки
     * @param string $marketplace - маркетплейс
     * @param array $context - контекст ошибки
     * @return array структурированное сообщение
     */
    public function createUserFriendlyError($errorCode, $marketplace = null, $context = []) {
        $marketplaceName = $marketplace ? MarketplaceDetector::getMarketplaceName($marketplace) : 'маркетплейс';
        
        $messages = [
            self::ERROR_NO_DATA => [
                'title' => 'Нет данных',
                'message' => "По маркетплейсу «{$marketplaceName}» нет данных за выбранный период",
                'description' => 'Возможно, данные еще не загружены или в указанном периоде не было продаж',
                'icon' => '📊',
                'severity' => self::SEVERITY_MEDIUM
            ],
            self::ERROR_MARKETPLACE_NOT_FOUND => [
                'title' => 'Маркетплейс не определен',
                'message' => 'Не удалось определить маркетплейс для части данных',
                'description' => 'Некоторые записи не содержат достаточно информации для классификации',
                'icon' => '❓',
                'severity' => self::SEVERITY_LOW
            ],
            self::ERROR_INVALID_MARKETPLACE => [
                'title' => 'Неверный маркетплейс',
                'message' => 'Указан неподдерживаемый маркетплейс',
                'description' => 'Поддерживаются только Ozon и Wildberries',
                'icon' => '⚠️',
                'severity' => self::SEVERITY_HIGH
            ],
            self::ERROR_DATA_INCONSISTENCY => [
                'title' => 'Несоответствие данных',
                'message' => 'Обнаружены расхождения в данных',
                'description' => 'Сумма по маркетплейсам не соответствует общим показателям',
                'icon' => '⚖️',
                'severity' => self::SEVERITY_HIGH
            ],
            self::ERROR_DATABASE_ERROR => [
                'title' => 'Ошибка базы данных',
                'message' => 'Временные проблемы с доступом к данным',
                'description' => 'Попробуйте повторить запрос через несколько минут',
                'icon' => '🔧',
                'severity' => self::SEVERITY_CRITICAL
            ]
        ];
        
        $template = $messages[$errorCode] ?? [
            'title' => 'Неизвестная ошибка',
            'message' => 'Произошла неожиданная ошибка',
            'description' => 'Обратитесь к администратору системы',
            'icon' => '❌',
            'severity' => self::SEVERITY_HIGH
        ];
        
        return array_merge($template, [
            'error_code' => $errorCode,
            'marketplace' => $marketplace,
            'marketplace_name' => $marketplaceName,
            'context' => $context,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Логирование ошибок
     * 
     * @param string $errorCode - код ошибки
     * @param string $message - сообщение об ошибке
     * @param array $context - контекст ошибки
     */
    private function logError($errorCode, $message, $context = []) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'ERROR',
            'error_code' => $errorCode,
            'message' => $message,
            'context' => $context
        ];
        
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Логирование информационных сообщений
     * 
     * @param string $message - сообщение
     * @param array $context - контекст
     */
    private function logInfo($message, $context = []) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'INFO',
            'message' => $message,
            'context' => $context
        ];
        
        $logLine = json_encode($logEntry, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Получить статистику ошибок за период
     * 
     * @param string $startDate - начальная дата
     * @param string $endDate - конечная дата
     * @return array статистика ошибок
     */
    public function getErrorStats($startDate, $endDate) {
        if (!file_exists($this->logFile)) {
            return [
                'total_errors' => 0,
                'errors_by_type' => [],
                'errors_by_severity' => [],
                'period' => "$startDate to $endDate"
            ];
        }
        
        $errors = [];
        $handle = fopen($this->logFile, 'r');
        
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if ($entry && $entry['level'] === 'ERROR') {
                    $entryDate = substr($entry['timestamp'], 0, 10);
                    if ($entryDate >= $startDate && $entryDate <= $endDate) {
                        $errors[] = $entry;
                    }
                }
            }
            fclose($handle);
        }
        
        // Группируем ошибки
        $errorsByType = [];
        $errorsBySeverity = [];
        
        foreach ($errors as $error) {
            $errorCode = $error['error_code'] ?? 'UNKNOWN';
            $errorsByType[$errorCode] = ($errorsByType[$errorCode] ?? 0) + 1;
            
            // Определяем серьезность по коду ошибки
            $severity = $this->getErrorSeverity($errorCode);
            $errorsBySeverity[$severity] = ($errorsBySeverity[$severity] ?? 0) + 1;
        }
        
        return [
            'total_errors' => count($errors),
            'errors_by_type' => $errorsByType,
            'errors_by_severity' => $errorsBySeverity,
            'period' => "$startDate to $endDate",
            'recent_errors' => array_slice($errors, -10) // Последние 10 ошибок
        ];
    }
    
    /**
     * Получить уровень серьезности ошибки по коду
     * 
     * @param string $errorCode - код ошибки
     * @return string уровень серьезности
     */
    private function getErrorSeverity($errorCode) {
        $severityMap = [
            self::ERROR_NO_DATA => self::SEVERITY_MEDIUM,
            self::ERROR_MARKETPLACE_NOT_FOUND => self::SEVERITY_LOW,
            self::ERROR_INVALID_MARKETPLACE => self::SEVERITY_HIGH,
            self::ERROR_DATA_INCONSISTENCY => self::SEVERITY_HIGH,
            self::ERROR_DATABASE_ERROR => self::SEVERITY_CRITICAL,
            self::ERROR_VALIDATION_FAILED => self::SEVERITY_HIGH
        ];
        
        return $severityMap[$errorCode] ?? self::SEVERITY_MEDIUM;
    }
}
?>