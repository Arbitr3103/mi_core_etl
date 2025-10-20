#!/bin/bash

# Скрипт для ежедневного обновления автомобильных данных из BaseBuy API
# Запускается через cron в 4:00 утра каждый день

# Настройки
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$SCRIPT_DIR"
LOG_DIR="$PROJECT_DIR/logs"
VENV_DIR="$PROJECT_DIR/venv"
PYTHON_SCRIPT="$PROJECT_DIR/importers/car_data_updater.py"

# Создаем директорию для логов если не существует
mkdir -p "$LOG_DIR"

# Имя лог-файла с датой
LOG_FILE="$LOG_DIR/car_update_$(date +%Y%m%d_%H%M%S).log"

# Функция логирования
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

log "🚀 Запуск ежедневного обновления автомобильных данных"
log "Директория проекта: $PROJECT_DIR"
log "Лог-файл: $LOG_FILE"

# Переходим в директорию проекта
cd "$PROJECT_DIR" || {
    log "❌ ОШИБКА: Не удалось перейти в директорию проекта: $PROJECT_DIR"
    exit 1
}

# Проверяем существование виртуального окружения
if [ -d "$VENV_DIR" ]; then
    log "🐍 Активируем виртуальное окружение: $VENV_DIR"
    source "$VENV_DIR/bin/activate" || {
        log "❌ ОШИБКА: Не удалось активировать виртуальное окружение"
        exit 1
    }
else
    log "⚠️ Виртуальное окружение не найдено, используем системный Python"
fi

# Проверяем существование скрипта
if [ ! -f "$PYTHON_SCRIPT" ]; then
    log "❌ ОШИБКА: Скрипт не найден: $PYTHON_SCRIPT"
    exit 1
fi

log "📋 Проверяем зависимости Python..."

# Проверяем наличие необходимых пакетов
python3 -c "
import sys
required_packages = ['requests', 'mysql.connector', 'bs4', 'dotenv']
missing_packages = []

for package in required_packages:
    try:
        if package == 'mysql.connector':
            import mysql.connector
        elif package == 'bs4':
            import bs4
        elif package == 'dotenv':
            import dotenv
        else:
            __import__(package)
        print(f'✅ {package}')
    except ImportError:
        missing_packages.append(package)
        print(f'❌ {package}')

if missing_packages:
    print(f'Отсутствующие пакеты: {missing_packages}')
    sys.exit(1)
else:
    print('Все зависимости установлены')
" 2>&1 | tee -a "$LOG_FILE"

# Проверяем код возврата проверки зависимостей
if [ ${PIPESTATUS[0]} -ne 0 ]; then
    log "❌ ОШИБКА: Не все зависимости установлены"
    log "Попытка установки недостающих пакетов..."
    
    pip3 install mysql-connector-python beautifulsoup4 python-dotenv requests 2>&1 | tee -a "$LOG_FILE"
    
    if [ ${PIPESTATUS[0]} -ne 0 ]; then
        log "❌ ОШИБКА: Не удалось установить зависимости"
        exit 1
    fi
fi

# Проверяем наличие .env файла
if [ ! -f "$PROJECT_DIR/.env" ]; then
    log "⚠️ ПРЕДУПРЕЖДЕНИЕ: Файл .env не найден"
    log "Убедитесь, что настроены переменные окружения для подключения к БД"
fi

log "🔄 Запускаем скрипт обновления автомобильных данных..."

# Запускаем основной скрипт
python3 "$PYTHON_SCRIPT" 2>&1 | tee -a "$LOG_FILE"

# Получаем код возврата
EXIT_CODE=${PIPESTATUS[0]}

if [ $EXIT_CODE -eq 0 ]; then
    log "✅ Обновление автомобильных данных завершено успешно"
else
    log "❌ Обновление завершилось с ошибкой (код: $EXIT_CODE)"
fi

# Деактивируем виртуальное окружение если оно было активировано
if [ -n "$VIRTUAL_ENV" ]; then
    deactivate
    log "🐍 Виртуальное окружение деактивировано"
fi

log "📊 Размер лог-файла: $(du -h "$LOG_FILE" | cut -f1)"
log "🏁 Скрипт завершен с кодом: $EXIT_CODE"

# Очистка старых логов (старше 30 дней)
find "$LOG_DIR" -name "car_update_*.log" -mtime +30 -delete 2>/dev/null || true

exit $EXIT_CODE
