# Безопасное развертывание Ozon Active Product Filtering

## Предварительная подготовка

### 1. Подключение к серверу

```bash
# Подключитесь к серверу
ssh your-server-user@your-server-ip

# Перейдите в директорию проекта
cd /path/to/your/mi_core_etl
```

### 2. Создание резервной копии

```bash
# Создайте полную резервную копию базы данных
mysqldump --single-transaction --routines --triggers mi_core_db > backup_before_active_filtering_$(date +%Y%m%d_%H%M%S).sql

# Создайте резервную копию файлов проекта
tar -czf project_backup_$(date +%Y%m%d_%H%M%S).tar.gz .
```

### 3. Обновление кода

```bash
# Получите последние изменения
git fetch origin main

# Проверьте текущую ветку и коммиты
git log --oneline -5

# Переключитесь на новую версию
git checkout main
git pull origin main
```

## Этап 1: Проверка готовности системы

### 1.1 Проверка окружения

```bash
# Проверьте версии
php --version
mysql --version

# Проверьте подключение к базе данных
php -r "
require_once 'config.php';
try {
    \$pdo = getDatabaseConnection();
    echo 'Database connection: OK\n';
} catch (Exception \$e) {
    echo 'Database connection failed: ' . \$e->getMessage() . '\n';
    exit(1);
}
"
```

### 1.2 Проверка файлов миграции

```bash
# Убедитесь, что все файлы миграции на месте
ls -la migrations/
ls -la deploy_active_product_filtering.sh

# Сделайте скрипт исполняемым
chmod +x deploy_active_product_filtering.sh
```

## Этап 2: Тестовое развертывание (Dry Run)

### 2.1 Запуск в режиме тестирования

```bash
# Запустите dry run для проверки
./deploy_active_product_filtering.sh --dry-run
```

**Ожидаемый результат:**

- ✅ Все файлы найдены
- ✅ Подключение к базе данных работает
- ✅ Показывает план изменений без их применения

### 2.2 Проверка текущего состояния

```bash
# Проверьте текущее количество продуктов
mysql mi_core_db -e "SELECT COUNT(*) as total_products FROM dim_products WHERE sku_ozon IS NOT NULL;"

# Проверьте структуру таблицы
mysql mi_core_db -e "DESCRIBE dim_products;"
```

## Этап 3: Применение миграции

### 3.1 Запуск полного развертывания

```bash
# Запустите полное развертывание
./deploy_active_product_filtering.sh
```

**Процесс развертывания:**

1. ✅ Создание резервной копии
2. ✅ Применение миграции базы данных
3. ✅ Валидация изменений
4. ✅ Обновление конфигурации
5. ✅ Тестирование системы

### 3.2 Мониторинг процесса

```bash
# В другом терминале следите за логами
tail -f logs/deployment_*.log
```

## Этап 4: Проверка результатов

### 4.1 Проверка структуры базы данных

```bash
# Проверьте новые таблицы
mysql mi_core_db -e "SHOW TABLES LIKE '%activity%';"

# Проверьте новые поля в dim_products
mysql mi_core_db -e "DESCRIBE dim_products;"

# Проверьте представление
mysql mi_core_db -e "SELECT * FROM v_active_products_stats;"
```

### 4.2 Проверка данных

```bash
# Проверьте статистику активных продуктов
mysql mi_core_db -e "
SELECT
    COUNT(*) as total_products,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_products,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_products
FROM dim_products
WHERE sku_ozon IS NOT NULL;
"
```

### 4.3 Проверка API

```bash
# Проверьте API endpoints
curl -s "http://localhost/api/inventory-analytics.php?action=activity_stats" | jq .

# Проверьте фильтрацию
curl -s "http://localhost/api/inventory-analytics.php?action=critical_stock&active_only=true" | jq .
```

## Этап 5: Тестирование функциональности

### 5.1 Запуск тестов

```bash
# Запустите интеграционные тесты
php tests/run_ozon_extractor_integration_tests.php

# Запустите тесты производительности
php tests/Performance/simple_performance_test.php
```

### 5.2 Проверка ETL процесса

```bash
# Запустите тестовый ETL процесс
php etl_cli.php run ozon --limit=10 --dry-run

# Проверьте логи ETL
tail -f logs/etl_*.log
```

## Этап 6: Настройка мониторинга

### 6.1 Настройка cron задач

```bash
# Добавьте задачи в crontab
crontab -e

# Добавьте следующие строки:
# Обновление статистики активности каждые 30 минут
*/30 * * * * cd /path/to/mi_core_etl && mysql mi_core_db -e "CALL UpdateActivityMonitoringStats();"

# ETL процесс каждые 4 часа
0 */4 * * * cd /path/to/mi_core_etl && php etl_cli.php run ozon >> logs/etl_cron.log 2>&1
```

### 6.2 Настройка мониторинга

```bash
# Создайте скрипт мониторинга
cat > monitor_active_filtering.sh << 'EOF'
#!/bin/bash
ACTIVE_COUNT=$(mysql mi_core_db -sN -e "SELECT active_products FROM v_active_products_stats;")
if [ "$ACTIVE_COUNT" -lt 40 ]; then
    echo "ALERT: Only $ACTIVE_COUNT active products (expected ~48)"
    # Отправьте уведомление
fi
EOF

chmod +x monitor_active_filtering.sh

# Добавьте в crontab для проверки каждые 15 минут
echo "*/15 * * * * /path/to/mi_core_etl/monitor_active_filtering.sh" | crontab -
```

## Этап 7: Проверка производительности

### 7.1 Сравнение производительности

```bash
# Запустите бенчмарк производительности
php tests/Performance/database_performance_benchmark.php

# Проверьте время загрузки дашборда
php tests/Performance/dashboard_load_test.php
```

### 7.2 Мониторинг ресурсов

```bash
# Проверьте использование ресурсов
top -p $(pgrep mysql)
iostat -x 1 5
```

## План отката (если что-то пошло не так)

### Автоматический откат

```bash
# Если развертывание не удалось, запустите откат
./deploy_active_product_filtering.sh --rollback
```

### Ручной откат

```bash
# Восстановите из резервной копии
mysql mi_core_db < backup_before_active_filtering_YYYYMMDD_HHMMSS.sql

# Восстановите файлы проекта (если нужно)
git checkout HEAD~1  # Вернитесь к предыдущему коммиту
```

## Контрольные точки успешного развертывания

- [ ] ✅ Резервная копия создана
- [ ] ✅ Миграция применена без ошибок
- [ ] ✅ Валидация прошла успешно
- [ ] ✅ API возвращает корректные данные
- [ ] ✅ Активных продуктов ~48 (вместо 176)
- [ ] ✅ Производительность улучшилась
- [ ] ✅ Тесты проходят успешно
- [ ] ✅ Мониторинг настроен
- [ ] ✅ Cron задачи добавлены

## Контакты для поддержки

- **Разработчик**: [Ваши контакты]
- **Администратор БД**: [Контакты администратора]
- **DevOps**: [Контакты DevOps]

## Полезные команды для диагностики

```bash
# Проверка статуса системы
mysql mi_core_db -e "SELECT * FROM v_active_products_stats;"

# Проверка последних изменений активности
mysql mi_core_db -e "SELECT * FROM product_activity_log ORDER BY changed_at DESC LIMIT 10;"

# Проверка производительности запросов
mysql mi_core_db -e "EXPLAIN SELECT * FROM dim_products WHERE is_active = 1;"

# Проверка размера таблиц
mysql mi_core_db -e "
SELECT
    TABLE_NAME,
    TABLE_ROWS,
    ROUND(DATA_LENGTH/1024/1024, 2) as 'Data Size (MB)',
    ROUND(INDEX_LENGTH/1024/1024, 2) as 'Index Size (MB)'
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'mi_core_db'
AND TABLE_NAME LIKE '%product%';
"
```

---

**Версия чеклиста**: 1.0  
**Дата**: 2025-01-16  
**Автор**: AI Assistant
