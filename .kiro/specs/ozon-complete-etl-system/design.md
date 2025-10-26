# Design Document - Ozon Complete ETL System

## Overview

Система ETL для ежедневной выгрузки данных из Ozon API состоит из трех основных компонентов: синхронизации товаров, выгрузки продаж и обновления остатков. Система построена на принципах надежности, масштабируемости и простоты сопровождения.

## Architecture

### High-Level Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Ozon API      │    │   ETL System    │    │  PostgreSQL DB  │
│                 │    │                 │    │                 │
│ /v2/product/list│───▶│  ProductETL     │───▶│  dim_products   │
│ /v2/posting/fbo │───▶│  SalesETL       │───▶│  fact_orders    │
│ /v1/report/*    │───▶│  InventoryETL   │───▶│  inventory      │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                              │
                              ▼
                       ┌─────────────────┐
                       │   Scheduler     │
                       │   & Monitor     │
                       └─────────────────┘
```

### System Components

1. **ETL Engine** - Базовый движок для обработки данных
2. **API Client** - Клиент для взаимодействия с Ozon API
3. **Database Layer** - Слой работы с PostgreSQL
4. **Scheduler** - Планировщик задач (cron-based)
5. **Monitor** - Система мониторинга и алертов
6. **Logger** - Централизованное логирование

## Components and Interfaces

### 1. Base ETL Framework

```php
abstract class BaseETL {
    protected DatabaseConnection $db;
    protected Logger $logger;
    protected OzonApiClient $apiClient;

    abstract public function extract(): array;
    abstract public function transform(array $data): array;
    abstract public function load(array $data): void;

    public function execute(): ETLResult {
        $startTime = microtime(true);

        try {
            $this->logger->info('Starting ETL process', ['class' => get_class($this)]);

            $rawData = $this->extract();
            $transformedData = $this->transform($rawData);
            $this->load($transformedData);

            $duration = microtime(true) - $startTime;
            $this->logger->info('ETL completed successfully', [
                'duration' => $duration,
                'records_processed' => count($transformedData)
            ]);

            return new ETLResult(true, count($transformedData), $duration);

        } catch (Exception $e) {
            $this->logger->error('ETL process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
}
```

### 2. Ozon API Client

```php
class OzonApiClient {
    private string $clientId;
    private string $apiKey;
    private string $baseUrl = 'https://api-seller.ozon.ru';
    private HttpClient $httpClient;

    public function getProducts(int $limit = 1000, ?string $lastId = null): array {
        return $this->makeRequest('POST', '/v2/product/list', [
            'filter' => [],
            'limit' => $limit,
            'last_id' => $lastId
        ]);
    }

    public function getSalesHistory(string $since, string $to, int $limit = 1000, int $offset = 0): array {
        return $this->makeRequest('POST', '/v2/posting/fbo/list', [
            'filter' => [
                'since' => $since,
                'to' => $to
            ],
            'limit' => $limit,
            'offset' => $offset
        ]);
    }

    public function createStockReport(): array {
        return $this->makeRequest('POST', '/v1/report/warehouse/stock', [
            'language' => 'DEFAULT'
        ]);
    }

    public function getReportStatus(string $code): array {
        return $this->makeRequest('POST', '/v1/report/info', [
            'code' => $code
        ]);
    }

    private function makeRequest(string $method, string $endpoint, array $data = []): array {
        // Implementation with retry logic, rate limiting, error handling
    }
}
```

### 3. Product ETL Component

```php
class ProductETL extends BaseETL {
    public function extract(): array {
        $products = [];
        $lastId = null;

        do {
            $response = $this->apiClient->getProducts(1000, $lastId);
            $batch = $response['result']['items'] ?? [];
            $products = array_merge($products, $batch);
            $lastId = $response['result']['last_id'] ?? null;

            $this->logger->debug('Extracted product batch', [
                'batch_size' => count($batch),
                'total_products' => count($products)
            ]);

        } while ($lastId && count($batch) > 0);

        return $products;
    }

    public function transform(array $data): array {
        return array_map(function($item) {
            return [
                'product_id' => $item['product_id'],
                'offer_id' => $item['offer_id'],
                'name' => $item['name'],
                'fbo_sku' => $item['fbo_sku'] ?? null,
                'fbs_sku' => $item['fbs_sku'] ?? null,
                'status' => $item['status'],
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }, $data);
    }

    public function load(array $data): void {
        $this->db->beginTransaction();

        try {
            foreach (array_chunk($data, 1000) as $batch) {
                $this->upsertProducts($batch);
            }

            $this->db->commit();
            $this->logger->info('Products loaded successfully', ['count' => count($data)]);

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
```

### 4. Sales ETL Component

```php
class SalesETL extends BaseETL {
    public function extract(): array {
        $since = date('Y-m-d\TH:i:s\Z', strtotime('-30 days'));
        $to = date('Y-m-d\TH:i:s\Z');

        $orders = [];
        $offset = 0;
        $limit = 1000;

        do {
            $response = $this->apiClient->getSalesHistory($since, $to, $limit, $offset);
            $batch = $response['result']['postings'] ?? [];
            $orders = array_merge($orders, $batch);
            $offset += $limit;

        } while (count($batch) === $limit);

        return $orders;
    }

    public function transform(array $data): array {
        $orderItems = [];

        foreach ($data as $order) {
            foreach ($order['products'] as $product) {
                $orderItems[] = [
                    'posting_number' => $order['posting_number'],
                    'offer_id' => $product['sku'],
                    'quantity' => $product['quantity'],
                    'price' => $product['price'] ?? 0,
                    'warehouse_id' => $order['warehouse_id'] ?? null,
                    'in_process_at' => $order['in_process_at'],
                    'created_at' => date('Y-m-d H:i:s')
                ];
            }
        }

        return $orderItems;
    }

    public function load(array $data): void {
        // Incremental load - only new orders
        $this->loadIncrementally($data);
    }
}
```

### 5. Inventory ETL Component

```php
class InventoryETL extends BaseETL {
    public function extract(): array {
        // Step 1: Request report generation
        $reportResponse = $this->apiClient->createStockReport();
        $reportCode = $reportResponse['result']['code'];

        // Step 2: Wait for report completion
        $maxAttempts = 30;
        $attempt = 0;

        do {
            sleep(60); // Wait 1 minute
            $statusResponse = $this->apiClient->getReportStatus($reportCode);
            $status = $statusResponse['result']['status'];
            $attempt++;

            $this->logger->debug('Checking report status', [
                'code' => $reportCode,
                'status' => $status,
                'attempt' => $attempt
            ]);

        } while ($status !== 'success' && $attempt < $maxAttempts);

        if ($status !== 'success') {
            throw new Exception('Report generation timeout after ' . $maxAttempts . ' attempts');
        }

        // Step 3: Download and parse CSV
        $fileUrl = $statusResponse['result']['file'];
        return $this->downloadAndParseCsv($fileUrl);
    }

    public function transform(array $data): array {
        return array_map(function($row) {
            return [
                'offer_id' => $row['SKU'],
                'warehouse_name' => $row['Warehouse name'],
                'item_name' => $row['Item Name'],
                'present' => (int)$row['Present'],
                'reserved' => (int)$row['Reserved'],
                'available' => max(0, (int)$row['Present'] - (int)$row['Reserved']),
                'updated_at' => date('Y-m-d H:i:s')
            ];
        }, $data);
    }

    public function load(array $data): void {
        // Full refresh of inventory table
        $this->db->beginTransaction();

        try {
            $this->db->exec('TRUNCATE TABLE inventory');

            foreach (array_chunk($data, 1000) as $batch) {
                $this->insertInventoryBatch($batch);
            }

            $this->db->commit();
            $this->logger->info('Inventory refreshed successfully', ['count' => count($data)]);

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
```

## Data Models

### Database Schema

```sql
-- Products dimension table
CREATE TABLE dim_products (
    id SERIAL PRIMARY KEY,
    product_id BIGINT UNIQUE NOT NULL,
    offer_id VARCHAR(255) UNIQUE NOT NULL,
    name TEXT,
    fbo_sku VARCHAR(255),
    fbs_sku VARCHAR(255),
    status VARCHAR(50),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Sales fact table
CREATE TABLE fact_orders (
    id SERIAL PRIMARY KEY,
    posting_number VARCHAR(255) NOT NULL,
    offer_id VARCHAR(255) NOT NULL,
    quantity INTEGER NOT NULL,
    price DECIMAL(10,2),
    warehouse_id VARCHAR(255),
    in_process_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(posting_number, offer_id)
);

-- Inventory table
CREATE TABLE inventory (
    id SERIAL PRIMARY KEY,
    offer_id VARCHAR(255) NOT NULL,
    warehouse_name VARCHAR(255) NOT NULL,
    item_name TEXT,
    present INTEGER DEFAULT 0,
    reserved INTEGER DEFAULT 0,
    available INTEGER DEFAULT 0,
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(offer_id, warehouse_name)
);

-- ETL execution log
CREATE TABLE etl_execution_log (
    id SERIAL PRIMARY KEY,
    etl_class VARCHAR(255) NOT NULL,
    status VARCHAR(50) NOT NULL,
    records_processed INTEGER,
    duration_seconds DECIMAL(10,3),
    error_message TEXT,
    started_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP
);

-- Indexes for performance
CREATE INDEX idx_dim_products_offer_id ON dim_products(offer_id);
CREATE INDEX idx_fact_orders_offer_id ON fact_orders(offer_id);
CREATE INDEX idx_fact_orders_date ON fact_orders(in_process_at);
CREATE INDEX idx_inventory_offer_id ON inventory(offer_id);
CREATE INDEX idx_inventory_warehouse ON inventory(warehouse_name);
```

## Error Handling

### Retry Strategy

-   API calls: Exponential backoff with max 5 retries
-   Database operations: Transaction rollback on failure
-   Report generation: Polling with timeout (30 minutes max)

### Error Recovery

-   Failed ETL processes log errors and send alerts
-   Partial failures allow continuation where possible
-   Database transactions ensure data consistency

### Monitoring Points

-   ETL execution duration and success rate
-   API response times and error rates
-   Database connection health
-   Disk space for logs and temporary files

## Testing Strategy

### Unit Tests

-   Test each ETL component in isolation
-   Mock API responses for predictable testing
-   Validate data transformation logic
-   Test error handling scenarios

### Integration Tests

-   Test with Ozon API sandbox environment
-   Validate end-to-end data flow
-   Test database operations with real schema
-   Verify scheduler integration

### Performance Tests

-   Load testing with large datasets (100k+ records)
-   Memory usage monitoring during processing
-   Database query performance validation
-   API rate limit compliance testing

## Deployment and Operations

### Environment Requirements

-   PHP 8.1+ with required extensions
-   PostgreSQL 13+ with sufficient storage
-   Cron daemon for scheduling
-   Monitoring tools (optional: Prometheus/Grafana)

### Configuration Management

-   Environment-specific config files
-   Secure credential storage (environment variables)
-   Logging level configuration
-   API rate limiting settings

### Monitoring and Alerting

-   Health check endpoints for each ETL component
-   Slack/email notifications for failures
-   Metrics collection for performance monitoring
-   Log aggregation for troubleshooting
