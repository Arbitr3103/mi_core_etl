#!/bin/bash

# Простой деплой для PostgreSQL системы
# Все файлы уже настроены для PostgreSQL

echo "🚀 Деплой PostgreSQL системы на market-mi.ru"
echo "============================================="

PRODUCTION_SERVER="market-mi.ru"
PRODUCTION_USER="root"
PRODUCTION_PATH="/var/www/market-mi.ru"

echo "📋 Копирование файлов..."

# Копируем основные файлы
scp postgresql_config.php "$PRODUCTION_USER@$PRODUCTION_SERVER:$PRODUCTION_PATH/config.php"
scp api/inventory-analytics.php "$PRODUCTION_USER@$PRODUCTION_SERVER:$PRODUCTION_PATH/api/"
scp inventory_cache_manager.php "$PRODUCTION_USER@$PRODUCTION_SERVER:$PRODUCTION_PATH/api/"
scp performance_monitor.php "$PRODUCTION_USER@$PRODUCTION_SERVER:$PRODUCTION_PATH/api/"

# Устанавливаем права доступа
ssh "$PRODUCTION_USER@$PRODUCTION_SERVER" << 'EOF'
    chown www-data:www-data /var/www/market-mi.ru/config.php
    chown www-data:www-data /var/www/market-mi.ru/api/inventory-analytics.php
    chown www-data:www-data /var/www/market-mi.ru/api/inventory_cache_manager.php
    chown www-data:www-data /var/www/market-mi.ru/api/performance_monitor.php
    chmod 644 /var/www/market-mi.ru/config.php
    chmod 644 /var/www/market-mi.ru/api/*.php
EOF

echo "✅ Деплой завершен!"
echo "🔗 Проверьте: https://www.market-mi.ru/warehouse-dashboard/"