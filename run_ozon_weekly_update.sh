#!/bin/bash
"""
Cron Script for Ozon Analytics Weekly Update
Скрипт для еженедельного обновления аналитических данных Ozon

Использование:
  ./run_ozon_weekly_update.sh

Для добавления в crontab:
  # Каждое воскресенье в 02:00
  0 2 * * 0 /path/to/run_ozon_weekly_update.sh

Автор: Manhattan System
Версия: 1.0
"""

# Настройки
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PYTHON_SCRIPT="$SCRIPT_DIR/ozon_weekly_update.py"
LOG_DIR="$SCRIPT_DIR/logs"
LOCK_FILE="$SCRIPT_DIR/ozon_update.lock"
PID_FILE="$SCRIPT_DIR/ozon_update.pid"

# Функция логирования
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_DIR/cron_ozon_update.log"
}

# Функция очистки при завершении
cleanup() {
    log "Очистка временных файлов..."
    rm -f "$LOCK_FILE" "$PID_FILE"
}

# Обработчик сигналов
trap cleanup EXIT INT TERM

# Создание директории для логов
mkdir -p "$LOG_DIR"

# Проверка блокировки (предотвращение одновременного запуска)
if [ -f "$LOCK_FILE" ]; then
    LOCK_PID=$(cat "$LOCK_FILE" 2>/dev/null)
    if [ -n "$LOCK_PID" ] && kill -0 "$LOCK_PID" 2>/dev/null; then
        log "ПРЕДУПРЕЖДЕНИЕ: Обновление уже выполняется (PID: $LOCK_PID)"
        exit 1
    else
        log "Найден устаревший lock файл, удаляем..."
        rm -f "$LOCK_FILE"
    fi
fi

# Создание lock файла
echo $$ > "$LOCK_FILE"
echo $$ > "$PID_FILE"

log "=== НАЧАЛО ЕЖЕНЕДЕЛЬНОГО ОБНОВЛЕНИЯ OZON ANALYTICS ==="
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
    log "ПРЕДУПРЕЖДЕНИЕ: Конфигурационный файл не найден: $CONFIG_FILE"
    log "Будет использована конфигурация по умолчанию"
fi

# Установка переменных окружения для Python
export PYTHONPATH="$SCRIPT_DIR:$PYTHONPATH"
export PYTHONIOENCODING=utf-8

# Запуск Python скрипта обновления
log "Запуск скрипта обновления..."
START_TIME=$(date +%s)

cd "$SCRIPT_DIR"
$PYTHON_CMD "$PYTHON_SCRIPT" 2>&1 | tee -a "$LOG_DIR/cron_ozon_update.log"
PYTHON_EXIT_CODE=$?

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

# Анализ результата
if [ $PYTHON_EXIT_CODE -eq 0 ]; then
    log "✅ ОБНОВЛЕНИЕ ЗАВЕРШЕНО УСПЕШНО"
    log "Продолжительность: ${DURATION} секунд"
    
    # Очистка старых логов (старше 30 дней)
    find "$LOG_DIR" -name "*.log" -type f -mtime +30 -delete 2>/dev/null
    log "Очистка старых логов выполнена"
    
else
    log "❌ ОБНОВЛЕНИЕ ЗАВЕРШЕНО С ОШИБКОЙ (код: $PYTHON_EXIT_CODE)"
    log "Продолжительность: ${DURATION} секунд"
    
    # Отправка дополнительного уведомления через системный лог
    logger -t "ozon_update" "Ошибка еженедельного обновления Ozon Analytics (код: $PYTHON_EXIT_CODE)"
fi

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

log "=== ЗАВЕРШЕНИЕ ЕЖЕНЕДЕЛЬНОГО ОБНОВЛЕНИЯ OZON ANALYTICS ==="

# Возврат кода завершения Python скрипта
exit $PYTHON_EXIT_CODE