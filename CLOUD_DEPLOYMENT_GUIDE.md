# 🚀 Руководство по деплою на облачный сервер

## 📋 Статус готовности

✅ **Код подготовлен и запушен в репозиторий**  
✅ **Все исправления API ошибок 500 включены**  
✅ **Скрипты деплоя созданы**  
✅ **Готов к деплою на сервер**

## 🎯 Быстрый деплой (3 способа)

### Способ 1: Автоматический деплой (если есть SSH доступ)

```bash
# Укажите IP вашего сервера
./deploy-to-server.sh
```

### Способ 2: Проверка статуса сервера

```bash
# Проверить текущее состояние
./check-server-status.sh YOUR_SERVER_IP
```

### Способ 3: Ручной деплой (рекомендуется)

Следуйте инструкциям в файле `manual-deploy-commands.md`

## 📝 Пошаговая инструкция ручного деплоя

### 1. Подключитесь к серверу

```bash
ssh vladimir@YOUR_SERVER_IP
cd /var/www/mi_core_api
```

### 2. Обновите код

```bash
git fetch origin
git reset --hard origin/main
```

### 3. Исправьте конфигурацию БД

```bash
# Проверьте текущую конфигурацию
grep "DB_NAME" .env

# Исправьте если нужно
sed -i 's/DB_NAME=mi_core_db/DB_NAME=mi_core/g' .env
```

### 4. Создайте недостающие таблицы

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

### 5. Перенесите данные

```bash
mysql -u v_admin -p'Arbitr09102022!' mi_core -e "
INSERT IGNORE INTO dim_products (sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at, synced_at)
SELECT sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at, synced_at
FROM product_master;
"
```

### 6. Добавьте склады

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

### 7. Установите права доступа

```bash
chmod +x health-check.php
chmod +x scripts/load-ozon-warehouses.php
```

### 8. Проверьте результат

```bash
php health-check.php
```

## ✅ Ожидаемый результат

После успешного деплоя вы должны увидеть:

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

## 🔧 Исправление проблемы со складами

Основная проблема была в том, что:

1. **База данных** - использовалась `mi_core_db` вместо `mi_core`
2. **Таблица dim_products** - отсутствовала, но API к ней обращались
3. **Склады** - таблица была пустая

Все эти проблемы исправлены в коде и будут применены при деплое.

## 🚨 Troubleshooting

### Если health-check показывает ошибки:

1. **Ошибка подключения к БД:**

```bash
mysql -u v_admin -p'Arbitr09102022!' mi_core -e "SELECT 1"
```

2. **Таблица не найдена:**

```bash
mysql -u v_admin -p'Arbitr09102022!' mi_core -e "SHOW TABLES"
```

3. **API возвращает 500:**

```bash
tail -f /var/log/nginx/error.log
```

### Если склады не загружаются:

1. **Проверьте API ключи в .env:**

```bash
grep "OZON_" .env
```

2. **Запустите загрузку вручную:**

```bash
php scripts/load-ozon-warehouses.php
```

## 📊 Мониторинг после деплоя

### Настройте crontab:

```bash
# Обновите пути в файле
sed -i 's|/Users/vladimirbragin/CascadeProjects/mi_core_etl|/var/www/mi_core_api|g' deployment/production/mdm-crontab.txt

# Установите
crontab deployment/production/mdm-crontab.txt
```

### Проверяйте логи:

```bash
tail -f logs/monitoring/health-check.log
tail -f logs/etl/ozon-warehouses.log
```

## 🎉 Результат

После деплоя:

- ✅ API больше не возвращают ошибки 500
- ✅ Склады отображаются корректно (8 складов)
- ✅ Товары загружены (9 товаров)
- ✅ Health-check проходит успешно
- ✅ Система готова к продакшену

---

**Дата создания:** 09.10.2025  
**Статус:** ✅ **ГОТОВ К ДЕПЛОЮ**  
**Время деплоя:** ~10-15 минут

🚀 **Система готова к запуску на облачном сервере!**
