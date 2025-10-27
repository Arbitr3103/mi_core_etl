# Успешный импорт продакшн-данных

## Что было сделано

### 1. Обновление схемы локальной базы данных

Добавлены недостающие колонки в таблицы для соответствия продакшн-схеме:

**Таблица `inventory`:**

-   `cluster` - кластер склада
-   `sku` - артикул товара
-   `name` - название товара
-   `available` - доступно для продажи
-   `reserved` - зарезервировано
-   `preparing_for_sale` - готовится к продаже
-   `in_requests` - в заявках
-   `in_transit` - в пути
-   `in_inspection` - на проверке
-   `returns` - возвраты
-   `expiring_soon` - истекает срок годности
-   `defective` - бракованные
-   `in_supply_requests` - в заявках на поставку
-   `returning_from_customers` - возвращается от клиентов
-   `excess_from_supply` - излишки от поставки
-   `awaiting_upd` - ожидает обновления
-   `preparing_for_removal` - готовится к списанию

**Таблица `warehouse_sales_metrics`:**

-   `created_at` - дата создания записи
-   `updated_at` - дата обновления записи

### 2. Импорт данных из продакшена

Успешно импортированы данные из продакшн-сервера:

-   **dim_products**: 271 записей (справочник товаров)
-   **inventory**: 271 записей (остатки на складах)
-   **warehouse_sales_metrics**: 271 записей (метрики продаж)

### 3. Проверка работы API

API endpoint работает корректно:

-   URL: `http://localhost:8080/api/inventory/detailed-stock`
-   Возвращает реальные данные из продакшена
-   Все 271 записей доступны

### 4. Примеры данных

```json
{
    "productName": "Конфеты Ирис SOLENTO, без глютена",
    "sku": "Фруктовый двухцветный0,25",
    "warehouseName": "Дополнительный склад",
    "cluster": "Основной",
    "currentStock": 0,
    "availableStock": 0,
    "preparing_for_sale": 11,
    "in_transit": 10,
    "dailySales": 3.5,
    "daysOfStock": 0,
    "status": "out_of_stock"
}
```

## Как использовать

### Запуск локального окружения

1. **Запустить бэкенд:**

    ```bash
    ./local-backend.sh
    ```

    API будет доступен на `http://localhost:8080`

2. **Запустить фронтенд:**

    ```bash
    ./local-frontend.sh
    ```

    Интерфейс будет доступен на `http://localhost:5173`

3. **Открыть в браузере:**
    ```
    http://localhost:5173
    ```

### Повторный импорт данных

Если нужно обновить данные из продакшена:

```bash
./load-production-data.sh
```

Скрипт автоматически:

-   Проверит подключение к продакшн-серверу
-   Обновит схему локальной БД
-   Импортирует свежие данные
-   Проверит корректность импорта

## Проверка данных

### Через psql

```bash
# Количество записей
psql -d mi_core_db -c "SELECT COUNT(*) FROM inventory;"

# Примеры данных
psql -d mi_core_db -c "
  SELECT sku, name, warehouse_name, available, preparing_for_sale, in_transit
  FROM inventory
  LIMIT 5;
"
```

### Через API

```bash
# Все записи
curl "http://localhost:8080/api/inventory/detailed-stock?limit=1000" | jq '.'

# Только критические остатки
curl "http://localhost:8080/api/inventory/detailed-stock?status=critical" | jq '.'

# Список складов
curl "http://localhost:8080/api/inventory/detailed-stock?action=warehouses" | jq '.'
```

## Статус

✅ Схема базы данных обновлена  
✅ Данные импортированы (271 записей)  
✅ API работает корректно  
✅ Фронтенд отображает реальные данные  
✅ Скрипт импорта готов к повторному использованию

## Дата импорта

27 октября 2025 года, 13:00 MSK
