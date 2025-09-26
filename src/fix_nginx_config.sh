#!/bin/bash

# Скрипт для исправления конфигурации Nginx после установки SSL
# Запускать на сервере Elysia под пользователем с sudo правами

echo "🔧 Исправление конфигурации Nginx для api.zavodprostavok.ru"
echo "=================================================="

# Проверяем, что скрипт запущен с sudo
if [ "$EUID" -ne 0 ]; then
    echo "❌ Этот скрипт должен быть запущен с sudo"
    echo "Используйте: sudo bash fix_nginx_config.sh"
    exit 1
fi

# Проверяем существование директории с API
API_DIR="/var/www/mi_core_api/src"
if [ ! -d "$API_DIR" ]; then
    echo "❌ Директория $API_DIR не найдена"
    echo "Убедитесь, что проект находится в правильной директории"
    exit 1
fi

# Проверяем существование API файлов
if [ ! -f "$API_DIR/api/countries.php" ]; then
    echo "❌ API файлы не найдены в $API_DIR/api/"
    echo "Убедитесь, что проект правильно развернут"
    exit 1
fi

echo "✅ API файлы найдены в $API_DIR"

# Создаем резервную копию текущей конфигурации
NGINX_CONFIG="/etc/nginx/sites-enabled/default"
BACKUP_FILE="/etc/nginx/sites-enabled/default.backup.$(date +%Y%m%d_%H%M%S)"

if [ -f "$NGINX_CONFIG" ]; then
    echo "📋 Создаем резервную копию: $BACKUP_FILE"
    cp "$NGINX_CONFIG" "$BACKUP_FILE"
else
    echo "❌ Файл конфигурации Nginx не найден: $NGINX_CONFIG"
    exit 1
fi

# Проверяем, есть ли уже правильная конфигурация
if grep -q "root /var/www/mi_core_api/src" "$NGINX_CONFIG"; then
    echo "✅ Конфигурация уже содержит правильный путь"
else
    echo "🔄 Исправляем путь к файлам API..."
    
    # Заменяем неправильный root путь на правильный
    sed -i 's|root /var/www/html|root /var/www/mi_core_api/src|g' "$NGINX_CONFIG"
    sed -i 's|root /usr/share/nginx/html|root /var/www/mi_core_api/src|g' "$NGINX_CONFIG"
    
    echo "✅ Путь исправлен на: /var/www/mi_core_api/src"
fi

# Проверяем синтаксис Nginx
echo "🔍 Проверяем синтаксис конфигурации Nginx..."
if nginx -t; then
    echo "✅ Синтаксис конфигурации корректен"
    
    # Перезагружаем Nginx
    echo "🔄 Перезагружаем Nginx..."
    systemctl reload nginx
    
    if [ $? -eq 0 ]; then
        echo "✅ Nginx успешно перезагружен"
    else
        echo "❌ Ошибка при перезагрузке Nginx"
        echo "Восстанавливаем резервную копию..."
        cp "$BACKUP_FILE" "$NGINX_CONFIG"
        systemctl reload nginx
        exit 1
    fi
else
    echo "❌ Ошибка в синтаксисе конфигурации Nginx"
    echo "Восстанавливаем резервную копию..."
    cp "$BACKUP_FILE" "$NGINX_CONFIG"
    exit 1
fi

# Проверяем права доступа к файлам
echo "🔍 Проверяем права доступа к файлам API..."
chown -R www-data:www-data "$API_DIR"
chmod -R 755 "$API_DIR"
chmod -R 644 "$API_DIR"/*.php "$API_DIR"/api/*.php

echo "✅ Права доступа настроены"

# Тестируем API
echo "🧪 Тестируем API..."
sleep 2

# Проверяем доступность через curl
if curl -s -o /dev/null -w "%{http_code}" https://api.zavodprostavok.ru/api/countries.php | grep -q "200"; then
    echo "✅ API работает! https://api.zavodprostavok.ru/api/countries.php"
else
    echo "⚠️  API может быть недоступен. Проверьте вручную:"
    echo "   curl https://api.zavodprostavok.ru/api/countries.php"
fi

# Показываем статус сервисов
echo ""
echo "📊 Статус сервисов:"
echo "Nginx: $(systemctl is-active nginx)"
echo "PHP-FPM: $(systemctl is-active php*-fpm | head -1)"

# Показываем логи для диагностики
echo ""
echo "📋 Последние записи в логах Nginx:"
tail -5 /var/log/nginx/error.log

echo ""
echo "🎉 Исправление завершено!"
echo "=================================================="
echo "Проверьте работу API:"
echo "- https://api.zavodprostavok.ru/api/"
echo "- https://api.zavodprostavok.ru/api/countries.php"
echo "- https://api.zavodprostavok.ru/api/test_api_endpoints.html"
echo ""
echo "Если проблемы остаются, проверьте логи:"
echo "- sudo tail -f /var/log/nginx/error.log"
echo "- sudo tail -f /var/log/nginx/access.log"