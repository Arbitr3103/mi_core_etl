#!/bin/bash

echo "🔧 Включение PHP в nginx для api.zavodprostavok.ru"
echo "=================================================="

# Создаем резервную копию
echo "📋 Создание резервной копии конфигурации..."
sudo cp /etc/nginx/sites-available/default /etc/nginx/sites-available/default.backup.$(date +%Y%m%d_%H%M%S)

# Проверяем текущее состояние PHP секции
echo "🔍 Текущее состояние PHP секции:"
sudo grep -A 5 -B 2 "location ~ \.php" /etc/nginx/sites-available/default | head -10

echo ""
echo "🛠️ Включение PHP обработки..."

# Создаем временный файл с правильной конфигурацией
sudo tee /tmp/nginx_php_config << 'EOF'
	# pass PHP scripts to FastCGI server
	location ~ \.php$ {
		include snippets/fastcgi-php.conf;
		fastcgi_pass unix:/run/php/php8.1-fpm.sock;
	}
EOF

# Заменяем закомментированную секцию PHP на рабочую
sudo sed -i '/# pass PHP scripts to FastCGI server/,/^[[:space:]]*#}$/c\
	# pass PHP scripts to FastCGI server\
	location ~ \.php$ {\
		include snippets/fastcgi-php.conf;\
		fastcgi_pass unix:/run/php/php8.1-fpm.sock;\
	}' /etc/nginx/sites-available/default

echo "✅ PHP секция обновлена"

# Проверяем конфигурацию nginx
echo "🧪 Проверка конфигурации nginx..."
if sudo nginx -t; then
    echo "✅ Конфигурация nginx корректна"
    
    # Перезагружаем nginx
    echo "🔄 Перезагрузка nginx..."
    sudo systemctl reload nginx
    
    echo "✅ Nginx перезагружен"
    
    # Проверяем что PHP работает
    echo "🧪 Тестирование PHP..."
    echo '<?php echo "PHP работает! Время: " . date("Y-m-d H:i:s"); ?>' | sudo tee /var/www/html/php_test.php > /dev/null
    
    echo "📡 Тест через curl..."
    sleep 2
    curl -s https://api.zavodprostavok.ru/php_test.php
    
    echo ""
    echo "🎉 PHP включен! Дашборд должен работать по адресу:"
    echo "   https://api.zavodprostavok.ru/dashboard_marketplace_enhanced.php"
    
else
    echo "❌ Ошибка в конфигурации nginx!"
    echo "🔄 Восстанавливаем резервную копию..."
    sudo cp /etc/nginx/sites-available/default.backup.$(date +%Y%m%d_%H%M%S) /etc/nginx/sites-available/default
    sudo nginx -t
fi

echo ""
echo "✅ Готово!"