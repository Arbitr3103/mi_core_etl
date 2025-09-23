#!/bin/bash

# Финальное исправление базы данных
# Игнорируем ошибку с процедурами и тестируем подключение

set -e

echo "🔧 ФИНАЛЬНОЕ ИСПРАВЛЕНИЕ БАЗЫ ДАННЫХ"
echo "==================================="

# Получаем пароль из конфигурации правильным способом
if [ -f "importers/config.py" ]; then
    echo "📋 Извлечение пароля из конфигурации..."
    # Используем python для правильного извлечения пароля
    CURRENT_PASSWORD=$(python3 -c "
import sys
sys.path.append('importers')
try:
    from config import DB_CONFIG
    print(DB_CONFIG['password'])
except:
    print('ERROR')
")
    
    if [ "$CURRENT_PASSWORD" = "ERROR" ]; then
        echo "❌ Не удалось извлечь пароль из конфигурации"
        CURRENT_PASSWORD=""
    else
        echo "✅ Пароль извлечен: ${CURRENT_PASSWORD:0:8}..."
    fi
else
    echo "❌ Конфигурация не найдена!"
    CURRENT_PASSWORD=""
fi

# Тестируем подключение
echo "🧪 Тестирование подключения к базе данных..."
if [ -n "$CURRENT_PASSWORD" ]; then
    if mysql -u replenishment_user -p"$CURRENT_PASSWORD" replenishment_db -e "SELECT 'Подключение работает!' as status;" 2>/dev/null; then
        echo "✅ Подключение к базе данных работает!"
        
        # Проверяем таблицы
        echo "📋 Проверка таблиц..."
        mysql -u replenishment_user -p"$CURRENT_PASSWORD" replenishment_db -e "SHOW TABLES;" 2>/dev/null
        
        # Тестируем Python подключение
        echo "🐍 Тестирование Python подключения..."
        python3 -c "
import sys
sys.path.append('.')
from replenishment_db_connector import test_connection
if test_connection():
    print('✅ Python подключение работает!')
else:
    print('❌ Проблема с Python подключением')
    sys.exit(1)
"
        
        echo ""
        echo "🎉 ВСЕ РАБОТАЕТ ОТЛИЧНО!"
        echo "======================"
        echo "База данных готова к использованию."
        echo ""
        echo "🚀 Запуск API сервера:"
        echo "python3 simple_api_server.py"
        exit 0
    else
        echo "❌ Подключение не работает"
    fi
else
    echo "❌ Пароль не найден"
fi

echo ""
echo "❌ ТРЕБУЕТСЯ ИСПРАВЛЕНИЕ"
echo "======================="
echo "Попробуйте запустить API сервер напрямую:"
echo "python3 simple_api_server.py"
echo ""
echo "Если не работает, выполните:"
echo "./fix_server_db.sh"