<?php
/**
 * DataTypeNormalizer - Нормализатор типов данных
 * 
 * Обеспечивает автоматическое приведение типов данных для совместимости
 * между различными источниками данных и предотвращения SQL ошибок.
 * 
 * Requirements: 2.2, 2.3, 8.2
 */

class DataTypeNormalizer {
    
    /**
     * Нормализует данные товара
     * 
     * Приводит все ID к строковому типу для совместимости с VARCHAR полями
     * 
     * @param array $product Данные товара
     * @return array Нормализованные данные
     */
    public function normalizeProduct($product) {
        $normalized = [];
        
        // Нормализуем ID поля
        $idFields = [
            'id',
            'cross_ref_id',
            'inventory_product_id',
            'analytics_product_id',
            'ozon_product_id',
            'sku_ozon',
            'sku_wb',
            'product_id',
            'master_id'
        ];
        
        foreach ($product as $key => $value) {
            if (in_array($key, $idFields)) {
                $normalized[$key] = $this->normalizeId($value);
            } else {
                $normalized[$key] = $this->normalizeValue($value, $key);
            }
        }
        
        return $normalized;
    }
    
    /**
     * Нормализует ID значение
     * 
     * Приводит INT к VARCHAR для совместимости с базой данных
     * 
     * @param mixed $id ID значение
     * @return string|null Нормализованный ID
     */
    public function normalizeId($id) {
        if ($id === null || $id === '') {
            return null;
        }
        
        // Приводим к строке
        $normalized = (string)$id;
        
        // Удаляем лишние пробелы
        $normalized = trim($normalized);
        
        // Проверяем валидность
        if ($normalized === '' || $normalized === '0') {
            return null;
        }
        
        return $normalized;
    }
    
    /**
     * Нормализует значение поля
     * 
     * @param mixed $value Значение
     * @param string $fieldName Имя поля
     * @return mixed Нормализованное значение
     */
    public function normalizeValue($value, $fieldName) {
        if ($value === null) {
            return null;
        }
        
        // Строковые поля
        if ($this->isStringField($fieldName)) {
            return $this->normalizeString($value);
        }
        
        // Числовые поля
        if ($this->isNumericField($fieldName)) {
            return $this->normalizeNumeric($value);
        }
        
        // Поля даты/времени
        if ($this->isDateTimeField($fieldName)) {
            return $this->normalizeDateTime($value);
        }
        
        // Булевы поля
        if ($this->isBooleanField($fieldName)) {
            return $this->normalizeBoolean($value);
        }
        
        return $value;
    }
    
    /**
     * Нормализует строковое значение
     * 
     * @param mixed $value Значение
     * @return string|null Нормализованная строка
     */
    private function normalizeString($value) {
        if ($value === null || $value === '') {
            return null;
        }
        
        $normalized = (string)$value;
        $normalized = trim($normalized);
        
        // Удаляем лишние пробелы
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        
        return $normalized !== '' ? $normalized : null;
    }
    
    /**
     * Нормализует числовое значение
     * 
     * @param mixed $value Значение
     * @return float|int|null Нормализованное число
     */
    private function normalizeNumeric($value) {
        if ($value === null || $value === '') {
            return null;
        }
        
        // Удаляем пробелы и запятые
        $cleaned = str_replace([' ', ','], ['', '.'], (string)$value);
        
        if (!is_numeric($cleaned)) {
            return null;
        }
        
        // Возвращаем int или float в зависимости от значения
        return strpos($cleaned, '.') !== false ? (float)$cleaned : (int)$cleaned;
    }
    
    /**
     * Нормализует дату/время
     * 
     * @param mixed $value Значение
     * @return string|null Нормализованная дата в формате Y-m-d H:i:s
     */
    private function normalizeDateTime($value) {
        if ($value === null || $value === '') {
            return null;
        }
        
        try {
            $date = new DateTime($value);
            return $date->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Нормализует булево значение
     * 
     * @param mixed $value Значение
     * @return bool Нормализованное булево значение
     */
    private function normalizeBoolean($value) {
        if (is_bool($value)) {
            return $value;
        }
        
        if (is_numeric($value)) {
            return (bool)(int)$value;
        }
        
        $value = strtolower(trim((string)$value));
        return in_array($value, ['true', 'yes', '1', 'on'], true);
    }
    
    /**
     * Проверяет, является ли поле строковым
     * 
     * @param string $fieldName Имя поля
     * @return bool
     */
    private function isStringField($fieldName) {
        $stringFields = [
            'name',
            'cached_name',
            'canonical_name',
            'source_name',
            'brand',
            'cached_brand',
            'canonical_brand',
            'category',
            'description',
            'status',
            'sync_status',
            'verification_status',
            'source'
        ];
        
        return in_array($fieldName, $stringFields);
    }
    
    /**
     * Проверяет, является ли поле числовым
     * 
     * @param string $fieldName Имя поля
     * @return bool
     */
    private function isNumericField($fieldName) {
        $numericFields = [
            'quantity',
            'quantity_present',
            'quantity_reserved',
            'price',
            'cost',
            'margin',
            'margin_percent',
            'confidence_score',
            'metric_value',
            'total_records',
            'good_records',
            'weight',
            'servings'
        ];
        
        return in_array($fieldName, $numericFields);
    }
    
    /**
     * Проверяет, является ли поле датой/временем
     * 
     * @param string $fieldName Имя поля
     * @return bool
     */
    private function isDateTimeField($fieldName) {
        $dateTimeFields = [
            'created_at',
            'updated_at',
            'last_api_sync',
            'calculation_date',
            'sync_date',
            'timestamp'
        ];
        
        return in_array($fieldName, $dateTimeFields);
    }
    
    /**
     * Проверяет, является ли поле булевым
     * 
     * @param string $fieldName Имя поля
     * @return bool
     */
    private function isBooleanField($fieldName) {
        $booleanFields = [
            'is_active',
            'is_deleted',
            'is_verified',
            'enabled',
            'disabled'
        ];
        
        return in_array($fieldName, $booleanFields);
    }
    
    /**
     * Проверяет валидность ID товара
     * 
     * @param mixed $productId ID товара
     * @return bool true если ID валиден
     */
    public function isValidProductId($productId) {
        if ($productId === null || $productId === '') {
            return false;
        }
        
        $normalized = $this->normalizeId($productId);
        
        if ($normalized === null) {
            return false;
        }
        
        // ID должен быть числом или строкой, содержащей только цифры и дефисы
        return preg_match('/^[0-9\-]+$/', $normalized) === 1;
    }
    
    /**
     * Безопасно сравнивает два ID
     * 
     * Приводит оба ID к одному типу перед сравнением
     * 
     * @param mixed $id1 Первый ID
     * @param mixed $id2 Второй ID
     * @return bool true если ID равны
     */
    public function compareIds($id1, $id2) {
        $normalized1 = $this->normalizeId($id1);
        $normalized2 = $this->normalizeId($id2);
        
        if ($normalized1 === null || $normalized2 === null) {
            return false;
        }
        
        return $normalized1 === $normalized2;
    }
    
    /**
     * Нормализует данные из API эндпоинта
     * 
     * Различные API возвращают данные в разных форматах
     * 
     * @param array $data Данные из API
     * @param string $source Источник данных ('ozon', 'wb', 'analytics', 'inventory')
     * @return array Нормализованные данные
     */
    public function normalizeAPIResponse($data, $source) {
        switch ($source) {
            case 'ozon':
                return $this->normalizeOzonResponse($data);
            case 'wb':
                return $this->normalizeWBResponse($data);
            case 'analytics':
                return $this->normalizeAnalyticsResponse($data);
            case 'inventory':
                return $this->normalizeInventoryResponse($data);
            default:
                return $this->normalizeProduct($data);
        }
    }
    
    /**
     * Нормализует ответ от Ozon API
     * 
     * @param array $data Данные от Ozon
     * @return array Нормализованные данные
     */
    private function normalizeOzonResponse($data) {
        $normalized = [];
        
        // Маппинг полей Ozon API
        $fieldMapping = [
            'product_id' => 'ozon_product_id',
            'offer_id' => 'sku_ozon',
            'name' => 'name',
            'price' => 'price',
            'stocks' => 'quantity'
        ];
        
        foreach ($fieldMapping as $ozonField => $normalizedField) {
            if (isset($data[$ozonField])) {
                $normalized[$normalizedField] = $data[$ozonField];
            }
        }
        
        // Обрабатываем вложенные структуры
        if (isset($data['stocks']) && is_array($data['stocks'])) {
            $totalQuantity = 0;
            foreach ($data['stocks'] as $stock) {
                $totalQuantity += $stock['present'] ?? 0;
            }
            $normalized['quantity'] = $totalQuantity;
        }
        
        return $this->normalizeProduct($normalized);
    }
    
    /**
     * Нормализует ответ от Wildberries API
     * 
     * @param array $data Данные от WB
     * @return array Нормализованные данные
     */
    private function normalizeWBResponse($data) {
        $normalized = [];
        
        // Маппинг полей WB API
        $fieldMapping = [
            'nmId' => 'wb_product_id',
            'vendorCode' => 'sku_wb',
            'title' => 'name',
            'brand' => 'brand',
            'quantity' => 'quantity'
        ];
        
        foreach ($fieldMapping as $wbField => $normalizedField) {
            if (isset($data[$wbField])) {
                $normalized[$normalizedField] = $data[$wbField];
            }
        }
        
        return $this->normalizeProduct($normalized);
    }
    
    /**
     * Нормализует ответ от Analytics API
     * 
     * @param array $data Данные от Analytics
     * @return array Нормализованные данные
     */
    private function normalizeAnalyticsResponse($data) {
        $normalized = [];
        
        // Маппинг полей Analytics API
        $fieldMapping = [
            'product_id' => 'analytics_product_id',
            'sku' => 'sku_ozon',
            'product_name' => 'name',
            'revenue' => 'revenue',
            'orders_count' => 'orders_count'
        ];
        
        foreach ($fieldMapping as $analyticsField => $normalizedField) {
            if (isset($data[$analyticsField])) {
                $normalized[$normalizedField] = $data[$analyticsField];
            }
        }
        
        return $this->normalizeProduct($normalized);
    }
    
    /**
     * Нормализует ответ от Inventory API
     * 
     * @param array $data Данные от Inventory
     * @return array Нормализованные данные
     */
    private function normalizeInventoryResponse($data) {
        $normalized = [];
        
        // Маппинг полей Inventory API
        $fieldMapping = [
            'product_id' => 'inventory_product_id',
            'sku' => 'sku_ozon',
            'quantity_present' => 'quantity_present',
            'quantity_reserved' => 'quantity_reserved',
            'warehouse_id' => 'warehouse_id'
        ];
        
        foreach ($fieldMapping as $inventoryField => $normalizedField) {
            if (isset($data[$inventoryField])) {
                $normalized[$normalizedField] = $data[$inventoryField];
            }
        }
        
        return $this->normalizeProduct($normalized);
    }
    
    /**
     * Валидирует нормализованные данные перед записью в БД
     * 
     * @param array $data Нормализованные данные
     * @return array Массив с результатом валидации ['valid' => bool, 'errors' => array]
     */
    public function validateNormalizedData($data) {
        $errors = [];
        
        // Проверяем обязательные поля
        $requiredFields = ['inventory_product_id'];
        foreach ($requiredFields as $field) {
            if (empty($data[$field])) {
                $errors[] = "Missing required field: {$field}";
            }
        }
        
        // Проверяем валидность ID полей
        $idFields = ['inventory_product_id', 'ozon_product_id', 'analytics_product_id'];
        foreach ($idFields as $field) {
            if (isset($data[$field]) && !$this->isValidProductId($data[$field])) {
                $errors[] = "Invalid format for field: {$field}";
            }
        }
        
        // Проверяем длину строковых полей
        $stringFields = [
            'name' => 500,
            'cached_name' => 500,
            'brand' => 200,
            'cached_brand' => 200
        ];
        
        foreach ($stringFields as $field => $maxLength) {
            if (isset($data[$field]) && strlen($data[$field]) > $maxLength) {
                $errors[] = "Field {$field} exceeds maximum length of {$maxLength}";
            }
        }
        
        // Проверяем числовые поля
        $numericFields = ['quantity', 'quantity_present', 'quantity_reserved', 'price', 'cost'];
        foreach ($numericFields as $field) {
            if (isset($data[$field]) && !is_numeric($data[$field])) {
                $errors[] = "Field {$field} must be numeric";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Создает SQL-безопасное значение для JOIN операций
     * 
     * @param mixed $value Значение
     * @param string $targetType Целевой тип ('VARCHAR', 'INT', 'BIGINT')
     * @return string SQL выражение для безопасного приведения типа
     */
    public function createSafeJoinValue($value, $targetType = 'VARCHAR') {
        $normalized = $this->normalizeId($value);
        
        if ($normalized === null) {
            return 'NULL';
        }
        
        switch (strtoupper($targetType)) {
            case 'VARCHAR':
            case 'CHAR':
            case 'TEXT':
                return "'" . addslashes($normalized) . "'";
            
            case 'INT':
            case 'BIGINT':
            case 'INTEGER':
                return is_numeric($normalized) ? $normalized : '0';
            
            default:
                return "'" . addslashes($normalized) . "'";
        }
    }
    
    /**
     * Получает SQL выражение для безопасного сравнения разных типов
     * 
     * @param string $field1 Первое поле
     * @param string $field2 Второе поле
     * @return string SQL выражение для сравнения
     */
    public function getSafeComparisonSQL($field1, $field2) {
        // Приводим оба поля к VARCHAR для безопасного сравнения
        return "CAST({$field1} AS CHAR) = CAST({$field2} AS CHAR)";
    }
}
