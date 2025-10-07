#!/bin/bash

echo "🔧 БЕЗОПАСНЫЙ ДЕПЛОЙ С ПРАВАМИ"

# Получаем права для git
echo "📋 Получение прав для git операций..."
sudo chown -R vladimir:vladimir /var/www/mi_core_api

# Git операции
echo "📥 Обновление из репозитория..."
git pull

# Выполняем переданную команду (если есть)
if [ "$1" ]; then
    echo "🔄 Выполнение команды: $1"
    ./$1
fi

# Возвращаем права веб-серверу
echo "🌐 Возврат прав веб-серверу..."
sudo chown -R www-data:www-data /var/www/mi_core_api

echo "✅ Деплой завершен!"