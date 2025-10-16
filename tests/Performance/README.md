# Performance Tests for Active Product Filter System

This directory contains comprehensive performance tests for the active product filtering system.

## Test Files

### 1. ActiveProductFilterPerformanceTest.php

PHPUnit-based performance tests that cover:

- Dashboard API performance with active product filtering
- Database performance with various data volumes
- System performance benchmarking before/after optimization
- Memory efficiency testing
- Concurrent load testing

### 2. dashboard_load_test.php

Standalone load testing script for dashboard API:

- Basic load performance testing
- High frequency request testing
- Concurrent user simulation
- Endpoint-specific load characteristics
- Data volume impact analysis

### 3. database_performance_benchmark.php

Database performance benchmarking tool:

- Current system performance baseline
- Performance testing with various data volumes (500-10,000 products)
- Query optimization testing
- Index effectiveness analysis
- Concurrent query performance

### 4. simple_performance_test.php

Lightweight performance test that works without external dependencies:

- API response time simulation
- Memory usage monitoring
- Load simulation testing

### 5. Support Files

- `performance_test_config.php` - Configuration and database setup
- `mock_api_server.php` - Mock API server for testing
- `run_active_product_filter_performance_tests.php` - Test runner

## Running Tests

### Run All Performance Tests

```bash
php tests/Performance/run_active_product_filter_performance_tests.php
```

### Run Individual Tests

```bash
# PHPUnit tests (requires PHPUnit)
phpunit tests/Performance/ActiveProductFilterPerformanceTest.php

# Dashboard load tests
php tests/Performance/dashboard_load_test.php

# Database benchmarks
php tests/Performance/database_performance_benchmark.php

# Simple performance test
php tests/Performance/simple_performance_test.php
```

## Test Requirements

### Database Requirements

- MySQL database (automatically creates test database if needed)
- Tables: `products`, `inventory_data`, `product_activity_log`
- Test data is automatically created and cleaned up

### API Requirements

- Dashboard API endpoint at `/api/inventory-analytics.php`
- Falls back to mock responses if API unavailable

## Performance Thresholds

The tests use the following performance thresholds:

- API response time: < 1000ms
- Database query time: < 500ms
- Memory usage: < 100MB

## Test Results

Test results are saved to `tests/results/` directory:

- JSON reports with detailed metrics
- HTML reports for easy viewing
- Performance trend analysis

## Key Metrics Tested

1. **API Performance**

   - Response times for critical endpoints
   - Success rates under load
   - Throughput measurements

2. **Database Performance**

   - Query execution times
   - Scaling characteristics
   - Index effectiveness

3. **System Performance**

   - Memory usage patterns
   - Concurrent request handling
   - Performance degradation analysis

4. **Load Testing**
   - Burst request handling
   - Sustained load performance
   - Concurrent user simulation

## Optimization Recommendations

Based on test results, the system provides recommendations for:

- Database index optimization
- Query performance improvements
- Memory usage optimization
- API response caching
- Connection pooling configuration

## Requirements Coverage

These tests fulfill **Requirement 2.4**: Performance optimization for active product filtering by providing:

- ✅ Load tests for dashboard API with active product filtering
- ✅ Database performance testing with various data volumes
- ✅ System performance benchmarking before and after optimization
- ✅ Comprehensive performance reporting and analysis
