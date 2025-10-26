# Ozon ETL Scheduler System

Система планировщика задач для автоматизации ETL процессов Ozon marketplace.

## 🚀 Быстрый старт

### Проверка установки

```bash
php src/ETL/Ozon/Scripts/test_scheduler.php
```

### Просмотр статуса cron задач

```bash
php src/ETL/Ozon/Scripts/manage_cron.php status --verbose
```

### Мониторинг процессов

```bash
php src/ETL/Ozon/Scripts/process_monitor.php status --verbose
```

## 📅 Расписание выполнения

| Процесс          | Время                         | Описание                             |
| ---------------- | ----------------------------- | ------------------------------------ |
| `sync_products`  | 02:15 ежедневно               | Синхронизация каталога товаров       |
| `sync_sales`     | 03:15 ежедневно               | Извлечение истории продаж за 30 дней |
| `sync_inventory` | 04:15 ежедневно               | Обновление складских остатков        |
| `health_check`   | Каждые 15 минут               | Мониторинг состояния системы         |
| `monitor_etl`    | Каждые 30 минут (9-18, пн-пт) | Мониторинг ETL процессов и алерты    |
| `daily_summary`  | 20:00 ежедневно               | Отправка ежедневного отчета          |
| `log_cleanup`    | 01:30 ежедневно               | Очистка старых логов (30+ дней)      |

## 🛠 Управление

### Установка/обновление cron задач

```bash
# Безопасное обновление (сохраняет существующие задачи)
php src/ETL/Ozon/Scripts/update_cron_safe.php --verbose --backup

# Предварительный просмотр изменений
php src/ETL/Ozon/Scripts/update_cron_safe.php --dry-run --verbose
```

### Управление процессами

```bash
# Просмотр активных процессов
php src/ETL/Ozon/Scripts/process_monitor.php status

# Завершение процесса
php src/ETL/Ozon/Scripts/process_monitor.php kill sync_products

# Принудительное завершение
php src/ETL/Ozon/Scripts/process_monitor.php kill sync_products --force

# Очистка устаревших блокировок
php src/ETL/Ozon/Scripts/process_monitor.php cleanup --verbose
```

### Мониторинг и алерты

```bash
# Проверка на ошибки
php src/ETL/Ozon/Scripts/monitor_etl.php --check-failures

# Проверка производительности
php src/ETL/Ozon/Scripts/monitor_etl.php --check-performance

# Проверка состояния системы
php src/ETL/Ozon/Scripts/monitor_etl.php --check-health

# Отправка ежедневного отчета
php src/ETL/Ozon/Scripts/monitor_etl.php --send-summary

# Полная проверка
php src/ETL/Ozon/Scripts/monitor_etl.php --all
```

## 📁 Структура файлов

```
src/ETL/Ozon/
├── Scripts/                    # Исполняемые скрипты
│   ├── sync_products.php      # Синхронизация товаров
│   ├── sync_sales.php         # Синхронизация продаж
│   ├── sync_inventory.php     # Синхронизация остатков
│   ├── health_check.php       # Проверка здоровья системы
│   ├── monitor_etl.php        # Мониторинг и алерты
│   ├── process_monitor.php    # Управление процессами
│   ├── manage_cron.php        # Управление cron задачами
│   ├── update_cron_safe.php   # Безопасное обновление cron
│   ├── install_scheduler.php  # Установка планировщика
│   └── test_scheduler.php     # Тестирование системы
├── Config/                     # Конфигурационные файлы
│   ├── cron_config.php        # Конфигурация планировщика
│   └── ozon_etl.crontab       # Шаблон crontab
├── Core/                       # Основные классы
│   ├── ProcessLock.php        # Блокировка процессов
│   ├── ProcessManager.php     # Управление процессами
│   ├── AlertManager.php       # Система алертов
│   └── SimpleLogger.php       # Простое логирование
└── Logs/                       # Логи и временные файлы
    ├── cron_jobs/             # Логи cron задач
    ├── locks/                 # Файлы блокировок
    └── pids/                  # PID файлы
```

## 🔧 Конфигурация

### Переменные окружения

```bash
# Основные настройки
ETL_MEMORY_LIMIT=512M
ETL_TIME_LIMIT=3600
ETL_CRON_LOG_PATH=/var/log/ozon_etl_cron.log

# Уведомления
ETL_NOTIFICATIONS_ENABLED=true
ETL_ALERT_THROTTLE_MINUTES=15

# Email уведомления
ETL_EMAIL_NOTIFICATIONS=true
ETL_FROM_EMAIL=etl@company.com
ETL_TO_EMAILS=admin@company.com,dev@company.com

# Slack уведомления
ETL_SLACK_NOTIFICATIONS=true
SLACK_WEBHOOK_URL=https://hooks.slack.com/services/...
SLACK_CHANNEL=#etl-alerts

# Webhook уведомления
ETL_WEBHOOK_NOTIFICATIONS=false
ETL_WEBHOOK_URL=https://api.company.com/webhooks/etl

# Ежедневные отчеты
ETL_DAILY_SUMMARY_ENABLED=true
ETL_DAILY_SUMMARY_TIME=20:00
```

### Пороги мониторинга

Настройки в `src/ETL/Ozon/Config/cron_config.php`:

```php
'monitoring' => [
    'max_execution_time' => [
        'sync_products' => 1800,   // 30 минут
        'sync_sales' => 2400,      // 40 минут
        'sync_inventory' => 3600,  // 60 минут
        'health_check' => 300      // 5 минут
    ],
    'alert_thresholds' => [
        'consecutive_failures' => 3,
        'execution_time_multiplier' => 2.0,
        'memory_usage_mb' => 1024
    ]
]
```

## 📊 Логирование

### Расположение логов

-   **Cron задачи**: `src/ETL/Ozon/Logs/cron_jobs/`
-   **Системные логи**: `src/ETL/Ozon/Logs/`
-   **Блокировки**: `src/ETL/Ozon/Logs/locks/`
-   **PID файлы**: `src/ETL/Ozon/Logs/pids/`

### Просмотр логов

```bash
# Последние логи синхронизации товаров
tail -f src/ETL/Ozon/Logs/cron_jobs/sync_products_$(date +%Y%m%d).log

# Логи мониторинга
tail -f src/ETL/Ozon/Logs/cron_jobs/monitor_etl_$(date +%Y%m%d).log

# Все логи за сегодня
ls -la src/ETL/Ozon/Logs/cron_jobs/*$(date +%Y%m%d).log
```

## 🚨 Устранение неполадок

### Процесс завис

```bash
# Проверить активные процессы
php src/ETL/Ozon/Scripts/process_monitor.php status

# Завершить зависший процесс
php src/ETL/Ozon/Scripts/process_monitor.php kill <process_name> --force

# Очистить устаревшие блокировки
php src/ETL/Ozon/Scripts/process_monitor.php cleanup
```

### Cron задачи не выполняются

```bash
# Проверить статус cron
php src/ETL/Ozon/Scripts/manage_cron.php status

# Переустановить cron задачи
php src/ETL/Ozon/Scripts/update_cron_safe.php --backup

# Проверить логи cron
sudo tail -f /var/log/cron
```

### Проблемы с уведомлениями

```bash
# Тестировать систему алертов
php src/ETL/Ozon/Scripts/monitor_etl.php --check-health --verbose

# Проверить конфигурацию уведомлений
grep -r "notifications" src/ETL/Ozon/Config/
```

## 📈 Мониторинг производительности

### Ключевые метрики

-   **Время выполнения**: Отслеживается для каждого процесса
-   **Использование памяти**: Пиковое потребление памяти
-   **Количество обработанных записей**: Производительность обработки
-   **Частота ошибок**: Количество неудачных выполнений

### Алерты

-   Превышение времени выполнения (2x от нормального)
-   Высокое потребление памяти (>1GB)
-   Последовательные сбои (3+ раза подряд)
-   Проблемы с системными ресурсами

## 🔄 Обновление системы

### Безопасное обновление

```bash
# 1. Создать резервную копию текущих cron задач
crontab -l > /tmp/crontab_backup_$(date +%Y%m%d).txt

# 2. Обновить систему планировщика
php src/ETL/Ozon/Scripts/update_cron_safe.php --backup --verbose

# 3. Проверить результат
php src/ETL/Ozon/Scripts/test_scheduler.php
```

### Откат изменений

```bash
# Восстановить из резервной копии
crontab /tmp/crontab_backup_YYYYMMDD.txt
```

---

## 📞 Поддержка

При возникновении проблем:

1. **Проверьте логи**: `src/ETL/Ozon/Logs/cron_jobs/`
2. **Запустите тест**: `php src/ETL/Ozon/Scripts/test_scheduler.php`
3. **Проверьте статус**: `php src/ETL/Ozon/Scripts/process_monitor.php status`
4. **Очистите блокировки**: `php src/ETL/Ozon/Scripts/process_monitor.php cleanup`

Система планировщика обеспечивает надежное выполнение ETL процессов с автоматическим мониторингом и уведомлениями о проблемах.
