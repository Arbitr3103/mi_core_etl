<?php
/**
 * Улучшенная версия скрипта синхронизации реальных названий товаров
 * 
 * Исправления:
 * - Исправлены SQL запросы без ошибок DISTINCT + ORDER BY
 * - Добавлена обработка ошибок и retry логика
 * - Интеграция с product_cross_reference таблицей
 * - Использование SafeSyncEngine для надежной синхронизации
 * - Прогресс-бар и детальная отчетность
 * - Возможность продолжения с места остановки
 * 
 * Requirements: 2.1, 3.1, 3.2, 3.3, 3.4, 8.1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/SafeSyncEngine.php';
require_once __DIR__ . '/src/FallbackDataProvider.php';
require_once __DIR__ . '/src/DataTypeNormalizer.php';
require_once __DIR__ . '/src/SyncErrorHandler.php';
require_once __DIR__ . '/src/CrossReferenceManager.php';

// Настройки скрипта
$options = getopt('', ['limit:', 'batch-size:', 'verbose', 'help']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

$limit = isset($options['limit']) ? (int)$options['limit'] : 50;
$batchSize = isset($options['batch-size']) ? (int)$options['batch-size'] : 10;
$verbose = isset($options['verbose']);

echo "🔄 СИНХРОНИЗАЦИЯ РЕАЛЬНЫХ НАЗВАНИЙ ТОВАРОВ (v2.0)\n";
echo "================================================\n\n";

try {
    // Создаем подключение к БД
    $pdo = createDatabaseConnection();
    echo "✅ Подключение к БД успешно\n\n";
    
    // Проверяем наличие необходимых таблиц
    echo "🔍 Проверка структуры базы данных...\n";
    checkDatabaseStructure($pdo);
    echo "✅ Структура БД корректна\n\n";
    
    // Создаем экземпляры компонентов
    $logger = new SimpleLogger(LOG_DIR . '/sync_' . date('Y-m-d_H-i-s') . '.log', $verbose ? 'DEBUG' : 'INFO');
    $syncEngine = new SafeSyncEngine($pdo, $logger);
    $syncEngine->setBatchSize($batchSize);
    
    $errorHandler = new SyncErrorHandler($pdo, $logger);
    $crossRefManager = new CrossReferenceManager($pdo, $logger);
    
    echo "📊 Статистика перед синхронизацией:\n";
    displayStatistics($syncEngine);
    displayCrossRefStatistics($crossRefManager);
    echo "\n";
    
    // Создаем записи для новых товаров
    echo "🔄 Создание записей для новых товаров...\n";
    $newEntries = $crossRefManager->createEntriesForNewProducts($limit);
    if ($newEntries > 0) {
        echo "   ✅ Создано новых записей: {$newEntries}\n\n";
    } else {
        echo "   ℹ️  Новых товаров не найдено\n\n";
    }
    
    // Запускаем синхронизацию с прогресс-баром
    echo "🚀 Начинаем синхронизацию (лимит: {$limit} товаров)...\n\n";
    
    $startTime = microtime(true);
    $results = syncWithProgress($syncEngine, $limit, $verbose);
    $endTime = microtime(true);
    
    $duration = round($endTime - $startTime, 2);
    
    // Выводим результаты
    echo "\n";
    echo "✅ СИНХРОНИЗАЦИЯ ЗАВЕРШЕНА!\n";
    echo "==========================\n\n";
    
    echo "📈 Результаты:\n";
    echo "   Всего обработано: {$results['total']}\n";
    echo "   Успешно: {$results['success']}\n";
    echo "   Ошибки: {$results['failed']}\n";
    echo "   Пропущено: {$results['skipped']}\n";
    echo "   Время выполнения: {$duration} сек\n\n";
    
    if (!empty($results['errors'])) {
        echo "⚠️  Ошибки при обработке:\n";
        foreach (array_slice($results['errors'], 0, 5) as $error) {
            if (is_array($error)) {
                echo "   - Товар {$error['product_id']}: {$error['error']}\n";
            } else {
                echo "   - {$error}\n";
            }
        }
        if (count($results['errors']) > 5) {
            echo "   ... и еще " . (count($results['errors']) - 5) . " ошибок\n";
        }
        echo "\n";
    }
    
    // Статистика после синхронизации
    echo "📊 Статистика после синхронизации:\n";
    displayStatistics($syncEngine);
    echo "\n";
    
    // Показываем примеры обновленных товаров
    echo "📋 Примеры обновленных товаров:\n";
    displaySampleProducts($pdo);
    echo "\n";
    
    echo "🎉 Готово! Реальные названия товаров обновлены.\n\n";
    
    echo "📋 Следующие шаги:\n";
    echo "1. Проверьте дашборд - должны появиться реальные названия\n";
    echo "2. Для полной синхронизации запустите: php sync-real-product-names-v2.php --limit=1000\n";
    echo "3. Настройте автоматический запуск через cron (рекомендуется раз в день)\n";
    echo "4. Логи сохранены в: " . LOG_DIR . "/sync_" . date('Y-m-d_H-i-s') . ".log\n\n";
    
} catch (Exception $e) {
    echo "\n❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    echo "Трассировка:\n" . $e->getTraceAsString() . "\n\n";
    exit(1);
}

/**
 * Создает подключение к базе данных
 */
function createDatabaseConnection() {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        return new PDO($dsn, DB_USER, DB_PASSWORD, $options);
    } catch (PDOException $e) {
        throw new Exception('Не удалось подключиться к БД: ' . $e->getMessage());
    }
}

/**
 * Проверяет наличие необходимых таблиц
 */
function checkDatabaseStructure($pdo) {
    $requiredTables = [
        'product_cross_reference',
        'dim_products',
        'inventory_data'
    ];
    
    foreach ($requiredTables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() === 0) {
            throw new Exception("Таблица {$table} не найдена. Запустите миграции.");
        }
    }
}

/**
 * Синхронизация с прогресс-баром
 */
function syncWithProgress($syncEngine, $limit, $verbose) {
    // Получаем товары для синхронизации
    $reflection = new ReflectionClass($syncEngine);
    $method = $reflection->getMethod('findProductsNeedingSync');
    $method->setAccessible(true);
    $products = $method->invoke($syncEngine, $limit);
    
    $total = count($products);
    if ($total === 0) {
        echo "   ℹ️  Нет товаров для синхронизации\n";
        return [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => []
        ];
    }
    
    $results = [
        'total' => $total,
        'success' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => []
    ];
    
    echo "   Найдено товаров: {$total}\n\n";
    
    // Обрабатываем товары с прогресс-баром
    $processed = 0;
    foreach ($products as $product) {
        $processed++;
        
        // Показываем прогресс
        if (!$verbose) {
            showProgress($processed, $total);
        } else {
            echo "   [{$processed}/{$total}] Обработка товара {$product['inventory_product_id']}...\n";
        }
        
        // Обрабатываем товар
        try {
            $method = $reflection->getMethod('processProduct');
            $method->setAccessible(true);
            $result = $method->invoke($syncEngine, $product);
            
            if ($result['status'] === 'success') {
                $results['success']++;
            } elseif ($result['status'] === 'skipped') {
                $results['skipped']++;
            } else {
                $results['failed']++;
                if (isset($result['error'])) {
                    $results['errors'][] = [
                        'product_id' => $product['inventory_product_id'],
                        'error' => $result['error']
                    ];
                }
            }
        } catch (Exception $e) {
            $results['failed']++;
            $results['errors'][] = [
                'product_id' => $product['inventory_product_id'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    if (!$verbose) {
        echo "\n";
    }
    
    return $results;
}

/**
 * Показывает прогресс-бар
 */
function showProgress($current, $total) {
    $percent = round(($current / $total) * 100);
    $barLength = 50;
    $filledLength = round(($percent / 100) * $barLength);
    
    $bar = str_repeat('█', $filledLength) . str_repeat('░', $barLength - $filledLength);
    
    echo "\r   Прогресс: [{$bar}] {$percent}% ({$current}/{$total})";
    
    if ($current === $total) {
        echo "\n";
    }
}

/**
 * Отображает статистику синхронизации
 */
function displayStatistics($syncEngine) {
    $stats = $syncEngine->getSyncStatistics();
    
    if (empty($stats)) {
        echo "   Статистика недоступна\n";
        return;
    }
    
    echo "   Всего товаров: {$stats['total_products']}\n";
    echo "   Синхронизировано: {$stats['synced']}\n";
    echo "   Ожидает синхронизации: {$stats['pending']}\n";
    echo "   Ошибки синхронизации: {$stats['failed']}\n";
    echo "   Процент синхронизации: {$stats['sync_percentage']}%\n";
    
    if ($stats['last_sync_time']) {
        echo "   Последняя синхронизация: {$stats['last_sync_time']}\n";
    }
}

/**
 * Отображает статистику cross_reference
 */
function displayCrossRefStatistics($crossRefManager) {
    $stats = $crossRefManager->getStatistics();
    
    if (empty($stats)) {
        return;
    }
    
    echo "   С реальными названиями: {$stats['with_real_names']}\n";
}

/**
 * Отображает примеры обновленных товаров
 */
function displaySampleProducts($pdo) {
    try {
        // ИСПРАВЛЕННЫЙ SQL ЗАПРОС - без проблем с DISTINCT и ORDER BY
        $sql = "
            SELECT 
                pcr.inventory_product_id,
                pcr.cached_name,
                i.quantity_present
            FROM product_cross_reference pcr
            JOIN inventory_data i ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            WHERE pcr.cached_name IS NOT NULL
              AND pcr.cached_name NOT LIKE 'Товар%ID%'
              AND i.quantity_present > 0
              AND pcr.sync_status = 'synced'
            ORDER BY i.quantity_present DESC
            LIMIT 10
        ";
        
        $stmt = $pdo->query($sql);
        $products = $stmt->fetchAll();
        
        if (empty($products)) {
            echo "   Нет обновленных товаров для отображения\n";
            return;
        }
        
        foreach ($products as $product) {
            $name = mb_substr($product['cached_name'], 0, 60);
            echo "   📦 ID {$product['inventory_product_id']}: {$name}... (остаток: {$product['quantity_present']})\n";
        }
        
    } catch (Exception $e) {
        echo "   ⚠️  Не удалось получить примеры: " . $e->getMessage() . "\n";
    }
}

/**
 * Показывает справку по использованию скрипта
 */
function showHelp() {
    echo "Использование: php sync-real-product-names-v2.php [опции]\n\n";
    echo "Опции:\n";
    echo "  --limit=N          Максимальное количество товаров для синхронизации (по умолчанию: 50)\n";
    echo "  --batch-size=N     Размер пакета для обработки (по умолчанию: 10)\n";
    echo "  --verbose          Подробный вывод (включает DEBUG логирование)\n";
    echo "  --help             Показать эту справку\n\n";
    echo "Примеры:\n";
    echo "  php sync-real-product-names-v2.php\n";
    echo "  php sync-real-product-names-v2.php --limit=100 --batch-size=20\n";
    echo "  php sync-real-product-names-v2.php --limit=1000 --verbose\n\n";
}
