# TypeScript Types for Warehouse Analytics API Integration

This directory contains comprehensive TypeScript type definitions for the Warehouse Analytics API integration system. The types are organized into logical modules to support different aspects of the application.

## File Structure

```
types/
├── index.ts                    # Central export file for all types
├── warehouse-analytics.ts      # Core Analytics API types
├── admin.ts                   # Admin interface types
├── components.ts              # React component prop types
└── README.md                  # This documentation file
```

## Type Categories

### Core Analytics Types (`warehouse-analytics.ts`)

**Enhanced Warehouse Types:**

-   `WarehouseItemEnhanced` - Extends base WarehouseItem with Analytics API fields
-   `DataQualityMetrics` - Comprehensive quality assessment metrics
-   `AnalyticsETLStatus` - ETL process status and progress tracking

**Data Integration Types:**

-   `EnhancedDashboardData` - Dashboard data with quality metrics
-   `SourcePriorityConfig` - Configuration for data source prioritization
-   `ValidationRule` - Rules for data validation processes

**Utility Types:**

-   `DataSource` - Union type for data sources
-   `ValidationStatus` - Union type for validation states
-   `ETLStatus` - Union type for ETL process states

### Admin Interface Types (`admin.ts`)

**Dashboard Types:**

-   `AdminDashboardData` - Main admin dashboard structure
-   `SystemOverview` - System health and status overview
-   `ETLStatusOverview` - ETL processes overview

**Configuration Types:**

-   `ETLConfigurationManager` - ETL system configuration
-   `ValidationRuleConfig` - Validation rule configuration
-   `AlertRuleConfig` - Alert rule configuration

**Monitoring Types:**

-   `SyncProgress` - Real-time sync progress tracking
-   `LiveSystemMetrics` - Live system performance metrics
-   `WebSocketMessage` - Real-time update message types

### Component Types (`components.ts`)

**Component Props:**

-   `AnalyticsETLStatusIndicatorProps` - ETL status indicator component
-   `DataQualityIndicatorProps` - Data quality display component
-   `SourceIndicatorProps` - Data source indicator component

**Table and List Types:**

-   `EnhancedWarehouseTableProps` - Enhanced warehouse table
-   `SortConfig` - Table sorting configuration
-   `FilterConfig` - Table filtering configuration

**Chart and Visualization:**

-   `QualityTrendChartProps` - Quality trend visualization
-   `SourceDistributionChartProps` - Source distribution chart
-   `ETLPerformanceChartProps` - ETL performance metrics chart

## Usage Examples

### Basic Import

```typescript
import type {
    WarehouseItemEnhanced,
    DataQualityMetrics,
    AnalyticsETLStatus,
} from "@/types";
```

### Component Props

```typescript
import type { AnalyticsETLStatusIndicatorProps } from "@/types";

const ETLStatusIndicator: React.FC<AnalyticsETLStatusIndicatorProps> = ({
    status,
    showProgress = true,
    onRetry,
}) => {
    // Component implementation
};
```

### Type Guards

```typescript
import { isValidDataSource, isValidETLStatus } from "@/types";

if (isValidDataSource(source)) {
    // TypeScript knows source is DataSource type
}
```

### Quality Assessment

```typescript
import { getQualityLevel, getFreshnessLevel } from "@/types";

const qualityLevel = getQualityLevel(85); // "good"
const freshnessLevel = getFreshnessLevel(30); // "fresh"
```

## Constants and Thresholds

The types include predefined constants for consistent behavior:

```typescript
import { QUALITY_THRESHOLDS, FRESHNESS_THRESHOLDS } from "@/types";

// Quality score thresholds
QUALITY_THRESHOLDS.EXCELLENT; // 95
QUALITY_THRESHOLDS.GOOD; // 85
QUALITY_THRESHOLDS.ACCEPTABLE; // 70

// Freshness thresholds (minutes)
FRESHNESS_THRESHOLDS.FRESH; // 60
FRESHNESS_THRESHOLDS.ACCEPTABLE; // 360
FRESHNESS_THRESHOLDS.STALE; // 1440
```

## Requirements Mapping

These types fulfill the following requirements:

-   **Requirement 9.1**: Enhanced warehouse item types with Analytics API fields
-   **Requirement 9.2**: Data quality metrics and ETL status types
-   **Requirement 17.3**: Freshness and quality indicator types

## Type Safety Features

1. **Union Types**: Strict typing for status values and enums
2. **Type Guards**: Runtime type checking functions
3. **Utility Functions**: Helper functions for quality assessment
4. **Optional Properties**: Flexible interfaces with optional fields
5. **Generic Types**: Reusable types for different contexts

## Best Practices

1. **Import from Index**: Always import from `@/types` for consistency
2. **Use Type Guards**: Validate runtime data with provided type guards
3. **Leverage Constants**: Use predefined thresholds and constants
4. **Component Props**: Use specific prop types for React components
5. **Optional Chaining**: Handle optional properties safely

## Future Extensions

The type system is designed to be extensible:

1. **New Data Sources**: Add new source types to union types
2. **Additional Metrics**: Extend quality metrics interfaces
3. **Custom Validations**: Add new validation rule types
4. **Enhanced Monitoring**: Extend monitoring and alert types

## Contributing

When adding new types:

1. Place them in the appropriate module file
2. Export them from the main index file
3. Add type guards if applicable
4. Update this README with usage examples
5. Ensure backward compatibility

## Related Files

-   `../components/` - React components using these types
-   `../services/` - API services using these types
-   `../hooks/` - React hooks using these types
-   `../../api/` - Backend API endpoints returning these types
