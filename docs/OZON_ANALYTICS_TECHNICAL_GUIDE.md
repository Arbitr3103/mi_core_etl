# Техническая документация Ozon Analytics Integration

## Обзор архитектуры

Интеграция аналитики Ozon представляет собой полнофункциональную систему для сбора, обработки и визуализации аналитических данных из API Ozon. Система интегрирована в существующий дашборд Manhattan и обеспечивает бесшовную работу с текущей инфраструктурой.

### Компоненты системы

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   Frontend      │    │   Backend API    │    │   Ozon API      │
│   Dashboard     │◄───┤   Integration    │◄───┤   Services      │
│                 │    │                  │    │                 │
└─────────────────┘    └──────────────────┘    └─────────────────┘
         │                        │                        │
         │                        ▼                        │
         │              ┌──────────────────┐               │
         │              │   Data Storage   │               │
         └──────────────┤   (MySQL)        │◄──────────────┘
                        │   + Caching      │
                        └──────────────────┘
```

## Backend Architecture

### Основные классы

#### 1. OzonAnalyticsAPI

Главный класс для взаимодействия с API Ozon.

**Расположение**: `src/classes/OzonAnalyticsAPI.php`

**Основные методы**:

```php
// Аутентификация
public function authenticate(): string

// Получение данных воронки
public function getFunnelData($dateFrom, $dateTo, $filters = []): array

// Получение демографических данных
public function getDemographics($dateFrom, $dateTo, $filters = []): array

// Получение данных кампаний
public function getCampaignData($dateFrom, $dateTo, $campaignId = null): array

// Экспорт данных
public function exportData($dataType, $format, $filters): string
```

**Константы**:

```php
const API_BASE_URL = 'https://api-seller.ozon.ru';
const TOKEN_LIFETIME = 1800; // 30 минут
const MAX_RETRIES = 3;
const RATE_LIMIT_DELAY = 1; // секунда между запросами
```

#### 2. OzonDataCache

Система кэширования для оптимизации производительности.

**Расположение**: `src/classes/OzonDataCache.php`

**Функциональность**:

- TTL кэширование (1 час по умолчанию)
- Автоматическая очистка устаревших данных
- Поддержка различных типов данных

#### 3. OzonSecurityManager

Управление безопасностью и контролем доступа.

**Расположение**: `src/classes/OzonSecurityManager.php`

**Возможности**:

- Контроль доступа по ролям
- Rate limiting
- Аудит логирование
- IP фильтрация

### API Endpoints

**Базовый URL**: `/src/api/ozon-analytics.php`

#### GET /funnel-data

Получение данных воронки продаж.

**Параметры**:

```json
{
  "date_from": "2025-01-01",
  "date_to": "2025-01-31",
  "product_id": "optional",
  "campaign_id": "optional",
  "use_cache": true
}
```

**Ответ**:

```json
{
  "success": true,
  "data": [
    {
      "date_from": "2025-01-01",
      "date_to": "2025-01-31",
      "views": 1000,
      "cart_additions": 150,
      "orders": 45,
      "conversion_view_to_cart": 15.0,
      "conversion_cart_to_order": 30.0,
      "conversion_overall": 4.5
    }
  ]
}
```

#### GET /demographics

Получение демографических данных.

**Параметры**:

```json
{
  "date_from": "2025-01-01",
  "date_to": "2025-01-31",
  "region": "optional",
  "age_group": "optional",
  "gender": "optional"
}
```

#### POST /export-data

Экспорт данных в различных форматах.

**Тело запроса**:

```json
{
  "data_type": "funnel|demographics|campaigns",
  "format": "csv|json",
  "filters": {
    "date_from": "2025-01-01",
    "date_to": "2025-01-31"
  }
}
```

## Database Schema

### Таблицы

#### ozon_api_settings

Хранение настроек API подключения.

```sql
CREATE TABLE ozon_api_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id VARCHAR(255) NOT NULL,
    api_key_hash VARCHAR(255) NOT NULL,
    access_token TEXT,
    token_expiry TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_id (client_id),
    INDEX idx_active (is_active)
);
```

#### ozon_funnel_data

Кэширование данных воронки продаж.

```sql
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
    INDEX idx_campaign (campaign_id),
    INDEX idx_cached_at (cached_at)
);
```

#### ozon_demographics

Демографические данные покупателей.

```sql
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
```

#### ozon_campaigns

Данные рекламных кампаний.

```sql
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

## Frontend Architecture

### JavaScript компоненты

#### 1. OzonFunnelChart

Визуализация воронки продаж.

**Расположение**: `src/js/OzonFunnelChart.js`

**Основные методы**:

```javascript
class OzonFunnelChart {
    constructor(containerId, options = {})
    renderFunnel(data)
    updateData(newData)
    destroy()
}
```

#### 2. OzonAnalyticsIntegration

Интеграция с основным дашбордом.

**Расположение**: `src/js/OzonAnalyticsIntegration.js`

**Функциональность**:

- Управление фильтрами
- Загрузка данных через API
- Обработка ошибок
- Кэширование на клиенте

#### 3. OzonDemographics

Демографические дашборды.

**Расположение**: `src/js/OzonDemographics.js`

**Компоненты**:

- Возрастное распределение
- Гендерное распределение
- Географическая карта

### CSS стили

**Расположение**: `src/css/ozon-performance.css`

**Основные классы**:

```css
.ozon-analytics-view {
  /* Основной контейнер */
}
.ozon-funnel-chart {
  /* Воронка продаж */
}
.ozon-demographics {
  /* Демографические данные */
}
.ozon-loading {
  /* Индикаторы загрузки */
}
.ozon-error {
  /* Сообщения об ошибках */
}
```

## Система безопасности

### Уровни доступа

```php
class OzonSecurityManager {
    const ACCESS_LEVEL_NONE = 0;    // Нет доступа
    const ACCESS_LEVEL_READ = 1;    // Только просмотр
    const ACCESS_LEVEL_EXPORT = 2;  // Просмотр + экспорт
    const ACCESS_LEVEL_ADMIN = 3;   // Полный доступ
}
```

### Операции

```php
const OPERATION_VIEW_FUNNEL = 'view_funnel';
const OPERATION_VIEW_DEMOGRAPHICS = 'view_demographics';
const OPERATION_EXPORT_DATA = 'export_data';
const OPERATION_MANAGE_SETTINGS = 'manage_settings';
```

### Rate Limiting

- **API запросы**: 1 запрос в секунду
- **Экспорт данных**: 5 запросов в минуту
- **Настройки**: 10 запросов в минуту

## Обработка ошибок

### Типы ошибок

```php
class OzonAPIException extends Exception {
    const AUTHENTICATION_ERROR = 'AUTHENTICATION_ERROR';
    const RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    const API_UNAVAILABLE = 'API_UNAVAILABLE';
    const INVALID_PARAMETERS = 'INVALID_PARAMETERS';
    const TOKEN_EXPIRED = 'TOKEN_EXPIRED';
    const NETWORK_ERROR = 'NETWORK_ERROR';
    const JSON_ERROR = 'JSON_ERROR';
}
```

### HTTP коды ответов

- **200**: Успешный запрос
- **400**: Неверные параметры
- **401**: Ошибка аутентификации
- **429**: Превышен лимит запросов
- **503**: API недоступен

## Кэширование

### Стратегия кэширования

1. **Уровень 1**: Кэш в памяти (PHP)

   - TTL: 5 минут
   - Размер: до 100 записей

2. **Уровень 2**: База данных

   - TTL: 1 час
   - Автоматическая очистка

3. **Уровень 3**: Браузер
   - TTL: 10 минут
   - localStorage для настроек

### Ключи кэша

```php
// Формат ключей кэша
$cacheKey = "ozon_{$dataType}_{$dateFrom}_{$dateTo}_{$filterHash}";

// Примеры
"ozon_funnel_2025-01-01_2025-01-31_abc123"
"ozon_demographics_2025-01-01_2025-01-31_def456"
```

## Производительность

### Оптимизации

1. **Database**:

   - Индексы на часто используемые поля
   - Партиционирование по датам
   - Регулярная очистка старых данных

2. **API**:

   - Connection pooling
   - Retry механизм с exponential backoff
   - Batch запросы где возможно

3. **Frontend**:
   - Lazy loading компонентов
   - Виртуализация больших списков
   - Debouncing для фильтров

### Мониторинг

```php
// Метрики производительности
$metrics = [
    'api_response_time' => 'Время ответа API',
    'cache_hit_rate' => 'Процент попаданий в кэш',
    'error_rate' => 'Процент ошибок',
    'concurrent_users' => 'Одновременные пользователи'
];
```

## Логирование

### Уровни логирования

- **ERROR**: Критические ошибки
- **WARN**: Предупреждения
- **INFO**: Информационные сообщения
- **DEBUG**: Отладочная информация

### Структура логов

```json
{
  "timestamp": "2025-01-05T10:30:00Z",
  "level": "INFO",
  "component": "OzonAnalyticsAPI",
  "action": "getFunnelData",
  "user_id": "user123",
  "duration_ms": 250,
  "status": "success",
  "details": {
    "date_from": "2025-01-01",
    "date_to": "2025-01-31",
    "records_returned": 15
  }
}
```

## Развертывание

### Требования к системе

- **PHP**: 7.4+
- **MySQL**: 5.7+
- **Extensions**: curl, json, pdo_mysql
- **Memory**: минимум 256MB
- **Disk**: 1GB для кэша и логов

### Переменные окружения

```bash
# Database
DB_HOST=127.0.0.1
DB_NAME=mi_core_db
DB_USER=ingest_user
DB_PASSWORD=xK9#mQ7$vN2@pL!rT4wY

# Ozon API
OZON_CLIENT_ID=your_client_id
OZON_API_KEY=your_api_key

# Cache
CACHE_TTL=3600
CACHE_MAX_SIZE=1000

# Security
RATE_LIMIT_ENABLED=true
AUDIT_LOG_ENABLED=true
```

### Миграции

```bash
# Применение миграций
./apply_ozon_analytics_migration.sh

# Проверка миграций
python3 verify_ozon_tables.py

# Откат миграций (при необходимости)
./rollback_ozon_analytics_migration.sh
```

## Тестирование

### Типы тестов

1. **Unit Tests**: Тестирование отдельных методов
2. **Integration Tests**: Тестирование взаимодействия компонентов
3. **API Tests**: Тестирование API endpoints
4. **Performance Tests**: Нагрузочное тестирование

### Запуск тестов

```bash
# Все тесты
php run_all_tests.php

# Интеграционные тесты
php test_ozon_analytics_integration.php

# Тесты безопасности
php test_ozon_security_integration.php

# Тесты экспорта
php test_ozon_export_functionality.php
```

## Мониторинг и алерты

### Ключевые метрики

- Время ответа API Ozon
- Процент успешных запросов
- Использование кэша
- Количество активных пользователей

### Алерты

- API недоступен более 5 минут
- Процент ошибок превышает 10%
- Время ответа превышает 5 секунд
- Превышение лимитов запросов

## Troubleshooting

### Частые проблемы

1. **"Token expired"**

   - Проверить настройки API
   - Обновить токен вручную

2. **"Rate limit exceeded"**

   - Увеличить задержки между запросами
   - Использовать кэш

3. **"No data returned"**
   - Проверить период запроса
   - Проверить фильтры

### Диагностические команды

```bash
# Проверка подключения к БД
php test_db_connection.php

# Проверка API Ozon
php test_ozon_analytics_api.php

# Проверка кэша
php test_cache_functionality.php
```

## API Reference

### Полный список endpoints

| Endpoint        | Method   | Описание               |
| --------------- | -------- | ---------------------- |
| `/funnel-data`  | GET      | Данные воронки продаж  |
| `/demographics` | GET      | Демографические данные |
| `/campaigns`    | GET      | Данные кампаний        |
| `/export-data`  | POST     | Экспорт данных         |
| `/settings`     | GET/POST | Управление настройками |
| `/health`       | GET      | Проверка состояния     |
| `/cache-stats`  | GET      | Статистика кэша        |

### Коды ошибок

| Код  | Описание                | Решение                    |
| ---- | ----------------------- | -------------------------- |
| 1001 | Invalid date range      | Проверить формат дат       |
| 1002 | Missing API credentials | Настроить API ключи        |
| 1003 | Rate limit exceeded     | Уменьшить частоту запросов |
| 1004 | Data not found          | Проверить период и фильтры |

---

_Последнее обновление: Январь 2025_
_Версия: 1.0_
