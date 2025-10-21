#!/bin/bash
"""
Weekly Full Inventory Resynchronization Script
Скрипт для еженедельной полной пересинхронизации остатков

Использование:
  ./run_weekly_inventory_resync.sh

Для добавления в crontab:
  # Каждое воскресенье в 02:00
  0 2 * * 0 /path/to/run_weekly_inventory_resync.sh

Автор: Inventory Sync System
Версия: 1.0
"""

# Настройки
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_SCRIPT="$SCRIPT_DIR/inventory_sync_service_with_error_handling.py"
LOG_DIR="$SCRIPT_DIR/logs"
LOCK_FILE="$SCRIPT_DIR/locks/weekly_inventory_resync.lock"
PID_FILE="$SCRIPT_DIR/pids/weekly_inventory_resync.pid"

# Функция логирования
log() {
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    local log_file="$LOG_DIR/cron_weekly_resync.log"
    echo "[$timestamp] $1" | tee -a "$log_file"
}

# Функция очистки при завершении
cleanup() {
    log "Очистка временных файлов еженедельной пересинхронизации"
    rm -f "$LOCK_FILE" "$PID_FILE"
}

# Обработчик сигналов
trap cleanup EXIT INT TERM

# Создание необходимых директорий
mkdir -p "$LOG_DIR" "$(dirname "$LOCK_FILE")" "$(dirname "$PID_FILE")"

# Проверка блокировки
if [ -f "$LOCK_FILE" ]; then
    LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
        log "ПРЕДУПРЕЖДЕНИЕ: Еженедельная пересинхронизация уже выполняется (PID: $LOCK_PID)"
        exit 1
    else
        log "Найден устаревший lock файл, удаляем..."
        rm -f "$LOCK_FILE"
    fi
fi

# Создание lock и PID файлов
echo $$ > "$LOCK_FILE"
echo $$ > "$PID_FILE"

log "=== НАЧАЛО ЕЖЕНЕДЕЛЬНОЙ ПОЛНОЙ ПЕРЕСИНХРОНИЗАЦИИ ==="
log "PID процесса: $$"
log "Дата запуска: $(date)"

# Проверка Python окружения
PYTHON_CMD=""
if command -v python3 &> /dev/null; then
    PYTHON_CMD="python3"
elif command -v python &> /dev/null; then
    PYTHON_CMD="python"
else
    log "ОШИБКА: Python не найден в системе"
    exit 1
fi

# Проверка существования Python скрипта
if [ ! -f "$PYTHON_SCRIPT" ]; then
    log "ОШИБКА: Python скрипт не найден: $PYTHON_SCRIPT"
    exit 1
fi

# Установка переменных окружения
export PYTHONPATH="$SCRIPT_DIR:$PYTHONPATH"
export PYTHONIOENCODING=utf-8

# Функция выполнения полной пересинхронизации
run_full_resync() {
    local source=$1
    log "Запуск полной пересинхронизации для источника: $source"
    
    START_TIME=$(date +%s)
    
    cd "$SCRIPT_DIR"
    
    # Создаем временный Python скрипт для полной пересинхронизации
    TEMP_SCRIPT=$(mktemp)
    cat > "$TEMP_SCRIPT" << EOF
#!/usr/bin/env python3
import sys
import os
sys.path.append('$SCRIPT_DIR')

from inventory_sync_service_with_error_handling import RobustInventorySyncService

def main():
    service = RobustInventorySyncService()
    try:
        service.connect_to_database()
        
        # Выполняем полную пересинхронизацию
        log_msg = f"Начинаем полную пересинхронизацию для источника: $source"
        print(log_msg)
        
        # Принудительная полная пересинхронизация за последние 7 дней
        result = service.force_full_resync('$source', days_back=7)
        
        print(f"Полная пересинхронизация $source завершена:")
        print(f"  Статус: {result.get('status', 'unknown')}")
        print(f"  Очищено записей: {result.get('cleared_records', 0)}")
        print(f"  Обработано: {result.get('processed_records', 0)}")
        print(f"  Вставлено: {result.get('inserted_records', 0)}")
        print(f"  Ошибок: {result.get('failed_records', 0)}")
        
        if result.get('error_message'):
            print(f"  Ошибка: {result['error_message']}")
            return 1
            
        return 0 if result.get('status') == 'success' else 1
        
    except Exception as e:
        print(f"Критическая ошибка полной пересинхронизации: {e}")
        return 1
    finally:
        service.close_database_connection()

if __name__ == "__main__":
    sys.exit(main())
EOF

    # Запуск пересинхронизации
    $PYTHON_CMD "$TEMP_SCRIPT" 2>&1 | tee -a "$LOG_DIR/cron_weekly_resync.log"
    RESYNC_EXIT_CODE=$?
    
    # Удаление временного скрипта
    rm -f "$TEMP_SCRIPT"
    
    END_TIME=$(date +%s)
    DURATION=$((END_TIME - START_TIME))
    
    if [ $RESYNC_EXIT_CODE -eq 0 ]; then
        log "✅ Полная пересинхронизация $source завершена успешно (${DURATION}с)"
    else
        log "❌ Полная пересинхронизация $source завершена с ошибкой (код: $RESYNC_EXIT_CODE, ${DURATION}с)"
    fi
    
    return $RESYNC_EXIT_CODE
}

# Выполнение полной пересинхронизации для всех источников
OVERALL_EXIT_CODE=0

log "Начинаем полную пересинхронизацию всех источников..."

# Пересинхронизация Ozon
run_full_resync "Ozon"
OZON_EXIT_CODE=$?

# Пауза между источниками
sleep 60

# Пересинхронизация Wildberries
run_full_resync "Wildberries"
WB_EXIT_CODE=$?

# Анализ общего результата
if [ $OZON_EXIT_CODE -ne 0 ] || [ $WB_EXIT_CODE -ne 0 ]; then
    OVERALL_EXIT_CODE=1
    log "❌ Еженедельная пересинхронизация завершена с ошибками (Ozon: $OZON_EXIT_CODE, WB: $WB_EXIT_CODE)"
else
    log "✅ Еженедельная пересинхронизация всех источников завершена успешно"
fi

# Дополнительные задачи после пересинхронизации
log "Выполнение дополнительных задач после пересинхронизации..."

# Очистка старых данных (старше 90 дней)
CLEANUP_SCRIPT=$(mktemp)
cat > "$CLEANUP_SCRIPT" << 'EOF'
#!/usr/bin/env python3
import sys
import os
sys.path.append(os.path.dirname(__file__))

try:
    from importers.ozon_importer import connect_to_db
    
    connection = connect_to_db()
    cursor = connection.cursor()
    
    # Очистка старых записей остатков (старше 90 дней)
    cursor.execute("""
        DELETE FROM inventory_data 
        WHERE snapshot_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
    """)
    deleted_inventory = cursor.rowcount
    
    # Очистка старых логов синхронизации (старше 90 дней)
    cursor.execute("""
        DELETE FROM sync_logs 
        WHERE started_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    """)
    deleted_logs = cursor.rowcount
    
    connection.commit()
    
    print(f"Очистка завершена:")
    print(f"  Удалено записей остатков: {deleted_inventory}")
    print(f"  Удалено записей логов: {deleted_logs}")
    
    cursor.close()
    connection.close()
    
except Exception as e:
    print(f"Ошибка очистки данных: {e}")
    sys.exit(1)
EOF

$PYTHON_CMD "$CLEANUP_SCRIPT" 2>&1 | tee -a "$LOG_DIR/cron_weekly_resync.log"
CLEANUP_EXIT_CODE=$?
rm -f "$CLEANUP_SCRIPT"

if [ $CLEANUP_EXIT_CODE -eq 0 ]; then
    log "✅ Очистка старых данных завершена успешно"
else
    log "❌ Ошибка при очистке старых данных (код: $CLEANUP_EXIT_CODE)"
fi

# Оптимизация таблиц базы данных
log "Оптимизация таблиц базы данных..."
OPTIMIZE_SCRIPT=$(mktemp)
cat > "$OPTIMIZE_SCRIPT" << 'EOF'
#!/usr/bin/env python3
import sys
import os
sys.path.append(os.path.dirname(__file__))

try:
    from importers.ozon_importer import connect_to_db
    
    connection = connect_to_db()
    cursor = connection.cursor()
    
    tables_to_optimize = ['inventory_data', 'sync_logs']
    
    for table in tables_to_optimize:
        print(f"Оптимизация таблицы {table}...")
        cursor.execute(f"OPTIMIZE TABLE {table}")
        result = cursor.fetchone()
        print(f"  Результат: {result}")
    
    cursor.close()
    connection.close()
    
    print("Оптимизация таблиц завершена")
    
except Exception as e:
    print(f"Ошибка оптимизации таблиц: {e}")
    sys.exit(1)
EOF

$PYTHON_CMD "$OPTIMIZE_SCRIPT" 2>&1 | tee -a "$LOG_DIR/cron_weekly_resync.log"
OPTIMIZE_EXIT_CODE=$?
rm -f "$OPTIMIZE_SCRIPT"

if [ $OPTIMIZE_EXIT_CODE -eq 0 ]; then
    log "✅ Оптимизация таблиц завершена успешно"
else
    log "❌ Ошибка при оптимизации таблиц (код: $OPTIMIZE_EXIT_CODE)"
fi

# Очистка старых логов файловой системы (старше 30 дней)
find "$LOG_DIR" -name "*.log" -type f -mtime +30 -delete 2>/dev/null
log "Очистка старых файлов логов выполнена"

# Генерация еженедельного отчета
log "Генерация еженедельного отчета..."
REPORT_SCRIPT=$(mktemp)
cat > "$REPORT_SCRIPT" << 'EOF'
#!/usr/bin/env python3
import sys
import os
from datetime import datetime, timedelta
sys.path.append(os.path.dirname(__file__))

try:
    from importers.ozon_importer import connect_to_db
    
    connection = connect_to_db()
    cursor = connection.cursor(dictionary=True)
    
    # Статистика за последнюю неделю
    week_ago = datetime.now() - timedelta(days=7)
    
    # Количество синхронизаций за неделю
    cursor.execute("""
        SELECT 
            source,
            COUNT(*) as sync_count,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
            AVG(duration_seconds) as avg_duration
        FROM sync_logs 
        WHERE sync_type = 'inventory' 
        AND started_at >= %s
        GROUP BY source
    """, (week_ago,))
    
    sync_stats = cursor.fetchall()
    
    print("=== ЕЖЕНЕДЕЛЬНЫЙ ОТЧЕТ ПО СИНХРОНИЗАЦИИ ОСТАТКОВ ===")
    print(f"Период: {week_ago.strftime('%Y-%m-%d')} - {datetime.now().strftime('%Y-%m-%d')}")
    print()
    
    for stat in sync_stats:
        success_rate = (stat['success_count'] / stat['sync_count'] * 100) if stat['sync_count'] > 0 else 0
        avg_duration = stat['avg_duration'] or 0
        
        print(f"Источник: {stat['source']}")
        print(f"  Всего синхронизаций: {stat['sync_count']}")
        print(f"  Успешных: {stat['success_count']}")
        print(f"  Процент успеха: {success_rate:.1f}%")
        print(f"  Среднее время: {avg_duration:.1f}с")
        print()
    
    # Текущее состояние остатков
    cursor.execute("""
        SELECT 
            source,
            COUNT(DISTINCT product_id) as unique_products,
            SUM(quantity_present) as total_present,
            SUM(quantity_reserved) as total_reserved,
            MAX(last_sync_at) as last_sync
        FROM inventory_data
        GROUP BY source
    """)
    
    inventory_stats = cursor.fetchall()
    
    print("=== ТЕКУЩЕЕ СОСТОЯНИЕ ОСТАТКОВ ===")
    for stat in inventory_stats:
        print(f"Источник: {stat['source']}")
        print(f"  Уникальных товаров: {stat['unique_products']}")
        print(f"  Общий остаток: {stat['total_present']}")
        print(f"  Зарезервировано: {stat['total_reserved']}")
        print(f"  Последняя синхронизация: {stat['last_sync']}")
        print()
    
    cursor.close()
    connection.close()
    
except Exception as e:
    print(f"Ошибка генерации отчета: {e}")
    sys.exit(1)
EOF

$PYTHON_CMD "$REPORT_SCRIPT" 2>&1 | tee -a "$LOG_DIR/cron_weekly_resync.log"
rm -f "$REPORT_SCRIPT"

# Статистика использования ресурсов
if command -v ps &> /dev/null; then
    log "Статистика процесса:"
    ps -o pid,ppid,user,%cpu,%mem,etime,cmd -p $$ 2>/dev/null | tail -n +2 | while read line; do
        log "  $line"
    done
fi

# Проверка размера логов
LOG_SIZE=$(du -sh "$LOG_DIR" 2>/dev/null | cut -f1)
log "Размер директории логов: ${LOG_SIZE:-'неизвестно'}"

# Отправка уведомления в системный лог
if [ $OVERALL_EXIT_CODE -ne 0 ]; then
    logger -t "inventory_weekly_resync" "Ошибка еженедельной пересинхронизации остатков (код: $OVERALL_EXIT_CODE)"
else
    logger -t "inventory_weekly_resync" "Еженедельная пересинхронизация остатков завершена успешно"
fi

log "=== ЗАВЕРШЕНИЕ ЕЖЕНЕДЕЛЬНОЙ ПОЛНОЙ ПЕРЕСИНХРОНИЗАЦИИ ==="

exit $OVERALL_EXIT_CODE