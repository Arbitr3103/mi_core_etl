# 🚀 Быстрый старт системы пополнения

## Автоматическое развертывание (рекомендуется)

```bash
# 1. Переходим в директорию проекта
cd /var/www/mi_core_etl

# 2. Запускаем автоматическое развертывание
./deployment/replenishment/quick_local_setup.sh

# 3. Тестируем систему
php test_connection.php
php test_quick_calculation.php

# 4. Запускаем веб-сервер (если нужно)
php -S localhost:8080

# 5. Открываем в браузере
# http://localhost:8080/html/replenishment_dashboard.php
```

## Ручное развертывание (если нужен контроль)

### Шаг 1: Подготовка базы данных

```bash
# Создаем пользователя и даем права
mysql -u root -p -e "
CREATE USER 'replenishment_user'@'localhost' IDENTIFIED BY 'secure_password_123';
GRANT SELECT, INSERT, UPDATE, DELETE ON mi_core.* TO 'replenishment_user'@'localhost';
GRANT CREATE, DROP, ALTER ON mi_core.replenishment_* TO 'replenishment_user'@'localhost';
GRANT EXECUTE ON mi_core.* TO 'replenishment_user'@'localhost';
FLUSH PRIVILEGES;
"

# Выполняем миграцию
mysql -u replenishment_user -p'secure_password_123' mi_core < deployment/replenishment/migrate_replenishment_system.sql
```

### Шаг 2: Настройка конфигурации

```bash
# Копируем шаблон конфигурации
cp deployment/replenishment/production/config.production.php config_replenishment.php

# Редактируем для локального использования
nano config_replenishment.php
```

Обновите в конфигурации:

```php
define('DB_USER', 'replenishment_user');
define('DB_PASSWORD', 'secure_password_123');
define('REPLENISHMENT_DEBUG', true);
define('EMAIL_REPORTS_ENABLED', false);
define('API_KEY_REQUIRED', false);
```

### Шаг 3: Тестирование

```bash
# Тест подключения
php -r "
require_once 'config_replenishment.php';
\$pdo = getDbConnection();
echo 'Подключение успешно!';
"

# Тест расчета
php -r "
require_once 'config_replenishment.php';
require_once 'src/Replenishment/ReplenishmentRecommender.php';
\$recommender = new ReplenishmentRecommender();
\$result = \$recommender->calculateRecommendations();
echo 'Расчет: ' . (\$result ? 'Успешно' : 'Ошибка');
"
```

### Шаг 4: Запуск веб-интерфейса

```bash
# Встроенный PHP сервер
php -S localhost:8080

# Или настройка Nginx
sudo cp deployment/replenishment/production/nginx.replenishment.conf /etc/nginx/sites-available/replenishment-local
sudo ln -s /etc/nginx/sites-available/replenishment-local /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## Проверка работы системы

### API endpoints

```bash
# Проверка здоровья системы
curl "http://localhost:8080/api/replenishment.php?action=health"

# Получение конфигурации
curl "http://localhost:8080/api/replenishment.php?action=config"

# Получение рекомендаций
curl "http://localhost:8080/api/replenishment.php?action=recommendations&limit=10"

# Статистика
curl "http://localhost:8080/api/replenishment.php?action=statistics"
```

### Веб-интерфейс

- **Дашборд**: http://localhost:8080/html/replenishment_dashboard.php
- **Мониторинг**: http://localhost:8080/html/monitoring_dashboard.php

## Полезные команды

```bash
# Полный расчет рекомендаций
php cron_replenishment_weekly.php

# Проверка логов
tail -f logs/replenishment/calculation.log

# Проверка базы данных
mysql -u replenishment_user -p'secure_password_123' mi_core -e "
SELECT COUNT(*) as recommendations FROM replenishment_recommendations;
SELECT COUNT(*) as actionable FROM replenishment_recommendations WHERE recommended_quantity > 0;
"

# Топ рекомендаций
mysql -u replenishment_user -p'secure_password_123' mi_core -e "
SELECT product_name, recommended_quantity, ads
FROM replenishment_recommendations
WHERE recommended_quantity > 0
ORDER BY recommended_quantity DESC
LIMIT 10;
"
```

## Создание тестовых данных (если нужно)

```bash
# Если в базе мало данных для тестирования
mysql -u replenishment_user -p'secure_password_123' mi_core << 'EOF'
-- Тестовые товары
INSERT IGNORE INTO dim_products (id, name, sku_ozon, is_active) VALUES
(1001, 'Тестовый товар 1', 'TEST-001', 1),
(1002, 'Тестовый товар 2', 'TEST-002', 1),
(1003, 'Тестовый товар 3', 'TEST-003', 1);

-- Тестовые продажи за последние 30 дней
INSERT IGNORE INTO fact_orders (product_id, order_date, qty, transaction_type)
SELECT
    1001 + (seq % 3) as product_id,
    DATE_SUB(CURDATE(), INTERVAL seq DAY) as order_date,
    1 + (seq % 5) as qty,
    'sale' as transaction_type
FROM (
    SELECT 0 as seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4
    UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9
    UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14
    UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19
    UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24
    UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29
) days;

-- Тестовые запасы
INSERT INTO inventory_data (product_id, current_stock, available_stock, warehouse_name, created_at)
VALUES
(1001, 50, 50, 'Тестовый склад', NOW()),
(1002, 25, 25, 'Тестовый склад', NOW()),
(1003, 75, 75, 'Тестовый склад', NOW())
ON DUPLICATE KEY UPDATE
    current_stock = VALUES(current_stock),
    available_stock = VALUES(available_stock);
EOF
```

## Устранение проблем

### Ошибка подключения к базе данных

```bash
# Проверяем MySQL
sudo systemctl status mysql
mysql -u root -p -e "SELECT 1;"

# Проверяем пользователя
mysql -u replenishment_user -p'secure_password_123' -e "SELECT 1;"
```

### Ошибки PHP

```bash
# Проверяем PHP
php -v
php -m | grep -E "(pdo|mysql)"

# Проверяем логи
tail -f /var/log/php*.log
```

### Пустые результаты расчета

```bash
# Проверяем исходные данные
mysql -u replenishment_user -p'secure_password_123' mi_core -e "
SELECT 'fact_orders' as table_name, COUNT(*) as count FROM fact_orders
UNION ALL
SELECT 'inventory_data', COUNT(*) FROM inventory_data
UNION ALL
SELECT 'dim_products', COUNT(*) FROM dim_products;
"
```

## Следующие шаги

1. **Настройка автоматических расчетов**:

   ```bash
   crontab -e
   # Добавить: 0 6 * * 1 cd /var/www/mi_core_etl && php cron_replenishment_weekly.php
   ```

2. **Настройка мониторинга**:

   ```bash
   ./deployment/replenishment/setup_monitoring.sh development
   ```

3. **Изучение документации**:
   - `deployment/replenishment/LOCAL_DEPLOYMENT_GUIDE.md` - подробная инструкция
   - `deployment/replenishment/CONFIGURATION_GUIDE.md` - настройка параметров
   - `deployment/replenishment/OPERATIONAL_PROCEDURES.md` - эксплуатация системы

---

**Поддержка**: Если возникли проблемы, проверьте логи в `logs/replenishment/` и обратитесь к полной документации.
