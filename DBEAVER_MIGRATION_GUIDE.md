# 🗄️ Применение миграции через DBeaver

## Шаг 1: Подключение к базе данных

1. **Откройте DBeaver**
2. **Создайте новое подключение** (если еще не создано):
   - Нажмите `Database` → `New Database Connection`
   - Выберите `MySQL`
   - Введите параметры подключения:
     - **Server Host**: `127.0.0.1` (или IP вашего сервера)
     - **Port**: `3306`
     - **Database**: `mi_core_db`
     - **Username**: `mi_core_user`
     - **Password**: `secure_password_123`
   - Нажмите `Test Connection` для проверки
   - Нажмите `Finish`

## Шаг 2: Проверка текущей структуры таблицы

1. **Найдите таблицу** `ozon_funnel_data` в дереве объектов
2. **Щелкните правой кнопкой** → `View Data`
3. **Перейдите на вкладку** `Properties` или `DDL`
4. **Проверьте**, есть ли уже поле `revenue` в структуре таблицы

## Шаг 3: Применение миграции

### Вариант A: Выполнить готовый SQL файл

1. **Откройте SQL редактор**: `SQL Editor` → `New SQL Script`
2. **Скопируйте содержимое** файла `migrations/add_revenue_to_funnel_data.sql`:

```sql
-- Добавление поля revenue в таблицу ozon_funnel_data
-- Это поле необходимо для хранения данных о выручке из Ozon API

-- Проверяем, существует ли уже поле revenue
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'ozon_funnel_data'
      AND column_name = 'revenue'
);

-- Добавляем поле revenue только если его нет
SET @sql = IF(@column_exists = 0,
    'ALTER TABLE ozon_funnel_data ADD COLUMN revenue DECIMAL(15,2) DEFAULT 0.00 COMMENT "Revenue from Ozon API" AFTER orders',
    'SELECT "Поле revenue уже существует в таблице ozon_funnel_data" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Проверяем результат
SELECT
    CASE
        WHEN @column_exists = 0 THEN '✅ Поле revenue успешно добавлено в таблицу ozon_funnel_data'
        ELSE 'ℹ️ Поле revenue уже существовало в таблице ozon_funnel_data'
    END as result;

-- Показываем текущую структуру таблицы
SELECT
    column_name,
    data_type,
    is_nullable,
    column_default,
    column_comment
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'ozon_funnel_data'
ORDER BY ordinal_position;
```

3. **Выполните скрипт**: нажмите `Ctrl+Enter` или кнопку `Execute SQL Script`

### Вариант B: Простая команда ALTER TABLE

Если предыдущий вариант не работает, используйте простую команду:

1. **Откройте новый SQL редактор**
2. **Выполните эту команду**:

```sql
-- Простое добавление поля revenue
ALTER TABLE ozon_funnel_data
ADD COLUMN revenue DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Revenue from Ozon API'
AFTER orders;
```

3. **Нажмите** `Ctrl+Enter` для выполнения

## Шаг 4: Проверка результата

1. **Обновите структуру таблицы**:

   - Щелкните правой кнопкой на таблице `ozon_funnel_data`
   - Выберите `Refresh`

2. **Проверьте структуру таблицы**:

   ```sql
   DESCRIBE ozon_funnel_data;
   ```

3. **Убедитесь**, что в результате есть строка:
   ```
   | revenue | decimal(15,2) | YES  |     | 0.00 | Revenue from Ozon API |
   ```

## Шаг 5: Тестирование

**Выполните тестовый запрос**:

```sql
-- Проверяем, что поле revenue работает
SELECT
    id,
    product_id,
    views,
    orders,
    revenue,
    cached_at
FROM ozon_funnel_data
LIMIT 5;
```

## 🚨 Возможные проблемы и решения

### Ошибка "Column already exists"

```
ERROR 1060 (42S21): Duplicate column name 'revenue'
```

**Решение**: Поле уже существует, миграция не нужна.

### Ошибка прав доступа

```
ERROR 1142 (42000): ALTER command denied
```

**Решение**: Убедитесь, что пользователь `mi_core_user` имеет права `ALTER` на таблицу.

### Ошибка подключения

```
Communications link failure
```

**Решение**:

- Проверьте параметры подключения
- Убедитесь, что MySQL сервер запущен
- Проверьте firewall настройки

## 📋 Финальная проверка

После успешного применения миграции:

1. ✅ Поле `revenue` добавлено в таблицу `ozon_funnel_data`
2. ✅ Тип поля: `DECIMAL(15,2)`
3. ✅ Значение по умолчанию: `0.00`
4. ✅ Комментарий: "Revenue from Ozon API"

Теперь можно проверять дашборд - он должен корректно обрабатывать данные с выручкой!
