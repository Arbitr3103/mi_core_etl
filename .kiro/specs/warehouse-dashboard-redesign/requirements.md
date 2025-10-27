# Requirements Document

## Introduction

This document outlines the requirements for redesigning the existing warehouse monitoring dashboard. The current dashboard provides aggregated data that gives a "temperature check" but lacks the detailed, actionable insights needed for inventory management decisions. The new dashboard will transition from warehouse-level aggregation to detailed product-level visibility, enabling managers to make informed decisions about specific SKUs on specific warehouses.

## Glossary

-   **Dashboard**: The web-based interface for monitoring warehouse inventory
-   **SKU**: Stock Keeping Unit - a unique identifier for each product
-   **Warehouse**: FBO (Fulfillment by Ozon) storage facility
-   **ADS**: Average Daily Sales - calculated sales velocity metric
-   **Days of Stock**: Current inventory divided by ADS, indicating how many days current stock will last
-   **Product-Warehouse Pair**: A unique combination of a specific product on a specific warehouse
-   **Replenishment Recommendation**: Calculated quantity of product suggested for shipment to warehouse

## Requirements

### Requirement 1

**User Story:** As an inventory manager, I want to see detailed product-level data for each warehouse, so that I can identify which specific products need attention rather than just warehouse-level summaries.

#### Acceptance Criteria

1. THE Dashboard SHALL display a table where each row represents a unique Product-Warehouse Pair
2. WHEN the dashboard loads, THE Dashboard SHALL show all Product-Warehouse Pairs with their current metrics
3. THE Dashboard SHALL replace the current warehouse card view with the detailed table view
4. THE Dashboard SHALL display product name and SKU for each Product-Warehouse Pair
5. THE Dashboard SHALL display warehouse name for each Product-Warehouse Pair

### Requirement 2

**User Story:** As an inventory manager, I want to see key inventory metrics for each product-warehouse combination, so that I can assess the urgency and priority of inventory decisions.

#### Acceptance Criteria

1. THE Dashboard SHALL display current stock quantity for each Product-Warehouse Pair
2. THE Dashboard SHALL display sales velocity (ADS or 30-day sales) for each Product-Warehouse Pair
3. THE Dashboard SHALL calculate and display Days of Stock for each Product-Warehouse Pair
4. THE Dashboard SHALL display a visual status indicator based on Days of Stock thresholds
5. THE Dashboard SHALL display replenishment recommendations for each Product-Warehouse Pair

### Requirement 3

**User Story:** As an inventory manager, I want to filter and search the inventory data, so that I can focus on specific warehouses, products, or problem areas.

#### Acceptance Criteria

1. THE Dashboard SHALL provide a filter to select one or multiple warehouses
2. THE Dashboard SHALL provide a filter to select inventory status levels (Critical, Low, Normal, Excess)
3. THE Dashboard SHALL provide a search function to find products by name or SKU
4. WHEN filters are applied, THE Dashboard SHALL update the table to show only matching Product-Warehouse Pairs
5. THE Dashboard SHALL allow clearing all filters to return to full view

### Requirement 4

**User Story:** As an inventory manager, I want to sort the inventory data by different criteria, so that I can prioritize my actions based on urgency or other factors.

#### Acceptance Criteria

1. THE Dashboard SHALL allow sorting by any column in the table
2. THE Dashboard SHALL sort by Days of Stock in ascending order by default
3. WHEN a column header is clicked, THE Dashboard SHALL sort the table by that column
4. THE Dashboard SHALL provide visual indicators for current sort column and direction
5. THE Dashboard SHALL maintain sort state when filters are applied

### Requirement 5

**User Story:** As an inventory manager, I want to see clear visual indicators of inventory status, so that I can quickly identify critical situations that need immediate attention.

#### Acceptance Criteria

1. THE Dashboard SHALL use color-coded status indicators for inventory levels
2. WHEN Days of Stock is less than 14 days, THE Dashboard SHALL display Critical status with red indicator
3. WHEN Days of Stock is between 14-30 days, THE Dashboard SHALL display Low status with yellow indicator
4. WHEN Days of Stock is between 30-60 days, THE Dashboard SHALL display Normal status with green indicator
5. WHEN Days of Stock is greater than 60 days, THE Dashboard SHALL display Excess status with blue indicator

### Requirement 6

**User Story:** As a system administrator, I want the dashboard to receive pre-calculated data from the backend, so that the frontend remains responsive and business logic is centralized.

#### Acceptance Criteria

1. THE Backend SHALL provide an API endpoint that returns detailed inventory data
2. THE Backend SHALL calculate all metrics including ADS, Days of Stock, and status indicators
3. THE Backend SHALL calculate replenishment recommendations based on business rules
4. THE Frontend SHALL receive ready-to-display data without performing calculations
5. THE API SHALL return data in a format optimized for table display and filtering

### Requirement 7

**User Story:** As an inventory manager, I want the dashboard to load quickly and respond smoothly to interactions, so that I can work efficiently without delays.

#### Acceptance Criteria

1. THE Dashboard SHALL load initial data within 3 seconds under normal conditions
2. THE Dashboard SHALL respond to filter and sort operations within 1 second
3. THE Dashboard SHALL handle at least 1000 Product-Warehouse Pairs without performance degradation
4. THE Dashboard SHALL provide loading indicators during data fetch operations
5. THE Dashboard SHALL gracefully handle API errors with user-friendly messages
