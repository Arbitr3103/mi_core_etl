# Marketplace Detection and Filtering Utilities

## Обзор

Созданы три основных класса для работы с разделением данных по маркетплейсам:

1. **MarketplaceDetector** - Определение маркетплейса и базовая валидация
2. **MarketplaceQueryBuilder** - Построение SQL запросов с фильтрацией по маркетплейсам
3. **MarketplaceValidator** - Валидация параметров и проверка целостности данных

## MarketplaceDetector

### Основные методы:

```php
// Определение маркетплейса по коду источника
$marketplace = MarketplaceDetector::detectFromSourceCode('ozon'); // 'ozon'
$marketplace = MarketplaceDetector::detectFromSourceCode('wb'); // 'wildberries'

// Определение по SKU
$marketplace = MarketplaceDetector::detectFromSku('OZON123', null, 'OZON123'); // 'ozon'

// Комплексное определение
$marketplace = MarketplaceDetector::detectMarketplace('ozon', 'Ozon Marketplace', 'SKU123', null, 'SKU123');

// Получение всех маркетплейсов
$all = MarketplaceDetector::getAllMarketplaces(); // ['ozon', 'wildberries']

// Получение названия и иконки
$name = MarketplaceDetector::getMarketplaceName('ozon'); // 'Ozon'
$icon = MarketplaceDetector::getMarketplaceIcon('ozon'); // '📦'

// Валидация параметра
$validation = MarketplaceDetector::validateMarketplaceParameter('ozon');
// ['valid' => true, 'error' => null]
```

### Константы:

- `MarketplaceDetector::OZON` = 'ozon'
- `MarketplaceDetector::WILDBERRIES` = 'wildberries'
- `MarketplaceDetector::UNKNOWN` = 'unknown'

## MarketplaceQueryBuilder

### Инициализация:

```php
$pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
$queryBuilder = new MarketplaceQueryBuilder($pdo);
```

### Основные методы:

```php
// Запрос сводки по маржинальности для конкретного маркетплейса
$queryData = $queryBuilder->buildMarginSummaryQuery(
    '2025-01-01', '2025-01-31', 'ozon', $clientId
);

// Запрос данных по дням
$queryData = $queryBuilder->buildDailyChartQuery(
    '2025-01-01', '2025-01-31', 'wildberries', $clientId
);

// Запрос топ товаров
$queryData = $queryBuilder->buildTopProductsQuery(
    'ozon', $limit = 10, '2025-01-01', '2025-01-31', $minRevenue = 1000
);

// Запрос сравнения маркетплейсов
$queryData = $queryBuilder->buildMarketplaceComparisonQuery(
    '2025-01-01', '2025-01-31', $clientId
);

// Выполнение запроса
$results = $queryBuilder->executeQuery($queryData, $fetchAll = true);
```

### Применение оптимизационных индексов:

```php
$results = $queryBuilder->applyOptimizationIndexes();
```

## MarketplaceValidator

### Инициализация:

```php
$pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
$validator = new MarketplaceValidator($pdo);
```

### Валидация API параметров:

```php
$params = [
    'marketplace' => 'ozon',
    'start_date' => '2025-01-01',
    'end_date' => '2025-01-31',
    'client_id' => 1,
    'limit' => 10,
    'min_revenue' => 1000
];

$validation = $validator->validateApiParameters($params);

if ($validation['valid']) {
    $sanitizedParams = $validation['sanitized'];
    // Используем очищенные параметры
} else {
    $errors = $validation['errors'];
    // Обрабатываем ошибки валидации
}
```

### Проверка целостности данных:

```php
$integrityReport = $validator->validateDataIntegrity('2025-01-01', '2025-01-31', $clientId);

echo "Статус: " . $integrityReport['status']; // 'excellent', 'good', 'issues_found'
print_r($integrityReport['issues']); // Критические проблемы
print_r($integrityReport['warnings']); // Предупреждения
```

### Генерация отчета о качестве данных:

```php
$qualityReport = $validator->generateDataQualityReport('2025-01-01', '2025-01-31', $clientId);

echo "Процент классификации: " . $qualityReport['classification']['classification_rate'] . "%";
print_r($qualityReport['recommendations']); // Рекомендации по улучшению
```

## Примеры использования

### 1. Получение статистики по маркетплейсу:

```php
$pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
$detector = new MarketplaceDetector($pdo);

$stats = $detector->getMarketplaceStats('2025-01-01', '2025-01-31', $clientId);
foreach ($stats as $stat) {
    echo $stat['icon'] . " " . $stat['name'] . ": " . $stat['total_revenue'] . " руб.\n";
}
```

### 2. Построение фильтра для существующего запроса:

```php
$detector = new MarketplaceDetector($pdo);
$filter = $detector->buildMarketplaceFilter('ozon', 's', 'dp', 'fo');

$sql = "SELECT * FROM fact_orders fo
        JOIN sources s ON fo.source_id = s.id
        LEFT JOIN dim_products dp ON fo.product_id = dp.id
        WHERE " . $filter['condition'];

$stmt = $pdo->prepare($sql);
foreach ($filter['params'] as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
```

### 3. Валидация и выполнение API запроса:

```php
function getMarketplaceData($params) {
    global $pdo;

    $validator = new MarketplaceValidator($pdo);
    $validation = $validator->validateApiParameters($params);

    if (!$validation['valid']) {
        return ['error' => 'Некорректные параметры', 'details' => $validation['errors']];
    }

    $queryBuilder = new MarketplaceQueryBuilder($pdo);
    $queryData = $queryBuilder->buildMarginSummaryQuery(
        $validation['sanitized']['start_date'],
        $validation['sanitized']['end_date'],
        $validation['sanitized']['marketplace'],
        $validation['sanitized']['client_id']
    );

    $results = $queryBuilder->executeQuery($queryData, false);
    return ['success' => true, 'data' => $results];
}
```

## Требования к базе данных

Утилиты работают с существующей структурой базы данных:

- Таблица `fact_orders` с полями: `source_id`, `product_id`, `sku`, `order_date`, `transaction_type`
- Таблица `sources` с полями: `id`, `code`, `name`
- Таблица `dim_products` с полями: `id`, `sku_ozon`, `sku_wb`, `product_name`
- Таблица `clients` с полями: `id`, `name`

## Рекомендуемые индексы

Для оптимальной производительности рекомендуется создать следующие индексы:

```sql
CREATE INDEX IF NOT EXISTS idx_sources_code ON sources(code);
CREATE INDEX IF NOT EXISTS idx_sources_name ON sources(name);
CREATE INDEX IF NOT EXISTS idx_fact_orders_date_type ON fact_orders(order_date, transaction_type);
CREATE INDEX IF NOT EXISTS idx_fact_orders_source_date ON fact_orders(source_id, order_date);
CREATE INDEX IF NOT EXISTS idx_dim_products_sku_ozon ON dim_products(sku_ozon);
CREATE INDEX IF NOT EXISTS idx_dim_products_sku_wb ON dim_products(sku_wb);
```

Эти индексы можно создать автоматически с помощью метода `applyOptimizationIndexes()` класса `MarketplaceQueryBuilder`.
