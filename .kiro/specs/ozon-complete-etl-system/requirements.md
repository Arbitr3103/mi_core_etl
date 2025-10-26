# Requirements Document - Ozon Complete ETL System

## Introduction

Система ежедневной выгрузки и синхронизации всех необходимых данных от Ozon API для полноценного управленческого дашборда складов.

## Glossary

-   **ETL_System**: Система извлечения, трансформации и загрузки данных от Ozon API
-   **Ozon_API**: API платформы Ozon для получения данных о товарах, продажах и остатках
-   **Product_Catalog**: Справочник товаров с основными идентификаторами
-   **Sales_History**: История продаж для расчета скорости продаж (ADS)
-   **Warehouse_Stock**: Актуальные остатки товаров по складам
-   **Manager_Dashboard**: Управленческий дашборд для принятия решений по складам

## Requirements

### Requirement 1: Справочник товаров

**User Story:** Как менеджер склада, я хочу иметь актуальный справочник всех наших товаров с их идентификаторами, чтобы понимать что мы продаем и связывать данные между системами.

#### Acceptance Criteria

1. THE ETL_System SHALL extract product catalog data from Ozon API endpoint `/v2/product/list` daily
2. THE ETL_System SHALL store product_id, offer_id, name, fbo_sku, and fbs_sku for each product
3. THE ETL_System SHALL update existing products and insert new products in dim_products table
4. THE ETL_System SHALL use offer_id as primary key for linking all data across systems
5. THE ETL_System SHALL log all product synchronization activities with timestamps

### Requirement 2: История продаж

**User Story:** Как менеджер склада, я хочу знать скорость продаж каждого товара за последние 30 дней, чтобы планировать пополнение запасов и избегать затоваривания.

#### Acceptance Criteria

1. THE ETL_System SHALL extract sales history from Ozon API endpoint `/v2/posting/fbo/list` daily
2. THE ETL_System SHALL collect posting_number, in_process_at, and products array for each order
3. THE ETL_System SHALL extract sku (offer_id) and quantity for each product in orders
4. THE ETL_System SHALL store sales data in fact_orders table linked by offer_id
5. THE ETL_System SHALL process sales data for the last 30 days to enable ADS calculations

### Requirement 3: Остатки по складам

**User Story:** Как менеджер склада, я хочу видеть точные остатки каждого товара на каждом складе в реальном времени, чтобы принимать решения о перемещении товаров и планировании поставок.

#### Acceptance Criteria

1. THE ETL_System SHALL request warehouse stock report via `/v1/report/warehouse/stock` API daily
2. WHEN report is requested, THE ETL_System SHALL poll `/v1/report/info` until report status becomes "success"
3. THE ETL_System SHALL download and parse CSV report containing SKU, warehouse name, and stock quantities
4. THE ETL_System SHALL store offer_id, warehouse_name, present, reserved quantities in inventory table
5. THE ETL_System SHALL completely refresh inventory table with each update to ensure data accuracy

### Requirement 4: Система мониторинга и логирования

**User Story:** Как системный администратор, я хочу отслеживать работу ETL процессов и получать уведомления о проблемах, чтобы обеспечить надежность системы.

#### Acceptance Criteria

1. THE ETL_System SHALL log all operations with timestamps and status information
2. WHEN ETL process fails, THE ETL_System SHALL send alert notifications to administrators
3. THE ETL_System SHALL track processing metrics including record counts and execution time
4. THE ETL_System SHALL provide health check endpoint for monitoring system status
5. THE ETL_System SHALL retain logs for at least 30 days for troubleshooting purposes

### Requirement 5: Планировщик задач

**User Story:** Как системный администратор, я хочу чтобы ETL процессы выполнялись автоматически по расписанию, чтобы данные всегда были актуальными без ручного вмешательства.

#### Acceptance Criteria

1. THE ETL_System SHALL execute product synchronization daily at 02:00 Moscow time
2. THE ETL_System SHALL execute sales data extraction daily at 03:00 Moscow time
3. THE ETL_System SHALL execute inventory update daily at 04:00 Moscow time
4. THE ETL_System SHALL implement retry mechanism for failed API calls with exponential backoff
5. THE ETL_System SHALL prevent concurrent execution of the same ETL process
