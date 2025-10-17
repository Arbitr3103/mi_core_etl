#!/bin/bash

# Скрипт для переключения между локальной и продакшен конфигурацией
# Использование: ./switch_config.sh [local|production]

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

CONFIG_LOCAL="$PROJECT_ROOT/config_replenishment.php"
CONFIG_PRODUCTION="$SCRIPT_DIR/config_production.php"
CONFIG_BACKUP="$PROJECT_ROOT/config_replenishment.backup.php"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1"
}

show_usage() {
    echo "Использование: $0 [local|production]"
    echo ""
    echo "Команды:"
    echo "  local       - Переключиться на локальную конфигурацию"
    echo "  production  - Переключиться на продакшен конфигурацию"
    echo "  status      - Показать текущую конфигурацию"
    echo ""
    exit 1
}

show_status() {
    if [ -f "$CONFIG_LOCAL" ]; then
        local env=$(grep "define('ENVIRONMENT'" "$CONFIG_LOCAL" | cut -d"'" -f4)
        local debug=$(grep "define('DEBUG_MODE'" "$CONFIG_LOCAL" | cut -d, -f2 | tr -d ' );')
        
        echo "Текущая конфигурация:"
        echo "  Окружение: $env"
        echo "  Отладка: $debug"
        echo "  Файл: $CONFIG_LOCAL"
    else
        echo "Конфигурационный файл не найден: $CONFIG_LOCAL"
    fi
}

switch_to_local() {
    log "Переключение на локальную конфигурацию..."
    
    if [ -f "$CONFIG_LOCAL" ]; then
        # Создаем резервную копию текущей конфигурации
        cp "$CONFIG_LOCAL" "$CONFIG_BACKUP"
        log "Создана резервная копия: $CONFIG_BACKUP"
    fi
    
    # Копируем локальную конфигурацию (она уже должна быть в корне)
    if [ -f "$PROJECT_ROOT/config_replenishment.php" ]; then
        log "Локальная конфигурация уже активна"
    else
        log "ОШИБКА: Локальная конфигурация не найдена"
        exit 1
    fi
    
    log "✓ Переключение на локальную конфигурацию завершено"
}

switch_to_production() {
    log "Переключение на продакшен конфигурацию..."
    
    if [ ! -f "$CONFIG_PRODUCTION" ]; then
        log "ОШИБКА: Продакшен конфигурация не найдена: $CONFIG_PRODUCTION"
        exit 1
    fi
    
    if [ -f "$CONFIG_LOCAL" ]; then
        # Создаем резервную копию текущей конфигурации
        cp "$CONFIG_LOCAL" "$CONFIG_BACKUP"
        log "Создана резервная копия: $CONFIG_BACKUP"
    fi
    
    # Копируем продакшен конфигурацию
    cp "$CONFIG_PRODUCTION" "$CONFIG_LOCAL"
    log "Продакшен конфигурация скопирована в: $CONFIG_LOCAL"
    
    log "✓ Переключение на продакшен конфигурацию завершено"
    log ""
    log "⚠️  ВАЖНО: Не забудьте обновить следующие параметры в продакшен конфигурации:"
    log "   - DB_PASSWORD (пароль базы данных)"
    log "   - API_KEY (ключ API)"
    log "   - SMTP настройки (если используется email)"
    log "   - EMAIL адреса для отчетов"
}

# Проверяем аргументы
if [ $# -eq 0 ]; then
    show_usage
fi

case "$1" in
    "local")
        switch_to_local
        ;;
    "production")
        switch_to_production
        ;;
    "status")
        show_status
        ;;
    *)
        echo "Неизвестная команда: $1"
        show_usage
        ;;
esac

echo ""
show_status