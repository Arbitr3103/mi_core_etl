# Руководство по миграции системы синхронизации остатков

## Обзор

Данное руководство описывает процесс безопасной миграции базы данных для системы синхронизации остатков товаров. Миграция включает обновление таблицы `inventory_data` и создание новой таблицы `sync_logs`.

## Изменения в схеме БД

### Обновления таблицы inventory_data

Добавляются следующие поля:

- `warehouse_name` VARCHAR(255) - название склада
- `stock_type` ENUM('FBO', 'FBS', 'realFBS', 'WB') - тип склада/фулфилмента
- `quantity_present` INT - количество товара в наличии
- `quantity_reserved` INT - зарезервированное количество
- `last_sync_at` TIMESTAMP - время последней синхронизации

### Новая таблица sync_logs

Создается для отслеживания процессов синхронизации:

- Логирование результатов синхронизации
- Отслеживание ошибок и времени выполнения
- Статистика обработанных записей

## Предварительные требования

### Системные требования

- MySQL 8.0+
- Права администратора БД
- Свободное место на диске (минимум 2x размера БД для бэкапов)
- Доступ к серверу БД

### Подготовка

1. Создайте полный бэкап базы данных
2. Убедитесь в наличии места для бэкапов
3. Запланируйте окно обслуживания
4. Уведомите пользователей о временной недоступности

## Процесс миграции

### Этап 1: Подготовка

```bash
# 1. Клонируйте репозиторий (если еще не сделано)
git pull origin main

# 2. Проверьте файлы миграции
ls -la migrations/
ls -la scripts/

# 3. Настройте переменные окружения
cp .env.example .env
# Отредактируйте .env с правильными данными БД
```

### Этап 2: Тестирование миграции

**ВАЖНО: Всегда тестируйте миграцию на копии продакшн данных!**

```bash
# Запустите полный тест миграции
./scripts/test_migration.sh full

# Или поэтапно:
./scripts/test_migration.sh setup      # Создать тестовую БД
./scripts/test_migration.sh test       # Протестировать миграцию
./scripts/test_migration.sh performance # Тест производительности
./scripts/test_migration.sh cleanup    # Очистить тестовую БД
```

### Этап 3: Применение миграции в продакшн

```bash
# 1. Предварительный просмотр (dry-run)
./scripts/migrate_production.sh dry-run

# 2. Применение миграции
./scripts/migrate_production.sh apply

# 3. Валидация результатов
./scripts/migrate_production.sh validate

# 4. Проверка статуса
./scripts/migrate_production.sh status
```

## Детальные инструкции

### 1. Создание бэкапа

```bash
# Автоматический бэкап (выполняется скриптом миграции)
./scripts/migrate_production.sh apply

# Ручной бэкап
mysqldump -u username -p --single-transaction --routines --triggers database_name > backup_$(date +%Y%m%d_%H%M%S).sql
gzip backup_*.sql
```

### 2. Проверка готовности системы

```bash
# Проверка подключения к БД
mysql -u username -p -e "SELECT 1;" database_name

# Проверка свободного места
df -h /var/lib/mysql
df -h /backup

# Проверка текущего состояния БД
mysql -u username -p database_name -e "
SELECT table_name, table_rows,
       ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
FROM information_schema.tables
WHERE table_schema = 'database_name'
ORDER BY table_rows DESC;
"
```

### 3. Мониторинг процесса миграции

```bash
# Следите за логами миграции
tail -f /var/log/inventory_sync/migration.log

# Проверка процессов MySQL
mysql -u username -p -e "SHOW PROCESSLIST;"

# Мониторинг использования ресурсов
top
iostat -x 1
```

## Откат миграции

### Автоматический откат

```bash
# Откат с помощью скрипта
./scripts/migrate_production.sh rollback
```

### Ручной откат

```bash
# Восстановление из бэкапа
mysql -u username -p database_name < backup_file.sql

# Или использование rollback скрипта
mysql -u username -p database_name < migrations/rollback_production_migration.sql
```

## Валидация миграции

### Автоматическая валидация

```bash
./scripts/migrate_production.sh validate
```

### Ручная проверка

```sql
-- Проверка структуры таблицы inventory_data
DESCRIBE inventory_data;

-- Проверка новых полей
SELECT
    COUNT(*) as total_records,
    COUNT(warehouse_name) as with_warehouse,
    COUNT(stock_type) as with_stock_type,
    COUNT(quantity_present) as with_quantity,
    COUNT(last_sync_at) as with_sync_time
FROM inventory_data;

-- Проверка таблицы sync_logs
SELECT COUNT(*) FROM sync_logs;

-- Проверка индексов
SHOW INDEX FROM inventory_data;
SHOW INDEX FROM sync_logs;
```

## Возможные проблемы и решения

### Проблема: Недостаточно места на диске

**Симптомы:**

- Ошибка "No space left on device"
- Миграция прерывается

**Решение:**

```bash
# Очистите временные файлы
sudo rm -rf /tmp/mysql*
sudo rm -rf /var/tmp/*

# Проверьте логи MySQL
sudo du -sh /var/log/mysql/

# Освободите место в директории данных MySQL
sudo mysql_logs_cleanup.sh
```

### Проблема: Блокировка таблиц

**Симптомы:**

- Миграция "зависает"
- Ошибки "Lock wait timeout exceeded"

**Решение:**

```sql
-- Проверьте активные блокировки
SHOW ENGINE INNODB STATUS;

-- Найдите блокирующие процессы
SELECT * FROM information_schema.INNODB_LOCKS;

-- Завершите блокирующие процессы (осторожно!)
KILL <process_id>;
```

### Проблема: Ошибки валидации

**Симптомы:**

- Валидация показывает FAIL
- Данные не мигрированы корректно

**Решение:**

```bash
# Проверьте детали ошибок
./scripts/migrate_production.sh validate

# Проверьте логи
tail -100 /var/log/inventory_sync/migration.log

# При необходимости выполните откат и повторите
./scripts/migrate_production.sh rollback
./scripts/migrate_production.sh apply
```

## Время выполнения

### Ожидаемое время миграции

| Размер БД | Время миграции | Время бэкапа |
| --------- | -------------- | ------------ |
| < 1 GB    | 2-5 минут      | 1-2 минуты   |
| 1-10 GB   | 5-15 минут     | 3-10 минут   |
| 10-100 GB | 15-60 минут    | 10-30 минут  |
| > 100 GB  | 1-3 часа       | 30-90 минут  |

### Факторы, влияющие на время:

- Размер таблицы inventory_data
- Скорость дисковой подсистемы
- Загрузка сервера БД
- Количество индексов

## Контрольный список миграции

### Перед миграцией

- [ ] Создан полный бэкап БД
- [ ] Протестирована миграция на копии данных
- [ ] Уведомлены пользователи о техническом обслуживании
- [ ] Проверено свободное место на диске
- [ ] Настроены переменные окружения
- [ ] Проверены права доступа к БД

### Во время миграции

- [ ] Запущен мониторинг процесса
- [ ] Отслеживаются логи миграции
- [ ] Проверяется использование ресурсов
- [ ] Готов план отката при проблемах

### После миграции

- [ ] Выполнена валидация миграции
- [ ] Проверена работоспособность приложения
- [ ] Протестирована синхронизация остатков
- [ ] Настроены новые cron задачи
- [ ] Обновлена документация
- [ ] Уведомлены пользователи о завершении работ

## Планирование окна обслуживания

### Рекомендуемое время

- Выберите время минимальной активности пользователей
- Запланируйте 2-3x от ожидаемого времени миграции
- Учтите время на откат в случае проблем

### Пример планирования для БД 10GB:

```
20:00 - Начало окна обслуживания
20:00-20:15 - Создание бэкапа (15 мин)
20:15-20:30 - Применение миграции (15 мин)
20:30-20:40 - Валидация и тестирование (10 мин)
20:40-21:00 - Резерв времени на проблемы (20 мин)
21:00 - Завершение окна обслуживания
```

## Контакты и поддержка

### В случае проблем:

1. Проверьте логи: `/var/log/inventory_sync/migration.log`
2. Выполните диагностику: `./scripts/migrate_production.sh status`
3. При критических проблемах выполните откат: `./scripts/migrate_production.sh rollback`
4. Обратитесь к команде разработки с детальным описанием проблемы

### Полезные команды для диагностики:

```bash
# Проверка статуса MySQL
sudo systemctl status mysql

# Проверка логов MySQL
sudo tail -f /var/log/mysql/error.log

# Проверка использования дискового пространства
df -h

# Проверка процессов MySQL
mysql -e "SHOW PROCESSLIST;"

# Проверка статуса репликации (если используется)
mysql -e "SHOW SLAVE STATUS\G"
```
