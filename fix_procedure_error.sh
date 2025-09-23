#!/bin/bash

# Исправление ошибки с существующей процедурой
# ERROR 1304: PROCEDURE CleanOldRecommendations already exists

set -e

echo "🔧 ИСПРАВЛЕНИЕ ОШИБКИ С ПРОЦЕДУРОЙ"
echo "=================================="

# Получаем пароль из конфигурации
REPLENISHMENT_PASSWORD=$(grep "password" importers/config.py | cut -d"'" -f2)

echo "🗄️  Удаление существующих процедур..."

mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db << 'EOF'
-- Удаляем все существующие процедуры
DROP PROCEDURE IF EXISTS CleanOldRecommendations;
DROP PROCEDURE IF EXISTS UpdateProductSettings;
DROP PROCEDURE IF EXISTS GenerateInventoryReport;

SELECT 'Процедуры удалены!' as status;
EOF

echo "✅ Процедуры удалены!"

# Применяем схему заново
echo "📋 Повторное применение схемы..."
mysql -u replenishment_user -p"$REPLENISHMENT_PASSWORD" replenishment_db < create_replenishment_schema_clean.sql

echo "✅ Схема применена успешно!"

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
echo "🎉 ПРОБЛЕМА С ПРОЦЕДУРОЙ ИСПРАВЛЕНА!"
echo "==================================="
echo "База данных готова к использованию."
echo ""
echo "🚀 Запуск API сервера:"
echo "python3 simple_api_server.py"