#!/bin/bash

# ===================================================================
# ЕЖЕНЕДЕЛЬНЫЙ ETL СКРИПТ ДЛЯ CRON JOB
# Запускается каждый понедельник в 3:00
# ===================================================================

# Настройки
PROJECT_DIR="/path/to/mi_core_etl"  # ЗАМЕНИТЕ НА РЕАЛЬНЫЙ ПУТЬ
LOG_DIR="$PROJECT_DIR/logs"
LOG_FILE="$LOG_DIR/weekly_etl_$(date +%Y%m%d_%H%M%S).log"

# Создаем директорию для логов если не существует
mkdir -p "$LOG_DIR"

# Функция логирования
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log "🚀 Запуск еженедельного ETL процесса"
log "📁 Рабочая директория: $PROJECT_DIR"
log "📝 Лог файл: $LOG_FILE"

# Переходим в директорию проекта
cd "$PROJECT_DIR" || {
    log "❌ ОШИБКА: Не удалось перейти в директорию $PROJECT_DIR"
    exit 1
}

# Обновляем код из репозитория
log "📥 Обновление кода из Git репозитория..."
git pull origin main >> "$LOG_FILE" 2>&1
if [ $? -eq 0 ]; then
    log "✅ Код успешно обновлен"
else
    log "⚠️  Предупреждение: Не удалось обновить код из Git"
fi

# Запускаем ETL процесс за последние 7 дней
log "🔄 Запуск ETL процесса Ozon за последние 7 дней..."
python3 main.py --source=ozon --last-7-days >> "$LOG_FILE" 2>&1

# Проверяем результат выполнения Ozon
if [ $? -eq 0 ]; then
    log "✅ ETL процесс Ozon завершен успешно"
else
    log "❌ ОШИБКА: ETL процесс Ozon завершился с ошибкой"
fi

# Запускаем ETL процесс Wildberries за последние 7 дней
log "🔄 Запуск ETL процесса Wildberries за последние 7 дней..."
python3 main.py --source=wb --last-7-days >> "$LOG_FILE" 2>&1

# Проверяем результат выполнения Wildberries
if [ $? -eq 0 ]; then
    log "✅ ETL процесс Wildberries завершен успешно"
else
    log "❌ ОШИБКА: ETL процесс Wildberries завершился с ошибкой"
fi

# Показываем статистику загруженных данных
log "📊 Запуск просмотра статистики..."
python3 view_orders.py >> "$LOG_FILE" 2>&1

log "🎉 Еженедельный ETL процесс полностью завершен"
exit 0
