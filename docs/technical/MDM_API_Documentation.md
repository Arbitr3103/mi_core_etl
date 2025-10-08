# Документация API системы MDM

## Содержание

1. [Обзор API](#обзор-api)
2. [Аутентификация](#аутентификация)
3. [Endpoints мастер-данных](#endpoints-мастер-данных)
4. [Endpoints верификации](#endpoints-верификации)
5. [Endpoints отчетов](#endpoints-отчетов)
6. [Коды ошибок](#коды-ошибок)
7. [Примеры использования](#примеры-использования)

## Обзор API

### Базовый URL

```
https://your-domain.com/api/
```

### Формат данных

- **Запросы:** JSON или form-data
- **Ответы:** JSON
- **Кодировка:** UTF-8
- **Версия API:** v1

### Общие заголовки

```http
Content-Type: application/json
Accept: application/json
Authorization: Bearer {token}
```

## Аутентификация

### Получение токена доступа

**POST** `/auth/token`

**Параметры запроса:**

```json
{
  "username": "string",
  "password": "string"
}
```

**Ответ:**

```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_at": "2024-12-31T23:59:59Z",
    "user": {
      "id": 123,
      "username": "user@example.com",
      "role": "data_manager"
    }
  }
}
```

### Обновление токена

**POST** `/auth/refresh`

**Заголовки:**

```http
Authorization: Bearer {current_token}
```

## Endpoints мастер-данных

### Получение списка мастер-товаров

**GET** `/products`

**Параметры запроса:**

- `page` (int) - номер страницы (по умолчанию: 1)
- `limit` (int) - количество записей (по умолчанию: 50, максимум: 200)
- `search` (string) - поиск по названию или бренду
- `brand` (string) - фильтр по бренду
- `category` (string) - фильтр по категории
- `status` (string) - фильтр по статусу (active, inactive, pending_review)

**Пример запроса:**

```http
GET /api/products?page=1&limit=20&brand=NIVEA&search=крем
```

**Ответ:**

```json
{
  "success": true,
  "data": {
    "products": [
      {
        "master_id": "PROD_001",
        "canonical_name": "Крем для лица NIVEA увлажняющий 50мл",
        "canonical_brand": "NIVEA",
        "canonical_category": "Косметика для лица",
        "description": "Увлажняющий крем для ежедневного ухода",
        "attributes": {
          "volume": "50мл",
          "skin_type": "все типы кожи"
        },
        "status": "active",
        "sku_count": 5,
        "created_at": "2024-01-15T10:30:00Z",
        "updated_at": "2024-02-20T14:45:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 25,
      "total_items": 1250,
      "items_per_page": 50
    }
  }
}
```

### Получение мастер-товара по ID

**GET** `/products/{master_id}`

**Ответ:**

```json
{
  "success": true,
  "data": {
    "master_id": "PROD_001",
    "canonical_name": "Крем для лица NIVEA увлажняющий 50мл",
    "canonical_brand": "NIVEA",
    "canonical_category": "Косметика для лица",
    "description": "Увлажняющий крем для ежедневного ухода",
    "attributes": {
      "volume": "50мл",
      "skin_type": "все типы кожи"
    },
    "status": "active",
    "linked_skus": [
      {
        "external_sku": "OZON_123456",
        "source": "ozon",
        "source_name": "Крем NIVEA для лица увлажняющий 50мл",
        "verification_status": "auto",
        "confidence_score": 0.95
      }
    ],
    "created_at": "2024-01-15T10:30:00Z",
    "updated_at": "2024-02-20T14:45:00Z"
  }
}
```

### Создание мастер-товара

**POST** `/products`

**Параметры запроса:**

```json
{
  "canonical_name": "Новый товар БРЕНД характеристики",
  "canonical_brand": "БРЕНД",
  "canonical_category": "Категория товара",
  "description": "Подробное описание товара",
  "attributes": {
    "key1": "value1",
    "key2": "value2"
  }
}
```

**Ответ:**

```json
{
  "success": true,
  "data": {
    "master_id": "PROD_NEW_001",
    "message": "Мастер-товар успешно создан"
  }
}
```

### Обновление мастер-товара

**PUT** `/products/{master_id}`

**Параметры запроса:**

```json
{
  "canonical_name": "Обновленное название товара",
  "description": "Обновленное описание",
  "attributes": {
    "new_attribute": "new_value"
  }
}
```

### Поиск товаров по SKU

**GET** `/products/by-sku/{external_sku}`

**Параметры запроса:**

- `source` (string) - источник SKU (ozon, wildberries, internal)

**Ответ:**

```json
{
  "success": true,
  "data": {
    "external_sku": "OZON_123456",
    "source": "ozon",
    "master_product": {
      "master_id": "PROD_001",
      "canonical_name": "Крем для лица NIVEA увлажняющий 50мл"
    },
    "mapping_info": {
      "verification_status": "auto",
      "confidence_score": 0.95,
      "created_at": "2024-01-15T10:30:00Z"
    }
  }
}
```

## Endpoints верификации

### Получение списка товаров на верификации

**GET** `/verification/pending`

**Параметры запроса:**

- `page` (int) - номер страницы
- `limit` (int) - количество записей
- `source` (string) - фильтр по источнику
- `confidence_min` (float) - минимальный процент уверенности
- `confidence_max` (float) - максимальный процент уверенности

**Ответ:**

```json
{
  "success": true,
  "data": {
    "pending_items": [
      {
        "id": 12345,
        "external_sku": "WB_789012",
        "source": "wildberries",
        "source_data": {
          "name": "Крем NIVEA увлажняющий 50мл",
          "brand": "NIVEA",
          "category": "Косметика"
        },
        "suggested_matches": [
          {
            "master_id": "PROD_001",
            "canonical_name": "Крем для лица NIVEA увлажняющий 50мл",
            "confidence_score": 0.87,
            "match_reasons": ["brand_match", "name_similarity"]
          }
        ],
        "created_at": "2024-02-20T09:15:00Z"
      }
    ],
    "pagination": {
      "current_page": 1,
      "total_pages": 5,
      "total_items": 125
    }
  }
}
```
