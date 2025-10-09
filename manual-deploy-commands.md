# 🚀 Команды для ручного деплоя на сервер

## 1. Подключитесь к серверу

```bash
ssh vladimir@your-server-ip
```

## 2. Перейдите в директорию проекта

```bash
cd /var/www/mi_core_api
```

## 3. Обновите код из репозитория

```bash
git fetch origin
git reset --hard origin/main
```

## 4. Исправьте конфигурацию базы данных

```bash
# Проверьте текущую конфигурацию
grep "DB_NAME" .env

# Если там mi_core_db, исправьте на mi_core
sed -i 's/DB_NAME=mi_core_db/DB_NAME=mi_core/g' .env

# Проверьте результат
grep "DB_NAME" .env
```

## 5. Создайте таблицу dim_products (если не существует)

```bash
mysql -u v_admin -p'Arbitr09102022!' mi_core -e "
CREATE TABLE IF NOT EXISTS dim_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku_ozon VARCHAR(255) UNIQUE,
    sku_wb VARCHAR(50),
    barcode VARCHAR(255),
    product_name VARCHAR(500),
    name VARCHAR(500),
    brand VARCHAR(255),
    category VARCHAR(255),
    cost_price DECIMAL(10,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    synced_at TIMESTAMP NULL
) ENGINE=InnoDB;
"
```

## 6. Перенесите данные из product_master в dim_products

```bash
mysql -u v_admin -p'Arbitr09102022!' mi_core -e "
INSERT IGNORE INTO dim_products (sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at, synced_at)
SELECT sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at, synced_at
FROM product_master;
"
```

## 7. Добавьте тестовые склады

```bash
mysql -u v_admin -p'Arbitr09102022!' mi_core -e "
INSERT INTO ozon_warehouses (warehouse_id, name, is_rfbs) VALUES
(1001, 'Склад Москва (Основной)', 0),
(1002, 'Склад СПб (RFBS)', 1),
(1003, 'Склад Екатеринбург', 0),
(1004, 'Склад Новосибирск (RFBS)', 1),
(1005, 'Склад Казань', 0)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    is_rfbs = VALUES(is_rfbs),
    updated_at = NOW();
"
```

## 8. Установите права доступа

```bash
chmod +x health-check.php
chmod +x scripts/load-ozon-warehouses.php
chmod +x scripts/fix-dashboard-errors.php
chmod +x scripts/fix-missing-product-names.php
```

## 9. Проверьте систему

```bash
# Health check
php health-check.php

# Проверьте API
php -f api/analytics.php

# Проверьте количество данных
mysql -u v_admin -p'Arbitr09102022!' mi_core -e "
SELECT 'dim_products' as table_name, COUNT(*) as count FROM dim_products
UNION ALL
SELECT 'ozon_warehouses', COUNT(*) FROM ozon_warehouses
UNION ALL
SELECT 'product_master', COUNT(*) FROM product_master;
"
```

## 10. Настройте API ключи (опционально)

```bash
nano .env
# Добавьте реальные API ключи:
# OZON_CLIENT_ID=your_real_client_id
# OZON_API_KEY=your_real_api_key
# WB_API_KEY=your_real_wb_api_key
```

## 11. Настройте crontab для автоматических задач

```bash
# Обновите пути в crontab файле
sed -i 's|/Users/vladimirbragin/CascadeProjects/mi_core_etl|/var/www/mi_core_api|g' deployment/production/mdm-crontab.txt

# Установите crontab
crontab deployment/production/mdm-crontab.txt

# Проверьте установку
crontab -l
```

## 12. Проверьте финальный результат

```bash
# Должен показать healthy статус
php health-check.php

# Должен показать данные без ошибок 500
curl -s http://localhost/api/analytics.php | head -10
```

## ✅ Ожидаемый результат

Health-check должен показать:

```json
{
  "status": "healthy",
  "timestamp": "2025-10-09T...",
  "checks": {
    "database": {
      "status": "healthy",
      "products_count": 9,
      "response_time_ms": 0
    },
    "api": {
      "status": "healthy",
      "response_time_ms": 10
    }
  }
}
```

## 🚨 Если что-то не работает

1. **Проверьте подключение к БД:**

```bash
mysql -u v_admin -p'Arbitr09102022!' mi_core -e "SELECT 1"
```

2. **Проверьте таблицы:**

```bash
mysql -u v_admin -p'Arbitr09102022!' mi_core -e "SHOW TABLES"
```

3. **Проверьте логи PHP:**

```bash
tail -f /var/log/nginx/error.log
tail -f /var/log/php*-fpm.log
```

4. **Проверьте права доступа:**

```bash
ls -la health-check.php
ls -la api/analytics.php
```
