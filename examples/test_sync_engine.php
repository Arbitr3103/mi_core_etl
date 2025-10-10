<?php
/**
 * Пример использования SafeSyncEngine
 * 
 * Демонстрирует основные возможности движка синхронизации
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/SafeSyncEngine.php';
require_once __DIR__ . '/../src/FallbackDataProvider.php';
require_once __DIR__ . '/../src/DataTypeNormalizer.php';

echo "=== Тест SafeSyncEngine ===\n\n";

try {
    // Создаем экземпляр движка синхронизации
    echo "1. Инициализация SafeSyncEngine...\n";
    $syncEngine = new SafeSyncEngine();
    echo "✅ SafeSyncEngine инициализирован\n\n";
    
    // Получаем статистику синхронизации
    echo "2. Получение статистики синхронизации...\n";
    $stats = $syncEngine->getSyncStatistics();
    
    if (!empty($stats)) {
        echo "📊 Статистика:\n";
        echo "   Всего товаров: {$stats['total_products']}\n";
        echo "   Синхронизировано: {$stats['synced']}\n";
        echo "   Ожидает синхронизации: {$stats['pending']}\n";
        echo "   Ошибки: {$stats['failed']}\n";
        echo "   Процент синхронизации: {$stats['sync_percentage']}%\n";
        echo "   Последняя синхронизация: {$stats['last_sync_time']}\n";
    } else {
        echo "⚠️  Не удалось получить статистику (возможно, таблица не создана)\n";
    }
    echo "\n";
    
    // Тестируем DataTypeNormalizer
    echo "3. Тестирование DataTypeNormalizer...\n";
    $normalizer = new DataTypeNormalizer();
    
    $testProduct = [
        'inventory_product_id' => 123456,  // INT
        'ozon_product_id' => '789012',     // STRING
        'name' => '  Тестовый товар  ',    // STRING с пробелами
        'quantity' => '100',               // STRING число
        'price' => '1,234.56'              // STRING с запятой
    ];
    
    echo "   Исходные данные:\n";
    print_r($testProduct);
    
    $normalized = $normalizer->normalizeProduct($testProduct);
    
    echo "\n   Нормализованные данные:\n";
    print_r($normalized);
    
    // Проверяем валидацию
    $validation = $normalizer->validateNormalizedData($normalized);
    echo "\n   Валидация: " . ($validation['valid'] ? '✅ Пройдена' : '❌ Не пройдена') . "\n";
    if (!$validation['valid']) {
        echo "   Ошибки:\n";
        foreach ($validation['errors'] as $error) {
            echo "   - {$error}\n";
        }
    }
    echo "\n";
    
    // Тестируем сравнение ID
    echo "4. Тестирование сравнения ID...\n";
    $id1 = 123456;
    $id2 = '123456';
    $id3 = '789012';
    
    echo "   Сравнение {$id1} и {$id2}: " . 
         ($normalizer->compareIds($id1, $id2) ? '✅ Равны' : '❌ Не равны') . "\n";
    echo "   Сравнение {$id1} и {$id3}: " . 
         ($normalizer->compareIds($id1, $id3) ? '✅ Равны' : '❌ Не равны') . "\n";
    echo "\n";
    
    // Тестируем FallbackDataProvider
    echo "5. Тестирование FallbackDataProvider...\n";
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER,
        DB_PASSWORD,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $fallbackProvider = new FallbackDataProvider($pdo, new SimpleLogger());
    
    // Получаем статистику кэша
    $cacheStats = $fallbackProvider->getCacheStatistics();
    
    if (!empty($cacheStats)) {
        echo "📊 Статистика кэша:\n";
        echo "   Всего записей: {$cacheStats['total_entries']}\n";
        echo "   Кэшированных названий: {$cacheStats['cached_names']}\n";
        echo "   Заглушек: {$cacheStats['placeholder_names']}\n";
        echo "   Реальных названий: {$cacheStats['real_names']}\n";
        echo "   Средний возраст кэша: {$cacheStats['avg_cache_age_hours']} часов\n";
        echo "   Hit rate: {$cacheStats['cache_hit_rate']}%\n";
    } else {
        echo "⚠️  Не удалось получить статистику кэша\n";
    }
    echo "\n";
    
    // Настройка параметров
    echo "6. Настройка параметров синхронизации...\n";
    $syncEngine->setBatchSize(20);
    $syncEngine->setMaxRetries(5);
    echo "✅ Размер пакета: 20\n";
    echo "✅ Максимум попыток: 5\n";
    echo "\n";
    
    // Запуск синхронизации (с ограничением)
    echo "7. Запуск тестовой синхронизации (лимит: 5 товаров)...\n";
    echo "⚠️  Для запуска реальной синхронизации раскомментируйте следующую строку:\n";
    echo "// \$results = \$syncEngine->syncProductNames(5);\n";
    echo "\n";
    
    /*
    $results = $syncEngine->syncProductNames(5);
    
    echo "📊 Результаты синхронизации:\n";
    echo "   Всего обработано: {$results['total']}\n";
    echo "   Успешно: {$results['success']}\n";
    echo "   Ошибки: {$results['failed']}\n";
    echo "   Пропущено: {$results['skipped']}\n";
    
    if (!empty($results['errors'])) {
        echo "\n   ❌ Ошибки:\n";
        foreach ($results['errors'] as $error) {
            echo "   - " . json_encode($error, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    */
    
    echo "\n✅ Все тесты завершены успешно!\n";
    
} catch (Exception $e) {
    echo "\n❌ ОШИБКА: " . $e->getMessage() . "\n";
    echo "Трассировка:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== Конец теста ===\n";
