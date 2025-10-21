#!/bin/bash

echo "🔧 УЛЬТИМАТИВНОЕ ИСПРАВЛЕНИЕ"

# Полностью переписываем nginx конфигурацию
sudo tee /etc/nginx/sites-available/default << 'EOF'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    root /var/www/html;
    index index.php index.html index.htm index.nginx-debian.html;
    server_name _;
    
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
}

server {
    root /var/www/html;
    index index.php index.html index.htm index.nginx-debian.html;
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

# Проверяем и перезагружаем
sudo nginx -t && sudo systemctl reload nginx

# Создаем простой тест
echo '<?php phpinfo(); ?>' | sudo tee /var/www/html/info.php

echo "✅ Готово! Проверьте: https://api.zavodprostavok.ru/info.php"