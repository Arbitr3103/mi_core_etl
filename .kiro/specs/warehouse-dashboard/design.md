# Design Document - Warehouse Dashboard

## Overview

Warehouse Dashboard - это система для управления остатками на складах Ozon с автоматическим расчетом потребности в пополнении. Система состоит из Backend API (PHP) и Frontend (React/TypeScript) и использует PostgreSQL для хранения данных.

## Architecture

### High-Level Architecture

```
┌─────────────────┐
│   React App     │
│   (Frontend)    │
└────────┬────────┘
         │ HTTP/JSON
         ▼
┌─────────────────┐
│   Nginx         │
│   (Reverse      │
│    Proxy)       │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│   PHP-FPM       │
│   (Backend API) │
└────────┬────────┘
         │ SQL
         ▼
┌─────────────────┐
│   PostgreSQL    │
│   (Database)    │
└─────────────────┘
```

### Component Architecture

```
Frontend (React)
├── Pages
│   └── WarehouseDashboardPage
├── Components
│   ├── WarehouseTable
│   ├── WarehouseFilters
│   ├── LiquidityBadge
│   ├── ReplenishmentIndicator
│   └── MetricsTooltip
├── Services
│   └── warehouseService
└── Types
    └── warehouse.ts

Backend (PHP)
├── Controllers
│   └── WarehouseController
├── Services
│   ├── WarehouseService
│   ├── SalesAnalyticsService
│   └── ReplenishmentCalculator
├── Models
│   ├── Warehouse
│   ├── InventoryItem
│   └── SalesData
└── Routes
    └── warehouse.php
```

## Data Models

### Database Schema Extensions

#### Расширение таблицы `inventory`

Добавляем поля для метрик Ozon:

```sql
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS preparing_for_sale INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS in_supply_requests INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS in_transit INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS in_inspection INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS returning_from_customers INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS expiring_soon INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS defective INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS excess_from_supply INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS awaiting_upd INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS preparing_for_removal INTEGER DEFAULT 0;
ALTER TABLE inventory ADD COLUMN IF NOT EXISTS cluster VARCHAR(100);
```

#### Новая таблица `warehouse_sales_metrics`

Кэш для расчетных метрик (обновляется раз в час):

```sql
CREATE TABLE IF NOT EXISTS warehouse_sales_metrics (
    id SERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL REFERENCES dim_products(id),
    warehouse_name VARCHAR(255) NOT NULL,
    source marketplace_source NOT NULL,

    -- Метрики продаж
    daily_sales_avg DECIMAL(10, 2) DEFAULT 0,
    sales_last_28_days INTEGER DEFAULT 0,
    days_with_stock INTEGER DEFAULT 0,
    days_without_sales INTEGER DEFAULT 0,

    -- Метрики ликвидности
    days_of_stock DECIMAL(10, 2),
    liquidity_status VARCHAR(50),

    -- Потребность в пополнении
    target_stock INTEGER DEFAULT 0,
    replenishment_need INTEGER DEFAULT 0,

    -- Метаданные
    calculated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,

    UNIQUE(product_id, warehouse_name, source)
);

CREATE INDEX idx_warehouse_metrics_product ON warehouse_sales_metrics(product_id);
CREATE INDEX idx_warehouse_metrics_warehouse ON warehouse_sales_metrics(warehouse_name);
CREATE INDEX idx_warehouse_metrics_liquidity ON warehouse_sales_metrics(liquidity_status);
CREATE INDEX idx_warehouse_metrics_calculated ON warehouse_sales_metrics(calculated_at);
```

### TypeScript Types

```typescript
// Warehouse item with all metrics
export interface WarehouseItem {
    // Product info
    product_id: number;
    sku: string;
    name: string;

    // Warehouse info
    warehouse_name: string;
    cluster: string;

    // Current stock
    available: number;
    reserved: number;

    // Ozon metrics
    preparing_for_sale: number;
    in_supply_requests: number;
    in_transit: number;
    in_inspection: number;
    returning_from_customers: number;
    expiring_soon: number;
    defective: number;
    excess_from_supply: number;
    awaiting_upd: number;
    preparing_for_removal: number;

    // Sales metrics
    daily_sales_avg: number;
    sales_last_28_days: number;
    days_without_sales: number;

    // Liquidity metrics
    days_of_stock: number | null;
    liquidity_status: "critical" | "low" | "normal" | "excess";

    // Replenishment
    target_stock: number;
    replenishment_need: number;

    // Metadata
    last_updated: string;
}

// Dashboard response
export interface WarehouseDashboardData {
    warehouses: WarehouseGroup[];
    summary: DashboardSummary;
    filters_applied: FilterState;
    last_updated: string;
}

export interface WarehouseGroup {
    warehouse_name: string;
    cluster: string;
    items: WarehouseItem[];
    totals: WarehouseTotals;
}

export interface DashboardSummary {
    total_products: number;
    active_products: number;
    total_replenishment_need: number;
    by_liquidity: {
        critical: number;
        low: number;
        normal: number;
        excess: number;
    };
}

export interface FilterState {
    warehouse?: string;
    cluster?: string;
    liquidity_status?: string;
    active_only: boolean;
    has_replenishment_need?: boolean;
}
```

## Components and Interfaces

### Backend API

#### Endpoint: `GET /api/warehouse/dashboard`

**Query Parameters:**

-   `warehouse` (optional) - фильтр по складу
-   `cluster` (optional) - фильтр по кластеру
-   `liquidity_status` (optional) - фильтр по статусу ликвидности
-   `active_only` (optional, default: true) - только активные товары
-   `has_replenishment_need` (optional) - только товары с потребностью в пополнении
-   `sort_by` (optional) - поле для сортировки
-   `sort_order` (optional) - направление сортировки (asc/desc)

**Response:**

```json
{
  "success": true,
  "data": {
    "warehouses": [
      {
        "warehouse_name": "АДЫГЕЙСК_РФЦ",
        "cluster": "Юг",
        "items": [...],
        "totals": {
          "total_items": 50,
          "total_available": 1000,
          "total_replenishment_need": 500
        }
      }
    ],
    "summary": {
      "total_products": 271,
      "active_products": 180,
      "total_replenishment_need": 5000,
      "by_liquidity": {
        "critical": 15,
        "low": 30,
        "normal": 120,
        "excess": 15
      }
    },
    "filters_applied": {...},
    "last_updated": "2025-10-22T12:00:00Z"
  }
}
```

#### Endpoint: `GET /api/warehouse/export`

Экспорт данных в CSV с теми же параметрами фильтрации.

**Response:** CSV file

### Backend Services

#### WarehouseService

Основной сервис для работы с данными складов.

```php
class WarehouseService {
    public function getDashboardData(array $filters): array;
    public function getWarehouseList(): array;
    public function getClusterList(): array;
    public function exportToCSV(array $filters): string;
}
```

#### SalesAnalyticsService

Сервис для расчета метрик продаж.

```php
class SalesAnalyticsService {
    public function calculateDailySalesAvg(int $productId, string $warehouse, int $days = 28): float;
    public function getDaysWithoutSales(int $productId, string $warehouse): int;
    public function getSalesLast28Days(int $productId, string $warehouse): int;
    public function getDaysWithStock(int $productId, string $warehouse, int $days = 28): int;
}
```

#### ReplenishmentCalculator

Сервис для расчета потребности в пополнении.

```php
class ReplenishmentCalculator {
    public function calculateTargetStock(float $dailySalesAvg, int $daysOfSupply = 30): int;
    public function calculateReplenishmentNeed(
        int $targetStock,
        int $available,
        int $inTransit,
        int $inSupplyRequests
    ): int;
    public function calculateDaysOfStock(int $available, float $dailySalesAvg): ?float;
    public function determineLiquidityStatus(float $daysOfStock): string;
}
```

### Frontend Components

#### WarehouseDashboardPage

Главная страница дашборда.

```typescript
export const WarehouseDashboardPage: React.FC = () => {
    const [filters, setFilters] = useState<FilterState>({
        active_only: true,
    });
    const [sortConfig, setSortConfig] = useState<SortConfig>({
        field: "replenishment_need",
        order: "desc",
    });

    const { data, isLoading, error, refetch } = useWarehouseDashboard(filters);

    return (
        <div>
            <DashboardHeader summary={data?.summary} onRefresh={refetch} />
            <WarehouseFilters filters={filters} onChange={setFilters} />
            <WarehouseTable
                data={data?.warehouses}
                sortConfig={sortConfig}
                onSort={setSortConfig}
            />
        </div>
    );
};
```

#### WarehouseTable

Таблица с данными по складам.

```typescript
interface WarehouseTableProps {
    data: WarehouseGroup[];
    sortConfig: SortConfig;
    onSort: (config: SortConfig) => void;
}

export const WarehouseTable: React.FC<WarehouseTableProps> = ({
    data,
    sortConfig,
    onSort,
}) => {
    return (
        <table>
            <thead>
                <tr>
                    <th onClick={() => onSort({ field: "name", order: "asc" })}>
                        Товар
                    </th>
                    <th
                        onClick={() =>
                            onSort({ field: "warehouse_name", order: "asc" })
                        }
                    >
                        Склад
                    </th>
                    <th
                        onClick={() =>
                            onSort({ field: "available", order: "desc" })
                        }
                    >
                        Доступно
                    </th>
                    <th
                        onClick={() =>
                            onSort({ field: "daily_sales_avg", order: "desc" })
                        }
                    >
                        Продажи/день
                    </th>
                    <th
                        onClick={() =>
                            onSort({ field: "days_of_stock", order: "asc" })
                        }
                    >
                        Дней запаса
                    </th>
                    <th
                        onClick={() =>
                            onSort({
                                field: "replenishment_need",
                                order: "desc",
                            })
                        }
                    >
                        Нужно заказать
                    </th>
                    <th>Статус</th>
                </tr>
            </thead>
            <tbody>
                {data.map((warehouse) => (
                    <WarehouseGroupRow
                        key={warehouse.warehouse_name}
                        group={warehouse}
                    />
                ))}
            </tbody>
        </table>
    );
};
```

#### LiquidityBadge

Бейдж статуса ликвидности.

```typescript
interface LiquidityBadgeProps {
    status: "critical" | "low" | "normal" | "excess";
    daysOfStock: number | null;
}

export const LiquidityBadge: React.FC<LiquidityBadgeProps> = ({
    status,
    daysOfStock,
}) => {
    const colors = {
        critical: "bg-red-100 text-red-800",
        low: "bg-yellow-100 text-yellow-800",
        normal: "bg-green-100 text-green-800",
        excess: "bg-blue-100 text-blue-800",
    };

    const labels = {
        critical: "Дефицит",
        low: "Низкий",
        normal: "Норма",
        excess: "Избыток",
    };

    return (
        <span className={`badge ${colors[status]}`}>
            {labels[status]}
            {daysOfStock !== null && ` (${daysOfStock.toFixed(0)} дн.)`}
        </span>
    );
};
```

#### ReplenishmentIndicator

Индикатор потребности в пополнении.

```typescript
interface ReplenishmentIndicatorProps {
    need: number;
    targetStock: number;
}

export const ReplenishmentIndicator: React.FC<ReplenishmentIndicatorProps> = ({
    need,
    targetStock,
}) => {
    if (need <= 0) {
        return <span className="text-green-600">✓ Достаточно</span>;
    }

    const percentage = (need / targetStock) * 100;
    const isUrgent = percentage > 50;

    return (
        <div
            className={isUrgent ? "text-red-600 font-bold" : "text-orange-600"}
        >
            {need} шт.
            {isUrgent && " 🔥"}
        </div>
    );
};
```

#### WarehouseFilters

Панель фильтров.

```typescript
interface WarehouseFiltersProps {
    filters: FilterState;
    onChange: (filters: FilterState) => void;
}

export const WarehouseFilters: React.FC<WarehouseFiltersProps> = ({
    filters,
    onChange,
}) => {
    const { data: warehouses } = useWarehouses();
    const { data: clusters } = useClusters();

    return (
        <div className="filters">
            <Select
                label="Склад"
                options={warehouses}
                value={filters.warehouse}
                onChange={(value) => onChange({ ...filters, warehouse: value })}
            />
            <Select
                label="Кластер"
                options={clusters}
                value={filters.cluster}
                onChange={(value) => onChange({ ...filters, cluster: value })}
            />
            <Select
                label="Статус ликвидности"
                options={[
                    { value: "critical", label: "Дефицит" },
                    { value: "low", label: "Низкий" },
                    { value: "normal", label: "Норма" },
                    { value: "excess", label: "Избыток" },
                ]}
                value={filters.liquidity_status}
                onChange={(value) =>
                    onChange({ ...filters, liquidity_status: value })
                }
            />
            <Checkbox
                label="Только активные товары"
                checked={filters.active_only}
                onChange={(checked) =>
                    onChange({ ...filters, active_only: checked })
                }
            />
            <Checkbox
                label="Только с потребностью в пополнении"
                checked={filters.has_replenishment_need}
                onChange={(checked) =>
                    onChange({ ...filters, has_replenishment_need: checked })
                }
            />
        </div>
    );
};
```

## Error Handling

### Backend

```php
try {
    $data = $warehouseService->getDashboardData($filters);
    return $this->success($data);
} catch (DatabaseException $e) {
    $this->logger->error('Database error in warehouse dashboard', [
        'error' => $e->getMessage()
    ]);
    return $this->error('Database error', 500);
} catch (ValidationException $e) {
    return $this->error($e->getMessage(), 400);
} catch (Exception $e) {
    $this->logger->error('Unexpected error in warehouse dashboard', [
        'error' => $e->getMessage()
    ]);
    return $this->error('Internal server error', 500);
}
```

### Frontend

```typescript
const { data, isLoading, error } = useWarehouseDashboard(filters);

if (isLoading) {
    return <LoadingSpinner />;
}

if (error) {
    return (
        <ErrorMessage message="Не удалось загрузить данные" onRetry={refetch} />
    );
}

if (!data || data.warehouses.length === 0) {
    return <EmptyState message="Нет данных для отображения" />;
}
```

## Testing Strategy

### Backend Tests

1. **Unit Tests** (PHPUnit):

    - `SalesAnalyticsServiceTest` - тесты расчета метрик продаж
    - `ReplenishmentCalculatorTest` - тесты расчета пополнения
    - `WarehouseServiceTest` - тесты бизнес-логики

2. **Integration Tests**:
    - Тесты API endpoints
    - Тесты SQL запросов

### Frontend Tests

1. **Unit Tests** (Vitest):

    - Тесты компонентов
    - Тесты утилит расчета

2. **Integration Tests**:
    - Тесты взаимодействия компонентов
    - Тесты API сервисов

## Performance Considerations

### Database Optimization

1. **Индексы**:

    - Индекс на `(product_id, warehouse_name, source)` в `inventory`
    - Индекс на `order_date` в `fact_orders`
    - Индекс на `liquidity_status` в `warehouse_sales_metrics`

2. **Кэширование**:

    - Таблица `warehouse_sales_metrics` обновляется раз в час
    - Redis кэш для часто запрашиваемых данных (TTL: 5 минут)

3. **Пагинация**:
    - Лимит 100 товаров на страницу
    - Lazy loading при скролле

### Frontend Optimization

1. **Code Splitting**:

    - Lazy loading компонента WarehouseDashboard
    - Отдельный chunk для таблицы

2. **Memoization**:

    - `React.memo` для компонентов строк таблицы
    - `useMemo` для расчетов

3. **Virtualization**:
    - Виртуализация таблицы при > 50 строках

## Security

1. **Authentication**: Используется существующая система аутентификации
2. **Authorization**: Проверка прав доступа к данным клиента
3. **Input Validation**: Валидация всех параметров запроса
4. **SQL Injection Prevention**: Использование prepared statements
5. **XSS Prevention**: Санитизация всех выводимых данных

## Deployment

1. **Backend**: Загрузка PHP файлов на сервер
2. **Frontend**: Сборка и загрузка через `./deployment/upload_frontend.sh`
3. **Database**: Миграции через SQL скрипты
4. **Cache**: Первоначальный расчет метрик

---

## Summary

Дизайн предусматривает расширение существующей схемы БД, создание новых API endpoints и React компонентов для отображения данных по складам с расчетом потребности в пополнении. Система оптимизирована для производительности через кэширование и индексы.
