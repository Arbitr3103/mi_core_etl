# Design Document: Система управления мастер-данными товаров (MDM)

## Overview

Система MDM (Master Data Management) предназначена для централизованного управления товарными данными, устранения дублирования и обеспечения единой точки истины для всех товарных атрибутов. Система включает автоматическое сопоставление, ручную верификацию, мониторинг качества данных и интеграцию с существующими системами.

## Architecture

### Высокоуровневая архитектура

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Внешние       │    │   ETL/Data      │    │   MDM Core      │
│   источники     │───▶│   Pipeline      │───▶│   System        │
│   (Ozon, WB)    │    │                 │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                                                       │
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Reporting     │    │   Management    │    │   Data Quality  │
│   & Analytics   │◀───│   Interface     │◀───│   Monitor       │
│                 │    │                 │    │                 │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

### Компоненты системы

1. **MDM Core System** - основная система управления мастер-данными
2. **ETL Pipeline** - процессы извлечения, трансформации и загрузки данных
3. **Matching Engine** - движок автоматического сопоставления
4. **Management Interface** - веб-интерфейс для управления данными
5. **Data Quality Monitor** - система мониторинга качества данных
6. **API Gateway** - единая точка доступа к очищенным данным

## Components and Interfaces

### 1. База данных MDM

#### Таблица master_products

```sql
CREATE TABLE master_products (
    master_id VARCHAR(50) PRIMARY KEY,
    canonical_name VARCHAR(500) NOT NULL,
    canonical_brand VARCHAR(200),
    canonical_category VARCHAR(200),
    description TEXT,
    attributes JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive', 'pending_review') DEFAULT 'active'
);
```

#### Таблица sku_mapping

```sql
CREATE TABLE sku_mapping (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    master_id VARCHAR(50) NOT NULL,
    external_sku VARCHAR(200) NOT NULL,
    source VARCHAR(50) NOT NULL,
    source_name VARCHAR(500),
    source_brand VARCHAR(200),
    source_category VARCHAR(200),
    confidence_score DECIMAL(3,2),
    verification_status ENUM('auto', 'manual', 'pending') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (master_id) REFERENCES master_products(master_id),
    UNIQUE KEY unique_source_sku (source, external_sku)
);
```

#### Таблица data_quality_metrics

```sql
CREATE TABLE data_quality_metrics (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(10,2),
    total_records INT,
    good_records INT,
    calculation_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_date (metric_name, calculation_date)
);
```

### 2. Matching Engine

#### Алгоритмы сопоставления

1. **Exact Match** - точное совпадение по SKU или штрихкоду
2. **Fuzzy Name Matching** - нечеткое сравнение названий (Levenshtein, Jaro-Winkler)
3. **Brand + Category Matching** - сопоставление по бренду и категории
4. **Attribute Similarity** - сравнение по ключевым атрибутам

#### Scoring System

```python
def calculate_match_score(candidate, target):
    scores = {
        'name_similarity': fuzzy_match(candidate.name, target.name) * 0.4,
        'brand_match': exact_match(candidate.brand, target.brand) * 0.3,
        'category_match': exact_match(candidate.category, target.category) * 0.2,
        'attributes_similarity': attribute_similarity(candidate, target) * 0.1
    }
    return sum(scores.values())
```

### 3. ETL Pipeline

#### Процесс обработки данных

1. **Extract** - извлечение данных из источников
2. **Transform** - очистка и стандартизация
3. **Match** - поиск соответствий в мастер-данных
4. **Load** - загрузка новых данных или обновление существующих

#### Правила трансформации

```python
transformation_rules = {
    'name_cleanup': [
        'remove_extra_spaces',
        'standardize_units',
        'fix_encoding_issues'
    ],
    'brand_standardization': [
        'normalize_case',
        'fix_common_typos',
        'map_brand_aliases'
    ],
    'category_mapping': [
        'map_to_standard_taxonomy',
        'handle_multilevel_categories'
    ]
}
```

### 4. Management Interface

#### Основные экраны

1. **Dashboard** - общая статистика и метрики качества
2. **Pending Matches** - товары, требующие ручной верификации
3. **Master Products** - управление мастер-данными
4. **Data Quality** - отчеты о качестве данных
5. **Configuration** - настройки системы

#### Workflow для верификации

```
Новый товар → Автоматическое сопоставление →
    ├─ Высокая уверенность (>90%) → Автоматическое принятие
    ├─ Средняя уверенность (70-90%) → Ручная верификация
    └─ Низкая уверенность (<70%) → Создание нового Master ID
```

## Data Models

### Master Product Model

```json
{
  "master_id": "PROD_001",
  "canonical_name": "Смесь для выпечки ЭТОНОВО Пицца 9 порций",
  "canonical_brand": "ЭТОНОВО",
  "canonical_category": "Смеси для выпечки",
  "description": "Готовая смесь для приготовления теста для пиццы",
  "attributes": {
    "weight": "450г",
    "servings": 9,
    "type": "dry_mix",
    "dietary": ["vegetarian"]
  },
  "status": "active"
}
```

### SKU Mapping Model

```json
{
  "master_id": "PROD_001",
  "external_sku": "266215809",
  "source": "ozon",
  "source_name": "Смесь для выпечки ЭТОНОВО пицца 9 шт набор",
  "source_brand": "ЭТОНОВО",
  "source_category": "Смеси для выпечки",
  "confidence_score": 0.95,
  "verification_status": "auto"
}
```

## Error Handling

### Типы ошибок и обработка

1. **Конфликты сопоставления** - когда один SKU может соответствовать нескольким Master ID
2. **Недостающие данные** - обработка товаров с неполной информацией
3. **Ошибки источников** - обработка недоступности внешних API
4. **Дублирование Master ID** - предотвращение создания дубликатов

### Стратегии восстановления

```python
error_handling_strategies = {
    'source_unavailable': 'use_cached_data',
    'matching_conflict': 'queue_for_manual_review',
    'data_validation_failed': 'log_and_skip',
    'duplicate_master_id': 'merge_or_deactivate'
}
```

## Testing Strategy

### Типы тестирования

1. **Unit Tests** - тестирование алгоритмов сопоставления
2. **Integration Tests** - тестирование ETL процессов
3. **Data Quality Tests** - валидация результатов очистки данных
4. **Performance Tests** - тестирование производительности на больших объемах

### Метрики качества

- **Precision** - точность автоматического сопоставления
- **Recall** - полнота покрытия товаров
- **Data Completeness** - процент заполненности атрибутов
- **Processing Speed** - скорость обработки новых товаров

## Performance Considerations

### Оптимизация производительности

1. **Индексирование** - создание индексов для быстрого поиска
2. **Кэширование** - кэширование результатов сопоставления
3. **Пакетная обработка** - обработка данных батчами
4. **Параллелизация** - параллельная обработка независимых товаров

### Масштабирование

- **Горизонтальное масштабирование** - распределение нагрузки между серверами
- **Партиционирование данных** - разделение данных по источникам или категориям
- **Асинхронная обработка** - использование очередей для тяжелых операций

## Security

### Меры безопасности

1. **Аутентификация и авторизация** - контроль доступа к системе
2. **Аудит изменений** - логирование всех изменений мастер-данных
3. **Шифрование данных** - защита чувствительной информации
4. **Резервное копирование** - регулярные бэкапы критических данных

### Роли и права доступа

- **Data Admin** - полный доступ к системе
- **Data Manager** - управление мастер-данными и верификация
- **Data Analyst** - только чтение данных и отчетов
- **System User** - доступ через API для интеграций
