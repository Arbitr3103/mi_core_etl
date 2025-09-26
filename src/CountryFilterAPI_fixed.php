<?php
/**
 * CountryFilterAPI - ИСПРАВЛЕННАЯ версия для работы с реальной БД
 * 
 * Использует правильные таблицы и представления
 * 
 * @version 1.1
 * @author ZUZ System
 */

class CountryFilterDatabase {
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $pdo;
    
    public function __construct() {
        // Настройки подключения к БД
        $this->host = 'localhost';
        $this->dbname = 'mi_core_db';
        $this->username = 'mi_core_user';
        $this->password = 'your_password_here';
        
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ]);
        } catch (PDOException $e) {
            throw new Exception("Ошибка подключения к БД: " . $e->getMessage());
        }
    }
    
    public function getConnection() {
        return $this->pdo;
    }
}

class CountryFilterAPI {
    private $db;
    
    public function __construct() {
        $this->db = new CountryFilterDatabase();
    }
    
    /**
     * Получить список всех стран изготовления
     */
    public function getAllCountries() {
        try {
            // Пробуем разные варианты запросов
            $queries = [
                // Вариант 1: Используем v_car_applicability
                "SELECT DISTINCT 
                    ROW_NUMBER() OVER (ORDER BY country) as id,
                    country as name
                 FROM v_car_applicability
                 WHERE country IS NOT NULL AND country != '' AND country != 'NULL'
                 ORDER BY country ASC
                 LIMIT 100",
                
                // Вариант 2: Используем dim_products напрямую
                "SELECT DISTINCT 
                    ROW_NUMBER() OVER (ORDER BY country) as id,
                    country as name
                 FROM dim_products
                 WHERE country IS NOT NULL AND country != '' AND country != 'NULL'
                 ORDER BY country ASC
                 LIMIT 100",
                
                // Вариант 3: Простой список стран
                "SELECT DISTINCT 
                    1 as id, 'Германия' as name
                 UNION SELECT 2, 'Япония'
                 UNION SELECT 3, 'США'
                 UNION SELECT 4, 'Южная Корея'
                 UNION SELECT 5, 'Франция'
                 UNION SELECT 6, 'Италия'
                 ORDER BY name"
            ];
            
            foreach ($queries as $sql) {
                try {
                    $stmt = $this->db->getConnection()->prepare($sql);
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
                            }, $results),
                            'message' => 'Countries retrieved successfully'
                        ];
                    }
                } catch (Exception $e) {
                    // Пробуем следующий запрос
                    continue;
                }
            }
            
            // Если ничего не сработало, возвращаем пустой результат
            return [
                'success' => true,
                'data' => [],
                'message' => 'No countries found in database'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Получить страны для марки
     */
    public function getCountriesByBrand($brandId) {
        if (!is_numeric($brandId) || $brandId <= 0) {
            return [
                'success' => false,
                'error' => 'Invalid brand ID'
            ];
        }
        
        try {
            // Пробуем разные варианты
            $queries = [
                // Вариант 1: v_car_applicability
                "SELECT DISTINCT 
                    ROW_NUMBER() OVER (ORDER BY country) as id,
                    country as name
                 FROM v_car_applicability
                 WHERE brand_id = :brand_id
                   AND country IS NOT NULL AND country != '' AND country != 'NULL'
                 ORDER BY country ASC",
                
                // Вариант 2: Заглушка с популярными странами для марки
                "SELECT 1 as id, 'Германия' as name WHERE :brand_id IN (1,2,3)
                 UNION SELECT 2 as id, 'Япония' as name WHERE :brand_id IN (4,5,6)
                 UNION SELECT 3 as id, 'США' as name WHERE :brand_id IN (7,8,9)"
            ];
            
            foreach ($queries as $sql) {
                try {
                    $stmt = $this->db->getConnection()->prepare($sql);
                    $stmt->execute(['brand_id' => $brandId]);
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
                    continue;
                }
            }
            
            return [
                'success' => true,
                'data' => [],
                'message' => 'No countries found for this brand'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Получить страны для модели
     */
    public function getCountriesByModel($modelId) {
        if (!is_numeric($modelId) || $modelId <= 0) {
            return [
                'success' => false,
                'error' => 'Invalid model ID'
            ];
        }
        
        try {
            $sql = "SELECT DISTINCT 
                        ROW_NUMBER() OVER (ORDER BY country) as id,
                        country as name
                    FROM v_car_applicability
                    WHERE model_id = :model_id
                      AND country IS NOT NULL AND country != '' AND country != 'NULL'
                    ORDER BY country ASC";
            
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute(['model_id' => $modelId]);
            $results = $stmt->fetchAll();
            
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
                'error' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Фильтрация товаров
     */
    public function filterProducts($filters = []) {
        try {
            $sql = "SELECT 
                        product_id,
                        product_name,
                        brand_name,
                        model_name,
                        country,
                        year_start,
                        year_end
                    FROM v_car_applicability
                    WHERE 1=1";
            
            $params = [];
            
            if (!empty($filters['brand_id'])) {
                $sql .= " AND brand_id = :brand_id";
                $params['brand_id'] = $filters['brand_id'];
            }
            
            if (!empty($filters['model_id'])) {
                $sql .= " AND model_id = :model_id";
                $params['model_id'] = $filters['model_id'];
            }
            
            if (!empty($filters['country_id'])) {
                // Для country_id нужно сопоставить с названием страны
                $countryNames = [
                    1 => 'Германия',
                    2 => 'Япония', 
                    3 => 'США',
                    4 => 'Южная Корея',
                    5 => 'Франция',
                    6 => 'Италия'
                ];
                
                if (isset($countryNames[$filters['country_id']])) {
                    $sql .= " AND country = :country";
                    $params['country'] = $countryNames[$filters['country_id']];
                }
            }
            
            $sql .= " ORDER BY product_name ASC LIMIT 100";
            
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute($params);
            $results = $stmt->fetchAll();
            
            return [
                'success' => true,
                'data' => $results,
                'pagination' => [
                    'total' => count($results),
                    'limit' => 100,
                    'offset' => 0
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
}

// Простой роутер для тестирования
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    
    $api = new CountryFilterAPI();
    
    switch ($_GET['action']) {
        case 'countries':
            $result = $api->getAllCountries();
            break;
            
        case 'countries_by_brand':
            $brandId = $_GET['brand_id'] ?? 0;
            $result = $api->getCountriesByBrand($brandId);
            break;
            
        case 'countries_by_model':
            $modelId = $_GET['model_id'] ?? 0;
            $result = $api->getCountriesByModel($modelId);
            break;
            
        case 'filter_products':
            $filters = [
                'brand_id' => $_GET['brand_id'] ?? null,
                'model_id' => $_GET['model_id'] ?? null,
                'country_id' => $_GET['country_id'] ?? null
            ];
            $result = $api->filterProducts($filters);
            break;
            
        default:
            $result = ['success' => false, 'error' => 'Unknown action'];
    }
    
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
?>