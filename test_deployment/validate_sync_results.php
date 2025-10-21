<?php
/**
 * Валидация результатов синхронизации
 * 
 * Проверяет корректность обновления данных после синхронизации
 * 
 * Requirements: 3.1, 3.2, 3.3
 */

require_once __DIR__ . '/config.php';

echo "🔍 ВАЛИДАЦИЯ РЕЗУЛЬТАТОВ СИНХРОНИЗАЦИИ\n";
echo "======================================\n\n";

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✅ Подключение к БД успешно\n\n";
    
    $validationResults = [
        'passed' => 0,
        'failed' => 0,
        'warnings' => 0,
        'details' => []
    ];
    
    // Тест 1: Проверка наличия таблицы product_cross_reference
    echo "📋 Тест 1: Проверка структуры базы данных\n";
    $test1 = validateDatabaseStructure($pdo);
    recordTestResult($validationResults, 'Структура БД', $test1);
    
    // Тест 2: Проверка данных в cross_reference
    echo "\n📋 Тест 2: Проверка данных в product_cross_reference\n";
    $test2 = validateCrossReferenceData($pdo);
    recordTestResult($validationResults, 'Данные cross_reference', $test2);
    
    // Тест 3: Проверка связи с dim_products
    echo "\n📋 Тест 3: Проверка связи с dim_products\n";
    $test3 = validateDimProductsLink($pdo);
    recordTestResult($validationResults, 'Связь с dim_products', $test3);
    
    // Тест 4: Проверка качества названий
    echo "\n📋 Тест 4: Проверка качества названий товаров\n";
    $test4 = validateProductNames($pdo);
    recordTestResult($validationResults, 'Качество названий', $test4);
    
    // Тест 5: Проверка SQL запросов
    echo "\n📋 Тест 5: Проверка исправленных SQL запросов\n";
    $test5 = validateSQLQueries($pdo);
    recordTestResult($validationResults, 'SQL запросы', $test5);
    
    // Итоговый отчет
    echo "\n";
    echo "=" . str_repeat("=", 50) . "\n";
    echo "📊 ИТОГОВЫЙ ОТЧЕТ\n";
    echo "=" . str_repeat("=", 50) . "\n\n";
    
    echo "✅ Пройдено тестов: {$validationResults['passed']}\n";
    echo "❌ Провалено тестов: {$validationResults['failed']}\n";
    echo "⚠️  Предупреждений: {$validationResults['warnings']}\n\n";
    
    if ($validationResults['failed'] === 0) {
        echo "🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
        echo "Синхронизация работает корректно.\n\n";
        exit(0);
    } else {
        echo "⚠️  ОБНАРУЖЕНЫ ПРОБЛЕМЫ\n";
        echo "Требуется дополнительная проверка.\n\n";
        
        echo "Детали проблем:\n";
        foreach ($validationResults['details'] as $detail) {
            if ($detail['status'] === 'failed') {
                echo "  ❌ {$detail['test']}: {$detail['message']}\n";
            }
        }
        echo "\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n❌ ОШИБКА: " . $e->getMessage() . "\n\n";
    exit(1);
}

/**
 * Проверяет структуру базы данных
 */
function validateDatabaseStructure($pdo) {
    $requiredTables = [
        'product_cross_reference' => [
            'inventory_product_id',
            'ozon_product_id',
            'analytics_product_id',
            'sku_ozon',
            'cached_name',
            'cached_brand',
            'sync_status',
            'last_api_sync'
        ],
        'dim_products' => [
            'sku_ozon',
            'name',
            'cross_ref_id'
        ]
    ];
    
    foreach ($requiredTables as $table => $columns) {
        // Проверяем наличие таблицы
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        if ($stmt->rowCount() === 0) {
            return [
                'status' => 'failed',
                'message' => "Таблица {$table} не найдена"
            ];
        }
        
        echo "   ✅ Таблица {$table} существует\n";
        
        // Проверяем наличие колонок
        $stmt = $pdo->query("DESCRIBE {$table}");
        $existingColumns = array_column($stmt->fetchAll(), 'Field');
        
        foreach ($columns as $column) {
            if (!in_array($column, $existingColumns)) {
                return [
                    'status' => 'failed',
                    'message' => "Колонка {$column} не найдена в таблице {$table}"
                ];
            }
        }
        
        echo "   ✅ Все необходимые колонки присутствуют\n";
    }
    
    return [
        'status' => 'passed',
        'message' => 'Структура БД корректна'
    ];
}

/**
 * Проверяет данные в product_cross_reference
 */
function validateCrossReferenceData($pdo) {
    $sql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced,
            SUM(CASE WHEN cached_name IS NOT NULL THEN 1 ELSE 0 END) as with_names
        FROM product_cross_reference
    ";
    
    $stmt = $pdo->query($sql);
    $stats = $stmt->fetch();
    
    echo "   Всего записей: {$stats['total']}\n";
    echo "   Синхронизировано: {$stats['synced']}\n";
    echo "   С названиями: {$stats['with_names']}\n";
    
    if ($stats['total'] === 0) {
        return [
            'status' => 'warning',
            'message' => 'Таблица product_cross_reference пуста'
        ];
    }
    
    return [
        'status' => 'passed',
        'message' => "Найдено {$stats['total']} записей"
    ];
}

/**
 * Проверяет связь с dim_products
 */
function validateDimProductsLink($pdo) {
    $sql = "
        SELECT 
            COUNT(DISTINCT dp.sku_ozon) as total_products,
            COUNT(DISTINCT pcr.id) as linked_products
        FROM dim_products dp
        LEFT JOIN product_cross_reference pcr ON dp.sku_ozon = pcr.sku_ozon
        WHERE dp.sku_ozon IS NOT NULL
    ";
    
    $stmt = $pdo->query($sql);
    $stats = $stmt->fetch();
    
    echo "   Товаров в dim_products: {$stats['total_products']}\n";
    echo "   Связано с cross_reference: {$stats['linked_products']}\n";
    
    $linkPercentage = $stats['total_products'] > 0 
        ? round(($stats['linked_products'] / $stats['total_products']) * 100, 2)
        : 0;
    
    echo "   Процент связанных: {$linkPercentage}%\n";
    
    if ($linkPercentage < 50) {
        return [
            'status' => 'warning',
            'message' => "Только {$linkPercentage}% товаров связаны с cross_reference"
        ];
    }
    
    return [
        'status' => 'passed',
        'message' => "Связь установлена для {$linkPercentage}% товаров"
    ];
}

/**
 * Проверяет качество названий товаров
 */
function validateProductNames($pdo) {
    $sql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN cached_name NOT LIKE 'Товар%ID%' AND cached_name IS NOT NULL THEN 1 ELSE 0 END) as real_names,
            SUM(CASE WHEN cached_name LIKE 'Товар%ID%' THEN 1 ELSE 0 END) as placeholder_names
        FROM product_cross_reference
    ";
    
    $stmt = $pdo->query($sql);
    $stats = $stmt->fetch();
    
    echo "   Всего товаров: {$stats['total']}\n";
    echo "   С реальными названиями: {$stats['real_names']}\n";
    echo "   С заглушками: {$stats['placeholder_names']}\n";
    
    $realNamesPercentage = $stats['total'] > 0 
        ? round(($stats['real_names'] / $stats['total']) * 100, 2)
        : 0;
    
    echo "   Процент реальных названий: {$realNamesPercentage}%\n";
    
    if ($realNamesPercentage < 30) {
        return [
            'status' => 'warning',
            'message' => "Только {$realNamesPercentage}% товаров имеют реальные названия"
        ];
    }
    
    return [
        'status' => 'passed',
        'message' => "{$realNamesPercentage}% товаров имеют реальные названия"
    ];
}

/**
 * Проверяет исправленные SQL запросы
 */
function validateSQLQueries($pdo) {
    // Тест 1: Запрос с DISTINCT без ORDER BY проблем
    try {
        $sql = "
            SELECT DISTINCT 
                pcr.inventory_product_id,
                pcr.cached_name
            FROM product_cross_reference pcr
            WHERE pcr.cached_name IS NOT NULL
            LIMIT 5
        ";
        
        $stmt = $pdo->query($sql);
        $stmt->fetchAll();
        
        echo "   ✅ Запрос с DISTINCT работает корректно\n";
    } catch (Exception $e) {
        return [
            'status' => 'failed',
            'message' => 'Ошибка в запросе с DISTINCT: ' . $e->getMessage()
        ];
    }
    
    // Тест 2: JOIN с правильным приведением типов
    try {
        $sql = "
            SELECT 
                i.product_id,
                pcr.cached_name
            FROM inventory_data i
            JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
            WHERE i.product_id != 0
            LIMIT 5
        ";
        
        $stmt = $pdo->query($sql);
        $stmt->fetchAll();
        
        echo "   ✅ JOIN с приведением типов работает корректно\n";
    } catch (Exception $e) {
        return [
            'status' => 'failed',
            'message' => 'Ошибка в JOIN запросе: ' . $e->getMessage()
        ];
    }
    
    // Тест 3: Подзапрос для сложной логики
    try {
        $sql = "
            SELECT product_id, product_name
            FROM (
                SELECT 
                    i.product_id,
                    pcr.cached_name as product_name,
                    i.quantity_present
                FROM inventory_data i
                JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
                WHERE i.product_id != 0
                ORDER BY i.quantity_present DESC
            ) ranked_products
            LIMIT 5
        ";
        
        $stmt = $pdo->query($sql);
        $stmt->fetchAll();
        
        echo "   ✅ Подзапрос с ORDER BY работает корректно\n";
    } catch (Exception $e) {
        return [
            'status' => 'failed',
            'message' => 'Ошибка в подзапросе: ' . $e->getMessage()
        ];
    }
    
    return [
        'status' => 'passed',
        'message' => 'Все SQL запросы работают корректно'
    ];
}

/**
 * Записывает результат теста
 */
function recordTestResult(&$results, $testName, $testResult) {
    $results['details'][] = [
        'test' => $testName,
        'status' => $testResult['status'],
        'message' => $testResult['message']
    ];
    
    if ($testResult['status'] === 'passed') {
        $results['passed']++;
        echo "   ✅ {$testResult['message']}\n";
    } elseif ($testResult['status'] === 'failed') {
        $results['failed']++;
        echo "   ❌ {$testResult['message']}\n";
    } else {
        $results['warnings']++;
        echo "   ⚠️  {$testResult['message']}\n";
    }
}
