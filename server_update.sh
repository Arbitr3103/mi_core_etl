#!/bin/bash

# Скрипт для обновления кода на сервере
echo "🔄 Обновление кода на сервере..."

# Переходим в директорию проекта
cd /home/vladimir/mi_core_etl

# Сохраняем локальные изменения
echo "💾 Сохраняем локальные изменения..."
git stash

# Обновляем код
echo "📥 Получаем последние изменения..."
git pull origin main

# Проверяем наличие новых файлов Ozon ETL
echo "🔍 Проверяем новые файлы Ozon ETL..."
if [ -d "src/ETL/Ozon" ]; then
    echo "✅ Ozon ETL система найдена"
    ls -la src/ETL/Ozon/
else
    echo "❌ Ozon ETL система не найдена"
fi

# Устанавливаем права доступа
echo "🔐 Настраиваем права доступа..."
sudo chown -R www-data:www-data /home/vladimir/mi_core_etl
sudo chmod -R 755 /home/vladimir/mi_core_etl

# Создаем директории для логов если их нет
sudo mkdir -p /home/vladimir/mi_core_etl/src/ETL/Ozon/Logs
sudo chmod -R 777 /home/vladimir/mi_core_etl/src/ETL/Ozon/Logs

echo "✅ Обновление завершено!"