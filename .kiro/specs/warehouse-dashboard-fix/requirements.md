# Warehouse Dashboard Production Fix Requirements

## Introduction

Fix the production deployment of the Warehouse Dashboard at https://www.market-mi.ru/warehouse-dashboard which currently has UI/UX issues where elements overlap and the layout is broken despite the application loading and API working correctly.

## Glossary

-   **Frontend Application**: React SPA built with Vite that renders the warehouse dashboard
-   **API Endpoint**: Backend PHP service at /api/warehouse-dashboard.php that returns warehouse data
-   **Browser Console**: Developer tools console showing JavaScript errors and network requests
-   **Base URL**: The root path where the application is served (/warehouse-dashboard/)
-   **API Base URL**: The path prefix for API requests (/api)
-   **Layout System**: CSS layout using Tailwind CSS and flexbox/grid for component positioning
-   **Responsive Design**: UI that adapts to different screen sizes and devices
-   **Z-Index**: CSS property controlling stacking order of overlapping elements

## Requirements

### Requirement 1

**User Story:** As a developer, I want to diagnose layout issues in production, so that I can identify what is causing elements to overlap

#### Acceptance Criteria

1. WHEN browser console is checked, THE System SHALL display any JavaScript or CSS errors
2. WHEN browser DevTools is used, THE System SHALL show computed styles for overlapping elements
3. WHEN layout is inspected, THE System SHALL identify z-index conflicts
4. THE System SHALL identify missing or incorrect CSS classes
5. THE System SHALL identify if Tailwind CSS is loading correctly

### Requirement 2

**User Story:** As a developer, I want to fix header and navigation layout, so that they display correctly without overlapping

#### Acceptance Criteria

1. THE System SHALL display header at top of page with correct positioning
2. THE System SHALL display navigation below header without overlap
3. THE System SHALL apply correct spacing between header and main content
4. THE System SHALL use sticky or fixed positioning correctly if needed
5. THE System SHALL ensure header and navigation have proper z-index values

### Requirement 3

**User Story:** As a developer, I want to fix dashboard content layout, so that filters and table display properly

#### Acceptance Criteria

1. THE System SHALL display dashboard header with metrics in proper grid layout
2. THE System SHALL display filter controls in organized rows without overlap
3. THE System SHALL display data table with proper column widths
4. THE System SHALL apply correct padding and margins to all sections
5. THE System SHALL ensure scrollable areas work correctly

### Requirement 4

**User Story:** As a developer, I want to fix responsive design issues, so that dashboard works on all screen sizes

#### Acceptance Criteria

1. WHEN viewport is desktop size, THE System SHALL display full layout with all columns
2. WHEN viewport is tablet size, THE System SHALL adjust layout to fit screen
3. WHEN viewport is mobile size, THE System SHALL stack elements vertically
4. THE System SHALL apply correct breakpoints for responsive behavior
5. THE System SHALL ensure text remains readable at all sizes

### Requirement 5

**User Story:** As a developer, I want to fix component spacing and alignment, so that UI looks professional

#### Acceptance Criteria

1. THE System SHALL apply consistent spacing between all components
2. THE System SHALL align text and buttons properly within containers
3. THE System SHALL ensure cards and panels have proper padding
4. THE System SHALL apply correct gap values in flex and grid layouts
5. THE System SHALL ensure no elements extend beyond their containers

### Requirement 6

**User Story:** As a user, I want to see a properly formatted dashboard, so that I can easily read and use the interface

#### Acceptance Criteria

1. WHEN user accesses https://www.market-mi.ru/warehouse-dashboard, THE System SHALL display clean, organized layout
2. THE System SHALL show all text clearly without overlap
3. THE System SHALL display buttons and controls in accessible positions
4. THE System SHALL render table data in readable format
5. THE System SHALL provide smooth scrolling experience
