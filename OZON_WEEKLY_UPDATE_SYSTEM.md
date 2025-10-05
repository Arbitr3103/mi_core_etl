# Система еженедельного обновления Ozon Analytics

Комплексная система для автоматического еженедельного обновления аналитических данных Ozon с инкрементальной загрузкой, логированием и уведомлениями.

## 📋 Обзор системы

Система состоит из следующих компонентов:

### Основные файлы

- `ozon_weekly_update.py` - Основной скрипт обновления данных
- `run_ozon_weekly_update.sh` - Bash скрипт для cron запуска
- `ozon_update_monitor.py` - Система мониторинга и анализа
- `test_ozon_weekly_update.py` - Тестовый набор
- `setup_ozon_weekly_update.sh` - Скрипт автоматической установки

### Конфигурационные файлы

- `ozon_update_config.py` - Шаблон конфигурации
- `config.py` - Рабочий файл конфигурации (создается при установке)

## 🚀 Быстрый старт

### 1. Установка системы

```bash
# Запуск автоматической установки
./setup_ozon_weekly_update.sh
```

### 2. Настройка конфигурации

```bash
# Отредактируйте файл конфигурации
nano config.py

# Проверьте конфигурацию
python3 ozon_update_config.py
```

### 3. Тестовый запуск

```bash
# Запуск тестов
./test_ozon_weekly_update.py

# Ручной запуск обновления
./run_ozon_weekly_update.sh
```

### 4. Настройка автоматического запуска

```bash
# Добавление в crontab (каждое воскресенье в 02:00)
crontab -e
# Добавьте строку:
0 2 * * 0 /path/to/run_ozon_weekly_update.sh
```

## ⚙️ Подробная настройка

### Конфигурация базы данных

```python
# config.py
DB_HOST = 'localhost'
DB_USER = 'your_db_user'
DB_PASSWORD = 'your_db_password'
DB_NAME = 'manhattan'
DB_PORT = 3306
```

### Настройки Ozon API

```python
# Получите в личном кабинете продавца Ozon
OZON_CLIENT_ID = 'your_client_id'
OZON_API_KEY = 'your_api_key'

# Настройки запросов
OZON_API_TIMEOUT = 30
OZON_RATE_LIMIT_DELAY = 1.0
OZON_MAX_RETRIES = 3
```

### Email уведомления

```python
# SMTP настройки
SMTP_SERVER = 'smtp.gmail.com'
SMTP_PORT = 587
SMTP_USER = 'your_email@gmail.com'
SMTP_PASSWORD = 'your_app_password'

# Получатели уведомлений
ADMIN_EMAILS = [
    'admin@yourcompany.com',
    'manager@yourcompany.com'
]
```

### Параметры обновления

```python
LOOKBACK_DAYS = 14          # Дней назад при первом запуске
MAX_DATE_RANGE_DAYS = 90    # Максимальный диапазон за запрос
BATCH_SIZE = 100            # Размер пакета данных
CACHE_TIMEOUT_HOURS = 1     # Время жизни кэша
```

## 🔄 Принцип работы

### Инкрементальное обновление

1. **Определение периода**: Система определяет даты последнего обновления для каждого типа данных
2. **Расчет периодов**: Вычисляются недельные периоды для обновления
3. **Загрузка данных**: Данные загружаются порциями с соблюдением rate limits
4. **Сохранение**: Новые данные заменяют существующие за тот же период

### Типы обновляемых данных

- **Данные воронки продаж** (`ozon_funnel_data`)

  - Просмотры товаров
  - Добавления в корзину
  - Оформленные заказы
  - Конверсии между этапами

- **Демографические данные** (`ozon_demographics`)

  - Возрастные группы покупателей
  - Гендерное распределение
  - Географическое распределение

- **Данные рекламных кампаний** (`ozon_campaigns`)
  - Показы и клики
  - Расходы и доходы
  - CTR, CPC, ROAS

## 📊 Мониторинг и анализ

### Проверка статуса

```bash
# Общий статус системы
python3 ozon_update_monitor.py --status

# Подробный отчет
python3 ozon_update_monitor.py --report text

# JSON отчет для интеграции
python3 ozon_update_monitor.py --report json

# Проверка алертов
python3 ozon_update_monitor.py --alerts
```

### Анализ логов

```bash
# Анализ логов за 7 дней
python3 ozon_update_monitor.py --logs 7

# Анализ за месяц
python3 ozon_update_monitor.py --logs 30
```

### Структура логов

```
logs/
├── ozon_update_20240115_020000.log    # Детальные логи обновления
├── cron_ozon_update.log               # Логи cron запусков
└── ozon_monitor_20240115.log          # Логи мониторинга
```

## 📧 Система уведомлений

### Типы уведомлений

#### Успешное обновление ✅

```
Тема: ✅ Ozon Analytics - Еженедельное обновление выполнено успешно

Содержание:
- Время выполнения
- Количество обновленных записей по типам
- Предупреждения (если есть)
- Статистика производительности
```

#### Ошибка обновления ❌

```
Тема: ❌ Ozon Analytics - Ошибка еженедельного обновления

Содержание:
- Описание ошибок
- Частично обновленные данные
- Рекомендации по устранению
- Контактная информация
```

### Настройка уведомлений

```python
# Включение/отключение типов уведомлений
SEND_SUCCESS_NOTIFICATIONS = True
SEND_ERROR_NOTIFICATIONS = True
SEND_WARNING_NOTIFICATIONS = False

# Дополнительные каналы уведомлений
WEBHOOK_URL = 'https://hooks.slack.com/...'
TELEGRAM_BOT_TOKEN = 'your_bot_token'
TELEGRAM_CHAT_ID = 'your_chat_id'
```

## 🛠️ Устранение неполадок

### Частые проблемы

#### 1. Ошибка подключения к базе данных

```bash
# Проверьте подключение
mysql -h localhost -u your_user -p your_database

# Проверьте права пользователя
GRANT SELECT, INSERT, UPDATE, DELETE ON manhattan.* TO 'your_user'@'localhost';
```

#### 2. Ошибка аутентификации Ozon API

```bash
# Проверьте учетные данные в личном кабинете Ozon
# Убедитесь, что API ключи активны
# Проверьте лимиты API
```

#### 3. Проблемы с email уведомлениями

```bash
# Для Gmail используйте App Password вместо обычного пароля
# Проверьте настройки SMTP сервера
# Убедитесь, что порт 587 открыт
```

#### 4. Проблемы с cron

```bash
# Проверьте cron задачи
crontab -l

# Проверьте логи cron
tail -f /var/log/cron

# Убедитесь в правильности путей в crontab
```

### Диагностические команды

```bash
# Проверка зависимостей Python
python3 -c "import mysql.connector, requests; print('OK')"

# Проверка конфигурации
python3 ozon_update_config.py

# Тестирование подключения к БД
python3 -c "
import mysql.connector
from config import *
conn = mysql.connector.connect(
    host=DB_HOST, user=DB_USER,
    password=DB_PASSWORD, database=DB_NAME
)
print('Database connection: OK')
"

# Проверка API Ozon (без реальных запросов)
python3 test_ozon_weekly_update.py
```

## 📈 Производительность и оптимизация

### Рекомендуемые настройки

#### Для небольших объемов данных (< 1000 товаров)

```python
LOOKBACK_DAYS = 7
BATCH_SIZE = 50
OZON_RATE_LIMIT_DELAY = 0.5
```

#### Для средних объемов данных (1000-10000 товаров)

```python
LOOKBACK_DAYS = 14
BATCH_SIZE = 100
OZON_RATE_LIMIT_DELAY = 1.0
```

#### Для больших объемов данных (> 10000 товаров)

```python
LOOKBACK_DAYS = 21
BATCH_SIZE = 200
OZON_RATE_LIMIT_DELAY = 1.5
```

### Мониторинг производительности

```python
# Включение мониторинга производительности
ENABLE_PERFORMANCE_MONITORING = True
PERFORMANCE_LOG_SLOW_QUERIES = True
SLOW_QUERY_THRESHOLD_SECONDS = 5

# Алерты на долгое выполнение
ALERT_ON_LONG_EXECUTION = True
LONG_EXECUTION_THRESHOLD_MINUTES = 30
```

## 🔒 Безопасность

### Защита учетных данных

```python
# Шифрование API ключей в БД
ENCRYPT_API_KEYS = True
ENCRYPTION_KEY = 'your_32_character_encryption_key'

# Ограничение доступа по IP
ALLOWED_IP_RANGES = [
    '127.0.0.1/32',
    '10.0.0.0/8',
    '192.168.0.0/16'
]
```

### Рекомендации по безопасности

1. **Используйте отдельного пользователя БД** с минимальными правами
2. **Храните пароли в переменных окружения** вместо файлов конфигурации
3. **Регулярно ротируйте API ключи** Ozon
4. **Ограничьте доступ к файлам логов** (chmod 600)
5. **Используйте HTTPS** для всех внешних соединений

## 🧪 Тестирование

### Запуск тестов

```bash
# Полный набор тестов
./test_ozon_weekly_update.py

# Только unit тесты
python3 -m unittest test_ozon_weekly_update.TestOzonWeeklyUpdater

# Только интеграционные тесты
python3 -m unittest test_ozon_weekly_update.TestSystemIntegration
```

### Тестовый режим

```python
# В config.py для тестирования
DEBUG_MODE = True
TEST_MODE = True    # Не отправляет реальные API запросы
DRY_RUN = True     # Не сохраняет данные в БД
```

## 📚 API Reference

### OzonWeeklyUpdater

#### Основные методы

```python
updater = OzonWeeklyUpdater('config.py')

# Подключение к БД
success = updater.connect_database()

# Аутентификация в API
token = updater.authenticate_ozon_api(client_id, api_key)

# Получение данных воронки
data = updater.getFunnelData(date_from, date_to, filters)

# Получение демографических данных
data = updater.getDemographics(date_from, date_to, filters)

# Запуск полного обновления
success = updater.run_update()
```

### OzonUpdateMonitor

#### Основные методы

```python
monitor = OzonUpdateMonitor('config.py')

# Получение статуса
status = monitor.get_last_update_status()

# Анализ логов
analysis = monitor.analyze_logs(days=7)

# Генерация отчета
report = monitor.generate_report(format='text')

# Проверка алертов
alerts = monitor.check_alerts()
```

## 🔄 Интеграция с существующей системой

### Интеграция с дашбордом

Система автоматически сохраняет данные в таблицы:

- `ozon_funnel_data`
- `ozon_demographics`
- `ozon_campaigns`

Эти таблицы используются существующим дашбордом для отображения аналитики.

### Webhook интеграция

```python
# Настройка webhook для внешних систем
WEBHOOK_URL = 'https://your-system.com/webhook/ozon-update'

# Система отправит POST запрос с данными:
{
    "event": "update_completed",
    "status": "success|error",
    "timestamp": "2024-01-15T02:10:00Z",
    "records_updated": {
        "funnel": 150,
        "demographics": 75,
        "campaigns": 25
    },
    "errors": []
}
```

## 📞 Поддержка

### Контакты

- **Техническая поддержка**: admin@zavodprostavok.ru
- **Документация**: Этот файл и комментарии в коде
- **Логи**: Директория `logs/` в корне проекта

### Полезные команды

```bash
# Просмотр активных процессов обновления
ps aux | grep ozon_weekly_update

# Проверка размера логов
du -sh logs/

# Очистка старых логов (старше 30 дней)
find logs/ -name "*.log" -mtime +30 -delete

# Проверка cron задач
crontab -l | grep ozon

# Мониторинг в реальном времени
tail -f logs/cron_ozon_update.log
```

## 📝 Changelog

### Версия 1.0 (Текущая)

- ✅ Базовая система еженедельного обновления
- ✅ Инкрементальная загрузка данных
- ✅ Система логирования и мониторинга
- ✅ Email уведомления
- ✅ Автоматическая установка и настройка
- ✅ Комплексное тестирование

### Планируемые улучшения

- 🔄 Поддержка данных рекламных кампаний
- 🔄 Интеграция с Telegram/Slack
- 🔄 Web интерфейс для мониторинга
- 🔄 Автоматическое восстановление после ошибок
- 🔄 Поддержка нескольких аккаунтов Ozon

---

_Документация обновлена: 2024-01-15_  
_Версия системы: 1.0_  
_Автор: Manhattan System_
