# Design Document: Система управления мастер-данными товаров (MDM)

## Overview

Система MDM (Master Data Management) решает критическую проблему несовместимости данных между разными API эндпоинтами Ozon. Основные проблемы: SQL ошибки при объединении таблиц, разные типы данных для ID товаров, отсутствие надежной связи между product_id из inventory и названиями из product info API. Система обеспечивает стабильную синхронизацию данных, исправляет SQL запросы и создает надежную систему сопоставления товаров.

## Architecture

### Высокоуровневая архитектура

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Ozon API      │    │   SQL Query     │    │   Fixed Data    │
│   Endpoints     │───▶│   Fixer         │───▶│   Layer         │
│ (Analytics,     │    │                 │    │                 │
│  Inventory,     │    │                 │    │                 │
│  Product Info)  │    │                 │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                                                       │
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Dashboard     │    │   Cross-Ref     │    │   Sync Engine   │
│   (Real Names)  │◀───│   Mapping       │◀───│   (No Errors)   │
│                 │    │   Table         │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

### Компоненты системы

1. **SQL Query Fixer** - исправление проблемных SQL запросов с DISTINCT и ORDER BY
2. **Cross-Reference Mapping** - таблица сопоставления разных ID одного товара
3. **Sync Engine** - надежная синхронизация без SQL ошибок
4. **Data Type Normalizer** - приведение типов данных к совместимым форматам
5. **API Response Harmonizer** - унификация ответов от разных эндпоинтов
6. **Fallback Data Provider** - резервные данные при недоступности API

## Components and Interfaces

### 1. База данных MDM - Исправленная схема

#### Таблица product_cross_reference - Решение проблемы разных ID

```sql
CREATE TABLE product_cross_reference (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    -- Унифицированные типы данных для избежания JOIN ошибок
    inventory_product_id VARCHAR(50) NOT NULL,  -- Из inventory_data
    analytics_product_id VARCHAR(50),           -- Из analytics API
    ozon_product_id VARCHAR(50),               -- Из product info API
    sku_ozon VARCHAR(50),                      -- Для совместимости с dim_products

    -- Кэшированные данные для fallback
    cached_name VARCHAR(500),
    cached_brand VARCHAR(200),
    last_api_sync TIMESTAMP,
    sync_status ENUM('synced', 'pending', 'failed') DEFAULT 'pending',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Индексы для быстрого поиска
    INDEX idx_inventory_id (inventory_product_id),
    INDEX idx_ozon_id (ozon_product_id),
    INDEX idx_sku_ozon (sku_ozon)
);
```

#### Исправленная таблица dim_products

```sql
-- Исправляем существующую таблицу для совместимости
ALTER TABLE dim_products
MODIFY COLUMN sku_ozon VARCHAR(50) NOT NULL,  -- Было INT, теперь VARCHAR
ADD COLUMN cross_ref_id BIGINT,
ADD FOREIGN KEY (cross_ref_id) REFERENCES product_cross_reference(id);
```

#### Таблица data_quality_metrics

```sql
CREATE TABLE data_quality_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(10,2),
    total_records INT,
    good_records INT,
    calculation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_date (metric_name, calculation_date)
);
```

### 2. SQL Query Fixer - Решение проблем с запросами

#### Исправленные запросы

**Проблемный запрос (вызывает ошибку):**

```sql
-- ОШИБКА: Expression #1 of ORDER BY clause is not in SELECT list
SELECT DISTINCT i.product_id
FROM inventory_data i
LEFT JOIN dim_products dp ON CONCAT('', i.product_id) = dp.sku_ozon
WHERE i.product_id != 0
ORDER BY i.quantity_present DESC
LIMIT 20;
```

**Исправленный запрос:**

```sql
-- РЕШЕНИЕ: Включаем сортируемую колонку в SELECT или убираем ORDER BY
SELECT DISTINCT i.product_id, MAX(i.quantity_present) as max_quantity
FROM inventory_data i
LEFT JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
WHERE i.product_id != 0
GROUP BY i.product_id
ORDER BY max_quantity DESC
LIMIT 20;
```

#### Безопасные паттерны запросов

```sql
-- Паттерн 1: Простой запрос без ORDER BY для DISTINCT
SELECT DISTINCT pcr.inventory_product_id
FROM product_cross_reference pcr
WHERE pcr.sync_status = 'pending'
LIMIT 20;

-- Паттерн 2: Подзапрос для сложной логики
SELECT product_id, product_name
FROM (
    SELECT i.product_id, dp.name as product_name, i.quantity_present
    FROM inventory_data i
    JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
    LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
    WHERE i.product_id != 0
    ORDER BY i.quantity_present DESC
) ranked_products
LIMIT 20;
```

### 3. Sync Engine - Надежная синхронизация без ошибок

#### Процесс безопасной синхронизации

1. **Pre-Check** - проверка структуры данных перед запросами
2. **Safe Extract** - извлечение с обработкой типов данных
3. **Error-Free Transform** - трансформация с валидацией
4. **Atomic Load** - загрузка с транзакциями и rollback

#### Исправленный алгоритм синхронизации

```php
class SafeSyncEngine {
    public function syncProductNames() {
        try {
            // Шаг 1: Безопасный поиск товаров без названий
            $products = $this->findProductsNeedingSync();

            // Шаг 2: Пакетная обработка для избежания timeout
            foreach (array_chunk($products, 10) as $batch) {
                $this->processBatch($batch);
            }

        } catch (Exception $e) {
            $this->handleSyncError($e);
        }
    }

    private function findProductsNeedingSync() {
        // Используем безопасный запрос без ORDER BY проблем
        $sql = "
            SELECT DISTINCT pcr.inventory_product_id, pcr.ozon_product_id
            FROM product_cross_reference pcr
            LEFT JOIN dim_products dp ON pcr.sku_ozon = dp.sku_ozon
            WHERE (dp.name LIKE 'Товар Ozon ID%' OR dp.name IS NULL)
            AND pcr.sync_status != 'failed'
            LIMIT 50
        ";
        return $this->db->query($sql)->fetchAll();
    }
}
```

#### Fallback механизмы

```php
class FallbackDataProvider {
    public function getProductName($productId) {
        // 1. Попробовать из кэша
        $cached = $this->getCachedName($productId);
        if ($cached) return $cached;

        // 2. Попробовать из API
        try {
            $apiName = $this->fetchFromOzonAPI($productId);
            $this->cacheProductName($productId, $apiName);
            return $apiName;
        } catch (Exception $e) {
            // 3. Использовать временное название
            return "Товар ID {$productId} (требует обновления)";
        }
    }
}
```

### 4. Management Interface

#### Основные экраны

1. **Dashboard** - общая статистика и метрики качества
2. **Pending Matches** - товары, требующие ручной верификации
3. **Master Products** - управление мастер-данными
4. **Data Quality** - отчеты о качестве данных
5. **Configuration** - настройки системы

#### Workflow для верификации

```
Новый товар → Автоматическое сопоставление →
    ├─ Высокая уверенность (>90%) → Автоматическое принятие
    ├─ Средняя уверенность (70-90%) → Ручная верификация
    └─ Низкая уверенность (<70%) → Создание нового Master ID
```

## Data Models

### Master Product Model

```json
{
  "master_id": "PROD_001",
  "canonical_name": "Смесь для выпечки ЭТОНОВО Пицца 9 порций",
  "canonical_brand": "ЭТОНОВО",
  "canonical_category": "Смеси для выпечки",
  "description": "Готовая смесь для приготовления теста для пиццы",
  "attributes": {
    "weight": "450г",
    "servings": 9,
    "type": "dry_mix",
    "dietary": ["vegetarian"]
  },
  "status": "active"
}
```

### SKU Mapping Model

```json
{
  "master_id": "PROD_001",
  "external_sku": "266215809",
  "source": "ozon",
  "source_name": "Смесь для выпечки ЭТОНОВО пицца 9 шт набор",
  "source_brand": "ЭТОНОВО",
  "source_category": "Смеси для выпечки",
  "confidence_score": 0.95,
  "verification_status": "auto"
}
```

## Error Handling

### Типы ошибок и обработка

1. **Конфликты сопоставления** - когда один SKU может соответствовать нескольким Master ID
2. **Недостающие данные** - обработка товаров с неполной информацией
3. **Ошибки источников** - обработка недоступности внешних API
4. **Дублирование Master ID** - предотвращение создания дубликатов

### Стратегии восстановления

```python
error_handling_strategies = {
    'source_unavailable': 'use_cached_data',
    'matching_conflict': 'queue_for_manual_review',
    'data_validation_failed': 'log_and_skip',
    'duplicate_master_id': 'merge_or_deactivate'
}
```

## Testing Strategy

### Типы тестирования

1. **Unit Tests** - тестирование алгоритмов сопоставления
2. **Integration Tests** - тестирование ETL процессов
3. **Data Quality Tests** - валидация результатов очистки данных
4. **Performance Tests** - тестирование производительности на больших объемах

### Метрики качества

- **Precision** - точность автоматического сопоставления
- **Recall** - полнота покрытия товаров
- **Data Completeness** - процент заполненности атрибутов
- **Processing Speed** - скорость обработки новых товаров

## Performance Considerations

### Оптимизация производительности

1. **Индексирование** - создание индексов для быстрого поиска
2. **Кэширование** - кэширование результатов сопоставления
3. **Пакетная обработка** - обработка данных батчами
4. **Параллелизация** - параллельная обработка независимых товаров

### Масштабирование

- **Горизонтальное масштабирование** - распределение нагрузки между серверами
- **Партиционирование данных** - разделение данных по источникам или категориям
- **Асинхронная обработка** - использование очередей для тяжелых операций

## Security

### Меры безопасности

1. **Аутентификация и авторизация** - контроль доступа к системе
2. **Аудит изменений** - логирование всех изменений мастер-данных
3. **Шифрование данных** - защита чувствительной информации
4. **Резервное копирование** - регулярные бэкапы критических данных

### Роли и права доступа

- **Data Admin** - полный доступ к системе
- **Data Manager** - управление мастер-данными и верификация
- **Data Analyst** - только чтение данных и отчетов
- **System User** - доступ через API для интеграций
