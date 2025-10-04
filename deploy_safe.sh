#!/bin/bash

# Безопасный скрипт деплоя для сервера
# Автоматически сохраняет локальные изменения и обновляет код

echo "🚀 Начинаем безопасный деплой..."

# Переходим в рабочую директорию
cd /var/www/mi_core_api

# Проверяем, есть ли изменения
if ! git diff-index --quiet HEAD --; then
    echo "📦 Сохраняем локальные изменения..."
    git stash push -m "Auto-save before deploy $(date)"
    STASHED=true
else
    echo "✅ Локальных изменений нет"
    STASHED=false
fi

# Получаем права на файлы
echo "🔧 Получаем права на файлы..."
sudo chown -R vladimir:vladimir /var/www/mi_core_api

# Подтягиваем изменения
echo "📥 Подтягиваем обновления с GitHub..."
git pull origin main

if [ $? -ne 0 ]; then
    echo "❌ Ошибка при git pull"
    exit 1
fi

# Копируем файлы в src/
echo "📋 Копируем файлы в src/..."
cp dashboard_marketplace_enhanced.php src/ 2>/dev/null || echo "⚠️  dashboard_marketplace_enhanced.php не найден"
cp InventoryAPI_Fixed.php src/ 2>/dev/null || echo "⚠️  InventoryAPI_Fixed.php не найден"
cp inventory_api_endpoint.php src/ 2>/dev/null || echo "⚠️  inventory_api_endpoint.php не найден"
cp test_inventory_module.php src/ 2>/dev/null || echo "⚠️  test_inventory_module.php не найден"

# Восстанавливаем локальные изменения если были
if [ "$STASHED" = true ]; then
    echo "🔄 Восстанавливаем локальные изменения..."
    git stash pop
    
    if [ $? -ne 0 ]; then
        echo "⚠️  Возможны конфликты при восстановлении изменений"
        echo "💡 Проверьте git status и разрешите конфликты вручную"
    fi
fi

# Устанавливаем права для веб-сервера
echo "🔐 Устанавливаем права для веб-сервера..."
sudo chown -R www-data:www-data /var/www/mi_core_api

# Перезапускаем PHP-FPM
echo "🔄 Перезапускаем PHP-FPM..."
sudo systemctl restart php8.1-fpm

echo "✅ Деплой завершен успешно!"
echo "🌐 Дашборд доступен по адресу: https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"

# Показываем статус
echo ""
echo "📊 Статус git:"
git status --porcelain

echo ""
echo "📁 Файлы в src/:"
ls -la src/*.php | grep -E "(dashboard|inventory|test)" | head -5