# MarketplaceDetector Unit Tests

## Overview

This test suite provides comprehensive unit testing for the `MarketplaceDetector` class, which is responsible for identifying marketplaces (Ozon, Wildberries) based on various data sources and building SQL filters for marketplace-specific queries.

## Test Coverage

### 1. Source Code Detection (`detectFromSourceCode`)

- ✅ Ozon patterns: 'ozon', 'OZON', 'ozon_api', 'озон', 'ОЗОН'
- ✅ Wildberries patterns: 'wildberries', 'wb', 'вб', 'wb_api'
- ✅ Case insensitivity
- ✅ Whitespace handling
- ✅ Edge cases: empty strings, null values, unknown sources
- ✅ Partial matches that should fail

### 2. Source Name Detection (`detectFromSourceName`)

- ✅ Detection in marketplace names
- ✅ Cyrillic character support
- ✅ Case insensitivity
- ✅ Edge cases: empty names, unknown sources

### 3. SKU-based Detection (`detectFromSku`)

- ✅ Exact SKU matches for Ozon and Wildberries
- ✅ No match scenarios
- ✅ Edge cases: empty SKUs, null values
- ✅ Ambiguous cases: same SKU for both marketplaces
- ✅ Single marketplace SKU scenarios
- ✅ Case sensitivity (exact match required)

### 4. Complex Detection Logic (`detectMarketplace`)

- ✅ Priority 1: Source code takes precedence
- ✅ Priority 2: Source name when source code is empty
- ✅ Priority 3: SKU when both source code and name are empty
- ✅ All unknown scenarios
- ✅ All empty parameter scenarios

### 5. Utility Methods

- ✅ `getAllMarketplaces()` - returns correct marketplace list
- ✅ `getMarketplaceName()` - returns human-readable names
- ✅ `getMarketplaceIcon()` - returns appropriate icons
- ✅ Handling of invalid marketplace constants

### 6. Parameter Validation (`validateMarketplaceParameter`)

- ✅ Valid inputs: null, valid marketplace constants
- ✅ Case insensitive validation
- ✅ Whitespace handling
- ✅ Invalid inputs: non-strings, empty strings, unknown marketplaces
- ✅ Proper error messages

### 7. SQL Filter Building

- ✅ Ozon filter generation with correct conditions and parameters
- ✅ Wildberries filter generation with multiple patterns
- ✅ Null marketplace handling (all marketplaces)
- ✅ Custom table aliases support
- ✅ Exclude filter generation
- ✅ SQL injection prevention (parameterized queries)
- ✅ Exception handling for invalid marketplaces

### 8. Edge Cases and Error Handling

- ✅ Missing data scenarios (all null, all empty, whitespace only)
- ✅ Case sensitivity rules
- ✅ Exception throwing for invalid parameters
- ✅ Proper error messages

## Requirements Coverage

This test suite validates the following requirements from the marketplace data separation specification:

- **Requirement 6.1**: Marketplace identification using source field, sku_ozon, and sku_wb fields
- **Requirement 6.2**: Correct classification of historical data by marketplace
- **Requirement 6.4**: Handling cases where marketplace information is missing ("Неопределенный источник")

## Test Execution

### Run MarketplaceDetector tests only:

```bash
php test_marketplace_detector_simple.php
```

### Run all tests including MarketplaceDetector:

```bash
php tests/run_all_tests.php
```

## Test Structure

The test class follows the existing project pattern:

- Custom assertion methods for clear error reporting
- Russian language output for consistency with project
- Detailed test descriptions and success/failure reporting
- Comprehensive edge case coverage
- Mock PDO object for database-dependent methods

## Expected Output

When all tests pass, you should see:

- ✅ Individual test results with descriptive messages
- 📊 Summary statistics (total, passed, failed, success rate)
- 📋 List of tested functionality
- 🎯 Requirements compliance confirmation
- 🎉 Success message

## Security Considerations

The tests specifically verify:

- SQL injection prevention through parameterized queries
- Input validation and sanitization
- Proper error handling without information disclosure
- Safe handling of user-provided marketplace parameters

## Performance Considerations

The tests are designed to:

- Execute quickly without database dependencies
- Use mock objects to avoid external dependencies
- Provide comprehensive coverage without redundant testing
- Support CI/CD integration with proper exit codes
