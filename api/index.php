<?php
/**
 * API Documentation - Документация по API фильтра стран
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$documentation = [
    'success' => true,
    'api_name' => 'Country Filter API',
    'version' => '1.1',
    'description' => 'API для фильтрации товаров по стране изготовления автомобиля',
    'base_url' => '/api/',
    'endpoints' => [
        [
            'method' => 'GET',
            'endpoint' => '/api/countries.php',
            'description' => 'Получить список всех стран изготовления',
            'parameters' => [],
            'example' => '/api/countries.php'
        ],
        [
            'method' => 'GET',
            'endpoint' => '/api/countries-by-brand.php',
            'description' => 'Получить страны для конкретной марки',
            'parameters' => [
                'brand_id' => 'ID марки автомобиля (обязательный)'
            ],
            'example' => '/api/countries-by-brand.php?brand_id=1'
        ],
        [
            'method' => 'GET',
            'endpoint' => '/api/countries-by-model.php',
            'description' => 'Получить страны для конкретной модели',
            'parameters' => [
                'model_id' => 'ID модели автомобиля (обязательный)'
            ],
            'example' => '/api/countries-by-model.php?model_id=5'
        ],
        [
            'method' => 'GET',
            'endpoint' => '/api/brands.php',
            'description' => 'Получить список всех марок автомобилей',
            'parameters' => [],
            'example' => '/api/brands.php'
        ],
        [
            'method' => 'GET',
            'endpoint' => '/api/models.php',
            'description' => 'Получить список моделей автомобилей',
            'parameters' => [
                'brand_id' => 'ID марки (опциональный, для фильтрации по марке)'
            ],
            'example' => '/api/models.php?brand_id=1'
        ],
        [
            'method' => 'GET',
            'endpoint' => '/api/years.php',
            'description' => 'Получить список годов выпуска',
            'parameters' => [
                'brand_id' => 'ID марки (опциональный)',
                'model_id' => 'ID модели (опциональный)'
            ],
            'example' => '/api/years.php?brand_id=1&model_id=5'
        ],
        [
            'method' => 'GET',
            'endpoint' => '/api/products.php',
            'description' => 'Фильтрация товаров по параметрам',
            'parameters' => [
                'brand_id' => 'ID марки (опциональный)',
                'model_id' => 'ID модели (опциональный)',
                'year' => 'Год выпуска (опциональный)',
                'country_id' => 'ID страны изготовления (опциональный)',
                'limit' => 'Количество записей (по умолчанию 100)',
                'offset' => 'Смещение для пагинации (по умолчанию 0)'
            ],
            'example' => '/api/products.php?brand_id=1&country_id=2&limit=50'
        ]
    ],
    'response_format' => [
        'success' => 'boolean - статус выполнения запроса',
        'data' => 'array - данные ответа',
        'error' => 'string - сообщение об ошибке (если success = false)',
        'message' => 'string - информационное сообщение (опционально)',
        'pagination' => 'object - информация о пагинации (для products.php)'
    ],
    'error_codes' => [
        200 => 'Успешный запрос',
        400 => 'Некорректные параметры запроса',
        404 => 'Ресурс не найден',
        405 => 'Метод не поддерживается',
        500 => 'Внутренняя ошибка сервера'
    ],
    'cors' => 'Поддерживается для всех endpoints',
    'cache' => 'Результаты кэшируются на 15-20 минут'
];

echo json_encode($documentation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>