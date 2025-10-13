#!/usr/bin/env php
<?php
/**
 * Утилита управления кэшем для дашборда складских остатков
 * Позволяет очищать, прогревать и мониторить кэш
 * Создано для задачи 7: Оптимизировать производительность
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../api/inventory_cache_manager.php';

class InventoryCacheManagerCLI {
    private $cache;
    private $pdo;
    
    public function __construct() {
        $this->cache = getInventoryCacheManager();
        $this->pdo = getDatabaseConnection();
    }
    
    public function run($args) {
        if (empty($args)) {
            $this->showHelp();
            return;
        }
        
        $command = $args[0];
        
        switch ($command) {
            case 'status':
                $this->showStatus();
                break;
                
            case 'clear':
                $this->clearCache();
                break;
                
            case 'warmup':
                $this->warmupCache();
                break;
                
            case 'clean':
                $this->cleanExpired();
                break;
                
            case 'stats':
                $this->showDetailedStats();
                break;
                
            case 'test':
                $this->testCache();
                break;
                
            case 'enable':
                $this->enableCache();
                break;
                
            case 'disable':
                $this->disableCache();
                break;
                
            default:
                echo "Неизвестная команда: $command\n";
                $this->showHelp();
        }
    }
    
    private function showHelp() {
        echo "Утилита управления кэшем дашборда складских остатков\n\n";
        echo "Использование: php manage_inventory_cache.php <команда>\n\n";
        echo "Доступные команды:\n";
        echo "  status    - Показать статус кэша\n";
        echo "  clear     - Очистить весь кэш\n";
        echo "  warmup    - Прогреть кэш основными данными\n";
        echo "  clean     - Удалить устаревшие файлы кэша\n";
        echo "  stats     - Показать детальную статистику\n";
        echo "  test      - Протестировать работу кэша\n";
        echo "  enable    - Включить кэширование\n";
        echo "  disable   - Отключить кэширование\n";
        echo "\n";
    }
    
    private function showStatus() {
        echo "=== Статус кэша дашборда складских остатков ===\n\n";
        
        $stats = $this->cache->getStats();
        
        if (!$stats['enabled']) {
            echo "❌ Кэширование отключено\n";
            return;
        }
        
        echo "✅ Кэширование включено\n";
        echo "📁 Директория кэша: {$stats['cache_dir']}\n";
        echo "⏱️  TTL по умолчанию: {$stats['default_ttl']} секунд\n";
        echo "📄 Всего файлов: {$stats['total_files']}\n";
        echo "✅ Актуальных файлов: {$stats['valid_files']}\n";
        echo "❌ Устаревших файлов: {$stats['expired_files']}\n";
        echo "💾 Размер кэша: {$stats['total_size_mb']} MB\n";
        
        if ($stats['expired_files'] > 0) {
            echo "\n⚠️  Рекомендуется очистить устаревшие файлы: php manage_inventory_cache.php clean\n";
        }
        
        echo "\n";
    }
    
    private function clearCache() {
        echo "Очистка кэша...\n";
        
        $cleared = $this->cache->clear();
        
        if ($cleared > 0) {
            echo "✅ Очищено файлов: $cleared\n";
        } else {
            echo "ℹ️  Кэш уже пуст или кэширование отключено\n";
        }
    }
    
    private function warmupCache() {
        echo "Прогрев кэша основными данными...\n";
        
        if (!$this->cache->isEnabled()) {
            echo "❌ Кэширование отключено\n";
            return;
        }
        
        try {
            $success = InventoryCacheUtils::warmupCache($this->pdo);
            
            if ($success) {
                echo "✅ Кэш успешно прогрет\n";
                
                // Показываем статистику после прогрева
                $stats = $this->cache->getStats();
                echo "📄 Файлов в кэше: {$stats['total_files']}\n";
                echo "💾 Размер: {$stats['total_size_mb']} MB\n";
            } else {
                echo "❌ Ошибка прогрева кэша\n";
            }
        } catch (Exception $e) {
            echo "❌ Ошибка: " . $e->getMessage() . "\n";
        }
    }
    
    private function cleanExpired() {
        echo "Очистка устаревших файлов кэша...\n";
        
        $cleaned = $this->cache->cleanExpired();
        
        if ($cleaned > 0) {
            echo "✅ Удалено устаревших файлов: $cleaned\n";
        } else {
            echo "ℹ️  Устаревших файлов не найдено\n";
        }
    }
    
    private function showDetailedStats() {
        echo "=== Детальная статистика кэша ===\n\n";
        
        $stats = $this->cache->getStats();
        
        if (!$stats['enabled']) {
            echo "❌ Кэширование отключено\n";
            return;
        }
        
        // Основная статистика
        echo "Основная информация:\n";
        echo "  Статус: " . ($stats['enabled'] ? 'Включен' : 'Отключен') . "\n";
        echo "  Директория: {$stats['cache_dir']}\n";
        echo "  TTL по умолчанию: {$stats['default_ttl']} сек\n";
        echo "\n";
        
        // Статистика файлов
        echo "Файлы кэша:\n";
        echo "  Всего файлов: {$stats['total_files']}\n";
        echo "  Актуальных: {$stats['valid_files']}\n";
        echo "  Устаревших: {$stats['expired_files']}\n";
        echo "  Размер: {$stats['total_size_bytes']} байт ({$stats['total_size_mb']} MB)\n";
        echo "\n";
        
        // Анализ эффективности
        if ($stats['total_files'] > 0) {
            $hit_rate = ($stats['valid_files'] / $stats['total_files']) * 100;
            echo "Эффективность:\n";
            echo "  Hit rate: " . round($hit_rate, 1) . "%\n";
            
            if ($hit_rate < 70) {
                echo "  ⚠️  Низкий hit rate, рекомендуется увеличить TTL\n";
            } elseif ($hit_rate > 95) {
                echo "  ✅ Отличный hit rate\n";
            } else {
                echo "  ✅ Хороший hit rate\n";
            }
        }
        
        // Детальная информация о файлах
        $this->showCacheFiles();
        
        echo "\n";
    }
    
    private function showCacheFiles() {
        if (!$this->cache->isEnabled()) {
            return;
        }
        
        $cache_dir = $this->cache->getStats()['cache_dir'];
        $files = glob($cache_dir . '/*.cache');
        
        if (empty($files)) {
            echo "  Файлы кэша отсутствуют\n";
            return;
        }
        
        echo "\nДетали файлов кэша:\n";
        echo str_pad("Файл", 30) . str_pad("Размер", 10) . str_pad("Статус", 12) . "Истекает\n";
        echo str_repeat("-", 70) . "\n";
        
        foreach ($files as $file) {
            $filename = basename($file, '.cache');
            $size = filesize($file);
            $size_kb = round($size / 1024, 1);
            
            $cache_data = json_decode(file_get_contents($file), true);
            if ($cache_data && isset($cache_data['expires_at'])) {
                $expires_at = $cache_data['expires_at'];
                $is_expired = time() > $expires_at;
                $status = $is_expired ? 'Устарел' : 'Актуален';
                $expires_str = date('H:i:s', $expires_at);
            } else {
                $status = 'Поврежден';
                $expires_str = 'N/A';
            }
            
            echo str_pad(substr($filename, 0, 28), 30) . 
                 str_pad($size_kb . ' KB', 10) . 
                 str_pad($status, 12) . 
                 $expires_str . "\n";
        }
    }
    
    private function testCache() {
        echo "Тестирование кэша...\n\n";
        
        if (!$this->cache->isEnabled()) {
            echo "❌ Кэширование отключено\n";
            return;
        }
        
        // Тест 1: Запись и чтение
        echo "Тест 1: Запись и чтение данных\n";
        $test_key = 'test_' . time();
        $test_data = ['test' => true, 'timestamp' => time()];
        
        $write_success = $this->cache->set($test_key, $test_data, 60);
        echo "  Запись: " . ($write_success ? '✅' : '❌') . "\n";
        
        $read_data = $this->cache->get($test_key);
        $read_success = $read_data && $read_data['test'] === true;
        echo "  Чтение: " . ($read_success ? '✅' : '❌') . "\n";
        
        // Тест 2: TTL
        echo "\nТест 2: Проверка TTL\n";
        $short_ttl_key = 'test_ttl_' . time();
        $this->cache->set($short_ttl_key, ['ttl_test' => true], 1);
        
        $immediate_read = $this->cache->get($short_ttl_key);
        echo "  Немедленное чтение: " . ($immediate_read ? '✅' : '❌') . "\n";
        
        sleep(2);
        $delayed_read = $this->cache->get($short_ttl_key);
        echo "  Чтение после истечения TTL: " . ($delayed_read === null ? '✅' : '❌') . "\n";
        
        // Тест 3: Производительность
        echo "\nТест 3: Производительность\n";
        $start_time = microtime(true);
        
        for ($i = 0; $i < 100; $i++) {
            $this->cache->set("perf_test_$i", ['data' => str_repeat('x', 1000)], 300);
        }
        
        $write_time = microtime(true) - $start_time;
        echo "  100 записей: " . round($write_time * 1000, 2) . " мс\n";
        
        $start_time = microtime(true);
        
        for ($i = 0; $i < 100; $i++) {
            $this->cache->get("perf_test_$i");
        }
        
        $read_time = microtime(true) - $start_time;
        echo "  100 чтений: " . round($read_time * 1000, 2) . " мс\n";
        
        // Очистка тестовых данных
        $this->cache->delete($test_key);
        for ($i = 0; $i < 100; $i++) {
            $this->cache->delete("perf_test_$i");
        }
        
        echo "\n✅ Тестирование завершено\n";
    }
    
    private function enableCache() {
        echo "Включение кэширования...\n";
        $this->cache->setEnabled(true);
        echo "✅ Кэширование включено\n";
    }
    
    private function disableCache() {
        echo "Отключение кэширования...\n";
        $this->cache->setEnabled(false);
        echo "✅ Кэширование отключено\n";
    }
}

// Запуск утилиты
if (php_sapi_name() === 'cli') {
    $manager = new InventoryCacheManagerCLI();
    $args = array_slice($argv, 1);
    $manager->run($args);
} else {
    echo "Эта утилита должна запускаться из командной строки\n";
}
?>