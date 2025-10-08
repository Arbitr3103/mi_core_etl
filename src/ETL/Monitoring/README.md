# ETL Monitoring System

Система мониторинга ETL процессов для отслеживания статуса выполнения, метрик производительности и уведомлений об ошибках.

## Компоненты системы

### 1. ETLMonitoringService

Основной сервис для мониторинга ETL задач:

- Отслеживание статуса выполнения задач
- Сбор метрик производительности
- Управление сессиями мониторинга
- Генерация системных алертов

### 2. ETLNotificationService

Сервис уведомлений:

- Email уведомления
- Slack интеграция
- Telegram боты
- Система cooldown для предотвращения спама
- Еженедельные отчеты

### 3. ETLDashboardController

Контроллер для веб-интерфейса:

- API для получения данных мониторинга
- Аналитические данные
- История выполнения задач
- Экспорт отчетов

### 4. Dashboard (dashboard.php)

Веб-интерфейс мониторинга:

- Реальное время отслеживания активных задач
- Графики производительности
- Системные алерты
- Детальная информация о задачах

## Установка и настройка

### 1. Создание таблиц базы данных

```sql
-- Выполните скрипт создания схемы
source src/ETL/Database/monitoring_schema.sql;
```

### 2. Конфигурация

```php
$config = [
    'monitoring' => [
        'performance_threshold_seconds' => 300, // 5 минут
        'error_threshold_count' => 10,
        'notification_cooldown_minutes' => 30,
        'metrics_retention_days' => 90
    ],
    'notifications' => [
        'email_enabled' => true,
        'email_recipients' => ['admin@example.com'],
        'slack_enabled' => false,
        'slack_webhook_url' => '',
        'telegram_enabled' => false,
        'telegram_bot_token' => '',
        'telegram_chat_id' => ''
    ]
];
```

### 3. Интеграция с ETL процессами

```php
use MDM\ETL\Monitoring\ETLMonitoringService;

$monitoringService = new ETLMonitoringService($pdo, $config['monitoring']);

// Запуск мониторинга задачи
$sessionId = $monitoringService->startTaskMonitoring(
    'my_etl_task_001',
    'full_etl',
    ['source' => 'ozon']
);

// Обновление прогресса
$monitoringService->updateTaskProgress($sessionId, [
    'current_step' => 'Извлечение данных',
    'records_processed' => 500,
    'records_total' => 1000
]);

// Завершение задачи
$monitoringService->finishTaskMonitoring($sessionId, 'success', [
    'records_processed' => 1000,
    'duration_seconds' => 120
]);
```

## Использование

### Запуск мониторинга ETL задачи

```php
// Создание сессии мониторинга
$sessionId = $monitoringService->startTaskMonitoring(
    $taskId,           // Уникальный ID задачи
    $taskType,         // Тип задачи (full_etl, incremental_etl, etc.)
    $metadata          // Дополнительные метаданные
);

// Обновление прогресса выполнения
$monitoringService->updateTaskProgress($sessionId, [
    'current_step' => 'Название текущего шага',
    'records_processed' => 100,
    'records_total' => 500,
    'additional_data' => ['key' => 'value']
]);

// Завершение мониторинга
$monitoringService->finishTaskMonitoring($sessionId, $status, $results);
```

### Получение статистики

```php
// Активные задачи
$activeTasks = $monitoringService->getActiveTasksStatus();

// История выполнения
$history = $monitoringService->getTaskHistory(50, [
    'task_type' => 'full_etl',
    'status' => 'success',
    'date_from' => '2024-01-01'
]);

// Метрики производительности
$metrics = $monitoringService->getPerformanceMetrics(7); // за 7 дней

// Статистика ошибок
$errors = $monitoringService->getErrorStatistics(7);

// Системные алерты
$alerts = $monitoringService->getSystemAlerts();
```

### Настройка уведомлений

#### Email уведомления

```php
$config['notifications'] = [
    'email_enabled' => true,
    'email_recipients' => [
        'admin@company.com',
        'devops@company.com'
    ]
];
```

#### Slack уведомления

```php
$config['notifications'] = [
    'slack_enabled' => true,
    'slack_webhook_url' => 'https://hooks.slack.com/services/YOUR/WEBHOOK/URL'
];
```

#### Telegram уведомления

```php
$config['notifications'] = [
    'telegram_enabled' => true,
    'telegram_bot_token' => 'YOUR_BOT_TOKEN',
    'telegram_chat_id' => 'YOUR_CHAT_ID'
];
```

### Веб-интерфейс

Откройте `src/ETL/Monitoring/dashboard.php` в браузере для доступа к веб-интерфейсу мониторинга.

Основные функции dashboard:

- Отображение активных задач в реальном времени
- Графики производительности и трендов
- Системные алерты и уведомления
- Детальная информация о каждой задаче
- История выполнения с фильтрацией
- Экспорт отчетов

## API Endpoints

### Получение данных dashboard

```
GET /dashboard.php?action=dashboard_data
```

Возвращает полные данные для главной страницы dashboard.

### Детали задачи

```
GET /dashboard.php?action=task_details&session_id=SESSION_ID
```

Возвращает детальную информацию о конкретной задаче.

### История задач

```
GET /dashboard.php?action=task_history&page=1&filters[status]=success
```

Возвращает историю выполнения задач с пагинацией и фильтрацией.

### Аналитические данные

```
GET /dashboard.php?action=analytics&days=7
```

Возвращает аналитические данные за указанный период.

## Мониторинг производительности

### Ключевые метрики

1. **Время выполнения задач** - отслеживание длительности выполнения
2. **Пропускная способность** - количество записей в секунду
3. **Процент успешности** - соотношение успешных и неудачных задач
4. **Использование ресурсов** - память и CPU (если доступно)

### Пороговые значения

- **performance_threshold_seconds**: Максимальное время выполнения задачи
- **error_threshold_count**: Максимальное количество ошибок в час
- **notification_cooldown_minutes**: Интервал между уведомлениями

### Автоматические алерты

Система автоматически генерирует алерты при:

- Превышении времени выполнения задач
- Высокой частоте ошибок
- Зависших задачах
- Проблемах с производительностью

## Обслуживание системы

### Автоматическая очистка

Система автоматически очищает старые данные:

- Логи старше 30 дней
- Метрики старше 90 дней
- Устаревший кэш
- Успешно обработанные записи из очереди повторов

### Ручная очистка

```sql
-- Очистка данных мониторинга старше 60 дней
CALL CleanupMonitoringData(60);

-- Обновление системных алертов
CALL UpdateSystemAlerts();

-- Генерация отчета производительности за 30 дней
CALL GeneratePerformanceReport(30);
```

## Тестирование

Запустите тестовый скрипт для проверки работы системы:

```bash
php test_etl_monitoring.php
```

Тест проверяет:

- Создание и мониторинг задач
- Обновление прогресса
- Завершение с успехом и ошибкой
- Получение статистики и метрик
- Работу dashboard API
- Системные алерты

## Структура базы данных

### Основные таблицы

- `etl_monitoring_sessions` - сессии мониторинга задач
- `etl_performance_metrics` - метрики производительности
- `etl_notifications` - история уведомлений
- `etl_system_alerts` - системные алерты
- `etl_monitoring_config` - конфигурация мониторинга
- `etl_dashboard_settings` - настройки пользователей

### Представления

- `v_etl_active_tasks` - активные задачи
- `v_etl_performance_summary` - сводка производительности
- `v_etl_error_analysis` - анализ ошибок
- `v_etl_dashboard_metrics` - метрики для dashboard

## Безопасность

- Все входные данные валидируются и экранируются
- Конфиденциальные настройки могут быть зашифрованы
- Доступ к dashboard может быть ограничен аутентификацией
- Логи не содержат чувствительной информации

## Масштабирование

Система поддерживает:

- Горизонтальное масштабирование через репликацию БД
- Партиционирование таблиц по датам
- Асинхронную обработку уведомлений
- Кэширование часто запрашиваемых данных

## Интеграция с внешними системами

- **Grafana**: Экспорт метрик для визуализации
- **Prometheus**: Метрики в формате Prometheus
- **ELK Stack**: Отправка логов в Elasticsearch
- **Monitoring системы**: Интеграция с Nagios, Zabbix и др.

## Troubleshooting

### Частые проблемы

1. **Задачи не отображаются в мониторинге**

   - Проверьте интеграцию с ETLOrchestrator
   - Убедитесь, что вызывается startTaskMonitoring()

2. **Уведомления не отправляются**

   - Проверьте настройки каналов уведомлений
   - Убедитесь, что не сработал cooldown

3. **Dashboard не загружается**

   - Проверьте подключение к базе данных
   - Убедитесь, что созданы все необходимые таблицы

4. **Медленная работа dashboard**
   - Проверьте индексы в базе данных
   - Настройте очистку старых данных

### Логи и отладка

Все операции логируются в таблицу `etl_logs` с источником:

- `monitoring` - основной сервис мониторинга
- `notifications` - сервис уведомлений
- `dashboard` - контроллер dashboard

Для отладки используйте:

```sql
-- Последние логи мониторинга
SELECT * FROM etl_logs
WHERE source IN ('monitoring', 'notifications', 'dashboard')
ORDER BY created_at DESC
LIMIT 50;
```
