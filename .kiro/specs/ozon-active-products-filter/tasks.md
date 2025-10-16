# Implementation Plan

- [x] 1. Database schema updates for product activity tracking

  - Create migration script to add is_active, activity_checked_at, activity_reason columns to products table
  - Create product_activity_log table for tracking status changes
  - Add necessary indexes for performance optimization
  - _Requirements: 3.2, 4.2_

- [x] 2. Core ProductActivityChecker implementation

  - [x] 2.1 Create ProductActivityChecker class with activity determination logic

    - Implement isProductActive() method with combined criteria (visibility, state, stock, pricing)
    - Add getActivityReason() method for debugging and logging
    - Create batchCheckActivity() method for efficient bulk processing
    - _Requirements: 3.1, 3.2_

  - [x] 2.2 Create ProductActivity and ActivityChangeLog data models

    - Implement ProductActivity class with all activity criteria properties
    - Create ActivityChangeLog class for tracking status changes
    - Add validation and serialization methods
    - _Requirements: 3.1, 4.4_

  - [x] 2.3 Write unit tests for ProductActivityChecker
    - Test various combinations of activity criteria
    - Test edge cases (zero stock, missing price, invalid state)
    - Test batch processing functionality
    - _Requirements: 3.1_

- [x] 3. Enhanced OzonExtractor with active product filtering

  - [x] 3.1 Modify OzonExtractor to support visibility filtering

    - Update buildOzonFilters() method to include visibility: "VISIBLE" by default
    - Add support for fetching product info and stock data
    - Integrate ProductActivityChecker into the extraction process
    - _Requirements: 1.1, 1.2, 1.3_

  - [x] 3.2 Implement product info and stock data fetching

    - Add methods to fetch detailed product info via /v2/product/info/list
    - Add methods to fetch stock data via /v3/product/info/stocks
    - Add methods to fetch pricing data via /v4/product/info/prices
    - Implement proper error handling and rate limiting
    - _Requirements: 1.2, 1.3_

  - [x] 3.3 Update product normalization with activity status

    - Modify normalizeOzonProduct() to include activity determination
    - Add activity status and reason to normalized product data
    - Update database insertion to include activity fields
    - _Requirements: 1.4, 3.2_

  - [x] 3.4 Write integration tests for enhanced OzonExtractor
    - Test API filtering with visibility parameter
    - Test product info and stock data integration
    - Test activity status determination during extraction
    - _Requirements: 1.1, 1.2, 1.3_

- [x] 4. Database integration for activity tracking

  - [x] 4.1 Create ActivityUpdateTransaction class

    - Implement transactional updates for product activity status
    - Add rollback functionality for failed updates
    - Create logging for all activity changes
    - _Requirements: 3.2, 4.1_

  - [x] 4.2 Implement activity change logging system

    - Create methods to log activity status changes
    - Add batch logging for performance
    - Implement log retention and cleanup
    - _Requirements: 4.1, 4.2_

  - [x] 4.3 Write database integration tests
    - Test activity status updates and rollbacks
    - Test activity change logging
    - Test database performance with new indexes
    - _Requirements: 3.2, 4.1_

- [x] 5. Update Inventory Analytics API for active product filtering

  - [x] 5.1 Modify existing dashboard endpoints to filter active products

    - Update inventory-analytics.php to add WHERE is_active = 1 to all queries
    - Add active_only parameter (default true) to all endpoints
    - Ensure backward compatibility for existing dashboard
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [x] 5.2 Create new activity monitoring endpoints

    - Implement /api/inventory-analytics.php?action=activity_stats endpoint
    - Create /api/inventory-analytics.php?action=inactive_products endpoint
    - Add /api/inventory-analytics.php?action=activity_changes endpoint
    - _Requirements: 4.3_

  - [x] 5.3 Update dashboard statistics calculations

    - Modify critical stock calculations to use only active products
    - Update low stock and overstock calculations for active products only
    - Ensure all counters reflect active products (48 instead of 176)
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [x] 5.4 Write API endpoint tests
    - Test filtering functionality in all endpoints
    - Test new activity monitoring endpoints
    - Verify statistics calculations with active products only
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [x] 6. ETL process integration and scheduling

  - [x] 6.1 Update ETL configuration for active product filtering

    - Modify ETL configuration to enable active product filtering by default
    - Add configuration options for activity check intervals
    - Update existing ETL scripts to use enhanced OzonExtractor
    - _Requirements: 1.1, 1.4_

  - [x] 6.2 Create activity monitoring and notification system

    - Implement monitoring for significant changes in active product count
    - Add email/log notifications when active products drop significantly
    - Create daily activity summary reports
    - _Requirements: 4.4_

  - [x] 6.3 Write ETL integration tests
    - Test complete ETL process with active product filtering
    - Test activity monitoring and notifications
    - Verify data consistency after ETL runs
    - _Requirements: 1.4, 4.4_

- [x] 7. Performance optimization and monitoring

  - [x] 7.1 Optimize database queries for active product filtering

    - Analyze and optimize all dashboard queries with new indexes
    - Implement query result caching for frequently accessed data
    - Add database query performance monitoring
    - _Requirements: 2.4_

  - [x] 7.2 Add performance metrics and logging

    - Implement execution time tracking for all major operations
    - Add memory usage monitoring for batch operations
    - Create performance dashboard for monitoring system health
    - _Requirements: 4.1, 4.2_

  - [x] 7.3 Write performance tests
    - Create load tests for dashboard API with active product filtering
    - Test database performance with various data volumes
    - Benchmark system performance before and after optimization
    - _Requirements: 2.4_

- [x] 8. Documentation and deployment preparation

  - [x] 8.1 Create migration scripts and deployment documentation

    - Write database migration scripts for production deployment
    - Create rollback procedures for safe deployment
    - Document configuration changes and new environment variables
    - _Requirements: 3.2, 4.2_

  - [x] 8.2 Update system documentation
    - Document new API endpoints and parameters
    - Create troubleshooting guide for activity filtering issues
    - Update ETL process documentation with new filtering capabilities
    - _Requirements: 4.3_
