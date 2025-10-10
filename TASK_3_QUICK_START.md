# Быстрый старт: Улучшенный скрипт синхронизации v2.0

## Что было сделано

✅ Исправлены критические SQL ошибки
✅ Добавлена обработка ошибок и retry логика
✅ Интеграция с таблицей product_cross_reference
✅ Создан улучшенный скрипт с прогресс-баром

## Быстрый запуск

### 1. Проверка структуры БД

```bash
# Убедитесь, что таблица product_cross_reference создана
mysql -u ingest_user -p mi_core_db < create_product_cross_reference_table.sql
```

### 2. Запуск синхронизации

```bash
# Базовая синхронизация (50 товаров)
php sync-real-product-names-v2.php

# Синхронизация с параметрами
php sync-real-product-names-v2.php --limit=100 --batch-size=20 --verbose
```

### 3. Проверка результатов

```bash
# Валидация
php validate_sync_results.php

# Просмотр логов
tail -f logs/sync_*.log
```

## Основные файлы

| Файл                             | Описание                        |
| -------------------------------- | ------------------------------- |
| `sync-real-product-names-v2.php` | Улучшенный скрипт синхронизации |
| `src/SyncErrorHandler.php`       | Обработчик ошибок с retry       |
| `src/CrossReferenceManager.php`  | Менеджер таблицы сопоставления  |
| `validate_sync_results.php`      | Валидация результатов           |
| `docs/SYNC_SCRIPT_V2_GUIDE.md`   | Полная документация             |

## Параметры командной строки

```bash
--limit=N          # Количество товаров (по умолчанию: 50)
--batch-size=N     # Размер пакета (по умолчанию: 10)
--verbose          # Подробный вывод
--help             # Справка
```

## Примеры использования

### Тестовая синхронизация

```bash
php sync-real-product-names-v2.php --limit=10 --verbose
```

### Полная синхронизация

```bash
php sync-real-product-names-v2.php --limit=10000 --batch-size=50
```

### Автоматизация через cron

```bash
# Ежедневная синхронизация в 3:00
0 3 * * * cd /path/to/project && php sync-real-product-names-v2.php --limit=1000 >> logs/cron_sync.log 2>&1
```

## Что исправлено

### 1. SQL ошибка с ORDER BY

**Было:**

```sql
SELECT DISTINCT i.product_id
FROM inventory_data i
ORDER BY i.quantity_present DESC  -- ОШИБКА!
```

**Стало:**

```sql
SELECT product_id
FROM (
    SELECT i.product_id, i.quantity_present
    FROM inventory_data i
    ORDER BY i.quantity_present DESC
) ranked_products
```

### 2. Несовместимость типов в JOIN

**Было:**

```sql
LEFT JOIN dim_products dp ON i.product_id = dp.sku_ozon  -- INT vs VARCHAR
```

**Стало:**

```sql
JOIN product_cross_reference pcr ON CAST(i.product_id AS CHAR) = pcr.inventory_product_id
```

### 3. Отсутствие обработки ошибок

**Было:**

- Скрипт падал при сбоях API
- Нет retry логики
- Нет логирования

**Стало:**

- Автоматические повторные попытки (3 раза)
- Экспоненциальная задержка (1s, 2s, 4s)
- Детальное логирование всех операций
- Graceful degradation

## Проверка работы

### Ожидаемый вывод

```
🔄 СИНХРОНИЗАЦИЯ РЕАЛЬНЫХ НАЗВАНИЙ ТОВАРОВ (v2.0)
================================================

✅ Подключение к БД успешно

🔍 Проверка структуры базы данных...
✅ Структура БД корректна

📊 Статистика перед синхронизацией:
   Всего товаров: 1250
   Синхронизировано: 1100
   Ожидает синхронизации: 150
   Процент синхронизации: 88.0%

🚀 Начинаем синхронизацию (лимит: 50 товаров)...

   Прогресс: [██████████████████████████████] 100% (50/50)

✅ СИНХРОНИЗАЦИЯ ЗАВЕРШЕНА!

📈 Результаты:
   Всего обработано: 50
   Успешно: 48
   Ошибки: 2
   Время выполнения: 12.5 сек
```

## Устранение проблем

### Ошибка подключения к БД

```bash
# Проверьте config.php
cat config.php | grep DB_

# Проверьте доступ
mysql -u ingest_user -p mi_core_db -e "SELECT 1"
```

### Таблица не найдена

```bash
# Создайте таблицу
mysql -u ingest_user -p mi_core_db < create_product_cross_reference_table.sql
```

### Медленная синхронизация

```bash
# Увеличьте размер пакета
php sync-real-product-names-v2.php --limit=100 --batch-size=50
```

## Дополнительная информация

- 📖 Полная документация: `docs/SYNC_SCRIPT_V2_GUIDE.md`
- 📋 Отчет о выполнении: `TASK_3_COMPLETION_REPORT.md`
- 🧪 Тесты: `validate_sync_results.php`

## Поддержка

При возникновении проблем:

1. Проверьте логи в `logs/`
2. Запустите валидацию: `php validate_sync_results.php`
3. Изучите документацию: `docs/SYNC_SCRIPT_V2_GUIDE.md`
