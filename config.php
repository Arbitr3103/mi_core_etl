<?php
/**
 * Простая конфигурация для тестирования API фильтра активности
 */

// Устанавливаем переменные окружения для продакшена
$_ENV['APP_ENV'] = 'production';
$_SERVER['APP_ENV'] = 'production';
putenv('APP_ENV=production');

// Загружаем production конфигурацию
if (file_exists(__DIR__ . '/config/production_db_override.php')) {
    require_once __DIR__ . '/config/production_db_override.php';
}

if (file_exists(__DIR__ . '/config/production.php')) {
    require_once __DIR__ . '/config/production.php';
}

// Функция для получения подключения к базе данных
function getDatabaseConnection() {
    if (function_exists('getProductionPgConnection')) {
        return getProductionPgConnection();
    }
    
    // Fallback для тестирования
    throw new Exception('Функция подключения к базе данных не найдена');
}

// Заглушки для функций кэширования и мониторинга
if (!function_exists('getInventoryCacheManager')) {
    function getInventoryCacheManager() {
        return new class {
            public function remember($key, $callback, $ttl = 300) {
                return $callback();
            }
            public function get($key) {
                return null;
            }
        };
    }
}

if (!function_exists('getPerformanceMonitor')) {
    function getPerformanceMonitor() {
        return new class {
            public function startTimer($name) {}
            public function endTimer($name, $context = []) {
                return ['execution_time_ms' => 0, 'memory_used_mb' => 0];
            }
        };
    }
}

// Заглушка для InventoryCacheKeys
if (!class_exists('InventoryCacheKeys')) {
    class InventoryCacheKeys {
        public static function getDashboardKey() {
            return 'dashboard';
        }
        public static function getCriticalProductsKey() {
            return 'critical_products';
        }
        public static function getOverstockProductsKey() {
            return 'overstock_products';
        }
        public static function getWarehouseSummaryKey() {
            return 'warehouse_summary';
        }
        public static function getWarehouseDetailsKey($name) {
            return 'warehouse_details_' . $name;
        }
        public static function getRecommendationsKey() {
            return 'recommendations';
        }
    }
}
?>