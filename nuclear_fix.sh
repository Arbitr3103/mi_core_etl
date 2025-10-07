#!/bin/bash

echo "☢️ ЯДЕРНОЕ ИСПРАВЛЕНИЕ NGINX"

# Полностью удаляем и пересоздаем конфигурацию
sudo rm /etc/nginx/sites-available/default
sudo rm /etc/nginx/sites-enabled/default

# Создаем чистую конфигурацию
sudo tee /etc/nginx/sites-available/default << 'EOF'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    root /var/www/html;
    index index.php index.html index.htm;
    server_name _;
    
    location / {
        try_files $uri $uri/ =404;
    }
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fmp.sock;
    }
}

server {
    listen 443 ssl;
    listen [::]:443 ssl;
    root /var/www/html;
    index index.php index.html index.htm;
    server_name api.zavodprostavok.ru;

    ssl_certificate /etc/letsencrypt/live/api.zavodprostavok.ru/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/api.zavodprostavok.ru/privkey.pem;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }
}

server {
    listen 80;
    server_name api.zavodprostavok.ru;
    return 301 https://$host$request_uri;
}
EOF

# Включаем сайт
sudo ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/

# Создаем простой тест
echo '<h1>РАБОТАЕТ!</h1>' | sudo tee /var/www/html/index.html

# Перезагружаем
sudo nginx -t && sudo systemctl reload nginx

echo "✅ Готово! Проверьте: https://api.zavodprostavok.ru/"