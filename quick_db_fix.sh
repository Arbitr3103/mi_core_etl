#!/bin/bash

# Быстрое исправление проблемы с MySQL для Ubuntu
# Использует sudo mysql вместо пароля root

set -e

echo "🔧 Быстрое исправление базы данных MySQL"
echo "========================================="

# Генерируем пароль
REPLENISHMENT_PASSWORD=$(openssl rand -base64 12 | tr -d "=+/" | cut -c1-12)

echo "📋 Создание базы данных и пользователя..."

# Создаем базу данных
echo "  - Создание базы replenishment_db..."
sudo mysql -e "CREATE DATABASE IF NOT EXISTS replenishment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Удаляем старого пользователя если есть
echo "  - Удаление старого пользователя..."
sudo mysql -e "DROP USER IF EXISTS 'replenishment_user'@'localhost';" 2>/dev/null || true

# Создаем нового пользователя
echo "  - Создание пользователя replenishment_user..."
sudo mysql -e "CREATE USER 'replenishment_user'@'localhost' IDENTIFIED BY '$REPLENISHMENT_PASSWORD';"

# Даем права
echo "  - Предоставление прав доступа..."
sudo mysql -e "GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

echo "✅ База данных настроена!"

# Применяем схему
echo "📋 Применение схемы базы данных..."
if [ -f "create_replenishment_schema_safe.sql" ]; then
    mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db < create_replenishment_schema_safe.sql
    echo "✅ Безопасная схема применена!"
elif [ -f "create_replenishment_schema_clean.sql" ]; then
    mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db < create_replenishment_schema_clean.sql
    echo "✅ Схема применена!"
else
    echo "⚠️  SQL файлы схемы не найдены"
fi

# Создаем конфигурацию
echo "📋 Создание конфигурации..."
mkdir -p importers

cat > importers/config.py << EOF
# Конфигурация базы данных
DB_CONFIG = {
    'host': 'localhost',
    'user': 'replenishment_user',
    'password': '$REPLENISHMENT_PASSWORD',
    'database': 'replenishment_db',
    'charset': 'utf8mb4',
    'autocommit': True
}

# Настройки системы
SYSTEM_CONFIG = {
    'critical_stockout_threshold': 3,
    'high_priority_threshold': 7,
    'max_recommended_order_multiplier': 3.0,
    'enable_email_alerts': False,
    'alert_email_recipients': []
}
EOF

chmod 600 importers/config.py

echo "✅ Конфигурация создана!"

# Тестируем подключение
echo "📋 Тестирование подключения..."
python3 -c "
import mysql.connector
try:
    conn = mysql.connector.connect(
        host='localhost',
        user='replenishment_user',
        password='$REPLENISHMENT_PASSWORD',
        database='replenishment_db'
    )
    cursor = conn.cursor()
    cursor.execute('SELECT 1')
    result = cursor.fetchone()
    cursor.close()
    conn.close()
    print('✅ Подключение успешно!')
except Exception as e:
    print(f'❌ Ошибка: {e}')
"

echo ""
echo "🎉 ГОТОВО!"
echo "=========="
echo "База данных: replenishment_db"
echo "Пользователь: replenishment_user"
echo "Пароль: $REPLENISHMENT_PASSWORD"
echo ""
echo "💾 СОХРАНИТЕ ПАРОЛЬ В БЕЗОПАСНОМ МЕСТЕ!"
echo ""
echo "🚀 Следующие шаги:"
echo "1. python3 simple_api_server.py"
echo "2. curl http://localhost:8000/api/health"