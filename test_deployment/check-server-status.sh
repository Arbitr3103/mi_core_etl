#!/bin/bash

# Скрипт проверки статуса MDM системы на сервере
# Использование: ./check-server-status.sh [server-ip]

SERVER_IP=${1:-"your-server-ip"}
SERVER_USER="vladimir"
SERVER_PATH="/var/www/mi_core_api"

echo "🔍 ПРОВЕРКА СТАТУСА MDM СИСТЕМЫ НА СЕРВЕРЕ"
echo "=========================================="
echo "Сервер: $SERVER_USER@$SERVER_IP"
echo "Путь: $SERVER_PATH"
echo ""

if [ "$SERVER_IP" = "your-server-ip" ]; then
    echo "❌ Укажите IP адрес сервера:"
    echo "   ./check-server-status.sh 192.168.1.100"
    exit 1
fi

# Команды для проверки
CHECK_COMMANDS="
set -e
cd $SERVER_PATH

echo '📊 СТАТУС СИСТЕМЫ:'
echo '=================='

echo '1️⃣ Конфигурация базы данных:'
grep 'DB_NAME\|DB_USER\|DB_HOST' .env | head -3

echo ''
echo '2️⃣ Подключение к базе данных:'
php -r \"
require_once 'config.php';
try {
    \\\$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
    echo '✅ База данных доступна\n';
} catch (Exception \\\$e) {
    echo '❌ Ошибка БД: ' . \\\$e->getMessage() . '\n';
}
\"

echo '3️⃣ Количество данных в таблицах:'
mysql -u v_admin -p'Arbitr09102022!' mi_core -e \"
SELECT 'dim_products' as table_name, COUNT(*) as count FROM dim_products
UNION ALL
SELECT 'ozon_warehouses', COUNT(*) FROM ozon_warehouses
UNION ALL  
SELECT 'product_master', COUNT(*) FROM product_master
UNION ALL
SELECT 'inventory_data', COUNT(*) FROM inventory_data;
\" 2>/dev/null || echo '❌ Ошибка доступа к таблицам'

echo ''
echo '4️⃣ Health Check:'
php health-check.php 2>/dev/null || echo '❌ Health check не работает'

echo ''
echo '5️⃣ API Analytics (первые 3 строки):'
php -f api/analytics.php 2>/dev/null | head -3 || echo '❌ Analytics API не работает'

echo ''
echo '6️⃣ Crontab задачи:'
crontab -l | grep -v '^#' | wc -l | xargs echo 'Активных задач:'

echo ''
echo '7️⃣ Права доступа к ключевым файлам:'
ls -la health-check.php api/analytics.php scripts/load-ozon-warehouses.php | head -3

echo ''
echo '✅ Проверка завершена!'
"

echo "Подключаемся к серверу и выполняем проверки..."
echo ""

ssh $SERVER_USER@$SERVER_IP "$CHECK_COMMANDS"

echo ""
echo "🎯 РЕКОМЕНДАЦИИ:"
echo "1. Если есть ошибки БД - проверьте конфигурацию .env"
echo "2. Если таблицы пустые - выполните миграцию данных"
echo "3. Если API не работают - проверьте права доступа к файлам"
echo "4. Для полного исправления используйте: ./fix-server-warehouses.sh $SERVER_IP"