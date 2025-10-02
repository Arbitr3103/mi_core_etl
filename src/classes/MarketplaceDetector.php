<?php
/**
 * MarketplaceDetector Class - Утилита для определения маркетплейса и фильтрации данных
 * 
 * Предоставляет методы для идентификации маркетплейса на основе источника данных,
 * SKU полей и построения SQL фильтров для разделения данных по маркетплейсам
 * 
 * @version 1.0
 * @author Manhattan System
 */

class MarketplaceDetector {
    // Константы для идентификации маркетплейсов
    const OZON = 'ozon';
    const WILDBERRIES = 'wildberries';
    const UNKNOWN = 'unknown';
    
    // Паттерны для определения маркетплейса по источнику
    private static $sourcePatterns = [
        self::OZON => ['ozon', 'озон'],
        self::WILDBERRIES => ['wildberries', 'wb', 'вб']
    ];
    
    private $pdo;
    
    /**
     * Конструктор класса
     * 
     * @param PDO $pdo - подключение к базе данных
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Определить маркетплейс по коду источника из таблицы sources
     * 
     * @param string $sourceCode - код источника (например, 'ozon', 'wb')
     * @return string константа маркетплейса
     */
    public static function detectFromSourceCode($sourceCode) {
        if (empty($sourceCode)) {
            return self::UNKNOWN;
        }
        
        $sourceCode = strtolower(trim($sourceCode));
        
        foreach (self::$sourcePatterns as $marketplace => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($sourceCode, $pattern) !== false) {
                    return $marketplace;
                }
            }
        }
        
        return self::UNKNOWN;
    }
    
    /**
     * Определить маркетплейс по названию источника
     * 
     * @param string $sourceName - название источника
     * @return string константа маркетплейса
     */
    public static function detectFromSourceName($sourceName) {
        if (empty($sourceName)) {
            return self::UNKNOWN;
        }
        
        $sourceName = strtolower(trim($sourceName));
        
        foreach (self::$sourcePatterns as $marketplace => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($sourceName, $pattern) !== false) {
                    return $marketplace;
                }
            }
        }
        
        return self::UNKNOWN;
    }
    
    /**
     * Определить маркетплейс по SKU полям товара
     * 
     * @param string|null $skuOzon - SKU товара на Ozon
     * @param string|null $skuWb - SKU товара на Wildberries
     * @param string $currentSku - текущий SKU из заказа
     * @return string константа маркетплейса
     */
    public static function detectFromSku($skuOzon, $skuWb, $currentSku) {
        if (empty($currentSku)) {
            return self::UNKNOWN;
        }
        
        // Проверяем точное совпадение с SKU Ozon
        if (!empty($skuOzon) && $currentSku === $skuOzon) {
            return self::OZON;
        }
        
        // Проверяем точное совпадение с SKU Wildberries
        if (!empty($skuWb) && $currentSku === $skuWb) {
            return self::WILDBERRIES;
        }
        
        return self::UNKNOWN;
    }
    
    /**
     * Комплексное определение маркетплейса по всем доступным данным
     * 
     * @param string|null $sourceCode - код источника
     * @param string|null $sourceName - название источника
     * @param string|null $skuOzon - SKU товара на Ozon
     * @param string|null $skuWb - SKU товара на Wildberries
     * @param string|null $currentSku - текущий SKU из заказа
     * @return string константа маркетплейса
     */
    public static function detectMarketplace($sourceCode = null, $sourceName = null, $skuOzon = null, $skuWb = null, $currentSku = null) {
        // Приоритет 1: определение по коду источника
        if (!empty($sourceCode)) {
            $marketplace = self::detectFromSourceCode($sourceCode);
            if ($marketplace !== self::UNKNOWN) {
                return $marketplace;
            }
        }
        
        // Приоритет 2: определение по названию источника
        if (!empty($sourceName)) {
            $marketplace = self::detectFromSourceName($sourceName);
            if ($marketplace !== self::UNKNOWN) {
                return $marketplace;
            }
        }
        
        // Приоритет 3: определение по SKU
        if (!empty($currentSku)) {
            $marketplace = self::detectFromSku($skuOzon, $skuWb, $currentSku);
            if ($marketplace !== self::UNKNOWN) {
                return $marketplace;
            }
        }
        
        return self::UNKNOWN;
    }
    
    /**
     * Получить список всех поддерживаемых маркетплейсов
     * 
     * @return array массив констант маркетплейсов
     */
    public static function getAllMarketplaces() {
        return [self::OZON, self::WILDBERRIES];
    }
    
    /**
     * Получить человекочитаемое название маркетплейса
     * 
     * @param string $marketplace - константа маркетплейса
     * @return string название маркетплейса
     */
    public static function getMarketplaceName($marketplace) {
        $names = [
            self::OZON => 'Ozon',
            self::WILDBERRIES => 'Wildberries',
            self::UNKNOWN => 'Неопределенный источник'
        ];
        
        return $names[$marketplace] ?? 'Неизвестный маркетплейс';
    }
    
    /**
     * Получить иконку маркетплейса для отображения в интерфейсе
     * 
     * @param string $marketplace - константа маркетплейса
     * @return string эмодзи или символ маркетплейса
     */
    public static function getMarketplaceIcon($marketplace) {
        $icons = [
            self::OZON => '📦',
            self::WILDBERRIES => '🛍️',
            self::UNKNOWN => '❓'
        ];
        
        return $icons[$marketplace] ?? '🏪';
    }
    
    /**
     * Валидация параметра маркетплейса
     * 
     * @param string|null $marketplace - параметр маркетплейса для валидации
     * @return array результат валидации ['valid' => bool, 'error' => string|null]
     */
    public static function validateMarketplaceParameter($marketplace) {
        if ($marketplace === null) {
            return ['valid' => true, 'error' => null]; // null означает "все маркетплейсы"
        }
        
        if (!is_string($marketplace)) {
            return ['valid' => false, 'error' => 'Параметр маркетплейса должен быть строкой'];
        }
        
        $marketplace = strtolower(trim($marketplace));
        
        if (empty($marketplace)) {
            return ['valid' => false, 'error' => 'Параметр маркетплейса не может быть пустым'];
        }
        
        $validMarketplaces = self::getAllMarketplaces();
        if (!in_array($marketplace, $validMarketplaces)) {
            return [
                'valid' => false, 
                'error' => 'Недопустимый маркетплейс. Допустимые значения: ' . implode(', ', $validMarketplaces)
            ];
        }
        
        return ['valid' => true, 'error' => null];
    }
    
    /**
     * Построить SQL условие для фильтрации по маркетплейсу
     * 
     * @param string|null $marketplace - маркетплейс для фильтрации (null = все маркетплейсы)
     * @param string $sourceTableAlias - алиас таблицы sources в запросе (по умолчанию 's')
     * @param string $productTableAlias - алиас таблицы dim_products в запросе (по умолчанию 'dp')
     * @param string $orderTableAlias - алиас таблицы fact_orders в запросе (по умолчанию 'fo')
     * @return array массив с SQL условием и параметрами ['condition' => string, 'params' => array]
     */
    public function buildMarketplaceFilter($marketplace = null, $sourceTableAlias = 's', $productTableAlias = 'dp', $orderTableAlias = 'fo') {
        // Валидация параметра
        $validation = self::validateMarketplaceParameter($marketplace);
        if (!$validation['valid']) {
            throw new InvalidArgumentException($validation['error']);
        }
        
        // Если маркетплейс не указан, возвращаем пустое условие (все маркетплейсы)
        if ($marketplace === null) {
            return ['condition' => '1=1', 'params' => []];
        }
        
        $marketplace = strtolower(trim($marketplace));
        $params = [];
        
        switch ($marketplace) {
            case self::OZON:
                $condition = "({$sourceTableAlias}.code LIKE :ozon_code OR {$sourceTableAlias}.name LIKE :ozon_name OR " .
                           "({$productTableAlias}.sku_ozon IS NOT NULL AND {$orderTableAlias}.sku = {$productTableAlias}.sku_ozon))";
                $params = [
                    'ozon_code' => '%ozon%',
                    'ozon_name' => '%ozon%'
                ];
                break;
                
            case self::WILDBERRIES:
                $condition = "({$sourceTableAlias}.code LIKE :wb_code1 OR {$sourceTableAlias}.code LIKE :wb_code2 OR " .
                           "{$sourceTableAlias}.name LIKE :wb_name1 OR {$sourceTableAlias}.name LIKE :wb_name2 OR " .
                           "({$productTableAlias}.sku_wb IS NOT NULL AND {$orderTableAlias}.sku = {$productTableAlias}.sku_wb))";
                $params = [
                    'wb_code1' => '%wb%',
                    'wb_code2' => '%wildberries%',
                    'wb_name1' => '%wildberries%',
                    'wb_name2' => '%вб%'
                ];
                break;
                
            default:
                throw new InvalidArgumentException("Неподдерживаемый маркетплейс: {$marketplace}");
        }
        
        return ['condition' => $condition, 'params' => $params];
    }
    
    /**
     * Построить SQL условие для исключения определенного маркетплейса
     * 
     * @param string $excludeMarketplace - маркетплейс для исключения
     * @param string $sourceTableAlias - алиас таблицы sources в запросе
     * @param string $productTableAlias - алиас таблицы dim_products в запросе
     * @param string $orderTableAlias - алиас таблицы fact_orders в запросе
     * @return array массив с SQL условием и параметрами
     */
    public function buildMarketplaceExcludeFilter($excludeMarketplace, $sourceTableAlias = 's', $productTableAlias = 'dp', $orderTableAlias = 'fo') {
        $includeFilter = $this->buildMarketplaceFilter($excludeMarketplace, $sourceTableAlias, $productTableAlias, $orderTableAlias);
        
        return [
            'condition' => "NOT ({$includeFilter['condition']})",
            'params' => $includeFilter['params']
        ];
    }
    
    /**
     * Получить статистику по маркетплейсам из базы данных
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param int|null $clientId - ID клиента (null для всех клиентов)
     * @return array статистика по маркетплейсам
     */
    public function getMarketplaceStats($startDate, $endDate, $clientId = null) {
        $sql = "
            SELECT 
                s.code as source_code,
                s.name as source_name,
                COUNT(DISTINCT fo.order_id) as orders_count,
                SUM(fo.qty * fo.price) as total_revenue,
                COUNT(DISTINCT fo.product_id) as unique_products
            FROM fact_orders fo
            JOIN sources s ON fo.source_id = s.id
            WHERE fo.order_date BETWEEN :start_date AND :end_date
                AND fo.transaction_type IN ('продажа', 'sale', 'order')
        ";
        
        $params = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        if ($clientId !== null) {
            $sql .= " AND fo.client_id = :client_id";
            $params['client_id'] = $clientId;
        }
        
        $sql .= " GROUP BY s.id, s.code, s.name ORDER BY total_revenue DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Классифицируем результаты по маркетплейсам
        $stats = [];
        foreach ($results as $row) {
            $marketplace = self::detectFromSourceCode($row['source_code']);
            if (!isset($stats[$marketplace])) {
                $stats[$marketplace] = [
                    'marketplace' => $marketplace,
                    'name' => self::getMarketplaceName($marketplace),
                    'icon' => self::getMarketplaceIcon($marketplace),
                    'orders_count' => 0,
                    'total_revenue' => 0,
                    'unique_products' => 0,
                    'sources' => []
                ];
            }
            
            $stats[$marketplace]['orders_count'] += $row['orders_count'];
            $stats[$marketplace]['total_revenue'] += $row['total_revenue'];
            $stats[$marketplace]['unique_products'] += $row['unique_products'];
            $stats[$marketplace]['sources'][] = [
                'code' => $row['source_code'],
                'name' => $row['source_name'],
                'orders_count' => $row['orders_count'],
                'total_revenue' => $row['total_revenue']
            ];
        }
        
        return array_values($stats);
    }
    
    /**
     * Проверить корректность классификации данных по маркетплейсам
     * 
     * @param string $startDate - начальная дата периода
     * @param string $endDate - конечная дата периода
     * @param int|null $clientId - ID клиента
     * @return array отчет о качестве данных
     */
    public function validateMarketplaceClassification($startDate, $endDate, $clientId = null) {
        // Получаем общую статистику
        $totalStats = $this->getMarketplaceStats($startDate, $endDate, $clientId);
        
        // Подсчитываем записи с неопределенным маркетплейсом
        $unknownCount = 0;
        $totalOrders = 0;
        
        foreach ($totalStats as $stat) {
            $totalOrders += $stat['orders_count'];
            if ($stat['marketplace'] === self::UNKNOWN) {
                $unknownCount = $stat['orders_count'];
            }
        }
        
        $classificationRate = $totalOrders > 0 ? (($totalOrders - $unknownCount) / $totalOrders) * 100 : 0;
        
        return [
            'total_orders' => $totalOrders,
            'classified_orders' => $totalOrders - $unknownCount,
            'unknown_orders' => $unknownCount,
            'classification_rate' => round($classificationRate, 2),
            'quality_status' => $classificationRate >= 95 ? 'excellent' : 
                              ($classificationRate >= 85 ? 'good' : 
                              ($classificationRate >= 70 ? 'fair' : 'poor')),
            'marketplace_breakdown' => $totalStats
        ];
    }
}
?>