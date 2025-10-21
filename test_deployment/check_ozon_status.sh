#!/bin/bash

# Быстрая проверка статуса Ozon Analytics

echo "🔍 БЫСТРАЯ ПРОВЕРКА СТАТУСА OZON ANALYTICS"
echo "=========================================="

# Проверка файлов
echo ""
echo "📁 Проверка ключевых файлов:"
files=(
    "src/classes/OzonAnalyticsAPI.php"
    "src/api/ozon-analytics.php"
    "src/js/OzonAnalyticsIntegration.js"
    "migrations/add_ozon_analytics_tables.sql"
)

for file in "${files[@]}"; do
    if [ -f "$file" ]; then
        echo "✅ $file"
    else
        echo "❌ $file - НЕ НАЙДЕН!"
    fi
done

# Проверка API endpoint
echo ""
echo "🌐 Проверка API endpoint:"
response=$(curl -s -w "%{http_code}" -o /dev/null "http://localhost/src/api/ozon-analytics.php?action=health" 2>/dev/null)

if [ "$response" = "200" ]; then
    echo "✅ API endpoint отвечает (HTTP 200)"
else
    echo "❌ API endpoint не отвечает (HTTP $response)"
fi

# Проверка прав доступа
echo ""
echo "🔐 Проверка прав доступа:"
if [ -r "src/classes/OzonAnalyticsAPI.php" ]; then
    echo "✅ Файлы читаемы"
else
    echo "❌ Проблемы с правами доступа"
fi

# Проверка логов
echo ""
echo "📋 Последние ошибки PHP:"
if [ -f "/var/log/php8.1-fpm.log" ]; then
    tail -5 /var/log/php8.1-fpm.log | grep -i "error\|fatal" | tail -2
else
    echo "Лог файл не найден"
fi

echo ""
echo "🚀 Для полной диагностики запустите:"
echo "php debug_ozon_loading.php"