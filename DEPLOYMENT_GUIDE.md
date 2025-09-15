# 🚀 Руководство по развертыванию Wildberries ETL

## 📋 Обзор проекта

Полностью реализован и протестирован модуль импорта данных из API Wildberries в существующую ETL систему `mi_core_etl`. Модуль поддерживает импорт продаж, возвратов и финансовых деталей с правильной атрибуцией по клиентам и источникам.

## ✅ Что реализовано

### 🔧 Основные компоненты
- **`wb_importer.py`** (813 строк) - основной модуль ETL для Wildberries
- **Интеграция с `main.py`** через параметр `--source=wb`
- **Автоматизация в `run_weekly_etl.sh`** для ежедневного запуска
- **SQL миграции** для поддержки мультиисточников
- **Комплексное тестирование** API и интеграции

### 📊 Поддерживаемые данные
- **Продажи и возвраты** (`/api/v1/supplier/sales`)
- **Финансовые детали** (`/api/v5/supplier/reportDetailByPeriod`)
- **Сырые события** для аудита и отладки
- **Автоматическое обогащение** через `dim_products`

### 🏗️ Архитектура данных
```
fact_orders:        продажи/возвраты с source_id=WB
fact_transactions:  финансовые операции (комиссии, логистика, штрафы)
raw_events:         сырые JSON с event_type='wb_sale'/'wb_finance_detail'
```

## 🛠️ Развертывание на продакшене

### 1. Подготовка базы данных

```bash
# 1. Добавить справочные данные
mysql -u username -p database_name < setup_wb_data.sql

# 2. Выполнить миграцию fact_transactions
mysql -u username -p database_name < migrate_fact_transactions.sql
```

### 2. Настройка переменных окружения

Добавить в `.env`:
```bash
# Wildberries API
WB_API_KEY=your_wildberries_api_key_here
WB_API_URL=https://statistics-api.wildberries.ru
```

### 3. Проверка готовности

```bash
# Тест API без БД
python3 test_wb_api_only.py

# Полный интеграционный тест (на сервере с БД)
python3 test_wb_integration.py
```

### 4. Запуск импорта

```bash
# Импорт за последние 7 дней
python3 main.py --source=wb --last-7-days

# Импорт за конкретный период
python3 main.py --source=wb --date-from=2024-01-01 --date-to=2024-01-31

# Только продажи (без финансовых деталей)
python3 main.py --source=wb --orders-only --last-7-days

# Только финансовые детали
python3 main.py --source=wb --transactions-only --last-7-days
```

## ⚙️ Автоматизация

### Еженедельный ETL
Скрипт `run_weekly_etl.sh` автоматически запускает импорт из обоих источников:

```bash
# Запуск вручную
./run_weekly_etl.sh

# Настройка cron (каждое воскресенье в 2:00)
0 2 * * 0 /path/to/mi_core_etl/run_weekly_etl.sh
```

### Логирование
Все логи сохраняются в `logs/`:
- `logs/wb_import_YYYY-MM-DD.log` - логи импорта Wildberries
- `logs/ozon_import_YYYY-MM-DD.log` - логи импорта Ozon

## 🔍 Мониторинг и отладка

### Проверка качества данных

```sql
-- Статистика по источникам
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

### Проверка сырых событий

```sql
-- Последние события Wildberries
SELECT 
    event_type,
    COUNT(*) as count,
    MAX(created_at) as last_event
FROM raw_events 
WHERE event_type IN ('wb_sale', 'wb_finance_detail')
GROUP BY event_type;
```

## 🚨 Особенности API Wildberries

### Ограничения
- **Rate Limit**: 1 запрос в минуту
- **Пагинация продаж**: до 80,000 записей за запрос
- **Пагинация финансов**: по параметру `rrdid`

### SSL сертификаты
API использует самоподписанные сертификаты. В продакшене рекомендуется:
```python
# Отключить предупреждения SSL (только для Wildberries API)
import urllib3
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
```

## 📈 Агрегация данных

Данные из Wildberries автоматически включаются в существующие агрегации:

```bash
# Запуск агрегации метрик
python3 run_aggregation.py
```

Агрегация суммирует метрики по `client_id` независимо от источника, обеспечивая единое представление данных клиента.

## 🔧 Устранение неполадок

### Ошибки подключения к API
```bash
# Проверить доступность API
curl -H "Authorization: YOUR_API_KEY" \
  "https://statistics-api.wildberries.ru/api/v1/supplier/sales?dateFrom=2024-01-01"
```

### Ошибки базы данных
```bash
# Проверить структуру таблиц
mysql -e "DESCRIBE fact_transactions;" database_name

# Проверить справочные данные
mysql -e "SELECT * FROM sources WHERE code = 'WB';" database_name
mysql -e "SELECT * FROM clients WHERE name = 'ТД Манхэттен';" database_name
```

### Проблемы с импортом
```bash
# Включить детальное логирование
export LOG_LEVEL=DEBUG
python3 main.py --source=wb --last-1-days
```

## 📚 Дополнительные ресурсы

- **`WB_SETUP.md`** - детальная документация по настройке
- **`test_wb_api_only.py`** - тест API без БД
- **`test_wb_integration.py`** - полный интеграционный тест
- **Логи в `logs/`** - для отладки и мониторинга

## 🎯 Готовность к продакшену

✅ **API протестирован** - все endpoints работают корректно  
✅ **Данные валидированы** - структура и качество проверены  
✅ **Интеграция завершена** - совместимость с существующей системой  
✅ **Автоматизация настроена** - еженедельный запуск  
✅ **Документация создана** - полное покрытие функционала  

**Система готова к продуктивному использованию! 🚀**
