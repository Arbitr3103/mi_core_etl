#!/bin/bash

echo "🔧 Исправляем конфигурацию nginx..."

# Создаем бэкап текущей конфигурации
sudo cp /etc/nginx/sites-available/default /etc/nginx/sites-available/default.backup.$(date +%Y%m%d_%H%M%S)

# Копируем новую конфигурацию
sudo cp nginx-site.conf /etc/nginx/sites-available/default

# Проверяем конфигурацию
echo "Проверяем конфигурацию nginx..."
if sudo nginx -t; then
    echo "✅ Конфигурация nginx корректна"
    
    # Перезапускаем nginx
    echo "Перезапускаем nginx..."
    sudo systemctl restart nginx
    
    echo "✅ Nginx перезапущен"
    
    # Проверяем статус
    sudo systemctl status nginx --no-pager -l
    
    echo ""
    echo "🎉 Конфигурация nginx исправлена!"
    echo "Теперь можно тестировать API:"
    echo "curl http://localhost/api/analytics.php"
    
else
    echo "❌ Ошибка в конфигурации nginx"
    echo "Восстанавливаем бэкап..."
    sudo cp /etc/nginx/sites-available/default.backup.* /etc/nginx/sites-available/default
    exit 1
fi