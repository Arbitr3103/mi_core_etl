# Implementation Plan

- [x] 1. Database schema preparation and analysis

  - Analyze current transaction types in fact_transactions table to understand data patterns
  - Add margin_percent column to metrics_daily table
  - Create necessary database indexes for performance optimization
  - _Requirements: 1.3, 3.1, 3.3_

- [x] 2. Enhanced SQL query development

  - Create comprehensive JOIN query combining fact_orders, dim_products, and fact_transactions
  - Implement transaction type classification logic for commissions and logistics
  - Add calculation for profit_sum using the complete margin formula
  - Add calculation for margin_percent with proper NULL handling
  - _Requirements: 1.1, 1.2, 2.1, 4.1, 4.2_

- [x] 3. Update aggregation script core logic

  - Modify run_aggregation.py to use the new enhanced SQL query
  - Implement margin percentage calculation function
  - Update the INSERT/UPDATE statement to include new margin fields
  - Add proper error handling and transaction management
  - _Requirements: 1.3, 2.3, 3.2, 4.3_

- [x] 4. Create comprehensive test suite

  - Create test data setup with products, orders, and transactions
  - Write unit tests for margin calculation functions
  - Create integration test for full aggregation process
  - Test edge cases (zero revenue, missing cost price, no transactions)
  - _Requirements: 3.2, 4.2_

- [x] 5. Performance optimization and validation

  - Test aggregation performance on historical data
  - Validate margin calculations against manual calculations
  - Optimize SQL query performance if needed
  - Create monitoring and logging for margin calculation process
  - _Requirements: 3.1, 3.2_

- [x] 6. Documentation and deployment preparation
  - Update technical documentation for new margin calculation logic
  - Create migration script for existing data recalculation
  - Prepare rollback plan in case of issues
  - Document new fields and their meaning for end users
  - _Requirements: 1.3, 3.3_
