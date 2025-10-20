#!/bin/bash

echo "🔧 Простое исправление"

# Создаем index.php в корне
echo '<?php
header("Content-Type: text/html; charset=utf-8");
echo "<h1>PHP работает!</h1>";
echo "<p>Время: " . date("Y-m-d H:i:s") . "</p>";
echo "<p><a href=\"dashboard_marketplace_enhanced.php\">Дашборд</a></p>";
?>' | sudo tee /var/www/html/index.php

# Добавляем index.php в nginx
sudo sed -i 's/index index.html index.htm index.nginx-debian.html;/index index.php index.html index.htm index.nginx-debian.html;/' /etc/nginx/sites-available/default

sudo systemctl reload nginx

echo "✅ Готово! Проверьте:"
echo "https://api.zavodprostavok.ru/"
echo "https://api.zavodprostavok.ru/index.php"