#!/bin/bash

echo "🔍 Поиск корня веб-сайта api.zavodprostavok.ru"
echo "================================================"

# Проверим конфигурацию nginx
echo "📋 Конфигурация nginx:"
sudo grep -r "api.zavodprostavok.ru" /etc/nginx/sites-available/ /etc/nginx/sites-enabled/ 2>/dev/null | head -10

echo ""
echo "📁 Структура /var/www:"
ls -la /var/www/

echo ""
echo "🔍 Поиск PHP файлов:"
sudo find /var/www -name "*.php" | head -10

echo ""
echo "📄 Что возвращает сервер (134 байта):"
curl https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php 2>/dev/null

echo ""
echo "🧪 Тест в /var/www/html:"
echo '<?php echo "Test from html: " . date("Y-m-d H:i:s"); ?>' | sudo tee /var/www/html/test_location.php > /dev/null
curl https://api.zavodprostavok.ru/test_location.php 2>/dev/null

echo ""
echo "✅ Готово!"