#!/bin/bash
"""
Inventory Health Check Script
Скрипт проверки состояния системы синхронизации остатков

Использование:
  ./check_inventory_health.sh

Для добавления в crontab:
  # Каждый час
  0 * * * * /path/to/check_inventory_health.sh

Автор: Inventory Monitoring System
Версия: 1.0
"""

# Настройки
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$SCRIPT_DIR/logs"
HEALTH_LOG="$LOG_DIR/health_check.log"
ALERT_LOG="$LOG_DIR/health_alerts.log"

# Пороговые значения
MAX_SYNC_AGE_HOURS=8          # Максимальный возраст последней синхронизации (часы)
MIN_PRODUCTS_OZON=10          # Минимальное количество товаров Ozon
MIN_PRODUCTS_WB=5             # Минимальное количество товаров WB
MAX_LOG_SIZE_MB=100           # Максимальный размер логов (MB)
MAX_FAILED_SYNCS=3            # Максимальное количество неудачных синхронизаций подряд

# Функция логирования
log() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" | tee -a "$HEALTH_LOG"
    
    # Дублируем критические сообщения в алерт лог
    if [[ "$level" == "CRITICAL" || "$level" == "ERROR" ]]; then
        echo "[$timestamp] [$level] $message" >> "$ALERT_LOG"
    fi
}

# Функция отправки уведомлений
send_alert() {
    local subject=$1
    local message=$2
    
    # Логируем алерт
    log "ALERT" "$subject: $message"
    
    # Отправляем в системный лог
    logger -t "inventory_health" "$subject: $message"
    
    # Здесь можно добавить отправку email, Slack, Telegram и т.д.
    # Пример для email (требует настройки sendmail):
    # echo "$message" | mail -s "$subject" admin@example.com
    
    # Пример для webhook (требует curl):
    # curl -X POST -H "Content-Type: application/json" \
    #      -d "{\"text\":\"$subject: $message\"}" \
    #      "https://hooks.slack.com/services/YOUR/WEBHOOK/URL"
}

# Создание директории для логов
mkdir -p "$LOG_DIR"

log "INFO" "=== НАЧАЛО ПРОВЕРКИ СОСТОЯНИЯ СИСТЕМЫ ==="

# Проверка Python окружения
PYTHON_CMD=""
if command -v python3 &> /dev/null; then
    PYTHON_CMD="python3"
elif command -v python &> /dev/null; then
    PYTHON_CMD="python"
else
    log "CRITICAL" "Python не найден в системе"
    send_alert "Inventory Health Critical" "Python не найден в системе"
    exit 1
fi

# Установка переменных окружения
export PYTHONPATH="$SCRIPT_DIR:$PYTHONPATH"
export PYTHONIOENCODING=utf-8

# Создание временного Python скрипта для проверки БД
HEALTH_CHECK_SCRIPT=$(mktemp)
cat > "$HEALTH_CHECK_SCRIPT" << 'EOF'
#!/usr/bin/env python3
import sys
import os
import json
from datetime import datetime, timedelta
sys.path.append(os.path.dirname(__file__))

def check_database_health():
    """Проверка состояния базы данных и синхронизации."""
    try:
        from importers.ozon_importer import connect_to_db
        
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        health_status = {
            'database_connection': True,
            'last_sync_times': {},
            'product_counts': {},
            'recent_errors': [],
            'sync_success_rate': {},
            'data_freshness': {}
        }
        
        # Проверка времени последней синхронизации
        cursor.execute("""
            SELECT 
                source,
                MAX(completed_at) as last_sync,
                COUNT(*) as total_syncs,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_syncs
            FROM sync_logs 
            WHERE sync_type = 'inventory' 
            AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY source
        """)
        
        sync_stats = cursor.fetchall()
        
        for stat in sync_stats:
            source = stat['source']
            last_sync = stat['last_sync']
            
            health_status['last_sync_times'][source] = {
                'last_sync': last_sync.isoformat() if last_sync else None,
                'hours_ago': (datetime.now() - last_sync).total_seconds() / 3600 if last_sync else None
            }
            
            # Расчет процента успешности
            success_rate = (stat['successful_syncs'] / stat['total_syncs'] * 100) if stat['total_syncs'] > 0 else 0
            health_status['sync_success_rate'][source] = {
                'success_rate': success_rate,
                'total_syncs': stat['total_syncs'],
                'successful_syncs': stat['successful_syncs']
            }
        
        # Проверка количества товаров по источникам
        cursor.execute("""
            SELECT 
                source,
                COUNT(DISTINCT product_id) as unique_products,
                SUM(quantity_present) as total_present,
                MAX(last_sync_at) as last_data_sync
            FROM inventory_data
            GROUP BY source
        """)
        
        inventory_stats = cursor.fetchall()
        
        for stat in inventory_stats:
            source = stat['source']
            health_status['product_counts'][source] = {
                'unique_products': stat['unique_products'],
                'total_present': stat['total_present'],
                'last_data_sync': stat['last_data_sync'].isoformat() if stat['last_data_sync'] else None
            }
        
        # Проверка недавних ошибок
        cursor.execute("""
            SELECT 
                source,
                error_message,
                started_at,
                status
            FROM sync_logs 
            WHERE sync_type = 'inventory' 
            AND status IN ('failed', 'partial')
            AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY started_at DESC
            LIMIT 10
        """)
        
        recent_errors = cursor.fetchall()
        
        for error in recent_errors:
            health_status['recent_errors'].append({
                'source': error['source'],
                'error_message': error['error_message'],
                'timestamp': error['started_at'].isoformat(),
                'status': error['status']
            })
        
        # Проверка актуальности данных
        cursor.execute("""
            SELECT 
                source,
                COUNT(*) as records_count,
                MIN(last_sync_at) as oldest_sync,
                MAX(last_sync_at) as newest_sync,
                AVG(TIMESTAMPDIFF(HOUR, last_sync_at, NOW())) as avg_age_hours
            FROM inventory_data
            GROUP BY source
        """)
        
        freshness_stats = cursor.fetchall()
        
        for stat in freshness_stats:
            source = stat['source']
            health_status['data_freshness'][source] = {
                'records_count': stat['records_count'],
                'oldest_sync': stat['oldest_sync'].isoformat() if stat['oldest_sync'] else None,
                'newest_sync': stat['newest_sync'].isoformat() if stat['newest_sync'] else None,
                'avg_age_hours': float(stat['avg_age_hours']) if stat['avg_age_hours'] else None
            }
        
        cursor.close()
        connection.close()
        
        return health_status
        
    except Exception as e:
        return {
            'database_connection': False,
            'error': str(e)
        }

if __name__ == "__main__":
    health_data = check_database_health()
    print(json.dumps(health_data, indent=2, default=str))
EOF

# Выполнение проверки состояния БД
cd "$SCRIPT_DIR"
HEALTH_DATA=$($PYTHON_CMD "$HEALTH_CHECK_SCRIPT" 2>/dev/null)
HEALTH_EXIT_CODE=$?

# Удаление временного скрипта
rm -f "$HEALTH_CHECK_SCRIPT"

# Анализ результатов проверки
ISSUES_FOUND=0

if [ $HEALTH_EXIT_CODE -ne 0 ]; then
    log "CRITICAL" "Ошибка выполнения проверки состояния БД"
    send_alert "Inventory Health Critical" "Не удалось выполнить проверку состояния базы данных"
    ISSUES_FOUND=$((ISSUES_FOUND + 1))
else
    # Парсинг JSON результата (требует jq, но можем обойтись без него)
    if command -v jq &> /dev/null; then
        # Используем jq для парсинга JSON
        DB_CONNECTION=$(echo "$HEALTH_DATA" | jq -r '.database_connection // false')
        
        if [ "$DB_CONNECTION" != "true" ]; then
            log "CRITICAL" "Нет подключения к базе данных"
            send_alert "Inventory Health Critical" "Отсутствует подключение к базе данных"
            ISSUES_FOUND=$((ISSUES_FOUND + 1))
        else
            log "INFO" "Подключение к базе данных: OK"
            
            # Проверка времени последней синхронизации
            for source in "Ozon" "Wildberries"; do
                HOURS_AGO=$(echo "$HEALTH_DATA" | jq -r ".last_sync_times.\"$source\".hours_ago // null")
                
                if [ "$HOURS_AGO" = "null" ]; then
                    log "ERROR" "Нет данных о синхронизации для источника $source"
                    send_alert "Inventory Sync Missing" "Отсутствуют данные о синхронизации для $source"
                    ISSUES_FOUND=$((ISSUES_FOUND + 1))
                elif (( $(echo "$HOURS_AGO > $MAX_SYNC_AGE_HOURS" | bc -l) )); then
                    log "ERROR" "Устаревшие данные для $source (${HOURS_AGO} часов назад)"
                    send_alert "Inventory Data Stale" "Данные $source устарели: ${HOURS_AGO} часов назад"
                    ISSUES_FOUND=$((ISSUES_FOUND + 1))
                else
                    log "INFO" "Синхронизация $source: OK (${HOURS_AGO} часов назад)"
                fi
                
                # Проверка количества товаров
                PRODUCT_COUNT=$(echo "$HEALTH_DATA" | jq -r ".product_counts.\"$source\".unique_products // 0")
                MIN_PRODUCTS=$([ "$source" = "Ozon" ] && echo $MIN_PRODUCTS_OZON || echo $MIN_PRODUCTS_WB)
                
                if [ "$PRODUCT_COUNT" -lt "$MIN_PRODUCTS" ]; then
                    log "ERROR" "Мало товаров для $source: $PRODUCT_COUNT (минимум $MIN_PRODUCTS)"
                    send_alert "Inventory Low Product Count" "$source: только $PRODUCT_COUNT товаров (минимум $MIN_PRODUCTS)"
                    ISSUES_FOUND=$((ISSUES_FOUND + 1))
                else
                    log "INFO" "Количество товаров $source: OK ($PRODUCT_COUNT)"
                fi
                
                # Проверка процента успешности
                SUCCESS_RATE=$(echo "$HEALTH_DATA" | jq -r ".sync_success_rate.\"$source\".success_rate // 0")
                if (( $(echo "$SUCCESS_RATE < 80" | bc -l) )); then
                    log "WARNING" "Низкий процент успешности для $source: ${SUCCESS_RATE}%"
                    if (( $(echo "$SUCCESS_RATE < 50" | bc -l) )); then
                        send_alert "Inventory Low Success Rate" "$source: критически низкий процент успешности ${SUCCESS_RATE}%"
                        ISSUES_FOUND=$((ISSUES_FOUND + 1))
                    fi
                else
                    log "INFO" "Процент успешности $source: OK (${SUCCESS_RATE}%)"
                fi
            done
            
            # Проверка недавних ошибок
            ERROR_COUNT=$(echo "$HEALTH_DATA" | jq -r '.recent_errors | length')
            if [ "$ERROR_COUNT" -gt 0 ]; then
                log "WARNING" "Найдено $ERROR_COUNT недавних ошибок синхронизации"
                if [ "$ERROR_COUNT" -gt "$MAX_FAILED_SYNCS" ]; then
                    send_alert "Inventory Multiple Errors" "Обнаружено $ERROR_COUNT ошибок синхронизации за последние 24 часа"
                    ISSUES_FOUND=$((ISSUES_FOUND + 1))
                fi
            else
                log "INFO" "Недавние ошибки: не найдены"
            fi
        fi
    else
        # Простая проверка без jq
        if echo "$HEALTH_DATA" | grep -q '"database_connection": false'; then
            log "CRITICAL" "Нет подключения к базе данных"
            send_alert "Inventory Health Critical" "Отсутствует подключение к базе данных"
            ISSUES_FOUND=$((ISSUES_FOUND + 1))
        else
            log "INFO" "Подключение к базе данных: OK"
        fi
    fi
fi

# Проверка размера логов
LOG_SIZE_MB=$(du -sm "$LOG_DIR" 2>/dev/null | cut -f1)
if [ "$LOG_SIZE_MB" -gt "$MAX_LOG_SIZE_MB" ]; then
    log "WARNING" "Большой размер логов: ${LOG_SIZE_MB}MB (максимум ${MAX_LOG_SIZE_MB}MB)"
    # Автоматическая очистка старых логов
    find "$LOG_DIR" -name "*.log" -mtime +7 -delete 2>/dev/null
    NEW_LOG_SIZE_MB=$(du -sm "$LOG_DIR" 2>/dev/null | cut -f1)
    log "INFO" "Очистка логов выполнена: ${LOG_SIZE_MB}MB -> ${NEW_LOG_SIZE_MB}MB"
else
    log "INFO" "Размер логов: OK (${LOG_SIZE_MB}MB)"
fi

# Проверка запущенных процессов синхронизации
RUNNING_SYNCS=$(ps aux | grep -E "(inventory_sync|run_inventory)" | grep -v grep | wc -l)
if [ "$RUNNING_SYNCS" -gt 3 ]; then
    log "WARNING" "Много запущенных процессов синхронизации: $RUNNING_SYNCS"
    send_alert "Inventory Too Many Processes" "Обнаружено $RUNNING_SYNCS запущенных процессов синхронизации"
    ISSUES_FOUND=$((ISSUES_FOUND + 1))
elif [ "$RUNNING_SYNCS" -gt 0 ]; then
    log "INFO" "Запущенные процессы синхронизации: $RUNNING_SYNCS"
else
    log "INFO" "Запущенные процессы синхронизации: отсутствуют"
fi

# Проверка доступности API (простая проверка)
if command -v curl &> /dev/null; then
    # Проверка Ozon API
    if curl -s --connect-timeout 10 "https://api-seller.ozon.ru" > /dev/null; then
        log "INFO" "Ozon API: доступен"
    else
        log "ERROR" "Ozon API: недоступен"
        send_alert "Inventory API Unavailable" "Ozon API недоступен"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
    
    # Проверка Wildberries API
    if curl -s --connect-timeout 10 "https://suppliers-api.wildberries.ru" > /dev/null; then
        log "INFO" "Wildberries API: доступен"
    else
        log "ERROR" "Wildberries API: недоступен"
        send_alert "Inventory API Unavailable" "Wildberries API недоступен"
        ISSUES_FOUND=$((ISSUES_FOUND + 1))
    fi
fi

# Проверка свободного места на диске
DISK_USAGE=$(df "$SCRIPT_DIR" | tail -1 | awk '{print $5}' | sed 's/%//')
if [ "$DISK_USAGE" -gt 90 ]; then
    log "CRITICAL" "Критически мало места на диске: ${DISK_USAGE}%"
    send_alert "Inventory Disk Space Critical" "Свободное место на диске: ${DISK_USAGE}%"
    ISSUES_FOUND=$((ISSUES_FOUND + 1))
elif [ "$DISK_USAGE" -gt 80 ]; then
    log "WARNING" "Мало места на диске: ${DISK_USAGE}%"
else
    log "INFO" "Свободное место на диске: OK ($((100 - DISK_USAGE))% свободно)"
fi

# Итоговый статус
if [ "$ISSUES_FOUND" -eq 0 ]; then
    log "INFO" "✅ Проверка состояния завершена: все системы работают нормально"
    exit 0
elif [ "$ISSUES_FOUND" -le 2 ]; then
    log "WARNING" "⚠️ Проверка состояния завершена: найдено $ISSUES_FOUND предупреждений"
    exit 1
else
    log "CRITICAL" "❌ Проверка состояния завершена: найдено $ISSUES_FOUND критических проблем"
    send_alert "Inventory Health Critical" "Обнаружено $ISSUES_FOUND критических проблем в системе синхронизации"
    exit 2
fi