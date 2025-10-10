# Task 5: Testing Quick Start Guide

## Быстрый запуск тестирования MDM системы

This guide provides quick commands to run all tests for Task 5.

---

## Prerequisites

- PHP 7.4+ installed
- MySQL database configured
- `.env` file with database credentials
- MDM system tables created

---

## Quick Test Commands

### 1. Run SQL Query Tests (Task 5.1)

Test all fixed SQL queries for compatibility and performance:

```bash
php tests/test_sql_queries_comprehensive.php
```

**What it tests:**

- ✅ MySQL compatibility (ONLY_FULL_GROUP_BY, window functions, CTEs)
- ✅ Fixed query execution (DISTINCT, JOINs, GROUP BY)
- ✅ Performance on different dataset sizes
- ✅ Data correctness validation

**Expected output:**

```
╔════════════════════════════════════════════════════════════════╗
║  COMPREHENSIVE SQL QUERY TESTING SUITE                        ║
║  Task 5.1: Тестирование исправленных SQL запросов             ║
╚════════════════════════════════════════════════════════════════╝

📊 MySQL Version: 9.4.0
🗄️  Database: mi_core
...
🎉 ALL TESTS PASSED! SQL queries are production-ready.
```

---

### 2. Run Product Sync Tests (Task 5.2)

Test product name synchronization and fallback mechanisms:

```bash
php tests/test_product_sync_comprehensive.php
```

**What it tests:**

- ✅ Sync script execution
- ✅ Data updates in dim_products
- ✅ Fallback mechanisms
- ✅ Dashboard display validation

**Expected output:**

```
╔════════════════════════════════════════════════════════════════╗
║  PRODUCT NAME SYNCHRONIZATION TESTING SUITE                   ║
║  Task 5.2: Тестирование синхронизации названий товаров        ║
╚════════════════════════════════════════════════════════════════╝

🗄️  Database: mi_core
...
🎉 ALL TESTS PASSED! Product synchronization is working correctly.
```

---

### 3. Run All Tests

Run both test suites sequentially:

```bash
# Run SQL tests
php tests/test_sql_queries_comprehensive.php

# Run sync tests
php tests/test_product_sync_comprehensive.php
```

Or create a simple script:

```bash
#!/bin/bash
echo "Running Task 5 Tests..."
echo "======================="
echo ""
echo "Test 5.1: SQL Queries"
php tests/test_sql_queries_comprehensive.php
echo ""
echo "Test 5.2: Product Sync"
php tests/test_product_sync_comprehensive.php
echo ""
echo "All tests completed!"
```

---

## Test Reports

After running tests, check the generated reports:

### SQL Test Report

```bash
cat logs/sql_test_report_*.json | jq '.'
```

### Sync Test Report

```bash
cat logs/sync_test_report_*.json | jq '.'
```

### View Latest Reports

```bash
# SQL test report
ls -t logs/sql_test_report_*.json | head -1 | xargs cat | jq '.summary'

# Sync test report
ls -t logs/sync_test_report_*.json | head -1 | xargs cat | jq '.summary'
```

---

## Understanding Test Results

### Success Indicators

✅ **All tests passed:**

```
📊 SUMMARY:
  Total Tests: 18
  Passed: 18 ✅
  Failed: 0 ❌
  Success Rate: 100.0%
```

⚠️ **Some tests failed:**

```
📊 SUMMARY:
  Total Tests: 18
  Passed: 17 ✅
  Failed: 1 ❌
  Success Rate: 94.4%

❌ FAILED TESTS:
  - [MySQL Compatibility] Window Functions Support
```

### Performance Metrics

Good performance indicators:

- Query time < 100ms for simple queries
- Query time < 500ms for complex JOINs
- Query time < 2000ms for large datasets

Example:

```
✅ Small Dataset (LIMIT 10)
   Avg Time: 0.71ms | Threshold: 100ms | Rows: 10
```

---

## Troubleshooting

### Database Connection Error

**Error:**

```
❌ Ошибка подключения к БД: SQLSTATE[HY000] [1045] Access denied
```

**Solution:**

1. Check `.env` file exists
2. Verify database credentials
3. Ensure MySQL is running

```bash
# Test database connection
php -r "
require_once 'config.php';
try {
    \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
    echo '✅ Database connection successful\n';
} catch (Exception \$e) {
    echo '❌ Connection failed: ' . \$e->getMessage() . '\n';
}
"
```

### Missing Tables Error

**Error:**

```
❌ Table 'mi_core.product_cross_reference' doesn't exist
```

**Solution:**
Run the migration scripts:

```bash
# Create product_cross_reference table
php -r "
require_once 'config.php';
\$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
\$sql = file_get_contents('create_product_cross_reference_table.sql');
\$pdo->exec(\$sql);
echo '✅ Table created\n';
"
```

### Collation Mismatch Error

**Error:**

```
❌ Illegal mix of collations (utf8mb4_general_ci,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT)
```

**Solution:**
This is already fixed in the test queries with `COLLATE utf8mb4_general_ci`. If you see this error, ensure you're using the latest test files.

---

## Test Coverage

### Task 5.1: SQL Query Testing

| Category            | Tests  | Coverage                        |
| ------------------- | ------ | ------------------------------- |
| MySQL Compatibility | 4      | Version checks, mode validation |
| Fixed Queries       | 5      | All critical queries            |
| Performance         | 4      | Small, medium, large datasets   |
| Data Correctness    | 5      | Validation rules                |
| **Total**           | **18** | **Comprehensive**               |

### Task 5.2: Product Sync Testing

| Category            | Tests  | Coverage                         |
| ------------------- | ------ | -------------------------------- |
| Sync Script         | 5      | Engine initialization, execution |
| Data Updates        | 6      | Database updates, mappings       |
| Fallback Mechanisms | 5      | Cache, temporary names           |
| Dashboard Display   | 5      | Query performance, API           |
| **Total**           | **21** | **Comprehensive**                |

---

## Next Steps

After running tests successfully:

1. **Review Reports**

   ```bash
   cat TASK_5_TESTING_REPORT.md
   ```

2. **Check Data Quality**

   ```bash
   php -r "
   require_once 'config.php';
   require_once 'src/SafeSyncEngine.php';
   \$engine = new SafeSyncEngine();
   \$stats = \$engine->getSyncStatistics();
   print_r(\$stats);
   "
   ```

3. **Run Initial Sync** (if needed)

   ```bash
   php sync-real-product-names-v2.php --limit 100
   ```

4. **Monitor Progress**
   ```bash
   # Check sync status
   php -r "
   require_once 'config.php';
   \$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
   \$stmt = \$pdo->query('SELECT sync_status, COUNT(*) as count FROM product_cross_reference GROUP BY sync_status');
   foreach (\$stmt->fetchAll() as \$row) {
       echo \$row['sync_status'] . ': ' . \$row['count'] . \"\n\";
   }
   "
   ```

---

## Continuous Testing

### Add to CI/CD Pipeline

```yaml
# .github/workflows/test.yml
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
          php-version: "8.1"
      - name: Run SQL Tests
        run: php tests/test_sql_queries_comprehensive.php
      - name: Run Sync Tests
        run: php tests/test_product_sync_comprehensive.php
```

### Scheduled Testing

```bash
# Add to crontab for daily testing
0 2 * * * cd /path/to/project && php tests/test_sql_queries_comprehensive.php >> logs/daily_tests.log 2>&1
```

---

## Support

For issues or questions:

1. Check `TASK_5_TESTING_REPORT.md` for detailed results
2. Review test logs in `logs/` directory
3. Examine individual test files for specific test logic

---

**Last Updated:** 2025-10-10  
**Version:** 1.0  
**Status:** ✅ Production Ready
