# Analytics ETL Troubleshooting Guide

## 📋 Введение

Данное руководство поможет вам диагностировать и устранить проблемы с Analytics ETL системой. Руководство организовано по типам проблем с пошаговыми инструкциями по их решению.

## 🚨 Экстренная диагностика

### Быстрая проверка системы

Выполните эти шаги для быстрой оценки состояния системы:

```bash
# 1. Проверка статуса ETL процессов
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://your-domain.com/api/analytics-etl/status

# 2. Проверка подключения к базе данных
php -r "
try {
    \$pdo = new PDO('pgsql:host=localhost;dbname=warehouse_analytics', 'user', 'pass');
    echo 'Database: OK\n';
} catch (Exception \$e) {
    echo 'Database: ERROR - ' . \$e->getMessage() . '\n';
}
"

# 3. Проверка доступности Ozon API
curl -H "Client-Id: YOUR_CLIENT_ID" \
     -H "Api-Key: YOUR_API_KEY" \
     -X POST https://api-seller.ozon.ru/v2/analytics/stock_on_warehouses \
     -d '{"limit": 1, "offset": 0}'

# 4. Проверка дискового пространства
df -h

# 5. Проверка логов
tail -n 50 logs/analytics_etl.log
```

### Индикаторы критических проблем

🔴 **Немедленное вмешательство требуется**:

-   ETL процессы не запускаются более 2 часов
-   Ошибки подключения к базе данных
-   Ozon API возвращает 401/403 ошибки
-   Свободное место на диске < 1GB
-   Более 50% складов без данных > 12 часов

🟡 **Требует внимания в ближайшее время**:

-   Качество данных < 70% для большинства складов
-   ETL процессы выполняются > 30 минут
-   Частые rate limit ошибки от Ozon API
-   Расхождения между источниками > 15%

## 🔧 ETL Process Issues

### Проблема: ETL процесс не запускается

#### Симптомы

-   Статус ETL показывает "not_started" длительное время
-   Кнопка "Запустить ETL" не работает
-   В логах ошибки инициализации

#### Диагностика

```bash
# Проверка cron задач
crontab -l | grep analytics_etl

# Проверка процессов
ps aux | grep analytics_etl

# Проверка прав доступа
ls -la src/Services/AnalyticsETL.php
ls -la logs/

# Проверка конфигурации
php -c php.ini -m | grep -E "(pdo|curl|json)"
```

#### Решения

**1. Проверка зависимостей**

```bash
# Установка недостающих расширений PHP
sudo apt-get install php-pdo php-pgsql php-curl php-json php-mbstring

# Перезапуск веб-сервера
sudo systemctl restart apache2
# или
sudo systemctl restart nginx
sudo systemctl restart php-fpm
```

**2. Исправление прав доступа**

```bash
# Установка правильных прав
chmod 755 src/Services/AnalyticsETL.php
chmod 777 logs/
chmod 777 storage/temp/

# Проверка владельца файлов
chown -R www-data:www-data src/ logs/ storage/
```

**3. Проверка конфигурации**

```php
<?php
// Создайте файл test_etl_config.php
require_once 'config/database.php';
require_once 'src/Services/AnalyticsETL.php';

try {
    $etl = new AnalyticsETL();
    echo "ETL initialization: OK\n";

    $status = $etl->getETLStatus();
    echo "ETL status check: OK\n";
    print_r($status);

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
```

**4. Ручной запуск для диагностики**

```bash
# Запуск ETL в debug режиме
php -d display_errors=1 src/etl/run_analytics_etl.php --debug --verbose

# Проверка вывода
tail -f logs/analytics_etl.log
```

### Проблема: ETL процесс зависает

#### Симптомы

-   Статус показывает "running" более 1 часа
-   Прогресс не изменяется
-   Высокое потребление CPU/памяти

#### Диагностика

```bash
# Поиск зависших процессов
ps aux | grep analytics_etl
ps aux | grep php | grep etl

# Проверка использования ресурсов
top -p $(pgrep -f analytics_etl)

# Проверка блокировок в базе данных
psql -d warehouse_analytics -c "
SELECT pid, state, query_start, query
FROM pg_stat_activity
WHERE state != 'idle' AND query LIKE '%analytics%';
"

# Проверка размера логов
ls -lh logs/analytics_etl.log
```

#### Решения

**1. Принудительная остановка процесса**

```bash
# Мягкая остановка
pkill -TERM -f analytics_etl

# Жесткая остановка (если мягкая не помогла)
pkill -KILL -f analytics_etl

# Очистка блокировок в базе данных
psql -d warehouse_analytics -c "
SELECT pg_terminate_backend(pid)
FROM pg_stat_activity
WHERE state != 'idle' AND query LIKE '%analytics%';
"
```

**2. Увеличение лимитов**

```php
// В начале ETL скрипта добавьте:
ini_set('memory_limit', '2G');
ini_set('max_execution_time', 3600); // 1 час
set_time_limit(3600);
```

**3. Оптимизация размера батча**

```php
// В конфигурации ETL уменьшите размер батча
$config = [
    'load_batch_size' => 500, // вместо 1000
    'max_memory_records' => 2500, // вместо 5000
    'enable_memory_monitoring' => true
];
```

**4. Добавление контрольных точек**

```php
// В ETL процесс добавьте периодические проверки
if (memory_get_usage(true) > 1.5 * 1024 * 1024 * 1024) { // 1.5GB
    gc_collect_cycles();
    if (memory_get_usage(true) > 1.8 * 1024 * 1024 * 1024) { // 1.8GB
        throw new Exception('Memory limit approaching, stopping ETL');
    }
}
```

### Проблема: ETL процесс завершается с ошибками

#### Симптомы

-   Статус показывает "failed"
-   В логах множественные ошибки
-   Данные частично загружены

#### Диагностика

```bash
# Анализ логов ошибок
grep -i error logs/analytics_etl.log | tail -20
grep -i exception logs/analytics_etl.log | tail -10
grep -i fatal logs/analytics_etl.log | tail -5

# Проверка последнего ETL процесса
php -r "
require_once 'src/Services/AnalyticsETL.php';
\$etl = new AnalyticsETL();
\$stats = \$etl->getETLStatistics(1);
print_r(\$stats);
"
```

#### Решения

**1. Анализ типичных ошибок**

**Database Connection Error:**

```bash
# Проверка подключения к БД
psql -h localhost -U analytics_user -d warehouse_analytics -c "\dt"

# Проверка настроек подключения
grep -r "DB_" .env config/
```

**API Authentication Error:**

```bash
# Проверка API ключей
curl -H "Client-Id: YOUR_CLIENT_ID" \
     -H "Api-Key: YOUR_API_KEY" \
     https://api-seller.ozon.ru/v1/seller/info

# Обновление ключей в .env
nano .env
```

**Memory/Timeout Errors:**

```php
// Добавьте в начало ETL скрипта
ini_set('memory_limit', '4G');
ini_set('max_execution_time', 7200); // 2 часа
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && $error['type'] === E_ERROR) {
        error_log("ETL Fatal Error: " . print_r($error, true));
    }
});
```

**2. Восстановление после ошибок**

```php
// Скрипт для восстановления ETL после ошибок
<?php
require_once 'src/Services/AnalyticsETL.php';

$etl = new AnalyticsETL();

// Найти последний неудачный батч
$lastBatch = $etl->getLastFailedBatch();
if ($lastBatch) {
    echo "Restarting failed batch: " . $lastBatch['batch_id'] . "\n";

    // Перезапуск с того же места
    $result = $etl->resumeETL($lastBatch['batch_id']);

    if ($result->isSuccessful()) {
        echo "ETL resumed successfully\n";
    } else {
        echo "ETL resume failed: " . $result->getErrorMessage() . "\n";
    }
}
?>
```

## 🌐 API Integration Issues

### Проблема: Ozon API недоступен

#### Симптомы

-   Ошибки подключения к api-seller.ozon.ru
-   Таймауты запросов
-   HTTP 5xx ошибки

#### Диагностика

```bash
# Проверка доступности API
ping api-seller.ozon.ru
nslookup api-seller.ozon.ru

# Проверка SSL сертификата
openssl s_client -connect api-seller.ozon.ru:443 -servername api-seller.ozon.ru

# Тест HTTP запроса
curl -v -H "Client-Id: test" -H "Api-Key: test" \
     https://api-seller.ozon.ru/v1/seller/info
```

#### Решения

**1. Проверка сетевых настроек**

```bash
# Проверка маршрутизации
traceroute api-seller.ozon.ru

# Проверка DNS
dig api-seller.ozon.ru

# Проверка прокси (если используется)
echo $http_proxy
echo $https_proxy
```

**2. Настройка fallback на UI-отчеты**

```php
// В конфигурации ETL
$config = [
    'api_timeout' => 60, // увеличить таймаут
    'api_retries' => 5,  // больше попыток
    'fallback_to_ui' => true, // автоматический fallback
    'ui_reports_path' => '/uploads/ozon_reports/'
];
```

**3. Реализация circuit breaker**

```php
class OzonAPICircuitBreaker {
    private $failureCount = 0;
    private $lastFailureTime = 0;
    private $threshold = 5;
    private $timeout = 300; // 5 минут

    public function canMakeRequest(): bool {
        if ($this->failureCount >= $this->threshold) {
            if (time() - $this->lastFailureTime > $this->timeout) {
                $this->reset();
                return true;
            }
            return false;
        }
        return true;
    }

    public function recordFailure(): void {
        $this->failureCount++;
        $this->lastFailureTime = time();
    }

    public function recordSuccess(): void {
        $this->reset();
    }

    private function reset(): void {
        $this->failureCount = 0;
        $this->lastFailureTime = 0;
    }
}
```

### Проблема: Rate Limit превышен

#### Симптомы

-   HTTP 429 ошибки
-   Заголовки X-RateLimit-Remaining: 0
-   Медленная загрузка данных

#### Диагностика

```bash
# Проверка текущих лимитов
curl -I -H "Client-Id: YOUR_CLIENT_ID" \
       -H "Api-Key: YOUR_API_KEY" \
       https://api-seller.ozon.ru/v1/seller/info

# Анализ частоты запросов в логах
grep "API Request" logs/analytics_etl.log | \
awk '{print $1, $2}' | uniq -c | tail -20
```

#### Решения

**1. Настройка rate limiting**

```php
class RateLimiter {
    private $requests = [];
    private $maxRequests = 30; // запросов в минуту
    private $timeWindow = 60;  // секунд

    public function canMakeRequest(): bool {
        $now = time();

        // Очистка старых запросов
        $this->requests = array_filter($this->requests, function($time) use ($now) {
            return ($now - $time) < $this->timeWindow;
        });

        return count($this->requests) < $this->maxRequests;
    }

    public function recordRequest(): void {
        $this->requests[] = time();
    }

    public function getWaitTime(): int {
        if (count($this->requests) < $this->maxRequests) {
            return 0;
        }

        $oldestRequest = min($this->requests);
        return $this->timeWindow - (time() - $oldestRequest) + 1;
    }
}
```

**2. Оптимизация запросов**

```php
// Увеличение размера страницы для уменьшения количества запросов
$apiClient->getStockOnWarehouses(0, 1000); // максимальный лимит

// Использование кэширования
$cacheKey = 'stock_data_' . md5(serialize($filters));
$cachedData = $cache->get($cacheKey);
if (!$cachedData) {
    $cachedData = $apiClient->getStockOnWarehouses($offset, $limit, $filters);
    $cache->set($cacheKey, $cachedData, 7200); // 2 часа
}
```

**3. Распределение нагрузки**

```php
// Распределение запросов во времени
class RequestScheduler {
    public function scheduleRequests(array $requests): array {
        $scheduled = [];
        $delay = 0;

        foreach ($requests as $request) {
            $scheduled[] = [
                'request' => $request,
                'execute_at' => time() + $delay
            ];
            $delay += 2; // 2 секунды между запросами
        }

        return $scheduled;
    }
}
```

## 💾 Database Issues

### Проблема: Медленные запросы к базе данных

#### Симптомы

-   ETL процесс выполняется очень долго
-   Высокая нагрузка на CPU базы данных
-   Таймауты подключения

#### Диагностика

```sql
-- Поиск медленных запросов
SELECT query, mean_time, calls, total_time
FROM pg_stat_statements
WHERE mean_time > 1000 -- запросы дольше 1 секунды
ORDER BY mean_time DESC
LIMIT 10;

-- Проверка блокировок
SELECT
    blocked_locks.pid AS blocked_pid,
    blocked_activity.usename AS blocked_user,
    blocking_locks.pid AS blocking_pid,
    blocking_activity.usename AS blocking_user,
    blocked_activity.query AS blocked_statement,
    blocking_activity.query AS current_statement_in_blocking_process
FROM pg_catalog.pg_locks blocked_locks
JOIN pg_catalog.pg_stat_activity blocked_activity ON blocked_activity.pid = blocked_locks.pid
JOIN pg_catalog.pg_locks blocking_locks ON blocking_locks.locktype = blocked_locks.locktype
JOIN pg_catalog.pg_stat_activity blocking_activity ON blocking_activity.pid = blocking_locks.pid
WHERE NOT blocked_locks.granted;

-- Проверка размера таблиц
SELECT
    schemaname,
    tablename,
    pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) as size
FROM pg_tables
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC;
```

#### Решения

**1. Оптимизация индексов**

```sql
-- Создание недостающих индексов
CREATE INDEX CONCURRENTLY idx_inventory_warehouse_sku
ON inventory (warehouse_name, sku);

CREATE INDEX CONCURRENTLY idx_inventory_data_source
ON inventory (data_source);

CREATE INDEX CONCURRENTLY idx_inventory_updated_at
ON inventory (updated_at);

CREATE INDEX CONCURRENTLY idx_analytics_etl_log_batch_id
ON analytics_etl_log (batch_id);

-- Анализ использования индексов
SELECT
    schemaname,
    tablename,
    indexname,
    idx_scan,
    idx_tup_read,
    idx_tup_fetch
FROM pg_stat_user_indexes
ORDER BY idx_scan DESC;
```

**2. Оптимизация запросов ETL**

```php
// Использование подготовленных запросов
$stmt = $pdo->prepare("
    INSERT INTO inventory (sku, warehouse_name, available_stock, data_source, updated_at)
    VALUES (?, ?, ?, ?, ?)
    ON CONFLICT (sku, warehouse_name)
    DO UPDATE SET
        available_stock = EXCLUDED.available_stock,
        data_source = EXCLUDED.data_source,
        updated_at = EXCLUDED.updated_at
");

// Батчевая вставка
$pdo->beginTransaction();
foreach ($batchData as $record) {
    $stmt->execute([
        $record['sku'],
        $record['warehouse_name'],
        $record['available_stock'],
        $record['data_source'],
        $record['updated_at']
    ]);
}
$pdo->commit();
```

**3. Настройка PostgreSQL**

```sql
-- Увеличение рабочей памяти
ALTER SYSTEM SET work_mem = '256MB';
ALTER SYSTEM SET maintenance_work_mem = '1GB';
ALTER SYSTEM SET shared_buffers = '2GB';

-- Оптимизация для ETL операций
ALTER SYSTEM SET checkpoint_completion_target = 0.9;
ALTER SYSTEM SET wal_buffers = '64MB';
ALTER SYSTEM SET max_wal_size = '4GB';

-- Применение настроек
SELECT pg_reload_conf();
```

### Проблема: Нехватка места на диске

#### Симптомы

-   Ошибки записи в базу данных
-   ETL процесс останавливается
-   Логи показывают "No space left on device"

#### Диагностика

```bash
# Проверка свободного места
df -h

# Поиск больших файлов
find / -type f -size +100M 2>/dev/null | head -20

# Размер директории с логами
du -sh logs/

# Размер базы данных
psql -d warehouse_analytics -c "
SELECT
    pg_database.datname,
    pg_size_pretty(pg_database_size(pg_database.datname)) AS size
FROM pg_database;
"
```

#### Решения

**1. Очистка логов**

```bash
# Архивирование старых логов
find logs/ -name "*.log" -mtime +7 -exec gzip {} \;

# Удаление очень старых логов
find logs/ -name "*.log.gz" -mtime +30 -delete

# Настройка ротации логов
cat > /etc/logrotate.d/analytics_etl << EOF
/path/to/your/logs/*.log {
    daily
    rotate 7
    compress
    delaycompress
    missingok
    notifempty
    create 644 www-data www-data
}
EOF
```

**2. Очистка временных файлов**

```bash
# Очистка временных файлов ETL
find storage/temp/ -type f -mtime +1 -delete

# Очистка кэша
find storage/cache/ -type f -mtime +7 -delete

# Очистка старых отчетов
find uploads/ozon_reports/processed/ -type f -mtime +30 -delete
```

**3. Оптимизация базы данных**

```sql
-- Очистка старых данных
DELETE FROM analytics_etl_log
WHERE created_at < NOW() - INTERVAL '30 days';

DELETE FROM data_quality_log
WHERE created_at < NOW() - INTERVAL '30 days';

-- VACUUM для освобождения места
VACUUM FULL analytics_etl_log;
VACUUM FULL data_quality_log;

-- Анализ статистики
ANALYZE;
```

## 📊 Data Quality Issues

### Проблема: Низкое качество данных

#### Симптомы

-   Качество данных < 70% для многих складов
-   Большие расхождения между API и UI данными
-   Много алертов о проблемах качества

#### Диагностика

```php
<?php
// Скрипт диагностики качества данных
require_once 'src/Services/DataValidator.php';

$validator = new DataValidator($pdo);

// Анализ качества по складам
$warehouses = $pdo->query("
    SELECT DISTINCT warehouse_name
    FROM inventory
    WHERE updated_at > NOW() - INTERVAL '24 hours'
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($warehouses as $warehouse) {
    $data = $pdo->query("
        SELECT * FROM inventory
        WHERE warehouse_name = '$warehouse'
        AND updated_at > NOW() - INTERVAL '24 hours'
    ")->fetchAll(PDO::FETCH_ASSOC);

    $metrics = $validator->calculateQualityMetrics($data);

    echo "Warehouse: $warehouse\n";
    echo "Quality Score: " . $metrics['overall_score'] . "%\n";
    echo "Completeness: " . $metrics['completeness'] . "%\n";
    echo "Consistency: " . $metrics['consistency'] . "%\n";
    echo "Freshness: " . $metrics['freshness'] . "%\n";
    echo "---\n";
}
?>
```

#### Решения

**1. Настройка правил валидации**

```php
// Обновление правил валидации
class ImprovedDataValidator extends DataValidator {
    protected function getValidationRules(): array {
        return [
            'required_fields' => ['sku', 'warehouse_name', 'available_stock'],
            'numeric_fields' => ['available_stock', 'reserved_stock', 'total_stock', 'price'],
            'positive_fields' => ['available_stock', 'total_stock'], // может быть 0
            'non_negative_fields' => ['reserved_stock', 'price'],
            'max_values' => [
                'available_stock' => 1000000,
                'price' => 10000000
            ],
            'string_length' => [
                'sku' => ['min' => 3, 'max' => 100],
                'warehouse_name' => ['min' => 5, 'max' => 255]
            ]
        ];
    }

    protected function validateBusinessLogic(array $record): array {
        $issues = [];

        // Проверка логики остатков
        if (isset($record['available_stock'], $record['reserved_stock'], $record['total_stock'])) {
            $calculated_total = $record['available_stock'] + $record['reserved_stock'];
            $actual_total = $record['total_stock'];

            if (abs($calculated_total - $actual_total) > ($actual_total * 0.1)) {
                $issues[] = "Stock calculation mismatch: calculated=$calculated_total, actual=$actual_total";
            }
        }

        // Проверка разумности цены
        if (isset($record['price']) && $record['price'] > 0) {
            if ($record['price'] < 10) {
                $issues[] = "Suspiciously low price: " . $record['price'];
            }
            if ($record['price'] > 1000000) {
                $issues[] = "Suspiciously high price: " . $record['price'];
            }
        }

        return $issues;
    }
}
```

**2. Улучшение нормализации данных**

```php
// Расширенная нормализация складов
class ImprovedWarehouseNormalizer extends WarehouseNormalizer {
    protected function getWarehouseSynonyms(): array {
        return [
            // Полные названия
            'Региональный Фулфилмент Центр Москва' => 'РФЦ_МОСКВА',
            'Мультирегиональный Фулфилмент Центр Екатеринбург' => 'МРФЦ_ЕКАТЕРИНБУРГ',

            // Сокращения
            'РФЦ МСК' => 'РФЦ_МОСКВА',
            'РФЦ СПБ' => 'РФЦ_САНКТ_ПЕТЕРБУРГ',
            'МРФЦ ЕКБ' => 'МРФЦ_ЕКАТЕРИНБУРГ',

            // Опечатки
            'РФЦ Мосвка' => 'РФЦ_МОСКВА',
            'РФЦ Санк-Петербург' => 'РФЦ_САНКТ_ПЕТЕРБУРГ',

            // Альтернативные написания
            'Склад Москва' => 'СКЛАД_МОСКВА',
            'Warehouse Moscow' => 'РФЦ_МОСКВА'
        ];
    }

    protected function detectWarehouseType(string $name): string {
        if (preg_match('/РФЦ|региональный.*фулфилмент/i', $name)) {
            return 'RFC';
        }
        if (preg_match('/МРФЦ|мультирегиональный.*фулфилмент/i', $name)) {
            return 'MRFC';
        }
        if (preg_match('/склад|warehouse/i', $name)) {
            return 'WAREHOUSE';
        }
        return 'UNKNOWN';
    }
}
```

**3. Автоматическое исправление данных**

```php
// Система автоматического исправления
class DataAutoFixer {
    private $pdo;
    private $validator;

    public function fixCommonIssues(): array {
        $fixes = [];

        // Исправление отрицательных остатков
        $stmt = $this->pdo->prepare("
            UPDATE inventory
            SET available_stock = 0
            WHERE available_stock < 0
        ");
        $stmt->execute();
        $fixes['negative_stock_fixed'] = $stmt->rowCount();

        // Исправление несоответствий в остатках
        $stmt = $this->pdo->prepare("
            UPDATE inventory
            SET total_stock = available_stock + reserved_stock
            WHERE ABS(total_stock - (available_stock + reserved_stock)) > (total_stock * 0.1)
            AND total_stock > 0
        ");
        $stmt->execute();
        $fixes['stock_calculation_fixed'] = $stmt->rowCount();

        // Удаление дубликатов
        $stmt = $this->pdo->prepare("
            DELETE FROM inventory a USING inventory b
            WHERE a.id < b.id
            AND a.sku = b.sku
            AND a.warehouse_name = b.warehouse_name
        ");
        $stmt->execute();
        $fixes['duplicates_removed'] = $stmt->rowCount();

        return $fixes;
    }
}
```

### Проблема: Расхождения между источниками данных

#### Симптомы

-   API и UI данные сильно отличаются
-   Алерты о расхождениях > 15%
-   Пользователи жалуются на неточные данные

#### Диагностика

```sql
-- Поиск расхождений между источниками
WITH api_data AS (
    SELECT sku, warehouse_name, available_stock as api_stock
    FROM inventory
    WHERE data_source = 'api'
    AND updated_at > NOW() - INTERVAL '6 hours'
),
ui_data AS (
    SELECT sku, warehouse_name, available_stock as ui_stock
    FROM inventory
    WHERE data_source = 'ui_report'
    AND updated_at > NOW() - INTERVAL '6 hours'
)
SELECT
    a.sku,
    a.warehouse_name,
    a.api_stock,
    u.ui_stock,
    ABS(a.api_stock - u.ui_stock) as difference,
    CASE
        WHEN a.api_stock > 0 THEN
            ROUND(ABS(a.api_stock - u.ui_stock) * 100.0 / a.api_stock, 2)
        ELSE 0
    END as percentage_diff
FROM api_data a
JOIN ui_data u ON a.sku = u.sku AND a.warehouse_name = u.warehouse_name
WHERE ABS(a.api_stock - u.ui_stock) > GREATEST(a.api_stock * 0.1, 10)
ORDER BY percentage_diff DESC
LIMIT 20;
```

#### Решения

**1. Система приоритизации источников**

```php
class DataSourcePrioritizer {
    private $priorities = [
        'api' => 100,
        'ui_report' => 80,
        'manual' => 90
    ];

    public function resolveConflict(array $records): array {
        // Сортировка по приоритету и времени
        usort($records, function($a, $b) {
            $priorityDiff = $this->priorities[$b['data_source']] - $this->priorities[$a['data_source']];
            if ($priorityDiff !== 0) {
                return $priorityDiff;
            }

            // При равном приоритете - по времени
            return strtotime($b['updated_at']) - strtotime($a['updated_at']);
        });

        $primary = $records[0];

        // Проверка на разумность данных
        if ($this->isDataReasonable($primary)) {
            return $primary;
        }

        // Поиск альтернативного источника
        foreach (array_slice($records, 1) as $record) {
            if ($this->isDataReasonable($record)) {
                return $record;
            }
        }

        return $primary; // Возвращаем первый, если все неразумные
    }

    private function isDataReasonable(array $record): bool {
        // Проверки разумности данных
        if ($record['available_stock'] < 0) return false;
        if ($record['price'] <= 0) return false;
        if (empty($record['sku']) || empty($record['warehouse_name'])) return false;

        return true;
    }
}
```

**2. Система алертов о расхождениях**

```php
class DiscrepancyAlertSystem {
    private $thresholds = [
        'critical' => 50, // 50% расхождение
        'warning' => 15,  // 15% расхождение
        'info' => 5       // 5% расхождение
    ];

    public function checkDiscrepancies(): array {
        $alerts = [];

        $discrepancies = $this->findDiscrepancies();

        foreach ($discrepancies as $discrepancy) {
            $percentage = $discrepancy['percentage_diff'];

            $severity = 'info';
            if ($percentage >= $this->thresholds['critical']) {
                $severity = 'critical';
            } elseif ($percentage >= $this->thresholds['warning']) {
                $severity = 'warning';
            }

            $alerts[] = [
                'severity' => $severity,
                'type' => 'data_discrepancy',
                'warehouse_name' => $discrepancy['warehouse_name'],
                'sku' => $discrepancy['sku'],
                'api_value' => $discrepancy['api_stock'],
                'ui_value' => $discrepancy['ui_stock'],
                'discrepancy_percentage' => $percentage,
                'description' => "Stock level discrepancy between API ({$discrepancy['api_stock']}) and UI report ({$discrepancy['ui_stock']})"
            ];
        }

        return $alerts;
    }
}
```

## 🔄 Performance Issues

### Проблема: Медленная загрузка дашборда

#### Симптомы

-   Дашборд загружается > 10 секунд
-   Пользователи жалуются на медленную работу
-   Высокая нагрузка на сервер

#### Диагностика

```bash
# Анализ производительности веб-сервера
curl -w "@curl-format.txt" -o /dev/null -s "https://your-domain.com/dashboard"

# Создайте файл curl-format.txt:
cat > curl-format.txt << EOF
     time_namelookup:  %{time_namelookup}\n
        time_connect:  %{time_connect}\n
     time_appconnect:  %{time_appconnect}\n
    time_pretransfer:  %{time_pretransfer}\n
       time_redirect:  %{time_redirect}\n
  time_starttransfer:  %{time_starttransfer}\n
                     ----------\n
          time_total:  %{time_total}\n
EOF

# Анализ медленных запросов PHP
tail -f /var/log/php_slow.log

# Проверка использования памяти
free -h
ps aux --sort=-%mem | head -10
```

#### Решения

**1. Оптимизация запросов к базе данных**

```php
// Кэширование результатов запросов
class DashboardDataProvider {
    private $cache;
    private $pdo;

    public function getWarehousesSummary(): array {
        $cacheKey = 'warehouses_summary_' . date('Y-m-d-H'); // кэш на час

        $data = $this->cache->get($cacheKey);
        if ($data === null) {
            $data = $this->fetchWarehousesSummary();
            $this->cache->set($cacheKey, $data, 3600); // 1 час
        }

        return $data;
    }

    private function fetchWarehousesSummary(): array {
        // Оптимизированный запрос с агрегацией
        $stmt = $this->pdo->query("
            SELECT
                warehouse_name,
                data_source,
                COUNT(*) as products_count,
                SUM(available_stock) as total_available,
                SUM(reserved_stock) as total_reserved,
                AVG(data_quality_score) as avg_quality,
                MAX(updated_at) as last_updated
            FROM inventory
            WHERE updated_at > NOW() - INTERVAL '24 hours'
            GROUP BY warehouse_name, data_source
            ORDER BY warehouse_name
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
```

**2. Асинхронная загрузка данных**

```javascript
// Frontend: асинхронная загрузка компонентов дашборда
class DashboardLoader {
    constructor() {
        this.loadingStates = new Map();
    }

    async loadDashboard() {
        // Показываем скелетон
        this.showSkeleton();

        // Загружаем компоненты параллельно
        const promises = [
            this.loadSummaryMetrics(),
            this.loadWarehousesList(),
            this.loadETLStatus(),
            this.loadQualityMetrics(),
        ];

        try {
            const results = await Promise.allSettled(promises);
            this.handleResults(results);
        } catch (error) {
            this.handleError(error);
        } finally {
            this.hideSkeleton();
        }
    }

    async loadWarehousesList() {
        const response = await fetch("/api/warehouses?summary=true");
        const data = await response.json();
        this.renderWarehousesList(data);
    }

    showSkeleton() {
        document.getElementById("dashboard").innerHTML = `
            <div class="skeleton-summary"></div>
            <div class="skeleton-warehouses"></div>
            <div class="skeleton-status"></div>
        `;
    }
}
```

**3. Оптимизация сервера**

```bash
# Настройка PHP-FPM
cat > /etc/php/8.1/fpm/pool.d/www.conf << EOF
[www]
user = www-data
group = www-data
listen = /run/php/php8.1-fpm.sock
pm = dynamic
pm.max_children = 50
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 35
pm.max_requests = 1000
request_terminate_timeout = 60
EOF

# Настройка Nginx для кэширования
cat > /etc/nginx/sites-available/dashboard << EOF
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/html;

    # Кэширование статических файлов
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Кэширование API ответов
    location /api/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_cache api_cache;
        proxy_cache_valid 200 5m;
        proxy_cache_key \$request_uri;
        add_header X-Cache-Status \$upstream_cache_status;
    }

    # Сжатие
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml;
}
EOF

# Создание кэш-директории
mkdir -p /var/cache/nginx/api_cache
chown www-data:www-data /var/cache/nginx/api_cache
```

## 📞 Получение помощи

### Сбор диагностической информации

Перед обращением в поддержку соберите следующую информацию:

```bash
#!/bin/bash
# Скрипт сбора диагностической информации

echo "=== SYSTEM INFO ===" > diagnostic_report.txt
uname -a >> diagnostic_report.txt
php -v >> diagnostic_report.txt
psql --version >> diagnostic_report.txt

echo -e "\n=== DISK SPACE ===" >> diagnostic_report.txt
df -h >> diagnostic_report.txt

echo -e "\n=== MEMORY USAGE ===" >> diagnostic_report.txt
free -h >> diagnostic_report.txt

echo -e "\n=== ETL STATUS ===" >> diagnostic_report.txt
curl -s -H "Authorization: Bearer YOUR_TOKEN" \
     https://your-domain.com/api/analytics-etl/status >> diagnostic_report.txt

echo -e "\n=== RECENT ERRORS ===" >> diagnostic_report.txt
tail -50 logs/analytics_etl.log | grep -i error >> diagnostic_report.txt

echo -e "\n=== DATABASE STATUS ===" >> diagnostic_report.txt
psql -d warehouse_analytics -c "
SELECT
    schemaname,
    tablename,
    n_tup_ins as inserts,
    n_tup_upd as updates,
    n_tup_del as deletes
FROM pg_stat_user_tables
WHERE schemaname = 'public'
ORDER BY n_tup_ins + n_tup_upd + n_tup_del DESC
LIMIT 10;
" >> diagnostic_report.txt

echo "Diagnostic report saved to diagnostic_report.txt"
```

### Контакты поддержки

#### Техническая поддержка

-   **Email**: support@company.com
-   **Телефон**: +7 (495) 123-45-67
-   **Часы работы**: Пн-Пт 9:00-18:00 (МСК)

#### Экстренная поддержка (критические инциденты)

-   **24/7 Hotline**: +7 (495) 123-45-68
-   **Telegram**: @warehouse_support
-   **Email**: critical@company.com

#### При обращении укажите:

1. Описание проблемы
2. Шаги для воспроизведения
3. Время возникновения проблемы
4. Диагностический отчет (если возможно)
5. Скриншоты ошибок
6. Версию системы

### Полезные ссылки

-   **API Documentation**: `/docs/analytics-etl-api-documentation.md`
-   **User Manual**: `/docs/warehouse-dashboard-user-manual.md`
-   **System Architecture**: `/docs/system-architecture.md`
-   **FAQ**: `https://your-domain.com/faq`
-   **Status Page**: `https://status.your-domain.com`

---

**Последнее обновление**: 25 января 2025 г.  
**Версия руководства**: 1.0  
**Версия системы**: 2.0 (с поддержкой Analytics ETL)
