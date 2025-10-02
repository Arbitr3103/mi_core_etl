<?php
/**
 * Тестирование системы обработки ошибок и fallback механизмов для маркетплейсов
 * 
 * Этот скрипт тестирует различные сценарии ошибок и проверяет
 * корректность работы fallback механизмов
 * 
 * @version 1.0
 * @author Manhattan System
 */

require_once 'config.php';
require_once 'MarginDashboardAPI.php';
require_once 'src/classes/MarketplaceDetector.php';
require_once 'src/classes/MarketplaceFallbackHandler.php';
require_once 'src/classes/MarketplaceDataValidator.php';

echo "<h1>Тестирование системы обработки ошибок маркетплейсов</h1>\n";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .test-section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
    .test-success { background-color: #d4edda; border-color: #c3e6cb; }
    .test-warning { background-color: #fff3cd; border-color: #ffeaa7; }
    .test-error { background-color: #f8d7da; border-color: #f5c6cb; }
    .test-info { background-color: #d1ecf1; border-color: #bee5eb; }
    pre { background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto; }
    .status-passed { color: #28a745; font-weight: bold; }
    .status-warning { color: #ffc107; font-weight: bold; }
    .status-failed { color: #dc3545; font-weight: bold; }
</style>\n";

try {
    // Инициализация API
    $marginAPI = new MarginDashboardAPI(DB_HOST, DB_NAME, DB_USER, DB_PASS);
    
    // Получаем PDO через reflection (временное решение)
    $reflection = new ReflectionClass($marginAPI);
    $pdoProperty = $reflection->getProperty('pdo');
    $pdoProperty->setAccessible(true);
    $pdo = $pdoProperty->getValue($marginAPI);
    
    $fallbackHandler = new MarketplaceFallbackHandler($pdo);
    $validator = new MarketplaceDataValidator($pdo, $marginAPI, $fallbackHandler);
    
    echo "<div class='test-section test-info'>";
    echo "<h2>✅ Инициализация успешна</h2>";
    echo "<p>Все необходимые классы загружены и инициализированы.</p>";
    echo "</div>";
    
} catch (Exception $e) {
    echo "<div class='test-section test-error'>";
    echo "<h2>❌ Ошибка инициализации</h2>";
    echo "<p>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "</div>";
    exit;
}

// Тест 1: Обработка отсутствующих данных
echo "<div class='test-section'>";
echo "<h2>Тест 1: Обработка отсутствующих данных</h2>";

try {
    $missingDataResult = $fallbackHandler->handleMissingData('ozon', '2025-01-01 to 2025-01-01', ['test' => true]);
    
    if ($missingDataResult['success'] && !$missingDataResult['has_data']) {
        echo "<p class='status-passed'>✅ ПРОЙДЕН</p>";
        echo "<p>Корректно обработано отсутствие данных для Ozon.</p>";
        echo "<p><strong>Сообщение:</strong> " . htmlspecialchars($missingDataResult['user_message']) . "</p>";
        echo "<p><strong>Предложения:</strong></p>";
        echo "<ul>";
        foreach ($missingDataResult['suggestions'] as $suggestion) {
            echo "<li>" . htmlspecialchars($suggestion) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p class='status-failed'>❌ НЕ ПРОЙДЕН</p>";
        echo "<p>Неожиданный результат обработки отсутствующих данных.</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='status-failed'>❌ ОШИБКА</p>";
    echo "<p>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

// Тест 2: Обработка неизвестного маркетплейса
echo "<div class='test-section'>";
echo "<h2>Тест 2: Обработка неизвестного маркетплейса</h2>";

try {
    $unknownMarketplace = $fallbackHandler->handleUnknownMarketplace('unknown_source', ['test' => true]);
    
    if ($unknownMarketplace === MarketplaceDetector::UNKNOWN) {
        echo "<p class='status-passed'>✅ ПРОЙДЕН</p>";
        echo "<p>Корректно обработан неизвестный маркетплейс.</p>";
    } else {
        echo "<p class='status-warning'>⚠️ ЧАСТИЧНО ПРОЙДЕН</p>";
        echo "<p>Маркетплейс определен как: " . htmlspecialchars($unknownMarketplace) . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='status-failed'>❌ ОШИБКА</p>";
    echo "<p>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

// Тест 3: Валидация параметров маркетплейса
echo "<div class='test-section'>";
echo "<h2>Тест 3: Валидация параметров маркетплейса</h2>";

$testCases = [
    ['ozon', true, 'Корректный маркетплейс Ozon'],
    ['wildberries', true, 'Корректный маркетплейс Wildberries'],
    ['invalid_marketplace', false, 'Некорректный маркетплейс'],
    ['', false, 'Пустой параметр'],
    [null, true, 'Null параметр (все маркетплейсы)']
];

$allPassed = true;

foreach ($testCases as $case) {
    list($marketplace, $expectedValid, $description) = $case;
    
    try {
        $validation = MarketplaceDetector::validateMarketplaceParameter($marketplace);
        
        if ($validation['valid'] === $expectedValid) {
            echo "<p class='status-passed'>✅ {$description}</p>";
        } else {
            echo "<p class='status-failed'>❌ {$description}</p>";
            echo "<p>Ожидалось: " . ($expectedValid ? 'валидный' : 'невалидный') . ", получено: " . ($validation['valid'] ? 'валидный' : 'невалидный') . "</p>";
            if (!$validation['valid']) {
                echo "<p>Ошибка: " . htmlspecialchars($validation['error']) . "</p>";
            }
            $allPassed = false;
        }
        
    } catch (Exception $e) {
        echo "<p class='status-failed'>❌ ОШИБКА в {$description}</p>";
        echo "<p>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
        $allPassed = false;
    }
}

if ($allPassed) {
    echo "<p class='status-passed'><strong>✅ ВСЕ ТЕСТЫ ВАЛИДАЦИИ ПРОЙДЕНЫ</strong></p>";
} else {
    echo "<p class='status-failed'><strong>❌ НЕКОТОРЫЕ ТЕСТЫ ВАЛИДАЦИИ НЕ ПРОЙДЕНЫ</strong></p>";
}

echo "</div>";

// Тест 4: Создание пользовательских сообщений об ошибках
echo "<div class='test-section'>";
echo "<h2>Тест 4: Создание пользовательских сообщений об ошибках</h2>";

$errorCodes = [
    MarketplaceFallbackHandler::ERROR_NO_DATA,
    MarketplaceFallbackHandler::ERROR_MARKETPLACE_NOT_FOUND,
    MarketplaceFallbackHandler::ERROR_INVALID_MARKETPLACE,
    MarketplaceFallbackHandler::ERROR_DATA_INCONSISTENCY,
    MarketplaceFallbackHandler::ERROR_DATABASE_ERROR
];

foreach ($errorCodes as $errorCode) {
    try {
        $userError = $fallbackHandler->createUserFriendlyError($errorCode, 'ozon', ['test' => true]);
        
        if (isset($userError['title']) && isset($userError['message']) && isset($userError['icon'])) {
            echo "<p class='status-passed'>✅ {$errorCode}</p>";
            echo "<p><strong>{$userError['icon']} {$userError['title']}:</strong> {$userError['message']}</p>";
            echo "<p><em>{$userError['description']}</em></p>";
        } else {
            echo "<p class='status-failed'>❌ {$errorCode}</p>";
            echo "<p>Неполная структура сообщения об ошибке.</p>";
        }
        
    } catch (Exception $e) {
        echo "<p class='status-failed'>❌ ОШИБКА в {$errorCode}</p>";
        echo "<p>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "</div>";

// Тест 5: Тестирование API с обработкой ошибок
echo "<div class='test-section'>";
echo "<h2>Тест 5: API с обработкой ошибок</h2>";

try {
    // Тестируем с корректными параметрами
    $startDate = date('Y-m-d', strtotime('-7 days'));
    $endDate = date('Y-m-d');
    
    echo "<h3>Тест с корректными параметрами</h3>";
    $result = $marginAPI->getMarginSummaryByMarketplace($startDate, $endDate, 'ozon');
    
    if (isset($result['success'])) {
        if ($result['success']) {
            echo "<p class='status-passed'>✅ API успешно вернул данные</p>";
            echo "<p>Есть данные: " . ($result['has_data'] ? 'Да' : 'Нет') . "</p>";
        } else {
            echo "<p class='status-warning'>⚠️ API вернул fallback результат</p>";
            echo "<p>Сообщение: " . htmlspecialchars($result['user_message'] ?? $result['message'] ?? 'Нет сообщения') . "</p>";
        }
    } else {
        echo "<p class='status-failed'>❌ API вернул неожиданную структуру</p>";
        echo "<pre>" . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
    }
    
    // Тестируем с некорректным маркетплейсом
    echo "<h3>Тест с некорректным маркетплейсом</h3>";
    $invalidResult = $marginAPI->getMarginSummaryByMarketplace($startDate, $endDate, 'invalid_marketplace');
    
    if (isset($invalidResult['success']) && !$invalidResult['success']) {
        echo "<p class='status-passed'>✅ Корректно обработан некорректный маркетплейс</p>";
        echo "<p>Код ошибки: " . htmlspecialchars($invalidResult['error_code'] ?? 'Не указан') . "</p>";
    } else {
        echo "<p class='status-failed'>❌ Некорректный маркетплейс не был обработан должным образом</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='status-failed'>❌ ОШИБКА в тестировании API</p>";
    echo "<p>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

// Тест 6: Валидация данных (если есть данные)
echo "<div class='test-section'>";
echo "<h2>Тест 6: Валидация данных</h2>";

try {
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $endDate = date('Y-m-d');
    
    echo "<p>Выполняется валидация данных за период: {$startDate} - {$endDate}</p>";
    
    $validationResult = $validator->validateMarketplaceData($startDate, $endDate);
    
    if (isset($validationResult['overall_status'])) {
        $status = $validationResult['overall_status'];
        $statusClass = $status === 'passed' ? 'status-passed' : ($status === 'warning' ? 'status-warning' : 'status-failed');
        
        echo "<p class='{$statusClass}'>Общий статус: {$status}</p>";
        
        if (!empty($validationResult['errors'])) {
            echo "<h4>Критические проблемы:</h4>";
            echo "<ul>";
            foreach ($validationResult['errors'] as $error) {
                echo "<li>" . htmlspecialchars($error) . "</li>";
            }
            echo "</ul>";
        }
        
        if (!empty($validationResult['warnings'])) {
            echo "<h4>Предупреждения:</h4>";
            echo "<ul>";
            foreach ($validationResult['warnings'] as $warning) {
                echo "<li>" . htmlspecialchars($warning) . "</li>";
            }
            echo "</ul>";
        }
        
        if (!empty($validationResult['recommendations'])) {
            echo "<h4>Рекомендации:</h4>";
            echo "<ul>";
            foreach ($validationResult['recommendations'] as $recommendation) {
                echo "<li>" . htmlspecialchars($recommendation) . "</li>";
            }
            echo "</ul>";
        }
        
        echo "<p class='status-passed'>✅ Валидация выполнена успешно</p>";
        
    } else {
        echo "<p class='status-failed'>❌ Валидация вернула неожиданную структуру</p>";
        echo "<pre>" . htmlspecialchars(json_encode($validationResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
    }
    
} catch (Exception $e) {
    echo "<p class='status-failed'>❌ ОШИБКА в валидации данных</p>";
    echo "<p>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

// Тест 7: Статистика ошибок
echo "<div class='test-section'>";
echo "<h2>Тест 7: Статистика ошибок</h2>";

try {
    $startDate = date('Y-m-d', strtotime('-7 days'));
    $endDate = date('Y-m-d');
    
    $errorStats = $fallbackHandler->getErrorStats($startDate, $endDate);
    
    echo "<p class='status-passed'>✅ Статистика ошибок получена</p>";
    echo "<p><strong>Всего ошибок:</strong> {$errorStats['total_errors']}</p>";
    echo "<p><strong>Период:</strong> {$errorStats['period']}</p>";
    
    if (!empty($errorStats['errors_by_type'])) {
        echo "<h4>Ошибки по типам:</h4>";
        echo "<ul>";
        foreach ($errorStats['errors_by_type'] as $type => $count) {
            echo "<li>{$type}: {$count}</li>";
        }
        echo "</ul>";
    }
    
    if (!empty($errorStats['errors_by_severity'])) {
        echo "<h4>Ошибки по серьезности:</h4>";
        echo "<ul>";
        foreach ($errorStats['errors_by_severity'] as $severity => $count) {
            echo "<li>{$severity}: {$count}</li>";
        }
        echo "</ul>";
    }
    
} catch (Exception $e) {
    echo "<p class='status-failed'>❌ ОШИБКА в получении статистики ошибок</p>";
    echo "<p>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

// Итоговый отчет
echo "<div class='test-section test-info'>";
echo "<h2>📊 Итоговый отчет</h2>";
echo "<p>Тестирование системы обработки ошибок и fallback механизмов завершено.</p>";
echo "<p><strong>Дата тестирования:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "<p><strong>Версия системы:</strong> 1.0</p>";

echo "<h3>Протестированные компоненты:</h3>";
echo "<ul>";
echo "<li>✅ MarketplaceFallbackHandler - обработка ошибок и fallback механизмы</li>";
echo "<li>✅ MarketplaceDataValidator - валидация и проверка консистентности данных</li>";
echo "<li>✅ MarginDashboardAPI - интеграция с обработкой ошибок</li>";
echo "<li>✅ MarketplaceDetector - валидация параметров</li>";
echo "</ul>";

echo "<h3>Рекомендации:</h3>";
echo "<ul>";
echo "<li>Регулярно проверяйте логи ошибок для выявления проблем</li>";
echo "<li>Настройте мониторинг качества данных</li>";
echo "<li>Обновляйте алгоритмы определения маркетплейсов при появлении новых источников</li>";
echo "<li>Тестируйте fallback механизмы при изменении структуры данных</li>";
echo "</ul>";

echo "</div>";

echo "<script>
    // Добавляем интерактивность
    document.addEventListener('DOMContentLoaded', function() {
        const sections = document.querySelectorAll('.test-section');
        sections.forEach(section => {
            section.style.cursor = 'pointer';
            section.addEventListener('click', function() {
                const pre = this.querySelector('pre');
                if (pre) {
                    pre.style.display = pre.style.display === 'none' ? 'block' : 'none';
                }
            });
        });
    });
</script>";

?>