#!/bin/bash

echo "🔍 ДИАГНОСТИКА СИСТЕМЫ"
echo "====================="

echo "1️⃣ ПРОВЕРКА ДАННЫХ В БД:"
echo "------------------------"
mysql -u root -pQwerty123! mi_core_db -e "
SELECT 
    source,
    COUNT(*) as records,
    SUM(quantity_present) as total_stock
FROM inventory 
GROUP BY source;
"

echo ""
echo "2️⃣ ПРОВЕРКА ФАЙЛОВ:"
echo "-------------------"
echo "Файлы в /var/www/html:"
ls -la /var/www/html/ | grep -E "\.(php|html)$"

echo ""
echo "3️⃣ ПРОВЕРКА NGINX:"
echo "------------------"
echo "Статус nginx:"
sudo systemctl status nginx --no-pager -l

echo ""
echo "Конфигурация для api.zavodprostavok.ru:"
sudo grep -A 5 -B 5 "api.zavodprostavok.ru" /etc/nginx/sites-available/default

echo ""
echo "4️⃣ ПРОВЕРКА PHP:"
echo "----------------"
echo "Статус PHP-FPM:"
sudo systemctl status php8.1-fpm --no-pager -l

echo ""
echo "5️⃣ ТЕСТ ПРОСТОГО HTML:"
echo "----------------------"
echo '<h1>HTML работает!</h1>' | sudo tee /var/www/html/test.html
curl -s https://api.zavodprostavok.ru/test.html

echo ""
echo "6️⃣ ЛОГИ NGINX:"
echo "--------------"
sudo tail -10 /var/log/nginx/error.log

echo ""
echo "✅ Диагностика завершена!"