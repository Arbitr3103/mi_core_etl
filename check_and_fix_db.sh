#!/bin/bash

# Проверка и исправление доступа к базе данных
# Универсальное решение для сервера

set -e

echo "🔍 ДИАГНОСТИКА И ИСПРАВЛЕНИЕ ДОСТУПА К БД"
echo "========================================"

# Проверяем существование конфигурации
if [ -f "importers/config.py" ]; then
    echo "📋 Конфигурация найдена"
    CURRENT_PASSWORD=$(grep "password" importers/config.py | cut -d"'" -f2)
    echo "   Текущий пароль в конфигурации: ${CURRENT_PASSWORD:0:8}..."
else
    echo "❌ Конфигурация не найдена!"
    CURRENT_PASSWORD=""
fi

# Тестируем текущий пароль
echo "🧪 Тестирование текущего пароля..."
if [ -n "$CURRENT_PASSWORD" ]; then
    if mysql -u replenishment_user -p"$CURRENT_PASSWORD" -e "SELECT 1;" 2>/dev/null; then
        echo "✅ Текущий пароль работает!"
        
        # Проверяем доступ к базе replenishment_db
        if mysql -u replenishment_user -p"$CURRENT_PASSWORD" replenishment_db -e "SELECT 1;" 2>/dev/null; then
            echo "✅ Доступ к базе replenishment_db работает!"
            
            # Проверяем таблицы
            echo "📋 Проверка таблиц..."
            mysql -u replenishment_user -p"$CURRENT_PASSWORD" replenishment_db -e "SHOW TABLES;"
            
            echo "🎉 Все работает! Можно запускать API сервер."
            exit 0
        else
            echo "❌ Нет доступа к базе replenishment_db"
        fi
    else
        echo "❌ Текущий пароль не работает"
    fi
else
    echo "❌ Пароль не найден в конфигурации"
fi

echo ""
echo "🔧 ИСПРАВЛЕНИЕ ПРОБЛЕМЫ..."
echo "========================"

# Генерируем новый пароль
NEW_PASSWORD=$(openssl rand -base64 16 | tr -d "=+/" | cut -c1-16)

echo "🗄️  Пересоздание пользователя через sudo mysql..."

# Пересоздаем пользователя
sudo mysql << EOF
-- Удаляем старого пользователя
DROP USER IF EXISTS 'replenishment_user'@'localhost';

-- Создаем базу данных если не существует
CREATE DATABASE IF NOT EXISTS replenishment_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Создаем нового пользователя
CREATE USER 'replenishment_user'@'localhost' IDENTIFIED BY '$NEW_PASSWORD';

-- Даем все права
GRANT ALL PRIVILEGES ON replenishment_db.* TO 'replenishment_user'@'localhost';

-- Применяем изменения
FLUSH PRIVILEGES;

-- Показываем результат
SELECT 'Пользователь пересоздан!' as status;
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

# Применяем схему
echo "📋 Применение схемы базы данных..."
if [ -f "create_replenishment_schema_clean.sql" ]; then
    mysql -u replenishment_user -p"$NEW_PASSWORD" replenishment_db < create_replenishment_schema_clean.sql
    echo "✅ Схема применена!"
else
    echo "⚠️  Файл схемы не найден, пропускаем..."
fi

# Тестируем подключение
echo "📋 Финальное тестирование..."
python3 -c "
import sys
sys.path.append('.')
from replenishment_db_connector import test_connection
if test_connection():
    print('✅ Все работает идеально!')
else:
    print('❌ Все еще есть проблемы')
    sys.exit(1)
"

echo ""
echo "🎉 ПРОБЛЕМА ПОЛНОСТЬЮ РЕШЕНА!"
echo "============================"
echo "База данных: replenishment_db"
echo "Пользователь: replenishment_user"
echo "Новый пароль: $NEW_PASSWORD"
echo ""
echo "💾 СОХРАНИТЕ ПАРОЛЬ!"
echo ""
echo "🚀 Запуск API сервера:"
echo "python3 simple_api_server.py"