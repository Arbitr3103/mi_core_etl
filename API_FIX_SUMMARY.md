# Сводка исправлений API

## Проблемы, которые были обнаружены:

### 1. API не поддерживал action=overview

**Проблема:** `curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=overview"` возвращал ошибку "Unknown action: overview"

**Решение:** ✅ Добавлен поддержка `action=overview` как алиас для `stats` с дополнительной информацией

### 2. Неправильное имя таблицы в API

**Проблема:** API использовал таблицу `inventory`, но в БД она называется `inventory_data`

**Решение:** ✅ Исправлены все SQL запросы для использования правильной таблицы `inventory_data`

### 3. Ошибки прав доступа БД

**Проблема:** Пользователь `ingest_user` не имеет прав CREATE для таблицы `ozon_warehouses`

**Статус:** ⚠️ Требует исправления на сервере (см. инструкции ниже)

### 4. Ошибки типов данных

**Проблема:** `product_id` имеет ограничения по размеру для больших значений (2919414603)

**Статус:** ⚠️ Требует изменения типа на BIGINT

## Что было исправлено в коде:

### Файл: `api/inventory-v4.php`

1. **Добавлен endpoint overview:**

```php
case 'overview':
case 'stats':
    // Возвращает полную статистику + информацию о последней синхронизации
```

2. **Исправлены все SQL запросы:**

- `FROM inventory` → `FROM inventory_data`
- Все 8 запросов обновлены для использования правильной таблицы

3. **Улучшена структура ответа overview:**

```json
{
  "success": true,
  "data": {
    "overview": {...},
    "stock_types": [...],
    "last_sync": {...},
    "api_version": "v4",
    "timestamp": "2025-10-09 15:32:00"
  }
}
```

## Инструкции по развертыванию:

### На сервере выполнить:

1. **Обновить API файл:**

```bash
cd /var/www/mi_core_api
cp api/inventory-v4.php api/inventory-v4.php.backup
# Заменить содержимое файла новым кодом из локального api/inventory-v4.php
```

2. **Исправить права БД (как root/admin):**

```sql
GRANT CREATE, INSERT, UPDATE, DELETE, SELECT ON mi_core.* TO 'ingest_user'@'localhost';
FLUSH PRIVILEGES;
```

3. **Исправить тип данных (опционально):**

```sql
ALTER TABLE inventory_data MODIFY COLUMN product_id BIGINT;
```

## Тестирование после развертывания:

```bash
# Основные endpoints
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=overview"
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=products&limit=5"
curl "http://api.zavodprostavok.ru/api/inventory-v4.php?action=critical&threshold=10"
```

## Ожидаемые результаты:

- ✅ `action=overview` будет работать
- ✅ Все данные из `inventory_data` (202 записи) будут доступны
- ✅ Статистика будет корректной
- ⚠️ Ошибки синхронизации уменьшатся после исправления прав БД

## Текущее состояние БД:

```
✓ inventory_data: 202 записей
✓ ozon_warehouses: 8 складов
✓ sync_logs: 14 записей
✓ dim_products: 9 записей
```

---

**Статус:** Код исправлен, готов к развертыванию
**Приоритет:** Высокий (API не работает без этих исправлений)
**Время развертывания:** ~5 минут
