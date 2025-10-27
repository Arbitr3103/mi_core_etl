#!/bin/bash

# Скрипт для обновления Ozon ETL системы на сервере
echo "🚀 Обновление Ozon ETL системы..."

# Временно изменяем права на папку для пользователя vladimir
echo "🔐 Временно изменяем права доступа..."
echo "qwert1234" | sudo -S chown -R vladimir:vladimir /home/vladimir/mi_core_etl/src/ETL/

# Создаем папку Ozon если её нет
mkdir -p /home/vladimir/mi_core_etl/src/ETL/Ozon

# Копируем новую систему Ozon ETL
echo "📁 Копируем систему Ozon ETL..."
cp -r /home/vladimir/mi_core_etl_new/src/ETL/Ozon/* /home/vladimir/mi_core_etl/src/ETL/Ozon/

# Копируем миграции
echo "🗄️ Копируем миграции..."
mkdir -p /home/vladimir/mi_core_etl/migrations
cp /home/vladimir/mi_core_etl_new/migrations/007_create_ozon_etl_schema.sql /home/vladimir/mi_core_etl/migrations/ 2>/dev/null || echo "Миграция уже существует"

# Копируем спецификации
echo "📋 Копируем спецификации..."
cp -r /home/vladimir/mi_core_etl_new/.kiro /home/vladimir/mi_core_etl/ 2>/dev/null || echo "Спецификации уже существуют"

# Создаем директории для логов
echo "📝 Создаем директории для логов..."
mkdir -p /home/vladimir/mi_core_etl/src/ETL/Ozon/Logs

# Возвращаем права обратно www-data
echo "🔒 Возвращаем права доступа www-data..."
echo "qwert1234" | sudo -S chown -R www-data:www-data /home/vladimir/mi_core_etl
echo "qwert1234" | sudo -S chmod -R 755 /home/vladimir/mi_core_etl
echo "qwert1234" | sudo -S chmod -R 777 /home/vladimir/mi_core_etl/src/ETL/Ozon/Logs

echo "✅ Ozon ETL система успешно обновлена!"

# Проверяем результат
echo "🔍 Проверяем установку..."
ls -la /home/vladimir/mi_core_etl/src/ETL/Ozon/Scripts/ | head -5