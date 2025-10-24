# Развертывание системы складских отчетов Ozon

## Обзор изменений

Система складских отчетов Ozon была полностью реализована и протестирована. Включает:

### ✅ Завершенные компоненты:

1. **Система тестирования и валидации** (Task 6)
2. **API для аналитики складов**
3. **Дашборды мониторинга складов**
4. **Система рекомендаций по пополнению**
5. **Аналитика продаж и прогнозирование**

## Файлы для развертывания

### Основные компоненты системы:

```
warehouse_dashboard_api.php          # API для мониторинга складов
inventory_products_dashboard.html    # Главный дашборд товаров
warehouse_dashboard.html            # Дашборд мониторинга складов
warehouse_dashboard_advanced.html   # Расширенный дашборд
dashboard_styles.css               # Стили интерфейса
inventory_products_script.js       # JavaScript для дашборда
api/inventory-analytics.php        # Обновленный API аналитики
```

### Аналитические компоненты:

```
warehouse_inventory_grouping.php         # Группировка товаров по складам
warehouse_status_report.php            # Отчеты по состоянию складов
warehouse_stock_analysis_queries.php   # Запросы анализа остатков
replenishment_recommendations_system.php # Система рекомендаций
sales_analysis_and_forecasting.php     # Аналитика и прогнозирование
```

### Система тестирования:

```
tests/Unit/ProductActivityTest.php           # Тесты логики активности
tests/Unit/WarehouseAnalysisTest.php        # Тесты анализа складов
tests/Integration/DashboardFilteringTest.php # Тесты фильтрации
tests/E2E/VisualDisplayValidationTest.php   # Тесты интерфейса
tests/run_ozon_activity_tests.php           # Запуск тестов
```

## Инструкции по развертыванию

### 1. Подготовка сервера

```bash
# Подключиться к серверу
ssh user@your-server.com

# Перейти в директорию проекта
cd /path/to/your/project
```

### 2. Получение обновлений

```bash
# Получить последние изменения
git pull origin main

# Проверить статус
git status
git log --oneline -3
```

### 3. Проверка базы данных

```bash
# Убедиться что таблица inventory существует
psql -d your_database -c "\dt inventory"

# Проверить структуру таблицы
psql -d your_database -c "\d inventory"
```

### 4. Настройка прав доступа

```bash
# Установить права на PHP файлы
chmod 644 *.php
chmod 644 api/*.php
chmod 644 warehouse_*.php

# Установить права на HTML и CSS
chmod 644 *.html
chmod 644 *.css
chmod 644 *.js

# Создать директорию для логов тестов
mkdir -p tests/results
chmod 755 tests/results
```

### 5. Проверка конфигурации

```bash
# Проверить подключение к базе данных
php -r "
require_once 'config/database_postgresql.php';
try {
    \$pdo = getDatabaseConnection();
    echo 'Database connection: OK\n';
} catch (Exception \$e) {
    echo 'Database error: ' . \$e->getMessage() . '\n';
}
"
```

### 6. Запуск тестов

```bash
# Запустить полный набор тестов
php tests/run_ozon_activity_tests.php

# Проверить результаты
ls -la tests/results/
```

### 7. Проверка API

```bash
# Тест API аналитики складов
curl "http://your-domain.com/api/inventory-analytics.php?action=dashboard&activity_filter=active"

# Тест API дашборда складов
curl "http://your-domain.com/warehouse_dashboard_api.php?endpoint=summary&activity_filter=active"
```

### 8. Проверка интерфейса

Откройте в браузере:

-   `http://your-domain.com/inventory_products_dashboard.html` - Дашборд товаров
-   `http://your-domain.com/warehouse_dashboard.html` - Мониторинг складов
-   `http://your-domain.com/warehouse_dashboard_advanced.html` - Расширенная аналитика

## Функциональность системы

### 🎯 Основные возможности:

1. **Мониторинг активности товаров**

    - Фильтрация по активности (активные/неактивные/все)
    - Расчет общих остатков по всем типам запасов
    - Цветовое кодирование статусов

2. **Анализ складов**

    - Группировка данных по складам
    - Определение критических уровней остатков
    - Расчет приоритетов пополнения

3. **Система рекомендаций**

    - Автоматические рекомендации по пополнению
    - Анализ продаж и прогнозирование
    - Оптимизация распределения по складам

4. **Визуальные индикаторы**
    - Выделение нулевых остатков
    - Предупреждения о критических уровнях
    - Адаптивный дизайн для мобильных устройств

### 🧪 Система тестирования:

-   **Unit тесты**: Логика активности и анализ складов
-   **Integration тесты**: Фильтрация дашборда и API
-   **E2E тесты**: Валидация интерфейса и UX
-   **Performance тесты**: Производительность с большими данными

## Мониторинг и поддержка

### Логи системы:

```bash
# Проверить логи ошибок API
tail -f logs/inventory_api_errors.log

# Проверить результаты тестов
cat tests/results/ozon_activity_test_report_*.json
```

### Регулярное обслуживание:

```bash
# Еженедельный запуск тестов
0 2 * * 1 cd /path/to/project && php tests/run_ozon_activity_tests.php >> logs/weekly_tests.log 2>&1

# Ежедневная проверка API
0 6 * * * curl -s "http://your-domain.com/api/inventory-analytics.php?action=dashboard" > /dev/null
```

## Устранение неполадок

### Частые проблемы:

1. **Ошибка подключения к БД**

    ```bash
    # Проверить настройки в config/database_postgresql.php
    # Убедиться что PostgreSQL запущен
    systemctl status postgresql
    ```

2. **Пустые данные в дашборде**

    ```bash
    # Проверить наличие данных в таблице inventory
    psql -d your_database -c "SELECT COUNT(*) FROM inventory;"
    ```

3. **Ошибки JavaScript**
    ```bash
    # Проверить консоль браузера (F12)
    # Убедиться что все JS файлы загружаются
    ```

## Контакты для поддержки

При возникновении проблем:

1. Проверить логи ошибок
2. Запустить тесты для диагностики
3. Проверить статус базы данных
4. Обратиться к документации API

---

**Статус развертывания**: ✅ Готово к продакшену
**Дата последнего обновления**: $(date)
**Версия**: 1.0.0
