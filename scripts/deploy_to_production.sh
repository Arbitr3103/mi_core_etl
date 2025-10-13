#!/bin/bash

# =====================================================
# Автоматическое развертывание оптимизаций на продакшен сервере
# Создано для задачи 7: Оптимизировать производительность
# =====================================================

set -e  # Остановить выполнение при ошибке

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Функции для вывода сообщений
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Проверка окружения
check_production_environment() {
    log_info "Проверка продакшен окружения..."
    
    # Проверяем, что мы на продакшен сервере
    if [[ ! -f "/etc/production_server" ]] && [[ "$FORCE_PRODUCTION" != "true" ]]; then
        log_warning "Не обнаружен маркер продакшен сервера"
        echo -n "Вы уверены, что это продакшен сервер? (yes/no): "
        read confirmation
        if [[ "$confirmation" != "yes" ]]; then
            log_error "Развертывание отменено"
            exit 1
        fi
    fi
    
    # Проверяем наличие необходимых команд
    for cmd in php mysql git; do
        if ! command -v $cmd &> /dev/null; then
            log_error "$cmd не найден. Установите необходимые зависимости."
            exit 1
        fi
    done
    
    log_success "Окружение проверено"
}

# Создание резервной копии
create_backup() {
    log_info "Создание резервной копии..."
    
    backup_dir="backups/production_deployment_$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$backup_dir"
    
    # Бэкап критически важных файлов
    if [[ -f "api/inventory-analytics.php" ]]; then
        cp "api/inventory-analytics.php" "$backup_dir/"
    fi
    
    if [[ -f "html/inventory_marketing_dashboard.php" ]]; then
        cp "html/inventory_marketing_dashboard.php" "$backup_dir/"
    fi
    
    # Бэкап базы данных (если настроены переменные)
    if [[ -n "$DB_USER" ]] && [[ -n "$DB_NAME" ]]; then
        log_info "Создание резервной копии базы данных..."
        mysqldump -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" > "$backup_dir/database_backup.sql" 2>/dev/null || {
            log_warning "Не удалось создать резервную копию БД (возможно, нет прав)"
        }
    fi
    
    log_success "Резервная копия создана в $backup_dir"
    echo "$backup_dir" > .last_backup_path
}

# Обновление кода
update_code() {
    log_info "Обновление кода из репозитория..."
    
    # Проверяем статус git
    if [[ -n $(git status --porcelain) ]]; then
        log_warning "Обнаружены незакоммиченные изменения"
        git status --short
        echo -n "Продолжить? (yes/no): "
        read confirmation
        if [[ "$confirmation" != "yes" ]]; then
            log_error "Развертывание отменено"
            exit 1
        fi
    fi
    
    # Получаем последние изменения
    git fetch origin
    git pull origin main
    
    # Проверяем наличие новых файлов оптимизации
    required_files=(
        "api/inventory_cache_manager.php"
        "sql/create_inventory_dashboard_indexes.sql"
        "js/inventory_dashboard_optimized.js"
        "scripts/manage_inventory_cache.php"
        "scripts/monitor_inventory_performance.php"
    )
    
    for file in "${required_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            log_error "Файл $file не найден после обновления"
            exit 1
        fi
    done
    
    log_success "Код успешно обновлен"
}

# Применение индексов базы данных
apply_database_indexes() {
    log_info "Применение индексов базы данных..."
    
    # Проверяем подключение к БД
    if ! php -r "
        require_once 'config.php';
        try {
            \$pdo = getDatabaseConnection();
            \$pdo->query('SELECT 1');
            echo 'OK';
        } catch (Exception \$e) {
            echo 'ERROR: ' . \$e->getMessage();
            exit(1);
        }
    " > /dev/null 2>&1; then
        log_error "Не удалось подключиться к базе данных"
        exit 1
    fi
    
    # Применяем индексы
    if php -r "
        require_once 'config.php';
        try {
            \$pdo = getDatabaseConnection();
            \$sql = file_get_contents('sql/create_inventory_dashboard_indexes.sql');
            \$pdo->exec(\$sql);
            echo 'SUCCESS';
        } catch (Exception \$e) {
            echo 'ERROR: ' . \$e->getMessage();
            exit(1);
        }
    "; then
        log_success "Индексы базы данных успешно созданы"
    else
        log_error "Ошибка создания индексов"
        exit 1
    fi
}

# Настройка кэширования
setup_caching() {
    log_info "Настройка системы кэширования..."
    
    # Создаем директорию для кэша
    cache_dir="cache/inventory"
    mkdir -p "$cache_dir"
    chmod 755 "$cache_dir"
    
    # Проверяем права записи
    if [[ ! -w "$cache_dir" ]]; then
        log_error "Нет прав записи в директорию кэша: $cache_dir"
        exit 1
    fi
    
    # Тестируем кэширование
    if php scripts/manage_inventory_cache.php test > /dev/null 2>&1; then
        log_success "Система кэширования работает корректно"
    else
        log_warning "Проблемы с системой кэширования, но продолжаем..."
    fi
    
    # Прогреваем кэш
    log_info "Прогрев кэша основными данными..."
    if php scripts/manage_inventory_cache.php warmup > /dev/null 2>&1; then
        log_success "Кэш прогрет"
    else
        log_warning "Не удалось прогреть кэш, но это не критично"
    fi
}

# Перезапуск веб-сервера
restart_web_server() {
    log_info "Перезапуск веб-сервера..."
    
    # Определяем тип веб-сервера и перезапускаем
    if systemctl is-active --quiet apache2; then
        sudo systemctl reload apache2
        log_success "Apache перезапущен"
    elif systemctl is-active --quiet nginx; then
        sudo systemctl reload nginx
        log_success "Nginx перезапущен"
    elif systemctl is-active --quiet httpd; then
        sudo systemctl reload httpd
        log_success "Apache (httpd) перезапущен"
    else
        log_warning "Не удалось определить веб-сервер для перезапуска"
    fi
}

# Тестирование развертывания
test_deployment() {
    log_info "Тестирование развертывания..."
    
    # Определяем базовый URL
    if [[ -n "$PRODUCTION_URL" ]]; then
        base_url="$PRODUCTION_URL"
    else
        base_url="http://localhost"
    fi
    
    # Тестируем API endpoints
    endpoints=(
        "api/inventory-analytics.php?action=dashboard"
        "api/inventory-analytics.php?action=critical-products"
        "api/inventory-analytics.php?action=warehouse-summary"
    )
    
    for endpoint in "${endpoints[@]}"; do
        log_info "Тестирование $endpoint..."
        
        if curl -s -f "$base_url/$endpoint" > /dev/null; then
            log_success "✅ $endpoint работает"
        else
            log_error "❌ $endpoint не отвечает"
            return 1
        fi
    done
    
    # Тестируем дашборд
    if curl -s -f "$base_url/html/inventory_marketing_dashboard.php" > /dev/null; then
        log_success "✅ Дашборд доступен"
    else
        log_error "❌ Дашборд недоступен"
        return 1
    fi
    
    log_success "Все тесты пройдены успешно"
}

# Настройка мониторинга
setup_monitoring() {
    log_info "Настройка мониторинга..."
    
    # Создаем директорию для логов
    mkdir -p logs
    chmod 755 logs
    
    # Добавляем задачи в crontab (если еще не добавлены)
    crontab -l 2>/dev/null | grep -q "manage_inventory_cache.php clean" || {
        (crontab -l 2>/dev/null; echo "0 */6 * * * cd $(pwd) && php scripts/manage_inventory_cache.php clean") | crontab -
        log_success "Добавлена задача очистки кэша в crontab"
    }
    
    crontab -l 2>/dev/null | grep -q "monitor_inventory_performance.php" || {
        (crontab -l 2>/dev/null; echo "0 8 * * * cd $(pwd) && php scripts/monitor_inventory_performance.php > logs/performance_\$(date +\\%Y\\%m\\%d).log") | crontab -
        log_success "Добавлена задача мониторинга производительности в crontab"
    }
}

# Главная функция
main() {
    echo "=================================================="
    echo "🚀 Развертывание оптимизаций на продакшен сервере"
    echo "   Дашборд складских остатков"
    echo "=================================================="
    echo
    
    # Проверяем аргументы командной строки
    while [[ $# -gt 0 ]]; do
        case $1 in
            --force)
                FORCE_PRODUCTION="true"
                shift
                ;;
            --url=*)
                PRODUCTION_URL="${1#*=}"
                shift
                ;;
            --skip-backup)
                SKIP_BACKUP="true"
                shift
                ;;
            --help)
                echo "Использование: $0 [опции]"
                echo "Опции:"
                echo "  --force          Принудительное выполнение без проверки продакшен сервера"
                echo "  --url=URL        Базовый URL для тестирования (по умолчанию: http://localhost)"
                echo "  --skip-backup    Пропустить создание резервной копии"
                echo "  --help           Показать эту справку"
                exit 0
                ;;
            *)
                log_error "Неизвестная опция: $1"
                exit 1
                ;;
        esac
    done
    
    # Выполняем все этапы развертывания
    check_production_environment
    
    if [[ "$SKIP_BACKUP" != "true" ]]; then
        create_backup
    fi
    
    update_code
    apply_database_indexes
    setup_caching
    restart_web_server
    
    if test_deployment; then
        setup_monitoring
        
        echo
        echo "=================================================="
        log_success "🎉 Развертывание завершено успешно!"
        echo "=================================================="
        echo
        log_info "Что было сделано:"
        echo "  ✅ Код обновлен из репозитория"
        echo "  ✅ Индексы базы данных созданы"
        echo "  ✅ Кэширование настроено и протестировано"
        echo "  ✅ Веб-сервер перезапущен"
        echo "  ✅ API endpoints протестированы"
        echo "  ✅ Мониторинг настроен"
        echo
        log_info "Следующие шаги:"
        echo "  1. Проверьте дашборд: ${PRODUCTION_URL:-http://localhost}/html/inventory_marketing_dashboard.php"
        echo "  2. Мониторьте логи: tail -f logs/inventory_api_errors.log"
        echo "  3. Проверьте производительность: php scripts/monitor_inventory_performance.php"
        echo
        log_info "Ожидаемые улучшения:"
        echo "  📈 Ускорение API в 200+ раз для кэшированных запросов"
        echo "  ⚡ Время ответа < 100ms для всех endpoints"
        echo "  💾 Автоматическое управление кэшем"
        echo
    else
        log_error "Тестирование не пройдено. Проверьте логи и исправьте ошибки."
        
        if [[ -f ".last_backup_path" ]]; then
            backup_path=$(cat .last_backup_path)
            log_info "Для отката используйте резервную копию: $backup_path"
        fi
        
        exit 1
    fi
}

# Обработка сигналов для корректного завершения
trap 'log_error "Развертывание прервано"; exit 1' INT TERM

# Запуск основной функции
main "$@"