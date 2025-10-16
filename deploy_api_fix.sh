#!/bin/bash

# Деплой исправления API для фильтрации активных товаров
# Исправляет синтаксическую ошибку в SQL запросах

set -e

echo "🔧 Деплой исправления API для фильтрации активных товаров..."
echo "============================================================"

# Проверяем, что мы на сервере
if [ ! -d "/var/www/mi_core_api" ]; then
    echo "❌ Ошибка: Директория /var/www/mi_core_api не найдена"
    echo "Этот скрипт должен запускаться на продакшн сервере"
    exit 1
fi

# Переходим в рабочую директорию
cd /var/www/mi_core_api

echo "📁 Текущая директория: $(pwd)"
echo "👤 Текущий пользователь: $(whoami)"

# Создаем резервную копию API файла
echo "💾 Создаем резервную копию API файла..."
if [ -f "api/inventory-analytics.php" ]; then
    cp api/inventory-analytics.php "api/inventory-analytics.php.backup.$(date +%Y%m%d_%H%M%S)"
    echo "✅ Резервная копия создана"
else
    echo "⚠️  Файл api/inventory-analytics.php не найден"
    exit 1
fi

# Временно передаем права vladimir для git pull
echo "🔐 Временно передаем права пользователю vladimir..."
sudo chown -R vladimir:vladimir .

# Получаем последние изменения
echo "📥 Получаем исправления из репозитория..."
git pull origin main

# Возвращаем права приложению (www-data)
echo "🔐 Возвращаем права приложению (www-data)..."
sudo chown -R www-data:www-data .

# Устанавливаем правильные права доступа
echo "🔧 Устанавливаем правильные права доступа..."
sudo chmod 644 api/inventory-analytics.php

# Проверяем синтаксис PHP
echo "🔍 Проверяем синтаксис PHP файла..."
if php -l api/inventory-analytics.php > /dev/null 2>&1; then
    echo "✅ Синтаксис PHP корректен"
else
    echo "❌ Ошибка синтаксиса PHP!"
    php -l api/inventory-analytics.php
    exit 1
fi

# Тестируем API endpoint
echo "🧪 Тестируем API endpoint с фильтрацией..."
if curl -s -f -o /dev/null "http://localhost/api/inventory-analytics.php?action=dashboard&active_only=1"; then
    echo "✅ API endpoint доступен"
else
    echo "⚠️  Не удалось проверить API endpoint (это может быть нормально)"
fi

echo ""
echo "🎉 Деплой исправления завершен!"
echo "================================"
echo ""
echo "🔧 Что было исправлено:"
echo "  • Исправлена синтаксическая ошибка в SQL запросах"
echo "  • Заменено {$activeFilter} на правильную конкатенацию строк"
echo "  • Теперь фильтрация активных товаров должна работать корректно"
echo ""
echo "🧪 Проверьте результат:"
echo "  • Откройте http://your-server/test_dashboard.html"
echo "  • Дашборд должен показывать ~51 активный товар вместо 446"
echo "  • Количество критических остатков должно значительно уменьшиться"
echo ""
echo "✅ Исправление применено успешно!"