# Marketplace API Integration Tests

This directory contains comprehensive integration tests for the marketplace-specific API endpoints, covering requirements 1.4, 2.4, 3.4, and 4.4 from the marketplace data separation specification.

## Test Files Overview

### Core Test Classes

1. **MarketplaceAPIIntegrationTest.php**

   - Main integration test class
   - Tests actual API methods with database connections
   - Validates data accuracy and consistency
   - Covers all marketplace-specific functionality

2. **MarketplaceHTTPEndpointTest.php**

   - HTTP endpoint integration tests
   - Tests API responses via HTTP requests
   - Validates response formats and headers
   - Tests error handling via HTTP

3. **MarketplaceSampleDataTest.php**
   - Sample data generation and validation
   - Tests API structure without requiring real database
   - Validates data formats and consistency
   - Useful for development and CI environments

### Test Runners

1. **run_marketplace_integration_tests.php**
   - Main test runner script
   - Supports both real database and sample data modes
   - Configurable via environment variables

## Requirements Coverage

### Requirement 1.4: Marketplace Data Display

- ✅ Tests margin summary by marketplace
- ✅ Validates separated view functionality
- ✅ Tests marketplace comparison data
- ✅ Validates response format and structure

### Requirement 2.4: Daily Chart Marketplace Separation

- ✅ Tests daily margin chart by marketplace
- ✅ Validates chart data structure
- ✅ Tests date range handling
- ✅ Validates missing date filling

### Requirement 3.4: Top Products Marketplace Separation

- ✅ Tests top products by marketplace
- ✅ Validates SKU display logic (sku_ozon vs sku_wb)
- ✅ Tests product ranking within marketplace
- ✅ Validates marketplace-specific filtering

### Requirement 4.4: Recommendations Marketplace Separation

- ✅ Tests recommendations summary by marketplace
- ✅ Tests recommendations list filtering
- ✅ Tests turnover data by marketplace
- ✅ Validates separated recommendations view

## Running the Tests

### Prerequisites

1. **PHP 7.4+** with PDO extension
2. **Database access** (for full integration tests)
3. **MarginDashboardAPI.php** and **RecommendationsAPI.php** classes

### Configuration

Set up your test database configuration:

```bash
# Environment variables (recommended)
export TEST_DB_HOST="localhost"
export TEST_DB_NAME="mi_core_db"
export TEST_DB_USER="your_username"
export TEST_DB_PASS="your_password"
```

Or modify the configuration in `run_marketplace_integration_tests.php`:

```php
$testConfig = [
    'host' => 'localhost',
    'dbname' => 'mi_core_db',
    'username' => 'your_username',
    'password' => 'your_password'
];
```

### Running Tests

#### Full Integration Tests (with real database)

```bash
php run_marketplace_integration_tests.php
```

#### Sample Data Mode (without database)

```bash
php run_marketplace_integration_tests.php --sample-data
```

#### Individual Test Classes

```bash
# Run specific test class
php -r "
require_once 'tests/MarketplaceAPIIntegrationTest.php';
\$test = new MarketplaceAPIIntegrationTest([
    'host' => 'localhost',
    'dbname' => 'mi_core_db',
    'username' => 'user',
    'password' => 'pass'
]);
\$test->runAllTests();
"
```

## Test Categories

### 1. API Method Tests

- **Margin API Methods**

  - `getMarginSummaryByMarketplace()`
  - `getTopProductsByMarketplace()`
  - `getDailyMarginChartByMarketplace()`
  - `getMarketplaceComparison()`

- **Recommendations API Methods**
  - `getSummary()` with marketplace filtering
  - `getRecommendations()` with marketplace filtering
  - `getTurnoverTop()` with marketplace filtering
  - `getRecommendationsByMarketplace()`

### 2. HTTP Endpoint Tests

- **Margin API Endpoints**

  - `/margin_api.php?action=summary&marketplace=ozon`
  - `/margin_api.php?action=top_products&marketplace=wildberries`
  - `/margin_api.php?action=separated_view`
  - `/margin_api.php?action=marketplace_comparison`

- **Recommendations API Endpoints**
  - `/recommendations_api.php?action=summary&marketplace=ozon`
  - `/recommendations_api.php?action=list&marketplace=wildberries`
  - `/recommendations_api.php?action=separated_view`

### 3. Error Handling Tests

- Invalid marketplace parameters
- Missing required parameters
- Database connection errors
- Empty data scenarios

### 4. Data Validation Tests

- Response structure validation
- Data type validation
- SKU display logic validation
- Marketplace filtering accuracy
- Data consistency checks

## Expected Test Output

### Successful Test Run

```
=== Marketplace API Integration Tests ===

1. TESTING MARGIN API ENDPOINTS
===============================

Testing: margin_summary_all... ✓ PASSED
Testing: margin_summary_ozon... ✓ PASSED
Testing: margin_summary_wildberries... ✓ PASSED
Testing: top_products_ozon... ✓ PASSED
Testing: top_products_wildberries... ✓ PASSED
Testing: daily_chart_ozon... ✓ PASSED
Testing: daily_chart_wildberries... ✓ PASSED
Testing: marketplace_comparison... ✓ PASSED

2. TESTING RECOMMENDATIONS API ENDPOINTS
========================================

Testing: recommendations_summary_all... ✓ PASSED
Testing: recommendations_summary_ozon... ✓ PASSED
Testing: recommendations_summary_wildberries... ✓ PASSED
Testing: recommendations_list_ozon... ✓ PASSED
Testing: recommendations_list_wildberries... ✓ PASSED
Testing: turnover_top_ozon... ✓ PASSED
Testing: turnover_top_wildberries... ✓ PASSED
Testing: recommendations_separated_view... ✓ PASSED

3. TESTING ERROR HANDLING
=========================

Testing: invalid_marketplace_margin... ✓ PASSED (Error properly handled)
Testing: invalid_marketplace_recommendations... ✓ PASSED (Error properly handled)
Testing: invalid_date_parameters... ✓ PASSED
Testing: missing_data_handling... ✓ PASSED

4. TESTING API RESPONSE FORMATS
===============================

Testing: margin_api_summary_ozon... ✓ PASSED
Testing: margin_api_separated_view... ✓ PASSED
Testing: recommendations_api_summary_wildberries... ✓ PASSED
Testing: recommendations_api_separated_view... ✓ PASSED

5. TESTING DATA ACCURACY
========================

Testing: data_consistency_check... ✓ PASSED
Testing: sku_display_logic... ✓ PASSED

=== TEST SUMMARY ===

Total tests: 22
Passed: 22
Failed: 0

Test coverage:
- Requirement 1.4 (Marketplace data display): ✓ Covered
- Requirement 2.4 (Daily chart marketplace separation): ✓ Covered
- Requirement 3.4 (Top products marketplace separation): ✓ Covered
- Requirement 4.4 (Recommendations marketplace separation): ✓ Covered

🎉 ALL TESTS PASSED! Integration tests completed successfully.
```

## Troubleshooting

### Common Issues

1. **Database Connection Failed**

   ```
   ERROR: Failed to initialize integration tests
   Message: Ошибка подключения к БД: SQLSTATE[HY000] [2002] Connection refused
   ```

   **Solution**: Check database credentials and ensure database server is running

2. **Missing API Classes**

   ```
   ERROR: Failed to initialize integration tests
   Message: Class 'MarginDashboardAPI' not found
   ```

   **Solution**: Ensure `MarginDashboardAPI.php` and `RecommendationsAPI.php` are in the correct location

3. **No Test Data**
   ```
   Testing: margin_summary_ozon... ✗ FAILED: No data found for specified period
   ```
   **Solution**: Use sample data mode or ensure test database has data for the test period

### Debug Mode

To enable detailed logging, modify the test configuration:

```php
// In MarginDashboardAPI constructor, enable logging
private $logFile = 'margin_api_test.log';
```

Check the log files for detailed API call information.

## Extending the Tests

### Adding New Test Cases

1. **Add to MarketplaceAPIIntegrationTest.php**:

```php
private function testNewFunctionality() {
    $this->runTest('new_test_name', function() {
        // Your test logic here
        $result = $this->marginAPI->newMethod();
        $this->validateNewStructure($result);
        return $result;
    });
}
```

2. **Add validation methods**:

```php
private function validateNewStructure($result) {
    $this->assert(is_array($result), 'Result should be array');
    // Add more validations
}
```

### Adding New HTTP Endpoint Tests

Add to `MarketplaceHTTPEndpointTest.php`:

```php
'new_endpoint_test' => [
    'url' => '/new_api.php?action=new_action&marketplace=ozon',
    'expected_fields' => ['success', 'data', 'meta'],
    'description' => 'Test new endpoint functionality'
]
```

## Continuous Integration

### GitHub Actions Example

```yaml
name: Marketplace API Tests

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
          extensions: pdo, pdo_mysql

      - name: Run Sample Data Tests
        run: php run_marketplace_integration_tests.php --sample-data
```

## Performance Benchmarks

The tests include execution time measurements. Expected performance:

- **API Method Tests**: < 100ms per test
- **HTTP Endpoint Tests**: < 200ms per test (with mock responses)
- **Data Validation Tests**: < 50ms per test
- **Total Test Suite**: < 5 seconds

## Contributing

When adding new marketplace functionality:

1. Add corresponding test cases to the integration tests
2. Update the requirements coverage documentation
3. Ensure both real database and sample data modes work
4. Add HTTP endpoint tests for new API endpoints
5. Update this README with new test information

## Support

For issues with the integration tests:

1. Check the troubleshooting section above
2. Review the test logs for detailed error information
3. Ensure all prerequisites are met
4. Try running in sample data mode to isolate database issues
