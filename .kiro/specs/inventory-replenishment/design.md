# Design Document

## Overview

Система автоматических заявок на пополнение склада будет анализировать текущие остатки товаров, рассчитывать скорость продаж и генерировать рекомендации по пополнению запасов. Система интегрируется с существующими таблицами `inventory` и `fact_orders` для получения актуальных данных.

## Architecture

### Компоненты системы

1. **Inventory Analyzer** - анализ текущих остатков
2. **Sales Velocity Calculator** - расчет скорости продаж
3. **Replenishment Recommender** - генерация рекомендаций
4. **Alert System** - система уведомлений
5. **Reporting Engine** - формирование отчетов

### Поток данных

```
inventory (остатки) + fact_orders (продажи)
    ↓
Sales Velocity Calculator
    ↓
Replenishment Recommender
    ↓
replenishment_recommendations (результаты)
    ↓
Alert System + Reporting Engine
```

## Components and Interfaces

### 1. Inventory Analyzer

Анализирует текущие остатки товаров из таблицы `inventory`:

```python
class InventoryAnalyzer:
    def get_current_stock(self, product_id=None, source=None):
        """Получить текущие остатки товаров"""

    def get_products_below_threshold(self, threshold_days=7):
        """Получить товары с критически низкими остатками"""

    def get_overstocked_products(self, threshold_days=90):
        """Получить товары с избыточными запасами"""
```

### 2. Sales Velocity Calculator

Рассчитывает скорость продаж на основе данных из `fact_orders`:

```python
class SalesVelocityCalculator:
    def calculate_daily_sales_rate(self, product_id, days=7):
        """Рассчитать среднедневные продажи за период"""

    def calculate_days_until_stockout(self, product_id):
        """Рассчитать дни до исчерпания запасов"""

    def get_sales_trend(self, product_id, days=30):
        """Определить тренд продаж (рост/падение/стабильно)"""
```

### 3. Replenishment Recommender

Генерирует рекомендации по пополнению:

```python
class ReplenishmentRecommender:
    def generate_recommendations(self):
        """Генерировать рекомендации по пополнению"""

    def calculate_recommended_quantity(self, product_id, lead_time_days=14):
        """Рассчитать рекомендуемое количество для заказа"""

    def prioritize_recommendations(self, recommendations):
        """Приоритизировать рекомендации по критичности"""
```

## Data Models

### Новая таблица: replenishment_recommendations

```sql
CREATE TABLE replenishment_recommendations (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    sku VARCHAR(255) NOT NULL,
    product_name VARCHAR(500),
    source VARCHAR(50) NOT NULL,

    -- Текущее состояние
    current_stock INT NOT NULL,
    reserved_stock INT DEFAULT 0,
    available_stock INT NOT NULL,

    -- Анализ продаж
    daily_sales_rate_7d DECIMAL(10,2) NOT NULL,
    daily_sales_rate_14d DECIMAL(10,2) NOT NULL,
    daily_sales_rate_30d DECIMAL(10,2) NOT NULL,

    -- Прогнозы
    days_until_stockout INT,
    recommended_order_quantity INT NOT NULL,
    recommended_order_value DECIMAL(12,2),

    -- Приоритизация
    priority_level ENUM('CRITICAL', 'HIGH', 'MEDIUM', 'LOW') NOT NULL,
    urgency_score DECIMAL(5,2) NOT NULL,

    -- Дополнительная информация
    last_sale_date DATE,
    last_restock_date DATE,
    sales_trend ENUM('GROWING', 'STABLE', 'DECLINING') DEFAULT 'STABLE',

    -- Метаданные
    analysis_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Индексы
    INDEX idx_product_analysis (product_id, analysis_date),
    INDEX idx_priority_urgency (priority_level, urgency_score),
    INDEX idx_source_date (source, analysis_date),

    FOREIGN KEY (product_id) REFERENCES dim_products(id)
) ENGINE=InnoDB;
```

### Расширение существующих таблиц

Добавим поля в таблицу `dim_products` для настроек пополнения:

```sql
ALTER TABLE dim_products ADD COLUMN (
    min_stock_level INT DEFAULT 0 COMMENT 'Минимальный уровень запасов',
    max_stock_level INT DEFAULT 0 COMMENT 'Максимальный уровень запасов',
    reorder_point INT DEFAULT 0 COMMENT 'Точка перезаказа',
    lead_time_days INT DEFAULT 14 COMMENT 'Время поставки в днях',
    supplier_id INT DEFAULT NULL COMMENT 'ID поставщика',
    is_active BOOLEAN DEFAULT TRUE COMMENT 'Активный товар для анализа'
);
```

## Business Logic

### Алгоритм расчета рекомендаций

1. **Анализ скорости продаж:**

   ```
   daily_sales_rate = SUM(quantity_sold_last_7_days) / 7
   ```

2. **Прогноз исчерпания запасов:**

   ```
   days_until_stockout = available_stock / daily_sales_rate
   ```

3. **Расчет рекомендуемого количества:**

   ```
   safety_stock = daily_sales_rate * safety_days (default: 7)
   lead_time_demand = daily_sales_rate * lead_time_days
   recommended_quantity = lead_time_demand + safety_stock - current_stock
   ```

4. **Определение приоритета:**

   ```
   if days_until_stockout <= 3: priority = 'CRITICAL'
   elif days_until_stockout <= 7: priority = 'HIGH'
   elif days_until_stockout <= 14: priority = 'MEDIUM'
   else: priority = 'LOW'
   ```

5. **Расчет urgency_score:**
   ```
   urgency_score = (daily_sales_rate * 10) / max(days_until_stockout, 1)
   ```

## Error Handling

### 1. Обработка отсутствующих данных

```python
def handle_missing_sales_data(product_id):
    """Обработка товаров без истории продаж"""
    # Использовать средние показатели по категории
    # Или установить минимальные рекомендации
```

### 2. Валидация данных

```python
def validate_inventory_data(inventory_record):
    """Валидация данных об остатках"""
    if inventory_record.quantity_present < 0:
        raise ValueError("Отрицательный остаток")

    if inventory_record.quantity_reserved > inventory_record.quantity_present:
        logger.warning("Резерв больше остатка")
```

### 3. Обработка аномалий

```python
def detect_sales_anomalies(product_id, sales_data):
    """Обнаружение аномалий в продажах"""
    # Выявление резких скачков или падений
    # Исключение из расчетов или корректировка
```

## Performance Considerations

### 1. Оптимизация запросов

```sql
-- Индекс для быстрого поиска продаж за период
CREATE INDEX idx_orders_product_date ON fact_orders(product_id, order_date);

-- Индекс для анализа остатков
CREATE INDEX idx_inventory_product_source ON inventory(product_id, source);
```

### 2. Кэширование результатов

```python
class ReplenishmentCache:
    def cache_recommendations(self, date, recommendations):
        """Кэширование рекомендаций на день"""

    def get_cached_recommendations(self, date):
        """Получение кэшированных рекомендаций"""
```

### 3. Батчевая обработка

```python
def process_products_in_batches(product_ids, batch_size=100):
    """Обработка товаров батчами для оптимизации"""
    for i in range(0, len(product_ids), batch_size):
        batch = product_ids[i:i + batch_size]
        process_batch(batch)
```

## Alert System

### Типы уведомлений

1. **CRITICAL** - товар закончится в течение 3 дней
2. **URGENT** - товар закончится в течение 7 дней
3. **WARNING** - товар не продавался более 30 дней
4. **INFO** - рекомендация по оптимизации запасов

### Каналы уведомлений

```python
class AlertManager:
    def send_email_alert(self, recipients, alert_data):
        """Отправка email уведомлений"""

    def send_slack_notification(self, channel, message):
        """Отправка в Slack"""

    def create_dashboard_alert(self, alert_data):
        """Создание алерта в дашборде"""
```

## Reporting Engine

### Типы отчетов

1. **Ежедневный отчет** - критические товары
2. **Еженедельный отчет** - полный анализ запасов
3. **Месячный отчет** - аналитика эффективности
4. **Ad-hoc отчеты** - по запросу пользователя

### Форматы экспорта

- CSV для Excel
- JSON для API
- PDF для печати
- HTML для веб-просмотра

## Integration Points

### 1. Интеграция с существующими системами

```python
def sync_with_inventory_system():
    """Синхронизация с системой управления запасами"""

def update_from_sales_data():
    """Обновление из данных о продажах"""

def export_to_erp_system():
    """Экспорт рекомендаций в ERP систему"""
```

### 2. API endpoints

```python
@app.route('/api/replenishment/recommendations')
def get_recommendations():
    """Получить рекомендации по пополнению"""

@app.route('/api/replenishment/analyze/<product_id>')
def analyze_product(product_id):
    """Анализ конкретного товара"""

@app.route('/api/replenishment/alerts')
def get_alerts():
    """Получить активные алерты"""
```

## Testing Strategy

### 1. Unit Tests

- Тестирование расчета скорости продаж
- Валидация алгоритмов рекомендаций
- Проверка обработки граничных случаев

### 2. Integration Tests

- Тестирование работы с базой данных
- Проверка генерации отчетов
- Тестирование системы алертов

### 3. Performance Tests

- Нагрузочное тестирование на больших объемах данных
- Проверка времени выполнения анализа
- Тестирование кэширования

### 4. Business Logic Tests

```python
def test_critical_stock_detection():
    """Тест обнаружения критических остатков"""

def test_recommendation_accuracy():
    """Тест точности рекомендаций"""

def test_priority_calculation():
    """Тест расчета приоритетов"""
```

## Deployment Strategy

### Phase 1: Core Functionality

- Базовый анализ остатков
- Расчет скорости продаж
- Простые рекомендации

### Phase 2: Advanced Features

- Система алертов
- Детальная аналитика
- Интеграция с внешними системами

### Phase 3: Optimization

- ML-алгоритмы прогнозирования
- Автоматическое обучение на исторических данных
- Продвинутая аналитика трендов
