# Dockerfile для системы пополнения склада
FROM python:3.11-slim

# Метаданные
LABEL maintainer="Inventory Replenishment Team"
LABEL description="Intelligent inventory replenishment system"
LABEL version="1.0.0"

# Установка системных зависимостей
RUN apt-get update && apt-get install -y \
    gcc \
    default-libmysqlclient-dev \
    pkg-config \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Создание пользователя приложения
RUN useradd --create-home --shell /bin/bash replenishment
WORKDIR /home/replenishment

# Копирование файлов зависимостей
COPY requirements.txt .

# Установка Python зависимостей
RUN pip install --no-cache-dir -r requirements.txt

# Копирование исходного кода
COPY --chown=replenishment:replenishment . .

# Создание директорий для логов и отчетов
RUN mkdir -p logs reports && \
    chown -R replenishment:replenishment logs reports

# Переключение на пользователя приложения
USER replenishment

# Переменные окружения
ENV PYTHONPATH=/home/replenishment
ENV PYTHONUNBUFFERED=1

# Порт для API
EXPOSE 8000

# Проверка здоровья контейнера
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD python3 -c "import requests; requests.get('http://localhost:8000/api/health')" || exit 1

# Команда по умолчанию - запуск API сервера
CMD ["python3", "simple_api_server.py"]