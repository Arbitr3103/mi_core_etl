# Дизайн рефакторинга проекта mi_core_etl с переходом на React

## Обзор

Комплексный рефакторинг проекта mi_core_etl включает переход от устаревшей PHP/HTML архитектуры к современному стеку с React frontend, оптимизированным PHP backend и автоматизированными DevOps процессами. Проект направлен на устранение технического долга и создание масштабируемой архитектуры.

## Архитектура

### Текущее состояние (AS-IS)

```
┌─────────────────┐    ┌──────────────┐    ┌─────────────────┐
│   ETL Scripts   │───▶│  MySQL DB    │───▶│  PHP Dashboard  │
│   (Смешанный)   │    │  mi_core_db  │    │  (Устаревший)   │
└─────────────────┘    └──────────────┘    └─────────────────┘
         │                       │                    │
         ▼                       ▼                    ▼
   ┌──────────┐           ┌─────────────┐      ┌──────────────┐
   │ Cron Jobs│           │ PHP API     │      │ Мусорные     │
   │ (Хаос)   │           │ (Частично)  │      │ файлы        │
   └──────────┘           └─────────────┘      └──────────────┘
```

### Целевая архитектура (TO-BE)

```
┌─────────────────┐    ┌──────────────┐    ┌─────────────────┐
│   ETL Pipeline  │───▶│  MySQL DB    │───▶│   React App     │
│   (Структурир.) │    │ (Оптимизир.) │    │  (TypeScript)   │
└─────────────────┘    └──────────────┘    └─────────────────┘
         │                       │                    │
         ▼                       ▼                    ▼
   ┌──────────┐           ┌─────────────┐      ┌──────────────┐
   │Automated │           │ REST API    │      │    Nginx     │
   │Deployment│           │ (Unified)   │      │  (Статика)   │
   └──────────┘           └─────────────┘      └──────────────┘
         │                       │                    │
         ▼                       ▼                    ▼
   ┌──────────┐           ┌─────────────┐      ┌──────────────┐
   │Monitoring│           │ Logging     │      │   Build      │
   │& Alerts  │           │ System      │      │ Automation   │
   └──────────┘           └─────────────┘      └──────────────┘
```

## Компоненты и интерфейсы

### 1. React Frontend Architecture

#### 1.1 Технологический стек

- **React 18+** с TypeScript для типобезопасности
- **Vite** для быстрой разработки и сборки
- **TanStack Query** для управления серверным состоянием
- **Zustand** для локального состояния
- **Tailwind CSS** для стилизации
- **React Virtual** для виртуализации больших списков

#### 1.2 Компонентная архитектура

```typescript
// Структура компонентов
src/
├── components/
│   ├── ui/                    // Базовые UI компоненты
│   │   ├── Button.tsx
│   │   ├── Card.tsx
│   │   ├── Toggle.tsx
│   │   └── VirtualList.tsx
│   ├── inventory/             // Бизнес-логика инвентаря
│   │   ├── InventoryDashboard.tsx
│   │   ├── ProductList.tsx
│   │   ├── ProductCard.tsx
│   │   ├── StockStatusBadge.tsx
│   │   └── ViewModeToggle.tsx
│   └── layout/                // Макет приложения
│       ├── Header.tsx
│       ├── Navigation.tsx
│       └── Layout.tsx
├── hooks/                     // Кастомные хуки
│   ├── useInventory.ts
│   ├── useLocalStorage.ts
│   └── useVirtualization.ts
├── services/                  // API сервисы
│   ├── api.ts
│   └── inventory.ts
├── stores/                    // Состояние приложения
│   └── inventoryStore.ts
├── types/                     // TypeScript типы
│   └── inventory.ts
└── utils/                     // Утилиты
    ├── formatters.ts
    └── constants.ts
```

#### 1.3 Ключевые компоненты

##### InventoryDashboard.tsx

```typescript
interface InventoryDashboardProps {
  initialViewMode?: "top10" | "all";
}

export const InventoryDashboard: React.FC<InventoryDashboardProps> = ({
  initialViewMode = "top10",
}) => {
  const { data, isLoading, error } = useInventory();
  const [viewMode, setViewMode] = useLocalStorage(
    "inventory-view-mode",
    initialViewMode
  );

  if (isLoading) return <LoadingSpinner />;
  if (error) return <ErrorMessage error={error} />;

  return (
    <div className="min-h-screen bg-gray-50 p-6">
      <Header title="Дашборд складских остатков" />

      <div className="mb-6">
        <ViewModeToggle
          mode={viewMode}
          onChange={setViewMode}
          counts={{
            critical: data.critical_products.count,
            lowStock: data.low_stock_products.count,
            overstock: data.overstock_products.count,
          }}
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <ProductList
          title="🚨 Критический остаток"
          products={data.critical_products}
          type="critical"
          viewMode={viewMode}
        />
        <ProductList
          title="⚠️ Низкий остаток"
          products={data.low_stock_products}
          type="low_stock"
          viewMode={viewMode}
        />
        <ProductList
          title="📈 Избыток товара"
          products={data.overstock_products}
          type="overstock"
          viewMode={viewMode}
        />
      </div>
    </div>
  );
};
```

##### ProductList.tsx с виртуализацией

```typescript
interface ProductListProps {
  title: string;
  products: { count: number; items: Product[] };
  type: "critical" | "low_stock" | "overstock";
  viewMode: "top10" | "all";
}

export const ProductList: React.FC<ProductListProps> = ({
  title,
  products,
  type,
  viewMode,
}) => {
  const displayedProducts =
    viewMode === "all" ? products.items : products.items.slice(0, 10);

  const listHeight =
    viewMode === "all" ? 500 : Math.min(displayedProducts.length * 80, 400);

  const ProductRow = ({
    index,
    style,
  }: {
    index: number;
    style: React.CSSProperties;
  }) => {
    const product = displayedProducts[index];
    return (
      <div style={style} className="px-4 py-3 border-b border-gray-100">
        <ProductCard product={product} type={type} compact />
      </div>
    );
  };

  return (
    <Card className="h-fit">
      <CardHeader>
        <div className="flex justify-between items-center">
          <h3 className="text-lg font-semibold">{title}</h3>
          <StockStatusBadge
            count={products.count}
            displayed={displayedProducts.length}
            type={type}
          />
        </div>
      </CardHeader>

      <CardContent className="p-0">
        {displayedProducts.length > 0 ? (
          <FixedSizeList
            height={listHeight}
            itemCount={displayedProducts.length}
            itemSize={80}
            overscanCount={5}
          >
            {ProductRow}
          </FixedSizeList>
        ) : (
          <div className="p-8 text-center text-gray-500">Товары не найдены</div>
        )}
      </CardContent>
    </Card>
  );
};
```

### 2. Backend Refactoring

#### 2.1 Новая структура проекта

```
mi_core_etl/
├── src/
│   ├── api/
│   │   ├── controllers/
│   │   │   ├── InventoryController.php
│   │   │   ├── AnalyticsController.php
│   │   │   └── HealthController.php
│   │   ├── middleware/
│   │   │   ├── AuthMiddleware.php
│   │   │   ├── CorsMiddleware.php
│   │   │   └── RateLimitMiddleware.php
│   │   └── routes/
│   │       ├── api.php
│   │       └── web.php
│   ├── etl/
│   │   ├── extractors/
│   │   │   ├── OzonExtractor.php
│   │   │   └── WildberriesExtractor.php
│   │   ├── transformers/
│   │   │   └── InventoryTransformer.php
│   │   └── loaders/
│   │       └── DatabaseLoader.php
│   ├── models/
│   │   ├── Product.php
│   │   ├── Inventory.php
│   │   └── Warehouse.php
│   ├── services/
│   │   ├── InventoryService.php
│   │   ├── CacheService.php
│   │   └── LoggingService.php
│   └── utils/
│       ├── Database.php
│       ├── Config.php
│       └── Logger.php
├── config/
│   ├── database.php
│   ├── api.php
│   ├── cache.php
│   └── logging.php
├── public/
│   ├── api/
│   │   └── index.php
│   └── build/              // React build
├── storage/
│   ├── logs/
│   ├── cache/
│   └── backups/
├── deployment/
│   ├── scripts/
│   │   ├── deploy.sh
│   │   ├── backup.sh
│   │   └── rollback.sh
│   └── configs/
│       ├── nginx.conf
│       └── php-fpm.conf
├── tests/
│   ├── unit/
│   └── integration/
├── docs/
│   ├── api.md
│   └── deployment.md
├── frontend/               // React приложение
│   ├── src/
│   ├── public/
│   ├── package.json
│   ├── vite.config.ts
│   └── tsconfig.json
├── .env.example
├── .gitignore
├── composer.json
├── README.md
└── package.json           // Root package.json для скриптов
```

#### 2.2 Унифицированный API Controller

```php
<?php
// src/api/controllers/InventoryController.php

class InventoryController {
    private InventoryService $inventoryService;
    private CacheService $cacheService;
    private LoggingService $logger;

    public function __construct(
        InventoryService $inventoryService,
        CacheService $cacheService,
        LoggingService $logger
    ) {
        $this->inventoryService = $inventoryService;
        $this->cacheService = $cacheService;
        $this->logger = $logger;
    }

    public function getDashboardData(Request $request): JsonResponse {
        try {
            $limit = $request->query('limit', 10);
            $cacheKey = "dashboard_data_{$limit}";

            // Проверяем кэш
            if ($cachedData = $this->cacheService->get($cacheKey)) {
                $this->logger->info('Dashboard data served from cache', ['limit' => $limit]);
                return new JsonResponse($cachedData);
            }

            // Получаем данные из сервиса
            $data = $this->inventoryService->getDashboardData($limit);

            // Кэшируем на 5 минут
            $this->cacheService->set($cacheKey, $data, 300);

            $this->logger->info('Dashboard data generated', [
                'limit' => $limit,
                'critical_count' => count($data['critical_products']['items']),
                'low_stock_count' => count($data['low_stock_products']['items']),
                'overstock_count' => count($data['overstock_products']['items'])
            ]);

            return new JsonResponse($data);

        } catch (Exception $e) {
            $this->logger->error('Dashboard data error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'error' => 'Ошибка получения данных дашборда',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function getProductDetails(Request $request, string $sku): JsonResponse {
        try {
            $product = $this->inventoryService->getProductBySku($sku);

            if (!$product) {
                return new JsonResponse(['error' => 'Товар не найден'], 404);
            }

            return new JsonResponse($product);

        } catch (Exception $e) {
            $this->logger->error('Product details error', [
                'sku' => $sku,
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'error' => 'Ошибка получения данных товара'
            ], 500);
        }
    }
}
```

### 3. Модели данных

#### 3.1 TypeScript типы для Frontend

```typescript
// src/types/inventory.ts

export interface Product {
  id: number;
  sku: string;
  name: string;
  current_stock: number;
  available_stock: number;
  reserved_stock: number;
  warehouse_name: string;
  stock_status: "critical" | "low_stock" | "normal" | "overstock";
  last_updated: string;
  price?: number;
  category?: string;
}

export interface ProductGroup {
  count: number;
  items: Product[];
}

export interface DashboardData {
  critical_products: ProductGroup;
  low_stock_products: ProductGroup;
  overstock_products: ProductGroup;
  last_updated: string;
  total_products: number;
}

export interface ApiResponse<T> {
  data: T;
  success: boolean;
  message?: string;
  error?: string;
}

export type ViewMode = "top10" | "all";
export type StockType = "critical" | "low_stock" | "overstock";
```

#### 3.2 PHP модели

```php
<?php
// src/models/Product.php

class Product {
    public int $id;
    public string $sku;
    public string $name;
    public int $current_stock;
    public int $available_stock;
    public int $reserved_stock;
    public string $warehouse_name;
    public string $stock_status;
    public DateTime $last_updated;
    public ?float $price;
    public ?string $category;

    public function __construct(array $data) {
        $this->id = $data['id'];
        $this->sku = $data['sku'];
        $this->name = $data['name'];
        $this->current_stock = $data['current_stock'];
        $this->available_stock = $data['available_stock'];
        $this->reserved_stock = $data['reserved_stock'];
        $this->warehouse_name = $data['warehouse_name'];
        $this->stock_status = $this->calculateStockStatus();
        $this->last_updated = new DateTime($data['last_updated']);
        $this->price = $data['price'] ?? null;
        $this->category = $data['category'] ?? null;
    }

    private function calculateStockStatus(): string {
        if ($this->current_stock <= 5) return 'critical';
        if ($this->current_stock <= 20) return 'low_stock';
        if ($this->current_stock > 100) return 'overstock';
        return 'normal';
    }

    public function toArray(): array {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'current_stock' => $this->current_stock,
            'available_stock' => $this->available_stock,
            'reserved_stock' => $this->reserved_stock,
            'warehouse_name' => $this->warehouse_name,
            'stock_status' => $this->stock_status,
            'last_updated' => $this->last_updated->format('Y-m-d H:i:s'),
            'price' => $this->price,
            'category' => $this->category
        ];
    }
}
```

## Обработка ошибок

### 1. Frontend Error Handling

```typescript
// src/hooks/useInventory.ts
import { useQuery } from "@tanstack/react-query";
import { inventoryService } from "../services/inventory";

export const useInventory = (limit: number = 10) => {
  return useQuery({
    queryKey: ["inventory", limit],
    queryFn: () => inventoryService.getDashboardData(limit),
    staleTime: 5 * 60 * 1000, // 5 минут
    cacheTime: 10 * 60 * 1000, // 10 минут
    retry: (failureCount, error) => {
      // Не повторяем запрос при 4xx ошибках
      if (error.response?.status >= 400 && error.response?.status < 500) {
        return false;
      }
      return failureCount < 3;
    },
    onError: (error) => {
      console.error("Inventory data fetch error:", error);
      // Можно добавить уведомления пользователю
    },
  });
};
```

### 2. Backend Error Handling

```php
<?php
// src/utils/Logger.php

class Logger {
    private string $logPath;
    private string $level;

    public function __construct() {
        $this->logPath = $_ENV['LOG_PATH'] ?? '/var/log/mi_core_etl';
        $this->level = $_ENV['LOG_LEVEL'] ?? 'info';
    }

    public function error(string $message, array $context = []): void {
        $this->log('ERROR', $message, $context);
    }

    public function info(string $message, array $context = []): void {
        $this->log('INFO', $message, $context);
    }

    private function log(string $level, string $message, array $context): void {
        $timestamp = date('Y-m-d H:i:s');
        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE);

        $logEntry = "[{$timestamp}] {$level}: {$message} {$contextJson}" . PHP_EOL;

        $logFile = $this->logPath . '/' . strtolower($level) . '_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
```

## Стратегия тестирования

### 1. Frontend Testing

```typescript
// src/components/__tests__/InventoryDashboard.test.tsx
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { InventoryDashboard } from "../InventoryDashboard";
import { inventoryService } from "../../services/inventory";

// Мокаем сервис
jest.mock("../../services/inventory");

const createWrapper = () => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });

  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
};

describe("InventoryDashboard", () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it("отображает данные дашборда после загрузки", async () => {
    const mockData = {
      critical_products: { count: 32, items: [] },
      low_stock_products: { count: 53, items: [] },
      overstock_products: { count: 28, items: [] },
      last_updated: "2025-10-21 10:00:00",
      total_products: 113,
    };

    (inventoryService.getDashboardData as jest.Mock).mockResolvedValue(
      mockData
    );

    render(<InventoryDashboard />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText("🚨 Критический остаток")).toBeInTheDocument();
      expect(screen.getByText("⚠️ Низкий остаток")).toBeInTheDocument();
      expect(screen.getByText("📈 Избыток товара")).toBeInTheDocument();
    });
  });

  it("обрабатывает ошибки загрузки", async () => {
    (inventoryService.getDashboardData as jest.Mock).mockRejectedValue(
      new Error("API Error")
    );

    render(<InventoryDashboard />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText(/ошибка/i)).toBeInTheDocument();
    });
  });
});
```

### 2. Backend Testing

```php
<?php
// tests/unit/InventoryControllerTest.php

use PHPUnit\Framework\TestCase;

class InventoryControllerTest extends TestCase {
    private InventoryController $controller;
    private InventoryService $inventoryService;
    private CacheService $cacheService;
    private LoggingService $logger;

    protected function setUp(): void {
        $this->inventoryService = $this->createMock(InventoryService::class);
        $this->cacheService = $this->createMock(CacheService::class);
        $this->logger = $this->createMock(LoggingService::class);

        $this->controller = new InventoryController(
            $this->inventoryService,
            $this->cacheService,
            $this->logger
        );
    }

    public function testGetDashboardDataReturnsData(): void {
        $expectedData = [
            'critical_products' => ['count' => 32, 'items' => []],
            'low_stock_products' => ['count' => 53, 'items' => []],
            'overstock_products' => ['count' => 28, 'items' => []]
        ];

        $this->cacheService->method('get')->willReturn(null);
        $this->inventoryService->method('getDashboardData')->willReturn($expectedData);

        $request = new Request(['limit' => 10]);
        $response = $this->controller->getDashboardData($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($expectedData, json_decode($response->getContent(), true));
    }

    public function testGetDashboardDataHandlesErrors(): void {
        $this->cacheService->method('get')->willReturn(null);
        $this->inventoryService->method('getDashboardData')
            ->willThrowException(new Exception('Database error'));

        $request = new Request(['limit' => 10]);
        $response = $this->controller->getDashboardData($request);

        $this->assertEquals(500, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
    }
}
```

## Решения по производительности

### 1. Frontend Optimization

- **Виртуализация списков** с react-window для больших списков товаров
- **Мемоизация компонентов** с React.memo для предотвращения лишних рендеров
- **Ленивая загрузка** компонентов с React.lazy
- **Оптимизация bundle** с помощью Vite и tree-shaking

### 2. Backend Optimization

- **Кэширование** API ответов в Redis/файловом кэше
- **Оптимизация SQL запросов** с индексами и EXPLAIN анализом
- **Пагинация** для больших наборов данных
- **Сжатие ответов** с gzip

### 3. Database Optimization

```sql
-- Оптимизированные индексы для дашборда
CREATE INDEX idx_inventory_stock_status ON inventory_data (stock_status, warehouse_id);
CREATE INDEX idx_inventory_updated ON inventory_data (last_updated DESC);
CREATE INDEX idx_products_active_sku ON products (is_active, sku);

-- Оптимизированное представление для дашборда
CREATE OR REPLACE VIEW v_dashboard_inventory AS
SELECT
    p.id,
    p.sku,
    p.name,
    i.current_stock,
    i.available_stock,
    i.reserved_stock,
    w.name as warehouse_name,
    CASE
        WHEN i.current_stock <= 5 THEN 'critical'
        WHEN i.current_stock <= 20 THEN 'low_stock'
        WHEN i.current_stock > 100 THEN 'overstock'
        ELSE 'normal'
    END as stock_status,
    i.last_updated,
    p.price,
    p.category
FROM products p
JOIN inventory_data i ON p.id = i.product_id
JOIN warehouses w ON i.warehouse_id = w.id
WHERE p.is_active = 1
ORDER BY
    CASE
        WHEN i.current_stock <= 5 THEN 1
        WHEN i.current_stock <= 20 THEN 2
        WHEN i.current_stock > 100 THEN 3
        ELSE 4
    END,
    i.current_stock ASC,
    p.name;
```

Этот дизайн обеспечивает современную, масштабируемую архитектуру с четким разделением ответственности, оптимальной производительностью и удобством сопровождения.
