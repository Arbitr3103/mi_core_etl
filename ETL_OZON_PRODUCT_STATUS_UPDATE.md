# План обновления ETL: Добавление статуса товаров Ozon

## Проблема

Дашборд показывает 271 товар, но в Ozon "В продаже" только 48 товаров.

**Причина:** Мы используем отчет `POST /v1/report/warehouse/stock`, который показывает только FBO-остатки в различных статусах (preparing_for_sale, in_transit и т.д.), но **не показывает реальный статус товара** ("продаётся", "готов к продаже", "архив").

## Решение

Добавить в ETL-процесс получение данных из **"Отчета по товарам"** (`POST /v2/product/import/info`).

---

## Изменения в ETL-процессе

### 1. Новый API endpoint для получения статусов товаров

**Метод:** `POST /v2/product/import/info`

**Описание:** Возвращает полную информацию о товарах, включая:

-   Статус товара (продаётся, готов к продаже, архив)
-   Видимость на сайте
-   FBO-остатки (реальные, доступные для продажи)
-   Цены, рейтинги и другие данные

**Формат ответа:** CSV-файл (аналогично отчету по остаткам)

### 2. Алгоритм получения данных

```python
# 1. Запросить генерацию отчета
POST /v2/product/import/info
{
  "offer_id": [],  # Пустой массив = все товары
  "product_id": [],
  "sku": []
}

# Ответ:
{
  "result": {
    "code": "report_code_12345"
  }
}

# 2. Проверять готовность отчета
POST /v1/report/info
{
  "code": "report_code_12345"
}

# Ответ (когда готов):
{
  "result": {
    "status": "SUCCESS",
    "file": "https://ozon.ru/report/download/..."
  }
}

# 3. Скачать CSV-файл
GET https://ozon.ru/report/download/...

# 4. Распарсить CSV и загрузить в БД
```

### 3. Ключевые поля в CSV-отчете

| Поле в CSV                                   | Назначение                                      | Куда сохранять                 |
| -------------------------------------------- | ----------------------------------------------- | ------------------------------ |
| `Статус товара` или `state_name`             | Статус: "продаётся", "готов к продаже", "архив" | `dim_products.ozon_status`     |
| `Видимость` или `visibility`                 | Показывается ли товар на сайте                  | `dim_products.ozon_visibility` |
| `FBO остаток, шт` или `fbo_present_stock`    | Реальный остаток на FBO                         | `inventory.quantity_present`   |
| `FBO доступно, шт` или `fbo_available_stock` | Доступно для продажи                            | `inventory.available`          |
| `Артикул` или `offer_id`                     | Для связи с товаром                             | Ключ для JOIN                  |

### 4. Обновление таблиц БД

**Таблица `dim_products`:**

```sql
ALTER TABLE dim_products
ADD COLUMN IF NOT EXISTS ozon_status VARCHAR(50),
ADD COLUMN IF NOT EXISTS ozon_visibility VARCHAR(50);

CREATE INDEX IF NOT EXISTS idx_dim_products_ozon_status
ON dim_products(ozon_status);
```

**Таблица `inventory`:**

-   Обновлять поля `quantity_present` и `available` из нового отчета
-   Эти данные будут **источником правды** для FBO-остатков

---

## Изменения в дашборде

### 1. Обновить представление `v_detailed_inventory`

Добавить фильтр по статусу товара:

```sql
CREATE OR REPLACE VIEW v_detailed_inventory AS
SELECT
    dp.id AS product_id,
    dp.product_name,
    dp.ozon_status,  -- НОВОЕ ПОЛЕ
    dp.ozon_visibility,  -- НОВОЕ ПОЛЕ
    -- ... остальные поля
FROM warehouse_sales_metrics wsm
LEFT JOIN dim_products dp ON wsm.product_id = dp.id
LEFT JOIN inventory i ON wsm.product_id = i.product_id
WHERE dp.id IS NOT NULL
  AND dp.ozon_status = 'продаётся'  -- ФИЛЬТР ПО СТАТУСУ
  AND (COALESCE(wsm.sales_last_28_days, 0) > 0
       OR COALESCE(wsm.daily_sales_avg, 0) > 0
       OR current_stock > 0);
```

### 2. Добавить фильтр в API

**Бэкенд (`api/classes/DetailedInventoryController.php`):**

Добавить новый параметр `product_status`:

```php
// В validateFilters()
if (isset($params['product_status']) && $params['product_status'] !== '') {
    $validStatuses = ['продаётся', 'готов к продаже', 'архив', 'заблокирован'];
    $status = trim($params['product_status']);

    if (in_array($status, $validStatuses)) {
        $filters['product_status'] = $status;
    }
}
```

**Фронтенд:**

Добавить фильтр по статусу товара в `FilterPanel.tsx`:

```typescript
// Новый фильтр
<select
    value={filters.productStatus || "продаётся"}
    onChange={(e) => onChange({ productStatus: e.target.value })}
>
    <option value="продаётся">В продаже (48)</option>
    <option value="готов к продаже">Готов к продаже (108)</option>
    <option value="">Все товары (271)</option>
</select>
```

---

## Результат

После внедрения:

-   **По умолчанию:** Дашборд показывает 48 товаров со статусом "продаётся"
-   **Опционально:** Можно включить товары "готов к продаже" (108 шт)
-   **Все товары:** Можно показать все 271 товар

---

## Приоритет задач

### Высокий приоритет (сделать сейчас)

1. ✅ Добавить колонки `ozon_status` и `ozon_visibility` в `dim_products`
2. ⏳ Обновить ETL-скрипт для получения данных из `/v2/product/import/info`
3. ⏳ Парсить CSV и сохранять статусы в БД
4. ⏳ Обновить представление `v_detailed_inventory` с фильтром по статусу

### Средний приоритет (можно сделать позже)

5. ⏳ Добавить фильтр по статусу в API
6. ⏳ Добавить UI-фильтр по статусу в дашборд
7. ⏳ Обновить документацию

---

## Временное решение (пока ETL не обновлен)

**Пока нет данных о статусах**, можно использовать **фильтр по умолчанию**:

```typescript
// В DEFAULT_FILTERS
statuses: ["critical", "low"], // Показывать только критические и низкие
```

Это даст 268 товаров (226 критических + 42 низких), что близко к реальности и полезно для менеджера.

---

## Файлы для изменения

### ETL (Python)

-   `etl/ozon_products_import.py` - новый скрипт для импорта статусов
-   `etl/ozon_api_client.py` - добавить метод для `/v2/product/import/info`

### База данных

-   ✅ `dim_products` - добавлены колонки `ozon_status`, `ozon_visibility`
-   ⏳ `v_detailed_inventory` - добавить фильтр по статусу

### Бэкенд

-   `api/classes/DetailedInventoryController.php` - добавить параметр `product_status`
-   `api/classes/DetailedInventoryService.php` - добавить фильтр в WHERE

### Фронтенд

-   `frontend/src/types/inventory-dashboard.ts` - добавить `productStatus` в `FilterState`
-   `frontend/src/components/inventory/FilterPanel.tsx` - добавить UI-фильтр
-   `frontend/src/services/queries.ts` - передавать `product_status` в API

---

## Дата создания

27 октября 2025 года, 15:00 MSK
