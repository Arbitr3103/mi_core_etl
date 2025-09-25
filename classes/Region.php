<?php
/**
 * Region Class - Класс для работы со странами/регионами изготовления автомобилей
 * 
 * Предоставляет методы для получения стран по различным критериям
 * в соответствии с требованиями спецификации car-country-filter
 * 
 * @version 1.0
 * @author ZUZ System
 */

class Region {
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
     * Получить все доступные регионы/страны изготовления
     * 
     * @return array массив регионов с id и name
     * @throws Exception при ошибке выполнения запроса
     */
    public function getAll() {
        try {
            $sql = "
                SELECT DISTINCT r.id, r.name
                FROM regions r
                INNER JOIN brands b ON r.id = b.region_id
                WHERE r.name IS NOT NULL AND r.name != ''
                ORDER BY r.name ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка получения списка регионов: " . $e->getMessage());
        }
    }
    
    /**
     * Получить регионы для конкретной марки автомобиля
     * 
     * @param int $brandId - ID марки автомобиля
     * @return array массив регионов для указанной марки
     * @throws Exception при ошибке выполнения запроса или некорректном ID
     */
    public function getByBrand($brandId) {
        if (!is_numeric($brandId) || $brandId <= 0) {
            throw new Exception("Некорректный ID марки");
        }
        
        try {
            $sql = "
                SELECT DISTINCT r.id, r.name
                FROM regions r
                INNER JOIN brands b ON r.id = b.region_id
                WHERE b.id = :brand_id
                  AND r.name IS NOT NULL AND r.name != ''
                ORDER BY r.name ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['brand_id' => $brandId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка получения регионов для марки: " . $e->getMessage());
        }
    }
    
    /**
     * Получить регионы для конкретной модели автомобиля
     * 
     * @param int $modelId - ID модели автомобиля
     * @return array массив регионов для указанной модели
     * @throws Exception при ошибке выполнения запроса или некорректном ID
     */
    public function getByModel($modelId) {
        if (!is_numeric($modelId) || $modelId <= 0) {
            throw new Exception("Некорректный ID модели");
        }
        
        try {
            $sql = "
                SELECT DISTINCT r.id, r.name
                FROM regions r
                INNER JOIN brands b ON r.id = b.region_id
                INNER JOIN car_models cm ON b.id = cm.brand_id
                WHERE cm.id = :model_id
                  AND r.name IS NOT NULL AND r.name != ''
                ORDER BY r.name ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['model_id' => $modelId]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка получения регионов для модели: " . $e->getMessage());
        }
    }
    
    /**
     * Проверить существование региона по ID
     * 
     * @param int $regionId - ID региона
     * @return bool true если регион существует, false если нет
     * @throws Exception при ошибке выполнения запроса
     */
    public function exists($regionId) {
        if (!is_numeric($regionId) || $regionId <= 0) {
            return false;
        }
        
        try {
            $sql = "SELECT COUNT(*) FROM regions WHERE id = :region_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['region_id' => $regionId]);
            
            return $stmt->fetchColumn() > 0;
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка проверки существования региона: " . $e->getMessage());
        }
    }
    
    /**
     * Получить информацию о регионе по ID
     * 
     * @param int $regionId - ID региона
     * @return array|null информация о регионе или null если не найден
     * @throws Exception при ошибке выполнения запроса
     */
    public function getById($regionId) {
        if (!is_numeric($regionId) || $regionId <= 0) {
            throw new Exception("Некорректный ID региона");
        }
        
        try {
            $sql = "SELECT id, name FROM regions WHERE id = :region_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['region_id' => $regionId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка получения информации о регионе: " . $e->getMessage());
        }
    }
    
    /**
     * Получить количество брендов в регионе
     * 
     * @param int $regionId - ID региона
     * @return int количество брендов в регионе
     * @throws Exception при ошибке выполнения запроса
     */
    public function getBrandCount($regionId) {
        if (!is_numeric($regionId) || $regionId <= 0) {
            throw new Exception("Некорректный ID региона");
        }
        
        try {
            $sql = "
                SELECT COUNT(DISTINCT b.id) 
                FROM brands b 
                WHERE b.region_id = :region_id
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['region_id' => $regionId]);
            
            return (int)$stmt->fetchColumn();
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка получения количества брендов: " . $e->getMessage());
        }
    }
    
    /**
     * Получить статистику по регионам
     * 
     * @return array массив с информацией о количестве брендов и моделей по регионам
     * @throws Exception при ошибке выполнения запроса
     */
    public function getStatistics() {
        try {
            $sql = "
                SELECT 
                    r.id,
                    r.name,
                    COUNT(DISTINCT b.id) as brand_count,
                    COUNT(DISTINCT cm.id) as model_count
                FROM regions r
                LEFT JOIN brands b ON r.id = b.region_id
                LEFT JOIN car_models cm ON b.id = cm.brand_id
                WHERE r.name IS NOT NULL AND r.name != ''
                GROUP BY r.id, r.name
                ORDER BY brand_count DESC, r.name ASC
            ";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            throw new Exception("Ошибка получения статистики регионов: " . $e->getMessage());
        }
    }
}