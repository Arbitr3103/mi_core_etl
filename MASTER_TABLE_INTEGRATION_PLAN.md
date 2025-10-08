# План интеграции с мастер таблицей dim_products

## Проблема доступа

У пользователя `v_admin` нет доступа к базе данных `mi_core_db`, где находится мастер таблица `dim_products`. Это требует альтернативного подхода к интеграции.

## Возможные решения

### Вариант 1: Экспорт данных из мастер таблицы

**Преимущества:**

- Не требует изменения прав доступа к БД
- Можно выполнить один раз и обновлять периодически
- Полный контроль над данными

**Шаги реализации:**

1. Экспортировать данные из `dim_products` в CSV/JSON файл
2. Создать скрипт импорта в таблицу `product_names`
3. Настроить периодическое обновление

### Вариант 2: Создание API для доступа к мастер таблице

**Преимущества:**

- Реальное время доступа к данным
- Автоматическая синхронизация
- Централизованное управление

**Шаги реализации:**

1. Создать API endpoint для доступа к `dim_products`
2. Обновить наш API для использования этого endpoint
3. Добавить кэширование для производительности

### Вариант 3: Копирование данных в локальную таблицу

**Преимущества:**

- Быстрый доступ к данным
- Независимость от внешних систем
- Возможность оптимизации структуры

**Шаги реализации:**

1. Создать расширенную таблицу `product_master` в `mi_core`
2. Скопировать данные из `dim_products`
3. Настроить регулярную синхронизацию

## Рекомендуемое решение

**Вариант 3** - создание локальной копии мастер таблицы с периодической синхронизацией.

### Структура новой таблицы `product_master`

```sql
CREATE TABLE product_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    master_id INT NOT NULL COMMENT 'ID из dim_products',
    sku_ozon VARCHAR(255) NOT NULL,
    sku_wb VARCHAR(50),
    barcode VARCHAR(255),
    product_name VARCHAR(500),
    name VARCHAR(500),
    brand VARCHAR(255),
    category VARCHAR(255),
    cost_price DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_sku_ozon (sku_ozon),
    INDEX idx_sku_wb (sku_wb),
    INDEX idx_brand (brand),
    INDEX idx_category (category),
    INDEX idx_synced_at (synced_at)
);
```

### Процесс синхронизации

1. **Экспорт из dim_products:**

   ```sql
   SELECT id, sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at
   FROM dim_products
   WHERE sku_ozon IS NOT NULL AND sku_ozon != ''
   ```

2. **Импорт в product_master:**
   ```sql
   INSERT INTO product_master (master_id, sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at)
   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
   ON DUPLICATE KEY UPDATE
   product_name = VALUES(product_name),
   name = VALUES(name),
   brand = VALUES(brand),
   category = VALUES(category),
   cost_price = VALUES(cost_price),
   updated_at = VALUES(updated_at),
   synced_at = CURRENT_TIMESTAMP
   ```

### Обновленный API запрос

```sql
SELECT
    i.id,
    i.product_id,
    i.sku,
    i.warehouse_name,
    i.stock_type,
    i.current_stock,
    i.reserved_stock,
    i.source,
    i.last_sync_at,
    pm.product_name,
    pm.name as alternative_name,
    pm.brand,
    pm.category,
    COALESCE(
        pm.product_name,
        pm.name,
        CASE
            WHEN i.sku REGEXP '^[0-9]+$' THEN CONCAT('Товар артикул ', i.sku)
            ELSE i.sku
        END
    ) as display_name,
    CONCAT(
        COALESCE(pm.product_name, pm.name, i.sku),
        CASE WHEN pm.brand IS NOT NULL THEN CONCAT(' (', pm.brand, ')') ELSE '' END,
        CASE WHEN pm.category IS NOT NULL THEN CONCAT(' [', pm.category, ']') ELSE '' END
    ) as full_display_name
FROM inventory_data i
LEFT JOIN product_master pm ON i.sku = pm.sku_ozon
WHERE i.source IN ('Ozon', 'Ozon_Analytics')
AND i.current_stock > 0
ORDER BY i.current_stock DESC
```

## Следующие шаги

1. **Получить образец данных** из `dim_products` для создания тестовой структуры
2. **Создать таблицу `product_master`** в базе `mi_core`
3. **Разработать скрипт импорта** данных
4. **Обновить API** для использования новой таблицы
5. **Протестировать** интеграцию
6. **Настроить** периодическую синхронизацию

## Ожидаемые результаты

- ✅ Полные названия товаров вместо числовых кодов
- ✅ Дополнительная информация: бренд, категория
- ✅ Улучшенный пользовательский интерфейс
- ✅ Возможность фильтрации по брендам и категориям
- ✅ Независимость от внешних баз данных
