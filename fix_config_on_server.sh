#!/bin/bash

# Быстрое исправление конфигурации на сервере
echo "🔧 Исправление конфигурации подключения к БД"

# Запрашиваем пароль пользователя replenishment_user
echo -n "Введите пароль для replenishment_user: "
read -s DB_PASSWORD
echo

# Создаем правильную конфигурацию
mkdir -p importers

cat > importers/config.py << EOF
# Конфигурация базы данных для системы пополнения склада
DB_CONFIG = {
    'host': 'localhost',
    'user': 'replenishment_user',
    'password': '$DB_PASSWORD',
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
    'alert_email_recipients': []
}
EOF

chmod 600 importers/config.py

echo "✅ Конфигурация обновлена!"

# Применяем безопасную схему
echo "📋 Применение безопасной схемы..."
mysql -u replenishment_user -p"$DB_PASSWORD" replenishment_db < create_replenishment_schema_safe.sql

if [ $? -eq 0 ]; then
    echo "✅ Схема применена успешно!"
else
    echo "❌ Ошибка применения схемы"
fi

# Тестируем подключение
echo "📋 Тестирование подключения..."
python3 -c "
from importers.ozon_importer import connect_to_db
try:
    conn = connect_to_db()
    cursor = conn.cursor()
    cursor.execute('SELECT COUNT(*) FROM replenishment_settings')
    result = cursor.fetchone()
    cursor.close()
    conn.close()
    print(f'✅ Подключение успешно! Найдено {result[0]} настроек')
except Exception as e:
    print(f'❌ Ошибка: {e}')
"

echo "🎉 Готово! Теперь система подключается к replenishment_db"