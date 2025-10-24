# Design Document

## Overview

Система получения точных остатков товаров на конкретных FBO-складах Ozon через API отчетов с функциональностью разделения товаров на активные и неактивные. Решает проблему неточности стандартного API и обеспечивает четкую классификацию товаров по наличию остатков для улучшения управления складскими запасами.

## Новая функциональность: Активность товаров

### Определение активности

-   **Активный товар**: Общий остаток > 0 (сумма всех полей остатков)
-   **Неактивный товар**: Общий остаток = 0 (нет остатков на складах)
-   **Общий остаток**: quantity_present + available + preparing_for_sale + in_requests + in_transit

## Architecture

### High-Level Architecture

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   Cron Scheduler│    │  ETL Orchestrator│    │ Ozon Reports API│
│                 │───▶│                  │───▶│                 │
│ Daily 06:00 UTC │    │ OzonStockReports │    │ POST /v1/report │
└─────────────────┘    │     Manager      │    │ /warehouse/stock│
                       └──────────────────┘    └─────────────────┘
                                │                        │
                                ▼                        ▼
                       ┌──────────────────┐    ┌─────────────────┐
                       │   Report Status  │    │   CSV Report    │
                       │    Monitoring    │    │   Processing    │
                       │                  │    │                 │
                       └──────────────────┘    └─────────────────┘
                                │                        │
                                ▼                        ▼
                       ┌──────────────────┐    ┌─────────────────┐
                       │   Database       │    │  Notification   │
                       │   Update         │    │   System        │
                       │   inventory      │    │                 │
                       └──────────────────┘    └─────────────────┘
```

### Component Architecture

1. **OzonStockReportsManager** - Основной класс управления процессом
2. **ReportRequestHandler** - Обработка запросов на создание отчетов
3. **ReportStatusMonitor** - Мониторинг готовности отчетов
4. **CSVReportProcessor** - Обработка CSV файлов отчетов
5. **InventoryDataUpdater** - Обновление данных в БД
6. **StockAlertManager** - Система уведомлений о критических остатках
7. **ProductActivityAnalyzer** - Анализ активности товаров (НОВЫЙ)
8. **DashboardFilterManager** - Управление фильтрацией в дашборде (НОВЫЙ)

## Components and Interfaces

### 1. OzonStockReportsManager

**Назначение:** Главный оркестратор процесса получения остатков через API отчетов

**Интерфейс:**

```php
class OzonStockReportsManager {
    public function __construct(PDO $pdo, OzonAnalyticsAPI $ozonAPI);
    public function executeStockReportsETL(): array;
    public function requestWarehouseStockReport(array $filters = []): string;
    public function monitorReportStatus(string $reportCode): array;
    public function processCompletedReport(string $reportCode): array;
    public function getReportHistory(int $days = 30): array;
}
```

**Ключевые методы:**

-   `executeStockReportsETL()` - Запуск полного цикла ETL
-   `requestWarehouseStockReport()` - Запрос генерации отчета
-   `monitorReportStatus()` - Проверка статуса отчета
-   `processCompletedReport()` - Обработка готового отчета

### 2. ReportRequestHandler

**Назначение:** Управление запросами к API отчетов Ozon

**Интерфейс:**

```php
class ReportRequestHandler {
    public function createWarehouseStockReport(array $parameters): string;
    public function getReportInfo(string $reportCode): array;
    public function downloadReportFile(string $downloadUrl): string;
    public function validateReportParameters(array $parameters): bool;
}
```

**API Endpoints:**

-   `POST /v1/report/warehouse/stock` - Создание отчета
-   `POST /v1/report/info` - Проверка статуса отчета

### 3. ReportStatusMonitor

**Назначение:** Мониторинг готовности отчетов с retry логикой

**Интерфейс:**

```php
class ReportStatusMonitor {
    public function waitForReportCompletion(string $reportCode, int $timeoutMinutes = 60): array;
    public function checkReportStatus(string $reportCode): string;
    public function scheduleStatusCheck(string $reportCode, int $delayMinutes = 5): void;
    public function handleReportTimeout(string $reportCode): void;
}
```

**Статусы отчетов:**

-   `PROCESSING` - Отчет генерируется
-   `SUCCESS` - Отчет готов к скачиванию
-   `ERROR` - Ошибка генерации отчета
-   `TIMEOUT` - Превышено время ожидания

### 7. ProductActivityAnalyzer

**Назначение:** Анализ активности товаров на основе остатков

**Интерфейс:**

```php
class ProductActivityAnalyzer {
    public function calculateTotalStock(array $stockData): int;
    public function determineProductActivity(array $productData): string;
    public function getActivityStatistics(): array;
    public function analyzeStockDistribution(): array;
}
```

**Логика активности:**

-   Активный: quantity_present + available + preparing_for_sale + in_requests + in_transit > 0
-   Неактивный: общий остаток = 0

### 8. DashboardFilterManager

**Назначение:** Управление фильтрацией товаров в дашборде

**Интерфейс:**

```php
class DashboardFilterManager {
    public function filterByActivity(array $products, string $filter): array;
    public function getFilterStatistics(array $products): array;
    public function applyVisualStyles(array $products): array;
}
```

**Типы фильтров:**

-   `ALL` - Все товары
-   `ACTIVE` - Только активные товары (остаток > 0)
-   `INACTIVE` - Только неактивные товары (остаток = 0)

### 4. CSVReportProcessor

**Назначение:** Обработка и парсинг CSV файлов отчетов

**Интерфейс:**

```php
class CSVReportProcessor {
    public function parseWarehouseStockCSV(string $csvContent): array;
    public function validateCSVStructure(array $csvData): bool;
    public function normalizeWarehouseNames(array $stockData): array;
    public function mapProductSKUs(array $stockData): array;
}
```

**Структура CSV данных:**

```
SKU,Warehouse_Name,Current_Stock,Reserved_Stock,Available_Stock,Last_Updated
1234567890,Хоругвино,150,25,125,2025-10-23 10:30:00
1234567890,Тверь,89,12,77,2025-10-23 10:30:00
```

### 5. InventoryDataUpdater

**Назначение:** Обновление данных остатков в базе данных

**Интерфейс:**

```php
class InventoryDataUpdater {
    public function updateInventoryFromReport(array $stockData): array;
    public function upsertInventoryRecord(array $stockRecord): bool;
    public function markStaleRecords(string $source, DateTime $cutoffTime): int;
    public function getInventoryUpdateStats(): array;
}
```

**Логика обновления:**

1. Upsert записей по ключу (product_id, warehouse_name, source)
2. Обновление timestamp последнего обновления
3. Маркировка устаревших записей
4. Логирование изменений

### 6. StockAlertManager

**Назначение:** Система уведомлений о критических остатках

**Интерфейс:**

```php
class StockAlertManager {
    public function analyzeStockLevels(array $stockData): array;
    public function generateCriticalStockAlerts(): array;
    public function sendStockAlertNotifications(array $alerts): bool;
    public function getStockAlertHistory(int $days = 7): array;
}
```

## Data Models

### 1. Расширение таблицы inventory

```sql
ALTER TABLE inventory
ADD COLUMN report_source ENUM('API_DIRECT', 'API_REPORTS') DEFAULT 'API_DIRECT',
ADD COLUMN last_report_update TIMESTAMP NULL,
ADD COLUMN report_code VARCHAR(255) NULL,
ADD INDEX idx_report_source (report_source),
ADD INDEX idx_last_report_update (last_report_update);
```

### 2. Новая таблица ozon_stock_reports

```sql
CREATE TABLE ozon_stock_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(255) NOT NULL UNIQUE,
    report_type ENUM('warehouse_stock') NOT NULL,
    status ENUM('REQUESTED', 'PROCESSING', 'SUCCESS', 'ERROR', 'TIMEOUT') NOT NULL,
    request_parameters JSON,
    download_url VARCHAR(500) NULL,
    file_size INT NULL,
    records_processed INT DEFAULT 0,
    error_message TEXT NULL,
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    processed_at TIMESTAMP NULL,

    INDEX idx_status (status),
    INDEX idx_requested_at (requested_at),
    INDEX idx_report_type (report_type)
) ENGINE=InnoDB;
```

### 3. Таблица stock_report_logs

```sql
CREATE TABLE stock_report_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(255) NOT NULL,
    log_level ENUM('INFO', 'WARNING', 'ERROR') NOT NULL,
    message TEXT NOT NULL,
    context JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_report_code (report_code),
    INDEX idx_log_level (log_level),
    INDEX idx_created_at (created_at),

    FOREIGN KEY (report_code) REFERENCES ozon_stock_reports(report_code) ON DELETE CASCADE
) ENGINE=InnoDB;
```

## Error Handling

### 1. API Error Handling

**Retry Strategy:**

-   Exponential backoff: 30s, 60s, 120s
-   Maximum 3 retries for report requests
-   Maximum 5 retries for file downloads

**Error Types:**

-   `AUTHENTICATION_ERROR` - Проблемы с API ключами
-   `RATE_LIMIT_EXCEEDED` - Превышен лимит запросов
-   `REPORT_GENERATION_FAILED` - Ошибка генерации отчета
-   `DOWNLOAD_FAILED` - Ошибка скачивания файла
-   `TIMEOUT_ERROR` - Превышено время ожидания

### 2. Data Validation

**CSV Validation:**

-   Проверка обязательных колонок
-   Валидация форматов данных
-   Проверка на дубликаты
-   Валидация SKU против справочника товаров

**Database Constraints:**

-   Foreign key constraints на product_id
-   Unique constraints на комбинации ключей
-   Check constraints на положительные значения остатков

### 3. Fallback Mechanisms

**При сбоях API отчетов:**

1. Использование последних доступных данных
2. Уведомление администраторов
3. Переключение на стандартный API (с пометкой о неточности)
4. Логирование для последующего анализа

## Testing Strategy

### 1. Unit Tests

**Компоненты для тестирования:**

-   `CSVReportProcessor::parseWarehouseStockCSV()`
-   `InventoryDataUpdater::upsertInventoryRecord()`
-   `ReportStatusMonitor::waitForReportCompletion()`
-   `StockAlertManager::analyzeStockLevels()`

**Mock Objects:**

-   Mock Ozon API responses
-   Mock CSV файлы с различными структурами
-   Mock database connections

### 2. Integration Tests

**Сценарии тестирования:**

-   Полный цикл ETL с mock API
-   Обработка различных статусов отчетов
-   Обновление данных в тестовой БД
-   Генерация уведомлений

### 3. End-to-End Tests

**Тестовые сценарии:**

-   Запуск ETL процесса в тестовой среде
-   Проверка корректности данных после обновления
-   Тестирование обработки ошибок
-   Проверка производительности на больших объемах данных

## Performance Considerations

### 1. Database Optimization

**Индексы:**

-   Составные индексы на (product_id, warehouse_name, source)
-   Индексы на timestamp поля для быстрой фильтрации
-   Индексы на поля статусов отчетов

**Партиционирование:**

-   Партиционирование таблицы stock_report_logs по дате
-   Архивирование старых отчетов (> 90 дней)

### 2. Memory Management

**Обработка больших CSV файлов:**

-   Потоковое чтение файлов
-   Batch обработка записей (по 1000 записей)
-   Освобождение памяти после каждого batch

### 3. Concurrent Processing

**Параллельная обработка:**

-   Асинхронная проверка статусов нескольких отчетов
-   Параллельное обновление данных по разным складам
-   Queue система для обработки уведомлений

## Security Considerations

### 1. API Security

**Защита API ключей:**

-   Хранение в зашифрованном виде
-   Ротация ключей каждые 90 дней
-   Логирование всех API запросов

### 2. Data Security

**Защита данных остатков:**

-   Шифрование чувствительных данных в БД
-   Ограничение доступа к таблицам остатков
-   Аудит всех изменений данных

### 3. File Security

**Безопасность CSV файлов:**

-   Валидация содержимого файлов
-   Временное хранение с автоудалением
-   Проверка на вредоносный контент

## Monitoring and Alerting

### 1. System Monitoring

**Метрики для мониторинга:**

-   Время выполнения ETL процесса
-   Количество успешных/неуспешных отчетов
-   Размер обрабатываемых файлов
-   Количество обновленных записей

### 2. Business Monitoring

**Бизнес-метрики:**

-   Количество складов с критическими остатками
-   Процент товаров с нулевыми остатками
-   Средний возраст данных остатков
-   Покрытие товаров данными по складам

### 3. Alert Configuration

**Критические алерты:**

-   Сбой ETL процесса более 2 часов
-   Отсутствие обновлений данных более 24 часов
-   Критические остатки на ключевых товарах
-   Ошибки API более 50% запросов

## Deployment Strategy

### 1. Database Migration

**Последовательность миграций:**

1. Создание новых таблиц
2. Добавление новых колонок в существующие таблицы
3. Создание индексов
4. Миграция существующих данных

### 2. Code Deployment

**Поэтапное развертывание:**

1. Развертывание новых классов без активации
2. Тестирование в staging среде
3. Постепенная активация функций
4. Мониторинг производительности

### 3. Rollback Plan

**План отката:**

-   Отключение новых ETL процессов
-   Возврат к предыдущей версии кода
-   Восстановление данных из backup
-   Уведомление пользователей о временных ограничениях
