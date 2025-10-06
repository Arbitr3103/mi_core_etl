# Руководство по использованию системы логирования синхронизации

## Обзор

Система логирования синхронизации состоит из двух основных компонентов:

1. **SyncLogger** - класс для структурированного логирования процессов синхронизации
2. **EnhancedInventorySyncService** - улучшенный сервис синхронизации с интегрированным логированием

## Основные возможности

### SyncLogger

- ✅ Запись результатов синхронизации в таблицу `sync_logs`
- ✅ Логирование времени выполнения и количества обработанных записей
- ✅ Запись ошибок и предупреждений
- ✅ Детальное логирование каждого этапа синхронизации
- ✅ Мониторинг использования памяти и производительности
- ✅ Генерация отчетов о состоянии системы

### EnhancedInventorySyncService

- ✅ Интеграция SyncLogger в процесс синхронизации
- ✅ Логирование API запросов с временем ответа
- ✅ Поэтапное логирование обработки данных
- ✅ Автоматическое определение статуса синхронизации
- ✅ Структурированная запись статистики в БД

## Использование

### Базовое использование SyncLogger

```python
from sync_logger import SyncLogger, SyncType, SyncStatus

# Инициализация (требуется подключение к БД)
sync_logger = SyncLogger(cursor, connection, "MyService")

# Начало сессии синхронизации
sync_entry = sync_logger.start_sync_session(SyncType.INVENTORY, "Ozon")

# Логирование этапов обработки
sync_logger.log_processing_stage(
    stage_name="API Data Fetch",
    records_input=0,
    records_output=150,
    processing_time=2.5,
    memory_usage_mb=64.0
)

# Логирование API запросов
sync_logger.log_api_request(
    endpoint="/api/stocks",
    response_time=1.2,
    status_code=200,
    records_received=150
)

# Логирование предупреждений и ошибок
sync_logger.log_warning("5 записей пропущено из-за отсутствия product_id")
sync_logger.log_error("Ошибка валидации данных", exception)

# Обновление счетчиков
sync_logger.update_sync_counters(
    records_processed=150,
    records_inserted=145,
    records_failed=5
)

# Завершение сессии
sync_log_id = sync_logger.end_sync_session(status=SyncStatus.SUCCESS)
```

### Использование EnhancedInventorySyncService

```python
from inventory_sync_service_enhanced import EnhancedInventorySyncService

# Создание сервиса
service = EnhancedInventorySyncService()

try:
    # Подключение к БД (автоматически инициализирует SyncLogger)
    service.connect_to_database()

    # Запуск синхронизации с автоматическим логированием
    results = service.run_full_sync()

    # Результаты содержат детальную статистику
    for source, result in results.items():
        print(f"{source}: {result.status.value} - {result.records_inserted} записей")

finally:
    service.close_database_connection()
```

## Структура данных в БД

### Таблица sync_logs

```sql
CREATE TABLE sync_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sync_type ENUM('inventory', 'orders', 'transactions') NOT NULL,
    source ENUM('Ozon', 'Wildberries', 'Manual') NOT NULL,
    status ENUM('success', 'partial', 'failed') NOT NULL,
    records_processed INT DEFAULT 0,
    records_updated INT DEFAULT 0,
    records_inserted INT DEFAULT 0,
    records_failed INT DEFAULT 0,
    started_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP,
    duration_seconds INT,
    api_requests_count INT DEFAULT 0,
    error_message TEXT,
    warning_message TEXT,
    INDEX idx_sync_type_status (sync_type, status),
    INDEX idx_source_date (source, started_at)
);
```

### Таблица sync_processing_stages (создается автоматически)

```sql
CREATE TABLE sync_processing_stages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sync_log_id INT NOT NULL,
    stage_name VARCHAR(255) NOT NULL,
    records_input INT DEFAULT 0,
    records_output INT DEFAULT 0,
    records_skipped INT DEFAULT 0,
    processing_time_seconds DECIMAL(10,3) DEFAULT 0,
    memory_usage_mb DECIMAL(10,2) NULL,
    error_count INT DEFAULT 0,
    warning_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sync_log_id) REFERENCES sync_logs(id) ON DELETE CASCADE
);
```

## Мониторинг и отчеты

### Получение отчета о состоянии системы

```python
# Получение отчета о состоянии за последние 24 часа
health_report = sync_logger.get_sync_health_report()

print(f"Общее состояние: {health_report['overall_health']}")
for source, data in health_report['sources'].items():
    print(f"{source}: {data['health_status']} - {data['success_count']} успешных синхронизаций")
```

### Получение последних логов

```python
# Получение последних 10 записей синхронизации для Ozon
recent_logs = sync_logger.get_recent_sync_logs(source="Ozon", limit=10)

for log in recent_logs:
    print(f"{log['started_at']}: {log['status']} - {log['records_processed']} записей")
```

## Типы логируемых этапов

1. **API Preparation** - подготовка к API запросам
2. **API Data Retrieval** - получение данных с API
3. **Process Batch N** - обработка батча данных
4. **Data Validation** - валидация полученных данных
5. **Database Update** - сохранение данных в БД

## Статусы синхронизации

- **SUCCESS** - синхронизация завершена успешно без ошибок
- **PARTIAL** - синхронизация завершена с предупреждениями или частичными ошибками
- **FAILED** - синхронизация завершена с критическими ошибками

## Мониторинг производительности

Система автоматически отслеживает:

- Время выполнения каждого этапа
- Использование памяти
- Количество API запросов
- Скорость обработки данных
- Процент успешных операций

## Интеграция с существующим кодом

Для интеграции SyncLogger в существующий код:

1. Импортируйте необходимые классы
2. Создайте экземпляр SyncLogger с подключением к БД
3. Оберните основную логику в сессию синхронизации
4. Добавьте логирование ключевых этапов
5. Завершите сессию с соответствующим статусом

## Требования

- Python 3.7+
- MySQL/MariaDB с таблицей sync_logs
- Библиотека psutil для мониторинга памяти
- Подключение к базе данных с правами на создание таблиц

## Тестирование

Запустите тесты для проверки функциональности:

```bash
python3 test_sync_logger.py
```

Все тесты должны пройти успешно перед использованием в продакшн среде.
