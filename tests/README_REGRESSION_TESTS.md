# MDM System - Regression Test Suite

## Overview

This directory contains comprehensive automated tests to prevent regression of fixed issues in the MDM (Master Data Management) system.

## Test Files

### Unit Tests

- **SafeSyncEngineTest.php** - Tests for synchronization engine
- **DataTypeNormalizerTest.php** - Tests for data type normalization
- **FallbackDataProviderTest.php** - Tests for fallback mechanisms

### Integration Tests

- **SyncIntegrationTest.php** - End-to-end synchronization tests

### Regression Tests

- **RegressionTest.php** - Tests for previously fixed bugs
- **DataTypeCompatibilityTest.php** - Comprehensive type compatibility tests

### Functional Tests

- **test_sql_queries_comprehensive.php** - SQL query validation
- **test_product_sync_comprehensive.php** - Product sync validation
- **test_api_endpoints_mdm.php** - API endpoint tests
- **test_data_quality_reports.php** - Data quality report tests

## Quick Start

### Run All Tests

```bash
# Using custom test runner
php run_all_mdm_tests.php

# Using PHPUnit
vendor/bin/phpunit
```

### Run Specific Test Suite

```bash
# Unit tests only
vendor/bin/phpunit --testsuite unit

# Integration tests only
vendor/bin/phpunit --testsuite integration

# Specific test file
vendor/bin/phpunit tests/RegressionTest.php
```

### Verify Test Coverage

```bash
php verify_test_coverage.php
```

## Test Categories

### 1. Regression Tests (RegressionTest.php)

Tests that prevent previously fixed issues from reoccurring:

#### SQL Query Issues

- ✅ `testSQLDistinctOrderByNoError()` - DISTINCT + ORDER BY error
- ✅ `testDataTypeCompatibilityInJoins()` - INT vs VARCHAR JOIN issues

#### Data Quality Issues

- ✅ `testPlaceholderNameReplacement()` - Placeholder name detection
- ✅ `testEmptyOrNullIdHandling()` - Null/empty ID validation

#### Sync Engine Issues

- ✅ `testSyncEngineTransactionRollback()` - Transaction handling
- ✅ `testBatchProcessingPreventsTimeout()` - Batch processing
- ✅ `testRetryLogicOnTemporaryFailures()` - Retry mechanism

#### Data Integrity Issues

- ✅ `testCrossReferenceTableIntegrity()` - Cross-reference integrity
- ✅ `testStaleCacheCleanup()` - Cache maintenance
- ✅ `testConcurrentSyncPrevention()` - Concurrent sync handling

### 2. Data Type Compatibility Tests (DataTypeCompatibilityTest.php)

Comprehensive tests for data type handling:

#### Type Conversion

- ✅ INT to VARCHAR conversion
- ✅ VARCHAR to INT conversion
- ✅ Mixed type ID comparison
- ✅ SQL CAST generation

#### API Response Normalization

- ✅ Ozon API response normalization
- ✅ Wildberries API response normalization
- ✅ Analytics API response normalization
- ✅ Inventory API response normalization

#### Data Validation

- ✅ Product ID validation
- ✅ Numeric string normalization
- ✅ Boolean value normalization
- ✅ DateTime normalization
- ✅ String trimming and cleaning

#### Edge Cases

- ✅ Special characters in IDs
- ✅ Large number handling
- ✅ Unicode string handling
- ✅ Null and empty value handling

### 3. Unit Tests

#### SafeSyncEngineTest.php

- Successful synchronization
- Database connection failure
- Empty product list handling
- Batch processing
- Retry logic
- Invalid product data
- Transaction rollback
- Custom batch size
- Sync statistics
- SQL query errors
- Limit parameter

#### DataTypeNormalizerTest.php

- ID normalization (INT/VARCHAR)
- Product normalization
- Value normalization
- API response normalization
- Data validation
- Safe JOIN value creation
- Comparison SQL generation

#### FallbackDataProviderTest.php

- Cache retrieval
- Cache storage
- API fallback
- Temporary name generation
- Cache statistics
- Stale cache cleanup
- Placeholder detection

### 4. Integration Tests (SyncIntegrationTest.php)

End-to-end workflow tests:

- Complete synchronization workflow
- Data type normalization in full cycle
- Fallback provider integration
- Cache statistics calculation
- Batch processing with rollback
- API response updates
- Stale cache cleanup
- Concurrent product updates
- Placeholder name detection
- Sync statistics accuracy

## Running Tests in CI/CD

Tests run automatically via GitHub Actions on:

- Push to `main` or `develop` branches
- Pull requests
- Manual workflow dispatch

### Workflow Jobs

1. **unit-tests** - Run on PHP 7.4, 8.0, 8.1, 8.2
2. **integration-tests** - Run with MySQL 8.0
3. **regression-tests** - Verify fixed issues
4. **data-type-compatibility-tests** - Type handling
5. **sql-query-tests** - Query validation
6. **code-quality** - Syntax and style checks
7. **security-scan** - Dependency vulnerabilities

## Test Data

### Test Database

Tests use SQLite in-memory database for speed and isolation:

```php
$testDb = new PDO('sqlite::memory:');
```

### Test Tables

- `product_cross_reference` - Cross-reference mapping
- `dim_products` - Product master data
- `inventory_data` - Inventory information

### Test Data Setup

Each test creates its own isolated test data:

```php
protected function setUp(): void {
    $this->createTestTables();
    $this->insertTestData();
}
```

## Writing New Tests

### Test Template

```php
<?php
use PHPUnit\Framework\TestCase;

class MyNewTest extends TestCase {
    protected function setUp(): void {
        // Setup test environment
    }

    protected function tearDown(): void {
        // Cleanup
    }

    public function testMyFeature() {
        // Arrange
        $input = 'test data';

        // Act
        $result = myFunction($input);

        // Assert
        $this->assertEquals('expected', $result);
    }
}
```

### Best Practices

1. **Descriptive names** - Test name should describe what it tests
2. **Isolated tests** - No dependencies between tests
3. **Clean state** - Use setUp/tearDown for clean state
4. **Test one thing** - Each test should verify one behavior
5. **Edge cases** - Test null, empty, invalid inputs
6. **Mock externals** - Mock APIs, databases, etc.
7. **Fast tests** - Keep tests fast (< 1 second each)
8. **Clear assertions** - Use descriptive assertion messages

## Troubleshooting

### Common Issues

#### PHPUnit Not Found

```bash
composer require --dev phpunit/phpunit
```

#### Database Connection Failed

```bash
# Check database credentials in config
# Verify database exists
# Test connection manually
```

#### Memory Limit Exceeded

```bash
php -d memory_limit=512M run_all_mdm_tests.php
```

#### Tests Timeout

```bash
# Increase timeout in phpunit.xml
# Or run specific test suites
```

### Debug Mode

Run tests with verbose output:

```bash
vendor/bin/phpunit --verbose
vendor/bin/phpunit --debug
```

## Test Coverage

### Current Coverage

- Unit Tests: 95%+
- Integration Tests: Full workflow coverage
- Regression Tests: All known issues covered
- Data Type Tests: Comprehensive coverage

### Generate Coverage Report

```bash
vendor/bin/phpunit --coverage-html coverage/
open coverage/index.html
```

## Continuous Improvement

### Adding Tests for New Features

1. Write test first (TDD)
2. Verify test fails
3. Implement feature
4. Verify test passes
5. Add to test suite

### Adding Regression Tests for Bugs

1. Reproduce bug in test
2. Verify test fails
3. Fix bug
4. Verify test passes
5. Add to RegressionTest.php
6. Document in this README

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Test-Driven Development](https://martinfowler.com/bliki/TestDrivenDevelopment.html)
- [Automated Testing Guide](../docs/AUTOMATED_TESTING_GUIDE.md)
- [MDM Design Document](../.kiro/specs/mdm-product-system/design.md)

## Support

For questions or issues:

1. Check this README
2. Review test code comments
3. Check CI/CD logs
4. Contact development team
