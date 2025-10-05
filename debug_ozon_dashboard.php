<?php
/**
 * Диагностический скрипт для проверки работы Ozon дашборда
 */

echo "🔍 Диагностика Ozon дашборда\n";
echo "===========================\n\n";

// Проверка 1: Подключение к БД
echo "1️⃣ Проверка подключения к базе данных...\n";
try {
    $host = '127.0.0.1';
    $dbname = 'mi_core_db';
    $username = 'mi_core_user';
    $password = 'secure_password_123';
    
    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    echo "✅ Подключение к БД успешно\n\n";
} catch (Exception $e) {
    echo "❌ Ошибка подключения к БД: " . $e->getMessage() . "\n";
    exit(1);
}

// Проверка 2: Структура таблицы
echo "2️⃣ Проверка структуры таблицы ozon_funnel_data...\n";
try {
    $stmt = $pdo->query("DESCRIBE ozon_funnel_data");
    $columns = $stmt->fetchAll();
    
    $hasRevenue = false;
    foreach ($columns as $column) {
        if ($column['Field'] === 'revenue') {
            $hasRevenue = true;
            echo "✅ Поле 'revenue' найдено: " . $column['Type'] . "\n";
            break;
        }
    }
    
    if (!$hasRevenue) {
        echo "❌ Поле 'revenue' отсутствует в таблице!\n";
        echo "Необходимо применить миграцию.\n";
        exit(1);
    }
    
    echo "✅ Структура таблицы корректна\n\n";
} catch (Exception $e) {
    echo "❌ Ошибка проверки структуры таблицы: " . $e->getMessage() . "\n";
    exit(1);
}

// Проверка 3: Данные в таблице
echo "3️⃣ Проверка данных в таблице ozon_funnel_data...\n";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM ozon_funnel_data");
    $result = $stmt->fetch();
    $count = $result['count'];
    
    echo "Количество записей в таблице: $count\n";
    
    if ($count > 0) {
        echo "✅ Данные в таблице есть\n";
        
        // Показываем последние записи
        $stmt = $pdo->query("SELECT * FROM ozon_funnel_data ORDER BY cached_at DESC LIMIT 3");
        $records = $stmt->fetchAll();
        
        echo "\n📊 Последние записи:\n";
        foreach ($records as $record) {
            echo "  - Product ID: " . ($record['product_id'] ?? 'null') . 
                 ", Views: " . $record['views'] . 
                 ", Orders: " . $record['orders'] . 
                 ", Revenue: " . $record['revenue'] . 
                 ", Date: " . $record['cached_at'] . "\n";
        }
    } else {
        echo "⚠️ Таблица пустая - нет данных для отображения\n";
        echo "Возможные причины:\n";
        echo "- API Ozon не возвращает данные\n";
        echo "- Ошибка в обработке данных\n";
        echo "- Данные не сохраняются в БД\n";
    }
    
    echo "\n";
} catch (Exception $e) {
    echo "❌ Ошибка проверки данных: " . $e->getMessage() . "\n";
}

// Проверка 4: API endpoint
echo "4️⃣ Проверка API endpoint...\n";
try {
    // Симулируем запрос к API
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET['action'] = 'funnel-data';
    $_GET['date_from'] = '2024-01-01';
    $_GET['date_to'] = '2024-01-31';
    
    // Перехватываем вывод
    ob_start();
    
    // Подавляем вывод заголовков для тестирования
    $originalHeaders = headers_list();
    
    try {
        include 'src/api/ozon-analytics.php';
    } catch (Exception $e) {
        echo "Ошибка включения API файла: " . $e->getMessage() . "\n";
    }
    
    $apiOutput = ob_get_clean();
    
    echo "📤 Ответ API (первые 500 символов):\n";
    echo substr($apiOutput, 0, 500) . "\n";
    
    // Проверяем, является ли ответ валидным JSON
    $jsonData = json_decode($apiOutput, true);
    if ($jsonData) {
        echo "✅ API возвращает валидный JSON\n";
        echo "Success: " . ($jsonData['success'] ? 'true' : 'false') . "\n";
        
        if (isset($jsonData['data']) && is_array($jsonData['data'])) {
            echo "Количество записей в ответе: " . count($jsonData['data']) . "\n";
            
            if (!empty($jsonData['data'][0])) {
                $firstRecord = $jsonData['data'][0];
                echo "Первая запись содержит поля: " . implode(', ', array_keys($firstRecord)) . "\n";
                
                if (isset($firstRecord['revenue'])) {
                    echo "✅ Поле 'revenue' присутствует в ответе API\n";
                } else {
                    echo "❌ Поле 'revenue' отсутствует в ответе API\n";
                }
            }
        } else {
            echo "⚠️ Поле 'data' пустое или отсутствует\n";
        }
    } else {
        echo "❌ API возвращает некорректный JSON\n";
        echo "Возможно, есть ошибки PHP или проблемы с кодировкой\n";
    }
    
    echo "\n";
} catch (Exception $e) {
    echo "❌ Ошибка тестирования API: " . $e->getMessage() . "\n";
}

// Проверка 5: Файлы классов
echo "5️⃣ Проверка файлов классов...\n";

$filesToCheck = [
    'src/classes/OzonAnalyticsAPI.php',
    'src/api/ozon-analytics.php'
];

foreach ($filesToCheck as $file) {
    if (file_exists($file)) {
        echo "✅ Файл $file существует\n";
        
        if (is_readable($file)) {
            echo "✅ Файл $file доступен для чтения\n";
        } else {
            echo "❌ Файл $file недоступен для чтения\n";
        }
    } else {
        echo "❌ Файл $file не найден\n";
    }
}

echo "\n";

// Проверка 6: Тест обработки данных
echo "6️⃣ Тест обработки данных Ozon API...\n";
try {
    require_once 'src/classes/OzonAnalyticsAPI.php';
    
    $ozonAPI = new OzonAnalyticsAPI('26100', '7e074977-e0db-4ace-ba9e-82903e088b4b', $pdo);
    echo "✅ Класс OzonAnalyticsAPI создан успешно\n";
    
    // Тестируем с мок-данными
    $mockResponse = [
        "data" => [
            [
                "dimensions" => [["id" => "1750881567", "name" => "Тестовый товар"]],
                "metrics" => [1000.50, 5, 100] // [revenue, orders, views]
            ]
        ]
    ];
    
    $reflection = new ReflectionClass($ozonAPI);
    $processMethod = $reflection->getMethod('processFunnelData');
    $processMethod->setAccessible(true);
    
    $result = $processMethod->invoke($ozonAPI, $mockResponse, '2024-01-01', '2024-01-31', []);
    
    if (!empty($result)) {
        echo "✅ Обработка данных работает корректно\n";
        echo "Результат обработки:\n";
        $firstResult = $result[0];
        echo "  - Product ID: " . ($firstResult['product_id'] ?? 'null') . "\n";
        echo "  - Views: " . $firstResult['views'] . "\n";
        echo "  - Orders: " . $firstResult['orders'] . "\n";
        echo "  - Revenue: " . $firstResult['revenue'] . "\n";
    } else {
        echo "❌ Обработка данных не работает\n";
    }
    
} catch (Exception $e) {
    echo "❌ Ошибка тестирования обработки данных: " . $e->getMessage() . "\n";
}

echo "\n";

// Итоговая диагностика
echo "🎯 ИТОГОВАЯ ДИАГНОСТИКА:\n";
echo "========================\n";

if ($count > 0) {
    echo "✅ База данных содержит данные\n";
} else {
    echo "❌ База данных пустая - основная проблема!\n";
    echo "\n🔧 РЕКОМЕНДАЦИИ:\n";
    echo "1. Проверьте, работает ли cron-скрипт обновления данных\n";
    echo "2. Проверьте логи на наличие ошибок API Ozon\n";
    echo "3. Попробуйте вручную запустить обновление данных\n";
    echo "4. Проверьте корректность Client ID и API Key для Ozon\n";
}

echo "\n💡 Следующие шаги:\n";
echo "- Если БД пустая: проверьте процесс загрузки данных\n";
echo "- Если данные есть, но не отображаются: проверьте фронтенд\n";
echo "- Проверьте консоль браузера на наличие JavaScript ошибок\n";
echo "- Убедитесь, что дашборд обращается к правильному API endpoint\n";

?>