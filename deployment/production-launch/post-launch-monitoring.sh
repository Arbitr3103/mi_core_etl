#!/bin/bash

# Скрипт мониторинга системы после запуска в продакшн

set -e

# Конфигурация
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$SCRIPT_DIR/logs/post_launch_monitoring_$(date +%Y%m%d_%H%M%S).log"
MONITORING_DURATION=${MONITORING_DURATION:-48} # часов
CHECK_INTERVAL=${CHECK_INTERVAL:-300} # секунд (5 минут)

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
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

# Создание директорий
create_directories() {
    mkdir -p "$SCRIPT_DIR/logs"
    mkdir -p "$SCRIPT_DIR/monitoring-data"
}

# Проверка здоровья API
check_api_health() {
    local api_url="http://localhost:8080"
    local health_status="UNKNOWN"
    local response_time=0
    
    # Проверяем health endpoint
    if response=$(curl -s -w "%{http_code}:%{time_total}" "$api_url/health" 2>/dev/null); then
        local http_code=$(echo "$response" | cut -d':' -f1)
        response_time=$(echo "$response" | cut -d':' -f2)
        
        if [ "$http_code" = "200" ]; then
            health_status="HEALTHY"
        else
            health_status="UNHEALTHY (HTTP $http_code)"
        fi
    else
        health_status="UNREACHABLE"
    fi
    
    echo "$health_status:$response_time"
}

# Проверка производительности API
check_api_performance() {
    local api_url="http://localhost:8080/api"
    local endpoints=(
        "master-products"
        "data-quality/metrics"
        "sku-mapping?limit=10"
    )
    
    local total_time=0
    local successful_requests=0
    local failed_requests=0
    
    for endpoint in "${endpoints[@]}"; do
        local start_time=$(date +%s.%N)
        
        if curl -s -f "$api_url/$endpoint" > /dev/null 2>&1; then
            ((successful_requests++))
        else
            ((failed_requests++))
        fi
        
        local end_time=$(date +%s.%N)
        local request_time=$(echo "$end_time - $start_time" | bc)
        total_time=$(echo "$total_time + $request_time" | bc)
    done
    
    local avg_time=$(echo "scale=3; $total_time / ${#endpoints[@]}" | bc)
    local success_rate=$(echo "scale=2; $successful_requests * 100 / (${#endpoints[@]})" | bc)
    
    echo "$avg_time:$success_rate:$successful_requests:$failed_requests"
}

# Проверка состояния базы данных
check_database_health() {
    local db_status="UNKNOWN"
    local connection_count=0
    local query_time=0
    
    # Проверяем подключение к базе данных
    if mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -e "SELECT 1" "$DB_NAME" &>/dev/null; then
        db_status="CONNECTED"
        
        # Получаем количество активных подключений
        connection_count=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -se "SHOW STATUS LIKE 'Threads_connected'" "$DB_NAME" | awk '{print $2}')
        
        # Измеряем время выполнения простого запроса
        local start_time=$(date +%s.%N)
        mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -se "SELECT COUNT(*) FROM master_products" "$DB_NAME" &>/dev/null
        local end_time=$(date +%s.%N)
        query_time=$(echo "scale=3; $end_time - $start_time" | bc)
    else
        db_status="DISCONNECTED"
    fi
    
    echo "$db_status:$connection_count:$query_time"
}

# Проверка системных ресурсов
check_system_resources() {
    # CPU использование
    local cpu_usage=$(top -bn1 | grep "Cpu(s)" | awk '{print $2}' | sed 's/%us,//')
    
    # Память
    local memory_info=$(free | grep Mem)
    local total_mem=$(echo $memory_info | awk '{print $2}')
    local used_mem=$(echo $memory_info | awk '{print $3}')
    local memory_usage=$(echo "scale=2; $used_mem * 100 / $total_mem" | bc)
    
    # Дисковое пространство
    local disk_usage=$(df -h / | awk 'NR==2 {print $5}' | sed 's/%//')
    
    echo "$cpu_usage:$memory_usage:$disk_usage"
}

# Проверка качества данных
check_data_quality() {
    local master_count=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -se "SELECT COUNT(*) FROM master_products" "$DB_NAME" 2>/dev/null || echo "0")
    local sku_count=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -se "SELECT COUNT(*) FROM sku_mapping" "$DB_NAME" 2>/dev/null || echo "0")
    
    # Процент товаров с известными брендами
    local branded_products=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -se "SELECT COUNT(*) FROM master_products WHERE canonical_brand != 'Неизвестный бренд'" "$DB_NAME" 2>/dev/null || echo "0")
    local brand_coverage=0
    if [ "$master_count" -gt 0 ]; then
        brand_coverage=$(echo "scale=2; $branded_products * 100 / $master_count" | bc)
    fi
    
    # Процент товаров с категориями
    local categorized_products=$(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" -se "SELECT COUNT(*) FROM master_products WHERE canonical_category != 'Без категории'" "$DB_NAME" 2>/dev/null || echo "0")
    local category_coverage=0
    if [ "$master_count" -gt 0 ]; then
        category_coverage=$(echo "scale=2; $categorized_products * 100 / $master_count" | bc)
    fi
    
    echo "$master_count:$sku_count:$brand_coverage:$category_coverage"
}

# Сбор метрик мониторинга
collect_metrics() {
    local timestamp=$(date -Iseconds)
    
    # Проверяем здоровье API
    local api_health=$(check_api_health)
    local api_status=$(echo "$api_health" | cut -d':' -f1)
    local api_response_time=$(echo "$api_health" | cut -d':' -f2)
    
    # Проверяем производительность API
    local api_perf=$(check_api_performance)
    local avg_response_time=$(echo "$api_perf" | cut -d':' -f1)
    local success_rate=$(echo "$api_perf" | cut -d':' -f2)
    local successful_requests=$(echo "$api_perf" | cut -d':' -f3)
    local failed_requests=$(echo "$api_perf" | cut -d':' -f4)
    
    # Проверяем базу данных
    local db_health=$(check_database_health)
    local db_status=$(echo "$db_health" | cut -d':' -f1)
    local db_connections=$(echo "$db_health" | cut -d':' -f2)
    local db_query_time=$(echo "$db_health" | cut -d':' -f3)
    
    # Проверяем системные ресурсы
    local sys_resources=$(check_system_resources)
    local cpu_usage=$(echo "$sys_resources" | cut -d':' -f1)
    local memory_usage=$(echo "$sys_resources" | cut -d':' -f2)
    local disk_usage=$(echo "$sys_resources" | cut -d':' -f3)
    
    # Проверяем качество данных
    local data_quality=$(check_data_quality)
    local master_count=$(echo "$data_quality" | cut -d':' -f1)
    local sku_count=$(echo "$data_quality" | cut -d':' -f2)
    local brand_coverage=$(echo "$data_quality" | cut -d':' -f3)
    local category_coverage=$(echo "$data_quality" | cut -d':' -f4)
    
    # Сохраняем метрики в JSON формате
    local metrics_file="$SCRIPT_DIR/monitoring-data/metrics_$(date +%Y%m%d_%H%M%S).json"
    
    cat > "$metrics_file" << EOF
{
    "timestamp": "$timestamp",
    "api": {
        "status": "$api_status",
        "health_response_time": $api_response_time,
        "avg_response_time": $avg_response_time,
        "success_rate": $success_rate,
        "successful_requests": $successful_requests,
        "failed_requests": $failed_requests
    },
    "database": {
        "status": "$db_status",
        "connections": $db_connections,
        "query_time": $db_query_time
    },
    "system": {
        "cpu_usage": $cpu_usage,
        "memory_usage": $memory_usage,
        "disk_usage": $disk_usage
    },
    "data_quality": {
        "master_products": $master_count,
        "sku_mappings": $sku_count,
        "brand_coverage": $brand_coverage,
        "category_coverage": $category_coverage
    }
}
EOF
    
    # Выводим текущий статус
    echo -e "\n${BLUE}=== Статус системы на $(date) ===${NC}"
    echo "API: $api_status (время отклика: ${api_response_time}s)"
    echo "База данных: $db_status (подключений: $db_connections)"
    echo "CPU: ${cpu_usage}%, Память: ${memory_usage}%, Диск: ${disk_usage}%"
    echo "Мастер-продуктов: $master_count, SKU: $sku_count"
    echo "Покрытие брендами: ${brand_coverage}%, категориями: ${category_coverage}%"
    
    # Проверяем критические пороги
    check_critical_thresholds "$api_status" "$api_response_time" "$success_rate" "$cpu_usage" "$memory_usage" "$disk_usage"
}

# Проверка критических порогов
check_critical_thresholds() {
    local api_status="$1"
    local api_response_time="$2"
    local success_rate="$3"
    local cpu_usage="$4"
    local memory_usage="$5"
    local disk_usage="$6"
    
    local alerts=()
    
    # Проверяем API
    if [ "$api_status" != "HEALTHY" ]; then
        alerts+=("🚨 API недоступен: $api_status")
    elif (( $(echo "$api_response_time > 1.0" | bc -l) )); then
        alerts+=("⚠️ Медленный API: ${api_response_time}s")
    fi
    
    # Проверяем успешность запросов
    if (( $(echo "$success_rate < 95" | bc -l) )); then
        alerts+=("⚠️ Низкий процент успешных запросов: ${success_rate}%")
    fi
    
    # Проверяем системные ресурсы
    if (( $(echo "$cpu_usage > 80" | bc -l) )); then
        alerts+=("⚠️ Высокая загрузка CPU: ${cpu_usage}%")
    fi
    
    if (( $(echo "$memory_usage > 85" | bc -l) )); then
        alerts+=("⚠️ Высокое использование памяти: ${memory_usage}%")
    fi
    
    if [ "$disk_usage" -gt 85 ]; then
        alerts+=("⚠️ Мало места на диске: ${disk_usage}%")
    fi
    
    # Отправляем алерты
    if [ ${#alerts[@]} -gt 0 ]; then
        for alert in "${alerts[@]}"; do
            warning "$alert"
        done
        
        send_alert_notification "${alerts[@]}"
    else
        log "✅ Все метрики в пределах нормы"
    fi
}

# Отправка уведомлений об алертах
send_alert_notification() {
    local alerts=("$@")
    local alert_message="MDM система - обнаружены проблемы:\n"
    
    for alert in "${alerts[@]}"; do
        alert_message="$alert_message- $alert\n"
    done
    
    # Отправляем в Slack
    if [ -n "$SLACK_WEBHOOK_URL" ]; then
        curl -X POST -H 'Content-type: application/json' \
            --data "{\"text\":\"$alert_message\"}" \
            "$SLACK_WEBHOOK_URL" &>/dev/null || true
    fi
    
    # Отправляем по email
    if [ -n "$EMAIL_ALERTS_TO" ]; then
        echo -e "$alert_message" | mail -s "MDM System Alert" "$EMAIL_ALERTS_TO" &>/dev/null || true
    fi
}

# Создание сводного отчета
create_monitoring_report() {
    local report_file="$SCRIPT_DIR/monitoring_report_$(date +%Y%m%d_%H%M%S).json"
    
    info "Создаем сводный отчет мониторинга..."
    
    # Анализируем собранные метрики
    local metrics_files=("$SCRIPT_DIR"/monitoring-data/metrics_*.json)
    local total_checks=0
    local healthy_checks=0
    local avg_response_time=0
    local min_response_time=999
    local max_response_time=0
    
    if [ ${#metrics_files[@]} -gt 0 ] && [ -f "${metrics_files[0]}" ]; then
        for metrics_file in "${metrics_files[@]}"; do
            if [ -f "$metrics_file" ]; then
                ((total_checks++))
                
                local api_status=$(cat "$metrics_file" | grep -o '"status":"[^"]*"' | head -1 | cut -d'"' -f4)
                if [ "$api_status" = "HEALTHY" ]; then
                    ((healthy_checks++))
                fi
                
                local response_time=$(cat "$metrics_file" | grep -o '"health_response_time":[^,}]*' | cut -d':' -f2)
                if [ -n "$response_time" ]; then
                    avg_response_time=$(echo "$avg_response_time + $response_time" | bc)
                    
                    if (( $(echo "$response_time < $min_response_time" | bc -l) )); then
                        min_response_time=$response_time
                    fi
                    
                    if (( $(echo "$response_time > $max_response_time" | bc -l) )); then
                        max_response_time=$response_time
                    fi
                fi
            fi
        done
        
        if [ $total_checks -gt 0 ]; then
            avg_response_time=$(echo "scale=3; $avg_response_time / $total_checks" | bc)
        fi
    fi
    
    local uptime_percentage=0
    if [ $total_checks -gt 0 ]; then
        uptime_percentage=$(echo "scale=2; $healthy_checks * 100 / $total_checks" | bc)
    fi
    
    # Получаем текущие данные системы
    local current_data=$(check_data_quality)
    local current_master_count=$(echo "$current_data" | cut -d':' -f1)
    local current_sku_count=$(echo "$current_data" | cut -d':' -f2)
    
    cat > "$report_file" << EOF
{
    "monitoring_period": {
        "start_time": "$(date -d "$MONITORING_DURATION hours ago" -Iseconds)",
        "end_time": "$(date -Iseconds)",
        "duration_hours": $MONITORING_DURATION
    },
    "system_stability": {
        "total_checks": $total_checks,
        "healthy_checks": $healthy_checks,
        "uptime_percentage": $uptime_percentage,
        "avg_response_time": $avg_response_time,
        "min_response_time": $min_response_time,
        "max_response_time": $max_response_time
    },
    "current_system_state": {
        "master_products": $current_master_count,
        "sku_mappings": $current_sku_count,
        "api_status": "$(check_api_health | cut -d':' -f1)",
        "database_status": "$(check_database_health | cut -d':' -f1)"
    },
    "recommendations": [
        $([ $total_checks -eq 0 ] && echo '"Недостаточно данных для анализа"' || echo '')
        $([ $(echo "$uptime_percentage < 99" | bc -l) -eq 1 ] && echo '"Исследовать причины простоев системы",' || echo '')
        $([ $(echo "$avg_response_time > 0.5" | bc -l) -eq 1 ] && echo '"Оптимизировать производительность API",' || echo '')
        "Продолжить мониторинг системы"
    ],
    "overall_status": "$([ $(echo "$uptime_percentage >= 99" | bc -l) -eq 1 ] && echo "STABLE" || echo "NEEDS_ATTENTION")"
}
EOF
    
    # Удаляем лишние запятые из JSON
    sed -i 's/,]/]/g' "$report_file"
    sed -i 's/\[,/[/g' "$report_file"
    
    log "✓ Сводный отчет создан: $report_file"
    
    # Выводим краткую сводку
    echo -e "\n${GREEN}=== СВОДКА МОНИТОРИНГА ===${NC}"
    echo "Период мониторинга: $MONITORING_DURATION часов"
    echo "Всего проверок: $total_checks"
    echo "Время работы: ${uptime_percentage}%"
    echo "Среднее время отклика: ${avg_response_time}s"
    echo "Текущее состояние: $([ $(echo "$uptime_percentage >= 99" | bc -l) -eq 1 ] && echo "СТАБИЛЬНО" || echo "ТРЕБУЕТ ВНИМАНИЯ")"
}

# Основная функция мониторинга
main() {
    log "🔍 === НАЧИНАЕМ МОНИТОРИНГ ПОСЛЕ ЗАПУСКА MDM СИСТЕМЫ ==="
    
    # Проверяем аргументы
    local continuous_mode=false
    local generate_report=false
    
    while [[ $# -gt 0 ]]; do
        case $1 in
            --continuous)
                continuous_mode=true
                shift
                ;;
            --duration)
                MONITORING_DURATION="$2"
                shift 2
                ;;
            --interval)
                CHECK_INTERVAL="$2"
                shift 2
                ;;
            --report)
                generate_report=true
                shift
                ;;
            --help)
                echo "Использование: $0 [опции]"
                echo "Опции:"
                echo "  --continuous         Непрерывный мониторинг"
                echo "  --duration HOURS     Продолжительность мониторинга (по умолчанию: 48)"
                echo "  --interval SECONDS   Интервал проверок (по умолчанию: 300)"
                echo "  --report             Создать только отчет по существующим данным"
                echo "  --help               Показать эту справку"
                exit 0
                ;;
            *)
                error "Неизвестная опция: $1"
                exit 1
                ;;
        esac
    done
    
    create_directories
    
    # Загружаем переменные окружения
    if [ -f "$SCRIPT_DIR/../production/.env" ]; then
        source "$SCRIPT_DIR/../production/.env"
    fi
    
    if [ "$generate_report" = true ]; then
        create_monitoring_report
        exit 0
    fi
    
    log "Параметры мониторинга:"
    log "- Продолжительность: $MONITORING_DURATION часов"
    log "- Интервал проверок: $CHECK_INTERVAL секунд"
    log "- Непрерывный режим: $continuous_mode"
    
    local start_time=$(date +%s)
    local end_time=$((start_time + MONITORING_DURATION * 3600))
    local check_count=0
    
    while true; do
        ((check_count++))
        
        log "--- Проверка #$check_count ---"
        collect_metrics
        
        local current_time=$(date +%s)
        
        # Проверяем, не истекло ли время мониторинга
        if [ "$continuous_mode" != true ] && [ $current_time -ge $end_time ]; then
            log "Период мониторинга завершен"
            break
        fi
        
        # Ждем до следующей проверки
        if [ "$continuous_mode" = true ] || [ $current_time -lt $end_time ]; then
            info "Следующая проверка через $CHECK_INTERVAL секунд..."
            sleep $CHECK_INTERVAL
        fi
    done
    
    # Создаем итоговый отчет
    create_monitoring_report
    
    log "🏁 === МОНИТОРИНГ ЗАВЕРШЕН ==="
}

# Запуск основной функции
main "$@"