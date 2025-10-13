# 🚀 Руководство по развертыванию оптимизаций на продакшен сервере

**Дата:** 13 октября 2025  
**Версия:** 1.0  
**Задача:** Развертывание оптимизаций производительности дашборда складских остатков

## 📋 Предварительные требования

### ✅ Что должно быть готово:

- [x] Код закоммичен и запушен в репозиторий
- [x] Локальное тестирование пройдено успешно
- [x] Все оптимизации протестированы
- [ ] Доступ к продакшен серверу
- [ ] Резервная копия базы данных
- [ ] Уведомление команды о развертывании

## 🔧 Шаг 1: Подготовка сервера

### 1.1 Подключение к серверу

```bash
# Подключитесь к продакшен серверу
ssh user@your-production-server.com

# Перейдите в директорию проекта
cd /path/to/mi_core_etl
```

### 1.2 Создание резервной копии

```bash
# Создайте резервную копию базы данных
mysqldump -u username -p database_name > backup_$(date +%Y%m%d_%H%M%S).sql

# Создайте резервную копию файлов
cp -r api/ backup_api_$(date +%Y%m%d_%H%M%S)/
cp -r html/ backup_html_$(date +%Y%m%d_%H%M%S)/
```

## 📥 Шаг 2: Обновление кода

### 2.1 Получение последних изменений

```bash
# Убедитесь, что находитесь на правильной ветке
git status
git branch

# Получите последние изменения
git fetch origin
git pull origin main

# Проверьте, что получили все файлы оптимизации
ls -la api/inventory_cache_manager.php
ls -la sql/create_inventory_dashboard_indexes.sql
ls -la js/inventory_dashboard_optimized.js
ls -la scripts/manage_inventory_cache.php
```

### 2.2 Проверка файлов

```bash
# Проверьте, что все новые файлы на месте
find . -name "*inventory*" -type f -newer backup_* 2>/dev/null | head -10
```

## 🗃️ Шаг 3: Применение индексов базы данных

### 3.1 Проверка подключения к БД

```bash
# Проверьте подключение к базе данных
php -r "
require_once 'config.php';
try {
    \$pdo = getDatabaseConnection();
    echo 'Подключение к БД: OK\n';
} catch (Exception \$e) {
    echo 'Ошибка БД: ' . \$e->getMessage() . '\n';
    exit(1);
}
"
```

### 3.2 Применение индексов

```bash
# Примените индексы для оптимизации
php -r "
require_once 'config.php';
try {
    \$pdo = getDatabaseConnection();
    \$sql = file_get_contents('sql/create_inventory_dashboard_indexes.sql');
    \$pdo->exec(\$sql);
    echo 'Индексы успешно созданы\n';
} catch (Exception \$e) {
    echo 'Ошибка создания индексов: ' . \$e->getMessage() . '\n';
    exit(1);
}
"
```

### 3.3 Проверка индексов

```bash
# Проверьте созданные индексы
mysql -u username -p -e "
SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'your_database_name'
AND TABLE_NAME = 'inventory_data'
AND INDEX_NAME LIKE 'idx_%';"
```

## 💾 Шаг 4: Настройка кэширования

### 4.1 Создание директории кэша

```bash
# Создайте директорию для кэша
mkdir -p cache/inventory
chmod 755 cache/inventory

# Проверьте права доступа
ls -la cache/
```

### 4.2 Тестирование кэша

```bash
# Протестируйте систему кэширования
php scripts/manage_inventory_cache.php test

# Проверьте статус кэша
php scripts/manage_inventory_cache.php status
```

### 4.3 Прогрев кэша

```bash
# Прогрейте кэш основными данными
php scripts/manage_inventory_cache.php warmup

# Проверьте результат
php scripts/manage_inventory_cache.php status
```

## 🌐 Шаг 5: Обновление веб-сервера

### 5.1 Для Apache

```bash
# Перезапустите Apache
sudo systemctl reload apache2
# или
sudo service apache2 reload
```

### 5.2 Для Nginx

```bash
# Перезапустите Nginx
sudo systemctl reload nginx
# или
sudo service nginx reload
```

### 5.3 Проверка веб-сервера

```bash
# Проверьте статус веб-сервера
sudo systemctl status apache2
# или
sudo systemctl status nginx

# Проверьте логи на ошибки
tail -f /var/log/apache2/error.log
# или
tail -f /var/log/nginx/error.log
```

## 🧪 Шаг 6: Тестирование на продакшене

### 6.1 Тест API endpoints

```bash
# Тестируйте основные API endpoints
curl -s "http://your-domain.com/api/inventory-analytics.php?action=dashboard" | jq .status

curl -s "http://your-domain.com/api/inventory-analytics.php?action=critical-products" | jq .status

curl -s "http://your-domain.com/api/inventory-analytics.php?action=warehouse-summary" | jq .status
```

### 6.2 Тест производительности

```bash
# Запустите тесты производительности на сервере
php tests/test_inventory_performance_optimizations.php

# Проверьте мониторинг производительности
php scripts/monitor_inventory_performance.php
```

### 6.3 Тест дашборда

```bash
# Откройте дашборд в браузере и проверьте:
# http://your-domain.com/html/inventory_marketing_dashboard.php

# Проверьте время загрузки (должно быть < 2 секунд)
# Проверьте работу всех функций
# Убедитесь, что данные отображаются корректно
```

## 📊 Шаг 7: Мониторинг и проверка

### 7.1 Настройка мониторинга

```bash
# Добавьте задачу в crontab для очистки кэша
echo "0 */6 * * * cd /path/to/mi_core_etl && php scripts/manage_inventory_cache.php clean" | crontab -

# Добавьте мониторинг производительности
echo "0 8 * * * cd /path/to/mi_core_etl && php scripts/monitor_inventory_performance.php > logs/performance_$(date +\%Y\%m\%d).log" | crontab -
```

### 7.2 Проверка логов

```bash
# Создайте директорию для логов
mkdir -p logs
chmod 755 logs

# Проверьте логи API
tail -f logs/inventory_api_errors.log

# Проверьте логи веб-сервера
tail -f /var/log/apache2/access.log | grep inventory
```

### 7.3 Мониторинг производительности

```bash
# Запустите мониторинг в фоне
nohup php scripts/monitor_inventory_performance.php > logs/performance_monitoring.log 2>&1 &

# Проверьте результаты через 5 минут
cat logs/performance_monitoring.log
```

## ✅ Шаг 8: Финальная проверка

### 8.1 Чек-лист проверки

- [ ] Индексы БД созданы успешно
- [ ] Кэширование работает (статус: enabled, файлы создаются)
- [ ] API отвечает быстро (< 100ms для всех endpoints)
- [ ] Дашборд загружается быстро (< 2 секунд)
- [ ] Нет ошибок в логах
- [ ] Мониторинг настроен
- [ ] Резервные копии созданы

### 8.2 Тест нагрузки (опционально)

```bash
# Простой тест нагрузки с помощью curl
for i in {1..10}; do
  time curl -s "http://your-domain.com/api/inventory-analytics.php?action=dashboard" > /dev/null
done
```

### 8.3 Уведомление команды

```bash
# Отправьте уведомление о завершении развертывания
echo "✅ Оптимизации производительности дашборда успешно развернуты на продакшене
📊 Время ответа API: < 100ms
💾 Кэширование: активно
🗂️ Индексы БД: созданы
🌐 Дашборд: http://your-domain.com/html/inventory_marketing_dashboard.php
📈 Ожидаемое улучшение: 200x ускорение для кэшированных запросов" | mail -s "Deployment Complete" team@company.com
```

## 🚨 Откат изменений (если что-то пошло не так)

### В случае проблем:

```bash
# 1. Остановите веб-сервер
sudo systemctl stop apache2  # или nginx

# 2. Восстановите файлы из резервной копии
rm -rf api/
cp -r backup_api_*/ api/

rm -rf html/
cp -r backup_html_*/ html/

# 3. Восстановите базу данных (если нужно)
mysql -u username -p database_name < backup_*.sql

# 4. Запустите веб-сервер
sudo systemctl start apache2  # или nginx

# 5. Проверьте работоспособность
curl -s "http://your-domain.com/api/inventory-analytics.php?action=dashboard"
```

## 📞 Контакты для поддержки

- **Техническая поддержка:** tech-support@company.com
- **Экстренная связь:** +7-XXX-XXX-XXXX
- **Документация:** [ссылка на внутреннюю документацию]

## 📝 Отчет о развертывании

После завершения развертывания заполните:

- **Дата развертывания:** ****\_\_\_****
- **Время начала:** ****\_\_\_****
- **Время завершения:** ****\_\_\_****
- **Ответственный:** ****\_\_\_****
- **Статус:** ✅ Успешно / ❌ С ошибками
- **Комментарии:** ****\_\_\_****

---

**Удачного развертывания! 🚀**
