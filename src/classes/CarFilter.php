<?php
/**
 * CarFilter Class - Класс для валидации и построения запросов фильтрации автомобилей
 * 
 * Предоставляет методы для валидации параметров фильтрации и построения SQL запросов
 * в соответствии с требованиями спецификации car-country-filter
 * 
 * @version 1.0
 * @author ZUZ System
 */

class CarFilter {
    private $pdo;
    private $brandId;
    private $modelId;
    private $year;
    private $countryId;
    private $limit;
    private $offset;
    
    /**
     * Конструктор класса
     * 
     * @param PDO $pdo - подключение к базе данных
     */
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->reset();
    }
    
    /**
     * Сбросить все фильтры к значениям по умолчанию
     */
    public function reset() {
        $this->brandId = null;
        $this->modelId = null;
        $this->year = null;
        $this->countryId = null;
        $this->limit = 100;
        $this->offset = 0;
    }
    
    /**
     * Установить фильтр по марке
     * 
     * @param int|null $brandId - ID марки или null для сброса
     * @return CarFilter для цепочки вызовов
     */
    public function setBrand($brandId) {
        $this->brandId = $brandId;
        return $this;
    }
    
    /**
     * Установить фильтр по модели
     * 
     * @param int|null $modelId - ID модели или null для сброса
     * @return CarFilter для цепочки вызовов
     */
    public function setModel($modelId) {
        $this->modelId = $modelId;
        return $this;
    }
    
    /**
     * Установить фильтр по году выпуска
     * 
     * @param int|null $year - год выпуска или null для сброса
     * @return CarFilter для цепочки вызовов
     */
    public function setYear($year) {
        $this->year = $year;
        return $this;
    }
    
    /**
     * Установить фильтр по стране изготовления
     * 
     * @param int|null $countryId - ID страны или null для сброса
     * @return CarFilter для цепочки вызовов
     */
    public function setCountry($countryId) {
        $this->countryId = $countryId;
        return $this;
    }
    
    /**
     * Установить лимит результатов
     * 
     * @param int $limit - максимальное количество результатов
     * @return CarFilter для цепочки вызовов
     */
    public function setLimit($limit) {
        $this->limit = $limit;
        return $this;
    }
    
    /**
     * Установить смещение для пагинации
     * 
     * @param int $offset - смещение
     * @return CarFilter для цепочки вызовов
     */
    public function setOffset($offset) {
        $this->offset = $offset;
        return $this;
    }
    
    /**
     * Установить фильтры из массива
     * 
     * @param array $filters - массив фильтров
     * @return CarFilter для цепочки вызовов
     */
    public function setFilters(array $filters) {
        if (isset($filters['brand_id'])) {
            $this->setBrand($filters['brand_id']);
        }
        if (isset($filters['model_id'])) {
            $this->setModel($filters['model_id']);
        }
        if (isset($filters['year'])) {
            $this->setYear($filters['year']);
        }
        if (isset($filters['country_id'])) {
            $this->setCountry($filters['country_id']);
        }
        if (isset($filters['limit'])) {
            $this->setLimit($filters['limit']);
        }
        if (isset($filters['offset'])) {
            $this->setOffset($filters['offset']);
        }
        
        return $this;
    }
    
    /**
     * Валидация всех установленных фильтров
     * 
     * @return array массив с результатом валидации ['valid' => bool, 'errors' => array]
     */
    public function validate() {
        $errors = [];
        
        // Валидация ID марки
        if ($this->brandId !== null) {
            if (!is_numeric($this->brandId) || $this->brandId <= 0) {
                $errors[] = 'Некорректный ID марки';
            }
        }
        
        // Валидация ID модели
        if ($this->modelId !== null) {
            if (!is_numeric($this->modelId) || $this->modelId <= 0) {
                $errors[] = 'Некорректный ID модели';
            }
        }
        
        // Валидация года выпуска
        if ($this->year !== null) {
            if (!is_numeric($this->year) || $this->year < 1900 || $this->year > (date('Y') + 1)) {
                $errors[] = 'Некорректный год выпуска (должен быть от 1900 до ' . (date('Y') + 1) . ')';
            }
        }
        
        // Валидация ID страны
        if ($this->countryId !== null) {
            if (!is_numeric($this->countryId) || $this->countryId <= 0) {
                $errors[] = 'Некорректный ID страны';
            }
        }
        
        // Валидация лимита
        if (!is_numeric($this->limit) || $this->limit <= 0 || $this->limit > 1000) {
            $errors[] = 'Некорректный лимит (должен быть от 1 до 1000)';
        }
        
        // Валидация смещения
        if (!is_numeric($this->offset) || $this->offset < 0) {
            $errors[] = 'Некорректное смещение (должно быть >= 0)';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Построить SQL запрос для фильтрации товаров
     * 
     * @return array массив с SQL запросом и параметрами ['sql' => string, 'params' => array]
     * @throws Exception при ошибке валидации
     */
    public function buildQuery() {
        $validation = $this->validate();
        if (!$validation['valid']) {
            throw new Exception('Ошибки валидации: ' . implode(', ', $validation['errors']));
        }
        
        $sql = "
            SELECT 
                p.id as product_id,
                p.sku_ozon,
                p.sku_wb,
                p.product_name,
                p.cost_price,
                b.name as brand_name,
                cm.name as model_name,
                r.name as country_name,
                cs.year_start,
                cs.year_end
            FROM dim_products p
            LEFT JOIN car_specifications cs ON p.specification_id = cs.id
            LEFT JOIN car_models cm ON cs.car_model_id = cm.id
            LEFT JOIN brands b ON cm.brand_id = b.id
            LEFT JOIN regions r ON b.region_id = r.id
            WHERE 1=1
        ";
        
        $params = [];
        
        // Добавляем условия фильтрации
        if ($this->brandId !== null) {
            $sql .= " AND b.id = :brand_id";
            $params['brand_id'] = $this->brandId;
        }
        
        if ($this->modelId !== null) {
            $sql .= " AND cm.id = :model_id";
            $params['model_id'] = $this->modelId;
        }
        
        if ($this->year !== null) {
            $sql .= " AND (cs.year_start IS NULL OR cs.year_start <= :year)";
            $sql .= " AND (cs.year_end IS NULL OR cs.year_end >= :year)";
            $params['year'] = $this->year;
        }
        
        if ($this->countryId !== null) {
            $sql .= " AND r.id = :country_id";
            $params['country_id'] = $this->countryId;
        }
        
        // Добавляем сортировку и лимиты
        $sql .= " ORDER BY p.product_name ASC";
        $sql .= " LIMIT :limit OFFSET :offset";
        
        return [
            'sql' => $sql,
            'params' => $params
        ];
    }
    
    /**
     * Построить SQL запрос для подсчета общего количества записей
     * 
     * @return array массив с SQL запросом и параметрами ['sql' => string, 'params' => array]
     * @throws Exception при ошибке валидации
     */
    public function buildCountQuery() {
        $validation = $this->validate();
        if (!$validation['valid']) {
            throw new Exception('Ошибки валидации: ' . implode(', ', $validation['errors']));
        }
        
        $sql = "
            SELECT COUNT(*) as total
            FROM dim_products p
            LEFT JOIN car_specifications cs ON p.specification_id = cs.id
            LEFT JOIN car_models cm ON cs.car_model_id = cm.id
            LEFT JOIN brands b ON cm.brand_id = b.id
            LEFT JOIN regions r ON b.region_id = r.id
            WHERE 1=1
        ";
        
        $params = [];
        
        // Добавляем те же условия фильтрации (без лимитов)
        if ($this->brandId !== null) {
            $sql .= " AND b.id = :brand_id";
            $params['brand_id'] = $this->brandId;
        }
        
        if ($this->modelId !== null) {
            $sql .= " AND cm.id = :model_id";
            $params['model_id'] = $this->modelId;
        }
        
        if ($this->year !== null) {
            $sql .= " AND (cs.year_start IS NULL OR cs.year_start <= :year)";
            $sql .= " AND (cs.year_end IS NULL OR cs.year_end >= :year)";
            $params['year'] = $this->year;
        }
        
        if ($this->countryId !== null) {
            $sql .= " AND r.id = :country_id";
            $params['country_id'] = $this->countryId;
        }
        
        return [
            'sql' => $sql,
            'params' => $params
        ];
    }
    
    /**
     * Выполнить запрос фильтрации товаров
     * 
     * @return array результат выполнения запроса
     * @throws Exception при ошибке выполнения запроса
     */
    public function execute() {
        try {
            // Получаем основной запрос
            $queryData = $this->buildQuery();
            $stmt = $this->pdo->prepare($queryData['sql']);
            
            // Привязываем параметры
            foreach ($queryData['params'] as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $this->limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $this->offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Получаем общее количество записей
            $countData = $this->buildCountQuery();
            $countStmt = $this->pdo->prepare($countData['sql']);
            
            foreach ($countData['params'] as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            
            $countStmt->execute();
            $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            return [
                'data' => $results,
                'pagination' => [
                    'total' => (int)$totalCount,
                    'limit' => $this->limit,
                    'offset' => $this->offset,
                    'has_more' => ($this->offset + $this->limit) < $totalCount
                ],
                'filters_applied' => [
                    'brand_id' => $this->brandId,
                    'model_id' => $this->modelId,
                    'year' => $this->year,
                    'country_id' => $this->countryId
                ]
            ];
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка выполнения запроса фильтрации: " . $e->getMessage());
        }
    }
    
    /**
     * Получить текущие значения фильтров
     * 
     * @return array массив с текущими значениями фильтров
     */
    public function getFilters() {
        return [
            'brand_id' => $this->brandId,
            'model_id' => $this->modelId,
            'year' => $this->year,
            'country_id' => $this->countryId,
            'limit' => $this->limit,
            'offset' => $this->offset
        ];
    }
    
    /**
     * Проверить, установлены ли какие-либо фильтры
     * 
     * @return bool true если установлен хотя бы один фильтр
     */
    public function hasFilters() {
        return $this->brandId !== null || 
               $this->modelId !== null || 
               $this->year !== null || 
               $this->countryId !== null;
    }
    
    /**
     * Получить количество установленных фильтров
     * 
     * @return int количество установленных фильтров
     */
    public function getFilterCount() {
        $count = 0;
        if ($this->brandId !== null) $count++;
        if ($this->modelId !== null) $count++;
        if ($this->year !== null) $count++;
        if ($this->countryId !== null) $count++;
        return $count;
    }
}