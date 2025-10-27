# Фильтр активных товаров

## Проблема

Дашборд показывал все 271 товар, включая товары с нулевым остатком (неактивные). Менеджеру нужно видеть только активные товары, которые требуют пополнения на конкретном складе.

## Решение

### 1. Обновлено представление `v_detailed_inventory`

Изменен расчет `current_stock` для включения всех детализированных полей остатков:

**Старая формула:**

```sql
quantity_present + quantity_reserved + preparing_for_sale
```

**Новая формула:**

```sql
available + reserved + preparing_for_sale + in_requests + in_transit +
in_inspection + returns + in_supply_requests + returning_from_customers +
excess_from_supply + awaiting_upd
```

Это соответствует реальной структуре данных Ozon, где остатки детализированы по статусам.

### 2. Добавлен фильтр "Показать архивные товары"

**Фронтенд:**

-   Добавлено поле `showArchived` в `FilterState`
-   Добавлен чекбокс в "Расширенные фильтры"
-   По умолчанию `showArchived = false` (показываются только активные товары)

**Бэкенд:**

-   Параметр `active_only` передается в API
-   По умолчанию `active_only = true` (фильтруются товары с `current_stock > 0`)

### 3. Результаты

**До изменений:**

-   Все товары показывали `currentStock = 0`
-   Невозможно было отличить активные товары от неактивных

**После изменений:**

-   `currentStock` показывает реальные значения (26, 36, 29 и т.д.)
-   По умолчанию показываются только активные товары
-   Архивные товары можно включить через чекбокс

## Использование

### Для менеджера

1. **Просмотр активных товаров (по умолчанию):**

    - Открыть дашборд
    - Видны только товары с остатками > 0

2. **Просмотр архивных товаров:**
    - Открыть "Расширенные фильтры"
    - Включить чекбокс "Показать архивные товары (нулевой остаток)"
    - Теперь видны все товары, включая с нулевым остатком

### Для разработчика

**API запрос (только активные):**

```bash
curl "http://localhost:8080/api/inventory/detailed-stock?active_only=true"
```

**API запрос (все товары):**

```bash
curl "http://localhost:8080/api/inventory/detailed-stock?active_only=false"
```

**Фронтенд:**

```typescript
// В FilterState
{
  showArchived: false, // По умолчанию скрыты архивные
  // ... другие фильтры
}

// Передается в API как
{
  active_only: !filters.showArchived
}
```

## Технические детали

### Измененные файлы

**База данных:**

-   `v_detailed_inventory` - обновлено представление

**Фронтенд:**

-   `frontend/src/types/inventory-dashboard.ts` - добавлено поле `showArchived`
-   `frontend/src/components/inventory/FilterPanel.tsx` - добавлен чекбокс
-   `frontend/src/services/queries.ts` - добавлен параметр `active_only`

**Бэкенд:**

-   `api/classes/DetailedInventoryController.php` - уже поддерживал `active_only`
-   `api/classes/DetailedInventoryService.php` - уже фильтровал по `current_stock > 0`

### SQL для пересоздания представления

Если нужно пересоздать представление:

```sql
-- См. полный SQL в коммите или в файле DATA_IMPORT_SUCCESS.md
CREATE OR REPLACE VIEW v_detailed_inventory AS
SELECT
    -- ...
    (COALESCE(i.available, 0) +
     COALESCE(i.reserved, 0) +
     COALESCE(i.preparing_for_sale, 0) +
     COALESCE(i.in_requests, 0) +
     COALESCE(i.in_transit, 0) +
     COALESCE(i.in_inspection, 0) +
     COALESCE(i.returns, 0) +
     COALESCE(i.in_supply_requests, 0) +
     COALESCE(i.returning_from_customers, 0) +
     COALESCE(i.excess_from_supply, 0) +
     COALESCE(i.awaiting_upd, 0)) AS current_stock,
    -- ...
FROM warehouse_sales_metrics wsm
LEFT JOIN dim_products dp ON wsm.product_id = dp.id
LEFT JOIN inventory i ON wsm.product_id = i.product_id
                      AND wsm.warehouse_name = i.warehouse_name
                      AND wsm.source = i.source
WHERE dp.id IS NOT NULL
  AND (COALESCE(wsm.sales_last_28_days, 0) > 0
       OR COALESCE(wsm.daily_sales_avg, 0) > 0
       OR (COALESCE(i.available, 0) + ... ) > 0);
```

## Проверка

```bash
# Проверить количество активных товаров
psql -d mi_core_db -c "
SELECT
  COUNT(*) FILTER (WHERE current_stock > 0) as active,
  COUNT(*) FILTER (WHERE current_stock = 0) as inactive
FROM v_detailed_inventory;
"

# Проверить API
curl "http://localhost:8080/api/inventory/detailed-stock?active_only=true&limit=5" | jq '.data[] | {productName, currentStock}'
```

## Дата реализации

27 октября 2025 года, 14:20 MSK
