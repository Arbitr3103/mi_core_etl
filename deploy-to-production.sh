#!/bin/bash

echo "🚀 РАЗВЕРТЫВАНИЕ API ИСПРАВЛЕНИЙ НА ПРОДАКШН"
echo "============================================="
echo "Дата: $(date)"
echo

# Проверяем, что мы в правильной директории
if [ ! -f "api/inventory-v4.php" ]; then
    echo "❌ Ошибка: файл api/inventory-v4.php не найден"
    echo "Убедитесь, что вы находитесь в корневой директории проекта"
    exit 1
fi

echo "📋 Что будет развернуто:"
echo "- Исправленный API файл inventory-v4.php"
echo "- Скрипт проверки БД check-database-structure.php"
echo "- Скрипт исправления прав fix-api-issues.php"
echo

read -p "Продолжить развертывание? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Развертывание отменено"
    exit 0
fi

# Определяем сервер (замените на ваш)
SERVER_HOST="api.zavodprostavok.ru"
SERVER_USER="vladimir"
SERVER_PATH="/var/www/mi_core_api"

echo "🔄 Этап 1: Создание резервной копии на сервере..."
ssh ${SERVER_USER}@${SERVER_HOST} "cd ${SERVER_PATH} && cp api/inventory-v4.php api/inventory-v4.php.backup.$(date +%Y%m%d_%H%M%S)"

if [ $? -eq 0 ]; then
    echo "✅ Резервная копия создана"
else
    echo "❌ Ошибка создания резервной копии"
    exit 1
fi

echo "📤 Этап 2: Загрузка исправленных файлов..."

# Загружаем основной API файл
scp api/inventory-v4.php ${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/api/
if [ $? -eq 0 ]; then
    echo "✅ API файл загружен"
else
    echo "❌ Ошибка загрузки API файла"
    exit 1
fi

# Загружаем вспомогательные скрипты
scp check-database-structure.php ${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/
scp fix-api-issues.php ${SERVER_USER}@${SERVER_HOST}:${SERVER_PATH}/

echo "🔧 Этап 3: Проверка структуры БД на сервере..."
ssh ${SERVER_USER}@${SERVER_HOST} "cd ${SERVER_PATH} && php check-database-structure.php"

echo "🧪 Этап 4: Тестирование API endpoints..."

# Тестируем основные endpoints
endpoints=("overview" "stats" "products" "critical" "test")

for endpoint in "${endpoints[@]}"; do
    echo "Тестируем: $endpoint"
    
    response=$(curl -s -w "%{http_code}" "http://${SERVER_HOST}/api/inventory-v4.php?action=$endpoint")
    http_code="${response: -3}"
    body="${response%???}"
    
    if [ "$http_code" = "200" ]; then
        if echo "$body" | grep -q '"success":true'; then
            echo "✅ $endpoint: OK"
        else
            echo "⚠️ $endpoint: HTTP 200, но ошибка в ответе"
            echo "   $(echo "$body" | head -c 100)..."
        fi
    else
        echo "❌ $endpoint: HTTP $http_code"
    fi
done

echo
echo "🎯 Этап 5: Финальная проверка..."

# Проверяем основной endpoint overview
overview_response=$(curl -s "http://${SERVER_HOST}/api/inventory-v4.php?action=overview")

if echo "$overview_response" | grep -q '"success":true'; then
    echo "✅ API полностью функционален"
    
    # Извлекаем статистику
    if echo "$overview_response" | grep -q '"total_products"'; then
        total_products=$(echo "$overview_response" | grep -o '"total_products":[0-9]*' | cut -d':' -f2)
        products_in_stock=$(echo "$overview_response" | grep -o '"products_in_stock":[0-9]*' | cut -d':' -f2)
        echo "📊 Статистика: $products_in_stock из $total_products товаров в наличии"
    fi
else
    echo "❌ API не работает корректно"
    echo "Ответ: $(echo "$overview_response" | head -c 200)"
fi

echo
echo "🏁 РАЗВЕРТЫВАНИЕ ЗАВЕРШЕНО"
echo "=========================="
echo "✅ Исправления применены"
echo "✅ API endpoints протестированы"
echo
echo "🔗 Доступные endpoints:"
echo "http://${SERVER_HOST}/api/inventory-v4.php?action=overview"
echo "http://${SERVER_HOST}/api/inventory-v4.php?action=products&limit=10"
echo "http://${SERVER_HOST}/api/inventory-v4.php?action=critical&threshold=5"
echo
echo "📋 Следующие шаги:"
echo "1. Проверить дашборд: https://${SERVER_HOST}/dashboard_inventory_v4.php"
echo "2. Исправить права БД: ssh ${SERVER_USER}@${SERVER_HOST} 'cd ${SERVER_PATH} && php fix-api-issues.php'"
echo "3. Мониторить логи ошибок"