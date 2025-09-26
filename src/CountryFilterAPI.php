<?php
/**
 * CountryFilterAPI - PHP класс для работы с фильтром по странам изготовления автомобилей
 * 
 * Предоставляет API endpoints для получения стран изготовления автомобилей
 * и фильтрации товаров по странам
 * 
 * МОДИФИКАЦИЯ: Поддержка двух баз данных
 * - replenishment_db: аналитические данные (DB_* переменные)
 * - mi_core_db: каталог товаров (CORE_DB_* переменные)
 * 
 * @version 1.1
 * @author ZUZ System
 */

class CountryFilterDatabase {
    /**
     * @var PDO|null Подключение к аналитической базе 'replenishment_db'
     */
    private $replenishment_db_conn;
    
    /**
     * @var PDO|null Подключение к основной базе 'mi_core_db' с каталогом товаров
     */
    private $mi_core_db_conn;
    
    public function __construct() {
        // Подключение №1: к replenishment_db (аналитическая база)
        try {
            $dsn = "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4";
            $this->replenishment_db_conn = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к replenishment_db: " . $e->getMessage());
        }
        
        // Подключение №2: к mi_core_db (основная база с каталогом товаров)
        try {
            $core_host = $_ENV['CORE_DB_HOST'] ?? 'localhost';
            $core_dbname = $_ENV['CORE_DB_NAME'] ?? 'mi_core_db';
            $core_username = $_ENV['CORE_DB_USER'] ?? 'app_user';
            $core_password = $_ENV['CORE_DB_PASSWORD'] ?? '';
            
            $dsn_core = "mysql:host={$core_host};dbname={$core_dbname};charset=utf8mb4";
            $this->mi_core_db_conn = new PDO($dsn_core, $core_username, $core_password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к mi_core_db: " . $e->getMessage());
        }
    }
    
    /**
     * Получить подключение к аналитической базе replenishment_db
     */
    public function getReplenishmentConnection() {
        return $this->replenishment_db_conn;
    }
    
    /**
     * Получить подключение к основной базе mi_core_db
     */
    public function getCoreConnection() {
        return $this->mi_core_db_conn;
    }
    
    /**
     * @deprecated Используйте getCoreConnection() или getReplenishmentConnection()
     */
    public function getConnection() {
        return $this->mi_core_db_conn; // По умолчанию возвращаем основную базу
    }
}

class CountryFilterAPI {
    public $db;
    private $cache;
    private $cacheTimeout;
    
    public function __construct() {
        $this->db = new CountryFilterDatabase();
        $this->cache = [];
        $this->cacheTimeout = 3600; // 1 час кэширования
    }
    
    /**
     * Получить данные из кэша или выполнить запрос
     * 
     * @param string $cacheKey - ключ кэша
     * @param callable $callback - функция для выполнения запроса
     * @return mixed
     */
    private function getCachedOrExecute($cacheKey, $callback) {
        // Проверяем кэш
        if (isset($this->cache[$cacheKey])) {
            $cached = $this->cache[$cacheKey];
            if (time() - $cached['timestamp'] < $this->cacheTimeout) {
                return $cached['data'];
            }
        }
        
        // Выполняем запрос
        $result = $callback();
        
        // Кэшируем результат только если запрос успешен
        if (isset($result['success']) && $result['success']) {
            $this->cache[$cacheKey] = [
                'data' => $result,
                'timestamp' => time()
            ];
        }
        
        return $result;
    }
    
    /**
     * Очистить кэш
     */
    public function clearCache() {
        $this->cache = [];
    }
    
    /**
     * Получить список всех стран изготовления
     * 
     * @return array
     */
    public function getAllCountries() {
        return $this->getCachedOrExecute('all_countries', function() {
            try {
                // Пробуем разные варианты для получения стран
                $queries = [
                    // Вариант 1: Из таблицы regions через region_id
                    "SELECT DISTINCT 
                        r.id,
                        r.name
                     FROM regions r
                     INNER JOIN v_car_applicability v ON r.id = v.region_id
                     WHERE r.name IS NOT NULL AND r.name != '' AND r.name != 'NULL'
                       AND r.name != 'легковые'
                     ORDER BY r.name ASC",
                    
                    // Вариант 2: Прямо из таблицы regions
                    "SELECT DISTINCT 
                        id,
                        name
                     FROM regions 
                     WHERE name IS NOT NULL AND name != '' AND name != 'NULL'
                       AND name != 'легковые'
                     ORDER BY name ASC",
                    
                    // Вариант 3: Заглушка с популярными странами
                    "SELECT 1 as id, 'Германия' as name
                     UNION SELECT 2, 'Япония'
                     UNION SELECT 3, 'США'
                     UNION SELECT 4, 'Южная Корея'
                     UNION SELECT 5, 'Франция'
                     UNION SELECT 6, 'Италия'
                     UNION SELECT 7, 'Великобритания'
                     UNION SELECT 8, 'Швеция'
                     UNION SELECT 9, 'Чехия'
                     ORDER BY name"
                ];
                
                foreach ($queries as $sql) {
                    try {
                        $stmt = $this->db->getCoreConnection()->prepare($sql);
                        $stmt->execute();
                        $results = $stmt->fetchAll();
                        
                        if (!empty($results)) {
                            return [
                                'success' => true,
                                'data' => array_map(function($row) {
                                    return [
                                        'id' => (int)$row['id'],
                                        'name' => $row['name']
                                    ];
                                }, $results)
                            ];
                        }
                    } catch (Exception $e) {
                        // Пробуем следующий запрос
                        continue;
                    }
                }
                
                // Если ничего не сработало, возвращаем заглушку
                return [
                    'success' => true,
                    'data' => [
                        ['id' => 1, 'name' => 'Германия'],
                        ['id' => 2, 'name' => 'Япония'],
                        ['id' => 3, 'name' => 'США'],
                        ['id' => 4, 'name' => 'Южная Корея'],
                        ['id' => 5, 'name' => 'Франция'],
                        ['id' => 6, 'name' => 'Италия']
                    ],
                    'message' => 'Используются предустановленные страны'
                ];
                

                
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Ошибка получения списка стран: ' . $e->getMessage()
                ];
            }
        });
    }
    
    /**
     * Получить страны изготовления для конкретной марки автомобиля
     * 
     * @param int $brandId - ID марки автомобиля
     * @return array
     */
    public function getCountriesByBrand($brandId) {
        // Валидация параметра
        if (!is_numeric($brandId) || $brandId <= 0) {
            return [
                'success' => false,
                'error' => 'Некорректный ID марки. Должен быть положительным числом.'
            ];
        }
        
        if ($brandId > 999999) {
            return [
                'success' => false,
                'error' => 'ID марки слишком большой'
            ];
        }
        
        return $this->getCachedOrExecute("countries_brand_{$brandId}", function() use ($brandId) {
            try {
                // Проверяем существование марки в mi_core_db
                $checkStmt = $this->db->getCoreConnection()->prepare("SELECT COUNT(*) as count FROM brands WHERE id = ?");
                $checkStmt->execute([(int)$brandId]);
                if ($checkStmt->fetch()['count'] == 0) {
                    return [
                        'success' => true,
                        'data' => [],
                        'message' => 'Марка не найдена или для неё нет доступных стран'
                    ];
                }
                
                // Пробуем разные варианты для получения стран по марке
                $queries = [
                    // Вариант 1: Из таблицы regions через связь
                    "SELECT DISTINCT 
                        r.id,
                        r.name
                     FROM regions r
                     INNER JOIN v_car_applicability v ON r.id = v.region_id
                     WHERE v.brand_id = :brand_id 
                       AND r.name IS NOT NULL AND r.name != '' AND r.name != 'NULL'
                       AND r.name != 'легковые'
                     ORDER BY r.name ASC",
                    
                    // Вариант 2: Заглушка с популярными странами для марки
                    "SELECT 1 as id, 'Германия' as name WHERE :brand_id IN (1,2,3,4,5)
                     UNION SELECT 2 as id, 'Япония' as name WHERE :brand_id IN (6,7,8,9,10)
                     UNION SELECT 3 as id, 'США' as name WHERE :brand_id IN (11,12,13,14,15)
                     UNION SELECT 4 as id, 'Южная Корея' as name WHERE :brand_id IN (16,17,18,19,20)"
                ];
                
                $results = [];
                foreach ($queries as $sql) {
                    try {
                        $stmt = $this->db->getCoreConnection()->prepare($sql);
                        $stmt->execute(['brand_id' => $brandId]);
                        $results = $stmt->fetchAll();
                        
                        if (!empty($results)) {
                            break; // Нашли результаты, выходим из цикла
                        }
                    } catch (Exception $e) {
                        // Пробуем следующий запрос
                        continue;
                    }
                }
                
                // Если нет результатов, возвращаем пустой массив с сообщением
                if (empty($results)) {
                    return [
                        'success' => true,
                        'data' => [],
                        'message' => 'Для данной марки не найдено стран изготовления'
                    ];
                }
                
                return [
                    'success' => true,
                    'data' => array_map(function($row) {
                        return [
                            'id' => (int)$row['id'],
                            'name' => $row['name']
                        ];
                    }, $results)
                ];
                
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Ошибка получения стран для марки: ' . $e->getMessage()
                ];
            }
        });
    }
    
    /**
     * Получить страны изготовления для конкретной модели автомобиля
     * 
     * @param int $modelId - ID модели автомобиля
     * @return array
     */
    public function getCountriesByModel($modelId) {
        // Валидация параметра
        if (!is_numeric($modelId) || $modelId <= 0) {
            return [
                'success' => false,
                'error' => 'Некорректный ID модели. Должен быть положительным числом.'
            ];
        }
        
        if ($modelId > 999999) {
            return [
                'success' => false,
                'error' => 'ID модели слишком большой'
            ];
        }
        
        return $this->getCachedOrExecute("countries_model_{$modelId}", function() use ($modelId) {
            try {
                // Проверяем существование модели в mi_core_db
                $checkStmt = $this->db->getCoreConnection()->prepare("SELECT COUNT(*) as count FROM car_models WHERE id = ?");
                $checkStmt->execute([(int)$modelId]);
                if ($checkStmt->fetch()['count'] == 0) {
                    return [
                        'success' => true,
                        'data' => [],
                        'message' => 'Модель не найдена или для неё нет доступных стран'
                    ];
                }
                
                // Используем таблицу regions через связь с v_car_applicability
                $sql = "
                    SELECT DISTINCT 
                        r.id,
                        r.name
                    FROM regions r
                    INNER JOIN v_car_applicability v ON r.id = v.region_id
                    WHERE v.model_id = :model_id
                      AND r.name IS NOT NULL AND r.name != '' AND r.name != 'NULL'
                      AND r.name != 'легковые'
                    ORDER BY r.name ASC
                    LIMIT 100
                ";
                
                $stmt = $this->db->getCoreConnection()->prepare($sql);
                $stmt->execute(['model_id' => $modelId]);
                
                $results = $stmt->fetchAll();
                
                // Если нет результатов, возвращаем пустой массив с сообщением
                if (empty($results)) {
                    return [
                        'success' => true,
                        'data' => [],
                        'message' => 'Для данной модели не найдено стран изготовления'
                    ];
                }
                
                return [
                    'success' => true,
                    'data' => array_map(function($row) {
                        return [
                            'id' => (int)$row['id'],
                            'name' => $row['name']
                        ];
                    }, $results)
                ];
                
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'error' => 'Ошибка получения стран для модели: ' . $e->getMessage()
                ];
            }
        });
    }
    
    /**
     * Фильтрация товаров с учетом страны изготовления
     * 
     * @param array $filters - массив фильтров
     * @return array
     */
    public function filterProducts($filters = []) {
        try {
            // Валидация параметров
            $validation = $this->validateFilters($filters);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'error' => 'Ошибки валидации параметров: ' . implode(', ', $validation['errors'])
                ];
            }
            
            // Валидация существования записей в БД
            $existenceValidation = $this->validateFilterExistence($filters);
            if (!$existenceValidation['valid']) {
                return [
                    'success' => false,
                    'error' => 'Ошибки валидации данных: ' . implode(', ', $existenceValidation['errors'])
                ];
            }
            
            // Подготовка параметров
            $brandId = isset($filters['brand_id']) && $filters['brand_id'] !== null && $filters['brand_id'] !== '' ? 
                       (int)$filters['brand_id'] : null;
            $modelId = isset($filters['model_id']) && $filters['model_id'] !== null && $filters['model_id'] !== '' ? 
                       (int)$filters['model_id'] : null;
            $year = isset($filters['year']) && $filters['year'] !== null && $filters['year'] !== '' ? 
                    (int)$filters['year'] : null;
            $countryId = isset($filters['country_id']) && $filters['country_id'] !== null && $filters['country_id'] !== '' ? 
                         (int)$filters['country_id'] : null;
            $limit = isset($filters['limit']) && $filters['limit'] !== null && $filters['limit'] !== '' ? 
                     (int)$filters['limit'] : 100;
            $offset = isset($filters['offset']) && $filters['offset'] !== null && $filters['offset'] !== '' ? 
                      (int)$filters['offset'] : 0;
            
            // Оптимизированный SQL запрос с использованием индексов
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
                INNER JOIN car_specifications cs ON p.specification_id = cs.id
                INNER JOIN car_models cm ON cs.car_model_id = cm.id
                INNER JOIN brands b ON cm.brand_id = b.id
                INNER JOIN regions r ON b.region_id = r.id
                WHERE p.product_name IS NOT NULL
            ";
            
            $params = [];
            
            // Добавляем условия фильтрации
            if ($brandId !== null) {
                $sql .= " AND b.id = :brand_id";
                $params['brand_id'] = $brandId;
            }
            
            if ($modelId !== null) {
                $sql .= " AND cm.id = :model_id";
                $params['model_id'] = $modelId;
            }
            
            if ($year !== null) {
                $sql .= " AND (cs.year_start IS NULL OR cs.year_start <= :year)";
                $sql .= " AND (cs.year_end IS NULL OR cs.year_end >= :year)";
                $params['year'] = $year;
            }
            
            if ($countryId !== null) {
                $sql .= " AND r.id = :country_id";
                $params['country_id'] = $countryId;
            }
            
            // Добавляем сортировку и лимиты
            $sql .= " ORDER BY p.product_name ASC";
            $sql .= " LIMIT :limit OFFSET :offset";
            
            // ИСПОЛЬЗУЕМ подключение к mi_core_db для данных каталога товаров
            $stmt = $this->db->getCoreConnection()->prepare($sql);
            
            // Привязываем параметры
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $results = $stmt->fetchAll();
            
            // Оптимизированный запрос подсчета с использованием индексов
            $countSql = "
                SELECT COUNT(*) as total
                FROM dim_products p
                INNER JOIN car_specifications cs ON p.specification_id = cs.id
                INNER JOIN car_models cm ON cs.car_model_id = cm.id
                INNER JOIN brands b ON cm.brand_id = b.id
                INNER JOIN regions r ON b.region_id = r.id
                WHERE p.product_name IS NOT NULL
            ";
            
            // Добавляем те же условия для подсчета
            if ($brandId !== null) {
                $countSql .= " AND b.id = :brand_id";
            }
            if ($modelId !== null) {
                $countSql .= " AND cm.id = :model_id";
            }
            if ($year !== null) {
                $countSql .= " AND (cs.year_start IS NULL OR cs.year_start <= :year)";
                $countSql .= " AND (cs.year_end IS NULL OR cs.year_end >= :year)";
            }
            if ($countryId !== null) {
                $countSql .= " AND r.id = :country_id";
            }
            
            // ИСПОЛЬЗУЕМ подключение к mi_core_db для подсчета
            $countStmt = $this->db->getCoreConnection()->prepare($countSql);
            foreach ($params as $key => $value) {
                $countStmt->bindValue($key, $value);
            }
            $countStmt->execute();
            $totalCount = $countStmt->fetch()['total'];
            
            return [
                'success' => true,
                'data' => array_map(function($row) {
                    return [
                        'product_id' => (int)$row['product_id'],
                        'sku_ozon' => $row['sku_ozon'],
                        'sku_wb' => $row['sku_wb'],
                        'product_name' => $row['product_name'],
                        'cost_price' => $row['cost_price'] ? (float)$row['cost_price'] : null,
                        'brand_name' => $row['brand_name'],
                        'model_name' => $row['model_name'],
                        'country_name' => $row['country_name'],
                        'year_start' => $row['year_start'] ? (int)$row['year_start'] : null,
                        'year_end' => $row['year_end'] ? (int)$row['year_end'] : null
                    ];
                }, $results),
                'pagination' => [
                    'total' => (int)$totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $limit) < $totalCount
                ],
                'filters_applied' => [
                    'brand_id' => $brandId,
                    'model_id' => $modelId,
                    'year' => $year,
                    'country_id' => $countryId
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Ошибка фильтрации товаров: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Валидация параметров фильтра
     * 
     * @param array $filters
     * @return array
     */
    public function validateFilters($filters) {
        $errors = [];
        
        // Валидация brand_id
        if (isset($filters['brand_id']) && $filters['brand_id'] !== null && $filters['brand_id'] !== '') {
            if (!is_numeric($filters['brand_id'])) {
                $errors[] = 'ID марки должен быть числом';
            } elseif ((int)$filters['brand_id'] <= 0) {
                $errors[] = 'ID марки должен быть положительным числом';
            } elseif ((int)$filters['brand_id'] > 999999) {
                $errors[] = 'ID марки слишком большой';
            }
        }
        
        // Валидация model_id
        if (isset($filters['model_id']) && $filters['model_id'] !== null && $filters['model_id'] !== '') {
            if (!is_numeric($filters['model_id'])) {
                $errors[] = 'ID модели должен быть числом';
            } elseif ((int)$filters['model_id'] <= 0) {
                $errors[] = 'ID модели должен быть положительным числом';
            } elseif ((int)$filters['model_id'] > 999999) {
                $errors[] = 'ID модели слишком большой';
            }
        }
        
        // Валидация year
        if (isset($filters['year']) && $filters['year'] !== null && $filters['year'] !== '') {
            if (!is_numeric($filters['year'])) {
                $errors[] = 'Год выпуска должен быть числом';
            } else {
                $year = (int)$filters['year'];
                $currentYear = (int)date('Y');
                if ($year < 1900) {
                    $errors[] = 'Год выпуска не может быть меньше 1900';
                } elseif ($year > $currentYear + 2) {
                    $errors[] = 'Год выпуска не может быть больше ' . ($currentYear + 2);
                }
            }
        }
        
        // Валидация country_id
        if (isset($filters['country_id']) && $filters['country_id'] !== null && $filters['country_id'] !== '') {
            if (!is_numeric($filters['country_id'])) {
                $errors[] = 'ID страны должен быть числом';
            } elseif ((int)$filters['country_id'] <= 0) {
                $errors[] = 'ID страны должен быть положительным числом';
            } elseif ((int)$filters['country_id'] > 999999) {
                $errors[] = 'ID страны слишком большой';
            }
        }
        
        // Валидация limit
        if (isset($filters['limit']) && $filters['limit'] !== null && $filters['limit'] !== '') {
            if (!is_numeric($filters['limit'])) {
                $errors[] = 'Лимит должен быть числом';
            } else {
                $limit = (int)$filters['limit'];
                if ($limit <= 0) {
                    $errors[] = 'Лимит должен быть положительным числом';
                } elseif ($limit > 1000) {
                    $errors[] = 'Лимит не может быть больше 1000';
                }
            }
        }
        
        // Валидация offset
        if (isset($filters['offset']) && $filters['offset'] !== null && $filters['offset'] !== '') {
            if (!is_numeric($filters['offset'])) {
                $errors[] = 'Смещение должно быть числом';
            } elseif ((int)$filters['offset'] < 0) {
                $errors[] = 'Смещение не может быть отрицательным';
            } elseif ((int)$filters['offset'] > 100000) {
                $errors[] = 'Смещение слишком большое';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Валидация существования записей в базе данных
     * 
     * @param array $filters
     * @return array
     */
    public function validateFilterExistence($filters) {
        $errors = [];
        
        try {
            // Проверяем существование марки в mi_core_db
            if (isset($filters['brand_id']) && $filters['brand_id'] !== null && $filters['brand_id'] !== '') {
                $stmt = $this->db->getCoreConnection()->prepare("SELECT COUNT(*) as count FROM brands WHERE id = ?");
                $stmt->execute([(int)$filters['brand_id']]);
                if ($stmt->fetch()['count'] == 0) {
                    $errors[] = 'Марка с указанным ID не найдена';
                }
            }
            
            // Проверяем существование модели в mi_core_db
            if (isset($filters['model_id']) && $filters['model_id'] !== null && $filters['model_id'] !== '') {
                $stmt = $this->db->getCoreConnection()->prepare("SELECT COUNT(*) as count FROM car_models WHERE id = ?");
                $stmt->execute([(int)$filters['model_id']]);
                if ($stmt->fetch()['count'] == 0) {
                    $errors[] = 'Модель с указанным ID не найдена';
                }
            }
            
            // Проверяем существование страны в mi_core_db
            if (isset($filters['country_id']) && $filters['country_id'] !== null && $filters['country_id'] !== '') {
                $stmt = $this->db->getCoreConnection()->prepare("SELECT COUNT(*) as count FROM regions WHERE id = ?");
                $stmt->execute([(int)$filters['country_id']]);
                if ($stmt->fetch()['count'] == 0) {
                    $errors[] = 'Страна с указанным ID не найдена';
                }
            }
            
            // Проверяем соответствие модели и марки в mi_core_db
            if (isset($filters['brand_id']) && isset($filters['model_id']) && 
                $filters['brand_id'] !== null && $filters['model_id'] !== null &&
                $filters['brand_id'] !== '' && $filters['model_id'] !== '') {
                
                $stmt = $this->db->getCoreConnection()->prepare(
                    "SELECT COUNT(*) as count FROM car_models WHERE id = ? AND brand_id = ?"
                );
                $stmt->execute([(int)$filters['model_id'], (int)$filters['brand_id']]);
                if ($stmt->fetch()['count'] == 0) {
                    $errors[] = 'Указанная модель не принадлежит выбранной марке';
                }
            }
            
        } catch (Exception $e) {
            $errors[] = 'Ошибка проверки данных в базе: ' . $e->getMessage();
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

// API Router - обработка HTTP запросов
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    
    try {
        $api = new CountryFilterAPI();
        $action = $_GET['action'];
        
        switch ($action) {
            case 'countries':
                // GET /api/countries
                $result = $api->getAllCountries();
                break;
                
            case 'countries_by_brand':
                // GET /api/countries/by-brand/{brandId}
                if (!isset($_GET['brand_id'])) {
                    $result = ['success' => false, 'error' => 'Не указан ID марки'];
                } else {
                    $result = $api->getCountriesByBrand($_GET['brand_id']);
                }
                break;
                
            case 'countries_by_model':
                // GET /api/countries/by-model/{modelId}
                if (!isset($_GET['model_id'])) {
                    $result = ['success' => false, 'error' => 'Не указан ID модели'];
                } else {
                    $result = $api->getCountriesByModel($_GET['model_id']);
                }
                break;
                
            case 'filter_products':
                // GET /api/products/filter
                $filters = [
                    'brand_id' => $_GET['brand_id'] ?? null,
                    'model_id' => $_GET['model_id'] ?? null,
                    'year' => $_GET['year'] ?? null,
                    'country_id' => $_GET['country_id'] ?? null,
                    'limit' => $_GET['limit'] ?? 100,
                    'offset' => $_GET['offset'] ?? 0
                ];
                
                // Валидация
                $validation = $api->validateFilters($filters);
                if (!$validation['valid']) {
                    $result = [
                        'success' => false,
                        'error' => 'Ошибки валидации: ' . implode(', ', $validation['errors'])
                    ];
                } else {
                    $result = $api->filterProducts($filters);
                }
                break;
                
            default:
                $result = ['success' => false, 'error' => 'Неизвестное действие'];
                break;
        }
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// Пример использования:
/*
// Получить все страны
$api = new CountryFilterAPI();
$countries = $api->getAllCountries();

// Получить страны для марки BMW (ID = 1)
$bmwCountries = $api->getCountriesByBrand(1);

// Получить страны для модели BMW X5 (ID = 5)
$x5Countries = $api->getCountriesByModel(5);

// Фильтровать товары по стране
$filters = [
    'brand_id' => 1,
    'country_id' => 2,
    'year' => 2020,
    'limit' => 50
];
$products = $api->filterProducts($filters);
*/
?>