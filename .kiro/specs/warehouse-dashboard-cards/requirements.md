# Requirements Document

## Introduction

Создание интерактивного дашборда складов с карточным представлением для мониторинга статусов всех складов в системе Market MI. Дашборд будет отображать ключевые метрики и статусы складов в удобном визуальном формате.

## Glossary

-   **Warehouse Dashboard**: Веб-интерфейс для мониторинга складов
-   **API Endpoint**: REST API точка доступа к данным складов
-   **Card Layout**: Карточное представление данных
-   **Status Indicator**: Визуальный индикатор статуса склада

## Requirements

### Requirement 1

**User Story:** Как менеджер склада, я хочу видеть общую сводку по всем складам в виде карточек, чтобы быстро оценить текущее состояние системы

#### Acceptance Criteria

1. WHEN пользователь открывает URL /warehouse-dashboard/, THE Warehouse Dashboard SHALL отобразить главную страницу с карточками складов
2. THE Warehouse Dashboard SHALL получить данные из API endpoint warehouse_dashboard_api.php с параметром endpoint=summary
3. THE Warehouse Dashboard SHALL отобразить общие метрики в виде статистических карточек
4. THE Warehouse Dashboard SHALL использовать адаптивный дизайн для корректного отображения на разных устройствах

### Requirement 2

**User Story:** Как пользователь системы, я хочу видеть ключевые метрики складов в наглядном формате, чтобы быстро понимать общее состояние

#### Acceptance Criteria

1. THE Warehouse Dashboard SHALL отобразить общее количество складов
2. THE Warehouse Dashboard SHALL показать количество уникальных товаров
3. THE Warehouse Dashboard SHALL отобразить общий объем запасов
4. THE Warehouse Dashboard SHALL показать данные о продажах за 28 дней
5. THE Warehouse Dashboard SHALL отобразить среднедневные продажи

### Requirement 3

**User Story:** Как аналитик, я хочу видеть статусы товаров по категориям, чтобы принимать решения о пополнении запасов

#### Acceptance Criteria

1. THE Warehouse Dashboard SHALL отобразить количество активных товаров
2. THE Warehouse Dashboard SHALL показать количество неактивных товаров
3. THE Warehouse Dashboard SHALL отобразить критические товары
4. THE Warehouse Dashboard SHALL показать товары требующие внимания
5. THE Warehouse Dashboard SHALL отобразить избыточные товары
