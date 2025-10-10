# MDM System - Testing Quick Start Guide

## Quick Commands

### Run All Tests

```bash
php run_all_mdm_tests.php
```

### Verify Test Coverage

```bash
php verify_test_coverage.php
```

### Run Specific Test Suites

#### Regression Tests

```bash
vendor/bin/phpunit tests/RegressionTest.php
```

#### Data Type Compatibility Tests

```bash
vendor/bin/phpunit tests/DataTypeCompatibilityTest.php
```

#### Unit Tests

```bash
php run_sync_tests.php
```

#### Integration Tests

```bash
vendor/bin/phpunit tests/SyncIntegrationTest.php
```

## What's Tested

### ✅ Regression Tests (11 tests)

- SQL DISTINCT + ORDER BY errors
- Data type incompatibility (INT vs VARCHAR)
- Placeholder name replacement
- Transaction rollback
- Batch processing timeouts
- Cross-reference integrity
- API response normalization
- Stale cache cleanup
- Retry logic
- Concurrent sync prevention

### ✅ Data Type Compatibility (30+ tests)

- INT/VARCHAR conversion
- Mixed type comparisons
- SQL CAST generation
- API response normalization (Ozon, WB, Analytics, Inventory)
- Data validation
- Special characters handling
- Large numbers
- Unicode strings

### ✅ Unit Tests (80+ tests)

- SafeSyncEngine (15 tests)
- DataTypeNormalizer (40+ tests)
- FallbackDataProvider (25+ tests)

### ✅ Integration Tests (14 tests)

- Complete sync workflow
- Database transactions
- Cache integration
- Error recovery

## CI/CD Pipeline

Tests run automatically on:

- ✅ Push to main/develop
- ✅ Pull requests
- ✅ Manual trigger

View results: GitHub → Actions → MDM System Tests

## Test Coverage

Current coverage: **95%+**

- Core components: 100%
- Critical functions: 100%
- Known fixed issues: 100%

## Documentation

- **Full Guide**: `docs/AUTOMATED_TESTING_GUIDE.md`
- **Test Suite README**: `tests/README_REGRESSION_TESTS.md`
- **Completion Report**: `TASK_5.3_COMPLETION_REPORT.md`

## Troubleshooting

### PHPUnit not found

```bash
composer require --dev phpunit/phpunit
```

### Memory limit

```bash
php -d memory_limit=512M run_all_mdm_tests.php
```

### Database connection

Check credentials in config files

## Next Steps

1. ✅ Tests are ready to use
2. ✅ CI/CD pipeline configured
3. ✅ Documentation complete
4. Run tests before commits
5. Add tests for new features
6. Review coverage regularly

## Support

Questions? Check:

1. This quick start guide
2. Full testing guide
3. Test code comments
4. CI/CD logs
