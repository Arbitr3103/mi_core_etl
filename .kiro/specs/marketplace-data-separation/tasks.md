# Implementation Plan

- [x] 1. Create marketplace detection and filtering utilities

  - Implement MarketplaceDetector class with methods to identify marketplace from source field and SKU fields
  - Create database query helper methods for marketplace filtering
  - Add validation for marketplace parameters
  - _Requirements: 6.1, 6.2, 6.4_

- [x] 2. Enhance MarginDashboardAPI with marketplace-specific methods

  - [x] 2.1 Add getMarginSummaryByMarketplace method

    - Modify existing summary query to support marketplace filtering
    - Implement logic to filter by source field patterns (ozon, wildberries, wb, озон, вб)
    - Add SKU-based marketplace detection using sku_ozon and sku_wb fields
    - _Requirements: 1.1, 1.2, 1.3_

  - [x] 2.2 Add getTopProductsByMarketplace method

    - Create marketplace-filtered version of top products query
    - Ensure proper SKU display based on marketplace (show sku_ozon for Ozon, sku_wb for Wildberries)
    - Implement separate ranking logic for each marketplace
    - _Requirements: 3.1, 3.2, 3.3, 3.4_

  - [x] 2.3 Add getDailyMarginChartByMarketplace method

    - Modify daily chart data query to support marketplace filtering
    - Ensure data points are correctly attributed to specific marketplace
    - Handle cases where data exists for only one marketplace on certain dates
    - _Requirements: 2.1, 2.2, 2.3_

  - [x] 2.4 Add getMarketplaceComparison method
    - Create method to return side-by-side comparison data for both marketplaces
    - Calculate comparative metrics (revenue, margin, orders) for each marketplace
    - Handle cases where one marketplace has no data for the period
    - _Requirements: 1.1, 1.2, 1.3, 2.1_

- [x] 3. Create enhanced API endpoints for marketplace data

  - [x] 3.1 Add marketplace parameter to existing endpoints

    - Modify margin_api.php to accept marketplace parameter
    - Update routing logic to handle marketplace-specific requests
    - Maintain backward compatibility with existing API calls
    - _Requirements: 6.3_

  - [x] 3.2 Create new separated view endpoint

    - Implement API endpoint that returns data for both marketplaces in single response
    - Structure response to include marketplace-specific sections
    - Add proper error handling for missing marketplace data
    - _Requirements: 1.4, 2.4, 4.4_

  - [x] 3.3 Add marketplace filtering to recommendations API
    - Modify recommendations logic to work with marketplace-specific data
    - Ensure stock recommendations are calculated separately for each marketplace
    - Update recommendation display to show marketplace context
    - _Requirements: 4.1, 4.2, 4.3, 4.4_

- [x] 4. Implement frontend view toggle functionality

  - [x] 4.1 Create MarketplaceViewToggle JavaScript class

    - Implement toggle buttons for switching between combined and separated views
    - Add event handlers for view mode changes
    - Implement localStorage persistence for user's preferred view mode
    - _Requirements: 5.1, 5.2, 5.3, 5.4_

  - [x] 4.2 Create separated view layout components

    - Design HTML structure for side-by-side marketplace display
    - Create CSS styles for marketplace-specific sections
    - Implement responsive design for mobile devices
    - _Requirements: 1.1, 2.1, 3.1, 4.1_

  - [x] 4.3 Implement data rendering for separated view
    - Create JavaScript functions to render marketplace-specific KPI cards
    - Implement separate chart rendering for each marketplace
    - Add loading states and error handling for each marketplace section
    - _Requirements: 1.4, 2.4, 3.4, 4.4_

- [x] 5. Update dashboard PHP files with new functionality

  - [x] 5.1 Modify dashboard_example.php

    - Add view toggle controls to the dashboard header
    - Include new JavaScript components and CSS styles
    - Update PHP logic to support marketplace parameter handling
    - _Requirements: 5.1, 5.2_

  - [x] 5.2 Update demo_dashboard.php

    - Integrate marketplace separation functionality into demo dashboard
    - Add example data for both marketplaces
    - Update demo API responses to include marketplace-specific data
    - _Requirements: 5.1, 5.2, 5.3_

  - [x] 5.3 Enhance main Manhattan dashboard
    - Apply marketplace separation to the production dashboard at https://api.zavodprostavok.ru/manhattan/
    - Ensure all existing functionality continues to work in combined mode
    - Test with real production data
    - _Requirements: 1.1, 2.1, 3.1, 4.1, 5.1_

- [x] 6. Add database optimizations for marketplace queries

  - [x] 6.1 Create database indexes for marketplace filtering

    - Add index on fact_orders.source field for faster marketplace filtering
    - Optimize existing indexes to support marketplace-specific queries
    - Analyze query performance with marketplace filters
    - _Requirements: 6.3_

  - [x] 6.2 Implement data validation for marketplace classification
    - Create validation logic to ensure data consistency across marketplaces
    - Add checks for proper SKU assignment (sku_ozon vs sku_wb)
    - Implement data quality reports for marketplace data
    - _Requirements: 6.1, 6.2, 6.4_

- [x] 6.3 Write unit tests for marketplace detection logic

  - Create tests for MarketplaceDetector class methods
  - Test edge cases like missing source data or ambiguous SKUs
  - Validate marketplace filtering query logic
  - _Requirements: 6.1, 6.2, 6.4_

- [x] 6.4 Write integration tests for API endpoints

  - Test marketplace-specific API endpoints with sample data
  - Validate response format and data accuracy
  - Test error handling for invalid marketplace parameters
  - _Requirements: 1.4, 2.4, 3.4, 4.4_

- [x] 7. Implement error handling and fallback mechanisms

  - [x] 7.1 Add comprehensive error handling for missing marketplace data

    - Implement graceful handling when one marketplace has no data
    - Add user-friendly error messages for data availability issues
    - Create fallback display when marketplace cannot be determined
    - _Requirements: 1.4, 2.4, 3.4, 4.4, 6.4_

  - [x] 7.2 Add data validation and consistency checks
    - Implement validation to ensure marketplace totals match combined totals
    - Add warnings for data inconsistencies
    - Create monitoring for marketplace data quality
    - _Requirements: 6.1, 6.2, 6.3_

- [x] 8. Final integration and testing

  - [x] 8.1 Integrate all components into production dashboard

    - Deploy enhanced MarginDashboardAPI to production
    - Update production dashboard with new frontend components
    - Configure production database with necessary indexes
    - _Requirements: 5.1, 5.2, 5.3, 5.4_

  - [x] 8.2 Perform end-to-end testing with production data

    - Test marketplace separation with real Ozon and Wildberries data
    - Validate data accuracy and consistency across both views
    - Test performance with production data volumes
    - _Requirements: 1.1, 1.2, 1.3, 2.1, 2.2, 2.3, 3.1, 3.2, 3.3, 4.1, 4.2, 4.3_

  - [x] 8.3 Create user documentation and training materials
    - Document new marketplace separation functionality
    - Create user guide for switching between view modes
    - Prepare training materials for end users
    - _Requirements: 5.1, 5.2, 5.3, 5.4_
