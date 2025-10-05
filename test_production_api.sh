#!/bin/bash

echo "🧪 Тестирование Ozon API в продакшене"
echo "===================================="

# Базовый URL (измените на ваш сервер)
BASE_URL="http://localhost"
API_ENDPOINT="$BASE_URL/src/api/ozon-analytics.php"

echo "🔗 Тестируем endpoint: $API_ENDPOINT"
echo ""

# Тест 1: Health check
echo "1️⃣ Тест Health Check..."
curl -s -w "\nHTTP Status: %{http_code}\n" \
     "$API_ENDPOINT?action=health" | head -20

echo ""
echo "----------------------------------------"

# Тест 2: Funnel data
echo "2️⃣ Тест получения данных воронки..."
curl -s -w "\nHTTP Status: %{http_code}\n" \
     "$API_ENDPOINT?action=funnel-data&date_from=2024-01-01&date_to=2024-01-31" | head -30

echo ""
echo "----------------------------------------"

# Тест 3: Demographics
echo "3️⃣ Тест получения демографических данных..."
curl -s -w "\nHTTP Status: %{http_code}\n" \
     "$API_ENDPOINT?action=demographics&date_from=2024-01-01&date_to=2024-01-31" | head -20

echo ""
echo "----------------------------------------"

# Тест 4: Проверка структуры ответа
echo "4️⃣ Проверка структуры JSON ответа..."
RESPONSE=$(curl -s "$API_ENDPOINT?action=funnel-data&date_from=2024-01-01&date_to=2024-01-31")

if echo "$RESPONSE" | python3 -m json.tool > /dev/null 2>&1; then
    echo "✅ JSON структура корректна"
    
    # Проверяем наличие ключевых полей
    if echo "$RESPONSE" | grep -q '"success"'; then
        echo "✅ Поле 'success' найдено"
    else
        echo "❌ Поле 'success' отсутствует"
    fi
    
    if echo "$RESPONSE" | grep -q '"data"'; then
        echo "✅ Поле 'data' найдено"
    else
        echo "❌ Поле 'data' отсутствует"
    fi
    
    if echo "$RESPONSE" | grep -q '"revenue"'; then
        echo "✅ Поле 'revenue' найдено в данных"
    else
        echo "❌ Поле 'revenue' отсутствует в данных"
    fi
    
else
    echo "❌ Некорректная JSON структура"
    echo "Ответ сервера:"
    echo "$RESPONSE" | head -10
fi

echo ""
echo "🎯 Тестирование завершено!"
echo ""
echo "💡 Если тесты не прошли, проверьте:"
echo "- Применена ли миграция БД"
echo "- Доступен ли сервер по адресу $BASE_URL"
echo "- Корректны ли права доступа к файлам"
echo "- Нет ли ошибок в логах сервера"