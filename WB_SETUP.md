# Настройка модуля импорта Wildberries

## Обзор

Модуль `wb_importer.py` обеспечивает интеграцию с API Wildberries для автоматического импорта:
- **Товаров** из Content API в таблицу `dim_products`
- **Продаж и возвратов** из Statistics API в таблицу `fact_orders`
- **Финансовых операций** из Statistics API в таблицу `fact_transactions`

## Предварительные требования

1. **API ключ Wildberries** - получите в личном кабинете поставщика
2. **Настроенная база данных** с таблицами из `create_tables.sql`
3. **Python 3.7+** с установленными зависимостями из `requirements.txt`

## Пошаговая настройка

### Шаг 1: Настройка API ключа

1. Скопируйте `.env.example` в `.env`:
```bash
cp .env.example .env
```

2. Отредактируйте `.env` файл, добавив ваш API ключ Wildberries:
```ini
WB_API_KEY=your_actual_wb_api_key_here
```

### Шаг 2: Подготовка базы данных

1. Добавьте записи для Wildberries в справочные таблицы:
```bash
mysql -u your_user -p your_database < setup_wb_data.sql
```

2. Обновите схему таблицы `fact_transactions`:
```bash
mysql -u your_user -p your_database < migrate_fact_transactions.sql
```

### Шаг 3: Тестирование

Запустите тесты для проверки корректности настройки:

```bash
# Базовые тесты
python test_wb_api.py

# Полное тестирование
python test_wb_api.py --test-all

# Отдельные компоненты
python test_wb_api.py --test-config
python test_wb_api.py --test-db
python test_wb_api.py --test-api

# Тестирование импорта товаров WB
python test_wb_products_import.py
```

## Первоначальная настройка импорта товаров

### Важно: Порядок выполнения

При первом запуске системы **обязательно** выполните импорт товаров перед импортом продаж:

```bash
# 1. Сначала импортируйте товары для заполнения dim_products
python main.py --source=wb --products-only

# 2. Затем можно импортировать продажи и финансы
python main.py --source=wb --last-7-days
```

### Зачем это нужно?

- **Связывание данных**: Продажи связываются с товарами по штрихкоду
- **Полнота информации**: Без товаров в `dim_products` продажи будут без названий и брендов
- **Производительность**: Импорт товаров занимает время (много API запросов)

### Мониторинг импорта товаров

```bash
# Проверка количества импортированных товаров WB
mysql -e "SELECT COUNT(*) as wb_products FROM dim_products WHERE sku_wb IS NOT NULL;"

# Примеры импортированных товаров
mysql -e "SELECT sku_wb, name, brand, barcode FROM dim_products WHERE sku_wb IS NOT NULL LIMIT 5;"
```

## Использование

### Ручной запуск

```bash
# Импорт данных Wildberries за вчерашний день (товары + продажи + финансы)
python main.py --source=wb

# Импорт за последние 7 дней
python main.py --source=wb --last-7-days

# Импорт за определенный период
python main.py --source=wb --start-date 2024-01-01 --end-date 2024-01-31

# Только товары (первоначальное заполнение dim_products)
python main.py --source=wb --products-only

# Только продажи
python main.py --source=wb --orders-only --start-date 2024-01-01

# Только финансовые операции
python main.py --source=wb --transactions-only --start-date 2024-01-01
```

### Автоматический запуск

Модуль интегрирован в `run_weekly_etl.sh` для автоматического еженедельного запуска:

```bash
# Настройте cron job (каждый понедельник в 3:00)
0 3 * * 1 /path/to/mi_core_etl/run_weekly_etl.sh
```

## Архитектура данных

### Таблицы назначения

1. **dim_products** - справочник товаров
   - `sku_wb` = nmID (артикул WB)
   - `barcode` = штрихкод товара (ключ для связывания)
   - Автоматическое обновление при импорте товаров

2. **fact_orders** - продажи и возвраты
   - `source_id` = ID источника 'WB' из таблицы `sources`
   - `client_id` = ID клиента 'ТД Манхэттен' из таблицы `clients`

3. **fact_transactions** - финансовые операции
   - Комиссии, логистика, штрафы и другие расходы
   - Каждая операция = отдельная строка

4. **raw_events** - сырые данные API
   - `event_type` = 'wb_sale', 'wb_finance_detail' или 'wb_product'
   - Полные JSON ответы для аудита

### Обогащение данных

- Товары связываются с `dim_products` по `supplierArticle` или `barcode`
- Автоматическое определение типа транзакции (продажа/возврат)
- Расходы автоматически помечаются отрицательными значениями

## Ограничения API

### Statistics API (продажи и финансы)
- **Лимит запросов**: 1 запрос в минуту
- **Максимум записей**: 80,000 за запрос (автоматическая пагинация)
- **Формат дат**: RFC3339 для API запросов

### Content API (товары)
- **Лимит запросов**: 100 запросов в минуту (задержка 700ms)
- **Максимум записей**: 100 товаров за запрос
- **Пагинация**: cursor-based с updatedAt и nmID

## Мониторинг и логирование

Все операции логируются с уровнями:
- `INFO` - успешные операции
- `WARNING` - предупреждения (товар не найден)
- `ERROR` - критические ошибки

Логи сохраняются в `logs/` директории при запуске через `run_weekly_etl.sh`.

## Агрегация данных

После импорта запустите агрегацию метрик:

```bash
python run_aggregation.py
```

Данные из Ozon и Wildberries автоматически объединяются в `metrics_daily` по `client_id`.

## Устранение неполадок

### Ошибка "WB_API_KEY not found"
- Проверьте наличие `.env` файла
- Убедитесь, что ключ указан без кавычек

### Ошибка "client_id или source_id не найден"
- Выполните `setup_wb_data.sql`
- Проверьте наличие записей в таблицах `clients` и `sources`

### Ошибка "Unknown column 'client_id'"
- Выполните миграцию `migrate_fact_transactions.sql`

### Ошибка API "401 Unauthorized"
- Проверьте корректность API ключа
- Убедитесь, что ключ активен в личном кабинете

### Медленная работа
- Нормально: API ограничен 1 запросом в минуту
- Для больших объемов данных процесс может занять несколько часов

## Поддержка

При возникновении проблем:
1. Запустите `python test_wb_api.py --test-all`
2. Проверьте логи в `logs/` директории
3. Убедитесь в корректности настройки БД и API ключа
