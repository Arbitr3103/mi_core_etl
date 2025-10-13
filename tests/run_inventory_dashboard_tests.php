<?php
/**
 * Главный тест-раннер для всех тестов дашборда складских остатков
 * Выполняет все тесты и генерирует сводный отчет
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/test_inventory_dashboard_api_direct.php';
require_once __DIR__ . '/test_product_classification.php';
require_once __DIR__ . '/test_dashboard_integration.php';

class InventoryDashboardTestRunner {
    private $test_results = [];
    private $start_time;
    
    public function __construct() {
        $this->start_time = microtime(true);
    }
    
    /**
     * Запуск всех тестов дашборда складских остатков
     */
    public function runAllTests() {
        echo "🚀 ЗАПУСК ПОЛНОГО НАБОРА ТЕСТОВ ДАШБОРДА СКЛАДСКИХ ОСТАТКОВ\n";
        echo str_repeat("=", 80) . "\n";
        echo "Дата и время: " . date('Y-m-d H:i:s') . "\n";
        echo "Тестируемые компоненты:\n";
        echo "  • API endpoints (inventory-analytics.php)\n";
        echo "  • Логика классификации товаров\n";
        echo "  • Интеграция дашборда\n";
        echo str_repeat("=", 80) . "\n\n";
        
        $overall_success = true;
        
        // 1. Тесты API endpoints
        echo "📡 БЛОК 1: ТЕСТИРОВАНИЕ API ENDPOINTS\n";
        echo str_repeat("-", 50) . "\n";
        try {
            $api_tester = new InventoryDashboardApiDirectTest();
            $api_success = $api_tester->runAllTests();
            $this->test_results['api_endpoints'] = $api_success ? 'PASSED' : 'FAILED';
            $overall_success = $overall_success && $api_success;
        } catch (Exception $e) {
            echo "❌ Критическая ошибка в тестах API: " . $e->getMessage() . "\n";
            $this->test_results['api_endpoints'] = 'FAILED - ' . $e->getMessage();
            $overall_success = false;
        }
        
        echo "\n" . str_repeat("-", 50) . "\n\n";
        
        // 2. Тесты классификации товаров
        echo "🏷️ БЛОК 2: ТЕСТИРОВАНИЕ КЛАССИФИКАЦИИ ТОВАРОВ\n";
        echo str_repeat("-", 50) . "\n";
        try {
            $classification_tester = new ProductClassificationTest();
            $classification_success = $classification_tester->runAllTests();
            $this->test_results['product_classification'] = $classification_success ? 'PASSED' : 'FAILED';
            $overall_success = $overall_success && $classification_success;
        } catch (Exception $e) {
            echo "❌ Критическая ошибка в тестах классификации: " . $e->getMessage() . "\n";
            $this->test_results['product_classification'] = 'FAILED - ' . $e->getMessage();
            $overall_success = false;
        }
        
        echo "\n" . str_repeat("-", 50) . "\n\n";
        
        // 3. Интеграционные тесты
        echo "🔗 БЛОК 3: ИНТЕГРАЦИОННЫЕ ТЕСТЫ ДАШБОРДА\n";
        echo str_repeat("-", 50) . "\n";
        try {
            $integration_tester = new DashboardIntegrationTest();
            $integration_success = $integration_tester->runAllTests();
            $this->test_results['dashboard_integration'] = $integration_success ? 'PASSED' : 'FAILED';
            $overall_success = $overall_success && $integration_success;
        } catch (Exception $e) {
            echo "❌ Критическая ошибка в интеграционных тестах: " . $e->getMessage() . "\n";
            $this->test_results['dashboard_integration'] = 'FAILED - ' . $e->getMessage();
            $overall_success = false;
        }
        
        // Генерируем итоговый отчет
        $this->generateFinalReport($overall_success);
        
        return $overall_success;
    }
    
    /**
     * Генерация итогового отчета
     */
    private function generateFinalReport($overall_success) {
        $end_time = microtime(true);
        $total_execution_time = round($end_time - $this->start_time, 2);
        
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "📊 ИТОГОВЫЙ ОТЧЕТ ТЕСТИРОВАНИЯ ДАШБОРДА СКЛАДСКИХ ОСТАТКОВ\n";
        echo str_repeat("=", 80) . "\n";
        
        // Статистика по блокам тестов
        echo "Результаты по блокам тестов:\n";
        echo str_repeat("-", 40) . "\n";
        
        $test_blocks = [
            'api_endpoints' => 'API Endpoints',
            'product_classification' => 'Классификация товаров',
            'dashboard_integration' => 'Интеграция дашборда'
        ];
        
        $passed_blocks = 0;
        $total_blocks = count($test_blocks);
        
        foreach ($test_blocks as $key => $name) {
            $result = $this->test_results[$key] ?? 'NOT_RUN';
            $status_icon = strpos($result, 'PASSED') !== false ? '✅' : '❌';
            $status_text = strpos($result, 'PASSED') !== false ? 'PASSED' : 'FAILED';
            
            echo sprintf("%-30s %s %s\n", $name, $status_icon, $status_text);
            
            if (strpos($result, 'PASSED') !== false) {
                $passed_blocks++;
            } elseif (strpos($result, 'FAILED -') !== false) {
                echo "   Детали: " . str_replace('FAILED - ', '', $result) . "\n";
            }
        }
        
        echo str_repeat("-", 40) . "\n";
        echo sprintf("Пройдено блоков: %d/%d (%.1f%%)\n", 
            $passed_blocks, $total_blocks, ($passed_blocks / $total_blocks) * 100);
        
        // Проверка соответствия требованиям
        echo "\nПроверка соответствия требованиям:\n";
        echo str_repeat("-", 40) . "\n";
        
        $requirements_check = [
            '1.1' => 'Отображение реальных данных из inventory_data',
            '1.2' => 'Использование правильных названий колонок',
            '2.1' => 'Классификация критических товаров (≤5)',
            '2.2' => 'Классификация товаров с низким остатком (6-20)',
            '2.3' => 'Классификация товаров с избытком (>100)',
            '3.1' => 'Получение названий из dim_products',
            '3.2' => 'Fallback для отсутствующих названий',
            '4.1' => 'Рекомендации для критических товаров',
            '4.2' => 'Рекомендации для товаров с избытком',
            '4.3' => 'Рекомендации для товаров с низким остатком',
            '5.1' => 'Группировка данных по складам',
            '5.2' => 'Агрегация остатков по складам',
            '5.3' => 'Отображение информации о складах'
        ];
        
        foreach ($requirements_check as $req_id => $req_description) {
            $status = $overall_success ? '✅' : '⚠️';
            echo sprintf("Требование %-4s %s %s\n", $req_id, $status, $req_description);
        }
        
        // Общая статистика
        echo "\n" . str_repeat("-", 40) . "\n";
        echo "Общая статистика:\n";
        echo "• Время выполнения: {$total_execution_time} сек\n";
        echo "• Дата тестирования: " . date('Y-m-d H:i:s') . "\n";
        echo "• Статус: " . ($overall_success ? '✅ ВСЕ ТЕСТЫ ПРОЙДЕНЫ' : '❌ ЕСТЬ ПРОВАЛЕННЫЕ ТЕСТЫ') . "\n";
        
        // Рекомендации
        if (!$overall_success) {
            echo "\n📋 РЕКОМЕНДАЦИИ ПО ИСПРАВЛЕНИЮ:\n";
            echo str_repeat("-", 40) . "\n";
            echo "1. Проверьте подключение к базе данных\n";
            echo "2. Убедитесь что таблицы inventory_data и dim_products существуют\n";
            echo "3. Проверьте корректность структуры таблиц\n";
            echo "4. Убедитесь что веб-сервер запущен для интеграционных тестов\n";
            echo "5. Проверьте права доступа к файлам и директориям\n";
        } else {
            echo "\n🎉 ПОЗДРАВЛЯЕМ! ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
            echo "Дашборд складских остатков готов к использованию.\n";
        }
        
        // Сохраняем отчет в файл
        $this->saveReportToFile($overall_success, $total_execution_time);
        
        echo str_repeat("=", 80) . "\n";
    }
    
    /**
     * Сохранение отчета в файл
     */
    private function saveReportToFile($overall_success, $execution_time) {
        try {
            $report_dir = __DIR__ . '/../logs';
            if (!is_dir($report_dir)) {
                mkdir($report_dir, 0755, true);
            }
            
            $report_file = $report_dir . '/inventory_dashboard_test_report_' . date('Y-m-d_H-i-s') . '.json';
            
            $report_data = [
                'timestamp' => date('Y-m-d H:i:s'),
                'overall_success' => $overall_success,
                'execution_time_seconds' => $execution_time,
                'test_results' => $this->test_results,
                'requirements_tested' => [
                    '1.1', '1.2', '2.1', '2.2', '2.3', 
                    '3.1', '3.2', '4.1', '4.2', '4.3', 
                    '5.1', '5.2', '5.3'
                ],
                'test_environment' => [
                    'php_version' => PHP_VERSION,
                    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
                    'database_available' => $this->checkDatabaseConnection()
                ]
            ];
            
            file_put_contents($report_file, json_encode($report_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            echo "\n📄 Отчет сохранен в файл: $report_file\n";
            
        } catch (Exception $e) {
            echo "\n⚠️ Не удалось сохранить отчет: " . $e->getMessage() . "\n";
        }
    }
    
    /**
     * Проверка подключения к базе данных
     */
    private function checkDatabaseConnection() {
        try {
            $pdo = getDatabaseConnection();
            $stmt = $pdo->query("SELECT 1");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Проверка готовности системы к тестированию
     */
    public function checkSystemReadiness() {
        echo "🔍 ПРОВЕРКА ГОТОВНОСТИ СИСТЕМЫ К ТЕСТИРОВАНИЮ\n";
        echo str_repeat("-", 50) . "\n";
        
        $checks = [];
        
        // Проверка подключения к БД
        try {
            $pdo = getDatabaseConnection();
            $stmt = $pdo->query("SELECT 1");
            $checks['database'] = true;
            echo "✅ Подключение к базе данных: OK\n";
        } catch (Exception $e) {
            $checks['database'] = false;
            echo "❌ Подключение к базе данных: FAILED - " . $e->getMessage() . "\n";
        }
        
        // Проверка таблиц
        try {
            $pdo = getDatabaseConnection();
            
            $tables = ['inventory_data', 'dim_products'];
            foreach ($tables as $table) {
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                if ($stmt->rowCount() > 0) {
                    $checks["table_$table"] = true;
                    echo "✅ Таблица $table: OK\n";
                } else {
                    $checks["table_$table"] = false;
                    echo "❌ Таблица $table: НЕ НАЙДЕНА\n";
                }
            }
        } catch (Exception $e) {
            echo "❌ Ошибка проверки таблиц: " . $e->getMessage() . "\n";
        }
        
        // Проверка API файла
        $api_file = __DIR__ . '/../api/inventory-analytics.php';
        if (file_exists($api_file)) {
            $checks['api_file'] = true;
            echo "✅ API файл inventory-analytics.php: OK\n";
        } else {
            $checks['api_file'] = false;
            echo "❌ API файл inventory-analytics.php: НЕ НАЙДЕН\n";
        }
        
        // Проверка прав на запись в logs
        $logs_dir = __DIR__ . '/../logs';
        if (!is_dir($logs_dir)) {
            mkdir($logs_dir, 0755, true);
        }
        
        if (is_writable($logs_dir)) {
            $checks['logs_writable'] = true;
            echo "✅ Права на запись в logs: OK\n";
        } else {
            $checks['logs_writable'] = false;
            echo "❌ Права на запись в logs: НЕТ ДОСТУПА\n";
        }
        
        $all_ready = !in_array(false, $checks);
        
        echo str_repeat("-", 50) . "\n";
        echo "Готовность системы: " . ($all_ready ? "✅ ГОТОВА" : "❌ НЕ ГОТОВА") . "\n\n";
        
        return $all_ready;
    }
}

// Запуск тестов если файл выполняется напрямую
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    try {
        $runner = new InventoryDashboardTestRunner();
        
        // Проверяем готовность системы
        if (!$runner->checkSystemReadiness()) {
            echo "❌ Система не готова к тестированию. Исправьте ошибки и попробуйте снова.\n";
            exit(1);
        }
        
        // Запускаем тесты
        $success = $runner->runAllTests();
        exit($success ? 0 : 1);
        
    } catch (Exception $e) {
        echo "❌ Критическая ошибка при запуске тестов: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>