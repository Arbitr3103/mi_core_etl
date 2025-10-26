# 🚀 Доступ к продакшен дашборду Analytics ETL

## 🌐 Веб-дашборд запущен и готов к использованию!

### 📍 Адреса для доступа:

**Основной дашборд:**

```
http://localhost:8081/warehouse_dashboard.html
```

**API для данных:**

```
http://localhost:8081/warehouse_dashboard_api.php
```

**Analytics ETL API:**

```
http://localhost:8081/src/api/controllers/AnalyticsETLController.php
```

## 📊 Что вы увидите на дашборде:

### 🏪 Общая статистика:

-   **7 складов** с данными
-   **15 уникальных товаров**
-   **6,969 единиц** общего остатка
-   **213 продаж** за 28 дней
-   **47 активных позиций**

### 📈 Топ складов по остаткам:

1. **Москва_РФЦ** - 1,620 единиц
2. **АДЫГЕЙСК_РФЦ** - 540 единиц
3. **Санкт-Петербург_РФЦ** - 515 единиц
4. **Екатеринбург_РФЦ** - 344 единицы

### 🔄 Автоматическое обновление:

-   Данные обновляются каждые 4 часа через cron
-   Последнее обновление: $(date)
-   Источник данных: Ozon Analytics API

## 🛠️ Управление системой:

### Проверка статуса ETL:

```bash
./check_analytics_etl_status.sh
```

### Ручной запуск ETL:

```bash
./run_analytics_etl_manual.sh --limit=100
```

### Просмотр логов:

```bash
tail -f logs/analytics_etl/etl_$(date +%Y-%m-%d).log
```

### Проверка cron задач:

```bash
crontab -l | grep analytics
```

## 🔧 Если дашборд не открывается:

1. **Проверьте, что сервер запущен:**

    ```bash
    ps aux | grep php
    ```

2. **Перезапустите сервер:**

    ```bash
    ./start_production_dashboard.sh
    ```

3. **Проверьте API:**
    ```bash
    curl http://localhost:8081/warehouse_dashboard_api.php
    ```

## 📱 Мобильная версия:

Дашборд адаптирован для мобильных устройств и планшетов.

## 🎯 Система полностью готова к эксплуатации!

**Откройте в браузере:** http://localhost:8081/warehouse_dashboard.html
