<?php
/**
 * Тест для проверки обработки ошибок и валидации фильтра по странам
 * 
 * Этот файл тестирует:
 * - Валидацию параметров фильтра на backend
 * - Обработку ошибок API
 * - Fallback поведение при отсутствии данных
 */

require_once 'CountryFilterAPI.php';

echo "<h1>Тест обработки ошибок и валидации</h1>\n";

// Тест 1: Валидация некорректных параметров
echo "<h2>Тест 1: Валидация некорректных параметров</h2>\n";

$api = new CountryFilterAPI();

$invalidFilters = [
    'brand_id' => 'abc',        // Не число
    'model_id' => -5,           // Отрицательное число
    'year' => 1800,             // Слишком старый год
    'country_id' => 9999999,    // Слишком большое число
    'limit' => 2000,            // Превышает максимум
    'offset' => -10             // Отрицательное смещение
];

$validation = $api->validateFilters($invalidFilters);
echo "<p><strong>Результат валидации некорректных параметров:</strong></p>\n";
echo "<pre>" . print_r($validation, true) . "</pre>\n";

// Тест 2: Валидация корректных параметров
echo "<h2>Тест 2: Валидация корректных параметров</h2>\n";

$validFilters = [
    'brand_id' => 1,
    'model_id' => 5,
    'year' => 2020,
    'country_id' => 2,
    'limit' => 50,
    'offset' => 0
];

$validation = $api->validateFilters($validFilters);
echo "<p><strong>Результат валидации корректных параметров:</strong></p>\n";
echo "<pre>" . print_r($validation, true) . "</pre>\n";

// Тест 3: Проверка существования записей
echo "<h2>Тест 3: Проверка существования записей</h2>\n";

$nonExistentFilters = [
    'brand_id' => 99999,        // Несуществующая марка
    'model_id' => 99999,        // Несуществующая модель
    'country_id' => 99999       // Несуществующая страна
];

try {
    $existenceValidation = $api->validateFilterExistence($nonExistentFilters);
    echo "<p><strong>Результат проверки существования записей:</strong></p>\n";
    echo "<pre>" . print_r($existenceValidation, true) . "</pre>\n";
} catch (Exception $e) {
    echo "<p><strong>Ошибка при проверке существования:</strong> " . $e->getMessage() . "</p>\n";
}

// Тест 4: Получение стран для несуществующей марки
echo "<h2>Тест 4: Получение стран для несуществующей марки</h2>\n";

$result = $api->getCountriesByBrand(99999);
echo "<p><strong>Результат запроса стран для несуществующей марки:</strong></p>\n";
echo "<pre>" . print_r($result, true) . "</pre>\n";

// Тест 5: Получение стран для несуществующей модели
echo "<h2>Тест 5: Получение стран для несуществующей модели</h2>\n";

$result = $api->getCountriesByModel(99999);
echo "<p><strong>Результат запроса стран для несуществующей модели:</strong></p>\n";
echo "<pre>" . print_r($result, true) . "</pre>\n";

// Тест 6: Фильтрация с некорректными параметрами
echo "<h2>Тест 6: Фильтрация с некорректными параметрами</h2>\n";

$result = $api->filterProducts($invalidFilters);
echo "<p><strong>Результат фильтрации с некорректными параметрами:</strong></p>\n";
echo "<pre>" . print_r($result, true) . "</pre>\n";

// Тест 7: Получение всех стран (должно работать)
echo "<h2>Тест 7: Получение всех стран</h2>\n";

$result = $api->getAllCountries();
echo "<p><strong>Результат получения всех стран:</strong></p>\n";
if ($result['success']) {
    echo "<p>Успешно получено " . count($result['data']) . " стран</p>\n";
    if (!empty($result['data'])) {
        echo "<p>Первые 3 страны:</p>\n";
        echo "<pre>" . print_r(array_slice($result['data'], 0, 3), true) . "</pre>\n";
    }
} else {
    echo "<pre>" . print_r($result, true) . "</pre>\n";
}

echo "<h2>Тестирование завершено</h2>\n";
echo "<p>Проверьте результаты выше для убеждения в корректной работе валидации и обработки ошибок.</p>\n";
?>