<?php
/**
 * Система кэширования для API складских остатков
 * Реализует кэширование часто запрашиваемых данных для оптимизации производительности
 * Создано для задачи 7: Оптимизировать производительность
 */

class InventoryCacheManager {
    private $cache_dir;
    private $default_ttl;
    private $enabled;
    
    public function __construct($cache_dir = null, $default_ttl = 300, $enabled = true) {
        $this->cache_dir = $cache_dir ?: __DIR__ . '/../cache/inventory';
        $this->default_ttl = $default_ttl; // 5 минут по умолчанию
        $this->enabled = $enabled;
        
        // Создаем директорию кэша если не существует
        if ($this->enabled && !is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0755, true);
        }
    }
    
    /**
     * Получить данные из кэша
     */
    public function get($key) {
        if (!$this->enabled) {
            return null;
        }
        
        $cache_file = $this->getCacheFilePath($key);
        
        if (!file_exists($cache_file)) {
            return null;
        }
        
        $cache_data = json_decode(file_get_contents($cache_file), true);
        
        if (!$cache_data || !isset($cache_data['expires_at']) || !isset($cache_data['data'])) {
            return null;
        }
        
        // Проверяем срок действия
        if (time() > $cache_data['expires_at']) {
            $this->delete($key);
            return null;
        }
        
        return $cache_data['data'];
    }
    
    /**
     * Сохранить данные в кэш
     */
    public function set($key, $data, $ttl = null) {
        if (!$this->enabled) {
            return false;
        }
        
        $ttl = $ttl ?: $this->default_ttl;
        $cache_file = $this->getCacheFilePath($key);
        
        $cache_data = [
            'data' => $data,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'ttl' => $ttl
        ];
        
        return file_put_contents($cache_file, json_encode($cache_data), LOCK_EX) !== false;
    }
    
    /**
     * Удалить данные из кэша
     */
    public function delete($key) {
        if (!$this->enabled) {
            return false;
        }
        
        $cache_file = $this->getCacheFilePath($key);
        
        if (file_exists($cache_file)) {
            return unlink($cache_file);
        }
        
        return true;
    }
    
    /**
     * Очистить весь кэш
     */
    public function clear() {
        if (!$this->enabled) {
            return false;
        }
        
        $files = glob($this->cache_dir . '/*.cache');
        $cleared = 0;
        
        foreach ($files as $file) {
            if (unlink($file)) {
                $cleared++;
            }
        }
        
        return $cleared;
    }
    
    /**
     * Получить статистику кэша
     */
    public function getStats() {
        if (!$this->enabled) {
            return [
                'enabled' => false,
                'message' => 'Кэширование отключено'
            ];
        }
        
        $files = glob($this->cache_dir . '/*.cache');
        $total_files = count($files);
        $total_size = 0;
        $expired_files = 0;
        $valid_files = 0;
        
        foreach ($files as $file) {
            $size = filesize($file);
            $total_size += $size;
            
            $cache_data = json_decode(file_get_contents($file), true);
            if ($cache_data && isset($cache_data['expires_at'])) {
                if (time() > $cache_data['expires_at']) {
                    $expired_files++;
                } else {
                    $valid_files++;
                }
            }
        }
        
        return [
            'enabled' => true,
            'total_files' => $total_files,
            'valid_files' => $valid_files,
            'expired_files' => $expired_files,
            'total_size_bytes' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2),
            'cache_dir' => $this->cache_dir,
            'default_ttl' => $this->default_ttl
        ];
    }
    
    /**
     * Очистить устаревшие файлы кэша
     */
    public function cleanExpired() {
        if (!$this->enabled) {
            return 0;
        }
        
        $files = glob($this->cache_dir . '/*.cache');
        $cleaned = 0;
        
        foreach ($files as $file) {
            $cache_data = json_decode(file_get_contents($file), true);
            if ($cache_data && isset($cache_data['expires_at'])) {
                if (time() > $cache_data['expires_at']) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
        }
        
        return $cleaned;
    }
    
    /**
     * Получить или установить данные (cache-aside pattern)
     */
    public function remember($key, $callback, $ttl = null) {
        $data = $this->get($key);
        
        if ($data !== null) {
            return $data;
        }
        
        // Данных в кэше нет, получаем их через callback
        $data = $callback();
        
        if ($data !== null) {
            $this->set($key, $data, $ttl);
        }
        
        return $data;
    }
    
    /**
     * Генерировать ключ кэша на основе параметров
     */
    public static function generateKey($prefix, $params = []) {
        $key_parts = [$prefix];
        
        if (!empty($params)) {
            ksort($params); // Сортируем для консистентности
            $key_parts[] = md5(serialize($params));
        }
        
        return implode('_', $key_parts);
    }
    
    /**
     * Получить путь к файлу кэша
     */
    private function getCacheFilePath($key) {
        $safe_key = preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
        return $this->cache_dir . '/' . $safe_key . '.cache';
    }
    
    /**
     * Проверить доступность кэширования
     */
    public function isEnabled() {
        return $this->enabled && is_dir($this->cache_dir) && is_writable($this->cache_dir);
    }
    
    /**
     * Включить/выключить кэширование
     */
    public function setEnabled($enabled) {
        $this->enabled = $enabled;
    }
    
    /**
     * Установить TTL по умолчанию
     */
    public function setDefaultTTL($ttl) {
        $this->default_ttl = $ttl;
    }
}

/**
 * Глобальная функция для получения экземпляра кэш-менеджера
 */
function getInventoryCacheManager() {
    static $instance = null;
    
    if ($instance === null) {
        // Настройки кэширования из конфигурации или переменных окружения
        $cache_enabled = $_ENV['INVENTORY_CACHE_ENABLED'] ?? true;
        $cache_ttl = $_ENV['INVENTORY_CACHE_TTL'] ?? 300; // 5 минут
        $cache_dir = $_ENV['INVENTORY_CACHE_DIR'] ?? __DIR__ . '/../cache/inventory';
        
        $instance = new InventoryCacheManager($cache_dir, $cache_ttl, $cache_enabled);
    }
    
    return $instance;
}

/**
 * Специализированные функции кэширования для разных типов данных
 */
class InventoryCacheKeys {
    const DASHBOARD_DATA = 'dashboard_data';
    const CRITICAL_PRODUCTS = 'critical_products';
    const OVERSTOCK_PRODUCTS = 'overstock_products';
    const WAREHOUSE_SUMMARY = 'warehouse_summary';
    const WAREHOUSE_DETAILS = 'warehouse_details';
    const RECOMMENDATIONS = 'recommendations';
    const PRODUCTS_BY_WAREHOUSE = 'products_by_warehouse';
    
    /**
     * Получить ключ для данных дашборда
     */
    public static function getDashboardKey($params = []) {
        return InventoryCacheManager::generateKey(self::DASHBOARD_DATA, $params);
    }
    
    /**
     * Получить ключ для критических товаров
     */
    public static function getCriticalProductsKey($params = []) {
        return InventoryCacheManager::generateKey(self::CRITICAL_PRODUCTS, $params);
    }
    
    /**
     * Получить ключ для товаров с избытком
     */
    public static function getOverstockProductsKey($params = []) {
        return InventoryCacheManager::generateKey(self::OVERSTOCK_PRODUCTS, $params);
    }
    
    /**
     * Получить ключ для сводки по складам
     */
    public static function getWarehouseSummaryKey($params = []) {
        return InventoryCacheManager::generateKey(self::WAREHOUSE_SUMMARY, $params);
    }
    
    /**
     * Получить ключ для деталей склада
     */
    public static function getWarehouseDetailsKey($warehouse_name, $params = []) {
        $params['warehouse'] = $warehouse_name;
        return InventoryCacheManager::generateKey(self::WAREHOUSE_DETAILS, $params);
    }
    
    /**
     * Получить ключ для рекомендаций
     */
    public static function getRecommendationsKey($params = []) {
        return InventoryCacheManager::generateKey(self::RECOMMENDATIONS, $params);
    }
    
    /**
     * Получить ключ для товаров по складам
     */
    public static function getProductsByWarehouseKey($params = []) {
        return InventoryCacheManager::generateKey(self::PRODUCTS_BY_WAREHOUSE, $params);
    }
}

/**
 * Утилиты для работы с кэшем
 */
class InventoryCacheUtils {
    /**
     * Инвалидировать кэш при обновлении данных
     */
    public static function invalidateInventoryCache() {
        $cache = getInventoryCacheManager();
        
        // Очищаем основные ключи кэша
        $keys_to_clear = [
            InventoryCacheKeys::getDashboardKey(),
            InventoryCacheKeys::getCriticalProductsKey(),
            InventoryCacheKeys::getOverstockProductsKey(),
            InventoryCacheKeys::getWarehouseSummaryKey(),
            InventoryCacheKeys::getRecommendationsKey(),
            InventoryCacheKeys::getProductsByWarehouseKey()
        ];
        
        foreach ($keys_to_clear as $key) {
            $cache->delete($key);
        }
        
        // Очищаем устаревшие файлы
        return $cache->cleanExpired();
    }
    
    /**
     * Прогреть кэш основными данными
     */
    public static function warmupCache($pdo) {
        $cache = getInventoryCacheManager();
        
        if (!$cache->isEnabled()) {
            return false;
        }
        
        try {
            // Прогреваем основные данные дашборда
            require_once __DIR__ . '/inventory-analytics.php';
            
            $dashboard_key = InventoryCacheKeys::getDashboardKey();
            $cache->remember($dashboard_key, function() use ($pdo) {
                return getInventoryDashboardData($pdo);
            });
            
            $warehouse_key = InventoryCacheKeys::getWarehouseSummaryKey();
            $cache->remember($warehouse_key, function() use ($pdo) {
                return getWarehouseSummary($pdo);
            });
            
            $recommendations_key = InventoryCacheKeys::getRecommendationsKey();
            $cache->remember($recommendations_key, function() use ($pdo) {
                return getDetailedRecommendations($pdo);
            });
            
            return true;
        } catch (Exception $e) {
            error_log("Cache warmup failed: " . $e->getMessage());
            return false;
        }
    }
}
?>