#!/bin/bash

echo "🔄 Обновление API на сервере..."

# Скачиваем обновленный API файл
echo "📥 Скачиваем обновленный API файл..."
wget https://raw.githubusercontent.com/Arbitr3103/mi_core_etl/main/api/inventory-v4.php -O /tmp/inventory-v4-updated.php

if [ $? -eq 0 ]; then
    echo "✅ Файл успешно скачан"
    
    # Создаем резервную копию
    echo "💾 Создаем резервную копию текущего API..."
    sudo cp /var/www/html/api/inventory-v4.php /var/www/html/api/inventory-v4.php.backup.$(date +%Y%m%d_%H%M%S)
    
    # Обновляем API файл
    echo "🔄 Обновляем API файл..."
    sudo cp /tmp/inventory-v4-updated.php /var/www/html/api/inventory-v4.php
    
    # Устанавливаем правильные права доступа
    echo "🔐 Устанавливаем права доступа..."
    sudo chown www-data:www-data /var/www/html/api/inventory-v4.php
    sudo chmod 644 /var/www/html/api/inventory-v4.php
    
    echo "✅ API успешно обновлен!"
    
    # Тестируем API
    echo "🧪 Тестируем API endpoints..."
    
    echo "📊 Тестируем overview..."
    curl -s "http://api.zavodprostavok.ru/api/inventory-v4.php?action=overview" | head -c 200
    echo -e "\n"
    
    echo "📦 Тестируем products..."
    curl -s "http://api.zavodprostavok.ru/api/inventory-v4.php?action=products&limit=3" | head -c 200
    echo -e "\n"
    
    echo "⚠️ Тестируем low-stock..."
    curl -s "http://api.zavodprostavok.ru/api/inventory-v4.php?action=low-stock&threshold=10" | head -c 200
    echo -e "\n"
    
    echo "📈 Тестируем analytics..."
    curl -s "http://api.zavodprostavok.ru/api/inventory-v4.php?action=analytics" | head -c 200
    echo -e "\n"
    
    echo "🎉 Обновление завершено!"
    
else
    echo "❌ Ошибка при скачивании файла"
    exit 1
fi