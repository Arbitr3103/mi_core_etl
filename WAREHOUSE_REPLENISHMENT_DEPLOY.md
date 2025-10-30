# 🚀 Деплой системы рекомендаций по пополнению складов

## Быстрый старт

Вся система уже реализована и протестирована локально. Теперь просто разворачиваем на продакшн.

### Шаг 1: Запустите скрипт деплоя

```bash
./deploy_warehouse_replenishment.sh
```

Скрипт автоматически:

-   ✅ Проверит наличие всех файлов
-   ✅ Создаст бэкап на сервере
-   ✅ Загрузит файлы на продакшн
-   ✅ Установит правильные права
-   ✅ Проверит работоспособность API

### Шаг 2: Откройте дашборды

После успешного деплоя откройте в браузере:

**Warehouse Dashboard (мониторинг складов):**

```
https://www.market-mi.ru/warehouse_dashboard.html
```

**Replenishment Dashboard (рекомендации по пополнению):**

```
https://www.market-mi.ru/html/replenishment_dashboard.php
```

---

## Что включено в деплой

### 1. Warehouse Dashboard (`warehouse_dashboard.html`)

-   Визуализация остатков по складам
-   KPI метрики (общие остатки, продажи, требуют пополнения)
-   Фильтрация по статусу склада
-   Карточки складов с детальной информацией
-   Рекомендации по заказу

### 2. Warehouse Dashboard API (`api/warehouse-dashboard.php`)

Endpoints:

-   `?action=dashboard` - данные дашборда
-   `?action=warehouses` - список складов
-   `?action=clusters` - кластеры складов
-   `?action=export` - экспорт в CSV

### 3. Replenishment Dashboard (`html/replenishment_dashboard.php`)

-   Таблица рекомендаций по пополнению
-   Расчет на основе ADS (Average Daily Sales)
-   Фильтры по товарам, складам, статусу
-   Сортировка по всем колонкам
-   Пагинация
-   Экспорт в Excel

### 4. Replenishment API (`api/replenishment.php`)

Endpoints:

-   `?action=recommendations` - получить рекомендации
-   `?action=calculate` - пересчитать рекомендации
-   `?action=report` - еженедельный отчет
-   `?action=config` - настройки системы
-   `?action=status` - статус системы

---

## Проверка работоспособности

### Проверка API

```bash
# Warehouse Dashboard API
curl "https://www.market-mi.ru/api/warehouse-dashboard.php?action=dashboard&limit=1"

# Replenishment API
curl "https://www.market-mi.ru/api/replenishment.php?action=status"
```

### Проверка в браузере

1. **Warehouse Dashboard:**

    - Откройте https://www.market-mi.ru/warehouse_dashboard.html
    - Должны увидеть карточки складов
    - Проверьте фильтры (Все склады, Критические, Низкие, и т.д.)

2. **Replenishment Dashboard:**
    - Откройте https://www.market-mi.ru/html/replenishment_dashboard.php
    - Должна загрузиться таблица с рекомендациями
    - Проверьте фильтры и сортировку
    - Попробуйте экспорт в Excel

---

## Настройка системы

### Параметры расчета рекомендаций

В Replenishment Dashboard нажмите кнопку "⚙️ Настройки":

-   **Период пополнения** (дни): Сколько дней запаса нужно поддерживать
-   **Страховой запас** (дни): Дополнительный запас на случай задержек
-   **Период анализа продаж** (дни): За какой период анализировать продажи для расчета ADS
-   **Минимальный ADS**: Минимальное значение ADS для включения товара в рекомендации

---

## Структура данных

### Warehouse Dashboard использует:

-   `warehouse_sales_metrics` - метрики продаж по складам
-   `dim_products` - справочник товаров
-   `inventory` - остатки товаров

### Replenishment System использует:

-   `replenishment_recommendations` - рекомендации по пополнению
-   `replenishment_config` - конфигурация системы
-   `replenishment_calculations` - история расчетов

---

## Откат изменений

Если что-то пошло не так, бэкап сохранен на сервере:

```bash
ssh vladimir@178.72.129.61

# Посмотреть доступные бэкапы
ls -la /var/www/backups/

# Восстановить из бэкапа
sudo cp /var/www/backups/warehouse_replenishment_backup_YYYYMMDD_HHMMSS/* /var/www/market-mi.ru/
```

---

## Troubleshooting

### Проблема: API возвращает 500 ошибку

**Решение:**

```bash
# Проверьте логи на сервере
ssh vladimir@178.72.129.61
sudo tail -f /var/log/nginx/error.log
sudo tail -f /var/log/php8.1-fpm.log
```

### Проблема: Нет данных в таблице

**Решение:**

```bash
# Проверьте подключение к БД
ssh vladimir@178.72.129.61
psql -U mi_core_user -d mi_core_db -c "SELECT COUNT(*) FROM dim_products;"
```

### Проблема: Дашборд не открывается

**Решение:**

```bash
# Проверьте права доступа
ssh vladimir@178.72.129.61
ls -la /var/www/market-mi.ru/warehouse_dashboard.html
ls -la /var/www/market-mi.ru/html/replenishment_dashboard.php
```

---

## Следующие шаги

После успешного деплоя:

1. ✅ Протестируйте все функции дашбордов
2. ✅ Проверьте корректность данных
3. ✅ Настройте параметры расчета под ваши бизнес-требования
4. ✅ Обучите пользователей работе с системой
5. ✅ Настройте автоматический пересчет рекомендаций (cron)

### Настройка автоматического пересчета

Добавьте в crontab на сервере:

```bash
# Пересчет рекомендаций каждый день в 6:00
0 6 * * * curl -X POST "https://www.market-mi.ru/api/replenishment.php?action=calculate"
```

---

## Контакты

Если возникли вопросы или проблемы:

-   Проверьте логи на сервере
-   Проверьте консоль браузера (F12)
-   Проверьте подключение к БД

---

**Готово! Система рекомендаций по пополнению складов развернута! 🎉**
