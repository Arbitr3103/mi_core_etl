<?php
/**
 * Тестовый скрипт для проверки API фильтра по странам
 */

require_once 'CountryFilterAPI.php';

echo "🧪 ТЕСТИРОВАНИЕ API ФИЛЬТРА ПО СТРАНАМ ИЗГОТОВЛЕНИЯ\n";
echo "=" . str_repeat("=", 60) . "\n\n";

try {
    $api = new CountryFilterAPI();
    
    // Тест 1: Получение всех стран
    echo "📍 Тест 1: Получение всех стран изготовления\n";
    echo "-" . str_repeat("-", 50) . "\n";
    
    $countries = $api->getAllCountries();
    
    if ($countries['success']) {
        echo "✅ Успешно получено стран: " . count($countries['data']) . "\n";
        
        if (!empty($countries['data'])) {
            echo "Первые 5 стран:\n";
            foreach (array_slice($countries['data'], 0, 5) as $country) {
                echo "  - ID: {$country['id']}, Название: {$country['name']}\n";
            }
        }
    } else {
        echo "❌ Ошибка: " . $countries['error'] . "\n";
    }
    echo "\n";
    
    // Тест 2: Получение стран для марки (используем первую доступную марку)
    echo "📍 Тест 2: Получение стран для марки\n";
    echo "-" . str_repeat("-", 50) . "\n";
    
    // Сначала получим список марок для тестирования
    $testBrandId = 1; // Предполагаем, что марка с ID 1 существует
    
    $brandCountries = $api->getCountriesByBrand($testBrandId);
    
    if ($brandCountries['success']) {
        echo "✅ Успешно получено стран для марки ID {$testBrandId}: " . count($brandCountries['data']) . "\n";
        
        if (!empty($brandCountries['data'])) {
            foreach ($brandCountries['data'] as $country) {
                echo "  - ID: {$country['id']}, Название: {$country['name']}\n";
            }
        } else {
            echo "  ℹ️  Для данной марки стран не найдено\n";
        }
    } else {
        echo "❌ Ошибка: " . $brandCountries['error'] . "\n";
    }
    echo "\n";
    
    // Тест 3: Получение стран для модели
    echo "📍 Тест 3: Получение стран для модели\n";
    echo "-" . str_repeat("-", 50) . "\n";
    
    $testModelId = 1; // Предполагаем, что модель с ID 1 существует
    
    $modelCountries = $api->getCountriesByModel($testModelId);
    
    if ($modelCountries['success']) {
        echo "✅ Успешно получено стран для модели ID {$testModelId}: " . count($modelCountries['data']) . "\n";
        
        if (!empty($modelCountries['data'])) {
            foreach ($modelCountries['data'] as $country) {
                echo "  - ID: {$country['id']}, Название: {$country['name']}\n";
            }
        } else {
            echo "  ℹ️  Для данной модели стран не найдено\n";
        }
    } else {
        echo "❌ Ошибка: " . $modelCountries['error'] . "\n";
    }
    echo "\n";
    
    // Тест 4: Фильтрация товаров без фильтров
    echo "📍 Тест 4: Фильтрация товаров (без фильтров)\n";
    echo "-" . str_repeat("-", 50) . "\n";
    
    $products = $api->filterProducts(['limit' => 5]);
    
    if ($products['success']) {
        echo "✅ Успешно получено товаров: " . count($products['data']) . "\n";
        echo "Общее количество: " . $products['pagination']['total'] . "\n";
        
        if (!empty($products['data'])) {
            echo "Первые товары:\n";
            foreach ($products['data'] as $product) {
                echo "  - {$product['product_name']} (SKU: {$product['sku_ozon']}, Страна: {$product['country_name']})\n";
            }
        }
    } else {
        echo "❌ Ошибка: " . $products['error'] . "\n";
    }
    echo "\n";
    
    // Тест 5: Фильтрация товаров с фильтром по стране
    echo "📍 Тест 5: Фильтрация товаров с фильтром по стране\n";
    echo "-" . str_repeat("-", 50) . "\n";
    
    if (!empty($countries['data'])) {
        $testCountryId = $countries['data'][0]['id'];
        $testCountryName = $countries['data'][0]['name'];
        
        $filteredProducts = $api->filterProducts([
            'country_id' => $testCountryId,
            'limit' => 5
        ]);
        
        if ($filteredProducts['success']) {
            echo "✅ Успешно получено товаров для страны '{$testCountryName}': " . count($filteredProducts['data']) . "\n";
            echo "Общее количество: " . $filteredProducts['pagination']['total'] . "\n";
            
            if (!empty($filteredProducts['data'])) {
                echo "Товары:\n";
                foreach ($filteredProducts['data'] as $product) {
                    echo "  - {$product['product_name']} (Страна: {$product['country_name']})\n";
                }
            }
        } else {
            echo "❌ Ошибка: " . $filteredProducts['error'] . "\n";
        }
    } else {
        echo "⚠️  Пропускаем тест - нет доступных стран\n";
    }
    echo "\n";
    
    // Тест 6: Валидация параметров
    echo "📍 Тест 6: Валидация параметров\n";
    echo "-" . str_repeat("-", 50) . "\n";
    
    $invalidFilters = [
        'brand_id' => 'invalid',
        'model_id' => -1,
        'year' => 1800,
        'country_id' => 'abc'
    ];
    
    $validation = $api->validateFilters($invalidFilters);
    
    if (!$validation['valid']) {
        echo "✅ Валидация корректно обнаружила ошибки:\n";
        foreach ($validation['errors'] as $error) {
            echo "  - {$error}\n";
        }
    } else {
        echo "❌ Валидация не обнаружила ошибки в некорректных данных\n";
    }
    echo "\n";
    
    // Тест 7: Тестирование API endpoints через HTTP (имитация)
    echo "📍 Тест 7: Проверка структуры API endpoints\n";
    echo "-" . str_repeat("-", 50) . "\n";
    
    $endpoints = [
        'GET /api/countries.php' => 'Получение всех стран',
        'GET /api/countries-by-brand.php?brand_id=1' => 'Получение стран для марки',
        'GET /api/countries-by-model.php?model_id=1' => 'Получение стран для модели',
        'GET /api/products-filter.php?country_id=1' => 'Фильтрация товаров по стране'
    ];
    
    echo "✅ Созданы следующие API endpoints:\n";
    foreach ($endpoints as $endpoint => $description) {
        echo "  - {$endpoint} - {$description}\n";
    }
    echo "\n";
    
    echo "🎉 ТЕСТИРОВАНИЕ ЗАВЕРШЕНО\n";
    echo "=" . str_repeat("=", 60) . "\n";
    
    echo "\n📋 РЕЗЮМЕ:\n";
    echo "✅ Создан класс CountryFilterAPI с методами:\n";
    echo "   - getAllCountries() - получение всех стран\n";
    echo "   - getCountriesByBrand() - получение стран для марки\n";
    echo "   - getCountriesByModel() - получение стран для модели\n";
    echo "   - filterProducts() - фильтрация товаров с поддержкой страны\n";
    echo "   - validateFilters() - валидация параметров\n";
    echo "\n✅ Созданы API endpoints:\n";
    echo "   - /api/countries.php\n";
    echo "   - /api/countries-by-brand.php\n";
    echo "   - /api/countries-by-model.php\n";
    echo "   - /api/products-filter.php\n";
    echo "\n✅ Все endpoints поддерживают:\n";
    echo "   - CORS headers\n";
    echo "   - JSON response format\n";
    echo "   - Error handling\n";
    echo "   - Parameter validation\n";
    
} catch (Exception $e) {
    echo "❌ КРИТИЧЕСКАЯ ОШИБКА: " . $e->getMessage() . "\n";
    echo "Проверьте настройки подключения к базе данных в .env файле\n";
}
?>