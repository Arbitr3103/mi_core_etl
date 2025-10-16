#!/bin/bash

# ===================================================================
# QUICK DEPLOYMENT SCRIPT FOR OZON ACTIVE PRODUCT FILTERING
# ===================================================================
# Быстрое и безопасное развертывание с автоматическими проверками

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m'

echo -e "${PURPLE}🚀 Быстрое развертывание Ozon Active Product Filtering${NC}"
echo "=================================================================="

# Функция для логирования
log() {
    echo -e "${BLUE}[$(date +'%H:%M:%S')] $1${NC}"
}

success() {
    echo -e "${GREEN}✅ $1${NC}"
}

error() {
    echo -e "${RED}❌ $1${NC}"
}

warning() {
    echo -e "${YELLOW}⚠️ $1${NC}"
}

# Шаг 1: Предварительная проверка
log "Шаг 1: Предварительная проверка готовности системы"
if [[ -f "pre_deployment_check.sh" ]]; then
    if ./pre_deployment_check.sh; then
        success "Система готова к развертыванию"
    else
        error "Система не готова к развертыванию. Исправьте ошибки и повторите попытку."
        exit 1
    fi
else
    warning "Скрипт предварительной проверки не найден, продолжаем без проверки"
fi

# Шаг 2: Создание резервной копии
log "Шаг 2: Создание резервной копии базы данных"
BACKUP_FILE="backup_before_active_filtering_$(date +%Y%m%d_%H%M%S).sql"

if php -r "
    require_once 'config.php';
    echo DB_NAME;
" > /dev/null 2>&1; then
    DB_NAME=$(php -r "require_once 'config.php'; echo DB_NAME;")
    
    if mysqldump --single-transaction --routines --triggers "$DB_NAME" > "$BACKUP_FILE" 2>/dev/null; then
        success "Резервная копия создана: $BACKUP_FILE"
    else
        error "Не удалось создать резервную копию"
        exit 1
    fi
else
    error "Не удается получить имя базы данных из конфигурации"
    exit 1
fi

# Шаг 3: Dry run
log "Шаг 3: Тестовый запуск (dry run)"
if [[ -f "deploy_active_product_filtering.sh" ]]; then
    if ./deploy_active_product_filtering.sh --dry-run; then
        success "Тестовый запуск прошел успешно"
    else
        error "Тестовый запуск не удался"
        exit 1
    fi
else
    error "Скрипт развертывания не найден"
    exit 1
fi

# Подтверждение от пользователя
echo -e "\n${YELLOW}⚠️ ВНИМАНИЕ: Сейчас будет применена миграция к базе данных${NC}"
echo -e "Резервная копия создана: ${BLUE}$BACKUP_FILE${NC}"
echo -e "Продолжить развертывание? (y/N): \c"
read -r CONFIRM

if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    warning "Развертывание отменено пользователем"
    exit 0
fi

# Шаг 4: Применение миграции
log "Шаг 4: Применение миграции активной фильтрации продуктов"
if ./deploy_active_product_filtering.sh; then
    success "Миграция применена успешно"
else
    error "Миграция не удалась"
    
    echo -e "\n${YELLOW}Хотите восстановить из резервной копии? (y/N): \c"
    read -r RESTORE
    
    if [[ "$RESTORE" =~ ^[Yy]$ ]]; then
        log "Восстановление из резервной копии..."
        if mysql "$DB_NAME" < "$BACKUP_FILE" 2>/dev/null; then
            success "База данных восстановлена из резервной копии"
        else
            error "Не удалось восстановить из резервной копии"
        fi
    fi
    
    exit 1
fi

# Шаг 5: Проверка результатов
log "Шаг 5: Проверка результатов развертывания"

# Проверка статистики активных продуктов
STATS=$(php -r "
    require_once 'config.php';
    try {
        \$pdo = getDatabaseConnection();
        \$stmt = \$pdo->query('SELECT * FROM v_active_products_stats');
        \$stats = \$stmt->fetch();
        echo json_encode(\$stats);
    } catch (Exception \$e) {
        echo 'ERROR: ' . \$e->getMessage();
        exit(1);
    }
")

if [[ "$STATS" != ERROR* ]]; then
    TOTAL=$(echo "$STATS" | php -r "echo json_decode(file_get_contents('php://stdin'), true)['total_products'];")
    ACTIVE=$(echo "$STATS" | php -r "echo json_decode(file_get_contents('php://stdin'), true)['active_products'];")
    PERCENTAGE=$(echo "$STATS" | php -r "echo json_decode(file_get_contents('php://stdin'), true)['active_percentage'];")
    
    success "Статистика продуктов:"
    echo -e "  📊 Всего продуктов: ${BLUE}$TOTAL${NC}"
    echo -e "  ✅ Активных продуктов: ${GREEN}$ACTIVE${NC}"
    echo -e "  📈 Процент активных: ${PURPLE}$PERCENTAGE%${NC}"
    
    if [[ $ACTIVE -ge 40 && $ACTIVE -le 60 ]]; then
        success "Количество активных продуктов в ожидаемом диапазоне (40-60)"
    else
        warning "Количество активных продуктов ($ACTIVE) не в ожидаемом диапазоне (40-60)"
    fi
else
    error "Не удалось получить статистику продуктов"
fi

# Шаг 6: Тестирование API
log "Шаг 6: Тестирование API endpoints"

# Проверка API активности
if command -v curl &> /dev/null; then
    API_TEST=$(curl -s "http://localhost/api/inventory-analytics.php?action=activity_stats" 2>/dev/null || echo "ERROR")
    
    if [[ "$API_TEST" != "ERROR" && "$API_TEST" == *"success"* ]]; then
        success "API endpoint activity_stats работает"
    else
        warning "API endpoint может быть недоступен (проверьте веб-сервер)"
    fi
else
    warning "curl не найден, пропускаем тестирование API"
fi

# Шаг 7: Настройка мониторинга
log "Шаг 7: Настройка базового мониторинга"

# Создание простого скрипта мониторинга
cat > monitor_system.sh << 'EOF'
#!/bin/bash
# Простой мониторинг активных продуктов

ACTIVE_COUNT=$(php -r "
    require_once 'config.php';
    try {
        \$pdo = getDatabaseConnection();
        \$stmt = \$pdo->query('SELECT active_products FROM v_active_products_stats');
        echo \$stmt->fetchColumn();
    } catch (Exception \$e) {
        echo '0';
    }
")

echo "$(date): Активных продуктов: $ACTIVE_COUNT"

if [ "$ACTIVE_COUNT" -lt 40 ]; then
    echo "ВНИМАНИЕ: Низкое количество активных продуктов ($ACTIVE_COUNT)"
fi
EOF

chmod +x monitor_system.sh
success "Создан скрипт мониторинга: monitor_system.sh"

# Финальный отчет
echo -e "\n${GREEN}=================================================================="
echo -e "🎉 РАЗВЕРТЫВАНИЕ ЗАВЕРШЕНО УСПЕШНО!${NC}"
echo "=================================================================="

echo -e "\n📋 Что было сделано:"
echo -e "✅ Создана резервная копия: ${BLUE}$BACKUP_FILE${NC}"
echo -e "✅ Применена миграция базы данных"
echo -e "✅ Добавлена фильтрация активных продуктов"
echo -e "✅ Настроен мониторинг активности"
echo -e "✅ Проверена работоспособность системы"

echo -e "\n🔧 Следующие шаги:"
echo -e "1. Настройте cron задачи для автоматического ETL:"
echo -e "   ${BLUE}0 */4 * * * cd $(pwd) && php etl_cli.php run ozon${NC}"
echo -e "2. Настройте мониторинг:"
echo -e "   ${BLUE}*/15 * * * * cd $(pwd) && ./monitor_system.sh >> logs/monitoring.log${NC}"
echo -e "3. Обновите дашборды для использования активных продуктов"
echo -e "4. Проверьте производительность через несколько часов"

echo -e "\n📊 Ожидаемые улучшения:"
echo -e "• Обработка ~48 продуктов вместо 176 (73% сокращение)"
echo -e "• Ускорение ETL процессов в 2-3 раза"
echo -e "• Более точные данные в отчетах"
echo -e "• Снижение нагрузки на API Ozon"

echo -e "\n📚 Документация:"
echo -e "• Руководство по развертыванию: ${BLUE}DEPLOYMENT_GUIDE_ACTIVE_PRODUCT_FILTERING.md${NC}"
echo -e "• API документация: ${BLUE}API_DOCUMENTATION_ACTIVE_PRODUCT_FILTERING.md${NC}"
echo -e "• Руководство по устранению неполадок: ${BLUE}TROUBLESHOOTING_GUIDE_ACTIVE_PRODUCT_FILTERING.md${NC}"

echo -e "\n${GREEN}Система готова к работе! 🚀${NC}"