#!/bin/bash

# Супер-быстрое применение исправленной схемы
# Используйте если база данных уже создана, но схема не применилась

echo "🔧 Применение исправленной схемы базы данных"
echo "============================================="

# Проверяем наличие файлов
if [ -f "create_replenishment_schema_safe.sql" ]; then
    SCHEMA_FILE="create_replenishment_schema_safe.sql"
    echo "📋 Используем безопасную схему: $SCHEMA_FILE"
elif [ -f "create_replenishment_schema_clean.sql" ]; then
    SCHEMA_FILE="create_replenishment_schema_clean.sql"
    echo "📋 Используем чистую схему: $SCHEMA_FILE"
else
    echo "❌ SQL файлы схемы не найдены!"
    exit 1
fi

# Запрашиваем данные подключения
echo -n "Введите пароль для replenishment_user: "
read -s DB_PASSWORD
echo

# Применяем схему
echo "📋 Применение схемы..."
mysql -u replenishment_user -p"$DB_PASSWORD" replenishment_db < "$SCHEMA_FILE"

if [ $? -eq 0 ]; then
    echo "✅ Схема применена успешно!"
    
    # Проверяем созданные таблицы
    echo "📋 Проверка созданных таблиц..."
    mysql -u replenishment_user -p"$DB_PASSWORD" replenishment_db -e "
    SELECT 
        TABLE_NAME as 'Таблица',
        TABLE_ROWS as 'Строк'
    FROM information_schema.TABLES 
    WHERE TABLE_SCHEMA = 'replenishment_db'
    ORDER BY TABLE_NAME;
    "
    
    echo ""
    echo "🎉 ГОТОВО!"
    echo "=========="
    echo "✅ База данных настроена"
    echo "✅ Схема применена"
    echo "✅ Тестовые данные добавлены"
    echo ""
    echo "🚀 Теперь можете запускать:"
    echo "python3 simple_api_server.py"
    
else
    echo "❌ Ошибка применения схемы"
    echo "💡 Попробуйте:"
    echo "1. Проверить пароль"
    echo "2. Убедиться что база replenishment_db существует"
    echo "3. Проверить права пользователя replenishment_user"
fi