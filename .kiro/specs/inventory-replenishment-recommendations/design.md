# Design Document

## Overview

Система рекомендаций по пополнению склада представляет собой модульную архитектуру, состоящую из компонентов для анализа продаж, расчета рекомендаций, API для интеграции с дашбордом и системы мониторинга. Система использует существующие данные о продажах и остатках из базы данных mi_core_db.

## Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Dashboard     │    │   Scheduler     │    │   Monitoring    │
│   Interface     │    │   (Cron)        │    │   System        │
└─────────┬───────┘    └─────────┬───────┘    └─────────┬───────┘
          │                      │                      │
          │                      │                      │
          v                      v                      v
┌─────────────────────────────────────────────────────────────────┐
│                    Replenishment API                            │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐            │
│  │   Reports   │  │ Calculations│  │   Config    │            │
│  │  Endpoint   │  │  Endpoint   │  │  Endpoint   │            │
│  └─────────────┘  └─────────────┘  └─────────────┘            │
└─────────────────────┬───────────────────────────────────────────┘
                      │
                      v
┌─────────────────────────────────────────────────────────────────┐
│                 Core Business Logic                             │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐            │
│  │    Sales    │  │    Stock    │  │Replenishment│            │
│  │  Analyzer   │  │ Calculator  │  │ Recommender │            │
│  └─────────────┘  └─────────────┘  └─────────────┘            │
└─────────────────────┬───────────────────────────────────────────┘
                      │
                      v
┌─────────────────────────────────────────────────────────────────┐
│                    Data Layer                                   │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐            │
│  │ Sales Data  │  │ Stock Data  │  │Recommendations│           │
│  │   (Orders)  │  │(Inventory)  │  │   Storage   │            │
│  └─────────────┘  └─────────────┘  └─────────────┘            │
└─────────────────────────────────────────────────────────────────┘
```

## Components and Interfaces

### 1. SalesAnalyzer Class

**Назначение:** Анализ продаж и расчет ADS

**Методы:**

- `calculateADS(productId, days = 30)`: Расчет средних продаж в день
- `getValidSalesDays(productId, days)`: Получение дней с ненулевыми остатками
- `getSalesData(productId, startDate, endDate)`: Получение данных продаж
- `validateSalesData(salesData)`: Валидация данных продаж

**Интерфейс:**

```php
interface SalesAnalyzerInterface {
    public function calculateADS(int $productId, int $days = 30): float;
    public function getValidSalesDays(int $productId, int $days): array;
}
```

### 2. StockCalculator Class

**Назначение:** Расчет целевых уровней запаса и рекомендаций

**Методы:**

- `calculateTargetStock(float $ads, int $replenishmentDays, int $safetyDays)`: Расчет целевого запаса
- `calculateReplenishmentRecommendation(float $targetStock, int $currentStock)`: Расчет рекомендации
- `getCurrentStock(int $productId)`: Получение текущего остатка
- `getReplenishmentParameters()`: Получение параметров расчета

**Интерфейс:**

```php
interface StockCalculatorInterface {
    public function calculateTargetStock(float $ads, int $replenishmentDays, int $safetyDays): float;
    public function calculateReplenishmentRecommendation(float $targetStock, int $currentStock): int;
}
```

### 3. ReplenishmentRecommender Class

**Назначение:** Основной класс для генерации рекомендаций

**Методы:**

- `generateRecommendations(array $productIds = null)`: Генерация рекомендаций
- `generateWeeklyReport()`: Генерация еженедельного отчета
- `saveRecommendations(array $recommendations)`: Сохранение рекомендаций
- `getRecommendations(array $filters = [])`: Получение рекомендаций

### 4. ReplenishmentAPI Class

**Назначение:** API для интеграции с дашбордом

**Endpoints:**

- `GET /api/replenishment.php?action=recommendations`: Получение рекомендаций
- `GET /api/replenishment.php?action=report`: Генерация отчета
- `POST /api/replenishment.php?action=calculate`: Запуск расчета
- `GET /api/replenishment.php?action=config`: Получение конфигурации
- `POST /api/replenishment.php?action=config`: Обновление конфигурации

## Data Models

### 1. Recommendation Model

```sql
CREATE TABLE replenishment_recommendations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    sku VARCHAR(100),
    ads DECIMAL(10,2) NOT NULL,
    current_stock INT NOT NULL,
    target_stock INT NOT NULL,
    recommended_quantity INT NOT NULL,
    calculation_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES dim_products(id),
    INDEX idx_calculation_date (calculation_date),
    INDEX idx_product_id (product_id)
);
```

### 2. Configuration Model

```sql
CREATE TABLE replenishment_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    parameter_name VARCHAR(100) NOT NULL UNIQUE,
    parameter_value VARCHAR(255) NOT NULL,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT INTO replenishment_config (parameter_name, parameter_value, description) VALUES
('replenishment_days', '14', 'Период пополнения в днях'),
('safety_days', '7', 'Страховой запас в днях'),
('analysis_days', '30', 'Период анализа продаж в днях'),
('min_ads_threshold', '0.1', 'Минимальный ADS для включения в рекомендации');
```

### 3. Calculation Log Model

```sql
CREATE TABLE replenishment_calculations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    calculation_date DATE NOT NULL,
    products_processed INT NOT NULL,
    recommendations_generated INT NOT NULL,
    execution_time_seconds INT,
    status ENUM('success', 'error', 'partial') NOT NULL,
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_calculation_date (calculation_date)
);
```

## Error Handling

### 1. Data Validation Errors

- Проверка наличия данных продаж за анализируемый период
- Валидация корректности остатков товаров
- Проверка существования товаров в системе

### 2. Calculation Errors

- Обработка деления на ноль при расчете ADS
- Валидация отрицательных значений остатков
- Проверка корректности параметров конфигурации

### 3. Database Errors

- Обработка ошибок подключения к базе данных
- Откат транзакций при ошибках сохранения
- Логирование всех ошибок базы данных

### 4. API Errors

- Стандартизированные коды ошибок HTTP
- Детальные сообщения об ошибках в JSON формате
- Логирование всех API запросов и ошибок

## Testing Strategy

### 1. Unit Tests

- Тестирование расчета ADS с различными наборами данных
- Тестирование расчета целевого запаса и рекомендаций
- Тестирование валидации данных и обработки ошибок
- Тестирование конфигурационных параметров

### 2. Integration Tests

- Тестирование интеграции с базой данных
- Тестирование API endpoints
- Тестирование генерации отчетов
- Тестирование еженедельного расчета

### 3. Performance Tests

- Тестирование производительности с большими объемами данных
- Тестирование времени выполнения расчетов
- Тестирование нагрузки на API
- Мониторинг использования памяти

### 4. Data Quality Tests

- Проверка корректности исходных данных
- Валидация результатов расчетов
- Тестирование граничных случаев
- Проверка консистентности данных

## Performance Considerations

### 1. Database Optimization

- Индексы на часто используемые поля (product_id, calculation_date)
- Партиционирование таблиц по датам для больших объемов данных
- Оптимизация запросов для расчета ADS
- Кэширование конфигурационных параметров

### 2. Calculation Optimization

- Батчевая обработка товаров для снижения нагрузки на БД
- Параллельная обработка независимых расчетов
- Кэширование промежуточных результатов
- Оптимизация SQL запросов для получения данных продаж

### 3. API Optimization

- Пагинация для больших результатов
- Кэширование ответов API
- Сжатие JSON ответов
- Асинхронная обработка длительных операций

## Security Considerations

### 1. API Security

- Аутентификация и авторизация доступа к API
- Валидация всех входных параметров
- Защита от SQL инъекций
- Ограничение частоты запросов (rate limiting)

### 2. Data Security

- Шифрование конфиденциальных данных
- Аудит доступа к данным рекомендаций
- Резервное копирование данных
- Контроль доступа к конфигурации

## Deployment Strategy

### 1. Development Environment

- Локальное тестирование на отдельном дашборде
- Использование тестовых данных
- Отладка и профилирование производительности

### 2. Staging Environment

- Тестирование на копии продакшн данных
- Интеграционное тестирование
- Проверка производительности

### 3. Production Deployment

- Поэтапное развертывание с возможностью отката
- Мониторинг производительности после развертывания
- Резервное копирование перед развертыванием
