# 🚀 Инструкции по деплою исправлений Ozon API

## Что было исправлено

- ✅ Исправлена обработка реальной структуры Ozon API
- ✅ Добавлено поле `revenue` в обработку данных
- ✅ Создана миграция БД для добавления поля `revenue`
- ✅ Исправлено сохранение данных в базу

## Шаги деплоя на сервере

### 1. Подключитесь к серверу

```bash
ssh your-server
cd /path/to/mi_core_etl
```

### 2. Получите последние изменения

```bash
git pull origin main
```

### 3. Примените миграцию БД

```bash
# Вариант 1: Через MySQL CLI
mysql -u mi_core_user -p mi_core_db < migrations/add_revenue_to_funnel_data.sql

# Вариант 2: Через PHP скрипт (если MySQL CLI недоступен)
php apply_revenue_migration.php
```

### 4. Проверьте структуру таблицы

```bash
mysql -u mi_core_user -p mi_core_db -e "DESCRIBE ozon_funnel_data;"
```

Убедитесь, что в выводе есть поле `revenue`:

```
| revenue | decimal(15,2) | YES  |     | 0.00 |       |
```

### 5. Проверьте права доступа к файлам

```bash
# Убедитесь, что веб-сервер может читать файлы
chmod 644 src/classes/OzonAnalyticsAPI.php
chmod 644 src/api/ozon-analytics.php

# Если используется Apache
sudo chown www-data:www-data src/classes/OzonAnalyticsAPI.php
sudo chown www-data:www-data src/api/ozon-analytics.php
```

### 6. Проверьте синтаксис PHP

```bash
php -l src/classes/OzonAnalyticsAPI.php
php -l src/api/ozon-analytics.php
```

### 7. Тестирование API

```bash
# Запустите тест API
./test_production_api.sh

# Или вручную:
curl "http://your-domain/src/api/ozon-analytics.php?action=health"
curl "http://your-domain/src/api/ozon-analytics.php?action=funnel-data&date_from=2024-01-01&date_to=2024-01-31"
```

### 8. Проверьте дашборд

Откройте дашборд в браузере и убедитесь, что:

- ✅ Данные загружаются
- ✅ Отображается выручка (revenue)
- ✅ Нет ошибок в консоли браузера

## Диагностика проблем

### Если API возвращает ошибки:

```bash
# Проверьте логи Apache
tail -f /var/log/apache2/error.log

# Проверьте логи PHP
tail -f /var/log/php_errors.log

# Проверьте логи приложения
tail -f /var/log/ozon_api.log
```

### Если данные не отображаются:

1. Проверьте, что миграция применена:

   ```sql
   SELECT * FROM information_schema.columns
   WHERE table_name = 'ozon_funnel_data' AND column_name = 'revenue';
   ```

2. Проверьте, что API возвращает данные:

   ```bash
   curl -s "http://your-domain/src/api/ozon-analytics.php?action=funnel-data&date_from=2024-01-01&date_to=2024-01-31" | jq .
   ```

3. Проверьте консоль браузера на наличие JavaScript ошибок

### Если миграция не применяется:

```bash
# Проверьте подключение к БД
mysql -u mi_core_user -p -e "SELECT 1;"

# Проверьте права пользователя
mysql -u root -p -e "SHOW GRANTS FOR 'mi_core_user'@'localhost';"

# Примените миграцию вручную
mysql -u mi_core_user -p mi_core_db -e "
ALTER TABLE ozon_funnel_data
ADD COLUMN revenue DECIMAL(15,2) DEFAULT 0.00 COMMENT 'Revenue from Ozon API'
AFTER orders;
"
```

## Откат изменений (если нужно)

### Откат кода:

```bash
git revert 4bdb850
git push origin main
```

### Откат миграции БД:

```sql
ALTER TABLE ozon_funnel_data DROP COLUMN revenue;
```

## Контакты для поддержки

- Разработчик: [ваш контакт]
- Документация: OZON_API_FIXES_SUMMARY.md
- Тесты: test_complete_funnel_flow.php
