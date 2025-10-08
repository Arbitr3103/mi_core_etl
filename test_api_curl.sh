#!/bin/bash

# Скрипт для тестирования API endpoints через curl

echo "🔧 Тестирование API endpoints..."
echo "================================"

# Цвета для вывода
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Базовый URL (можно изменить для тестирования на сервере)
BASE_URL="http://localhost:8080"

# Функция тестирования API
test_api() {
    local endpoint="$1"
    local name="$2"
    
    echo -n "Тестируем $name... "
    
    # Выполняем запрос и измеряем время
    local start_time=$(date +%s.%N)
    local response=$(curl -s -w "%{http_code}" -H "Accept: application/json" "$BASE_URL/api/$endpoint")
    local end_time=$(date +%s.%N)
    
    # Извлекаем HTTP код (последние 3 символа)
    local http_code="${response: -3}"
    local json_response="${response%???}"
    
    # Вычисляем время отклика
    local response_time=$(echo "($end_time - $start_time) * 1000" | bc)
    
    if [ "$http_code" = "200" ]; then
        echo -e "${GREEN}✓ OK${NC} (${response_time%.*}ms)"
        
        # Проверяем, что ответ содержит JSON
        if echo "$json_response" | jq . > /dev/null 2>&1; then
            echo "  JSON валиден"
            
            # Показываем краткую информацию из ответа
            if echo "$json_response" | jq -e '.status' > /dev/null 2>&1; then
                local status=$(echo "$json_response" | jq -r '.status')
                echo "  Статус: $status"
            fi
            
            if echo "$json_response" | jq -e '.total_products' > /dev/null 2>&1; then
                local total=$(echo "$json_response" | jq -r '.total_products')
                echo "  Всего товаров: $total"
            fi
        else
            echo -e "  ${YELLOW}⚠ Ответ не является валидным JSON${NC}"
        fi
    else
        echo -e "${RED}✗ FAIL${NC} (HTTP $http_code, ${response_time%.*}ms)"
        
        # Показываем ошибку если есть
        if echo "$json_response" | jq -e '.message' > /dev/null 2>&1; then
            local error_msg=$(echo "$json_response" | jq -r '.message')
            echo "  Ошибка: $error_msg"
        fi
    fi
    
    echo ""
}

# Проверяем доступность jq для парсинга JSON
if ! command -v jq &> /dev/null; then
    echo -e "${YELLOW}⚠ jq не установлен, JSON парсинг будет ограничен${NC}"
    echo ""
fi

# Тестируем все API endpoints
test_api "sync-stats.php" "Статистика синхронизации"
test_api "analytics.php" "Аналитика товаров"
test_api "fix-product-names.php" "Исправление товаров"
test_api "debug.php" "Диагностика системы"

echo "================================"
echo "Тестирование завершено"

# Дополнительная проверка - тест производительности
echo ""
echo "🚀 Тест производительности (10 запросов к sync-stats)..."

total_time=0
successful_requests=0

for i in {1..10}; do
    start_time=$(date +%s.%N)
    response=$(curl -s -w "%{http_code}" "$BASE_URL/api/sync-stats.php")
    end_time=$(date +%s.%N)
    
    http_code="${response: -3}"
    request_time=$(echo "($end_time - $start_time) * 1000" | bc)
    total_time=$(echo "$total_time + $request_time" | bc)
    
    if [ "$http_code" = "200" ]; then
        ((successful_requests++))
    fi
    
    echo -n "."
done

echo ""

if [ $successful_requests -gt 0 ]; then
    avg_time=$(echo "scale=1; $total_time / 10" | bc)
    success_rate=$(echo "scale=1; $successful_requests * 100 / 10" | bc)
    
    echo "Результаты:"
    echo "- Успешных запросов: $successful_requests/10 (${success_rate}%)"
    echo "- Среднее время отклика: ${avg_time}ms"
    
    if (( $(echo "$avg_time < 100" | bc -l) )); then
        echo -e "- Производительность: ${GREEN}Отлично${NC}"
    elif (( $(echo "$avg_time < 200" | bc -l) )); then
        echo -e "- Производительность: ${YELLOW}Хорошо${NC}"
    else
        echo -e "- Производительность: ${RED}Медленно${NC}"
    fi
else
    echo -e "${RED}Все запросы завершились ошибкой${NC}"
fi