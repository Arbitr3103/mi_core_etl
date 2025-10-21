#!/bin/bash

echo "🔧 ФИНАЛЬНОЕ ИСПРАВЛЕНИЕ ДАШБОРДА"
echo "================================="

# 1. Включаем PHP в nginx
echo "📝 Включение PHP в nginx..."
sudo cp /etc/nginx/sites-available/default /etc/nginx/sites-available/default.backup

# Создаем правильную конфигурацию nginx
sudo tee /tmp/nginx_config << 'EOF'
server {
    root /var/www/html;
    index index.html index.htm index.nginx-debian.html index.php;
    server_name api.zavodprostavok.ru;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }

    listen [::]:443 ssl ipv6only=on;
    listen 443 ssl;
    ssl_certificate /etc/letsencrypt/live/api.zavodprostavok.ru/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.zavodprostavok.ru/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;
}

server {
    if ($host = api.zavodprostavok.ru) {
        return 301 https://$host$request_uri;
    }
    listen 80;
    listen [::]:80;
    server_name api.zavodprostavok.ru;
    return 404;
}
EOF

# Заменяем конфигурацию для api.zavodprostavok.ru
echo "🔄 Обновление конфигурации nginx..."
sudo sed -i '/server_name api.zavodprostavok.ru/,/^}/c\
server {\
    root /var/www/html;\
    index index.html index.htm index.nginx-debian.html index.php;\
    server_name api.zavodprostavok.ru;\
\
    location / {\
        try_files $uri $uri/ =404;\
    }\
\
    location ~ \.php$ {\
        include snippets/fastcgi-php.conf;\
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;\
    }\
\
    location ~ /\.ht {\
        deny all;\
    }\
\
    listen [::]:443 ssl ipv6only=on;\
    listen 443 ssl;\
    ssl_certificate /etc/letsencrypt/live/api.zavodprostavok.ru/fullchain.pem;\
    ssl_certificate_key /etc/letsencrypt/live/api.zavodprostavok.ru/privkey.pem;\
    include /etc/letsencrypt/options-ssl-nginx.conf;\
    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;\
}' /etc/nginx/sites-available/default

# 2. Создаем простой тестовый файл
echo "📄 Создание тестового PHP файла..."
echo '<?php echo "PHP работает! " . date("Y-m-d H:i:s"); ?>' | sudo tee /var/www/html/test.php

# 3. Проверяем и перезагружаем nginx
echo "🧪 Проверка конфигурации nginx..."
if sudo nginx -t; then
    echo "✅ Конфигурация корректна"
    sudo systemctl reload nginx
    echo "🔄 Nginx перезагружен"
else
    echo "❌ Ошибка в конфигурации, восстанавливаем backup"
    sudo cp /etc/nginx/sites-available/default.backup /etc/nginx/sites-available/default
    exit 1
fi

# 4. Тестируем PHP
echo "📡 Тест PHP:"
sleep 2
curl -s https://api.zavodprostavok.ru/test.php

echo ""
echo "📡 Тест дашборда:"
curl -s https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php | head -5

echo ""
echo "🎉 ГОТОВО! Если видите HTML выше - дашборд работает!"
echo "   https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"