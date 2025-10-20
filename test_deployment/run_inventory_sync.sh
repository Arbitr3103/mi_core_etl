#!/bin/bash
"""
Cron Script for Inventory Synchronization
Скрипт для автоматической синхронизации остатков товаров

Использование:
  ./run_inventory_sync.sh [ozon|wb|all]

Для добавления в crontab:
  # Каждые 6 часов (в 00:00, 06:00, 12:00, 18:00)
  0 */6 * * * /path/to/run_inventory_sync.sh all
  
  # Отдельно для Ozon каждые 6 часов со смещением
  30 */6 * * * /path/to/run_inventory_sync.sh ozon
  
  # Отдельно для Wildberries каждые 6 часов со смещением
  0 */6 * * * /path/to/run_inventory_sync.sh wb

Автор: Inventory Sync System
Версия: 1.0
"""

# Настройки
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_SCRIPT="$SCRIPT_DIR/inventory_sync_service_with_error_handling.py"
LOG_DIR="$SCRIPT_DIR/logs"
LOCK_DIR="$SCRIPT_DIR/locks"
PID_DIR="$SCRIPT_DIR/pids"

# Параметр источника (ozon, wb, all)
SOURCE="${1:-all}"

# Файлы блокировки и PID для каждого источника
LOCK_FILE="$LOCK_DIR/inventory_sync_${SOURCE}.lock"
PID_FILE="$PID_DIR/inventory_sync_${SOURCE}.pid"

# Функция логирования
log() {
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    local log_file="$LOG_DIR/cron_inventory_sync_${SOURCE}.log"
    echo "[$timestamp] $1" | tee -a "$log_file"
}

# Функция очистки при завершении
cleanup() {
    log "Очистка временных файлов для источника: $SOURCE"
    rm -f "$LOCK_FILE" "$PID_FILE"
}

# Обработчик сигналов
trap cleanup EXIT INT TERM

# Создание необходимых директорий
mkdir -p "$LOG_DIR" "$LOCK_DIR" "$PID_DIR"

# Проверка параметров
if [[ ! "$SOURCE" =~ ^(ozon|wb|all)$ ]]; then
    log "ОШИБКА: Неверный параметр источника: $SOURCE"
    log "Использование: $0 [ozon|wb|all]"
    exit 1
fi

# Проверка блокировки (предотвращение одновременного запуска)
if [ -f "$LOCK_FILE" ]; then
    LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
        log "ПРЕДУПРЕЖДЕНИЕ: Синхронизация $SOURCE уже выполняется (PID: $LOCK_PID)"
        exit 1
    else
        log "Найден устаревший lock файл для $SOURCE, удаляем..."
        rm -f "$LOCK_FILE"
    fi
fi

# Создание lock и PID файлов
echo $$ > "$LOCK_FILE"
echo $$ > "$PID_FILE"

log "=== НАЧАЛО СИНХРОНИЗАЦИИ ОСТАТКОВ: $SOURCE ==="
log "PID процесса: $$"
log "Рабочая директория: $SCRIPT_DIR"

# Проверка существования Python скрипта
if [ ! -f "$PYTHON_SCRIPT" ]; then
    log "ОШИБКА: Python скрипт не найден: $PYTHON_SCRIPT"
    exit 1
fi

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

log "Используется Python: $(which $PYTHON_CMD)"
log "Версия Python: $($PYTHON_CMD --version)"

# Проверка зависимостей Python
log "Проверка зависимостей Python..."
REQUIRED_PACKAGES=("mysql-connector-python" "requests")
MISSING_PACKAGES=()

for package in "${REQUIRED_PACKAGES[@]}"; do
    if ! $PYTHON_CMD -c "import ${package//-/_}" &> /dev/null; then
        MISSING_PACKAGES+=("$package")
    fi
done

if [ ${#MISSING_PACKAGES[@]} -gt 0 ]; then
    log "ОШИБКА: Отсутствуют необходимые Python пакеты: ${MISSING_PACKAGES[*]}"
    log "Установите их командой: pip install ${MISSING_PACKAGES[*]}"
    exit 1
fi

# Проверка конфигурационного файла
CONFIG_FILE="$SCRIPT_DIR/config.py"
if [ ! -f "$CONFIG_FILE" ]; then
    log "ОШИБКА: Конфигурационный файл не найден: $CONFIG_FILE"
    exit 1
fi

# Установка переменных окружения для Python
export PYTHONPATH="$SCRIPT_DIR:$PYTHONPATH"
export PYTHONIOENCODING=utf-8

# Функция запуска синхронизации для конкретного источника
run_sync() {
    local sync_source=$1
    log "Запуск синхронизации для источника: $sync_source"
    
    START_TIME=$(date +%s)
    
    cd "$SCRIPT_DIR"
    
    # Создаем временный Python скрипт для запуска синхронизации
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
        
        if '$sync_source' == 'ozon':
            result = service.sync_ozon_inventory_with_recovery()
        elif '$sync_source' == 'wb':
            result = service.sync_wb_inventory_with_recovery()
        else:
            print(f"Неподдерживаемый источник: $sync_source")
            return 1
            
        print(f"Синхронизация {result.source} завершена:")
        print(f"  Статус: {result.status.value}")
        print(f"  Обработано: {result.records_processed}")
        print(f"  Вставлено: {result.records_inserted}")
        print(f"  Ошибок: {result.records_failed}")
        print(f"  Длительность: {result.duration_seconds}с")
        
        if result.error_message:
            print(f"  Ошибка: {result.error_message}")
            return 1
            
        return 0
        
    except Exception as e:
        print(f"Критическая ошибка: {e}")
        return 1
    finally:
        service.close_database_connection()

if __name__ == "__main__":
    sys.exit(main())
EOF

    # Запуск синхронизации
    $PYTHON_CMD "$TEMP_SCRIPT" 2>&1 | tee -a "$LOG_DIR/cron_inventory_sync_${SOURCE}.log"
    SYNC_EXIT_CODE=$?
    
    # Удаление временного скрипта
    rm -f "$TEMP_SCRIPT"
    
    END_TIME=$(date +%s)
    DURATION=$((END_TIME - START_TIME))
    
    if [ $SYNC_EXIT_CODE -eq 0 ]; then
        log "✅ Синхронизация $sync_source завершена успешно (${DURATION}с)"
    else
        log "❌ Синхронизация $sync_source завершена с ошибкой (код: $SYNC_EXIT_CODE, ${DURATION}с)"
    fi
    
    return $SYNC_EXIT_CODE
}

# Основная логика выполнения
OVERALL_EXIT_CODE=0

case "$SOURCE" in
    "ozon")
        run_sync "ozon"
        OVERALL_EXIT_CODE=$?
        ;;
    "wb")
        run_sync "wb"
        OVERALL_EXIT_CODE=$?
        ;;
    "all")
        log "Запуск синхронизации для всех источников..."
        
        # Синхронизация Ozon
        run_sync "ozon"
        OZON_EXIT_CODE=$?
        
        # Небольшая пауза между синхронизациями
        sleep 30
        
        # Синхронизация Wildberries
        run_sync "wb"
        WB_EXIT_CODE=$?
        
        # Общий результат
        if [ $OZON_EXIT_CODE -ne 0 ] || [ $WB_EXIT_CODE -ne 0 ]; then
            OVERALL_EXIT_CODE=1
            log "❌ Синхронизация завершена с ошибками (Ozon: $OZON_EXIT_CODE, WB: $WB_EXIT_CODE)"
        else
            log "✅ Синхронизация всех источников завершена успешно"
        fi
        ;;
esac

# Очистка старых логов (старше 30 дней)
find "$LOG_DIR" -name "cron_inventory_sync_*.log" -type f -mtime +30 -delete 2>/dev/null
log "Очистка старых логов выполнена"

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

# Отправка уведомления в системный лог при ошибках
if [ $OVERALL_EXIT_CODE -ne 0 ]; then
    logger -t "inventory_sync" "Ошибка синхронизации остатков для источника: $SOURCE (код: $OVERALL_EXIT_CODE)"
fi

log "=== ЗАВЕРШЕНИЕ СИНХРОНИЗАЦИИ ОСТАТКОВ: $SOURCE ==="

# Возврат общего кода завершения
exit $OVERALL_EXIT_CODE