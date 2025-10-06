# Руководство по устранению неполадок системы синхронизации остатков

## Общие проблемы и решения

### 1. Проблемы с подключением к API

#### Ошибка: "API connection timeout"

**Симптомы:**

- Синхронизация прерывается с ошибкой таймаута
- В логах: `requests.exceptions.Timeout`

**Причины:**

- Медленное интернет-соединение
- Перегрузка API маркетплейса
- Неправильные настройки таймаута

**Решения:**

1. Увеличьте таймаут в `config.py`:

   ```python
   API_TIMEOUT = 60  # увеличить с 30 до 60 секунд
   ```

2. Проверьте интернет-соединение:

   ```bash
   ping api.ozon.ru
   ping suppliers-api.wildberries.ru
   ```

3. Проверьте статус API маркетплейсов:
   ```bash
   curl -I https://api.ozon.ru/v1/ping
   ```

#### Ошибка: "Invalid API credentials"

**Симптомы:**

- HTTP 401 Unauthorized
- В логах: "Authentication failed"

**Решения:**

1. Проверьте API ключи в `.env`:

   ```bash
   grep -E "(OZON_|WB_)" .env
   ```

2. Убедитесь, что ключи не содержат лишних пробелов:

   ```python
   # Добавьте .strip() при чтении переменных
   OZON_API_KEY = os.getenv('OZON_API_KEY', '').strip()
   ```

3. Проверьте срок действия ключей в личных кабинетах маркетплейсов

4. Протестируйте ключи отдельно:
   ```bash
   python test_api_credentials.py
   ```

#### Ошибка: "Rate limit exceeded"

**Симптомы:**

- HTTP 429 Too Many Requests
- Синхронизация замедляется или останавливается

**Решения:**

1. Увеличьте задержки между запросами:

   ```python
   API_RETRY_DELAY = 10  # увеличить задержку
   MAX_CONCURRENT_REQUESTS = 2  # уменьшить параллельность
   ```

2. Реализуйте экспоненциальную задержку:

   ```python
   import time
   import random

   def exponential_backoff(attempt):
       delay = (2 ** attempt) + random.uniform(0, 1)
       time.sleep(min(delay, 60))  # максимум 60 секунд
   ```

### 2. Проблемы с базой данных

#### Ошибка: "Table 'inventory' doesn't exist"

**Симптомы:**

- `mysql.connector.errors.ProgrammingError: Table 'inventory' doesn't exist`
- Синхронизация не может записать данные

**Причина:**

- Код пытается использовать старое название таблицы

**Решение:**

1. Проверьте, что все запросы используют `inventory_data`:

   ```bash
   grep -r "inventory[^_]" *.py
   ```

2. Замените все вхождения:

   ```python
   # Неправильно
   cursor.execute("INSERT INTO inventory ...")

   # Правильно
   cursor.execute("INSERT INTO inventory_data ...")
   ```

#### Ошибка: "Access denied for user"

**Симптомы:**

- `mysql.connector.errors.ProgrammingError: Access denied`
- Не удается подключиться к БД

**Решения:**

1. Проверьте права пользователя:

   ```sql
   SHOW GRANTS FOR 'inventory_sync_user'@'localhost';
   ```

2. Предоставьте необходимые права:

   ```sql
   GRANT SELECT, INSERT, UPDATE, DELETE ON replenishment_db.* TO 'inventory_sync_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

3. Проверьте подключение:
   ```bash
   mysql -u inventory_sync_user -p -h localhost replenishment_db
   ```

#### Ошибка: "Duplicate entry for key"

**Симптомы:**

- `mysql.connector.errors.IntegrityError: Duplicate entry`
- Некоторые записи не обновляются

**Решение:**

Используйте `INSERT ... ON DUPLICATE KEY UPDATE`:

```sql
INSERT INTO inventory_data (product_id, source, current_stock, last_sync_at)
VALUES (%s, %s, %s, NOW())
ON DUPLICATE KEY UPDATE
    current_stock = VALUES(current_stock),
    last_sync_at = NOW()
```

### 3. Проблемы с Cron задачами

#### Проблема: Cron задачи не выполняются

**Диагностика:**

1. Проверьте статус cron:

   ```bash
   sudo systemctl status cron
   ```

2. Проверьте логи cron:

   ```bash
   sudo tail -f /var/log/syslog | grep CRON
   ```

3. Проверьте синтаксис crontab:
   ```bash
   crontab -l
   ```

**Решения:**

1. Используйте полные пути:

   ```cron
   # Неправильно
   0 */6 * * * python inventory_sync_service.py

   # Правильно
   0 */6 * * * /usr/bin/python3 /full/path/to/inventory_sync_service.py
   ```

2. Установите переменные окружения в crontab:

   ```cron
   PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
   PYTHONPATH=/path/to/project

   0 */6 * * * cd /path/to/project && /usr/bin/python3 inventory_sync_service.py
   ```

#### Проблема: Cron выполняется, но скрипт падает

**Диагностика:**

1. Проверьте логи скрипта:

   ```bash
   tail -f /var/log/inventory_sync/cron.log
   ```

2. Запустите скрипт вручную:
   ```bash
   cd /path/to/project
   /usr/bin/python3 inventory_sync_service.py --source=all
   ```

**Решения:**

1. Добавьте обработку ошибок в cron:

   ```cron
   0 */6 * * * cd /path/to/project && /usr/bin/python3 inventory_sync_service.py 2>&1 | tee -a /var/log/inventory_sync/cron.log
   ```

2. Создайте wrapper скрипт:

   ```bash
   #!/bin/bash
   # /usr/local/bin/run_inventory_sync.sh

   cd /path/to/project
   source .env
   /usr/bin/python3 inventory_sync_service.py --source=all

   if [ $? -ne 0 ]; then
       echo "$(date): Inventory sync failed" >> /var/log/inventory_sync/errors.log
   fi
   ```

### 4. Проблемы с данными

#### Проблема: Данные не обновляются

**Диагностика:**

1. Проверьте последнюю синхронизацию:

   ```sql
   SELECT source, MAX(last_sync_at) as last_sync
   FROM inventory_data
   GROUP BY source;
   ```

2. Проверьте логи синхронизации:
   ```sql
   SELECT * FROM sync_logs
   ORDER BY started_at DESC
   LIMIT 5;
   ```

**Решения:**

1. Запустите принудительную синхронизацию:

   ```bash
   python inventory_sync_service.py --force --source=all
   ```

2. Проверьте фильтры данных:
   ```python
   # Убедитесь, что не фильтруются нужные товары
   if product['stock'] > 0:  # может быть слишком строгий фильтр
       save_to_db(product)
   ```

#### Проблема: Неправильные остатки

**Диагностика:**

1. Сравните с данными в личном кабинете маркетплейса
2. Проверьте логику расчета остатков:

   ```python
   # Для Ozon
   available_stock = fbo_stock + fbs_stock + real_fbs_stock

   # Для WB
   available_stock = quantity - reserved_quantity
   ```

**Решения:**

1. Добавьте детальное логирование:

   ```python
   logger.info(f"Product {sku}: FBO={fbo}, FBS={fbs}, Total={total}")
   ```

2. Проверьте маппинг полей API:
   ```python
   # Убедитесь в правильности маппинга
   ozon_mapping = {
       'offer_id': 'sku',
       'fbo_present': 'fbo_stock',
       'fbs_present': 'fbs_stock'
   }
   ```

### 5. Проблемы с производительностью

#### Проблема: Медленная синхронизация

**Диагностика:**

1. Измерьте время выполнения:

   ```bash
   time python inventory_sync_service.py --source=ozon
   ```

2. Проверьте размер данных:
   ```sql
   SELECT COUNT(*) FROM inventory_data;
   ```

**Решения:**

1. Увеличьте размер батча:

   ```python
   BATCH_SIZE = 2000  # увеличить с 1000
   ```

2. Добавьте индексы:

   ```sql
   CREATE INDEX idx_product_source_sync ON inventory_data(product_id, source, last_sync_at);
   ```

3. Используйте параллельную обработку:

   ```python
   from concurrent.futures import ThreadPoolExecutor

   with ThreadPoolExecutor(max_workers=3) as executor:
       futures = [
           executor.submit(sync_ozon_inventory),
           executor.submit(sync_wb_inventory)
       ]
   ```

#### Проблема: Высокое использование памяти

**Решения:**

1. Обрабатывайте данные по частям:

   ```python
   def process_in_chunks(data, chunk_size=1000):
       for i in range(0, len(data), chunk_size):
           yield data[i:i + chunk_size]
   ```

2. Освобождайте память:

   ```python
   import gc

   def sync_inventory():
       data = fetch_api_data()
       process_data(data)
       del data  # явно удалить
       gc.collect()  # принудительная сборка мусора
   ```

### 6. Проблемы с уведомлениями

#### Проблема: Email уведомления не отправляются

**Диагностика:**

1. Проверьте SMTP настройки:

   ```bash
   telnet smtp.gmail.com 587
   ```

2. Протестируйте отправку:
   ```python
   python test_email_notifications.py
   ```

**Решения:**

1. Проверьте настройки Gmail:

   - Включите двухфакторную аутентификацию
   - Создайте пароль приложения
   - Используйте пароль приложения вместо основного пароля

2. Добавьте обработку ошибок SMTP:
   ```python
   try:
       send_email(subject, body)
   except smtplib.SMTPAuthenticationError:
       logger.error("SMTP authentication failed")
   except smtplib.SMTPException as e:
       logger.error(f"SMTP error: {e}")
   ```

## Диагностические команды

### Проверка состояния системы

```bash
# Полная диагностика
python diagnostic_tool.py --full-check

# Проверка API подключений
python diagnostic_tool.py --check-api

# Проверка БД
python diagnostic_tool.py --check-db

# Проверка cron задач
python diagnostic_tool.py --check-cron
```

### Полезные SQL запросы

```sql
-- Статистика синхронизации за последние 24 часа
SELECT
    source,
    COUNT(*) as sync_count,
    AVG(duration_seconds) as avg_duration,
    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count
FROM sync_logs
WHERE started_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY source;

-- Товары с устаревшими данными
SELECT
    product_id,
    sku,
    source,
    last_sync_at,
    TIMESTAMPDIFF(HOUR, last_sync_at, NOW()) as hours_old
FROM inventory_data
WHERE last_sync_at < DATE_SUB(NOW(), INTERVAL 8 HOUR)
ORDER BY last_sync_at;

-- Аномалии в остатках
SELECT
    product_id,
    sku,
    source,
    current_stock,
    reserved_stock,
    last_sync_at
FROM inventory_data
WHERE current_stock < 0 OR reserved_stock < 0 OR current_stock > 10000
ORDER BY last_sync_at DESC;
```

## Мониторинг и алерты

### Настройка мониторинга

Создайте скрипт мониторинга `/usr/local/bin/monitor_inventory_sync.sh`:

```bash
#!/bin/bash

LOG_FILE="/var/log/inventory_sync/monitor.log"
ALERT_EMAIL="admin@yourcompany.com"

# Проверка последней синхронизации
LAST_SYNC=$(mysql -u inventory_sync_user -p"$DB_PASSWORD" -D replenishment_db -se "
    SELECT MAX(started_at) FROM sync_logs WHERE status = 'success'
")

if [ -z "$LAST_SYNC" ]; then
    echo "$(date): ERROR - No successful sync found" >> $LOG_FILE
    echo "No successful inventory sync found" | mail -s "Inventory Sync Alert" $ALERT_EMAIL
fi

# Проверка количества товаров
PRODUCT_COUNT=$(mysql -u inventory_sync_user -p"$DB_PASSWORD" -D replenishment_db -se "
    SELECT COUNT(*) FROM inventory_data WHERE last_sync_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
")

if [ "$PRODUCT_COUNT" -lt 10 ]; then
    echo "$(date): WARNING - Low product count: $PRODUCT_COUNT" >> $LOG_FILE
    echo "Low product count in inventory: $PRODUCT_COUNT" | mail -s "Inventory Count Alert" $ALERT_EMAIL
fi
```

### Настройка логротации

Создайте `/etc/logrotate.d/inventory_sync`:

```
/var/log/inventory_sync/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 644 inventory_sync inventory_sync
    postrotate
        /usr/bin/systemctl reload rsyslog > /dev/null 2>&1 || true
    endscript
}
```

## Восстановление после сбоев

### Процедура восстановления

1. **Остановите все cron задачи:**

   ```bash
   crontab -r  # временно удалить все задачи
   ```

2. **Проверьте целостность данных:**

   ```sql
   SELECT COUNT(*) FROM inventory_data WHERE last_sync_at IS NULL;
   ```

3. **Очистите поврежденные данные:**

   ```sql
   DELETE FROM inventory_data WHERE current_stock IS NULL OR current_stock < 0;
   ```

4. **Запустите полную пересинхронизацию:**

   ```bash
   python inventory_sync_service.py --full-resync --source=all
   ```

5. **Восстановите cron задачи:**
   ```bash
   crontab -e  # восстановить задачи
   ```

### Резервное копирование

Создайте скрипт резервного копирования:

```bash
#!/bin/bash
# /usr/local/bin/backup_inventory_data.sh

BACKUP_DIR="/backup/inventory_sync"
DATE=$(date +%Y%m%d_%H%M%S)

mkdir -p $BACKUP_DIR

mysqldump -u inventory_sync_user -p"$DB_PASSWORD" replenishment_db inventory_data sync_logs > $BACKUP_DIR/inventory_backup_$DATE.sql

# Сжать и удалить старые бэкапы
gzip $BACKUP_DIR/inventory_backup_$DATE.sql
find $BACKUP_DIR -name "*.sql.gz" -mtime +7 -delete
```

## Контакты для поддержки

При критических проблемах:

1. Проверьте логи: `/var/log/inventory_sync/`
2. Запустите диагностику: `python diagnostic_tool.py --full-check`
3. Обратитесь к команде разработки с детальным описанием проблемы и логами
