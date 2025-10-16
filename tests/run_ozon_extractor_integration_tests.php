<?php
/**
 * Test Runner for OzonExtractor Integration Tests
 * 
 * This script runs the integration tests for the enhanced OzonExtractor
 * with active product filtering functionality.
 */

require_once __DIR__ . '/Integration/OzonExtractorIntegrationTest.php';

echo "🚀 ЗАПУСК INTEGRATION ТЕСТОВ OZONEXTRACTOR\n";
echo "=" . str_repeat("=", 50) . "\n";
echo "Дата: " . date('Y-m-d H:i:s') . "\n";
echo "PHP версия: " . PHP_VERSION . "\n";
echo "=" . str_repeat("=", 50) . "\n\n";

try {
    $test = new OzonExtractorIntegrationTest();
    $success = $test->runAllTests();
    
    if ($success) {
        echo "\n🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
        echo "Enhanced OzonExtractor готов к использованию.\n";
        exit(0);
    } else {
        echo "\n❌ НЕКОТОРЫЕ ТЕСТЫ ПРОВАЛИЛИСЬ!\n";
        echo "Проверьте результаты выше для деталей.\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n💥 КРИТИЧЕСКАЯ ОШИБКА ПРИ ЗАПУСКЕ ТЕСТОВ:\n";
    echo "Ошибка: " . $e->getMessage() . "\n";
    echo "Файл: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}