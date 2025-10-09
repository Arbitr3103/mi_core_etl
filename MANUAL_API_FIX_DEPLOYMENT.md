# Руководство по исправлению API

## Проблемы, которые были обнаружены:

1. **API не поддерживает action=overview** - исправлено
2. **Неправильное имя таблицы** - API использовал `inventory`, а реальная таблица `inventory_data`
3. **Ошибки прав доступа БД** - пользователь `ingest_user` не имеет прав CREATE
4. **Ошибки типов данных** - `product_id` должен быть BIGINT для больших значений

## Исправления, которые нужно применить на сервере:

### 1. Обновить API файл

Скопируйте содержимое файла `api/inventory-v4.php` на сервер:

```bash
# На сервере
cd /var/www/mi_core_api
cp api/inventory-v4.php api/inventory-v4.php.backup
# Затем замените содержимое файла новым кодом
```

### 2. Основные изменения в API:

- ✅ Добавлен поддержка `action=overview` (алиас для `stats`)
- ✅ Исправлено имя таблицы с `inventory` на `inventory_data`
- ✅ Добавлена информация о последней синхронизации в overview
- ✅ Улучшена обработка ошибок

### 3. Тестирование после обновления:

```bash
# Тест основных endpoints
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=overview"
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=stats"
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=products&limit=5"
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=critical&threshold=10"
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=test"
```

### 4. Исправление прав доступа БД (опционально):

```sql
-- Подключиться к MySQL как root или admin
GRANT CREATE, INSERT, UPDATE, DELETE, SELECT ON mi_core.* TO 'ingest_user'@'localhost';
FLUSH PRIVILEGES;

-- Изменить тип данных для больших product_id
ALTER TABLE inventory_data MODIFY COLUMN product_id BIGINT;
```

## Ожидаемые результаты:

После применения исправлений:

1. **API overview будет работать** и возвращать полную статистику
2. **Все endpoints будут использовать правильную таблицу** `inventory_data`
3. **Данные будут корректно отображаться** (202 записи из inventory_data)
4. **Ошибки синхронизации уменьшатся** после исправления прав БД

## Структура ответа API overview:

```json
{
  "success": true,
  "data": {
    "overview": {
      "total_products": 202,
      "products_in_stock": 176,
      "total_stock": 12543,
      "total_reserved": 234,
      "total_warehouses": 8,
      "avg_stock": 62.1,
      "last_update": "2025-10-09 13:02:58"
    },
    "stock_types": [...],
    "last_sync": {...},
    "api_version": "v4",
    "timestamp": "2025-10-09 15:32:00"
  }
}
```

## Мониторинг после развертывания:

1. Проверить логи синхронизации: `SELECT * FROM sync_logs ORDER BY created_at DESC LIMIT 5`
2. Проверить данные: `SELECT COUNT(*) FROM inventory_data WHERE quantity_present > 0`
3. Мониторить ошибки в логах веб-сервера

---

**Статус:** Готово к развертыванию
**Дата:** 09.10.2025
**Версия API:** v4.1 (исправленная)
