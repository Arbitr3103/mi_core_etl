<?php
/**
 * Заглушка для inventory_cache_manager.php
 * Простая реализация без реального кэширования
 */

class InventoryCacheKeys {
    public static function getDashboardKey() {
        return 'dashboard_data';
    }
    
    public static function getCriticalProductsKey() {
        return 'critical_products';
    }
    
    public static function getOverstockProductsKey() {
        return 'overstock_products';
    }
}

class InventoryCache {
    private $cache = [];
    
    public function remember($key, $callback, $ttl = 300) {
        // Простая реализация без реального кэширования
        // Всегда выполняем callback
        return $callback();
    }
    
    public function get($key) {
        return isset($this->cache[$key]) ? $this->cache[$key] : null;
    }
    
    public function set($key, $value, $ttl = 300) {
        $this->cache[$key] = $value;
        return true;
    }
    
    public function forget($key) {
        unset($this->cache[$key]);
        return true;
    }
}

// Функция для получения экземпляра кэша
function getInventoryCache() {
    static $cache = null;
    if ($cache === null) {
        $cache = new InventoryCache();
    }
    return $cache;
}

// Алиас для совместимости
function getInventoryCacheManager() {
    return getInventoryCache();
}
?>