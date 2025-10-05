# Design Document

## Overview

Интеграция аналитических данных Ozon в существующий дашборд маржинальности для получения полной воронки продаж и маркетинговых метрик. Система будет расширять текущий функционал дашборда `dashboard_marketplace_enhanced.php` новым разделом аналитики Ozon с визуализацией воронки продаж, демографических данных и экспортом отчетов.

## Architecture

### High-Level Architecture

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   Dashboard     │    │   Ozon Analytics │    │   Ozon API      │
│   Frontend      │◄───┤   Service        │◄───┤   Integration   │
│                 │    │                  │    │                 │
└─────────────────┘    └──────────────────┘    └─────────────────┘
         │                        │                        │
         │                        ▼                        │
         │              ┌──────────────────┐               │
         │              │   Data Storage   │               │
         └──────────────┤   (MySQL)        │◄──────────────┘
                        │                  │
                        └──────────────────┘
```

### Integration Points

1. **Frontend Integration**: Новая вкладка "📊 Аналитика Ozon" в существующем дашборде
2. **Backend Integration**: Новый PHP класс `OzonAnalyticsAPI` интегрированный с `MarginDashboardAPI_Updated`
3. **Database Integration**: Новые таблицы для хранения аналитических данных Ozon
4. **API Integration**: Прямое подключение к Ozon API для получения данных в реальном времени

## Components and Interfaces

### 1. OzonAnalyticsAPI Class

```php
class OzonAnalyticsAPI {
    // Основные методы
    public function authenticate(): string                    // Получение токена доступа
    public function getFunnelData($dateFrom, $dateTo): array  // Данные воронки продаж
    public function getDemographics($dateFrom, $dateTo): array // Демографические данные
    public function getCampaignData($campaignId): array       // Данные рекламных кампаний
    public function exportData($format, $filters): string     // Экспорт данных
}
```

### 2. Database Schema Extensions

```sql
-- Таблица для хранения настроек Ozon API
CREATE TABLE ozon_api_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id VARCHAR(255) NOT NULL,
    api_key_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Таблица для кэширования данных воронки
CREATE TABLE ozon_funnel_data (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    product_id VARCHAR(100),
    campaign_id VARCHAR(100),
    views INT DEFAULT 0,
    cart_additions INT DEFAULT 0,
    orders INT DEFAULT 0,
    conversion_view_to_cart DECIMAL(5,2),
    conversion_cart_to_order DECIMAL(5,2),
    conversion_overall DECIMAL(5,2),
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_range (date_from, date_to),
    INDEX idx_product (product_id),
    INDEX idx_campaign (campaign_id)
);

-- Таблица для демографических данных
CREATE TABLE ozon_demographics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    age_group VARCHAR(20),
    gender VARCHAR(10),
    region VARCHAR(100),
    orders_count INT DEFAULT 0,
    revenue DECIMAL(12,2) DEFAULT 0,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date_range (date_from, date_to),
    INDEX idx_demographics (age_group, gender, region)
);

-- Таблица для данных рекламных кампаний
CREATE TABLE ozon_campaigns (
    id INT PRIMARY KEY AUTO_INCREMENT,
    campaign_id VARCHAR(100) NOT NULL,
    campaign_name VARCHAR(255),
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    impressions INT DEFAULT 0,
    clicks INT DEFAULT 0,
    spend DECIMAL(12,2) DEFAULT 0,
    orders INT DEFAULT 0,
    revenue DECIMAL(12,2) DEFAULT 0,
    ctr DECIMAL(5,2),
    cpc DECIMAL(8,2),
    roas DECIMAL(8,2),
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_campaign_period (campaign_id, date_from, date_to),
    INDEX idx_campaign (campaign_id),
    INDEX idx_date_range (date_from, date_to)
);
```

### 3. Frontend Components

#### 3.1 Ozon Analytics Tab

- Новая вкладка в существующем интерфейсе переключения видов
- Интеграция с текущей системой фильтров (даты, товары)

#### 3.2 Funnel Visualization

```javascript
// Компонент визуализации воронки
class OzonFunnelChart {
  constructor(containerId) {
    this.container = document.getElementById(containerId);
    this.chart = null;
  }

  renderFunnel(data) {
    // Отображение воронки продаж с конверсиями
  }

  updateData(newData) {
    // Обновление данных без перерисовки
  }
}
```

#### 3.3 Demographics Dashboard

```javascript
// Компонент демографической аналитики
class OzonDemographics {
  renderAgeDistribution(data) {
    /* ... */
  }
  renderGenderSplit(data) {
    /* ... */
  }
  renderRegionalMap(data) {
    /* ... */
  }
}
```

### 4. API Endpoints

```php
// Новые эндпоинты в существующей структуре
/src/api/ozon-analytics.php
    - GET /funnel-data
    - GET /demographics
    - GET /campaigns
    - POST /export-data
    - GET /settings
    - POST /settings (для сохранения настроек)
```

## Data Models

### 1. Funnel Data Model

```php
class OzonFunnelData {
    public $dateFrom;
    public $dateTo;
    public $productId;
    public $campaignId;
    public $views;
    public $cartAdditions;
    public $orders;
    public $conversionViewToCart;
    public $conversionCartToOrder;
    public $conversionOverall;
}
```

### 2. Demographics Data Model

```php
class OzonDemographics {
    public $dateFrom;
    public $dateTo;
    public $ageGroups;      // Array of age group data
    public $genderSplit;    // Male/Female distribution
    public $regions;        // Regional distribution
}
```

### 3. Campaign Data Model

```php
class OzonCampaignData {
    public $campaignId;
    public $campaignName;
    public $dateFrom;
    public $dateTo;
    public $impressions;
    public $clicks;
    public $spend;
    public $orders;
    public $revenue;
    public $ctr;
    public $cpc;
    public $roas;
}
```

## Error Handling

### 1. API Error Handling

```php
class OzonAPIException extends Exception {
    private $errorCode;
    private $errorType;

    public function __construct($message, $code, $type) {
        parent::__construct($message, $code);
        $this->errorCode = $code;
        $this->errorType = $type;
    }
}

// Типы ошибок:
// - AUTHENTICATION_ERROR (401)
// - RATE_LIMIT_EXCEEDED (429)
// - API_UNAVAILABLE (503)
// - INVALID_PARAMETERS (400)
// - TOKEN_EXPIRED (401)
```

### 2. Frontend Error Handling

```javascript
class OzonErrorHandler {
  static handleAPIError(error) {
    switch (error.code) {
      case 401:
        this.showAuthError();
        break;
      case 429:
        this.showRateLimitError();
        break;
      case 503:
        this.showServiceUnavailable();
        break;
      default:
        this.showGenericError(error.message);
    }
  }
}
```

### 3. Data Validation

- Валидация дат (не более 90 дней ретроспективы)
- Проверка формата данных от API
- Валидация токена доступа перед каждым запросом
- Проверка лимитов запросов

## Testing Strategy

### 1. Unit Tests

```php
// Тесты для OzonAnalyticsAPI
class OzonAnalyticsAPITest extends PHPUnit\Framework\TestCase {
    public function testAuthentication() { /* ... */ }
    public function testFunnelDataRetrieval() { /* ... */ }
    public function testErrorHandling() { /* ... */ }
    public function testDataCaching() { /* ... */ }
}
```

### 2. Integration Tests

- Тестирование подключения к реальному API Ozon
- Проверка корректности сохранения данных в БД
- Тестирование интеграции с существующим дашбордом

### 3. Frontend Tests

```javascript
// Jest тесты для компонентов
describe("OzonFunnelChart", () => {
  test("renders funnel correctly", () => {
    /* ... */
  });
  test("handles empty data", () => {
    /* ... */
  });
  test("updates on filter change", () => {
    /* ... */
  });
});
```

### 4. Performance Tests

- Тестирование времени отклика API
- Проверка производительности при больших объемах данных
- Тестирование кэширования

## Security Considerations

### 1. API Key Management

- Хранение API ключей в зашифрованном виде
- Использование environment variables для чувствительных данных
- Ротация токенов доступа

### 2. Data Protection

- Шифрование демографических данных
- Логирование доступа к аналитическим данным
- Ограничение доступа по ролям пользователей

### 3. Rate Limiting

- Реализация локального rate limiting
- Кэширование данных для снижения нагрузки на API
- Graceful degradation при превышении лимитов

## Performance Optimization

### 1. Caching Strategy

```php
class OzonDataCache {
    private $cacheTimeout = 3600; // 1 hour

    public function getCachedFunnelData($key) {
        // Проверка актуальности кэша
        // Возврат кэшированных данных или null
    }

    public function cacheFunnelData($key, $data) {
        // Сохранение данных в кэш с TTL
    }
}
```

### 2. Database Optimization

- Индексы для быстрого поиска по датам и товарам
- Партиционирование таблиц по датам
- Регулярная очистка устаревших данных

### 3. Frontend Optimization

- Lazy loading для больших наборов данных
- Виртуализация таблиц с большим количеством строк
- Debouncing для фильтров и поиска

## Deployment Strategy

### 1. Database Migration

```sql
-- Миграция для добавления новых таблиц
-- Файл: migrations/add_ozon_analytics_tables.sql
```

### 2. Configuration

```php
// config/ozon_config.php
return [
    'api_base_url' => 'https://api-seller.ozon.ru',
    'cache_timeout' => 3600,
    'rate_limit' => 100, // requests per hour
    'max_date_range' => 90, // days
];
```

### 3. Monitoring

- Логирование всех API запросов
- Мониторинг производительности
- Алерты при ошибках API или превышении лимитов
