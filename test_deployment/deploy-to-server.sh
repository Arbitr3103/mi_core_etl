#!/bin/bash

# Скрипт деплоя MDM системы на облачный сервер
# Создан: 09.10.2025
# Статус: Готов для деплоя после исправления API проблем

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Конфигурация сервера
SERVER_USER="vladimir"
SERVER_HOST="your-server-ip-or-domain"
SERVER_PATH="/var/www/mi_core_api"
REPO_URL="https://github.com/Arbitr3103/mi_core_etl.git"

echo -e "${BLUE}"
echo "🚀 ДЕПЛОЙ MDM СИСТЕМЫ НА ОБЛАЧНЫЙ СЕРВЕР"
echo "========================================"
echo -e "${NC}"

echo -e "${YELLOW}📋 ПАРАМЕТРЫ ДЕПЛОЯ:${NC}"
echo "Сервер: $SERVER_USER@$SERVER_HOST"
echo "Путь: $SERVER_PATH"
echo "Репозиторий: $REPO_URL"
echo ""

# Подтверждение деплоя
echo -n "Продолжить деплой? (y/n): "
read confirmation
if [ "$confirmation" != "y" ] && [ "$confirmation" != "Y" ]; then
    echo "Деплой отменен"
    exit 0
fi

echo -e "\n${BLUE}1️⃣ ПОДКЛЮЧЕНИЕ К СЕРВЕРУ И ОБНОВЛЕНИЕ КОДА${NC}"

# Создаем команды для выполнения на сервере
DEPLOY_COMMANDS="
set -e

echo '📦 Переходим в директорию проекта...'
cd $SERVER_PATH

echo '🔄 Создаем резервную копию...'
cp .env .env.backup.\$(date +%Y%m%d_%H%M%S) || echo 'Нет .env файла для бэкапа'

echo '📥 Обновляем код из репозитория...'
git fetch origin
git reset --hard origin/main

echo '🔧 Устанавливаем права доступа...'
chmod +x health-check.php
chmod +x scripts/load-ozon-warehouses.php
chmod +x scripts/fix-dashboard-errors.php
chmod +x scripts/fix-missing-product-names.php

echo '⚙️ Проверяем конфигурацию...'
if [ ! -f '.env' ]; then
    echo 'Создаем .env файл из примера...'
    cp .env.example .env
    echo 'ВНИМАНИЕ: Настройте .env файл с правильными параметрами!'
fi

echo '🗄️ Проверяем базу данных...'
php -r \"
require_once 'config.php';
try {
    \\\$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
    echo 'База данных доступна\n';
} catch (Exception \\\$e) {
    echo 'ОШИБКА БД: ' . \\\$e->getMessage() . '\n';
    exit(1);
}
\"

echo '📊 Проверяем таблицы...'
mysql -u \$DB_USER -p\$DB_PASSWORD \$DB_NAME -e \"
SELECT 
    'dim_products' as table_name, COUNT(*) as count FROM dim_products
UNION ALL
SELECT 
    'ozon_warehouses', COUNT(*) FROM ozon_warehouses
UNION ALL  
SELECT 
    'product_master', COUNT(*) FROM product_master;
\" || echo 'Некоторые таблицы могут отсутствовать'

echo '🏥 Запускаем health-check...'
php health-check.php

echo '✅ Деплой завершен успешно!'
"

echo "Выполняем команды на сервере..."
ssh $SERVER_USER@$SERVER_HOST "$DEPLOY_COMMANDS"

echo -e "\n${BLUE}2️⃣ ПРОВЕРКА ДЕПЛОЯ${NC}"

echo "Проверяем health-check на сервере..."
HEALTH_RESULT=$(ssh $SERVER_USER@$SERVER_HOST "cd $SERVER_PATH && php health-check.php")

if echo "$HEALTH_RESULT" | grep -q '"status": "healthy"'; then
    echo -e "${GREEN}✅ Health-check прошел успешно!${NC}"
else
    echo -e "${RED}❌ Проблемы с health-check:${NC}"
    echo "$HEALTH_RESULT"
fi

echo -e "\n${BLUE}3️⃣ НАСТРОЙКА ПРОДАКШЕН СРЕДЫ${NC}"

echo "Создаем скрипт настройки на сервере..."
SETUP_SCRIPT="
echo '🔧 Настройка продакшен среды...'

# Проверяем, что таблица dim_products существует
mysql -u v_admin -p'Arbitr09102022!' mi_core -e \"
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
\"

# Переносим данные из product_master если dim_products пустая
PRODUCTS_COUNT=\$(mysql -u v_admin -p'Arbitr09102022!' mi_core -e \"SELECT COUNT(*) FROM dim_products\" -s -N)
if [ \"\$PRODUCTS_COUNT\" -eq 0 ]; then
    echo 'Переносим данные из product_master в dim_products...'
    mysql -u v_admin -p'Arbitr09102022!' mi_core -e \"
    INSERT INTO dim_products (sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at, synced_at)
    SELECT sku_ozon, sku_wb, barcode, product_name, name, brand, category, cost_price, created_at, updated_at, synced_at
    FROM product_master;
    \"
    echo 'Данные перенесены!'
fi

# Проверяем склады
WAREHOUSES_COUNT=\$(mysql -u v_admin -p'Arbitr09102022!' mi_core -e \"SELECT COUNT(*) FROM ozon_warehouses\" -s -N)
echo \"Складов в системе: \$WAREHOUSES_COUNT\"

# Запускаем загрузку складов
echo 'Загружаем склады Ozon...'
php scripts/load-ozon-warehouses.php || echo 'Проблема с загрузкой складов (возможно API ключи не настроены)'

echo 'Настройка завершена!'
"

ssh $SERVER_USER@$SERVER_HOST "cd $SERVER_PATH && $SETUP_SCRIPT"

echo -e "\n${GREEN}🎉 ДЕПЛОЙ ЗАВЕРШЕН!${NC}"

echo -e "\n${YELLOW}📋 СЛЕДУЮЩИЕ ШАГИ:${NC}"
echo "1. Настройте API ключи в .env файле на сервере"
echo "2. Проверьте работу системы: ssh $SERVER_USER@$SERVER_HOST 'cd $SERVER_PATH && php health-check.php'"
echo "3. Настройте crontab: ssh $SERVER_USER@$SERVER_HOST 'crontab $SERVER_PATH/deployment/production/mdm-crontab.txt'"
echo "4. Мониторьте логи: ssh $SERVER_USER@$SERVER_HOST 'tail -f $SERVER_PATH/logs/production/*.log'"

echo -e "\n${BLUE}🔗 ПОЛЕЗНЫЕ КОМАНДЫ:${NC}"
echo "# Подключение к серверу:"
echo "ssh $SERVER_USER@$SERVER_HOST"
echo ""
echo "# Проверка статуса:"
echo "ssh $SERVER_USER@$SERVER_HOST 'cd $SERVER_PATH && php health-check.php'"
echo ""
echo "# Просмотр логов:"
echo "ssh $SERVER_USER@$SERVER_HOST 'cd $SERVER_PATH && tail -f logs/monitoring/health-check.log'"
echo ""
echo "# Настройка API ключей:"
echo "ssh $SERVER_USER@$SERVER_HOST 'cd $SERVER_PATH && nano .env'"

echo -e "\n${GREEN}✅ Система развернута на сервере $SERVER_HOST!${NC}"