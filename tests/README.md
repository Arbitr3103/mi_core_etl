# MDM Sync Engine Test Suite

Comprehensive test suite for the Master Data Management synchronization system, covering unit tests and integration tests for all components.

## Test Structure

```
tests/
├── bootstrap.php                  # Test environment setup
├── SafeSyncEngineTest.php        # Unit tests for SafeSyncEngine
├── FallbackDataProviderTest.php  # Unit tests for FallbackDataProvider
├── DataTypeNormalizerTest.php    # Unit tests for DataTypeNormalizer
├── SyncIntegrationTest.php       # Integration tests for full sync cycle
└── README.md                      # This file
```

## Requirements

- PHP 7.4 or higher
- PHPUnit 9.5 or higher
- PDO extension with SQLite support (for integration tests)
- MySQL/MariaDB (for production tests)

## Installation

Install PHPUnit via Composer:

```bash
composer require --dev phpunit/phpunit
```

## Running Tests

### Run All Tests

```bash
vendor/bin/phpunit
```

### Run Specific Test Suite

```bash
# Unit tests only
vendor/bin/phpunit --testsuite="Unit Tests"

# Integration tests only
vendor/bin/phpunit --testsuite="Integration Tests"
```

### Run Specific Test File

```bash
vendor/bin/phpunit tests/SafeSyncEngineTest.php
vendor/bin/phpunit tests/DataTypeNormalizerTest.php
```

### Run Specific Test Method

```bash
vendor/bin/phpunit --filter testSyncProductNamesSuccess
vendor/bin/phpunit --filter testNormalizeIntegerIdToString
```

### Run with Coverage Report

```bash
vendor/bin/phpunit --coverage-html coverage/html
vendor/bin/phpunit --coverage-text
```

### Alternative: Simple Test Runner

If PHPUnit is not installed, use the simplified test runner:

```bash
php run_sync_tests.php
```

## Test Coverage

### SafeSyncEngineTest.php

Tests for the main synchronization engine:

- ✅ Successful synchronization of product names
- ✅ Database connection failure handling
- ✅ Empty product list handling
- ✅ Batch processing with multiple products
- ✅ Retry logic on database failure
- ✅ Invalid product data handling
- ✅ Transaction rollback on error
- ✅ Custom batch size configuration
- ✅ Sync statistics retrieval
- ✅ SQL query error handling
- ✅ Limit parameter functionality

**Total: 11 test methods**

### FallbackDataProviderTest.php

Tests for the fallback data provider:

- ✅ Get product name from cache
- ✅ Get product name with cache disabled
- ✅ Fallback to temporary name
- ✅ Cache product name successfully
- ✅ Create new cache entry
- ✅ Handle database errors when caching
- ✅ Get cached name for missing product
- ✅ Placeholder name detection
- ✅ Update cache from API response
- ✅ Get cache statistics
- ✅ Clear stale cache
- ✅ Clear stale cache with custom age
- ✅ Handle errors when clearing cache
- ✅ Set API timeout
- ✅ Set API timeout with minimum value
- ✅ Cache enabled/disabled toggle
- ✅ Get product name with different sources
- ✅ Handle null product ID
- ✅ Cache with additional data

**Total: 19 test methods**

### DataTypeNormalizerTest.php

Tests for data type normalization:

- ✅ Normalize integer ID to string
- ✅ Normalize string ID
- ✅ Normalize ID with whitespace
- ✅ Normalize null ID
- ✅ Normalize empty string ID
- ✅ Normalize zero ID
- ✅ Normalize product with all fields
- ✅ Normalize string value
- ✅ Normalize numeric value from string
- ✅ Normalize numeric value with comma
- ✅ Normalize integer value
- ✅ Normalize datetime value
- ✅ Normalize invalid datetime
- ✅ Normalize boolean value from string
- ✅ Validate product ID - valid cases
- ✅ Validate product ID - invalid cases
- ✅ Compare IDs with same values
- ✅ Compare IDs with different values
- ✅ Compare IDs with null values
- ✅ Normalize Ozon API response
- ✅ Normalize Wildberries API response
- ✅ Normalize Analytics API response
- ✅ Normalize Inventory API response
- ✅ Validate normalized data - valid
- ✅ Validate normalized data - missing required field
- ✅ Validate normalized data - invalid ID format
- ✅ Validate normalized data - string too long
- ✅ Validate normalized data - invalid numeric field
- ✅ Create safe JOIN value for VARCHAR
- ✅ Create safe JOIN value for INT
- ✅ Create safe JOIN value for null
- ✅ Get safe comparison SQL
- ✅ Normalize empty string to null
- ✅ Normalize whitespace-only string to null
- ✅ Normalize product with mixed types

**Total: 35 test methods**

### SyncIntegrationTest.php

Integration tests for the complete synchronization workflow:

- ✅ Complete synchronization workflow
- ✅ Data type normalization in full cycle
- ✅ Fallback provider integration
- ✅ Cache statistics calculation
- ✅ Batch processing with transaction rollback
- ✅ Update cache from API response
- ✅ Clear stale cache entries
- ✅ Normalize different API responses
- ✅ Validate normalized data before storage
- ✅ Safe ID comparison across different types
- ✅ End-to-end sync with retry logic
- ✅ Concurrent product updates
- ✅ Placeholder name detection and replacement
- ✅ Sync statistics accuracy

**Total: 14 test methods**

## Total Test Coverage

- **Total Test Files:** 4
- **Total Test Methods:** 79
- **Components Tested:** 3 (SafeSyncEngine, FallbackDataProvider, DataTypeNormalizer)

## Test Scenarios Covered

### Error Scenarios

1. Database connection failures
2. SQL query errors
3. Transaction rollback scenarios
4. API request failures
5. Invalid data handling
6. Missing required fields
7. Data type mismatches
8. Constraint violations

### Normal Operation

1. Successful synchronization
2. Batch processing
3. Cache hit/miss scenarios
4. Data normalization
5. API response handling
6. Statistics calculation
7. Configuration changes

### Edge Cases

1. Empty product lists
2. Null values
3. Zero values
4. Whitespace handling
5. Very long strings
6. Concurrent updates
7. Stale cache entries
8. Placeholder names

## Configuration

Test configuration is defined in `phpunit.xml`:

- Test database: SQLite in-memory (for integration tests)
- Log directory: `/tmp`
- Coverage reports: `coverage/` directory
- Test results: `test-results/` directory

## Environment Variables

Set these environment variables to customize test behavior:

```bash
export DB_HOST=localhost
export DB_PORT=3306
export DB_NAME=test_db
export DB_USER=test_user
export DB_PASSWORD=test_password
export LOG_DIR=/tmp
export OZON_CLIENT_ID=test_client_id
export OZON_API_KEY=test_api_key
```

## Continuous Integration

### GitHub Actions Example

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: "7.4"
          extensions: pdo, pdo_sqlite, pdo_mysql

      - name: Install dependencies
        run: composer install

      - name: Run tests
        run: vendor/bin/phpunit --coverage-text
```

## Best Practices

1. **Run tests before committing:** Always run the full test suite before pushing changes
2. **Write tests for new features:** Add tests for any new functionality
3. **Test error scenarios:** Ensure error handling is properly tested
4. **Keep tests isolated:** Each test should be independent
5. **Use descriptive names:** Test method names should clearly describe what they test
6. **Mock external dependencies:** Use mocks for database and API calls in unit tests
7. **Use real database for integration tests:** Integration tests use SQLite in-memory database

## Troubleshooting

### PHPUnit not found

```bash
composer install
# or
composer require --dev phpunit/phpunit
```

### PDO SQLite extension missing

```bash
# Ubuntu/Debian
sudo apt-get install php-sqlite3

# macOS
brew install php
```

### Permission denied for log directory

```bash
sudo mkdir -p /tmp
sudo chmod 777 /tmp
```

### Tests failing due to database connection

Check that test database credentials are correct in `phpunit.xml` or environment variables.

## Contributing

When adding new tests:

1. Follow existing test structure and naming conventions
2. Add test documentation to this README
3. Ensure all tests pass before submitting PR
4. Aim for >80% code coverage
5. Test both success and failure scenarios

## License

Same as the main project.
