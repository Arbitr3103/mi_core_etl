#!/bin/bash

# ===================================================================
# СКРИПТ ПРИМЕНЕНИЯ МИГРАЦИИ СИНХРОНИЗАЦИИ ОСТАТКОВ
# ===================================================================
# Дата создания: 2025-01-06
# Описание: Безопасное применение миграции схемы БД
# ===================================================================

set -e  # Остановка при любой ошибке

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Функция для логирования
log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

# Проверка наличия необходимых файлов
check_files() {
    log "Проверка наличия файлов миграции..."
    
    if [ ! -f "migrations/inventory_sync_schema_update.sql" ]; then
        error "Файл миграции не найден: migrations/inventory_sync_schema_update.sql"
        exit 1
    fi
    
    if [ ! -f "migrations/validate_inventory_sync_migration.sql" ]; then
        error "Файл валидации не найден: migrations/validate_inventory_sync_migration.sql"
        exit 1
    fi
    
    if [ ! -f "migrations/rollback_inventory_sync_schema_update.sql" ]; then
        warning "Файл отката не найден: migrations/rollback_inventory_sync_schema_update.sql"
    fi
    
    success "Все необходимые файлы найдены"
}

# Проверка подключения к БД
check_db_connection() {
    log "Проверка подключения к базе данных..."
    
    # Попытка подключения к БД (требует настройки переменных окружения)
    if ! mysql --defaults-extra-file=<(echo -e "[client]\nuser=${DB_USER:-root}\npassword=${DB_PASSWORD}\nhost=${DB_HOST:-localhost}\nport=${DB_PORT:-3306}") \
         -e "SELECT 1;" "${DB_NAME:-replenishment_db}" > /dev/null 2>&1; then
        error "Не удается подключиться к базе данных"
        error "Убедитесь, что установлены переменные окружения:"
        error "  DB_HOST, DB_PORT, DB_USER, DB_PASSWORD, DB_NAME"
        exit 1
    fi
    
    success "Подключение к базе данных установлено"
}

# Создание резервной копии
create_backup() {
    log "Создание резервной копии базы данных..."
    
    local backup_file="backup_inventory_sync_$(date +%Y%m%d_%H%M%S).sql"
    
    mysqldump --defaults-extra-file=<(echo -e "[client]\nuser=${DB_USER:-root}\npassword=${DB_PASSWORD}\nhost=${DB_HOST:-localhost}\nport=${DB_PORT:-3306}") \
              --single-transaction --routines --triggers \
              "${DB_NAME:-replenishment_db}" > "$backup_file"
    
    if [ $? -eq 0 ]; then
        success "Резервная копия создана: $backup_file"
        echo "$backup_file" > .last_backup_file
    else
        error "Ошибка создания резервной копии"
        exit 1
    fi
}

# Применение миграции
apply_migration() {
    log "Применение миграции схемы БД..."
    
    mysql --defaults-extra-file=<(echo -e "[client]\nuser=${DB_USER:-root}\npassword=${DB_PASSWORD}\nhost=${DB_HOST:-localhost}\nport=${DB_PORT:-3306}") \
          "${DB_NAME:-replenishment_db}" < migrations/inventory_sync_schema_update.sql
    
    if [ $? -eq 0 ]; then
        success "Миграция успешно применена"
    else
        error "Ошибка применения миграции"
        exit 1
    fi
}

# Валидация миграции
validate_migration() {
    log "Валидация результатов миграции..."
    
    mysql --defaults-extra-file=<(echo -e "[client]\nuser=${DB_USER:-root}\npassword=${DB_PASSWORD}\nhost=${DB_HOST:-localhost}\nport=${DB_PORT:-3306}") \
          "${DB_NAME:-replenishment_db}" < migrations/validate_inventory_sync_migration.sql
    
    if [ $? -eq 0 ]; then
        success "Валидация миграции завершена"
    else
        warning "Обнаружены проблемы при валидации"
    fi
}

# Функция отката
rollback_migration() {
    log "Выполнение отката миграции..."
    
    if [ -f "migrations/rollback_inventory_sync_schema_update.sql" ]; then
        mysql --defaults-extra-file=<(echo -e "[client]\nuser=${DB_USER:-root}\npassword=${DB_PASSWORD}\nhost=${DB_HOST:-localhost}\nport=${DB_PORT:-3306}") \
              "${DB_NAME:-replenishment_db}" < migrations/rollback_inventory_sync_schema_update.sql
        
        if [ $? -eq 0 ]; then
            success "Откат миграции выполнен"
        else
            error "Ошибка отката миграции"
        fi
    else
        error "Файл отката не найден"
    fi
}

# Восстановление из резервной копии
restore_backup() {
    if [ -f ".last_backup_file" ]; then
        local backup_file=$(cat .last_backup_file)
        if [ -f "$backup_file" ]; then
            log "Восстановление из резервной копии: $backup_file"
            
            mysql --defaults-extra-file=<(echo -e "[client]\nuser=${DB_USER:-root}\npassword=${DB_PASSWORD}\nhost=${DB_HOST:-localhost}\nport=${DB_PORT:-3306}") \
                  "${DB_NAME:-replenishment_db}" < "$backup_file"
            
            if [ $? -eq 0 ]; then
                success "База данных восстановлена из резервной копии"
            else
                error "Ошибка восстановления из резервной копии"
            fi
        else
            error "Файл резервной копии не найден: $backup_file"
        fi
    else
        error "Информация о резервной копии не найдена"
    fi
}

# Главная функция
main() {
    echo "====================================================================="
    echo "           МИГРАЦИЯ СХЕМЫ БД ДЛЯ СИНХРОНИЗАЦИИ ОСТАТКОВ"
    echo "====================================================================="
    
    # Проверка аргументов командной строки
    case "${1:-apply}" in
        "apply")
            log "Режим: Применение миграции"
            check_files
            check_db_connection
            create_backup
            apply_migration
            validate_migration
            success "Миграция успешно завершена!"
            ;;
        "rollback")
            log "Режим: Откат миграции"
            check_db_connection
            rollback_migration
            ;;
        "restore")
            log "Режим: Восстановление из резервной копии"
            check_db_connection
            restore_backup
            ;;
        "validate")
            log "Режим: Только валидация"
            check_db_connection
            validate_migration
            ;;
        *)
            echo "Использование: $0 [apply|rollback|restore|validate]"
            echo ""
            echo "Команды:"
            echo "  apply    - Применить миграцию (по умолчанию)"
            echo "  rollback - Откатить миграцию"
            echo "  restore  - Восстановить из резервной копии"
            echo "  validate - Только валидация без изменений"
            echo ""
            echo "Переменные окружения:"
            echo "  DB_HOST     - Хост БД (по умолчанию: localhost)"
            echo "  DB_PORT     - Порт БД (по умолчанию: 3306)"
            echo "  DB_USER     - Пользователь БД (по умолчанию: root)"
            echo "  DB_PASSWORD - Пароль БД"
            echo "  DB_NAME     - Имя БД (по умолчанию: replenishment_db)"
            exit 1
            ;;
    esac
}

# Обработка сигналов для безопасного завершения
trap 'error "Операция прервана пользователем"; exit 1' INT TERM

# Запуск основной функции
main "$@"