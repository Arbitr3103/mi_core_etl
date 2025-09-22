# Design Document

## Overview

Система расчета маржинальности будет улучшена для обеспечения точного расчета чистой прибыли с учетом всех компонентов: себестоимости, комиссий маркетплейса и логистических расходов. Основные изменения коснутся скрипта `run_aggregation.py` и SQL-запросов для агрегации данных.

## Architecture

### Компоненты системы

1. **Aggregation Engine** (`run_aggregation.py`) - основной движок агрегации
2. **Database Layer** - таблицы `fact_orders`, `dim_products`, `fact_transactions`, `metrics_daily`
3. **Transaction Classifier** - логика классификации типов транзакций
4. **Margin Calculator** - модуль расчета маржинальности

### Поток данных

```
fact_orders (продажи)
    ↓
JOIN dim_products (себестоимость)
    ↓
JOIN fact_transactions (комиссии, логистика)
    ↓
Margin Calculator (расчет прибыли)
    ↓
metrics_daily (сохранение результатов)
```

## Components and Interfaces

### 1. Enhanced Aggregation Query

Новый SQL-запрос будет объединять три основные таблицы:

```sql
SELECT
    fo.client_id,
    fo.order_date AS metric_date,
    COUNT(CASE WHEN fo.transaction_type = 'продажа' THEN fo.id END) AS orders_cnt,
    SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) AS revenue_sum,
    SUM(CASE WHEN fo.transaction_type = 'продажа' THEN COALESCE(dp.cost_price * fo.qty, 0) ELSE 0 END) AS cogs_sum,
    SUM(CASE WHEN ft.transaction_type IN ('комиссия', 'эквайринг') THEN ABS(ft.amount) ELSE 0 END) AS commission_sum,
    SUM(CASE WHEN ft.transaction_type IN ('логистика', 'доставка') THEN ABS(ft.amount) ELSE 0 END) AS shipping_sum,
    -- Расчет чистой прибыли
    (SUM(CASE WHEN fo.transaction_type = 'продажа' THEN (fo.qty * fo.price) ELSE 0 END) -
     SUM(CASE WHEN fo.transaction_type = 'продажа' THEN COALESCE(dp.cost_price * fo.qty, 0) ELSE 0 END) -
     SUM(CASE WHEN ft.transaction_type IN ('комиссия', 'эквайринг', 'логистика', 'доставка') THEN ABS(ft.amount) ELSE 0 END)) AS profit_sum
FROM fact_orders fo
LEFT JOIN dim_products dp ON fo.product_id = dp.id
LEFT JOIN fact_transactions ft ON fo.order_id = ft.order_id
WHERE fo.order_date = %s
GROUP BY fo.client_id, fo.order_date
```

### 2. Transaction Type Mapping

Классификация типов транзакций из fact_transactions:

- **Комиссии**: `'комиссия'`, `'эквайринг'`, `'OperationMarketplaceServiceItemFulfillment'`
- **Логистика**: `'логистика'`, `'доставка'`, `'OperationMarketplaceServiceItemDeliveryToCustomer'`
- **Возвраты**: `'возврат'`, `'OperationMarketplaceServiceItemReturn'`

### 3. Margin Percentage Calculator

Функция для расчета процента маржинальности:

```python
def calculate_margin_percentage(profit_sum: float, revenue_sum: float) -> Optional[float]:
    """
    Рассчитывает процент маржинальности.

    Args:
        profit_sum: Чистая прибыль
        revenue_sum: Общая выручка

    Returns:
        Процент маржинальности или None если выручка равна 0
    """
    if revenue_sum == 0:
        return None
    return (profit_sum / revenue_sum) * 100
```

## Data Models

### Enhanced metrics_daily Table

Таблица `metrics_daily` уже содержит необходимые поля:

```sql
CREATE TABLE metrics_daily (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    metric_date DATE NOT NULL,
    orders_cnt INT NOT NULL DEFAULT 0,
    revenue_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
    returns_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
    cogs_sum DECIMAL(18,4) NULL,                    -- Себестоимость
    shipping_sum DECIMAL(18,4) NOT NULL DEFAULT 0,  -- Логистика
    commission_sum DECIMAL(18,4) NOT NULL DEFAULT 0, -- Комиссии
    other_expenses_sum DECIMAL(18,4) NOT NULL DEFAULT 0,
    profit_sum DECIMAL(18,4) NULL,                  -- Чистая прибыль
    margin_percent DECIMAL(8,4) NULL,               -- Процент маржинальности (добавить)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_metrics (client_id, metric_date)
);
```

**Необходимо добавить поле `margin_percent`:**

```sql
ALTER TABLE metrics_daily
ADD COLUMN margin_percent DECIMAL(8,4) NULL COMMENT 'Процент маржинальности'
AFTER profit_sum;
```

## Error Handling

### 1. Data Validation

- Проверка наличия обязательных полей перед расчетом
- Валидация корректности дат и числовых значений
- Обработка NULL значений в себестоимости

### 2. Transaction Management

- Использование транзакций для атомарности операций
- Rollback при ошибках в процессе агрегации
- Детальное логирование ошибок

### 3. Error Recovery

```python
try:
    cursor.execute(sql_query, params)
    affected_rows = cursor.rowcount
    connection.commit()
    logger.info(f"Агрегация завершена успешно. Обработано записей: {affected_rows}")
    return True
except Exception as e:
    logger.error(f"Ошибка при агрегации метрик за дату {date_to_process}: {e}")
    connection.rollback()
    return False
```

## Testing Strategy

### 1. Unit Tests

- Тестирование функции расчета маржинальности
- Проверка корректности SQL-запросов
- Валидация обработки граничных случаев

### 2. Integration Tests

- Тестирование полного цикла агрегации
- Проверка корректности JOIN операций
- Валидация результатов на тестовых данных

### 3. Performance Tests

- Измерение времени выполнения на больших объемах данных
- Проверка использования индексов
- Оптимизация запросов при необходимости

### 4. Test Data Setup

```python
def create_test_data():
    """Создание тестовых данных для проверки расчета маржинальности"""
    # Создание тестового товара с себестоимостью
    # Создание тестового заказа
    # Создание связанных транзакций (комиссии, логистика)
    # Запуск агрегации и проверка результатов
```

## Performance Considerations

### 1. Database Indexes

Необходимые индексы для оптимизации запросов:

```sql
-- Индексы для fact_orders
CREATE INDEX idx_fact_orders_date_client ON fact_orders(order_date, client_id);
CREATE INDEX idx_fact_orders_product ON fact_orders(product_id);

-- Индексы для fact_transactions
CREATE INDEX idx_fact_transactions_order ON fact_transactions(order_id);
CREATE INDEX idx_fact_transactions_date_type ON fact_transactions(transaction_date, transaction_type);

-- Индексы для dim_products
CREATE INDEX idx_dim_products_cost ON dim_products(id, cost_price);
```

### 2. Query Optimization

- Использование LEFT JOIN вместо INNER JOIN для обработки товаров без себестоимости
- Группировка по минимальному набору полей
- Использование CASE WHEN для условной агрегации

### 3. Batch Processing

- Обработка данных по датам для контроля объема
- Возможность параллельной обработки нескольких дат
- Мониторинг использования памяти

## Migration Plan

### Phase 1: Database Schema Update

1. Добавление поля `margin_percent` в таблицу `metrics_daily`
2. Создание необходимых индексов

### Phase 2: Code Enhancement

1. Обновление SQL-запроса в `run_aggregation.py`
2. Добавление функции расчета процента маржинальности
3. Улучшение обработки ошибок

### Phase 3: Testing & Validation

1. Создание тестовых данных
2. Запуск тестов на исторических данных
3. Валидация результатов

### Phase 4: Production Deployment

1. Резервное копирование данных
2. Развертывание изменений
3. Мониторинг производительности
