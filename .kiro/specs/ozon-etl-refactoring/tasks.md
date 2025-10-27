# Implementation Plan

-   [x] 1. Database Schema Migration

    -   Create migration script to add visibility field to dim_products table
    -   Add database index for performance optimization on visibility field
    -   Create backup of current schema before migration
    -   _Requirements: 2.1, 3.1, 3.3_

-   [x] 1.1 Create database migration script

    -   Write SQL migration script with ALTER TABLE statement for dim_products
    -   Include CREATE INDEX statement for idx_dim_products_visibility
    -   Add rollback script for safe migration reversal
    -   _Requirements: 2.1, 3.1_

-   [x] 1.2 Execute schema migration with backup

    -   Create full database backup before applying changes
    -   Execute migration script on development environment first
    -   Validate schema changes and index creation
    -   _Requirements: 2.1, 3.1_

-   [x] 2. Refactor ProductETL Component

    -   Modify ProductETL to use products report API instead of product list API
    -   Implement CSV parsing logic for products report
    -   Add visibility field processing and normalization
    -   Update upsert logic to include visibility field
    -   _Requirements: 1.1, 1.2, 2.1_

-   [x] 2.1 Update API extraction method

    -   Replace getProducts() call with createProductsReport() in extract() method
    -   Implement report polling logic similar to InventoryETL
    -   Add CSV download and parsing functionality
    -   _Requirements: 1.1, 1.2_

-   [x] 2.2 Implement CSV parsing for products data

    -   Create validateProductsCsvStructure() method for CSV validation
    -   Parse CSV headers and map to expected product fields
    -   Extract visibility field from CSV and validate values
    -   _Requirements: 1.1, 1.2, 2.1_

-   [x] 2.3 Add visibility status normalization

    -   Create normalizeVisibilityStatus() method with status mapping
    -   Map Ozon visibility values to standardized internal values
    -   Handle unknown or null visibility values with default mapping
    -   _Requirements: 1.2, 2.1_

-   [x] 2.4 Update database loading logic

    -   Modify loadProductBatch() method to include visibility field
    -   Update upsert SQL to handle visibility field in ON CONFLICT clause
    -   Ensure visibility field is properly updated for existing products
    -   _Requirements: 1.3, 2.1_

-   [x] 2.5 Write unit tests for ProductETL changes

    -   Test visibility status normalization with various input values
    -   Test CSV parsing with different CSV structures and edge cases
    -   Test database upsert logic with visibility field updates
    -   _Requirements: 1.1, 1.2, 2.1_

-   [x] 3. Update v_detailed_inventory View Logic

    -   Rewrite view SQL to implement new stock status calculation logic
    -   Add JOIN with dim_products table to access visibility field
    -   Implement combined visibility and availability logic for stock status
    -   Test view performance with new logic
    -   _Requirements: 1.4, 1.5, 4.1, 4.3_

-   [x] 3.1 Implement new stock status calculation

    -   Create CASE statement for stock_status with visibility and availability logic
    -   Define status hierarchy: archived_or_hidden > out_of_stock > critical > low > normal > excess
    -   Ensure proper handling of NULL values and edge cases
    -   _Requirements: 1.4, 1.5, 4.1_

-   [x] 3.2 Add visibility-based filtering

    -   Include visibility field in SELECT clause of the view
    -   Add WHERE clause to filter out hidden products by default
    -   Ensure available_stock calculation uses (present - reserved) formula
    -   _Requirements: 1.4, 4.1, 4.3_

-   [x] 3.3 Optimize view performance

    -   Add proper JOIN conditions between inventory, dim_products, and warehouse_sales_metrics
    -   Ensure indexes are utilized effectively in the new view logic
    -   Test query execution time with large datasets
    -   _Requirements: 3.4, 4.4_

-   [x] 3.4 Create view performance tests

    -   Write SQL queries to test view performance with different data volumes
    -   Measure query execution time and identify potential bottlenecks
    -   Create test cases for various filtering scenarios
    -   _Requirements: 3.4, 4.4_

-   [x] 4. Update API Endpoint Logic

    -   Modify /api/inventory/detailed-stock endpoint to work with enhanced view
    -   Add filtering options for stock_status field
    -   Implement default filtering to exclude archived and out-of-stock items
    -   Maintain backward compatibility with existing API consumers
    -   _Requirements: 4.1, 4.2, 4.3, 4.4_

-   [x] 4.1 Enhance API filtering capabilities

    -   Add support for stock_status filtering in API parameters
    -   Implement include_hidden parameter to optionally show hidden products
    -   Add visibility field to API response structure
    -   _Requirements: 4.1, 4.2, 4.3_

-   [x] 4.2 Update default API behavior

    -   Modify default query to exclude archived_or_hidden and out_of_stock items
    -   Ensure API returns only products that are truly "in sale" by default
    -   Add documentation for new filtering behavior
    -   _Requirements: 4.1, 4.3, 4.4_

-   [x] 4.3 Write API integration tests

    -   Test API response with new visibility and stock_status fields
    -   Test filtering functionality with various parameter combinations
    -   Verify backward compatibility with existing API consumers
    -   _Requirements: 4.1, 4.2, 4.3, 4.4_

-   [x] 5. Update ETL Scheduler Configuration

    -   Modify cron configuration to ensure ProductETL runs before InventoryETL
    -   Add dependency management between ETL processes
    -   Update monitoring to track both ETL components separately
    -   Implement error handling for ETL sequence failures
    -   _Requirements: 5.1, 5.2, 5.3, 5.4_

-   [x] 5.1 Configure ETL execution sequence

    -   Update cron jobs to run ProductETL before InventoryETL with proper timing
    -   Add dependency checks to prevent InventoryETL from running if ProductETL failed
    -   Implement retry logic for failed ETL processes
    -   _Requirements: 5.1, 5.2_

-   [x] 5.2 Enhance ETL monitoring and logging

    -   Add separate logging for ProductETL visibility processing
    -   Create metrics tracking for visibility field updates and status distribution
    -   Implement alerts for ETL sequence failures or data quality issues
    -   _Requirements: 5.2, 5.3, 5.4_

-   [x] 5.3 Create ETL integration tests

    -   Write end-to-end tests for complete ETL workflow
    -   Test ETL sequence execution and dependency handling
    -   Verify data consistency after both ETL processes complete
    -   _Requirements: 5.1, 5.2, 5.3_

-   [x] 6. Data Validation and Verification

    -   Create validation scripts to compare results with Ozon personal cabinet
    -   Implement data quality checks for visibility and stock status accuracy
    -   Set up monitoring for data consistency between ETL runs
    -   Create verification reports for business stakeholders
    -   _Requirements: 6.1, 6.2, 6.3, 6.4_

-   [x] 6.1 Implement data accuracy validation

    -   Create SQL queries to count active products and compare with Ozon cabinet
    -   Implement spot-checking logic for random product samples
    -   Generate validation reports with discrepancy analysis
    -   _Requirements: 6.1, 6.2_

-   [x] 6.2 Create business verification tools

    -   Build verification dashboard showing before/after comparison
    -   Implement export functionality for validation results
    -   Create automated reports for business stakeholders
    -   _Requirements: 6.2, 6.3, 6.4_

-   [x] 6.3 Write data quality tests

    -   Create automated tests for data consistency validation
    -   Test edge cases and boundary conditions in business logic
    -   Implement regression tests to prevent data quality degradation
    -   _Requirements: 6.1, 6.2, 6.3, 6.4_

-   [x] 7. Documentation and Deployment

    -   Update system documentation with new architecture and business logic
    -   Create deployment guide for production rollout
    -   Prepare rollback procedures in case of issues
    -   Train operations team on new monitoring and troubleshooting procedures
    -   _Requirements: 5.4, 6.4_

-   [x] 7.1 Create comprehensive documentation

    -   Document new ETL architecture and data flow
    -   Update API documentation with new fields and filtering options
    -   Create troubleshooting guide for common issues
    -   _Requirements: 5.4, 6.4_

-   [x] 7.2 Prepare production deployment plan

    -   Create step-by-step deployment checklist
    -   Prepare database migration scripts for production
    -   Set up monitoring and alerting for production deployment
    -   _Requirements: 5.4, 6.4_

-   [x] 7.3 Create deployment verification tests

    -   Write post-deployment validation scripts
    -   Create smoke tests for production environment
    -   Implement rollback verification procedures
    -   _Requirements: 5.4, 6.4_

-   [ ] 8. Performance Optimization with Materialized Status Table

    -   Create inventory_stock_status table for pre-computed stock statuses
    -   Implement refresh function to update materialized data after ETL
    -   Update API to use materialized table instead of complex view
    -   Optimize queries with simple indexed lookups
    -   _Requirements: 3.4, 4.4_

-   [ ] 8.1 Create inventory_stock_status table migration

    -   Design table schema with product_id, warehouse_name, stock_status, and metadata
    -   Add proper indexes for fast lookups by product_id, warehouse_name, and stock_status
    -   Create migration script with rollback capability
    -   _Requirements: 3.4, 4.4_

-   [ ] 8.2 Implement stock status calculation function

    -   Create refresh_inventory_stock_status() PostgreSQL function
    -   Implement visibility and availability logic from view as stored procedure
    -   Add proper error handling and logging for calculation process
    -   _Requirements: 1.4, 1.5, 3.4, 4.1_

-   [ ] 8.3 Update InventoryETL to refresh materialized data

    -   Add call to refresh_inventory_stock_status() after inventory loading
    -   Implement transaction handling to ensure data consistency
    -   Add performance logging for materialization process
    -   _Requirements: 1.3, 3.4, 5.1_

-   [ ] 8.4 Create simplified API queries

    -   Replace complex view queries with simple SELECT from inventory_stock_status
    -   Add JOIN with inventory table for detailed stock information
    -   Implement fast filtering by pre-computed stock_status field
    -   _Requirements: 4.1, 4.3, 4.4_

-   [ ] 8.5 Performance comparison and validation

    -   Create benchmark tests comparing view vs materialized table performance
    -   Measure query execution time improvements for different scenarios
    -   Validate data consistency between view and materialized table
    -   _Requirements: 3.4, 4.4, 6.1_

-   [ ] 9. Enhanced ProductETL with Visibility Report

    -   Refactor ProductETL to use /v1/report/products/create API
    -   Implement proper CSV parsing for products report format
    -   Add robust error handling for report generation and download
    -   Optimize visibility data processing and normalization
    -   _Requirements: 1.1, 1.2, 2.1_

-   [ ] 9.1 Implement products report API integration

    -   Replace direct product list API with report-based approach
    -   Add report polling logic with timeout and retry mechanisms
    -   Implement CSV download with proper error handling
    -   _Requirements: 1.1, 1.2_

-   [ ] 9.2 Enhance CSV parsing and validation

    -   Create robust CSV parser with column mapping flexibility
    -   Add data validation for required fields and formats
    -   Implement error reporting for malformed or incomplete data
    -   _Requirements: 1.2, 2.1_

-   [ ] 9.3 Optimize visibility processing pipeline

    -   Batch process visibility updates for better performance
    -   Add progress tracking and logging for large datasets
    -   Implement incremental updates to avoid full table reloads
    -   _Requirements: 1.2, 2.1, 3.1_

-   [ ] 10. ETL Integration and Orchestration

    -   Implement proper ETL sequencing with ProductETL → InventoryETL → Status Refresh
    -   Add dependency management and failure recovery mechanisms
    -   Create comprehensive monitoring and alerting for ETL pipeline
    -   Optimize overall ETL performance and resource usage
    -   _Requirements: 5.1, 5.2, 5.3_

-   [ ] 10.1 Create ETL orchestration framework

    -   Implement ETL coordinator class to manage execution sequence
    -   Add dependency checking and conditional execution logic
    -   Create unified logging and monitoring for entire ETL pipeline
    -   _Requirements: 5.1, 5.2_

-   [ ] 10.2 Implement failure recovery and retry logic

    -   Add automatic retry mechanisms for transient failures
    -   Implement partial recovery for interrupted ETL processes
    -   Create manual intervention points for complex failure scenarios
    -   _Requirements: 5.2, 5.3_

-   [ ] 10.3 Create comprehensive ETL monitoring

    -   Implement real-time status tracking for all ETL components
    -   Add performance metrics collection and analysis
    -   Create alerting system for failures and performance degradation
    -   _Requirements: 5.2, 5.3, 5.4_

-   [x] 11. Functional Indexes Optimization (Alternative Approach)

    -   Create immutable SQL function for stock status calculation
    -   Implement functional indexes on computed expressions
    -   Optimize view with function-based status calculation
    -   Compare performance with materialized table approach
    -   _Requirements: 3.4, 4.4_

-   [x] 11.1 Create immutable stock status function

    -   Implement get_product_stock_status() PostgreSQL function
    -   Mark function as IMMUTABLE for index optimization
    -   Include all business logic from CASE expressions
    -   _Requirements: 1.4, 1.5, 3.4_

-   [x] 11.2 Create functional and partial indexes

    -   Create functional index on get_product_stock_status() result
    -   Add partial index on (present - reserved) for available stock
    -   Optimize existing visibility index for better selectivity
    -   _Requirements: 3.4, 4.4_

-   [x] 11.3 Refactor view to use functional approach

    -   Update v_detailed_inventory to call get_product_stock_status()
    -   Simplify JOIN conditions for better planner optimization
    -   Use INNER JOIN where appropriate to reduce dataset size
    -   _Requirements: 1.4, 3.4, 4.1_

-   [x] 11.4 Performance comparison and validation

    -   Benchmark functional indexes vs materialized table approach
    -   Test query planner behavior with functional indexes
    -   Validate data consistency between approaches
    -   _Requirements: 3.4, 4.4, 6.1_

-   [ ] 12. Hybrid Optimization Strategy

    -   Combine functional indexes with selective materialization
    -   Implement smart caching for frequently accessed data
    -   Create adaptive query routing based on data volume
    -   Optimize for both real-time queries and batch operations
    -   _Requirements: 3.4, 4.4_

-   [ ] 12.1 Implement adaptive query strategy

    -   Create query router to choose between view and materialized table
    -   Add logic to detect query complexity and data volume
    -   Implement fallback mechanisms for performance degradation
    -   _Requirements: 3.4, 4.4_

-   [ ] 12.2 Create selective materialization

    -   Materialize only frequently accessed or complex calculations
    -   Keep real-time calculations for simple status checks
    -   Implement cache invalidation strategies
    -   _Requirements: 3.4, 4.1, 4.4_

-   [ ] 12.3 Performance monitoring and auto-tuning

    -   Implement query performance tracking and analysis
    -   Create automatic index usage statistics collection
    -   Add alerts for performance regression detection
    -   _Requirements: 3.4, 4.4, 5.3_
