#!/bin/bash

echo "🚀 Деплой исправлений Ozon API"
echo "=============================="

# Проверяем, что мы в правильной директории
if [ ! -f "src/classes/OzonAnalyticsAPI.php" ]; then
    echo "❌ Ошибка: файл OzonAnalyticsAPI.php не найден"
    echo "Убедитесь, что вы находитесь в корневой директории проекта"
    exit 1
fi

echo "✅ Файлы проекта найдены"

# Применяем миграцию БД
echo ""
echo "📊 Применяем миграцию базы данных..."

# Проверяем доступность MySQL
if command -v mysql &> /dev/null; then
    echo "✅ MySQL найден"
    
    # Применяем миграцию
    echo "Применяем миграцию add_revenue_to_funnel_data.sql..."
    mysql -u mi_core_user -psecure_password_123 mi_core_db < migrations/add_revenue_to_funnel_data.sql
    
    if [ $? -eq 0 ]; then
        echo "✅ Миграция успешно применена"
    else
        echo "❌ Ошибка при применении миграции"
        exit 1
    fi
else
    echo "⚠️ MySQL не найден в PATH"
    echo "Примените миграцию вручную:"
    echo "mysql -u mi_core_user -p mi_core_db < migrations/add_revenue_to_funnel_data.sql"
fi

# Проверяем структуру таблицы
echo ""
echo "🔍 Проверяем структуру таблицы ozon_funnel_data..."
mysql -u mi_core_user -psecure_password_123 mi_core_db -e "DESCRIBE ozon_funnel_data;" 2>/dev/null

if [ $? -eq 0 ]; then
    echo "✅ Таблица ozon_funnel_data доступна"
else
    echo "⚠️ Не удалось проверить структуру таблицы"
fi

# Проверяем права доступа к файлам
echo ""
echo "🔐 Проверяем права доступа к файлам..."

if [ -r "src/classes/OzonAnalyticsAPI.php" ]; then
    echo "✅ OzonAnalyticsAPI.php доступен для чтения"
else
    echo "❌ Нет доступа к OzonAnalyticsAPI.php"
fi

if [ -r "src/api/ozon-analytics.php" ]; then
    echo "✅ ozon-analytics.php доступен для чтения"
else
    echo "❌ Нет доступа к ozon-analytics.php"
fi

# Проверяем синтаксис PHP файлов
echo ""
echo "🧪 Проверяем синтаксис PHP файлов..."

if command -v php &> /dev/null; then
    echo "Проверяем OzonAnalyticsAPI.php..."
    php -l src/classes/OzonAnalyticsAPI.php
    
    echo "Проверяем ozon-analytics.php..."
    php -l src/api/ozon-analytics.php
    
    if [ $? -eq 0 ]; then
        echo "✅ Синтаксис PHP файлов корректен"
    else
        echo "❌ Найдены ошибки синтаксиса"
        exit 1
    fi
else
    echo "⚠️ PHP не найден, пропускаем проверку синтаксиса"
fi

echo ""
echo "🎉 Деплой завершен!"
echo ""
echo "📋 Следующие шаги:"
echo "1. Проверьте дашборд Ozon Analytics"
echo "2. Убедитесь, что данные загружаются корректно"
echo "3. Проверьте логи на наличие ошибок"
echo ""
echo "🔗 Полезные команды для диагностики:"
echo "- Проверить логи Apache: tail -f /var/log/apache2/error.log"
echo "- Проверить логи PHP: tail -f /var/log/php_errors.log"
echo "- Тест API: curl 'http://localhost/src/api/ozon-analytics.php?action=funnel-data&date_from=2024-01-01&date_to=2024-01-31'"