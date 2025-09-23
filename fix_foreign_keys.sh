#!/bin/bash

# Исправление проблемы с внешними ключами в MySQL
# Для использования на сервере где возникла ошибка

set -e

echo "🔧 ИСПРАВЛЕНИЕ ПРОБЛЕМЫ С ВНЕШНИМИ КЛЮЧАМИ"
echo "=========================================="

# Получаем пароль из конфигурации
if [ -f "importers/config.py" ]; then
    REPLENISHMENT_PASSWORD=$(grep "password" importers/config.py | cut -d"'" -f2)
    echo "📋 Пароль найден в конфигурации"
else
    echo "❌ Файл конфигурации не найден!"
    echo "Введите пароль для replenishment_user:"
    read -s REPLENISHMENT_PASSWORD
fi

echo "🗄️  Очистка базы данных с отключением проверки внешних ключей..."

mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db << 'EOF'
-- Отключаем проверку внешних ключей
SET FOREIGN_KEY_CHECKS = 0;

-- Удаляем все таблицы в правильном порядке
DROP TABLE IF EXISTS replenishment_recommendations;
DROP TABLE IF EXISTS replenishment_alerts;
DROP TABLE IF EXISTS sales_data;
DROP TABLE IF EXISTS inventory_data;
DROP TABLE IF EXISTS dim_products;
DROP TABLE IF EXISTS replenishment_settings;

-- Включаем обратно проверку внешних ключей
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Все таблицы удалены успешно!' as status;
EOF

echo "✅ База данных очищена!"

# Применяем чистую схему
echo "📋 Применение чистой схемы..."
if [ -f "create_replenishment_schema_clean.sql" ]; then
    mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db < create_replenishment_schema_clean.sql
    echo "✅ Чистая схема применена!"
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
echo "База данных готова к использованию."
echo ""
echo "🚀 Теперь можно запустить API сервер:"
echo "python3 simple_api_server.py"