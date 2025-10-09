#!/bin/bash

# Скрипт исправления проблемы со складами на сервере
# Создан: 09.10.2025

set -e

SERVER_USER="vladimir"
SERVER_HOST="elysia"
SERVER_PATH="/var/www/mi_core_api"

echo "🔧 ИСПРАВЛЕНИЕ ПРОБЛЕМЫ СО СКЛАДАМИ НА СЕРВЕРЕ"
echo "=============================================="

# Команды для исправления на сервере
FIX_COMMANDS="
set -e
cd $SERVER_PATH

echo '1️⃣ Проверяем текущее состояние базы данных...'

# Проверяем, какая база данных используется
echo 'Текущая конфигурация:'
grep 'DB_NAME' .env || echo 'DB_NAME не найден в .env'

echo '2️⃣ Исправляем конфигурацию базы данных...'

# Исправляем .env файл
if grep -q 'DB_NAME=mi_core_db' .env; then
    sed -i 's/DB_NAME=mi_core_db/DB_NAME=mi_core/g' .env
    echo '✅ Исправлено: DB_NAME=mi_core_db -> DB_NAME=mi_core'
else
    echo 'ℹ️ DB_NAME уже правильный или не найден'
fi

echo '3️⃣ Проверяем таблицу dim_products...'

# Проверяем существование таблицы dim_products
if mysql -u v_admin -p'Arbitr09102022!' mi_core -e 'DESCRIBE dim_products' >/dev/null 2>&1; then
    echo '✅ Таблица dim_products существует'
    PRODUCTS_COUNT=\$(mysql -u v_admin -p'Arbitr09102022!' mi_core -e 'SELECT COUNT(*) FROM dim_products' -s -N)
    echo \"Товаров в dim_products: \$PRODUCTS_COUNT\"
else
    echo '❌ Таблица dim_products не существует, создаем...'
    mysql -u v_admin -p'Arbitr09102022!' mi_core -e \"
    CREATE TABLE dim_products (
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
    \"
    echo '✅ Таблица dim_products создана'
    
    # Переносим данные из product_master
    echo 'Переносим данные из product_master...'
    mysql -u v_admin -p'Arbitr09102022!' mi_core -e \"
    INSERT INTO dim_products (sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at, synced_at)
    SELECT sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at, synced_at
    FROM product_master;
    \"
    echo '✅ Данные перенесены'
fi

echo '4️⃣ Проверяем склады...'

WAREHOUSES_COUNT=\$(mysql -u v_admin -p'Arbitr09102022!' mi_core -e 'SELECT COUNT(*) FROM ozon_warehouses' -s -N)
echo \"Текущее количество складов: \$WAREHOUSES_COUNT\"

if [ \"\$WAREHOUSES_COUNT\" -eq 0 ]; then
    echo 'Добавляем тестовые склады...'
    mysql -u v_admin -p'Arbitr09102022!' mi_core -e \"
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
    \"
    echo '✅ Тестовые склады добавлены'
fi

echo '5️⃣ Тестируем систему...'

# Запускаем health-check
echo 'Health-check результат:'
php health-check.php

echo '6️⃣ Тестируем API...'

# Тестируем analytics API
echo 'Analytics API результат:'
php -f api/analytics.php | head -5

echo '✅ Исправление завершено!'
"

echo "Выполняем исправления на сервере $SERVER_HOST..."
ssh $SERVER_USER@$SERVER_HOST "$FIX_COMMANDS"

echo ""
echo "🎉 ИСПРАВЛЕНИЯ ПРИМЕНЕНЫ!"
echo ""
echo "📋 Проверьте результат:"
echo "ssh $SERVER_USER@$SERVER_HOST 'cd $SERVER_PATH && php health-check.php'"