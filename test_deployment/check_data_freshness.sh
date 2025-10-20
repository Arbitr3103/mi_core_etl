#!/bin/bash
"""
Data Freshness Check Script
Скрипт проверки актуальности данных синхронизации

Использование:
  ./check_data_freshness.sh

Для добавления в crontab:
  # Каждые 2 часа
  0 */2 * * * /path/to/check_data_freshness.sh

Автор: Inventory Monitoring System
Версия: 1.0
"""

# Настройки
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$SCRIPT_DIR/logs"
FRESHNESS_LOG="$LOG_DIR/freshness_check.log"

# Пороговые значения (в часах)
WARNING_AGE_HOURS=8    # Предупреждение если данные старше 8 часов
CRITICAL_AGE_HOURS=12  # Критическое состояние если данные старше 12 часов

# Функция логирования
log() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" | tee -a "$FRESHNESS_LOG"
}

# Функция отправки уведомлений
send_alert() {
    local level=$1
    local message=$2
    
    log "ALERT" "[$level] $message"
    logger -t "inventory_freshness" "[$level] $message"
    
    # Здесь можно добавить отправку уведомлений
    # email, Slack, Telegram и т.д.
}

# Создание директории для логов
mkdir -p "$LOG_DIR"

log "INFO" "=== ПРОВЕРКА АКТУАЛЬНОСТИ ДАННЫХ ==="

# Проверка Python окружения
PYTHON_CMD=""
if command -v python3 &> /dev/null; then
    PYTHON_CMD="python3"
elif command -v python &> /dev/null; then
    PYTHON_CMD="python"
else
    log "ERROR" "Python не найден в системе"
    exit 1
fi

# Установка переменных окружения
export PYTHONPATH="$SCRIPT_DIR:$PYTHONPATH"
export PYTHONIOENCODING=utf-8

# Создание Python скрипта для проверки актуальности
FRESHNESS_SCRIPT=$(mktemp)
cat > "$FRESHNESS_SCRIPT" << 'EOF'
#!/usr/bin/env python3
import sys
import os
import json
from datetime import datetime, timedelta
sys.path.append(os.path.dirname(__file__))

def check_data_freshness():
    """Проверка актуальности данных синхронизации."""
    try:
        from importers.ozon_importer import connect_to_db
        
        connection = connect_to_db()
        cursor = connection.cursor(dictionary=True)
        
        freshness_data = {
            'check_time': datetime.now().isoformat(),
            'sources': {},
            'overall_status': 'ok'
        }
        
        # Проверка актуальности данных по источникам
        cursor.execute("""
            SELECT 
                source,
                COUNT(*) as total_records,
                COUNT(DISTINCT product_id) as unique_products,
                MIN(last_sync_at) as oldest_sync,
                MAX(last_sync_at) as newest_sync,
                AVG(TIMESTAMPDIFF(HOUR, last_sync_at, NOW())) as avg_age_hours,
                COUNT(CASE WHEN last_sync_at < DATE_SUB(NOW(), INTERVAL 8 HOUR) THEN 1 END) as stale_records_8h,
                COUNT(CASE WHEN last_sync_at < DATE_SUB(NOW(), INTERVAL 12 HOUR) THEN 1 END) as stale_records_12h
            FROM inventory_data
            GROUP BY source
        """)
        
        inventory_freshness = cursor.fetchall()
        
        for data in inventory_freshness:
            source = data['source']
            avg_age = float(data['avg_age_hours']) if data['avg_age_hours'] else 0
            
            # Определение статуса актуальности
            if avg_age > 12:
                status = 'critical'
                freshness_data['overall_status'] = 'critical'
            elif avg_age > 8:
                status = 'warning'
                if freshness_data['overall_status'] == 'ok':
                    freshness_data['overall_status'] = 'warning'
            else:
                status = 'ok'
            
            freshness_data['sources'][source] = {
                'status': status,
                'total_records': data['total_records'],
                'unique_products': data['unique_products'],
                'oldest_sync': data['oldest_sync'].isoformat() if data['oldest_sync'] else None,
                'newest_sync': data['newest_sync'].isoformat() if data['newest_sync'] else None,
                'avg_age_hours': avg_age,
                'stale_records_8h': data['stale_records_8h'],
                'stale_records_12h': data['stale_records_12h']
            }
        
        # Проверка последних синхронизаций
        cursor.execute("""
            SELECT 
                source,
                MAX(completed_at) as last_completed,
                MAX(started_at) as last_started,
                COUNT(CASE WHEN status = 'success' AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as successful_24h,
                COUNT(CASE WHEN status = 'failed' AND started_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as failed_24h
            FROM sync_logs 
            WHERE sync_type = 'inventory'
            GROUP BY source
        """)
        
        sync_freshness = cursor.fetchall()
        
        for data in sync_freshness:
            source = data['source']
            
            if source in freshness_data['sources']:
                last_completed = data['last_completed']
                hours_since_sync = 0
                
                if last_completed:
                    hours_since_sync = (datetime.now() - last_completed).total_seconds() / 3600
                
                freshness_data['sources'][source].update({
                    'last_completed': last_completed.isoformat() if last_completed else None,
                    'last_started': data['last_started'].isoformat() if data['last_started'] else None,
                    'hours_since_sync': hours_since_sync,
                    'successful_24h': data['successful_24h'],
                    'failed_24h': data['failed_24h']
                })
                
                # Обновление статуса на основе времени последней синхронизации
                if hours_since_sync > 12:
                    freshness_data['sources'][source]['sync_status'] = 'critical'
                    freshness_data['overall_status'] = 'critical'
                elif hours_since_sync > 8:
                    freshness_data['sources'][source]['sync_status'] = 'warning'
                    if freshness_data['overall_status'] == 'ok':
                        freshness_data['overall_status'] = 'warning'
                else:
                    freshness_data['sources'][source]['sync_status'] = 'ok'
        
        # Проверка общей статистики
        cursor.execute("""
            SELECT 
                COUNT(*) as total_inventory_records,
                COUNT(DISTINCT product_id) as total_unique_products,
                COUNT(CASE WHEN last_sync_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN 1 END) as fresh_records,
                COUNT(CASE WHEN last_sync_at < DATE_SUB(NOW(), INTERVAL 12 HOUR) THEN 1 END) as stale_records
            FROM inventory_data
        """)
        
        overall_stats = cursor.fetchone()
        
        freshness_data['overall_stats'] = {
            'total_inventory_records': overall_stats['total_inventory_records'],
            'total_unique_products': overall_stats['total_unique_products'],
            'fresh_records': overall_stats['fresh_records'],
            'stale_records': overall_stats['stale_records'],
            'freshness_percentage': (overall_stats['fresh_records'] / overall_stats['total_inventory_records'] * 100) if overall_stats['total_inventory_records'] > 0 else 0
        }
        
        cursor.close()
        connection.close()
        
        return freshness_data
        
    except Exception as e:
        return {
            'error': str(e),
            'overall_status': 'error'
        }

if __name__ == "__main__":
    freshness_result = check_data_freshness()
    print(json.dumps(freshness_result, indent=2, default=str))
EOF

# Выполнение проверки актуальности
cd "$SCRIPT_DIR"
FRESHNESS_DATA=$($PYTHON_CMD "$FRESHNESS_SCRIPT" 2>/dev/null)
FRESHNESS_EXIT_CODE=$?

# Удаление временного скрипта
rm -f "$FRESHNESS_SCRIPT"

# Анализ результатов
if [ $FRESHNESS_EXIT_CODE -ne 0 ]; then
    log "ERROR" "Ошибка выполнения проверки актуальности данных"
    exit 1
fi

# Парсинг результатов (с jq если доступен, иначе простой grep)
if command -v jq &> /dev/null; then
    OVERALL_STATUS=$(echo "$FRESHNESS_DATA" | jq -r '.overall_status // "unknown"')
    
    case "$OVERALL_STATUS" in
        "ok")
            log "INFO" "✅ Актуальность данных: все источники в норме"
            ;;
        "warning")
            log "WARNING" "⚠️ Актуальность данных: обнаружены предупреждения"
            
            # Детальная информация по источникам
            for source in "Ozon" "Wildberries"; do
                STATUS=$(echo "$FRESHNESS_DATA" | jq -r ".sources.\"$source\".status // \"unknown\"")
                AVG_AGE=$(echo "$FRESHNESS_DATA" | jq -r ".sources.\"$source\".avg_age_hours // 0")
                HOURS_SINCE_SYNC=$(echo "$FRESHNESS_DATA" | jq -r ".sources.\"$source\".hours_since_sync // 0")
                
                if [ "$STATUS" = "warning" ]; then
                    log "WARNING" "$source: данные устарели (средний возраст: ${AVG_AGE}ч, последняя синхронизация: ${HOURS_SINCE_SYNC}ч назад)"
                fi
            done
            ;;
        "critical")
            log "CRITICAL" "❌ Актуальность данных: критические проблемы"
            send_alert "CRITICAL" "Критически устаревшие данные синхронизации"
            
            # Детальная информация по источникам
            for source in "Ozon" "Wildberries"; do
                STATUS=$(echo "$FRESHNESS_DATA" | jq -r ".sources.\"$source\".status // \"unknown\"")
                AVG_AGE=$(echo "$FRESHNESS_DATA" | jq -r ".sources.\"$source\".avg_age_hours // 0")
                HOURS_SINCE_SYNC=$(echo "$FRESHNESS_DATA" | jq -r ".sources.\"$source\".hours_since_sync // 0")
                
                if [ "$STATUS" = "critical" ]; then
                    log "CRITICAL" "$source: критически устаревшие данные (средний возраст: ${AVG_AGE}ч, последняя синхронизация: ${HOURS_SINCE_SYNC}ч назад)"
                    send_alert "CRITICAL" "$source: данные не обновлялись ${HOURS_SINCE_SYNC} часов"
                fi
            done
            ;;
        "error")
            log "ERROR" "Ошибка при проверке актуальности данных"
            send_alert "ERROR" "Не удалось проверить актуальность данных синхронизации"
            ;;
    esac
    
    # Общая статистика
    TOTAL_RECORDS=$(echo "$FRESHNESS_DATA" | jq -r '.overall_stats.total_inventory_records // 0')
    FRESH_RECORDS=$(echo "$FRESHNESS_DATA" | jq -r '.overall_stats.fresh_records // 0')
    FRESHNESS_PCT=$(echo "$FRESHNESS_DATA" | jq -r '.overall_stats.freshness_percentage // 0')
    
    log "INFO" "Общая статистика: $TOTAL_RECORDS записей, $FRESH_RECORDS актуальных (${FRESHNESS_PCT}%)"
    
else
    # Простая проверка без jq
    if echo "$FRESHNESS_DATA" | grep -q '"overall_status": "critical"'; then
        log "CRITICAL" "❌ Обнаружены критически устаревшие данные"
        send_alert "CRITICAL" "Критически устаревшие данные синхронизации"
    elif echo "$FRESHNESS_DATA" | grep -q '"overall_status": "warning"'; then
        log "WARNING" "⚠️ Обнаружены устаревшие данные"
    elif echo "$FRESHNESS_DATA" | grep -q '"overall_status": "ok"'; then
        log "INFO" "✅ Актуальность данных: все в норме"
    else
        log "ERROR" "Неизвестный статус актуальности данных"
    fi
fi

# Сохранение детального отчета
REPORT_FILE="$LOG_DIR/freshness_report_$(date +%Y%m%d_%H%M%S).json"
echo "$FRESHNESS_DATA" > "$REPORT_FILE"
log "INFO" "Детальный отчет сохранен: $REPORT_FILE"

# Очистка старых отчетов (старше 7 дней)
find "$LOG_DIR" -name "freshness_report_*.json" -mtime +7 -delete 2>/dev/null

log "INFO" "=== ПРОВЕРКА АКТУАЛЬНОСТИ ЗАВЕРШЕНА ==="

# Возврат кода в зависимости от статуса
case "$OVERALL_STATUS" in
    "ok") exit 0 ;;
    "warning") exit 1 ;;
    "critical") exit 2 ;;
    *) exit 3 ;;
esac