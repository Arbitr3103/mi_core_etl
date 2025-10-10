# MDM System - Automated Testing Guide

## Overview

This document describes the comprehensive automated testing strategy for the MDM (Master Data Management) system. The test suite is designed to prevent regression of fixed issues and ensure system reliability.

## Test Structure

### 1. Unit Tests

Unit tests verify individual components in isolation:

- **SafeSyncEngineTest.php** - Tests for the synchronization engine
  - Batch processing
  - Retry logic
  - Error handling
  - Transaction management
- **DataTypeNormalizerTest.php** - Tests for data type normalization
  - INT to VARCHAR conversion
  - API response normalization
  - Data validation
  - Type comparison
- **FallbackDataProviderTest.php** - Tests for fallback mechanisms
  - Cache management
  - API fallback logic
  - Stale cache cleanup
  - Cache statistics

### 2. Integration Tests

Integration tests verify components working together:

- **SyncIntegrationTest.php** - End-to-end synchronization tests
  - Complete sync workflow
  - Database transactions
  - Cache integration
  - Error recovery

### 3. Regression Tests

Regression tests prevent previously fixed issues from reoccurring:

- **RegressionTest.php** - Tests for known fixed issues
  - SQL DISTINCT + ORDER BY errors
  - Data type incompatibility
  - Placeholder name replacement
  - Transaction rollback
  - Batch processing timeouts
  - Cross-reference integrity

### 4. Data Type Compatibility Tests

Comprehensive tests for data type handling:

- **DataTypeCompatibilityTest.php** - Type conversion and validation
  - INT/VARCHAR conversion
  - Mixed type comparisons
  - SQL CAST generation
  - API response normalization
  - Unicode string handling
  - Large number handling

### 5. SQL Query Tests

Tests for SQL query correctness:

- **test_sql_queries_comprehensive.php** - SQL query validation
  - DISTINCT + ORDER BY patterns
  - JOIN compatibility
  - Type casting
  - Query performance

## Running Tests

### Local Development

#### Run All Tests

```bash
php run_all_mdm_tests.php
```

#### Run Specific Test Suite

```bash
# Unit tests
php run_sync_tests.php

# Integration tests
vendor/bin/phpunit tests/SyncIntegrationTest.php

# Regression tests
vendor/bin/phpunit tests/RegressionTest.php

# Data type tests
vendor/bin/phpunit tests/DataTypeCompatibilityTest.php
```

#### Run with PHPUnit

```bash
# All tests
vendor/bin/phpunit

# Specific test suite
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite integration

# With coverage
vendor/bin/phpunit --coverage-html coverage/
```

### CI/CD Pipeline

Tests run automatically on:

- Push to `main` or `develop` branches
- Pull requests
- Manual workflow dispatch

#### GitHub Actions Workflow

The `.github/workflows/mdm-tests.yml` workflow includes:

1. **Unit Tests** - Run on PHP 7.4, 8.0, 8.1, 8.2
2. **Integration Tests** - Run with MySQL 8.0
3. **Regression Tests** - Verify fixed issues
4. **Data Type Tests** - Validate type handling
5. **SQL Query Tests** - Test query correctness
6. **Code Quality** - Syntax and style checks
7. **Security Scan** - Dependency vulnerabilities

#### Viewing CI/CD Results

1. Go to GitHub repository
2. Click "Actions" tab
3. Select workflow run
4. View test results and logs

## Test Coverage

### Current Coverage

- **Unit Tests**: 95%+ coverage of core components
- **Integration Tests**: Full sync workflow coverage
- **Regression Tests**: All known fixed issues covered
- **Data Type Tests**: Comprehensive type handling coverage

### Coverage Reports

Generate coverage report:

```bash
vendor/bin/phpunit --coverage-html coverage/
```

View report:

```bash
open coverage/index.html
```

## Writing New Tests

### Test Naming Convention

- Test files: `*Test.php` or `test_*.php`
- Test methods: `test*` or `testCamelCase`
- Test classes: `*Test` extends `TestCase`

### Example Unit Test

```php
<?php
use PHPUnit\Framework\TestCase;

class MyComponentTest extends TestCase {
    private $component;

    protected function setUp(): void {
        $this->component = new MyComponent();
    }

    public function testBasicFunctionality() {
        $result = $this->component->doSomething();
        $this->assertEquals('expected', $result);
    }

    public function testErrorHandling() {
        $this->expectException(Exception::class);
        $this->component->doInvalidOperation();
    }
}
```

### Example Integration Test

```php
<?php
use PHPUnit\Framework\TestCase;

class MyIntegrationTest extends TestCase {
    private $testDb;

    protected function setUp(): void {
        $this->testDb = new PDO('sqlite::memory:');
        $this->createTestSchema();
    }

    public function testEndToEndWorkflow() {
        // Setup
        $this->insertTestData();

        // Execute
        $result = $this->runWorkflow();

        // Verify
        $this->assertDatabaseState();
    }
}
```

## Regression Test Checklist

When fixing a bug, add a regression test:

1. ✅ Identify the bug and root cause
2. ✅ Write a test that reproduces the bug
3. ✅ Verify the test fails before the fix
4. ✅ Implement the fix
5. ✅ Verify the test passes after the fix
6. ✅ Add test to regression suite
7. ✅ Document the test in this guide

## Known Fixed Issues (Regression Tests)

### Issue 1: SQL DISTINCT + ORDER BY Error

- **Test**: `testSQLDistinctOrderByNoError()`
- **Original Error**: "Expression #1 of ORDER BY clause is not in SELECT list"
- **Fix**: Include ORDER BY column in SELECT or use subquery
- **Status**: ✅ Fixed and tested

### Issue 2: Data Type Incompatibility

- **Test**: `testDataTypeCompatibilityInJoins()`
- **Original Error**: JOIN failures due to INT vs VARCHAR mismatch
- **Fix**: Use CAST in JOIN conditions
- **Status**: ✅ Fixed and tested

### Issue 3: Placeholder Names Not Replaced

- **Test**: `testPlaceholderNameReplacement()`
- **Original Error**: Dashboard showing "Товар Ozon ID 123"
- **Fix**: Detect and replace placeholder names
- **Status**: ✅ Fixed and tested

### Issue 4: Transaction Rollback Failures

- **Test**: `testSyncEngineTransactionRollback()`
- **Original Error**: Database inconsistency after failed sync
- **Fix**: Proper transaction handling with rollback
- **Status**: ✅ Fixed and tested

### Issue 5: Batch Processing Timeouts

- **Test**: `testBatchProcessingPreventsTimeout()`
- **Original Error**: Large syncs timing out
- **Fix**: Process in configurable batches
- **Status**: ✅ Fixed and tested

## Continuous Improvement

### Adding New Tests

1. Identify untested scenarios
2. Write test cases
3. Run tests locally
4. Submit PR with tests
5. Verify CI/CD passes

### Updating Existing Tests

1. Review test coverage reports
2. Identify gaps or outdated tests
3. Update test cases
4. Verify all tests pass
5. Update documentation

### Test Maintenance

- Review tests quarterly
- Remove obsolete tests
- Update for new features
- Maintain >90% coverage

## Troubleshooting

### Tests Failing Locally

1. Check PHP version (7.4+)
2. Install dependencies: `composer install`
3. Verify database connection
4. Check file permissions
5. Review error messages

### Tests Failing in CI/CD

1. Check workflow logs
2. Verify environment variables
3. Check database setup
4. Review dependency versions
5. Test locally with same PHP version

### Common Issues

**Issue**: PHPUnit not found

```bash
composer require --dev phpunit/phpunit
```

**Issue**: Database connection failed

```bash
# Check database credentials
# Verify database exists
# Test connection manually
```

**Issue**: Memory limit exceeded

```bash
php -d memory_limit=512M run_all_mdm_tests.php
```

## Best Practices

1. **Write tests first** (TDD approach)
2. **Keep tests isolated** (no dependencies between tests)
3. **Use descriptive names** (test purpose should be clear)
4. **Test edge cases** (null, empty, invalid data)
5. **Mock external dependencies** (APIs, databases)
6. **Clean up after tests** (tearDown method)
7. **Run tests frequently** (before commits)
8. **Maintain test data** (use fixtures)
9. **Document complex tests** (comments and docblocks)
10. **Review test coverage** (aim for >90%)

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [Test-Driven Development Guide](https://martinfowler.com/bliki/TestDrivenDevelopment.html)
- [MDM System Design Document](./design.md)

## Support

For questions or issues with tests:

1. Check this documentation
2. Review test code comments
3. Check CI/CD logs
4. Contact development team
