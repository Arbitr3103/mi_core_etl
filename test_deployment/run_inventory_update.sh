#!/bin/bash

# ===================================================================
# Скрипт автоматического обновления остатков товаров
# 
# Описание: Запускает импорт остатков с маркетплейсов Ozon и Wildberries
# Частота: Рекомендуется запускать 1 раз в сутки (например, в 03:00)
# Cron: 0 3 * * * /path/to/mi_core_etl/run_inventory_update.sh
# 
# Автор: ETL System
# Дата: 20 сентября 2025
# ===================================================================

# Настройки
PROJECT_DIR="/home/vladimir/mi_core_etl"
PYTHON_PATH="$PROJECT_DIR/venv/bin/python3"
LOG_DIR="$PROJECT_DIR/logs"
LOG_FILE="$LOG_DIR/inventory_update_$(date +%Y-%m-%d).log"
LOCK_FILE="$PROJECT_DIR/inventory_update.lock"

# Функция логирования
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

# Функция для безопасного завершения
cleanup() {
    log_message "Получен сигнал завершения, очищаем lock файл"
    rm -f "$LOCK_FILE"
    exit 1
}

# Обработка сигналов
trap cleanup SIGINT SIGTERM

# Проверка блокировки
if [ -f "$LOCK_FILE" ]; then
    PID=$(cat "$LOCK_FILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        log_message "❌ Процесс обновления остатков уже запущен (PID: $PID)"
        exit 1
    else
        log_message "⚠️ Найден устаревший lock файл, удаляем"
        rm -f "$LOCK_FILE"
    fi
fi

# Создаем lock файл
echo $$ > "$LOCK_FILE"

# Начало работы
log_message "🚀 Запуск обновления остатков товаров"
log_message "Проект: $PROJECT_DIR"
log_message "Python: $PYTHON_PATH"
log_message "Лог файл: $LOG_FILE"

# Проверяем существование директорий
if [ ! -d "$PROJECT_DIR" ]; then
    log_message "❌ Директория проекта не найдена: $PROJECT_DIR"
    rm -f "$LOCK_FILE"
    exit 1
fi

if [ ! -f "$PYTHON_PATH" ]; then
    log_message "❌ Python не найден: $PYTHON_PATH"
    rm -f "$LOCK_FILE"
    exit 1
fi

# Создаем директорию для логов
mkdir -p "$LOG_DIR"

# Переходим в директорию проекта
cd "$PROJECT_DIR" || {
    log_message "❌ Не удалось перейти в директорию проекта"
    rm -f "$LOCK_FILE"
    exit 1
}

# Настройка окружения
export PYTHONPATH="$PROJECT_DIR:$PYTHONPATH"
export PYTHONIOENCODING=utf-8
export LANG=ru_RU.UTF-8
export LC_ALL=ru_RU.UTF-8

# Функция выполнения команды с логированием
run_task() {
    local task_name="$1"
    local command="$2"
    
    log_message "▶️ Начинаем: $task_name"
    
    if eval "$command" >> "$LOG_FILE" 2>&1; then
        log_message "✅ Завершено: $task_name"
        return 0
    else
        local exit_code=$?
        log_message "❌ Ошибка в задаче: $task_name (код: $exit_code)"
        return $exit_code
    fi
}

# Основная логика
INVENTORY_EXIT_CODE=0

# Запуск импорта остатков
run_task "Импорт остатков товаров" "$PYTHON_PATH importers/stock_importer.py"
INVENTORY_EXIT_CODE=$?

# Проверяем результат
if [ $INVENTORY_EXIT_CODE -eq 0 ]; then
    log_message "✅ Обновление остатков завершено успешно"
    
    # Дополнительная статистика
    log_message "📊 Получение статистики остатков..."
    run_task "Статистика остатков" "$PYTHON_PATH -c \"
import sys
sys.path.append('importers')
from ozon_importer import connect_to_db

try:
    connection = connect_to_db()
    cursor = connection.cursor(dictionary=True)
    
    cursor.execute('''
        SELECT 
            source,
            COUNT(*) as total_records,
            COUNT(DISTINCT product_id) as unique_products,
            SUM(quantity_present) as total_present,
            SUM(quantity_reserved) as total_reserved
        FROM inventory 
        GROUP BY source
    ''')
    
    stats = cursor.fetchall()
    print('📊 СТАТИСТИКА ОСТАТКОВ:')
    for stat in stats:
        print(f'{stat[\\\"source\\\"]}: {stat[\\\"total_records\\\"]} записей, {stat[\\\"unique_products\\\"]} товаров, доступно: {stat[\\\"total_present\\\"]}')
    
    cursor.close()
    connection.close()
    
except Exception as e:
    print(f'Ошибка получения статистики: {e}')
\""
    
else
    log_message "❌ Обновление остатков завершилось с ошибками (код: $INVENTORY_EXIT_CODE)"
fi

# Архивирование старых логов (старше 30 дней)
log_message "🗂️ Архивирование старых логов..."
find "$LOG_DIR" -name "inventory_update_*.log" -mtime +30 -delete 2>/dev/null || true

# Очистка lock файла
rm -f "$LOCK_FILE"

# Итоговое сообщение
TOTAL_TIME=$(($(date +%s) - $(stat -c %Y "$LOCK_FILE" 2>/dev/null || echo $(date +%s))))
log_message "⏱️ Общее время выполнения: ${TOTAL_TIME} секунд"

if [ $INVENTORY_EXIT_CODE -eq 0 ]; then
    log_message "🎉 Скрипт обновления остатков завершен успешно"
    exit 0
else
    log_message "💥 Скрипт обновления остатков завершен с ошибками"
    exit 1
fi
