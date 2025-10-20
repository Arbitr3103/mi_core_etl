#!/bin/bash

# ===================================================================
# Скрипт автоматического обновления движений товаров
# 
# Описание: Запускает импорт движений товаров с маркетплейсов Ozon и Wildberries
# Частота: Рекомендуется запускать каждые 1-2 часа
# Cron: 0 */2 * * * /path/to/mi_core_etl/run_movements_update.sh
# 
# Автор: ETL System
# Дата: 20 сентября 2025
# ===================================================================

# Настройки
PROJECT_DIR="/home/vladimir/mi_core_etl"
PYTHON_PATH="$PROJECT_DIR/venv/bin/python3"
LOG_DIR="$PROJECT_DIR/logs"
LOG_FILE="$LOG_DIR/movements_update_$(date +%Y-%m-%d).log"
LOCK_FILE="$PROJECT_DIR/movements_update.lock"

# Параметры по умолчанию
HOURS_BACK=${1:-2}  # Количество часов назад (по умолчанию 2)

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
        log_message "❌ Процесс обновления движений уже запущен (PID: $PID)"
        exit 1
    else
        log_message "⚠️ Найден устаревший lock файл, удаляем"
        rm -f "$LOCK_FILE"
    fi
fi

# Создаем lock файл
echo $$ > "$LOCK_FILE"

# Начало работы
log_message "🚀 Запуск обновления движений товаров (за последние $HOURS_BACK часов)"
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
MOVEMENTS_EXIT_CODE=0

# Запуск импорта движений
run_task "Импорт движений товаров" "$PYTHON_PATH importers/movement_importer.py --hours $HOURS_BACK"
MOVEMENTS_EXIT_CODE=$?

# Проверяем результат
if [ $MOVEMENTS_EXIT_CODE -eq 0 ]; then
    log_message "✅ Обновление движений завершено успешно"
    
    # Дополнительная статистика
    log_message "📊 Получение статистики движений..."
    run_task "Статистика движений" "$PYTHON_PATH -c \"
import sys
sys.path.append('importers')
from ozon_importer import connect_to_db

try:
    connection = connect_to_db()
    cursor = connection.cursor(dictionary=True)
    
    # Статистика за последние часы
    cursor.execute('''
        SELECT 
            source,
            movement_type,
            COUNT(*) as count,
            SUM(ABS(quantity)) as total_quantity
        FROM stock_movements 
        WHERE movement_date >= DATE_SUB(NOW(), INTERVAL $HOURS_BACK HOUR)
        GROUP BY source, movement_type
        ORDER BY source, movement_type
    '''.replace('$HOURS_BACK', str($HOURS_BACK)))
    
    stats = cursor.fetchall()
    print(f'📊 СТАТИСТИКА ДВИЖЕНИЙ ЗА ПОСЛЕДНИЕ $HOURS_BACK ЧАСОВ:')
    
    current_source = None
    for stat in stats:
        if stat['source'] != current_source:
            current_source = stat['source']
            print(f'{current_source}:')
        print(f'  {stat[\\\"movement_type\\\"]}: {stat[\\\"count\\\"]} операций, {stat[\\\"total_quantity\\\"]} единиц')
    
    # Общая статистика
    cursor.execute('''
        SELECT 
            COUNT(*) as total_movements,
            COUNT(DISTINCT product_id) as unique_products
        FROM stock_movements
        WHERE movement_date >= DATE_SUB(NOW(), INTERVAL $HOURS_BACK HOUR)
    '''.replace('$HOURS_BACK', str($HOURS_BACK)))
    
    total_stats = cursor.fetchone()
    print(f'Всего новых движений: {total_stats[\\\"total_movements\\\"]}')
    print(f'Затронуто товаров: {total_stats[\\\"unique_products\\\"]}')
    
    cursor.close()
    connection.close()
    
except Exception as e:
    print(f'Ошибка получения статистики: {e}')
\""
    
else
    log_message "❌ Обновление движений завершилось с ошибками (код: $MOVEMENTS_EXIT_CODE)"
fi

# Проверка на критические проблемы
if [ $MOVEMENTS_EXIT_CODE -ne 0 ]; then
    log_message "🚨 Обнаружены критические ошибки, проверяем подключение к API..."
    
    # Простая проверка доступности API
    if ! curl -s --connect-timeout 10 "https://api-seller.ozon.ru" > /dev/null; then
        log_message "⚠️ API Ozon недоступен"
    fi
    
    if ! curl -s --connect-timeout 10 "https://suppliers-api.wildberries.ru" > /dev/null; then
        log_message "⚠️ API Wildberries недоступен"
    fi
fi

# Очистка старых логов (старше 7 дней для движений, так как они запускаются чаще)
log_message "🗂️ Архивирование старых логов..."
find "$LOG_DIR" -name "movements_update_*.log" -mtime +7 -delete 2>/dev/null || true

# Очистка lock файла
rm -f "$LOCK_FILE"

# Итоговое сообщение
TOTAL_TIME=$(($(date +%s) - $(stat -c %Y "$LOCK_FILE" 2>/dev/null || echo $(date +%s))))
log_message "⏱️ Время выполнения: ${TOTAL_TIME} секунд"

if [ $MOVEMENTS_EXIT_CODE -eq 0 ]; then
    log_message "🎉 Скрипт обновления движений завершен успешно"
    exit 0
else
    log_message "💥 Скрипт обновления движений завершен с ошибками"
    exit 1
fi
