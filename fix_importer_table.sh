#!/bin/bash

echo "🔧 ИСПРАВЛЕНИЕ ТАБЛИЦЫ В ИМПОРТЕРЕ"

# Заменяем inventory_data на inventory в импортере
sed -i 's/inventory_data/inventory/g' /var/www/mi_core_api/importers/stock_importer.py

# Убираем поле last_sync_at которого нет в таблице inventory
sed -i 's/, last_sync_at//g' /var/www/mi_core_api/importers/stock_importer.py
sed -i 's/, NOW()//g' /var/www/mi_core_api/importers/stock_importer.py
sed -i 's/last_sync_at = NOW()/updated_at = CURRENT_TIMESTAMP/g' /var/www/mi_core_api/importers/stock_importer.py

echo "✅ Импортер исправлен для работы с таблицей inventory"

echo "🔄 Запуск исправленного импортера..."
cd /var/www/mi_core_api
python3 importers/stock_importer.py

echo ""
echo "🌐 Проверьте дашборд: https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"