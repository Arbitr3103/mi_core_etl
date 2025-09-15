#!/bin/bash

# ===================================================================
# ЕЖЕНЕДЕЛЬНЫЙ ETL СКРИПТ ДЛЯ CRON JOB
# Запускается каждый понедельник в 3:00
# Исправленная версия для корректной работы с cron
# ===================================================================

# Устанавливаем переменные окружения для правильной работы с кодировками
export PYTHONIOENCODING=utf-8
export LANG=ru_RU.UTF-8
export LC_ALL=ru_RU.UTF-8

# ЯВНО указываем абсолютный путь к папке проекта
PROJECT_DIR="/home/vladimir/mi_core_etl"
LOG_DIR="$PROJECT_DIR/logs"

# Создаем папку для логов, если ее нет
mkdir -p "$LOG_DIR"

# Формируем имя лог-файла с датой
LOG_FILE="$LOG_DIR/etl_run_$(date +%Y-%m-%d).log"

# Переходим в директорию проекта, чтобы все относительные пути работали
cd "$PROJECT_DIR" || exit 1

# --- НАЧАЛО ВЫПОЛНЕНИЯ ---
# Перенаправляем ВЕСЬ вывод (и обычный, и ошибки) в лог-файл
{
    echo "==================================================="
    echo "Запуск еженедельного ETL: $(date)"
    echo "==================================================="

    # Узнаем АБСОЛЮТНЫЕ пути к командам
    GIT_PATH=$(which git)
    PYTHON_PATH="$PROJECT_DIR/venv/bin/python3"
    
    # Проверяем существование Python в виртуальном окружении
    if [ ! -f "$PYTHON_PATH" ]; then
        echo "ПРЕДУПРЕЖДЕНИЕ: Виртуальное окружение не найдено, используем системный Python"
        PYTHON_PATH=$(which python3)
    fi

    echo "Используемые пути:"
    echo "  Git: $GIT_PATH"
    echo "  Python: $PYTHON_PATH"
    echo "  Рабочая директория: $(pwd)"

    # Обновляем код из репозитория
    echo ""
    echo "--- Обновляем код из Git ---"
    $GIT_PATH pull origin main
    GIT_EXIT_CODE=$?
    if [ $GIT_EXIT_CODE -eq 0 ]; then
        echo "✅ Код успешно обновлен из Git"
    else
        echo "⚠️ Предупреждение: Git pull завершился с кодом $GIT_EXIT_CODE"
    fi

    # Активируем виртуальное окружение если существует
    if [ -f "$PROJECT_DIR/venv/bin/activate" ]; then
        echo ""
        echo "--- Активируем виртуальное окружение ---"
        source "$PROJECT_DIR/venv/bin/activate"
        echo "✅ Виртуальное окружение активировано"
    fi

    # Запускаем основной скрипт импорта для Ozon
    echo ""
    echo "--- Запускаем импорт данных Ozon ---"
    $PYTHON_PATH main.py --last-7-days --source=ozon
    OZON_EXIT_CODE=$?
    if [ $OZON_EXIT_CODE -eq 0 ]; then
        echo "✅ ETL процесс Ozon завершен успешно"
    else
        echo "❌ ОШИБКА: ETL процесс Ozon завершился с кодом $OZON_EXIT_CODE"
    fi

    # Запускаем основной скрипт импорта для Wildberries
    echo ""
    echo "--- Запускаем импорт данных Wildberries ---"
    $PYTHON_PATH main.py --last-7-days --source=wb
    WB_EXIT_CODE=$?
    if [ $WB_EXIT_CODE -eq 0 ]; then
        echo "✅ ETL процесс Wildberries завершен успешно"
    else
        echo "❌ ОШИБКА: ETL процесс Wildberries завершился с кодом $WB_EXIT_CODE"
    fi
    
    # Запускаем скрипт агрегации метрик
    echo ""
    echo "--- Запускаем агрегацию ежедневных метрик ---"
    $PYTHON_PATH run_aggregation.py
    AGGREGATION_EXIT_CODE=$?
    if [ $AGGREGATION_EXIT_CODE -eq 0 ]; then
        echo "✅ Агрегация метрик завершена успешно"
    else
        echo "❌ ОШИБКА: Агрегация метрик завершилась с кодом $AGGREGATION_EXIT_CODE"
    fi

    # Показываем статистику загруженных данных
    echo ""
    echo "--- Показываем статистику данных ---"
    $PYTHON_PATH view_orders.py

    echo ""
    echo "==================================================="
    echo "Завершение еженедельного ETL: $(date)"
    echo "Коды выхода:"
    echo "  Git pull: $GIT_EXIT_CODE"
    echo "  Ozon ETL: $OZON_EXIT_CODE"
    echo "  WB ETL: $WB_EXIT_CODE"
    echo "  Агрегация: $AGGREGATION_EXIT_CODE"
    echo "==================================================="

} >> "$LOG_FILE" 2>&1

# Выходим с кодом ошибки если хотя бы один процесс упал
if [ $OZON_EXIT_CODE -ne 0 ] || [ $WB_EXIT_CODE -ne 0 ] || [ $AGGREGATION_EXIT_CODE -ne 0 ]; then
    exit 1
fi

exit 0
