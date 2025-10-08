<?php

namespace MDM\ETL\DataExtractors;

use Exception;
use PDO;

/**
 * Экстрактор данных из внутренних систем
 * Извлекает данные о товарах из внутренних таблиц базы данных
 */
class InternalSystemExtractor extends BaseExtractor
{
    private array $sourceTables;
    private array $fieldMappings;
    
    public function __construct(PDO $pdo, array $config = [])
    {
        parent::__construct($pdo, $config);
        
        $this->sourceTables = $config['source_tables'] ?? [
            'products' => 'products',
            'inventory' => 'inventory',
            'orders' => 'orders'
        ];
        
        $this->fieldMappings = $config['field_mappings'] ?? [
            'products' => [
                'sku' => 'sku',
                'name' => 'name',
                'brand' => 'brand',
                'category' => 'category',
                'price' => 'price',
                'description' => 'description'
            ]
        ];
    }
    
    /**
     * Извлечение данных товаров из внутренних систем
     * 
     * @param array $filters Фильтры для извлечения
     * @return array Данные товаров
     */
    public function extract(array $filters = []): array
    {
        $this->log('INFO', 'Начало извлечения данных из внутренних систем', $filters);
        
        try {
            $products = $this->executeWithRetry(function() use ($filters) {
                return $this->fetchInternalProducts($filters);
            });
            
            $normalizedProducts = [];
            foreach ($products as $product) {
                $normalizedProducts[] = $this->normalizeInternalProduct($product);
            }
            
            if (!$this->validateData($normalizedProducts)) {
                throw new Exception('Валидация извлеченных данных не прошла');
            }
            
            $this->log('INFO', 'Успешно извлечено товаров', ['count' => count($normalizedProducts)]);
            
            return $normalizedProducts;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Ошибка извлечения данных из внутренних систем', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            throw $e;
        }
    }
    
    /**
     * Проверка доступности внутренних систем
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        try {
            // Проверяем доступность основной таблицы товаров
            $tableName = $this->sourceTables['products'];
            $stmt = $this->pdo->prepare("SELECT 1 FROM `$tableName` LIMIT 1");
            $stmt->execute();
            
            return true;
            
        } catch (Exception $e) {
            $this->log('WARNING', 'Внутренние системы недоступны', ['error' => $e->getMessage()]);
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
        return 'internal';
    }
    
    /**
     * Получение товаров из внутренних таблиц
     * 
     * @param array $filters Фильтры
     * @return array
     */
    private function fetchInternalProducts(array $filters): array
    {
        $tableName = $this->sourceTables['products'];
        $fieldMap = $this->fieldMappings['products'];
        
        // Строим SELECT запрос с маппингом полей
        $selectFields = [];
        foreach ($fieldMap as $internalField => $dbField) {
            $selectFields[] = "`$dbField` as `$internalField`";
        }
        
        $sql = "SELECT " . implode(', ', $selectFields) . " FROM `$tableName`";
        $params = [];
        
        // Добавляем условия WHERE если есть фильтры
        $whereConditions = [];
        
        if (!empty($filters['updated_after'])) {
            $whereConditions[] = "updated_at >= ?";
            $params[] = $filters['updated_after'];
        }
        
        if (!empty($filters['brand'])) {
            $whereConditions[] = "`{$fieldMap['brand']}` = ?";
            $params[] = $filters['brand'];
        }
        
        if (!empty($filters['category'])) {
            $whereConditions[] = "`{$fieldMap['category']}` = ?";
            $params[] = $filters['category'];
        }
        
        if (!empty($filters['sku_list'])) {
            $placeholders = str_repeat('?,', count($filters['sku_list']) - 1) . '?';
            $whereConditions[] = "`{$fieldMap['sku']}` IN ($placeholders)";
            $params = array_merge($params, $filters['sku_list']);
        }
        
        if (!empty($whereConditions)) {
            $sql .= " WHERE " . implode(' AND ', $whereConditions);
        }
        
        // Добавляем сортировку и лимит
        $sql .= " ORDER BY `{$fieldMap['sku']}`";
        
        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . intval($filters['limit']);
        }
        
        $this->log('INFO', 'Выполнение SQL запроса', [
            'sql' => $sql,
            'params_count' => count($params)
        ]);
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Нормализация данных товара из внутренних систем
     * 
     * @param array $internalProduct Данные товара из внутренней системы
     * @return array Нормализованные данные
     */
    private function normalizeInternalProduct(array $internalProduct): array
    {
        return [
            'external_sku' => $this->sanitizeString($internalProduct['sku'] ?? ''),
            'source' => $this->getSourceName(),
            'source_name' => $this->sanitizeString($internalProduct['name'] ?? ''),
            'source_brand' => $this->sanitizeString($internalProduct['brand'] ?? ''),
            'source_category' => $this->sanitizeString($internalProduct['category'] ?? ''),
            'price' => $this->sanitizePrice($internalProduct['price'] ?? 0),
            'description' => $this->sanitizeString($internalProduct['description'] ?? ''),
            'attributes' => $this->extractInternalAttributes($internalProduct),
            'extracted_at' => date('Y-m-d H:i:s'),
            'raw_data' => json_encode($internalProduct, JSON_UNESCAPED_UNICODE)
        ];
    }
    
    /**
     * Извлечение атрибутов товара из внутренних данных
     * 
     * @param array $internalProduct Данные товара
     * @return array Атрибуты товара
     */
    private function extractInternalAttributes(array $internalProduct): array
    {
        $attributes = [];
        
        // Добавляем все дополнительные поля как атрибуты
        $standardFields = ['sku', 'name', 'brand', 'category', 'price', 'description'];
        
        foreach ($internalProduct as $field => $value) {
            if (!in_array($field, $standardFields) && !empty($value)) {
                $key = $this->sanitizeString($field);
                
                if (is_string($value)) {
                    $cleanValue = $this->sanitizeString($value);
                } elseif (is_numeric($value)) {
                    $cleanValue = $value;
                } else {
                    $cleanValue = json_encode($value, JSON_UNESCAPED_UNICODE);
                }
                
                if (!empty($key) && !empty($cleanValue)) {
                    $attributes[$key] = $cleanValue;
                }
            }
        }
        
        return $this->sanitizeAttributes($attributes);
    }
    
    /**
     * Получение дополнительных данных из связанных таблиц
     * 
     * @param string $sku SKU товара
     * @return array Дополнительные данные
     */
    private function getAdditionalData(string $sku): array
    {
        $additionalData = [];
        
        // Получаем данные из таблицы остатков
        if (!empty($this->sourceTables['inventory'])) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT * FROM `{$this->sourceTables['inventory']}` WHERE sku = ? LIMIT 1"
                );
                $stmt->execute([$sku]);
                $inventory = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($inventory) {
                    $additionalData['inventory'] = $inventory;
                }
            } catch (Exception $e) {
                $this->log('WARNING', 'Не удалось получить данные остатков', [
                    'sku' => $sku,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        // Получаем статистику заказов
        if (!empty($this->sourceTables['orders'])) {
            try {
                $stmt = $this->pdo->prepare("
                    SELECT 
                        COUNT(*) as orders_count,
                        SUM(quantity) as total_quantity,
                        AVG(price) as avg_price,
                        MAX(created_at) as last_order_date
                    FROM `{$this->sourceTables['orders']}` 
                    WHERE sku = ?
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ");
                $stmt->execute([$sku]);
                $orderStats = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($orderStats && $orderStats['orders_count'] > 0) {
                    $additionalData['order_stats'] = $orderStats;
                }
            } catch (Exception $e) {
                $this->log('WARNING', 'Не удалось получить статистику заказов', [
                    'sku' => $sku,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $additionalData;
    }
    
    /**
     * Получение списка доступных таблиц
     * 
     * @return array Список таблиц
     */
    public function getAvailableTables(): array
    {
        try {
            $stmt = $this->pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            return $tables;
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Не удалось получить список таблиц', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Получение структуры таблицы
     * 
     * @param string $tableName Имя таблицы
     * @return array Структура таблицы
     */
    public function getTableStructure(string $tableName): array
    {
        try {
            $stmt = $this->pdo->prepare("DESCRIBE `$tableName`");
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->log('ERROR', 'Не удалось получить структуру таблицы', [
                'table' => $tableName,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
}