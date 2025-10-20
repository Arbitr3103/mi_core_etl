#!/bin/bash

# Скрипт для проверки статуса API на сервере
# Использование: ./check_server_status.sh [server_ip] [username]

SERVER_IP=${1:-"your-server-ip"}
USERNAME=${2:-"root"}
SERVER_PATH="/var/www/mi_core_api"

# Цвета
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

print_status "🔍 ПРОВЕРКА СТАТУСА СЕРВЕРА"
echo "=================================="
print_status "Сервер: $USERNAME@$SERVER_IP"
echo ""

# Проверяем подключение к серверу
if ! ssh -o ConnectTimeout=5 "$USERNAME@$SERVER_IP" "echo 'Подключение успешно'" 2>/dev/null; then
    print_error "❌ Не удается подключиться к серверу"
    exit 1
fi

print_success "✅ Подключение к серверу установлено"

# Проверяем файлы
print_status "📁 Проверка файлов на сервере:"

ssh "$USERNAME@$SERVER_IP" "
    if [[ -f '$SERVER_PATH/api/inventory-analytics.php' ]]; then
        echo '✅ API файл найден'
    else
        echo '❌ API файл отсутствует'
    fi
    
    if [[ -f '$SERVER_PATH/config.php' ]]; then
        echo '✅ Config файл найден'
    else
        echo '❌ Config файл отсутствует'
    fi
    
    if [[ -f '$SERVER_PATH/.env' ]]; then
        echo '✅ .env файл найден'
    else
        echo '❌ .env файл отсутствует'
    fi
"

# Проверяем права доступа
print_status "🔐 Проверка прав доступа:"
ssh "$USERNAME@$SERVER_IP" "
    ls -la $SERVER_PATH/api/inventory-analytics.php 2>/dev/null || echo '❌ Файл не найден'
    ls -la $SERVER_PATH/config.php 2>/dev/null || echo '❌ Файл не найден'
"

# Проверяем веб-сервер
print_status "🌐 Проверка веб-сервера:"
ssh "$USERNAME@$SERVER_IP" "
    if systemctl is-active --quiet nginx; then
        echo '✅ Nginx запущен'
    else
        echo '❌ Nginx не запущен'
    fi
    
    if systemctl is-active --quiet php8.1-fpm; then
        echo '✅ PHP-FPM запущен'
    elif systemctl is-active --quiet php7.4-fpm; then
        echo '✅ PHP-FPM (7.4) запущен'
    else
        echo '❌ PHP-FPM не запущен'
    fi
"

# Тестируем API
print_status "🧪 Тестирование API:"
API_RESPONSE=$(ssh "$USERNAME@$SERVER_IP" "curl -s -w '%{http_code}' 'http://127.0.0.1/api/inventory-analytics.php?action=dashboard' -o /tmp/api_test.json 2>/dev/null || echo '000'")

if [[ "$API_RESPONSE" == "200" ]]; then
    print_success "✅ API отвечает (HTTP 200)"
    
    # Проверяем содержимое ответа
    ssh "$USERNAME@$SERVER_IP" "
        if grep -q 'success' /tmp/api_test.json 2>/dev/null; then
            echo '✅ API возвращает корректные данные'
        else
            echo '❌ API возвращает ошибку:'
            head -n 5 /tmp/api_test.json 2>/dev/null || echo 'Не удается прочитать ответ'
        fi
        rm -f /tmp/api_test.json
    "
else
    print_error "❌ API не отвечает (HTTP $API_RESPONSE)"
fi

# Проверяем логи ошибок
print_status "📋 Последние ошибки в логах:"
ssh "$USERNAME@$SERVER_IP" "
    echo 'Nginx ошибки:'
    tail -n 3 /var/log/nginx/error.log 2>/dev/null || echo 'Лог не найден'
    
    echo 'PHP ошибки:'
    tail -n 3 /var/log/php*.log 2>/dev/null || echo 'Лог не найден'
"

echo ""
print_status "🔗 Полезные ссылки:"
echo "• API: http://$SERVER_IP/api/inventory-analytics.php?action=dashboard"
echo "• Дашборд: http://$SERVER_IP/test_dashboard.html"
echo ""
print_status "🛠️ Команды для диагностики:"
echo "• ssh $USERNAME@$SERVER_IP 'sudo tail -f /var/log/nginx/error.log'"
echo "• ssh $USERNAME@$SERVER_IP 'sudo systemctl restart nginx php8.1-fpm'"