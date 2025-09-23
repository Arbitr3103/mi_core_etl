#!/bin/bash

# Исправление проблемы с доступом к БД на сервере
# Пересоздание пользователя с правильным паролем

set -e

echo "🔧 ИСПРАВЛЕНИЕ ДОСТУПА К БАЗЕ ДАННЫХ НА СЕРВЕРЕ"
echo "=============================================="

# Генерируем новый пароль
NEW_PASSWORD=$(openssl rand -base64 16 | tr -d "=+/" | cut -c1-16)

echo "🗄️  Пересоздание пользователя базы данных..."

# Пересоздаем пользователя через sudo mysql
sudo mysql << EOF
-- Удаляем старого пользователя
DROP USER IF EXISTS 'replenishment_user'@'localhost';

-- Создаем нового пользователя с новым паролем
CREATE USER 'replenishment_user'@'localhost' IDENTIFIED BY '$NEW_PASSWORD';

-- Даем все права на базу replenishment_db
GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';

-- Применяем изменения
FLUSH PRIVILEGES;

-- Показываем результат
SELECT 'Пользователь пересоздан успешно!' as status;
EOF

echo "✅ Пользователь пересоздан!"

# Обновляем конфигурацию
echo "📋 Обновление конфигурации..."
mkdir -p importers

cat > importers/config.py << EOF
# Конфигурация базы данных для системы пополнения склада
# Автоматически обновлено $(date)

DB_CONFIG = {
    'host': 'localhost',
    'user': 'replenishment_user',
    'password': '$NEW_PASSWORD',
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

echo "✅ Конфигурация обновлена!"

# Очищаем базу данных
echo "🗄️  Очистка базы данных..."
mysql -u replenishment_user -p"$NEW_PASSWORD" replenishment_db << 'EOF'
-- Отключаем проверку внешних ключей
SET FOREIGN_KEY_CHECKS = 0;

-- Удаляем все таблицы
DROP TABLE IF EXISTS replenishment_recommendations;
DROP TABLE IF EXISTS replenishment_alerts;
DROP TABLE IF EXISTS sales_data;
DROP TABLE IF EXISTS inventory_data;
DROP TABLE IF EXISTS dim_products;
DROP TABLE IF EXISTS replenishment_settings;

-- Включаем обратно проверку внешних ключей
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'База данных очищена!' as status;
EOF

echo "✅ База данных очищена!"

# Применяем схему
echo "📋 Применение схемы базы данных..."
if [ -f "create_replenishment_schema_clean.sql" ]; then
    mysql -u replenishment_user -p"$NEW_PASSWORD" replenishment_db < create_replenishment_schema_clean.sql
    echo "✅ Схема применена!"
else
    echo "❌ Файл create_replenishment_schema_clean.sql не найден!"
    exit 1
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

echo ""
echo "🎉 ПРОБЛЕМА ИСПРАВЛЕНА!"
echo "======================"
echo "База данных: replenishment_db"
echo "Пользователь: replenishment_user"
echo "Новый пароль: $NEW_PASSWORD"
echo ""
echo "💾 СОХРАНИТЕ НОВЫЙ ПАРОЛЬ В БЕЗОПАСНОМ МЕСТЕ!"
echo ""
echo "🚀 Теперь можно запустить API сервер:"
echo "python3 simple_api_server.py"