# MI Core ETL - Модуль импорта данных из маркетплейсов

Проект для импорта данных из API маркетплейсов (Ozon, Wildberries) в базу данных MySQL с поддержкой мультиисточников и автоматической агрегации метрик.

## Установка и настройка

1. Создайте виртуальное окружение:
```bash
python3 -m venv venv
source venv/bin/activate
```

2. Установите зависимости:
```bash
pip install -r requirements.txt
```

3. Настройте базу данных MySQL:
```bash
# Войдите в MySQL под администратором
sudo mysql

# Выполните SQL скрипт для создания БД, таблиц и пользователя
source create_tables.sql

# ВАЖНО: Замените пароль в скрипте на надежный!
```

4. Настройте файл `.env` с вашими учетными данными:
- Укажите пароль для пользователя `ingest_user`
- Добавьте Client ID и API Key от Ozon
- Добавьте API Key от Wildberries

```bash
# Пример .env файла
DB_HOST=localhost
DB_USER=ingest_user
DB_PASSWORD=your_secure_password
DB_NAME=mi_core

# Ozon API
OZON_CLIENT_ID=your_ozon_client_id
OZON_API_KEY=your_ozon_api_key

# Wildberries API
WB_API_KEY=your_wildberries_api_key
```

**⚠️ ВАЖНО ПО БЕЗОПАСНОСТИ:**
- Используйте только пользователя `ingest_user`, НЕ `root`
- Установите надежный пароль для `ingest_user`
- Пользователь имеет только необходимые права: SELECT, INSERT, UPDATE

5. Настройте поддержку Wildberries (для новых установок):
```bash
# Добавить справочные данные для Wildberries
mysql -u ingest_user -p mi_core < setup_wb_data.sql

# Выполнить миграцию для поддержки мультиисточников
mysql -u ingest_user -p mi_core < migrate_fact_transactions.sql
```

## Поддерживаемые источники данных

### 🛒 Ozon
- **Продукты**: импорт каталога товаров
- **Заказы**: продажи и возвраты
- **Транзакции**: финансовые операции (комиссии, логистика)

### 🛍️ Wildberries
- **Заказы**: продажи и возвраты через `/api/v1/supplier/sales`
- **Транзакции**: финансовые детали через `/api/v5/supplier/reportDetailByPeriod`
- **Особенности**: rate limit 1 запрос/минуту, пагинация до 80k записей

## Структура проекта

### Основные модули
- `importers/ozon_importer.py` - модуль импорта данных из Ozon
- `importers/wb_importer.py` - модуль импорта данных из Wildberries
- `main.py` - точка входа для запуска импорта
- `run_aggregation.py` - агрегация ежедневных метрик

### Конфигурация и автоматизация
- `requirements.txt` - зависимости проекта
- `.env` - конфигурационный файл с учетными данными
- `run_weekly_etl.sh` - скрипт автоматического еженедельного импорта

### SQL скрипты
- `create_tables.sql` - создание базовой структуры БД
- `setup_wb_data.sql` - настройка справочных данных для Wildberries
- `migrate_fact_transactions.sql` - миграция для поддержки мультиисточников

### Тестирование
- `test_wb_api_only.py` - тест API Wildberries без БД
- `test_wb_integration.py` - полный интеграционный тест
- `test_*.py` - различные тестовые скрипты

### Документация
- `WB_SETUP.md` - детальная настройка Wildberries
- `DEPLOYMENT_GUIDE.md` - руководство по развертыванию

## Использование

### Импорт из Ozon (по умолчанию)
```bash
# Импорт всех данных за последние 7 дней
python3 main.py --last-7-days

# Импорт за конкретный период
python3 main.py --date-from=2024-01-01 --date-to=2024-01-31

# Импорт только продуктов
python3 main.py --products-only --last-7-days

# Импорт только заказов
python3 main.py --orders-only --last-7-days

# Импорт только транзакций
python3 main.py --transactions-only --last-7-days
```

### Импорт из Wildberries
```bash
# Импорт всех данных за последние 7 дней
python3 main.py --source=wb --last-7-days

# Импорт за конкретный период
python3 main.py --source=wb --date-from=2024-01-01 --date-to=2024-01-31

# Импорт только заказов (продажи и возвраты)
python3 main.py --source=wb --orders-only --last-7-days

# Импорт только финансовых транзакций
python3 main.py --source=wb --transactions-only --last-7-days
```

### Автоматический импорт
```bash
# Еженедельный импорт из всех источников
./run_weekly_etl.sh

# Агрегация ежедневных метрик
python3 run_aggregation.py
```

### Тестирование
```bash
# Тест API Wildberries (без БД)
python3 test_wb_api_only.py

# Полный интеграционный тест
python3 test_wb_integration.py
```

## Структура таблиц

### Основные таблицы данных
- **`dim_products`** - справочник товаров с артикулами и ценами
- **`fact_orders`** - факты заказов (продажи/возвраты) с атрибуцией по источникам
- **`fact_transactions`** - финансовые транзакции (комиссии, логистика, штрафы)
- **`raw_events`** - сырые JSON данные из API для аудита

### Справочные таблицы
- **`clients`** - справочник клиентов (ТД Манхэттен, и др.)
- **`sources`** - справочник источников данных (Ozon, WB)
- **`metrics_daily`** - агрегированные ежедневные метрики

### Ключевые особенности
- **Мультиисточники**: все таблицы фактов содержат `client_id` и `source_id`
- **Идемпотентность**: повторные запуски безопасны благодаря `ON DUPLICATE KEY UPDATE`
- **Аудит**: все сырые данные сохраняются в `raw_events` с типами событий
- **Автоагрегация**: ежедневные метрики рассчитываются автоматически

## Мониторинг и логи

### Логирование
Все операции логируются в директорию `logs/`:
- `logs/ozon_import_YYYY-MM-DD.log` - логи импорта Ozon
- `logs/wb_import_YYYY-MM-DD.log` - логи импорта Wildberries
- `logs/aggregation_YYYY-MM-DD.log` - логи агрегации метрик

### Проверка качества данных
```sql
-- Статистика по источникам за последние 7 дней
SELECT 
    s.name as source,
    COUNT(*) as orders_count,
    SUM(CASE WHEN transaction_type = 'продажа' THEN 1 ELSE 0 END) as sales,
    SUM(CASE WHEN transaction_type = 'возврат' THEN 1 ELSE 0 END) as returns
FROM fact_orders fo
JOIN sources s ON fo.source_id = s.id
WHERE fo.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY s.name;

-- Финансовые операции по источникам
SELECT 
    s.name as source,
    COUNT(*) as transactions_count,
    SUM(amount) as total_amount
FROM fact_transactions ft
JOIN sources s ON ft.source_id = s.id
WHERE ft.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY s.name;
```

## Автоматизация

### Настройка cron
```bash
# Еженедельный импорт (воскресенье в 2:00)
0 2 * * 0 /path/to/mi_core_etl/run_weekly_etl.sh

# Ежедневная агрегация метрик (3:00 утра)
0 3 * * * cd /path/to/mi_core_etl && python3 run_aggregation.py
```

### Мониторинг выполнения
```bash
# Проверка последних импортов
tail -f logs/wb_import_$(date +%Y-%m-%d).log
tail -f logs/ozon_import_$(date +%Y-%m-%d).log

# Проверка статуса cron задач
crontab -l
grep CRON /var/log/syslog
```

## Устранение неполадок

### Частые проблемы

**Ошибки API Wildberries:**
- Проверьте корректность `WB_API_KEY` в `.env`
- Убедитесь в соблюдении rate limit (1 запрос/минуту)
- API использует самоподписанные SSL сертификаты

**Ошибки подключения к БД:**
- Проверьте права пользователя `ingest_user`
- Убедитесь, что выполнены все миграции
- Проверьте настройки сети и firewall

**Проблемы с данными:**
- Проверьте наличие справочных данных в `clients` и `sources`
- Убедитесь в корректности маппинга товаров в `dim_products`
- Проверьте логи на предмет ошибок трансформации

### Полезные команды диагностики
```bash
# Тест подключения к БД
python3 test_db_connection.py

# Тест API без БД
python3 test_wb_api_only.py

# Проверка конфигурации
python3 test_config.py

# Полная диагностика
python3 test_wb_integration.py
```

## Разработка и расширение

### Добавление нового источника данных
1. Создайте новый модуль в `importers/`
2. Добавьте источник в таблицу `sources`
3. Обновите `main.py` для поддержки нового источника
4. Создайте тесты и документацию

### Требования к разработке
- Следуйте архитектуре существующих импортеров
- Используйте параметризованные SQL запросы
- Реализуйте идемпотентность операций
- Добавляйте подробное логирование
- Сохраняйте сырые данные в `raw_events`

## Лицензия и поддержка

Проект разработан для внутреннего использования компании. 
Для вопросов и предложений обращайтесь к команде разработки.

---

**📊 Система готова к продуктивному использованию!**  
**🚀 Поддерживает автоматический импорт из Ozon и Wildberries**  
**📈 Включает агрегацию метрик и мониторинг качества данных**
