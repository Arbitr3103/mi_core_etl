#!/bin/bash

echo "🔧 ИСПРАВЛЕНИЕ КОРНЕВОЙ ДИРЕКТОРИИ"

# Проблема: nginx все еще читает из /var/www/mi_core_api/src/
# Решение: принудительно заменить root директорию

echo "📋 Текущая конфигурация:"
sudo grep -n "root.*www" /etc/nginx/sites-available/default

echo ""
echo "🔄 Принудительная замена root директории..."

# Заменяем ВСЕ упоминания root на /var/www/html
sudo sed -i 's|root /var/www/mi_core_api.*|root /var/www/html;|g' /etc/nginx/sites-available/default
sudo sed -i 's|root.*mi_core_api.*|root /var/www/html;|g' /etc/nginx/sites-available/default

echo "📋 Новая конфигурация:"
sudo grep -n "root.*www" /etc/nginx/sites-available/default

echo ""
echo "🧪 Проверка и перезагрузка nginx..."
if sudo nginx -t; then
    sudo systemctl reload nginx
    echo "✅ Nginx перезагружен"
else
    echo "❌ Ошибка конфигурации"
    exit 1
fi

echo ""
echo "📡 Тест HTML:"
curl -s https://api.zavodprostavok.ru/test.html

echo ""
echo "🎉 Готово! Теперь nginx должен читать из /var/www/html/"