# Requirements Document

## Introduction

Система региональной аналитики продаж предназначена для анализа продаж продукции бренда ЭТОНОВО компании ТД Манхэттен через маркетплейсы Ozon и Wildberries. На первом этапе система работает с существующими данными о продажах по маркетплейсам, на втором этапе интегрируется с Ozon API для получения детальной региональной разбивки. Система должна предоставлять информацию о географическом распределении продаж и помогать в принятии решений по развитию бизнеса.

## Glossary

- **Regional_Analytics_System**: Система региональной аналитики продаж
- **Sales_Data**: Данные о продажах из таблицы fact_orders
- **Marketplace_Analytics**: Аналитика по маркетплейсам как базовый уровень группировки
- **Ozon_API_Integration**: Интеграция с Ozon Seller API для получения региональных данных
- **Marketplace**: Торговая площадка (Ozon, Wildberries)
- **Brand_ETONOVO**: Продукция бренда ЭТОНОВО (существующие 100+ товаров)
- **TD_Manhattan**: Торговый дом Манхэттен (клиент системы, client_id=1)
- **Dashboard**: Панель управления для отображения аналитики
- **API_Endpoint**: Конечная точка API для получения данных

## Requirements

### Requirement 1

**User Story:** Как аналитик компании ТД Манхэттен, я хочу видеть текущие данные о продажах бренда ЭТОНОВО по маркетплейсам, чтобы понимать эффективность каждого канала продаж.

#### Acceptance Criteria

1. WHEN пользователь запрашивает аналитику продаж, THE Regional_Analytics_System SHALL отобразить данные по Ozon и Wildberries отдельно
2. THE Regional_Analytics_System SHALL показывать количество заказов, общую выручку и среднюю цену по каждому маркетплейсу
3. THE Regional_Analytics_System SHALL отображать данные за выбранный период времени
4. THE Regional_Analytics_System SHALL показывать топ-10 товаров по выручке для каждого маркетплейса
5. THE Regional_Analytics_System SHALL предоставлять данные в виде таблицы и графиков

### Requirement 2

**User Story:** Как менеджер по продажам, я хочу сравнивать эффективность продаж через разные маркетплейсы, чтобы оптимизировать стратегию продвижения.

#### Acceptance Criteria

1. WHEN пользователь выбирает сравнение маркетплейсов, THE Regional_Analytics_System SHALL отобразить сравнительную таблицу Ozon vs Wildberries
2. THE Regional_Analytics_System SHALL показывать долю каждого маркетплейса в общих продажах
3. THE Regional_Analytics_System SHALL выделять товары с наибольшими различиями в эффективности по маркетплейсам
4. THE Regional_Analytics_System SHALL показывать разницу в средних ценах между маркетплейсами

### Requirement 3

**User Story:** Как руководитель отдела развития, я хочу видеть динамику роста продаж по маркетплейсам, чтобы выявлять перспективные направления для инвестиций.

#### Acceptance Criteria

1. WHEN пользователь запрашивает анализ динамики, THE Regional_Analytics_System SHALL показывать изменение объемов продаж по месяцам для каждого маркетплейса
2. THE Regional_Analytics_System SHALL рассчитывать темпы роста продаж по маркетплейсам
3. THE Regional_Analytics_System SHALL выделять маркетплейсы с наибольшим потенциалом роста
4. THE Regional_Analytics_System SHALL показывать сезонные тренды продаж

### Requirement 4

**User Story:** Как маркетолог, я хочу анализировать популярность конкретных товаров ЭТОНОВО на разных маркетплейсах, чтобы адаптировать ассортимент под особенности каждой площадки.

#### Acceptance Criteria

1. WHEN пользователь выбирает анализ по товарам, THE Regional_Analytics_System SHALL отображать топ-продукты для каждого маркетплейса
2. THE Regional_Analytics_System SHALL показывать различия в популярности товаров между Ozon и Wildberries
3. THE Regional_Analytics_System SHALL предоставлять возможность фильтрации по категориям товаров
4. THE Regional_Analytics_System SHALL выделять товары, которые продаются только на одном маркетплейсе

### Requirement 5

**User Story:** Как системный администратор, я хочу настроить интеграцию с Ozon API для получения детальных региональных данных, чтобы расширить аналитику до уровня регионов России.

#### Acceptance Criteria

1. THE Ozon_API_Integration SHALL подключаться к Ozon Seller API с использованием Client-Id и Api-Key
2. THE Ozon_API_Integration SHALL запрашивать данные через endpoint /v1/analytics/data с фильтрами по датам и бренду ЭТОНОВО
3. THE Regional_Analytics_System SHALL сохранять полученные региональные данные в отдельную таблицу
4. THE Regional_Analytics_System SHALL обрабатывать региональные данные и отображать их в дашборде
5. THE Ozon_API_Integration SHALL выполнять инкрементальные обновления данных ежедневно

### Requirement 6

**User Story:** Как системный администратор, я хочу иметь API для получения аналитических данных, чтобы интегрировать систему с другими инструментами компании.

#### Acceptance Criteria

1. THE Regional_Analytics_System SHALL предоставлять RESTful API для получения данных о продажах
2. THE API_Endpoint SHALL поддерживать фильтрацию по датам, маркетплейсам и товарам
3. THE API_Endpoint SHALL возвращать данные в формате JSON
4. THE Regional_Analytics_System SHALL обеспечивать аутентификацию доступа к API
5. THE API_Endpoint SHALL поддерживать пагинацию для больших объемов данных
