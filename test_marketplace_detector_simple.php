<?php
/**
 * Simple Test Runner for MarketplaceDetector
 * 
 * Runs MarketplaceDetector tests independently to verify functionality
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once __DIR__ . '/tests/MarketplaceDetectorTest.php';

echo "🚀 ЗАПУСК ТЕСТОВ MarketplaceDetector\n";
echo "=" . str_repeat("=", 80) . "\n";
echo "Дата: " . date('Y-m-d H:i:s') . "\n";
echo "=" . str_repeat("=", 80) . "\n\n";

try {
    $test = new MarketplaceDetectorTest();
    $test->runAllTests();
    
    echo "\n✅ Тестирование завершено успешно!\n";
    
} catch (Exception $e) {
    echo "\n❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    echo "Стек вызовов:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
?>