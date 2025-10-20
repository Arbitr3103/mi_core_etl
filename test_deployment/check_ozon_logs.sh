#!/bin/bash

echo "📋 Проверка логов и диагностика Ozon дашборда"
echo "============================================="

# Проверяем основные файлы
echo "1️⃣ Проверка файлов проекта..."
FILES_TO_CHECK=(
    "src/classes/OzonAnalyticsAPI.php"
    "src/api/ozon-analytics.php"
    "migrations/add_revenue_to_funnel_data.sql"
)

for file in "${FILES_TO_CHECK[@]}"; do
    if [ -f "$file" ]; then
        echo "✅ $file - найден"
    else
        echo "❌ $file - НЕ НАЙДЕН"
    fi
done

echo ""

# Проверяем логи Apache (если доступны)
echo "2️⃣ Проверка логов веб-сервера..."
LOG_PATHS=(
    "/var/log/apache2/error.log"
    "/var/log/httpd/error_log"
    "/usr/local/var/log/apache2/error_log"
    "/opt/lampp/logs/error_log"
)

FOUND_LOG=false
for log_path in "${LOG_PATHS[@]}"; do
    if [ -f "$log_path" ]; then
        echo "✅ Найден лог: $log_path"
        echo "Последние ошибки связанные с Ozon:"
        tail -50 "$log_path" | grep -i "ozon\|analytics" | tail -10
        FOUND_LOG=true
        break
    fi
done

if [ "$FOUND_LOG" = false ]; then
    echo "⚠️ Логи веб-сервера не найдены в стандартных местах"
    echo "Проверьте логи вручную в зависимости от вашей конфигурации"
fi

echo ""

# Проверяем права доступа к файлам
echo "3️⃣ Проверка прав доступа..."
if [ -f "src/classes/OzonAnalyticsAPI.php" ]; then
    PERMS=$(ls -la src/classes/OzonAnalyticsAPI.php)
    echo "Права на OzonAnalyticsAPI.php: $PERMS"
fi

if [ -f "src/api/ozon-analytics.php" ]; then
    PERMS=$(ls -la src/api/ozon-analytics.php)
    echo "Права на ozon-analytics.php: $PERMS"
fi

echo ""

# Проверяем процессы
echo "4️⃣ Проверка запущенных процессов..."
if pgrep -f "apache\|httpd\|nginx" > /dev/null; then
    echo "✅ Веб-сервер запущен"
    ps aux | grep -E "(apache|httpd|nginx)" | grep -v grep | head -3
else
    echo "❌ Веб-сервер не запущен или не найден"
fi

if pgrep -f "mysql\|mariadb" > /dev/null; then
    echo "✅ MySQL/MariaDB запущен"
else
    echo "❌ MySQL/MariaDB не запущен"
fi

echo ""

# Тестируем доступность API
echo "5️⃣ Тест доступности API..."
API_URLS=(
    "http://localhost/src/api/ozon-analytics.php?action=health"
    "http://127.0.0.1/src/api/ozon-analytics.php?action=health"
)

for url in "${API_URLS[@]}"; do
    echo "Тестируем: $url"
    
    if command -v curl &> /dev/null; then
        RESPONSE=$(curl -s -w "HTTP_CODE:%{http_code}" "$url" 2>/dev/null)
        HTTP_CODE=$(echo "$RESPONSE" | grep -o "HTTP_CODE:[0-9]*" | cut -d: -f2)
        BODY=$(echo "$RESPONSE" | sed 's/HTTP_CODE:[0-9]*$//')
        
        if [ "$HTTP_CODE" = "200" ]; then
            echo "✅ API доступен (HTTP $HTTP_CODE)"
            echo "Ответ: ${BODY:0:100}..."
        else
            echo "❌ API недоступен (HTTP $HTTP_CODE)"
            echo "Ответ: $BODY"
        fi
    else
        echo "⚠️ curl не найден, пропускаем тест API"
    fi
    
    echo ""
done

# Проверяем конфигурацию PHP
echo "6️⃣ Проверка конфигурации PHP..."
if command -v php &> /dev/null; then
    echo "✅ PHP найден: $(php -v | head -1)"
    
    # Проверяем важные настройки
    echo "Настройки PHP:"
    php -r "echo 'display_errors: ' . ini_get('display_errors') . \"\n\";"
    php -r "echo 'error_reporting: ' . ini_get('error_reporting') . \"\n\";"
    php -r "echo 'max_execution_time: ' . ini_get('max_execution_time') . \"\n\";"
    php -r "echo 'memory_limit: ' . ini_get('memory_limit') . \"\n\";"
    
    # Проверяем расширения
    echo "Расширения PHP:"
    php -m | grep -E "(pdo|mysql|json|curl)" | sed 's/^/  - /'
    
else
    echo "❌ PHP не найден в PATH"
fi

echo ""

# Итоговые рекомендации
echo "🎯 РЕКОМЕНДАЦИИ ПО ДИАГНОСТИКЕ:"
echo "==============================="
echo "1. Запустите: php debug_ozon_dashboard.php"
echo "2. Откройте в браузере: test_api_browser.html"
echo "3. Проверьте консоль браузера на наличие JavaScript ошибок"
echo "4. Убедитесь, что cron-скрипт обновления данных работает"
echo "5. Проверьте корректность Client ID и API Key для Ozon"
echo ""
echo "📞 Если проблема не решается:"
echo "- Проверьте логи веб-сервера на наличие PHP ошибок"
echo "- Убедитесь, что все файлы имеют правильные права доступа"
echo "- Проверьте, что база данных содержит актуальные данные"
echo "- Убедитесь, что дашборд обращается к правильному API endpoint"