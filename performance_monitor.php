<?php
/**
 * Заглушка для performance_monitor.php
 * Простая реализация мониторинга производительности
 */

class PerformanceMonitor {
    private $timers = [];
    private $metrics = [];
    
    public function startTimer($name) {
        $this->timers[$name] = microtime(true);
        return $this;
    }
    
    public function endTimer($name, $data = []) {
        $endTime = microtime(true);
        $startTime = $this->timers[$name] ?? $endTime;
        $duration = $endTime - $startTime;
        
        $this->metrics[$name] = array_merge($data, [
            'duration_ms' => round($duration * 1000, 2),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
        return $this->metrics[$name];
    }
    
    public function getMetrics() {
        return $this->metrics;
    }
    
    public function recordMetric($name, $value, $data = []) {
        $this->metrics[$name] = array_merge($data, [
            'value' => $value,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

// Функция для получения экземпляра монитора (только если не объявлена)
if (!function_exists('getPerformanceMonitor')) {
    function getPerformanceMonitor() {
        static $monitor = null;
        if ($monitor === null) {
            $monitor = new PerformanceMonitor();
        }
        return $monitor;
    }
}
?>