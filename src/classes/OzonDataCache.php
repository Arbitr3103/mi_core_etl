<?php
/**
 * OzonDataCache Class - Система кэширования для Ozon Analytics API
 * 
 * Обеспечивает эффективное кэширование данных аналитики Ozon
 * с поддержкой TTL (время жизни кэша) и автоматической очистки.
 * 
 * @version 1.0
 * @author Manhattan System
 */

class OzonDataCache {
    private $pdo;
    private $defaultTTL = 3600; // 1 час по умолчанию
    
    /**
     * Конструктор класса
     * 
     * @param PDO|null $pdo - подключение к базе данных
     */
    public function __construct(PDO $pdo = null) {
        $this->pdo = $pdo;
    }
    
    /**
     * Получение кэшированных данных воронки
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param array $filters - фильтры
     * @return array|null кэшированные данные или null
     */
    public function getFunnelData($dateFrom, $dateTo, $filters = []) {
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
     * Сохранение данных воронки в кэш
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param array $filters - фильтры
     * @param array $data - данные для сохранения
     * @return bool успех операции
     */
    public function setFunnelData($dateFrom, $dateTo, $filters, $data) {
        if (!$this->pdo || empty($data)) {
            return false;
        }
        
        try {
            // Данные уже сохраняются в основном методе saveFunnelDataToDatabase
            // Этот метод нужен для совместимости с интерфейсом
            return true;
            
        } catch (Exception $e) {
            error_log('Ошибка сохранения данных воронки в кэш: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Получение кэшированных демографических данных
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param array $filters - фильтры
     * @return array|null кэшированные данные или null
     */
    public function getDemographicsData($dateFrom, $dateTo, $filters = []) {
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
            
            return $stmt->fetchAll();
            
        } catch (PDOException $e) {
            error_log('Ошибка получения кэшированных демографических данных: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Сохранение демографических данных в кэш
     * 
     * @param string $dateFrom - начальная дата
     * @param string $dateTo - конечная дата
     * @param array $filters - фильтры
     * @param array $data - данные для сохранения
     * @return bool успех операции
     */
    public function setDemographicsData($dateFrom, $dateTo, $filters, $data) {
        if (!$this->pdo || empty($data)) {
            return false;
        }
        
        try {
            // Данные уже сохраняются в основном методе
            // Этот метод нужен для совместимости с интерфейсом
            return true;
            
        } catch (Exception $e) {
            error_log('Ошибка сохранения демографических данных в кэш: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Очистка устаревшего кэша
     * 
     * @param int $maxAge - максимальный возраст кэша в секундах
     * @return int количество удаленных записей
     */
    public function cleanupExpiredCache($maxAge = 86400) {
        if (!$this->pdo) {
            return 0;
        }
        
        try {
            $deletedCount = 0;
            
            // Очищаем устаревшие данные воронки
            $sql = "DELETE FROM ozon_funnel_data 
                    WHERE cached_at < DATE_SUB(NOW(), INTERVAL :max_age SECOND)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['max_age' => $maxAge]);
            $deletedCount += $stmt->rowCount();
            
            // Очищаем устаревшие демографические данные
            $sql = "DELETE FROM ozon_demographics 
                    WHERE cached_at < DATE_SUB(NOW(), INTERVAL :max_age SECOND)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['max_age' => $maxAge]);
            $deletedCount += $stmt->rowCount();
            
            return $deletedCount;
            
        } catch (PDOException $e) {
            error_log('Ошибка очистки кэша: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Получение статистики кэша
     * 
     * @return array статистика кэша
     */
    public function getCacheStats() {
        if (!$this->pdo) {
            return [];
        }
        
        try {
            $stats = [];
            
            // Статистика данных воронки
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM ozon_funnel_data");
            $stats['funnel_records'] = $stmt->fetchColumn();
            
            // Статистика демографических данных
            $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM ozon_demographics");
            $stats['demographics_records'] = $stmt->fetchColumn();
            
            // Последнее обновление
            $stmt = $this->pdo->query("SELECT MAX(cached_at) as last_update FROM ozon_funnel_data");
            $stats['last_funnel_update'] = $stmt->fetchColumn();
            
            $stmt = $this->pdo->query("SELECT MAX(cached_at) as last_update FROM ozon_demographics");
            $stats['last_demographics_update'] = $stmt->fetchColumn();
            
            return $stats;
            
        } catch (PDOException $e) {
            error_log('Ошибка получения статистики кэша: ' . $e->getMessage());
            return [];
        }
    }
}