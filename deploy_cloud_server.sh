#!/bin/bash

# Развертывание системы пополнения склада на облачном сервере
# Версия для Ubuntu с новой конфигурацией базы данных

set -e

echo "🚀 РАЗВЕРТЫВАНИЕ СИСТЕМЫ ПОПОЛНЕНИЯ СКЛАДА НА ОБЛАЧНОМ СЕРВЕРЕ"
echo "=============================================================="

# Проверяем, что мы на сервере
if [[ ! -f /etc/os-release ]]; then
    echo "❌ Этот скрипт предназначен для Linux серверов"
    exit 1
fi

# Обновляем систему
echo "📦 Обновление системы..."
sudo apt update
sudo apt upgrade -y

# Устанавливаем необходимые пакеты
echo "📦 Установка необходимых пакетов..."
sudo apt install -y python3 python3-pip python3-venv mysql-server git curl

# Создаем виртуальное окружение
echo "🐍 Создание виртуального окружения..."
python3 -m venv venv
source venv/bin/activate

# Устанавливаем Python зависимости
echo "📦 Установка Python зависимостей..."
pip install mysql-connector-python requests

# Настраиваем MySQL
echo "🗄️  Настройка MySQL..."

# Генерируем пароль для replenishment_user
REPLENISHMENT_PASSWORD=$(openssl rand -base64 16 | tr -d "=+/" | cut -c1-16)

# Создаем базу данных и пользователя
echo "📋 Создание базы данных и пользователя..."
sudo mysql -e "CREATE DATABASE IF NOT EXISTS replenishment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "DROP USER IF EXISTS 'replenishment_user'@'localhost';"
sudo mysql -e "CREATE USER 'replenishment_user'@'localhost' IDENTIFIED BY '$REPLENISHMENT_PASSWORD';"
sudo mysql -e "GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

echo "✅ База данных настроена!"

# Создаем конфигурацию
echo "📋 Создание конфигурации..."
mkdir -p importers

cat > importers/config.py << EOF
# Конфигурация базы данных для системы пополнения склада
# Автоматически сгенерировано для облачного сервера

DB_CONFIG = {
    'host': 'localhost',
    'user': 'replenishment_user',
    'password': '$REPLENISHMENT_PASSWORD',
    'database': 'replenishment_db',
    'charset': 'utf8mb4',
    'autocommit': True,
    'raise_on_warnings': True
}

# Настройки системы
SYSTEM_CONFIG = {
    'critical_stockout_threshold': 3,
    'high_priority_threshold': 7,
    'max_recommended_order_multiplier': 3.0,
    'enable_email_alerts': False,
    'alert_email_recipients': [],
    'max_analysis_products': 10000,
    'analysis_batch_size': 1000,
    'data_retention_days': 90
}

# Настройки логирования
LOGGING_CONFIG = {
    'level': 'INFO',
    'format': '%(asctime)s - %(levelname)s - %(message)s',
    'file': 'replenishment.log'
}
EOF

chmod 600 importers/config.py

echo "✅ Конфигурация создана!"

# Применяем схему базы данных
echo "📋 Применение схемы базы данных..."
if [ -f "create_replenishment_schema_safe.sql" ]; then
    mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db < create_replenishment_schema_safe.sql
    echo "✅ Схема базы данных применена!"
else
    echo "⚠️  Файл схемы не найден, пропускаем..."
fi

# Тестируем подключение
echo "📋 Тестирование подключения..."
python3 -c "
import sys
sys.path.append('.')
from replenishment_db_connector import test_connection
if test_connection():
    print('✅ Подключение к базе данных работает!')
else:
    print('❌ Проблема с подключением к базе данных')
    sys.exit(1)
"

# Тестируем компоненты
echo "📋 Тестирование компонентов..."
python3 test_components_init.py

# Создаем systemd сервис
echo "🔧 Создание systemd сервиса..."
sudo tee /etc/systemd/system/replenishment-api.service > /dev/null << EOF
[Unit]
Description=Replenishment API Server
After=network.target mysql.service

[Service]
Type=simple
User=$USER
WorkingDirectory=$(pwd)
Environment=PATH=$(pwd)/venv/bin
ExecStart=$(pwd)/venv/bin/python3 $(pwd)/simple_api_server.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF

# Перезагружаем systemd и запускаем сервис
sudo systemctl daemon-reload
sudo systemctl enable replenishment-api
sudo systemctl start replenishment-api

# Настраиваем nginx (опционально)
if command -v nginx &> /dev/null; then
    echo "🌐 Настройка nginx..."
    sudo tee /etc/nginx/sites-available/replenishment-api > /dev/null << EOF
server {
    listen 80;
    server_name _;

    location / {
        proxy_pass http://localhost:8000;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
EOF

    sudo ln -sf /etc/nginx/sites-available/replenishment-api /etc/nginx/sites-enabled/
    sudo nginx -t && sudo systemctl reload nginx
    echo "✅ Nginx настроен!"
fi

# Настраиваем firewall
echo "🔥 Настройка firewall..."
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 8000/tcp
sudo ufw --force enable

echo ""
echo "🎉 РАЗВЕРТЫВАНИЕ ЗАВЕРШЕНО!"
echo "=========================="
echo "База данных: replenishment_db"
echo "Пользователь: replenishment_user"
echo "Пароль: $REPLENISHMENT_PASSWORD"
echo ""
echo "💾 СОХРАНИТЕ ПАРОЛЬ В БЕЗОПАСНОМ МЕСТЕ!"
echo ""
echo "🌐 Сервис доступен по адресам:"
echo "   - http://$(curl -s ifconfig.me):8000 (прямой доступ)"
if command -v nginx &> /dev/null; then
    echo "   - http://$(curl -s ifconfig.me) (через nginx)"
fi
echo ""
echo "🔧 Управление сервисом:"
echo "   sudo systemctl status replenishment-api"
echo "   sudo systemctl restart replenishment-api"
echo "   sudo systemctl logs -f replenishment-api"
echo ""
echo "🧪 Тестирование:"
echo "   curl http://localhost:8000/api/health"