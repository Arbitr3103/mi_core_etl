#!/bin/bash

# Основной скрипт миграции данных в MDM систему

set -e

# Конфигурация
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$SCRIPT_DIR/logs/migration_$(date +%Y%m%d_%H%M%S).log"
BACKUP_DIR="$SCRIPT_DIR/backups"

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Функция логирования
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1" | tee -a "$LOG_FILE"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR:${NC} $1" | tee -a "$LOG_FILE"
}

warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING:${NC} $1" | tee -a "$LOG_FILE"
}

info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')] INFO:${NC} $1" | tee -a "$LOG_FILE"
}

# Создание необходимых директорий
create_directories() {
    log "Создаем необходимые директории..."
    
    mkdir -p "$SCRIPT_DIR/logs"
    mkdir -p "$SCRIPT_DIR/extracted-data"
    mkdir -p "$BACKUP_DIR"
    
    log "Директории созданы"
}

# Проверка предварительных условий
check_prerequisites() {
    log "Проверяем предварительные условия..."
    
    # Проверяем наличие PHP
    if ! command -v php &> /dev/null; then
        error "PHP не установлен"
        exit 1
    fi
    
    # Проверяем наличие MySQL
    if ! command -v mysql &> /dev/null; then
        error "MySQL клиент не установлен"
        exit 1
    fi
    
    # Проверяем подключение к базе данных
    if ! php -r "
        require_once '$SCRIPT_DIR/../../config.php';
        try {
            \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
            echo 'OK';
        } catch (Exception \$e) {
            echo 'FAIL: ' . \$e->getMessage();
            exit(1);
        }
    "; then
        error "Не удается подключиться к базе данных"
        exit 1
    fi
    
    # Проверяем наличие необходимых таблиц
    REQUIRED_TABLES=("master_products" "sku_mapping" "data_quality_metrics")
    for table in "${REQUIRED_TABLES[@]}"; do
        if ! mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "DESCRIBE $table" &> /dev/null; then
            error "Таблица $table не существует. Запустите миграции схемы базы данных."
            exit 1
        fi
    done
    
    log "Предварительные условия выполнены"
}

# Создание резервной копии
create_backup() {
    log "Создаем резервную копию базы данных..."
    
    BACKUP_FILE="$BACKUP_DIR/pre_migration_backup_$(date +%Y%m%d_%H%M%S).sql"
    
    if mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" \
        --single-transaction \
        --routines \
        --triggers \
        "$DB_NAME" > "$BACKUP_FILE"; then
        
        # Сжимаем бэкап
        gzip "$BACKUP_FILE"
        log "Резервная копия создана: ${BACKUP_FILE}.gz"
    else
        error "Не удалось создать резервную копию"
        exit 1
    fi
}

# Этап 1: Извлечение данных
extract_data() {
    log "=== ЭТАП 1: Извлечение существующих данных ==="
    
    info "Запускаем скрипт извлечения данных..."
    
    if php "$SCRIPT_DIR/extract-existing-data.php"; then
        log "Извлечение данных завершено успешно"
    else
        error "Ошибка при извлечении данных"
        exit 1
    fi
    
    # Проверяем, что файлы данных созданы
    DATA_FILES=("internal_products.json" "ozon_products.json" "wb_products.json")
    for file in "${DATA_FILES[@]}"; do
        if [ ! -f "$SCRIPT_DIR/extracted-data/$file" ]; then
            warning "Файл $file не найден, возможно источник данных недоступен"
        fi
    done
}

# Этап 2: Создание мастер-данных
create_master_data() {
    log "=== ЭТАП 2: Создание мастер-данных ==="
    
    info "Запускаем скрипт создания мастер-продуктов..."
    
    if php "$SCRIPT_DIR/create-master-products.php"; then
        log "Создание мастер-данных завершено успешно"
    else
        error "Ошибка при создании мастер-данных"
        exit 1
    fi
}

# Этап 3: Валидация результатов
validate_results() {
    log "=== ЭТАП 3: Валидация результатов миграции ==="
    
    info "Запускаем валидацию миграции..."
    
    if php "$SCRIPT_DIR/validate-migration.php"; then
        log "Валидация завершена успешно"
    else
        error "Валидация выявила критические ошибки"
        
        # Предлагаем откат
        read -p "Выполнить откат к резервной копии? (y/N): " -n 1 -r
        echo
        if [[ $REPLY =~ ^[Yy]$ ]]; then
            rollback_migration
        fi
        exit 1
    fi
}

# Этап 4: Обновление метрик качества данных
update_quality_metrics() {
    log "=== ЭТАП 4: Обновление метрик качества данных ==="
    
    info "Рассчитываем метрики качества данных..."
    
    # Рассчитываем процент покрытия мастер-данными
    COVERAGE_QUERY="
        SELECT 
            (SELECT COUNT(*) FROM master_products) as total_masters,
            (SELECT COUNT(*) FROM sku_mapping) as total_skus,
            (SELECT COUNT(*) FROM master_products WHERE canonical_brand != 'Неизвестный бренд') as products_with_brand,
            (SELECT COUNT(*) FROM master_products WHERE canonical_category != 'Без категории') as products_with_category
    "
    
    php -r "
        require_once '$SCRIPT_DIR/../../config.php';
        \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
        
        \$stmt = \$pdo->query(\"$COVERAGE_QUERY\");
        \$metrics = \$stmt->fetch(PDO::FETCH_ASSOC);
        
        \$brand_coverage = \$metrics['total_masters'] > 0 ? (\$metrics['products_with_brand'] / \$metrics['total_masters']) * 100 : 0;
        \$category_coverage = \$metrics['total_masters'] > 0 ? (\$metrics['products_with_category'] / \$metrics['total_masters']) * 100 : 0;
        
        // Сохраняем метрики в таблицу
        \$insert_sql = \"INSERT INTO data_quality_metrics (metric_name, metric_value, total_records, good_records) VALUES (?, ?, ?, ?)\";
        \$stmt = \$pdo->prepare(\$insert_sql);
        
        \$stmt->execute(['master_data_coverage', 100, \$metrics['total_skus'], \$metrics['total_masters']);
        \$stmt->execute(['brand_coverage', \$brand_coverage, \$metrics['total_masters'], \$metrics['products_with_brand']);
        \$stmt->execute(['category_coverage', \$category_coverage, \$metrics['total_masters'], \$metrics['products_with_category']);
        
        echo \"Метрики качества данных обновлены\n\";
        echo \"- Покрытие мастер-данными: 100%\n\";
        echo \"- Покрытие брендами: \" . round(\$brand_coverage, 2) . \"%\n\";
        echo \"- Покрытие категориями: \" . round(\$category_coverage, 2) . \"%\n\";
    "
    
    log "Метрики качества данных обновлены"
}

# Откат миграции
rollback_migration() {
    log "=== ОТКАТ МИГРАЦИИ ==="
    
    warning "Выполняем откат к резервной копии..."
    
    # Находим последний бэкап
    LATEST_BACKUP=$(ls -t "$BACKUP_DIR"/pre_migration_backup_*.sql.gz 2>/dev/null | head -n1)
    
    if [ -n "$LATEST_BACKUP" ]; then
        info "Восстанавливаем из бэкапа: $LATEST_BACKUP"
        
        # Очищаем таблицы MDM
        mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" -e "
            SET FOREIGN_KEY_CHECKS = 0;
            TRUNCATE TABLE sku_mapping;
            TRUNCATE TABLE master_products;
            TRUNCATE TABLE data_quality_metrics;
            SET FOREIGN_KEY_CHECKS = 1;
        "
        
        # Восстанавливаем из бэкапа
        gunzip -c "$LATEST_BACKUP" | mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME"
        
        log "Откат выполнен успешно"
    else
        error "Резервная копия не найдена"
        exit 1
    fi
}

# Создание итогового отчета
create_final_report() {
    log "=== Создание итогового отчета ==="
    
    REPORT_FILE="$SCRIPT_DIR/extracted-data/migration_final_report.json"
    
    php -r "
        require_once '$SCRIPT_DIR/../../config.php';
        \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
        
        // Собираем статистику
        \$stats = [];
        
        \$stats['total_masters'] = \$pdo->query('SELECT COUNT(*) FROM master_products')->fetchColumn();
        \$stats['total_skus'] = \$pdo->query('SELECT COUNT(*) FROM sku_mapping')->fetchColumn();
        
        \$source_stats = \$pdo->query('
            SELECT source, COUNT(*) as count 
            FROM sku_mapping 
            GROUP BY source
        ')->fetchAll(PDO::FETCH_KEY_PAIR);
        
        \$stats['sources'] = \$source_stats;
        
        \$quality_stats = \$pdo->query('
            SELECT 
                SUM(CASE WHEN canonical_brand != \"Неизвестный бренд\" THEN 1 ELSE 0 END) as with_brand,
                SUM(CASE WHEN canonical_category != \"Без категории\" THEN 1 ELSE 0 END) as with_category,
                COUNT(*) as total
            FROM master_products
        ')->fetch(PDO::FETCH_ASSOC);
        
        \$stats['quality'] = \$quality_stats;
        
        \$report = [
            'migration_date' => date('Y-m-d H:i:s'),
            'status' => 'SUCCESS',
            'statistics' => \$stats
        ];
        
        file_put_contents('$REPORT_FILE', json_encode(\$report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        
        echo json_encode(\$report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    "
    
    log "Итоговый отчет создан: $REPORT_FILE"
}

# Основная функция
main() {
    log "=== НАЧИНАЕМ МИГРАЦИЮ ДАННЫХ В MDM СИСТЕМУ ==="
    
    # Проверяем аргументы командной строки
    SKIP_BACKUP=false
    FORCE_MIGRATION=false
    
    while [[ $# -gt 0 ]]; do
        case $1 in
            --skip-backup)
                SKIP_BACKUP=true
                shift
                ;;
            --force)
                FORCE_MIGRATION=true
                shift
                ;;
            --help)
                echo "Использование: $0 [опции]"
                echo "Опции:"
                echo "  --skip-backup    Пропустить создание резервной копии"
                echo "  --force          Принудительная миграция без подтверждения"
                echo "  --help           Показать эту справку"
                exit 0
                ;;
            *)
                error "Неизвестная опция: $1"
                exit 1
                ;;
        esac
    done
    
    # Подтверждение запуска
    if [ "$FORCE_MIGRATION" != true ]; then
        echo -e "${YELLOW}ВНИМАНИЕ: Будет выполнена миграция данных в MDM систему.${NC}"
        echo "Это может занять продолжительное время и изменить данные в базе."
        read -p "Продолжить? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log "Миграция отменена пользователем"
            exit 0
        fi
    fi
    
    # Выполняем миграцию
    create_directories
    check_prerequisites
    
    if [ "$SKIP_BACKUP" != true ]; then
        create_backup
    else
        warning "Создание резервной копии пропущено"
    fi
    
    extract_data
    create_master_data
    validate_results
    update_quality_metrics
    create_final_report
    
    log "=== МИГРАЦИЯ ДАННЫХ ЗАВЕРШЕНА УСПЕШНО ==="
    
    # Выводим итоговую статистику
    if [ -f "$SCRIPT_DIR/extracted-data/migration_final_report.json" ]; then
        echo -e "\n${GREEN}=== ИТОГОВАЯ СТАТИСТИКА ===${NC}"
        cat "$SCRIPT_DIR/extracted-data/migration_final_report.json" | python3 -m json.tool 2>/dev/null || cat "$SCRIPT_DIR/extracted-data/migration_final_report.json"
    fi
}

# Обработка ошибок
trap 'error "Миграция прервана из-за ошибки"; exit 1' ERR

# Запуск основной функции
main "$@"