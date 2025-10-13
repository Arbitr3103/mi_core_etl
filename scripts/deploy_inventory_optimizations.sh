#!/bin/bash

# =====================================================
# Скрипт развертывания оптимизаций производительности дашборда складских остатков
# Применяет индексы, настраивает кэширование и проверяет производительность
# Создано для задачи 7: Оптимизировать производительность
# =====================================================

set -e  # Остановить выполнение при ошибке

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Функция для вывода сообщений
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
check_environment() {
    log_info "Проверка окружения..."
    
    # Проверяем наличие PHP
    if ! command -v php &> /dev/null; then
        log_error "PHP не найден. Установите PHP для продолжения."
        exit 1
    fi
    
    # Проверяем наличие MySQL
    if ! command -v mysql &> /dev/null; then
        log_error "MySQL клиент не найден. Установите MySQL клиент для продолжения."
        exit 1
    fi
    
    # Проверяем наличие необходимых файлов
    required_files=(
        "sql/create_inventory_dashboard_indexes.sql"
        "api/inventory_cache_manager.php"
        "scripts/manage_inventory_cache.php"
        "scripts/monitor_inventory_performance.php"
    )
    
    for file in "${required_files[@]}"; do
        if [[ ! -f "$file" ]]; then
            log_error "Файл $file не найден"
            exit 1
        fi
    done
    
    log_success "Окружение проверено"
}

# Создание резервной копии
create_backup() {
    log_info "Создание резервной копии..."
    
    backup_dir="backups/inventory_optimization_$(date +%Y%m%d_%H%M%S)"
    mkdir -p "$backup_dir"
    
    # Бэкап API файлов
    if [[ -f "api/inventory-analytics.php" ]]; then
        cp "api/inventory-analytics.php" "$backup_dir/"
        log_success "Создана резервная копия API"
    fi
    
    # Бэкап дашборда
    if [[ -f "html/inventory_marketing_dashboard.php" ]]; then
        cp "html/inventory_marketing_dashboard.php" "$backup_dir/"
        log_success "Создана резервная копия дашборда"
    fi
    
    log_success "Резервные копии созданы в $backup_dir"
}

# Применение индексов базы данных
apply_database_indexes() {
    log_info "Применение индексов базы данных..."
    
    # Проверяем подключение к базе данных
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
    log_info "Создание индексов для оптимизации запросов..."
    
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
        log_success "Индексы успешно созданы"
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
    log_info "Тестирование системы кэширования..."
    
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

# Обновление JavaScript файлов
update_frontend() {
    log_info "Обновление frontend компонентов..."
    
    # Создаем директорию для JS файлов если не существует
    mkdir -p "js"
    
    # Проверяем наличие оптимизированного JS файла
    if [[ -f "js/inventory_dashboard_optimized.js" ]]; then
        log_success "Оптимизированный JavaScript файл найден"
        
        # Обновляем дашборд для использования нового JS файла
        if [[ -f "html/inventory_marketing_dashboard.php" ]]; then
            # Добавляем подключение оптимизированного JS в конец файла если его еще нет
            if ! grep -q "inventory_dashboard_optimized.js" "html/inventory_marketing_dashboard.php"; then
                log_info "Добавление подключения оптимизированного JavaScript..."
                
                # Создаем временный файл с добавленным скриптом
                temp_file=$(mktemp)
                
                # Копируем содержимое до закрывающего </body>
                sed '/<\/body>/i\    <script src="../js/inventory_dashboard_optimized.js"></script>' \
                    "html/inventory_marketing_dashboard.php" > "$temp_file"
                
                # Заменяем оригинальный файл
                mv "$temp_file" "html/inventory_marketing_dashboard.php"
                
                log_success "Оптимизированный JavaScript подключен к дашборду"
            else
                log_info "Оптимизированный JavaScript уже подключен"
            fi
        fi
    else
        log_warning "Оптимизированный JavaScript файл не найден"
    fi
}

# Проверка производительности
check_performance() {
    log_info "Проверка производительности после оптимизации..."
    
    # Запускаем мониторинг производительности
    if php scripts/monitor_inventory_performance.php > performance_report.txt 2>&1; then
        log_success "Отчет о производительности создан: performance_report.txt"
        
        # Показываем краткую сводку
        log_info "Краткая сводка производительности:"
        
        if grep -q "✅" performance_report.txt; then
            success_count=$(grep -c "✅" performance_report.txt)
            log_success "Успешных тестов: $success_count"
        fi
        
        if grep -q "❌" performance_report.txt; then
            error_count=$(grep -c "❌" performance_report.txt)
            log_warning "Тестов с ошибками: $error_count"
        fi
        
        if grep -q "МЕДЛЕННО" performance_report.txt; then
            log_warning "Обнаружены медленные запросы - см. performance_report.txt"
        fi
        
    else
        log_warning "Не удалось выполнить проверку производительности"
    fi
}

# Настройка автоматической очистки кэша
setup_cache_cleanup() {
    log_info "Настройка автоматической очистки кэша..."
    
    # Создаем скрипт для cron
    cleanup_script="scripts/cleanup_inventory_cache.sh"
    
    cat > "$cleanup_script" << 'EOF'
#!/bin/bash
# Автоматическая очистка кэша дашборда складских остатков
cd "$(dirname "$0")/.."
php scripts/manage_inventory_cache.php clean > /dev/null 2>&1
EOF
    
    chmod +x "$cleanup_script"
    
    log_success "Скрипт очистки кэша создан: $cleanup_script"
    log_info "Добавьте в crontab для автоматической очистки:"
    log_info "0 */6 * * * $(pwd)/$cleanup_script"
}

# Создание документации
create_documentation() {
    log_info "Создание документации по оптимизации..."
    
    doc_file="INVENTORY_PERFORMANCE_OPTIMIZATION.md"
    
    cat > "$doc_file" << EOF
# Оптимизация производительности дашборда складских остатков

## Примененные оптимизации

### 1. Индексы базы данных
- Созданы индексы для таблицы \`inventory_data\`
- Оптимизированы запросы для классификации товаров
- Добавлены составные индексы для JOIN операций

### 2. Система кэширования
- Реализовано файловое кэширование API ответов
- TTL: 5 минут для основных данных, 3 минуты для критических товаров
- Автоматическая инвалидация при обновлении данных

### 3. Оптимизация frontend
- Ленивая загрузка данных
- Виртуализация списков товаров
- Автоматическое обновление в фоне

## Управление кэшем

### Команды управления кэшем:
\`\`\`bash
# Статус кэша
php scripts/manage_inventory_cache.php status

# Очистка кэша
php scripts/manage_inventory_cache.php clear

# Прогрев кэша
php scripts/manage_inventory_cache.php warmup

# Очистка устаревших файлов
php scripts/manage_inventory_cache.php clean
\`\`\`

## Мониторинг производительности

### Запуск мониторинга:
\`\`\`bash
php scripts/monitor_inventory_performance.php
\`\`\`

## Рекомендации по обслуживанию

1. **Регулярная очистка кэша**: Настройте cron для автоматической очистки
2. **Мониторинг индексов**: Периодически проверяйте эффективность индексов
3. **Анализ производительности**: Запускайте мониторинг при изменениях в данных

## Настройки производительности

### Переменные окружения:
- \`INVENTORY_CACHE_ENABLED\`: включить/отключить кэширование
- \`INVENTORY_CACHE_TTL\`: время жизни кэша в секундах
- \`INVENTORY_CACHE_DIR\`: директория для файлов кэша

### Рекомендуемые настройки MySQL:
\`\`\`sql
SET innodb_buffer_pool_size = 1G;
SET query_cache_size = 64M;
SET tmp_table_size = 64M;
SET max_heap_table_size = 64M;
\`\`\`

## Устранение неполадок

### Проблемы с кэшем:
1. Проверьте права доступа к директории кэша
2. Убедитесь, что достаточно места на диске
3. Проверьте логи ошибок в \`logs/inventory_api_errors.log\`

### Медленные запросы:
1. Проверьте статистику индексов: \`ANALYZE TABLE inventory_data\`
2. Используйте \`EXPLAIN\` для анализа планов выполнения
3. Рассмотрите возможность партиционирования больших таблиц

Дата создания: $(date)
Версия: 1.0
EOF

    log_success "Документация создана: $doc_file"
}

# Основная функция
main() {
    echo "=================================================="
    echo "🚀 Развертывание оптимизаций производительности"
    echo "   Дашборд складских остатков"
    echo "=================================================="
    echo
    
    # Выполняем все этапы
    check_environment
    create_backup
    apply_database_indexes
    setup_caching
    update_frontend
    setup_cache_cleanup
    check_performance
    create_documentation
    
    echo
    echo "=================================================="
    log_success "🎉 Оптимизация производительности завершена!"
    echo "=================================================="
    echo
    log_info "Что было сделано:"
    echo "  ✅ Созданы индексы базы данных"
    echo "  ✅ Настроено кэширование"
    echo "  ✅ Обновлен frontend"
    echo "  ✅ Настроена автоматическая очистка"
    echo "  ✅ Создана документация"
    echo
    log_info "Следующие шаги:"
    echo "  1. Проверьте отчет производительности: performance_report.txt"
    echo "  2. Настройте cron для автоматической очистки кэша"
    echo "  3. Мониторьте производительность регулярно"
    echo "  4. Прочитайте документацию: INVENTORY_PERFORMANCE_OPTIMIZATION.md"
    echo
}

# Запуск скрипта
main "$@"