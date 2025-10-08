# MDM Matching Engine Tests

This directory contains comprehensive unit tests for the MDM (Master Data Management) system's matching engine components.

## Test Structure

```
tests/
├── Unit/
│   └── Services/
│       ├── MatchingEngineTest.php           # Core matching algorithms
│       ├── MatchingScoreServiceTest.php     # Confidence scoring system
│       ├── DataEnrichmentServiceTest.php    # Data enrichment from external sources
│       └── ProductMatchingOrchestratorTest.php # Orchestration of matching process
├── Performance/
│   └── MatchingEnginePerformanceTest.php    # Performance and scalability tests
├── bootstrap.php                            # Test environment setup
├── results/                                 # Test results and reports
└── README.md                               # This file
```

## Test Coverage

### MatchingEngineTest.php

Tests all matching algorithms:

- **Exact SKU matching** - Tests precise SKU-based matching
- **Exact barcode matching** - Tests barcode-based product identification
- **Fuzzy name matching** - Tests Levenshtein distance-based name similarity
- **Brand/category matching** - Tests brand and category-based matching
- **Overall score calculation** - Tests weighted scoring algorithm
- **Edge cases** - Tests handling of null values, special characters, Unicode
- **String normalization** - Tests case-insensitive and whitespace handling
- **Performance** - Tests with large datasets (1000+ products)

### MatchingScoreServiceTest.php

Tests confidence scoring and decision making:

- **Confidence score calculation** - Tests scoring algorithm with various inputs
- **Decision thresholds** - Tests auto-accept, manual review, auto-reject decisions
- **Score adjustments** - Tests penalties and bonuses for edge cases
- **Threshold configuration** - Tests customizable decision thresholds
- **Statistics calculation** - Tests decision statistics and reporting
- **Performance** - Tests scoring performance with large match sets

### DataEnrichmentServiceTest.php

Tests data enrichment from external sources:

- **Multi-source enrichment** - Tests Ozon, Wildberries, Barcode APIs
- **Cache management** - Tests caching of enrichment results
- **Error handling** - Tests graceful handling of API failures
- **Data mapping** - Tests transformation of external API responses
- **Configuration** - Tests source configuration and timeouts
- **Performance** - Tests enrichment performance and memory usage

### ProductMatchingOrchestratorTest.php

Tests orchestration of the complete matching process:

- **End-to-end processing** - Tests complete product processing workflow
- **Batch processing** - Tests bulk product processing
- **Decision workflows** - Tests auto-accept, manual review, create new flows
- **Error handling** - Tests error recovery and reporting
- **Performance** - Tests orchestration performance with large batches

### MatchingEnginePerformanceTest.php

Tests performance and scalability:

- **Large dataset performance** - Tests with 5000+ master products
- **Scalability testing** - Tests performance scaling with dataset size
- **Memory efficiency** - Tests memory usage and garbage collection
- **Concurrent processing** - Tests concurrent matching requests
- **Complex product handling** - Tests performance with complex product data

## Running Tests

### Prerequisites

- PHP 8.1 or higher
- PHPUnit 10.x
- Composer dependencies installed

### Run All Tests

```bash
# Using PHPUnit directly
./vendor/bin/phpunit

# Using custom test runner
php run_matching_tests.php
```

### Run Specific Test Suites

```bash
# Unit tests only
./vendor/bin/phpunit --testsuite="Unit Tests"

# Performance tests only
./vendor/bin/phpunit --testsuite="Performance Tests"
```

### Run Individual Test Classes

```bash
# Matching engine tests
./vendor/bin/phpunit tests/Unit/Services/MatchingEngineTest.php

# Score service tests
./vendor/bin/phpunit tests/Unit/Services/MatchingScoreServiceTest.php

# Performance tests
./vendor/bin/phpunit tests/Performance/MatchingEnginePerformanceTest.php
```

### Run with Coverage Report

```bash
./vendor/bin/phpunit --coverage-html tests/results/coverage
```

## Test Configuration

### PHPUnit Configuration (phpunit.xml)

- **Bootstrap**: `tests/bootstrap.php` - Sets up test environment
- **Test Suites**: Separate unit and performance test suites
- **Coverage**: Includes `src/` directory, excludes migrations and config
- **Logging**: JUnit XML, HTML testdox, and text reports

### Environment Variables

```bash
APP_ENV=testing          # Test environment
DB_CONNECTION=sqlite     # In-memory SQLite for tests
DB_DATABASE=:memory:     # Memory database for speed
```

## Performance Benchmarks

### Expected Performance Targets

| Test Type        | Dataset Size      | Target Time    | Target Memory |
| ---------------- | ----------------- | -------------- | ------------- |
| Fuzzy Name Match | 5000 products     | < 10 seconds   | < 50MB        |
| Confidence Score | 1000 calculations | < 1 second     | < 5MB         |
| Batch Processing | 100 products      | < 60 seconds   | < 100MB       |
| Scalability      | 100→5000 products | < 100x scaling | Linear memory |

### Performance Test Results

Performance tests generate detailed reports including:

- Execution time per operation
- Memory usage patterns
- Scaling factors
- Concurrent processing efficiency

## Test Data

### Test Helpers

The bootstrap file provides helper functions:

- `createTestMasterProduct()` - Creates test master products
- `generateTestProductData()` - Generates test product data
- `assertMatchingResult()` - Validates matching results
- `TestPerformanceTracker` - Tracks performance metrics
- `TestDataGenerator` - Generates large test datasets

### Sample Test Data

Tests use realistic product data including:

- Russian product names with Cyrillic characters
- Various product categories (electronics, food, auto parts)
- Different brands and manufacturers
- Complex product attributes and specifications

## Edge Cases Tested

### Data Quality Issues

- Empty or null values
- Very short product names (< 3 characters)
- Very long product descriptions (> 1000 characters)
- Special characters and Unicode text
- Malformed barcodes and SKUs

### Performance Edge Cases

- Large datasets (5000+ products)
- Complex products with many attributes
- Concurrent processing scenarios
- Memory-intensive operations
- Repeated operations (memory leaks)

### Error Conditions

- External API failures
- Database connection errors
- Invalid configuration
- Malformed input data
- Resource exhaustion

## Troubleshooting

### Common Issues

**Tests fail with memory errors:**

```bash
# Increase PHP memory limit
php -d memory_limit=512M ./vendor/bin/phpunit
```

**Performance tests timeout:**

```bash
# Increase time limit
php -d max_execution_time=300 ./vendor/bin/phpunit
```

**Database connection errors:**

```bash
# Ensure SQLite extension is installed
php -m | grep sqlite
```

### Debug Mode

Enable verbose output for debugging:

```bash
./vendor/bin/phpunit --verbose --debug
```

## Continuous Integration

### GitHub Actions Example

```yaml
name: MDM Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.1
          extensions: sqlite3, pdo_sqlite
      - name: Install dependencies
        run: composer install
      - name: Run tests
        run: ./vendor/bin/phpunit
      - name: Upload coverage
        uses: codecov/codecov-action@v1
```

## Contributing

### Adding New Tests

1. Follow existing test structure and naming conventions
2. Include both positive and negative test cases
3. Add performance tests for new algorithms
4. Update this README with new test descriptions

### Test Guidelines

- Use descriptive test method names
- Include docblocks explaining test purpose
- Test edge cases and error conditions
- Verify performance characteristics
- Use appropriate assertions for data types

### Code Coverage

Maintain high code coverage (>90%) for all matching engine components:

- All public methods must be tested
- All decision branches must be covered
- All error conditions must be tested
- Performance characteristics must be verified
