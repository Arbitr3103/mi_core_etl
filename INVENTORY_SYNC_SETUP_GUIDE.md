# Руководство по настройке системы синхронизации остатков

## Обзор

Данное руководство описывает процесс установки и настройки системы синхронизации остатков товаров с маркетплейсами Ozon и Wildberries.

## Требования к системе

### Программное обеспечение

- Python 3.8+
- MySQL 8.0+
- Cron (для автоматизации)
- Git

### Python пакеты

```bash
pip install -r requirements.txt
```

Основные зависимости:

- requests>=2.28.0
- mysql-connector-python>=8.0.0
- python-dotenv>=0.19.0
- schedule>=1.1.0

## Установка и конфигурация

### 1. Клонирование репозитория

```bash
git clone <repository-url>
cd <project-directory>
```

### 2. Настройка переменных окружения

Создайте файл `.env` на основе `.env.example`:

```bash
cp .env.example .env
```

Заполните необходимые переменные:

```env
# Database Configuration
DB_HOST=localhost
DB_PORT=3306
DB_NAME=replenishment_db
DB_USER=inventory_sync_user
DB_PASSWORD=secure_password

# Ozon API Configuration
OZON_CLIENT_ID=your_ozon_client_id
OZON_API_KEY=your_ozon_api_key
OZON_WAREHOUSE_ID=your_warehouse_id

# Wildberries API Configuration
WB_API_KEY=your_wb_api_key
WB_SUPPLIER_ID=your_supplier_id

# Email Configuration (для уведомлений)
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=notifications@yourcompany.com
SMTP_PASSWORD=app_password
ALERT_EMAIL=admin@yourcompany.com

# Logging Configuration
LOG_LEVEL=INFO
LOG_FILE_PATH=/var/log/inventory_sync/
```

### 3. Настройка базы данных

#### Создание пользователя БД

```sql
-- Подключитесь к MySQL как root
CREATE USER 'inventory_sync_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON replenishment_db.* TO 'inventory_sync_user'@'localhost';
FLUSH PRIVILEGES;
```

#### Применение миграций

```bash
# Выполните миграционные скрипты в правильном порядке
mysql -u inventory_sync_user -p replenishment_db < migrations/inventory_sync_schema_update.sql
mysql -u inventory_sync_user -p replenishment_db < migrations/add_sync_logs_table.sql
```

### 4. Проверка подключения к API

Протестируйте подключение к API маркетплейсов:

```bash
python test_api_connections.py
```

Ожидаемый вывод:

```
✓ Ozon API connection successful
✓ Wildberries API connection successful
✓ Database connection successful
```

## Настройка Cron задач

### Создание cron расписания

Откройте crontab для редактирования:

```bash
crontab -e
```

Добавьте следующие задачи:

```cron
# Синхронизация остатков каждые 6 часов
0 */6 * * * /usr/bin/python3 /path/to/project/inventory_sync_service.py --source=all >> /var/log/inventory_sync/cron.log 2>&1

# Синхронизация только Ozon каждые 4 часа (альтернативный вариант)
0 */4 * * * /usr/bin/python3 /path/to/project/inventory_sync_service.py --source=ozon >> /var/log/inventory_sync/ozon_cron.log 2>&1

# Синхронизация только Wildberries каждые 4 часа
30 */4 * * * /usr/bin/python3 /path/to/project/inventory_sync_service.py --source=wb >> /var/log/inventory_sync/wb_cron.log 2>&1

# Еженедельная полная пересинхронизация (воскресенье в 2:00)
0 2 * * 0 /usr/bin/python3 /path/to/project/inventory_sync_service.py --full-resync >> /var/log/inventory_sync/weekly_resync.log 2>&1

# Проверка здоровья системы каждый час
0 * * * * /usr/bin/python3 /path/to/project/sync_monitor.py --health-check >> /var/log/inventory_sync/health.log 2>&1

# Еженедельный отчет (понедельник в 9:00)
0 9 * * 1 /usr/bin/python3 /path/to/project/sync_monitor.py --weekly-report >> /var/log/inventory_sync/reports.log 2>&1
```

### Альтернативные варианты расписания

#### Для высоконагруженных систем:

```cron
# Более частая синхронизация каждые 2 часа
0 */2 * * * /usr/bin/python3 /path/to/project/inventory_sync_service.py --source=all
```

#### Для систем с ограниченными ресурсами:

```cron
# Синхронизация 2 раза в день
0 8,20 * * * /usr/bin/python3 /path/to/project/inventory_sync_service.py --source=all
```

### Настройка логирования для cron

Создайте директорию для логов:

```bash
sudo mkdir -p /var/log/inventory_sync
sudo chown $USER:$USER /var/log/inventory_sync
```

Настройте ротацию логов в `/etc/logrotate.d/inventory_sync`:

```
/var/log/inventory_sync/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 644 inventory_sync inventory_sync
}
```

## Мониторинг и алерты

### Настройка email уведомлений

Убедитесь, что SMTP настройки корректны в `.env` файле.

Протестируйте отправку уведомлений:

```bash
python test_email_notifications.py
```

### Настройка мониторинга

Создайте скрипт для проверки состояния системы:

```bash
#!/bin/bash
# /usr/local/bin/check_inventory_sync.sh

LOG_FILE="/var/log/inventory_sync/health_check.log"
PYTHON_PATH="/usr/bin/python3"
PROJECT_PATH="/path/to/project"

echo "$(date): Starting inventory sync health check" >> $LOG_FILE

# Проверка последней синхронизации
$PYTHON_PATH $PROJECT_PATH/sync_monitor.py --check-last-sync >> $LOG_FILE 2>&1

# Проверка аномалий в данных
$PYTHON_PATH $PROJECT_PATH/sync_monitor.py --detect-anomalies >> $LOG_FILE 2>&1

echo "$(date): Health check completed" >> $LOG_FILE
```

Сделайте скрипт исполняемым:

```bash
chmod +x /usr/local/bin/check_inventory_sync.sh
```

## Параметры конфигурации

### Настройки синхронизации

В файле `config.py` можно настроить следующие параметры:

```python
# Таймауты API запросов
API_TIMEOUT = 30  # секунд
API_RETRY_COUNT = 3
API_RETRY_DELAY = 5  # секунд

# Размеры батчей для обработки
BATCH_SIZE = 1000  # записей за раз
MAX_CONCURRENT_REQUESTS = 5

# Пороги для алертов
MAX_SYNC_AGE_HOURS = 8  # максимальный возраст данных
MIN_PRODUCTS_THRESHOLD = 10  # минимальное количество товаров
ANOMALY_THRESHOLD = 0.1  # 10% изменение для детекции аномалий
```

### Настройки производительности

```python
# Оптимизация БД
DB_POOL_SIZE = 10
DB_POOL_RECYCLE = 3600  # секунд

# Кэширование
CACHE_TTL = 300  # 5 минут
ENABLE_CACHE = True
```

## Безопасность

### Защита API ключей

1. Никогда не коммитьте `.env` файл в репозиторий
2. Используйте права доступа 600 для `.env` файла:
   ```bash
   chmod 600 .env
   ```
3. Регулярно ротируйте API ключи (рекомендуется каждые 90 дней)

### Права доступа к файлам

```bash
# Установите правильные права доступа
chmod 755 inventory_sync_service.py
chmod 755 sync_monitor.py
chmod 600 .env
chmod 644 config.py
```

### Настройка firewall

Если используется внешняя БД, настройте firewall:

```bash
# Разрешить подключения к MySQL только с локального хоста
sudo ufw allow from 127.0.0.1 to any port 3306
```

## Проверка установки

### Контрольный список

- [ ] Python 3.8+ установлен
- [ ] Все зависимости установлены (`pip install -r requirements.txt`)
- [ ] Файл `.env` создан и заполнен
- [ ] База данных настроена и миграции применены
- [ ] API подключения протестированы
- [ ] Cron задачи настроены
- [ ] Логирование работает
- [ ] Email уведомления настроены
- [ ] Права доступа к файлам установлены

### Тестовый запуск

Выполните тестовую синхронизацию:

```bash
python inventory_sync_service.py --test-mode --source=ozon
```

Проверьте логи:

```bash
tail -f /var/log/inventory_sync/cron.log
```

### Проверка данных в БД

```sql
-- Проверьте, что данные синхронизируются
SELECT
    source,
    COUNT(*) as products_count,
    MAX(last_sync_at) as last_sync
FROM inventory_data
WHERE last_sync_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
GROUP BY source;

-- Проверьте логи синхронизации
SELECT
    source,
    status,
    records_processed,
    started_at
FROM sync_logs
ORDER BY started_at DESC
LIMIT 10;
```

## Следующие шаги

После успешной установки:

1. Мониторьте логи в течение первых 24 часов
2. Проверьте корректность данных в дашборде
3. Настройте дополнительные алерты при необходимости
4. Документируйте любые специфичные для вашей среды настройки

## Поддержка

При возникновении проблем:

1. Проверьте логи в `/var/log/inventory_sync/`
2. Убедитесь, что все API ключи актуальны
3. Проверьте подключение к базе данных
4. Обратитесь к разделу "Устранение неполадок" ниже
