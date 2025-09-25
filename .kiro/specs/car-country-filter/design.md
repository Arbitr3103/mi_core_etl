# Design Document

## Overview

Данный дизайн описывает реализацию фильтра по стране изготовления автомобиля для системы ZUZ проставок. Фильтр будет интегрирован с существующими фильтрами (марка, модель, год выпуска) и использовать уже имеющуюся в системе таблицу `regions` для хранения информации о странах.

## Architecture

### Существующая архитектура данных

Система уже имеет следующую структуру данных:

- `regions` - таблица стран/регионов
- `brands` - таблица марок автомобилей с полем `region_id`
- `car_models` - таблица моделей автомобилей с полем `brand_id`
- `car_specifications` - таблица спецификаций с полем `car_model_id`

### Архитектура фильтрации

```
Frontend (JavaScript)
    ↓
API Endpoints (PHP)
    ↓
Database Queries (MySQL)
    ↓
Cached Results (Optional)
```

### Схема данных

```
regions (страны)
    ↓ region_id
brands (марки)
    ↓ brand_id
car_models (модели)
    ↓ car_model_id
car_specifications (спецификации)
```

## Components and Interfaces

### 1. Frontend Components

#### CountryFilter Component

```javascript
class CountryFilter {
  constructor(containerId, onChangeCallback) {
    this.container = document.getElementById(containerId);
    this.onChange = onChangeCallback;
    this.countries = [];
    this.selectedCountry = null;
  }

  async loadCountries(brandId = null, modelId = null) {
    // Загружает доступные страны для выбранной марки/модели
  }

  render() {
    // Отрисовывает select элемент с опциями стран
  }

  getSelectedCountry() {
    // Возвращает выбранную страну
  }

  reset() {
    // Сбрасывает выбор
  }
}
```

#### FilterManager (расширение существующего)

```javascript
class FilterManager {
  // Существующие методы...

  onCountryChange(countryId) {
    this.selectedCountry = countryId;
    this.applyFilters();
  }

  applyFilters() {
    // Применяет все фильтры включая страну
    const filters = {
      brand: this.selectedBrand,
      model: this.selectedModel,
      year: this.selectedYear,
      country: this.selectedCountry,
    };
    this.loadProducts(filters);
  }
}
```

### 2. Backend API Endpoints

#### GET /api/countries

Возвращает список всех доступных стран изготовления.

**Response:**

```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Германия" },
    { "id": 2, "name": "Япония" },
    { "id": 3, "name": "США" }
  ]
}
```

#### GET /api/countries/by-brand/{brandId}

Возвращает страны для конкретной марки.

**Response:**

```json
{
  "success": true,
  "data": [{ "id": 1, "name": "Германия" }]
}
```

#### GET /api/countries/by-model/{modelId}

Возвращает страны для конкретной модели.

#### GET /api/products/filter

Расширенный endpoint для фильтрации товаров.

**Parameters:**

- `brand_id` (optional)
- `model_id` (optional)
- `year` (optional)
- `country_id` (optional)

### 3. Database Layer

#### Новые SQL запросы

**Получение стран для марки:**

```sql
SELECT DISTINCT r.id, r.name
FROM regions r
JOIN brands b ON r.id = b.region_id
WHERE b.id = ?
```

**Получение стран для модели:**

```sql
SELECT DISTINCT r.id, r.name
FROM regions r
JOIN brands b ON r.id = b.region_id
JOIN car_models cm ON b.id = cm.brand_id
WHERE cm.id = ?
```

**Фильтрация товаров с учетом страны:**

```sql
SELECT p.*, b.name as brand_name, cm.name as model_name, r.name as country_name
FROM products p
JOIN car_specifications cs ON p.specification_id = cs.id
JOIN car_models cm ON cs.car_model_id = cm.id
JOIN brands b ON cm.brand_id = b.id
JOIN regions r ON b.region_id = r.id
WHERE 1=1
  AND (? IS NULL OR b.id = ?)
  AND (? IS NULL OR cm.id = ?)
  AND (? IS NULL OR cs.year_start <= ? AND cs.year_end >= ?)
  AND (? IS NULL OR r.id = ?)
```

## Data Models

### Region Model (существующая)

```php
class Region {
    public $id;
    public $name;

    public static function getAll() {
        // Возвращает все регионы
    }

    public static function getByBrand($brandId) {
        // Возвращает регионы для марки
    }

    public static function getByModel($modelId) {
        // Возвращает регионы для модели
    }
}
```

### Filter Model (новая)

```php
class CarFilter {
    public $brandId;
    public $modelId;
    public $year;
    public $countryId;

    public function validate() {
        // Валидация параметров фильтра
    }

    public function buildQuery() {
        // Строит SQL запрос с учетом всех фильтров
    }
}
```

## Error Handling

### Frontend Error Handling

- Показ сообщения "Ошибка загрузки стран" при сбое API
- Отключение фильтра при отсутствии данных
- Graceful degradation - работа без фильтра по стране

### Backend Error Handling

- Валидация входных параметров
- Обработка ошибок базы данных
- Логирование ошибок для отладки
- Возврат понятных сообщений об ошибках

### Database Error Handling

- Обработка отсутствующих связей (LEFT JOIN)
- Индексы для оптимизации производительности
- Проверка целостности данных

## Testing Strategy

### Unit Tests

1. **Frontend Tests**

   - Тестирование CountryFilter компонента
   - Тестирование интеграции с FilterManager
   - Тестирование обработки ошибок

2. **Backend Tests**

   - Тестирование API endpoints
   - Тестирование валидации параметров
   - Тестирование SQL запросов

3. **Database Tests**
   - Тестирование производительности запросов
   - Тестирование целостности данных
   - Тестирование индексов

### Integration Tests

1. **End-to-End Tests**

   - Полный цикл фильтрации от выбора страны до отображения результатов
   - Тестирование комбинации всех фильтров
   - Тестирование мобильной версии

2. **Performance Tests**
   - Время загрузки фильтра
   - Время применения фильтров
   - Нагрузочное тестирование API

### Manual Testing

1. **User Experience Testing**
   - Удобство использования фильтра
   - Интуитивность интерфейса
   - Тестирование на разных устройствах

## Implementation Phases

### Phase 1: Backend API

- Создание API endpoints для стран
- Расширение существующего API фильтрации
- Тестирование API

### Phase 2: Frontend Integration

- Создание CountryFilter компонента
- Интеграция с существующими фильтрами
- Стилизация под существующий дизайн

### Phase 3: Testing & Optimization

- Комплексное тестирование
- Оптимизация производительности
- Исправление найденных проблем

### Phase 4: Deployment

- Развертывание на тестовом сервере
- Пользовательское тестирование
- Развертывание на продакшене

## Performance Considerations

### Database Optimization

- Индексы на `brands.region_id`
- Кэширование результатов запросов стран
- Оптимизация JOIN запросов

### Frontend Optimization

- Ленивая загрузка списка стран
- Кэширование в localStorage
- Дебаунсинг при быстрых изменениях фильтров

### Caching Strategy

- Кэширование списка стран на 1 час
- Кэширование результатов фильтрации на 15 минут
- Инвалидация кэша при обновлении данных

## Security Considerations

### Input Validation

- Валидация всех параметров фильтра
- Защита от SQL инъекций через prepared statements
- Санитизация пользовательского ввода

### Access Control

- Проверка прав доступа к API
- Rate limiting для предотвращения злоупотреблений
- Логирование подозрительной активности

## Monitoring and Analytics

### Metrics to Track

- Использование фильтра по странам
- Популярные комбинации фильтров
- Время отклика API
- Ошибки и их частота

### Logging

- Логирование всех API запросов
- Логирование ошибок с контекстом
- Мониторинг производительности запросов
