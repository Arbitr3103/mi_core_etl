#!/bin/bash

# Скрипт отладки структуры данных для MDM системы
# Анализирует состояние базы данных и качество данных

set -e

# Конфигурация
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$SCRIPT_DIR/debug_data_structure_$(date +%Y%m%d_%H%M%S).log"

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
NC='\033[0m'

# Функции логирования
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

success() {
    echo -e "${PURPLE}[$(date +'%Y-%m-%d %H:%M:%S')] SUCCESS:${NC} $1" | tee -a "$LOG_FILE"
}

# Проверка подключения к базе данных
check_database_connection() {
    log "=== ПРОВЕРКА ПОДКЛЮЧЕНИЯ К БАЗЕ ДАННЫХ ==="
    
    if command -v mysql &> /dev/null; then
        log "✓ MySQL клиент найден"
        
        # Проверяем подключение
        if mysql -u root -e "SELECT 'Connection OK' as status;" > /dev/null 2>&1; then
            success "✓ Подключение к MySQL успешно"
        else
            error "✗ Не удается подключиться к MySQL"
            return 1
        fi
        
        # Проверяем базу данных mi_core
        if mysql -u root -e "USE mi_core; SELECT 'Database OK' as status;" > /dev/null 2>&1; then
            success "✓ База данных mi_core доступна"
        else
            error "✗ База данных mi_core недоступна"
            return 1
        fi
    else
        error "✗ MySQL клиент не найден"
        return 1
    fi
}

# Анализ структуры таблиц
analyze_table_structure() {
    log "=== АНАЛИЗ СТРУКТУРЫ ТАБЛИЦ ==="
    
    # Получаем список таблиц
    local tables=$(mysql -u root mi_core -e "SHOW TABLES;" | tail -n +2)
    local table_count=$(echo "$tables" | wc -l)
    
    log "Найдено таблиц: $table_count"
    
    echo "$tables" | while read table; do
        if [ -n "$table" ]; then
            info "Анализируем таблицу: $table"
            
            # Получаем структуру таблицы
            local columns=$(mysql -u root mi_core -e "DESCRIBE $table;" | tail -n +2 | wc -l)
            local records=$(mysql -u root mi_core -e "SELECT COUNT(*) FROM $table;" | tail -n +2)
            
            log "  - Столбцов: $columns"
            log "  - Записей: $records"
            
            # Проверяем индексы
            local indexes=$(mysql -u root mi_core -e "SHOW INDEX FROM $table;" | tail -n +2 | wc -l)
            log "  - Индексов: $indexes"
        fi
    done
}

# Анализ качества данных в product_master
analyze_product_master_quality() {
    log "=== АНАЛИЗ КАЧЕСТВА ДАННЫХ В PRODUCT_MASTER ==="
    
    if mysql -u root mi_core -e "DESCRIBE product_master;" > /dev/null 2>&1; then
        success "✓ Таблица product_master найдена"
        
        # Общая статистика
        local total_products=$(mysql -u root mi_core -se "SELECT COUNT(*) FROM product_master;")
        log "Всего товаров: $total_products"
        
        # Анализ названий товаров
        local products_with_names=$(mysql -u root mi_core -se "
            SELECT COUNT(*) FROM product_master 
            WHERE (product_name IS NOT NULL AND product_name != '' AND product_name NOT LIKE 'Товар артикул%')
            OR (name IS NOT NULL AND name != '' AND name NOT LIKE 'Товар артикул%');
        ")
        
        local products_without_names=$(mysql -u root mi_core -se "
            SELECT COUNT(*) FROM product_master 
            WHERE (product_name IS NULL OR product_name = '' OR product_name LIKE 'Товар артикул%')
            AND (name IS NULL OR name = '' OR name LIKE 'Товар артикул%');
        ")
        
        log "Товары с названиями: $products_with_names"
        log "Товары без названий: $products_without_names"
        
        if [ "$total_products" -gt 0 ]; then
            local name_completeness=$(echo "scale=2; $products_with_names * 100 / $total_products" | bc)
            log "Полнота названий: ${name_completeness}%"
        fi
        
        # Анализ брендов
        local products_with_brands=$(mysql -u root mi_core -se "
            SELECT COUNT(*) FROM product_master 
            WHERE brand IS NOT NULL AND brand != '' AND brand != 'Неизвестный бренд';
        ")
        
        log "Товары с брендами: $products_with_brands"
        
        # Анализ категорий
        local products_with_categories=$(mysql -u root mi_core -se "
            SELECT COUNT(*) FROM product_master 
            WHERE category IS NOT NULL AND category != '';
        ")
        
        log "Товары с категориями: $products_with_categories"
        
        # Топ бренды
        log "Топ 5 брендов:"
        mysql -u root mi_core -e "
            SELECT brand, COUNT(*) as count 
            FROM product_master 
            WHERE brand IS NOT NULL AND brand != ''
            GROUP BY brand 
            ORDER BY count DESC 
            LIMIT 5;
        " | while read line; do
            if [[ "$line" != *"brand"* ]]; then
                log "  - $line"
            fi
        done
        
    else
        error "✗ Таблица product_master не найдена"
    fi
}

# Анализ данных инвентаря
analyze_inventory_data() {
    log "=== АНАЛИЗ ДАННЫХ ИНВЕНТАРЯ ==="
    
    if mysql -u root mi_core -e "DESCRIBE inventory_data;" > /dev/null 2>&1; then
        success "✓ Таблица inventory_data найдена"
        
        local total_inventory=$(mysql -u root mi_core -se "SELECT COUNT(*) FROM inventory_data;")
        log "Всего записей инвентаря: $total_inventory"
        
        # Анализ по источникам
        log "Распределение по источникам:"
        mysql -u root mi_core -e "
            SELECT source, COUNT(*) as count 
            FROM inventory_data 
            GROUP BY source 
            ORDER BY count DESC;
        " | while read line; do
            if [[ "$line" != *"source"* ]]; then
                log "  - $line"
            fi
        done
        
        # Анализ складов
        local unique_warehouses=$(mysql -u root mi_core -se "
            SELECT COUNT(DISTINCT warehouse_name) FROM inventory_data 
            WHERE warehouse_name IS NOT NULL;
        ")
        log "Уникальных складов: $unique_warehouses"
        
        # Критические остатки
        local critical_stock=$(mysql -u root mi_core -se "
            SELECT COUNT(*) FROM inventory_data 
            WHERE current_stock <= 5 AND current_stock > 0;
        ")
        log "Товары с критическими остатками (≤5): $critical_stock"
        
    else
        warning "⚠ Таблица inventory_data не найдена"
    fi
}

# Проверка связей между таблицами
check_table_relationships() {
    log "=== ПРОВЕРКА СВЯЗЕЙ МЕЖДУ ТАБЛИЦАМИ ==="
    
    # Проверяем связь product_master и inventory_data
    if mysql -u root mi_core -e "DESCRIBE product_master;" > /dev/null 2>&1 && 
       mysql -u root mi_core -e "DESCRIBE inventory_data;" > /dev/null 2>&1; then
        
        log "Проверяем связи product_master ↔ inventory_data..."
        
        # Товары в product_master, которые есть в inventory_data
        local linked_products=$(mysql -u root mi_core -se "
            SELECT COUNT(DISTINCT pm.id) 
            FROM product_master pm 
            JOIN inventory_data id ON pm.sku_ozon = id.sku;
        ")
        
        local total_master_products=$(mysql -u root mi_core -se "SELECT COUNT(*) FROM product_master;")
        
        log "Товары из product_master с данными инвентаря: $linked_products из $total_master_products"
        
        if [ "$total_master_products" -gt 0 ]; then
            local link_percentage=$(echo "scale=2; $linked_products * 100 / $total_master_products" | bc)
            log "Процент связанности: ${link_percentage}%"
        fi
    fi
}

# Анализ производительности
analyze_performance() {
    log "=== АНАЛИЗ ПРОИЗВОДИТЕЛЬНОСТИ ==="
    
    # Тест скорости запросов
    log "Тестируем скорость основных запросов..."
    
    # Запрос к product_master
    local start_time=$(date +%s.%N)
    mysql -u root mi_core -e "SELECT COUNT(*) FROM product_master;" > /dev/null
    local end_time=$(date +%s.%N)
    local query_time=$(echo "$end_time - $start_time" | bc)
    log "Время запроса COUNT(product_master): ${query_time}s"
    
    # Запрос с JOIN
    if mysql -u root mi_core -e "DESCRIBE inventory_data;" > /dev/null 2>&1; then
        local start_time=$(date +%s.%N)
        mysql -u root mi_core -e "
            SELECT COUNT(*) 
            FROM product_master pm 
            LEFT JOIN inventory_data id ON pm.sku_ozon = id.sku 
            LIMIT 100;
        " > /dev/null
        local end_time=$(date +%s.%N)
        local join_time=$(echo "$end_time - $start_time" | bc)
        log "Время JOIN запроса: ${join_time}s"
    fi
}

# Проверка API endpoints
test_api_endpoints() {
    log "=== ТЕСТИРОВАНИЕ API ENDPOINTS ==="
    
    local api_files=(
        "api/sync-stats.php"
        "api/analytics.php"
        "api/fix-product-names.php"
    )
    
    for api_file in "${api_files[@]}"; do
        if [ -f "$api_file" ]; then
            log "Тестируем: $api_file"
            
            local start_time=$(date +%s.%N)
            if php "$api_file" > /dev/null 2>&1; then
                local end_time=$(date +%s.%N)
                local response_time=$(echo "$end_time - $start_time" | bc)
                success "✓ $api_file работает (${response_time}s)"
            else
                error "✗ $api_file не работает"
            fi
        else
            warning "⚠ $api_file не найден"
        fi
    done
}

# Проверка JavaScript файлов
check_javascript_files() {
    log "=== ПРОВЕРКА JAVASCRIPT ФАЙЛОВ ==="
    
    if [ -f "js/dashboard-fixes.js" ]; then
        local file_size=$(stat -f%z "js/dashboard-fixes.js" 2>/dev/null || stat -c%s "js/dashboard-fixes.js" 2>/dev/null)
        success "✓ dashboard-fixes.js найден (размер: ${file_size} байт)"
        
        # Проверяем основные функции
        if grep -q "loadSyncStats" "js/dashboard-fixes.js"; then
            log "  ✓ Функция loadSyncStats найдена"
        fi
        
        if grep -q "loadAnalytics" "js/dashboard-fixes.js"; then
            log "  ✓ Функция loadAnalytics найдена"
        fi
        
        if grep -q "safeSetInnerHTML" "js/dashboard-fixes.js"; then
            log "  ✓ Функция safeSetInnerHTML найдена"
        fi
    else
        warning "⚠ js/dashboard-fixes.js не найден"
    fi
}

# Создание отчета о состоянии данных
create_data_report() {
    log "=== СОЗДАНИЕ ОТЧЕТА О СОСТОЯНИИ ДАННЫХ ==="
    
    local report_file="data_structure_report_$(date +%Y%m%d_%H%M%S).json"
    
    # Собираем данные для отчета
    local total_products=$(mysql -u root mi_core -se "SELECT COUNT(*) FROM product_master;" 2>/dev/null || echo "0")
    local products_with_names=$(mysql -u root mi_core -se "
        SELECT COUNT(*) FROM product_master 
        WHERE (product_name IS NOT NULL AND product_name != '' AND product_name NOT LIKE 'Товар артикул%')
        OR (name IS NOT NULL AND name != '' AND name NOT LIKE 'Товар артикул%');
    " 2>/dev/null || echo "0")
    
    local total_inventory=$(mysql -u root mi_core -se "SELECT COUNT(*) FROM inventory_data;" 2>/dev/null || echo "0")
    
    # Создаем JSON отчет
    cat > "$report_file" << EOF
{
    "report_date": "$(date -Iseconds)",
    "database_status": "connected",
    "tables": {
        "product_master": {
            "exists": true,
            "total_records": $total_products,
            "records_with_names": $products_with_names,
            "name_completeness_percent": $(echo "scale=2; $products_with_names * 100 / $total_products" | bc 2>/dev/null || echo "0")
        },
        "inventory_data": {
            "exists": true,
            "total_records": $total_inventory
        }
    },
    "api_status": {
        "sync_stats": "$([ -f "api/sync-stats.php" ] && echo "available" || echo "missing")",
        "analytics": "$([ -f "api/analytics.php" ] && echo "available" || echo "missing")",
        "fix_product_names": "$([ -f "api/fix-product-names.php" ] && echo "available" || echo "missing")"
    },
    "javascript_status": {
        "dashboard_fixes": "$([ -f "js/dashboard-fixes.js" ] && echo "available" || echo "missing")"
    },
    "overall_health": "$([ $products_with_names -eq $total_products ] && echo "excellent" || echo "needs_attention")"
}
EOF
    
    success "✓ Отчет создан: $report_file"
}

# Основная функция
main() {
    log "🔍 === НАЧИНАЕМ ОТЛАДКУ СТРУКТУРЫ ДАННЫХ ==="
    
    # Выполняем все проверки
    check_database_connection || exit 1
    analyze_table_structure
    analyze_product_master_quality
    analyze_inventory_data
    check_table_relationships
    analyze_performance
    test_api_endpoints
    check_javascript_files
    create_data_report
    
    success "🎉 === ОТЛАДКА СТРУКТУРЫ ДАННЫХ ЗАВЕРШЕНА ==="
    
    echo ""
    echo -e "${GREEN}=== СВОДКА ОТЛАДКИ ===${NC}"
    echo "✅ Подключение к базе данных: OK"
    echo "✅ Структура таблиц: проанализирована"
    echo "✅ Качество данных: оценено"
    echo "✅ API endpoints: протестированы"
    echo "✅ JavaScript файлы: проверены"
    echo ""
    echo -e "${BLUE}Подробные результаты сохранены в:${NC}"
    echo "- Лог отладки: $LOG_FILE"
    echo "- JSON отчет: data_structure_report_*.json"
}

# Обработка ошибок
trap 'error "Отладка прервана из-за ошибки"' ERR

# Запуск основной функции
main "$@"