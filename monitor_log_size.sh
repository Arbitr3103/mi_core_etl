#!/bin/bash
"""
Log Size Monitor Script
Скрипт мониторинга размера логов и их автоматической очистки

Использование:
  ./monitor_log_size.sh

Для добавления в crontab:
  # Каждый день в 23:00
  0 23 * * * /path/to/monitor_log_size.sh

Автор: Inventory Monitoring System
Версия: 1.0
"""

# Настройки
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$SCRIPT_DIR/logs"
MONITOR_LOG="$LOG_DIR/log_monitor.log"

# Пороговые значения
MAX_TOTAL_SIZE_MB=500      # Максимальный общий размер логов (MB)
MAX_SINGLE_FILE_MB=50      # Максимальный размер одного файла лога (MB)
CLEANUP_DAYS=30            # Удалять логи старше N дней
ARCHIVE_DAYS=7             # Архивировать логи старше N дней

# Функция логирования
log() {
    local level=$1
    local message=$2
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] [$level] $message" | tee -a "$MONITOR_LOG"
}

# Функция отправки уведомлений
send_alert() {
    local level=$1
    local message=$2
    
    log "ALERT" "[$level] $message"
    logger -t "inventory_log_monitor" "[$level] $message"
}

# Функция форматирования размера
format_size() {
    local size_kb=$1
    if [ "$size_kb" -gt 1048576 ]; then
        echo "$(( size_kb / 1048576 ))GB"
    elif [ "$size_kb" -gt 1024 ]; then
        echo "$(( size_kb / 1024 ))MB"
    else
        echo "${size_kb}KB"
    fi
}

# Создание директории для логов
mkdir -p "$LOG_DIR"

log "INFO" "=== МОНИТОРИНГ РАЗМЕРА ЛОГОВ ==="

# Проверка общего размера директории логов
if [ -d "$LOG_DIR" ]; then
    TOTAL_SIZE_KB=$(du -sk "$LOG_DIR" | cut -f1)
    TOTAL_SIZE_MB=$((TOTAL_SIZE_KB / 1024))
    TOTAL_SIZE_FORMATTED=$(format_size $TOTAL_SIZE_KB)
    
    log "INFO" "Общий размер логов: $TOTAL_SIZE_FORMATTED"
    
    if [ "$TOTAL_SIZE_MB" -gt "$MAX_TOTAL_SIZE_MB" ]; then
        log "WARNING" "Размер логов превышает лимит: ${TOTAL_SIZE_MB}MB > ${MAX_TOTAL_SIZE_MB}MB"
        send_alert "WARNING" "Размер логов превышает лимит: $TOTAL_SIZE_FORMATTED"
    fi
else
    log "ERROR" "Директория логов не найдена: $LOG_DIR"
    exit 1
fi

# Анализ отдельных файлов логов
log "INFO" "Анализ отдельных файлов логов..."

LARGE_FILES=0
TOTAL_FILES=0
ARCHIVED_FILES=0
DELETED_FILES=0

# Создание директории для архивов
ARCHIVE_DIR="$LOG_DIR/archive"
mkdir -p "$ARCHIVE_DIR"

# Обработка файлов логов
find "$LOG_DIR" -name "*.log" -type f | while read -r log_file; do
    if [ -f "$log_file" ]; then
        TOTAL_FILES=$((TOTAL_FILES + 1))
        
        # Получение информации о файле
        FILE_SIZE_KB=$(du -k "$log_file" | cut -f1)
        FILE_SIZE_MB=$((FILE_SIZE_KB / 1024))
        FILE_SIZE_FORMATTED=$(format_size $FILE_SIZE_KB)
        FILE_NAME=$(basename "$log_file")
        FILE_AGE_DAYS=$(find "$log_file" -mtime +0 -printf '%C@\n' 2>/dev/null | head -1)
        
        if [ -z "$FILE_AGE_DAYS" ]; then
            # Альтернативный способ получения возраста файла
            FILE_AGE_DAYS=$(( ($(date +%s) - $(stat -c %Y "$log_file" 2>/dev/null || stat -f %m "$log_file" 2>/dev/null || echo 0)) / 86400 ))
        fi
        
        # Проверка размера файла
        if [ "$FILE_SIZE_MB" -gt "$MAX_SINGLE_FILE_MB" ]; then
            log "WARNING" "Большой файл лога: $FILE_NAME ($FILE_SIZE_FORMATTED)"
            LARGE_FILES=$((LARGE_FILES + 1))
            
            # Архивирование больших файлов
            if command -v gzip &> /dev/null; then
                ARCHIVE_NAME="$ARCHIVE_DIR/${FILE_NAME}_$(date +%Y%m%d_%H%M%S).gz"
                if gzip -c "$log_file" > "$ARCHIVE_NAME" 2>/dev/null; then
                    # Очистка оригинального файла (оставляем пустым для продолжения записи)
                    > "$log_file"
                    log "INFO" "Файл $FILE_NAME заархивирован и очищен: $ARCHIVE_NAME"
                    ARCHIVED_FILES=$((ARCHIVED_FILES + 1))
                else
                    log "ERROR" "Ошибка архивирования файла: $FILE_NAME"
                fi
            fi
        fi
        
        # Архивирование старых файлов
        if [ "$FILE_AGE_DAYS" -gt "$ARCHIVE_DAYS" ] && [ "$FILE_AGE_DAYS" -le "$CLEANUP_DAYS" ]; then
            if command -v gzip &> /dev/null && [ ! -f "$ARCHIVE_DIR/${FILE_NAME}.gz" ]; then
                ARCHIVE_NAME="$ARCHIVE_DIR/${FILE_NAME}_$(date +%Y%m%d).gz"
                if gzip -c "$log_file" > "$ARCHIVE_NAME" 2>/dev/null; then
                    rm -f "$log_file"
                    log "INFO" "Старый файл заархивирован: $FILE_NAME -> $(basename "$ARCHIVE_NAME")"
                    ARCHIVED_FILES=$((ARCHIVED_FILES + 1))
                fi
            fi
        fi
        
        # Удаление очень старых файлов
        if [ "$FILE_AGE_DAYS" -gt "$CLEANUP_DAYS" ]; then
            if rm -f "$log_file" 2>/dev/null; then
                log "INFO" "Удален старый файл лога: $FILE_NAME (возраст: ${FILE_AGE_DAYS} дней)"
                DELETED_FILES=$((DELETED_FILES + 1))
            fi
        fi
    fi
done

# Очистка старых архивов (старше 90 дней)
OLD_ARCHIVES=$(find "$ARCHIVE_DIR" -name "*.gz" -mtime +90 -type f 2>/dev/null | wc -l)
if [ "$OLD_ARCHIVES" -gt 0 ]; then
    find "$ARCHIVE_DIR" -name "*.gz" -mtime +90 -type f -delete 2>/dev/null
    log "INFO" "Удалено старых архивов: $OLD_ARCHIVES"
fi

# Мониторинг специфических типов логов
log "INFO" "Анализ специфических типов логов..."

# Проверка логов синхронизации
SYNC_LOGS=$(find "$LOG_DIR" -name "cron_inventory_sync_*.log" -type f 2>/dev/null | wc -l)
if [ "$SYNC_LOGS" -gt 0 ]; then
    SYNC_SIZE=$(find "$LOG_DIR" -name "cron_inventory_sync_*.log" -type f -exec du -sk {} + 2>/dev/null | awk '{sum+=$1} END {print sum}')
    SYNC_SIZE_FORMATTED=$(format_size ${SYNC_SIZE:-0})
    log "INFO" "Логи синхронизации: $SYNC_LOGS файлов, $SYNC_SIZE_FORMATTED"
fi

# Проверка логов мониторинга
HEALTH_LOGS=$(find "$LOG_DIR" -name "*health*.log" -type f 2>/dev/null | wc -l)
if [ "$HEALTH_LOGS" -gt 0 ]; then
    HEALTH_SIZE=$(find "$LOG_DIR" -name "*health*.log" -type f -exec du -sk {} + 2>/dev/null | awk '{sum+=$1} END {print sum}')
    HEALTH_SIZE_FORMATTED=$(format_size ${HEALTH_SIZE:-0})
    log "INFO" "Логи мониторинга: $HEALTH_LOGS файлов, $HEALTH_SIZE_FORMATTED"
fi

# Проверка логов ошибок
ERROR_LOGS=$(find "$LOG_DIR" -name "*error*.log" -o -name "*alert*.log" -type f 2>/dev/null | wc -l)
if [ "$ERROR_LOGS" -gt 0 ]; then
    ERROR_SIZE=$(find "$LOG_DIR" -name "*error*.log" -o -name "*alert*.log" -type f -exec du -sk {} + 2>/dev/null | awk '{sum+=$1} END {print sum}')
    ERROR_SIZE_FORMATTED=$(format_size ${ERROR_SIZE:-0})
    log "INFO" "Логи ошибок и алертов: $ERROR_LOGS файлов, $ERROR_SIZE_FORMATTED"
fi

# Анализ роста логов за последние 24 часа
log "INFO" "Анализ роста логов за последние 24 часа..."

RECENT_LOGS=$(find "$LOG_DIR" -name "*.log" -newermt "24 hours ago" -type f 2>/dev/null | wc -l)
if [ "$RECENT_LOGS" -gt 0 ]; then
    RECENT_SIZE=$(find "$LOG_DIR" -name "*.log" -newermt "24 hours ago" -type f -exec du -sk {} + 2>/dev/null | awk '{sum+=$1} END {print sum}')
    RECENT_SIZE_FORMATTED=$(format_size ${RECENT_SIZE:-0})
    log "INFO" "Новые логи за 24ч: $RECENT_LOGS файлов, $RECENT_SIZE_FORMATTED"
    
    # Предупреждение о быстром росте
    RECENT_SIZE_MB=$((${RECENT_SIZE:-0} / 1024))
    if [ "$RECENT_SIZE_MB" -gt 100 ]; then
        log "WARNING" "Быстрый рост логов: ${RECENT_SIZE_MB}MB за 24 часа"
        send_alert "WARNING" "Быстрый рост размера логов: $RECENT_SIZE_FORMATTED за 24 часа"
    fi
fi

# Проверка доступного места на диске
DISK_USAGE=$(df "$LOG_DIR" | tail -1 | awk '{print $5}' | sed 's/%//')
DISK_AVAILABLE=$(df -h "$LOG_DIR" | tail -1 | awk '{print $4}')

log "INFO" "Использование диска: ${DISK_USAGE}%, доступно: $DISK_AVAILABLE"

if [ "$DISK_USAGE" -gt 90 ]; then
    log "CRITICAL" "Критически мало места на диске: ${DISK_USAGE}%"
    send_alert "CRITICAL" "Критически мало места на диске: ${DISK_USAGE}%, доступно: $DISK_AVAILABLE"
    
    # Экстренная очистка логов
    log "INFO" "Выполняется экстренная очистка логов..."
    EMERGENCY_CLEANED=$(find "$LOG_DIR" -name "*.log" -mtime +3 -type f -delete -print 2>/dev/null | wc -l)
    log "INFO" "Экстренно удалено файлов: $EMERGENCY_CLEANED"
    
elif [ "$DISK_USAGE" -gt 80 ]; then
    log "WARNING" "Мало места на диске: ${DISK_USAGE}%"
    send_alert "WARNING" "Мало места на диске: ${DISK_USAGE}%, доступно: $DISK_AVAILABLE"
fi

# Создание отчета о состоянии логов
REPORT_FILE="$LOG_DIR/log_size_report_$(date +%Y%m%d).txt"
cat > "$REPORT_FILE" << EOF
=== ОТЧЕТ О СОСТОЯНИИ ЛОГОВ ===
Дата: $(date)

Общая статистика:
- Общий размер логов: $TOTAL_SIZE_FORMATTED
- Всего файлов логов: $TOTAL_FILES
- Больших файлов (>$MAX_SINGLE_FILE_MB MB): $LARGE_FILES
- Заархивировано файлов: $ARCHIVED_FILES
- Удалено файлов: $DELETED_FILES

Использование диска:
- Занято: ${DISK_USAGE}%
- Доступно: $DISK_AVAILABLE

Рост за 24 часа:
- Новых файлов: $RECENT_LOGS
- Размер новых файлов: $RECENT_SIZE_FORMATTED

Настройки очистки:
- Архивирование: файлы старше $ARCHIVE_DAYS дней
- Удаление: файлы старше $CLEANUP_DAYS дней
- Максимальный размер файла: $MAX_SINGLE_FILE_MB MB
- Максимальный общий размер: $MAX_TOTAL_SIZE_MB MB
EOF

log "INFO" "Отчет сохранен: $REPORT_FILE"

# Очистка старых отчетов (старше 30 дней)
find "$LOG_DIR" -name "log_size_report_*.txt" -mtime +30 -delete 2>/dev/null

# Финальная проверка размера после очистки
FINAL_SIZE_KB=$(du -sk "$LOG_DIR" | cut -f1)
FINAL_SIZE_FORMATTED=$(format_size $FINAL_SIZE_KB)
SIZE_REDUCTION_KB=$((TOTAL_SIZE_KB - FINAL_SIZE_KB))
SIZE_REDUCTION_FORMATTED=$(format_size $SIZE_REDUCTION_KB)

if [ "$SIZE_REDUCTION_KB" -gt 0 ]; then
    log "INFO" "Размер после очистки: $FINAL_SIZE_FORMATTED (освобождено: $SIZE_REDUCTION_FORMATTED)"
else
    log "INFO" "Размер после проверки: $FINAL_SIZE_FORMATTED"
fi

# Проверка целостности важных логов
IMPORTANT_LOGS=("cron_inventory_sync_all.log" "health_check.log" "freshness_check.log")
MISSING_LOGS=0

for important_log in "${IMPORTANT_LOGS[@]}"; do
    if [ ! -f "$LOG_DIR/$important_log" ]; then
        log "WARNING" "Отсутствует важный лог: $important_log"
        MISSING_LOGS=$((MISSING_LOGS + 1))
        
        # Создаем пустой файл лога
        touch "$LOG_DIR/$important_log"
        log "INFO" "Создан пустой файл лога: $important_log"
    fi
done

if [ "$MISSING_LOGS" -gt 0 ]; then
    send_alert "WARNING" "Отсутствовали $MISSING_LOGS важных файлов логов"
fi

log "INFO" "=== МОНИТОРИНГ ЛОГОВ ЗАВЕРШЕН ==="

# Возврат кода в зависимости от найденных проблем
if [ "$DISK_USAGE" -gt 90 ]; then
    exit 2  # Критическая проблема
elif [ "$DISK_USAGE" -gt 80 ] || [ "$LARGE_FILES" -gt 5 ]; then
    exit 1  # Предупреждение
else
    exit 0  # Все в порядке
fi