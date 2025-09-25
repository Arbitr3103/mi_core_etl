# Руководство по API фильтра по странам изготовления

## Обзор

API фильтра по странам изготовления предоставляет endpoints для получения информации о странах изготовления автомобилей и фильтрации товаров ZUZ проставок по этим странам.

## Структура файлов

```
CountryFilterAPI.php          # Основной класс API
api/
├── countries.php             # Получение всех стран
├── countries-by-brand.php    # Получение стран для марки
├── countries-by-model.php    # Получение стран для модели
└── products-filter.php       # Фильтрация товаров
test_country_filter_api.php   # Тестовый скрипт
```

## API Endpoints

### 1. Получение всех стран изготовления

**Endpoint:** `GET /api/countries.php`

**Описание:** Возвращает список всех доступных стран изготовления автомобилей.

**Параметры:** Нет

**Пример запроса:**

```bash
curl -X GET "http://your-domain.com/api/countries.php"
```

**Пример ответа:**

```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Германия" },
    { "id": 2, "name": "Япония" },
    { "id": 3, "name": "США" },
    { "id": 4, "name": "Южная Корея" }
  ]
}
```

### 2. Получение стран для марки автомобиля

**Endpoint:** `GET /api/countries-by-brand.php`

**Описание:** Возвращает страны изготовления для конкретной марки автомобиля.

**Параметры:**

- `brand_id` (обязательный) - ID марки автомобиля

**Пример запроса:**

```bash
curl -X GET "http://your-domain.com/api/countries-by-brand.php?brand_id=1"
```

**Пример ответа:**

```json
{
  "success": true,
  "data": [{ "id": 1, "name": "Германия" }]
}
```

### 3. Получение стран для модели автомобиля

**Endpoint:** `GET /api/countries-by-model.php`

**Описание:** Возвращает страны изготовления для конкретной модели автомобиля.

**Параметры:**

- `model_id` (обязательный) - ID модели автомобиля

**Пример запроса:**

```bash
curl -X GET "http://your-domain.com/api/countries-by-model.php?model_id=5"
```

**Пример ответа:**

```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Германия" },
    { "id": 3, "name": "США" }
  ]
}
```

### 4. Фильтрация товаров с поддержкой страны

**Endpoint:** `GET /api/products-filter.php`

**Описание:** Фильтрует товары по различным критериям, включая страну изготовления.

**Параметры:**

- `brand_id` (опциональный) - ID марки автомобиля
- `model_id` (опциональный) - ID модели автомобиля
- `year` (опциональный) - Год выпуска автомобиля
- `country_id` (опциональный) - ID страны изготовления
- `limit` (опциональный, по умолчанию: 100) - Количество результатов
- `offset` (опциональный, по умолчанию: 0) - Смещение для пагинации

**Пример запроса:**

```bash
curl -X GET "http://your-domain.com/api/products-filter.php?brand_id=1&country_id=1&year=2020&limit=10"
```

**Пример ответа:**

```json
{
  "success": true,
  "data": [
    {
      "product_id": 1,
      "sku_ozon": "ZUZ-001",
      "sku_wb": "WB-ZUZ-001",
      "product_name": "Проставка ZUZ для BMW X5",
      "cost_price": 1500.0,
      "brand_name": "BMW",
      "model_name": "X5",
      "country_name": "Германия",
      "year_start": 2018,
      "year_end": 2023
    }
  ],
  "pagination": {
    "total": 25,
    "limit": 10,
    "offset": 0,
    "has_more": true
  },
  "filters_applied": {
    "brand_id": 1,
    "model_id": null,
    "year": 2020,
    "country_id": 1
  }
}
```

## Использование в JavaScript

### Пример загрузки всех стран

```javascript
async function loadCountries() {
  try {
    const response = await fetch("/api/countries.php");
    const data = await response.json();

    if (data.success) {
      return data.data;
    } else {
      console.error("Ошибка загрузки стран:", data.error);
      return [];
    }
  } catch (error) {
    console.error("Ошибка запроса:", error);
    return [];
  }
}
```

### Пример загрузки стран для марки

```javascript
async function loadCountriesForBrand(brandId) {
  try {
    const response = await fetch(
      `/api/countries-by-brand.php?brand_id=${brandId}`
    );
    const data = await response.json();

    if (data.success) {
      return data.data;
    } else {
      console.error("Ошибка загрузки стран для марки:", data.error);
      return [];
    }
  } catch (error) {
    console.error("Ошибка запроса:", error);
    return [];
  }
}
```

### Пример фильтрации товаров

```javascript
async function filterProducts(filters) {
  try {
    const params = new URLSearchParams();

    Object.keys(filters).forEach((key) => {
      if (
        filters[key] !== null &&
        filters[key] !== undefined &&
        filters[key] !== ""
      ) {
        params.append(key, filters[key]);
      }
    });

    const response = await fetch(
      `/api/products-filter.php?${params.toString()}`
    );
    const data = await response.json();

    if (data.success) {
      return data;
    } else {
      console.error("Ошибка фильтрации товаров:", data.error);
      return { data: [], pagination: { total: 0 } };
    }
  } catch (error) {
    console.error("Ошибка запроса:", error);
    return { data: [], pagination: { total: 0 } };
  }
}

// Пример использования
const filters = {
  brand_id: 1,
  country_id: 1,
  year: 2020,
  limit: 20,
  offset: 0,
};

filterProducts(filters).then((result) => {
  console.log("Найдено товаров:", result.data.length);
  console.log("Общее количество:", result.pagination.total);
});
```

## Обработка ошибок

Все endpoints возвращают стандартизированный формат ответа:

**Успешный ответ:**

```json
{
  "success": true,
  "data": [...]
}
```

**Ответ с ошибкой:**

```json
{
  "success": false,
  "error": "Описание ошибки"
}
```

### Типичные ошибки

- `400 Bad Request` - Некорректные параметры запроса
- `404 Not Found` - Endpoint не найден
- `405 Method Not Allowed` - Неподдерживаемый HTTP метод
- `500 Internal Server Error` - Внутренняя ошибка сервера

## Настройка базы данных

API использует следующие таблицы:

- `regions` - таблица стран/регионов
- `brands` - таблица марок автомобилей с полем `region_id`
- `car_models` - таблица моделей автомобилей
- `car_specifications` - таблица спецификаций автомобилей
- `dim_products` - таблица товаров

Убедитесь, что в файле `.env` указаны корректные настройки подключения к базе данных:

```env
DB_HOST=localhost
DB_NAME=mi_core_db
DB_USER=your_username
DB_PASSWORD=your_password
```

## Тестирование

Для тестирования API запустите:

```bash
php test_country_filter_api.php
```

Этот скрипт проверит:

- Подключение к базе данных
- Работу всех методов API
- Валидацию параметров
- Корректность возвращаемых данных

## CORS поддержка

Все endpoints поддерживают CORS и включают следующие заголовки:

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET, OPTIONS
Access-Control-Allow-Headers: Content-Type
```

## Производительность

Для оптимизации производительности рекомендуется:

1. Добавить индексы в базу данных:

```sql
CREATE INDEX idx_brands_region_id ON brands(region_id);
CREATE INDEX idx_car_models_brand_id ON car_models(brand_id);
CREATE INDEX idx_car_specifications_model_id ON car_specifications(car_model_id);
```

2. Использовать кэширование результатов на frontend
3. Ограничивать количество результатов параметром `limit`

## Безопасность

API включает следующие меры безопасности:

- Валидация всех входных параметров
- Использование prepared statements для защиты от SQL инъекций
- Ограничение методов HTTP (только GET и OPTIONS)
- Обработка и логирование ошибок

## Интеграция с существующими фильтрами

Для интеграции с существующей системой фильтрации:

1. Добавьте загрузку стран при инициализации фильтров
2. Обновляйте список доступных стран при изменении марки/модели
3. Включите `country_id` в параметры существующего API фильтрации товаров
4. Добавьте обработку сброса фильтра по стране

Пример интеграции см. в файле `frontend_examples.js`.
