# Руководство по использованию SafeSyncEngine

## Обзор

SafeSyncEngine - это надежный движок синхронизации данных товаров, который решает критические проблемы несовместимости типов данных между различными API эндпоинтами (Ozon Analytics, Inventory, Product Info).

## Основные компоненты

### 1. SafeSyncEngine

Главный класс для синхронизации названий товаров из API в базу данных.

**Основные возможности:**

- Безопасное извлечение данных с проверкой типов
- Пакетная обработка для избежания timeout
- Автоматические повторные попытки при ошибках
- Детальное логирование всех операций
- Транзакционные обновления базы данных

**Пример использования:**

```php
require_once 'src/SafeSyncEngine.php';

// Создание экземпляра
$syncEngine = new SafeSyncEngine();

// Синхронизация всех товаров, требующих обновления
$results = $syncEngine->syncProductNames();

// Синхронизация с ограничением
$results = $syncEngine->syncProductNames(50); // Только 50 товаров

// Настройка параметров
$syncEngine->setBatchSize(20);      // Размер пакета
$syncEngine->setMaxRetries(5);      // Количество повторных попыток

// Получение статистики
$stats = $syncEngine->getSyncStatistics();
print_r($stats);
```

### 2. FallbackDataProvider

Провайдер резервных данных с многоуровневой системой получения информации о товарах.

**Стратегия получения данных:**

1. Кэш из таблицы `product_cross_reference`
2. API запрос к Ozon
3. Временное название (fallback)

**Пример использования:**

```php
require_once 'src/FallbackDataProvider.php';

$fallbackProvider = new FallbackDataProvider($pdo, $logger);

// Получение названия товара
$productName = $fallbackProvider->getProductName('123456', 'ozon');

// Кэширование названия
$fallbackProvider->cacheProductName('123456', 'Название товара', [
    'brand' => 'Бренд',
    'category' => 'Категория'
]);

// Обновление кэша из API ответа
$products = [
    ['id' => '123456', 'name' => 'Товар 1', 'brand' => 'Бренд 1'],
    ['id' => '789012', 'name' => 'Товар 2', 'brand' => 'Бренд 2']
];
$updated = $fallbackProvider->updateCacheFromAPIResponse($products);

// Статистика кэша
$cacheStats = $fallbackProvider->getCacheStatistics();
print_r($cacheStats);

// Очистка устаревшего кэша (старше 7 дней)
$cleared = $fallbackProvider->clearStaleCache(168);

// Настройка
$fallbackProvider->setCacheEnabled(true);
$fallbackProvider->setApiTimeout(30);
```

### 3. DataTypeNormalizer

Нормализатор типов данных для обеспечения совместимости между различными источниками.

**Основные возможности:**

- Автоматическое приведение INT к VARCHAR для ID полей
- Нормализация данных из разных API эндпоинтов
- Валидация данных перед записью в БД
- Безопасное сравнение разных типов ID

**Пример использования:**

```php
require_once 'src/DataTypeNormalizer.php';

$normalizer = new DataTypeNormalizer();

// Нормализация товара
$product = [
    'inventory_product_id' => 123456,  // INT
    'ozon_product_id' => '789012',     // STRING
    'name' => '  Товар  ',             // STRING с пробелами
    'quantity' => '100',               // STRING число
    'price' => '1,234.56'              // STRING с запятой
];

$normalized = $normalizer->normalizeProduct($product);

// Нормализация ID
$id = $normalizer->normalizeId(123456);  // Вернет '123456'

// Проверка валидности ID
$isValid = $normalizer->isValidProductId('123456');  // true

// Сравнение ID разных типов
$equal = $normalizer->compareIds(123456, '123456');  // true

// Нормализация API ответа
$ozonData = ['product_id' => 123, 'name' => 'Товар', 'price' => 1000];
$normalized = $normalizer->normalizeAPIResponse($ozonData, 'ozon');

// Валидация данных
$validation = $normalizer->validateNormalizedData($normalized);
if (!$validation['valid']) {
    print_r($validation['errors']);
}

// SQL-безопасное сравнение
$sql = "SELECT * FROM products WHERE " .
       $normalizer->getSafeComparisonSQL('inventory_id', 'ozon_id');
```

## Архитектура решения

### Проблемы, которые решает система

1. **SQL ошибки при JOIN операциях**

   - Проблема: `inventory_data.product_id` (INT) vs `dim_products.sku_ozon` (VARCHAR)
   - Решение: DataTypeNormalizer приводит все ID к VARCHAR

2. **Ошибки DISTINCT + ORDER BY**

   - Проблема: "Expression #1 of ORDER BY clause is not in SELECT list"
   - Решение: Использование подзапросов и GROUP BY вместо DISTINCT

3. **Отсутствие названий товаров**

   - Проблема: Дашборды показывают "Товар Ozon ID 123"
   - Решение: FallbackDataProvider с кэшированием и API запросами

4. **Timeout при синхронизации**
   - Проблема: Обработка большого количества товаров
   - Решение: Пакетная обработка в SafeSyncEngine

### Схема работы

```
┌─────────────────────────────────────────────────────────────┐
│                     SafeSyncEngine                          │
│                                                             │
│  1. Находит товары, требующие синхронизации                │
│  2. Разбивает на пакеты (batch processing)                 │
│  3. Для каждого товара:                                    │
│     ├─ Валидирует данные (DataTypeNormalizer)             │
│     ├─ Нормализует типы данных                            │
│     ├─ Получает название (FallbackDataProvider)           │
│     └─ Обновляет БД с retry логикой                       │
│                                                             │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                  FallbackDataProvider                       │
│                                                             │
│  Стратегия получения данных:                               │
│  1. Кэш (product_cross_reference.cached_name)              │
│  2. Ozon API (/v2/product/info)                            │
│  3. Временное название                                     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────┐
│                   DataTypeNormalizer                        │
│                                                             │
│  - INT → VARCHAR для ID полей                              │
│  - Очистка строк (trim, пробелы)                           │
│  - Валидация форматов                                      │
│  - Безопасное сравнение                                    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Требования

- PHP 7.4+
- MySQL 5.7+ / MariaDB 10.2+
- PDO расширение
- CURL расширение (для API запросов)
- Таблица `product_cross_reference` (см. миграцию)
- Таблица `dim_products`

## Установка

1. Создайте необходимые таблицы:

```bash
mysql -u root -p mi_core < create_product_cross_reference_table.sql
mysql -u root -p mi_core < migrate_dim_products_table.sql
```

2. Настройте `.env` файл:

```env
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=your_password
DB_NAME=mi_core
DB_PORT=3306

OZON_CLIENT_ID=your_client_id
OZON_API_KEY=your_api_key
```

3. Подключите классы в вашем коде:

```php
require_once 'config.php';
require_once 'src/SafeSyncEngine.php';
require_once 'src/FallbackDataProvider.php';
require_once 'src/DataTypeNormalizer.php';
```

## Использование

### Базовая синхронизация

```php
$syncEngine = new SafeSyncEngine();
$results = $syncEngine->syncProductNames();

echo "Обработано: {$results['total']}\n";
echo "Успешно: {$results['success']}\n";
echo "Ошибки: {$results['failed']}\n";
```

### Синхронизация с настройками

```php
$syncEngine = new SafeSyncEngine();

// Настройка параметров
$syncEngine->setBatchSize(10);      // Обрабатывать по 10 товаров
$syncEngine->setMaxRetries(3);      // 3 попытки при ошибках

// Синхронизация с ограничением
$results = $syncEngine->syncProductNames(100);
```

### Мониторинг синхронизации

```php
$syncEngine = new SafeSyncEngine();
$stats = $syncEngine->getSyncStatistics();

echo "Процент синхронизации: {$stats['sync_percentage']}%\n";
echo "Ожидает обработки: {$stats['pending']}\n";
echo "Последняя синхронизация: {$stats['last_sync_time']}\n";
```

### Работа с кэшем

```php
$fallbackProvider = new FallbackDataProvider($pdo, $logger);

// Получение статистики
$stats = $fallbackProvider->getCacheStatistics();
echo "Hit rate: {$stats['cache_hit_rate']}%\n";

// Очистка устаревшего кэша (старше 7 дней)
$cleared = $fallbackProvider->clearStaleCache(168);
echo "Очищено записей: {$cleared}\n";
```

## Логирование

Все операции логируются в файлы:

- `logs/sync_engine_YYYY-MM-DD.log` - логи синхронизации

Уровни логирования:

- `DEBUG` - детальная информация о каждой операции
- `INFO` - основные события (успешные операции)
- `WARNING` - предупреждения (повторные попытки)
- `ERROR` - ошибки (критические проблемы)

Настройка уровня логирования в `config.php`:

```php
define('LOG_LEVEL', 'INFO');  // DEBUG, INFO, WARNING, ERROR
```

## Обработка ошибок

Система использует многоуровневую обработку ошибок:

1. **Retry логика** - автоматические повторные попытки
2. **Транзакции** - откат изменений при ошибках
3. **Fallback механизмы** - резервные источники данных
4. **Детальное логирование** - запись всех ошибок

Пример обработки ошибок:

```php
try {
    $syncEngine = new SafeSyncEngine();
    $results = $syncEngine->syncProductNames();

    if ($results['failed'] > 0) {
        echo "Обнаружены ошибки:\n";
        foreach ($results['errors'] as $error) {
            echo "- {$error['message']}\n";
        }
    }
} catch (Exception $e) {
    echo "Критическая ошибка: " . $e->getMessage() . "\n";
    // Отправить уведомление администратору
}
```

## Производительность

### Рекомендуемые настройки

- **Размер пакета**: 10-20 товаров (зависит от скорости API)
- **Timeout API**: 30 секунд
- **Retry попытки**: 3-5 попыток
- **Очистка кэша**: каждые 7 дней

### Оптимизация

1. Используйте индексы на таблице `product_cross_reference`
2. Настройте размер пакета в зависимости от нагрузки
3. Запускайте синхронизацию в off-peak часы
4. Мониторьте размер лог-файлов

## Тестирование

Запустите тестовый скрипт:

```bash
php examples/test_sync_engine.php
```

Тест проверяет:

- Инициализацию компонентов
- Нормализацию данных
- Сравнение ID
- Статистику синхронизации
- Статистику кэша

## Troubleshooting

### Проблема: "Database connection failed"

**Решение:** Проверьте настройки в `.env` файле и доступность MySQL сервера.

### Проблема: "Table 'product_cross_reference' doesn't exist"

**Решение:** Выполните миграцию:

```bash
mysql -u root -p mi_core < create_product_cross_reference_table.sql
```

### Проблема: "All API attempts failed"

**Решение:**

1. Проверьте API ключи в `.env`
2. Проверьте доступность Ozon API
3. Увеличьте timeout: `$fallbackProvider->setApiTimeout(60)`

### Проблема: Низкий процент синхронизации

**Решение:**

1. Проверьте логи: `tail -f logs/sync_engine_*.log`
2. Запустите синхронизацию с меньшим лимитом
3. Проверьте статус API ключей

## Интеграция с существующими системами

### Обновление дашборда

```php
// В вашем API endpoint
require_once 'src/FallbackDataProvider.php';

$fallbackProvider = new FallbackDataProvider($pdo, $logger);

// Получение названия товара
$productName = $fallbackProvider->getProductName($productId);

// Использование в запросе
$sql = "
    SELECT
        i.product_id,
        COALESCE(pcr.cached_name, dp.name, 'Неизвестный товар') as product_name,
        i.quantity_present
    FROM inventory_data i
    LEFT JOIN product_cross_reference pcr
        ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
    LEFT JOIN dim_products dp
        ON pcr.sku_ozon = dp.sku_ozon
";
```

### Автоматическая синхронизация (cron)

```bash
# Добавьте в crontab
# Синхронизация каждый час
0 * * * * php /path/to/project/scripts/sync_products.php >> /var/log/sync.log 2>&1
```

Создайте `scripts/sync_products.php`:

```php
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../src/SafeSyncEngine.php';

$syncEngine = new SafeSyncEngine();
$results = $syncEngine->syncProductNames(100);

echo date('Y-m-d H:i:s') . " - Синхронизировано: {$results['success']}, Ошибок: {$results['failed']}\n";
```

## Поддержка

При возникновении проблем:

1. Проверьте логи в директории `logs/`
2. Запустите тестовый скрипт `examples/test_sync_engine.php`
3. Проверьте статистику синхронизации
4. Обратитесь к разделу Troubleshooting

## Changelog

### Version 1.0.0 (2025-10-10)

- Первый релиз
- SafeSyncEngine с пакетной обработкой
- FallbackDataProvider с кэшированием
- DataTypeNormalizer для совместимости типов
- Детальное логирование
- Retry логика
- Транзакционные обновления
