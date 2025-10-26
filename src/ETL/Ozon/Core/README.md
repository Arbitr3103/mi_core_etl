# Ozon ETL Core Framework

This directory contains the core framework components for the Ozon ETL system.

## Components

### BaseETL

Abstract base class that provides the foundation for all ETL processes. Implements the ETL pattern with:

-   `extract()` - Extract data from source
-   `transform()` - Transform data to target format
-   `load()` - Load data into target system
-   `execute()` - Orchestrate the complete ETL process

### DatabaseConnection

Provides PostgreSQL database connectivity with:

-   Connection pooling
-   Transaction management
-   Batch operations (insert, upsert)
-   Query utilities

### Logger

Structured logging system with:

-   JSON format logging
-   Log rotation
-   Multiple log levels
-   Alert integration
-   Performance logging

### ETLResult

Result object that contains:

-   Success/failure status
-   Records processed count
-   Execution duration
-   Detailed metrics
-   Error messages

### ETLFactory

Factory class for creating framework components with proper dependency injection.

## Usage Example

```php
<?php

use MiCore\ETL\Ozon\Core\ETLFactory;
use MiCore\ETL\Ozon\Core\BaseETL;

// Load configuration
$config = require __DIR__ . '/../Config/bootstrap.php';

// Create factory
$factory = new ETLFactory($config);

// Create framework components
$database = $factory->createDatabase();
$logger = $factory->createLogger('my-etl');

// Example ETL implementation
class MyETL extends BaseETL
{
    public function extract(): array
    {
        $this->logger->info('Extracting data...');
        // Extract logic here
        return [];
    }

    public function transform(array $data): array
    {
        $this->logger->info('Transforming data...', ['count' => count($data)]);
        // Transform logic here
        return $data;
    }

    public function load(array $data): void
    {
        $this->logger->info('Loading data...', ['count' => count($data)]);
        // Load logic here
    }
}

// Execute ETL
$etl = new MyETL($database, $logger, $apiClient);
$result = $etl->execute();

if ($result->isSuccess()) {
    echo "ETL completed successfully!\n";
    echo "Records processed: " . $result->getRecordsProcessed() . "\n";
    echo "Duration: " . $result->getFormattedDuration() . "\n";
} else {
    echo "ETL failed: " . $result->getErrorMessage() . "\n";
}
```

## Requirements Satisfied

This implementation satisfies the following requirements from the specification:

-   **Requirement 2.1**: ETL framework with extract, transform, load methods
-   **Requirement 4.1**: Comprehensive logging with timestamps and status information
-   **Requirement 4.3**: Processing metrics including record counts and execution time
-   **Requirement 4.4**: Database connectivity and transaction management

## Next Steps

The next tasks will implement:

1. Ozon API Client (Task 4)
2. Specific ETL components (Tasks 5-7)
3. Executable scripts (Task 8)
4. Scheduler integration (Task 9)
