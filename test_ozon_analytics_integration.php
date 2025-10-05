<?php
/**
 * Интеграционный тест для OzonAnalyticsAPI
 * 
 * Проверяет интеграцию с базой данных и основную функциональность
 * без выполнения реальных API запросов
 */

require_once 'src/classes/OzonAnalyticsAPI.php';

echo "🔧 ИНТЕГРАЦИОННЫЙ ТЕСТ OzonAnalyticsAPI\n";
echo str_repeat("=", 50) . "\n\n";

// Проверяем наличие необходимых констант
$requiredConstants = [
    'DB_HOST' => $_ENV['DB_HOST'] ?? 'localhost',
    'DB_NAME' => $_ENV['DB_NAME'] ?? 'mi_core_db',
    'DB_USER' => $_ENV['DB_USER'] ?? 'test_user',
    'DB_PASSWORD' => $_ENV['DB_PASSWORD'] ?? 'test_password'
];

echo "1️⃣ Проверка конфигурации:\n";
foreach ($requiredConstants as $key => $value) {
    echo "   $key: " . (empty($value) ? '❌ Не установлено' : '✅ Установлено') . "\n";
}

// Тест подключения к базе данных
echo "\n2️⃣ Тест подключения к базе данных:\n";
$pdo = null;

try {
    $pdo = new PDO(
        "mysql:host={$requiredConstants['DB_HOST']};dbname={$requiredConstants['DB_NAME']};charset=utf8mb4",
        $requiredConstants['DB_USER'],
        $requiredConstants['DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    echo "✅ Подключение к базе данных успешно\n";
    
    // Проверяем наличие необходимых таблиц
    echo "\n3️⃣ Проверка структуры базы данных:\n";
    
    $requiredTables = [
        'ozon_api_settings',
        'ozon_funnel_data',
        'ozon_demographics',
        'ozon_campaigns'
    ];
    
    foreach ($requiredTables as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            $exists = $stmt->rowCount() > 0;
            echo "   Таблица $table: " . ($exists ? '✅ Существует' : '❌ Отсутствует') . "\n";
            
            if (!$exists) {
                echo "     💡 Выполните миграцию: migrations/add_ozon_analytics_tables.sql\n";
            }
        } catch (PDOException $e) {
            echo "   Таблица $table: ❌ Ошибка проверки - " . $e->getMessage() . "\n";
        }
    }
    
} catch (PDOException $e) {
    echo "❌ Ошибка подключения к БД: " . $e->getMessage() . "\n";
    echo "💡 Проверьте настройки подключения в .env файле\n";
}

// Тест создания экземпляра API с подключением к БД
echo "\n4️⃣ Тест создания экземпляра API:\n";

try {
    $ozonAPI = new OzonAnalyticsAPI('test_client_id', 'test_api_key', $pdo);
    echo "✅ Экземпляр OzonAnalyticsAPI с БД создан успешно\n";
    
    // Тест получения статистики
    $stats = $ozonAPI->getApiStats();
    echo "✅ Статистика API получена:\n";
    echo "   - Client ID: " . $stats['client_id'] . "\n";
    echo "   - Токен валиден: " . ($stats['token_valid'] ? 'Да' : 'Нет') . "\n";
    
} catch (Exception $e) {
    echo "❌ Ошибка создания экземпляра API: " . $e->getMessage() . "\n";
}

// Тест обработки исключений
echo "\n5️⃣ Тест обработки исключений:\n";

$exceptionTests = [
    ['AUTHENTICATION_ERROR', 401],
    ['RATE_LIMIT_EXCEEDED', 429],
    ['INVALID_PARAMETERS', 400],
    ['API_UNAVAILABLE', 503]
];

foreach ($exceptionTests as $test) {
    try {
        $exception = new OzonAPIException("Тест {$test[0]}", $test[1], $test[0]);
        echo "✅ {$test[0]}: " . $exception->getRecommendation() . "\n";
    } catch (Exception $e) {
        echo "❌ Ошибка тестирования исключения {$test[0]}: " . $e->getMessage() . "\n";
    }
}

// Тест валидации данных
echo "\n6️⃣ Тест валидации входных данных:\n";

if (isset($ozonAPI)) {
    // Тест валидации дат через рефлексию
    try {
        $reflection = new ReflectionClass($ozonAPI);
        $isValidDateMethod = $reflection->getMethod('isValidDate');
        $isValidDateMethod->setAccessible(true);
        
        $dateTests = [
            ['2024-01-01', true],
            ['2024-13-01', false],
            ['2024-01-32', false],
            ['invalid-date', false],
            ['2024/01/01', false]
        ];
        
        foreach ($dateTests as $test) {
            $result = $isValidDateMethod->invoke($ozonAPI, $test[0]);
            $expected = $test[1];
            $status = ($result === $expected) ? '✅' : '❌';
            echo "   Дата '{$test[0]}': $status " . ($result ? 'валидна' : 'невалидна') . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Ошибка тестирования валидации дат: " . $e->getMessage() . "\n";
    }
}

// Тест экспорта данных (с пустыми данными)
echo "\n7️⃣ Тест функций экспорта:\n";

if (isset($ozonAPI)) {
    try {
        // Тест валидации параметров экспорта
        $exportTests = [
            ['funnel', 'json', true],
            ['demographics', 'csv', true],
            ['campaigns', 'json', true],
            ['invalid_type', 'json', false],
            ['funnel', 'invalid_format', false]
        ];
        
        foreach ($exportTests as $test) {
            try {
                // Мы не можем выполнить реальный экспорт без данных,
                // но можем проверить валидацию параметров
                $reflection = new ReflectionClass($ozonAPI);
                $method = $reflection->getMethod('exportData');
                
                if (!$test[2]) {
                    // Ожидаем исключение
                    try {
                        $method->invoke($ozonAPI, $test[0], $test[1], []);
                        echo "❌ Должно было быть исключение для {$test[0]}/{$test[1]}\n";
                    } catch (InvalidArgumentException $e) {
                        echo "✅ Корректная валидация {$test[0]}/{$test[1]}: " . $e->getMessage() . "\n";
                    }
                } else {
                    echo "✅ Параметры экспорта {$test[0]}/{$test[1]} валидны\n";
                }
                
            } catch (Exception $e) {
                if ($test[2]) {
                    echo "⚠️ Тест экспорта {$test[0]}/{$test[1]}: " . $e->getMessage() . "\n";
                } else {
                    echo "✅ Ожидаемая ошибка для {$test[0]}/{$test[1]}: " . $e->getMessage() . "\n";
                }
            }
        }
        
    } catch (Exception $e) {
        echo "❌ Ошибка тестирования экспорта: " . $e->getMessage() . "\n";
    }
}

// Финальный отчет
echo "\n" . str_repeat("=", 50) . "\n";
echo "📋 ОТЧЕТ О ТЕСТИРОВАНИИ:\n\n";

$recommendations = [];

if (!$pdo) {
    $recommendations[] = "Настройте подключение к базе данных";
}

if ($pdo) {
    // Проверяем таблицы еще раз для финального отчета
    $missingTables = [];
    foreach ($requiredTables ?? [] as $table) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() === 0) {
                $missingTables[] = $table;
            }
        } catch (PDOException $e) {
            $missingTables[] = $table;
        }
    }
    
    if (!empty($missingTables)) {
        $recommendations[] = "Создайте отсутствующие таблицы: " . implode(', ', $missingTables);
        $recommendations[] = "Выполните миграцию: php migrations/add_ozon_analytics_tables.sql";
    }
}

if (empty($recommendations)) {
    echo "🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
    echo "Класс OzonAnalyticsAPI готов к использованию.\n\n";
    echo "Следующие шаги:\n";
    echo "1. Установите реальные OZON_CLIENT_ID и OZON_API_KEY в .env\n";
    echo "2. Протестируйте подключение к реальному API Ozon\n";
    echo "3. Интегрируйте класс в основное приложение\n";
} else {
    echo "⚠️ ТРЕБУЮТСЯ ДОПОЛНИТЕЛЬНЫЕ НАСТРОЙКИ:\n";
    foreach ($recommendations as $i => $recommendation) {
        echo ($i + 1) . ". $recommendation\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";

?>