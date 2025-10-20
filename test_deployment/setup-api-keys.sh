#!/bin/bash

# Интерактивная настройка API ключей для продакшена
# Создан: 09.10.2025

set -e

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}"
echo "🔑 НАСТРОЙКА API КЛЮЧЕЙ ДЛЯ ПРОДАКШЕНА"
echo "====================================="
echo -e "${NC}"

# Проверяем наличие .env.production
if [ ! -f ".env.production" ]; then
    echo -e "${RED}❌ Файл .env.production не найден!${NC}"
    echo "Сначала запустите: ./deployment/production-launch/prepare-production.sh"
    exit 1
fi

echo -e "${YELLOW}⚠️ ВАЖНО: Вводите только реальные продакшен API ключи!${NC}"
echo -e "${YELLOW}Тестовые ключи могут привести к проблемам в продакшене.${NC}"
echo ""

# Функция для безопасного ввода
read_secret() {
    local prompt="$1"
    local var_name="$2"
    local current_value="$3"
    
    echo -e "${BLUE}$prompt${NC}"
    if [ "$current_value" != "your_production_ozon_client_id" ] && [ "$current_value" != "your_production_ozon_api_key" ] && [ "$current_value" != "your_production_wb_api_key" ]; then
        echo -e "${GREEN}Текущее значение: ${current_value:0:10}...${NC}"
        echo -n "Оставить текущее значение? (y/n): "
        read keep_current
        if [ "$keep_current" = "y" ] || [ "$keep_current" = "Y" ]; then
            return
        fi
    fi
    
    echo -n "Введите новое значение: "
    read -s new_value
    echo ""
    
    if [ -n "$new_value" ]; then
        # Обновляем значение в файле
        if [[ "$OSTYPE" == "darwin"* ]]; then
            # macOS
            sed -i '' "s|^${var_name}=.*|${var_name}=${new_value}|" .env.production
        else
            # Linux
            sed -i "s|^${var_name}=.*|${var_name}=${new_value}|" .env.production
        fi
        echo -e "${GREEN}✅ $var_name обновлен${NC}"
    else
        echo -e "${YELLOW}⚠️ Пустое значение, пропускаем${NC}"
    fi
}

# Читаем текущие значения
current_ozon_client_id=$(grep "^OZON_CLIENT_ID=" .env.production | cut -d'=' -f2)
current_ozon_api_key=$(grep "^OZON_API_KEY=" .env.production | cut -d'=' -f2)
current_wb_api_key=$(grep "^WB_API_KEY=" .env.production | cut -d'=' -f2)

echo -e "${BLUE}1️⃣ НАСТРОЙКА OZON API${NC}"
read_secret "Введите Ozon Client ID:" "OZON_CLIENT_ID" "$current_ozon_client_id"
read_secret "Введите Ozon API Key:" "OZON_API_KEY" "$current_ozon_api_key"

echo -e "\n${BLUE}2️⃣ НАСТРОЙКА WILDBERRIES API${NC}"
read_secret "Введите Wildberries API Key:" "WB_API_KEY" "$current_wb_api_key"

# Дополнительные настройки
echo -e "\n${BLUE}3️⃣ ДОПОЛНИТЕЛЬНЫЕ НАСТРОЙКИ${NC}"

echo -n "Настроить SMTP для уведомлений? (y/n): "
read setup_smtp
if [ "$setup_smtp" = "y" ] || [ "$setup_smtp" = "Y" ]; then
    echo -n "SMTP Host: "
    read smtp_host
    echo -n "SMTP Port (587): "
    read smtp_port
    smtp_port=${smtp_port:-587}
    echo -n "SMTP User: "
    read smtp_user
    echo -n "SMTP Password: "
    read -s smtp_password
    echo ""
    echo -n "Email для алертов: "
    read alert_email
    
    # Обновляем SMTP настройки
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s|^SMTP_HOST=.*|SMTP_HOST=${smtp_host}|" .env.production
        sed -i '' "s|^SMTP_PORT=.*|SMTP_PORT=${smtp_port}|" .env.production
        sed -i '' "s|^SMTP_USER=.*|SMTP_USER=${smtp_user}|" .env.production
        sed -i '' "s|^SMTP_PASSWORD=.*|SMTP_PASSWORD=${smtp_password}|" .env.production
        sed -i '' "s|^EMAIL_ALERTS_TO=.*|EMAIL_ALERTS_TO=${alert_email}|" .env.production
    else
        sed -i "s|^SMTP_HOST=.*|SMTP_HOST=${smtp_host}|" .env.production
        sed -i "s|^SMTP_PORT=.*|SMTP_PORT=${smtp_port}|" .env.production
        sed -i "s|^SMTP_USER=.*|SMTP_USER=${smtp_user}|" .env.production
        sed -i "s|^SMTP_PASSWORD=.*|SMTP_PASSWORD=${smtp_password}|" .env.production
        sed -i "s|^EMAIL_ALERTS_TO=.*|EMAIL_ALERTS_TO=${alert_email}|" .env.production
    fi
    
    echo -e "${GREEN}✅ SMTP настройки обновлены${NC}"
fi

echo -n "Настроить Slack webhook для уведомлений? (y/n): "
read setup_slack
if [ "$setup_slack" = "y" ] || [ "$setup_slack" = "Y" ]; then
    echo -n "Slack Webhook URL: "
    read slack_webhook
    
    if [[ "$OSTYPE" == "darwin"* ]]; then
        sed -i '' "s|^SLACK_WEBHOOK_URL=.*|SLACK_WEBHOOK_URL=${slack_webhook}|" .env.production
    else
        sed -i "s|^SLACK_WEBHOOK_URL=.*|SLACK_WEBHOOK_URL=${slack_webhook}|" .env.production
    fi
    
    echo -e "${GREEN}✅ Slack webhook обновлен${NC}"
fi

# Тестирование API ключей
echo -e "\n${BLUE}4️⃣ ТЕСТИРОВАНИЕ API КЛЮЧЕЙ${NC}"

echo -n "Протестировать API ключи? (y/n): "
read test_keys
if [ "$test_keys" = "y" ] || [ "$test_keys" = "Y" ]; then
    echo "Тестируем подключение к Ozon API..."
    
    # Создаем временный скрипт для тестирования
    cat > test_ozon_api.php << 'EOF'
<?php
require_once '.env.production';

// Загружаем переменные из .env.production
$lines = file('.env.production', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    if (strpos($line, '=') === false) continue;
    
    list($name, $value) = explode('=', $line, 2);
    $name = trim($name);
    $value = trim($value);
    
    if ($name === 'OZON_CLIENT_ID') $ozon_client_id = $value;
    if ($name === 'OZON_API_KEY') $ozon_api_key = $value;
}

if (empty($ozon_client_id) || empty($ozon_api_key)) {
    echo "❌ API ключи не настроены\n";
    exit(1);
}

// Тестируем подключение
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api-seller.ozon.ru/v1/warehouse/list');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Client-Id: ' . $ozon_client_id,
    'Api-Key: ' . $ozon_api_key,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $data = json_decode($response, true);
    if (isset($data['result'])) {
        echo "✅ Ozon API работает, найдено складов: " . count($data['result']) . "\n";
    } else {
        echo "⚠️ Ozon API отвечает, но данные неожиданного формата\n";
    }
} else {
    echo "❌ Ошибка Ozon API: HTTP $httpCode\n";
    if ($httpCode === 401) {
        echo "   Проверьте правильность API ключей\n";
    }
}
EOF

    php test_ozon_api.php
    rm test_ozon_api.php
fi

# Финальная проверка
echo -e "\n${BLUE}5️⃣ ФИНАЛЬНАЯ ПРОВЕРКА${NC}"

# Проверяем, что все ключи настроены
missing_keys=()
if grep -q "your_production_ozon_client_id" .env.production; then
    missing_keys+=("OZON_CLIENT_ID")
fi
if grep -q "your_production_ozon_api_key" .env.production; then
    missing_keys+=("OZON_API_KEY")
fi
if grep -q "your_production_wb_api_key" .env.production; then
    missing_keys+=("WB_API_KEY")
fi

if [ ${#missing_keys[@]} -eq 0 ]; then
    echo -e "${GREEN}✅ Все API ключи настроены!${NC}"
    
    echo -e "\n${GREEN}🎉 НАСТРОЙКА ЗАВЕРШЕНА!${NC}"
    echo -e "${YELLOW}📋 СЛЕДУЮЩИЕ ШАГИ:${NC}"
    echo "1. Запустите: ./switch-to-production.sh"
    echo "2. Установите crontab: crontab deployment/production/mdm-crontab.txt"
    echo "3. Протестируйте систему: php health-check.php"
    echo "4. Запустите загрузку складов: php scripts/load-ozon-warehouses.php"
    
else
    echo -e "${YELLOW}⚠️ Не настроены ключи: ${missing_keys[*]}${NC}"
    echo "Запустите скрипт еще раз для настройки оставшихся ключей"
fi

echo -e "\n${BLUE}📄 Конфигурация сохранена в .env.production${NC}"