#!/bin/bash

echo "🔧 Исправление дашборда"
echo "======================"

# Копируем дашборд в правильное место
echo "📋 Копирование дашборда в /var/www/html..."
sudo cp dashboard_marketplace_enhanced.php /var/www/html/
sudo chown www-data:www-data /var/www/html/dashboard_marketplace_enhanced.php
sudo chmod 644 /var/www/html/dashboard_marketplace_enhanced.php

# Проверяем что файл скопировался
echo "✅ Файл дашборда:"
ls -la /var/www/html/dashboard_marketplace_enhanced.php

# Создаем простой тест PHP
echo "🧪 Создание тестового PHP файла..."
echo '<?php echo "PHP работает! " . date("Y-m-d H:i:s"); ?>' | sudo tee /var/www/html/test_simple.php > /dev/null
sudo chown www-data:www-data /var/www/html/test_simple.php

# Тестируем PHP
echo "📡 Тест PHP:"
curl -s https://api.zavodprostavok.ru/test_simple.php

echo ""
echo "📡 Тест дашборда:"
curl -s https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php | head -5

echo ""
echo "📋 Проверка логов nginx:"
sudo tail -5 /var/log/nginx/error.log 2>/dev/null || echo "Нет ошибок в логах"

echo ""
echo "✅ Готово! Дашборд должен быть доступен:"
echo "   https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"