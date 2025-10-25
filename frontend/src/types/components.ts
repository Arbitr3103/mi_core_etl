/**
 * Component-specific TypeScript types for Analytics API integration
 *
 * These types are specifically designed for React components that will
 * display and interact with Analytics API data and ETL monitoring.
 *
 * Requirements: 9.1, 9.2, 17.3
 */

import type {
  WarehouseItemEnhanced,
  DataQualityMetrics,
  AnalyticsETLStatus,
  ETLAlert,
  DataSource,
  ValidationStatus,
  ETLStatus,
} from "./warehouse-analytics";

// =============================================================================
// COMPONENT PROP TYPES
// =============================================================================

/**
 * Props for AnalyticsETLStatusIndicator component
 * Displays the current status of ETL processes
 */
export interface AnalyticsETLStatusIndicatorProps {
  status: AnalyticsETLStatus;
  showProgress?: boolean;
  showDetails?: boolean;
  onRetry?: (syncId: number) => void;
  onCancel?: (syncId: number) => void;
  className?: string;
}

/**
 * Props for DataQualityIndicator component
 * Shows data quality metrics and scores
 */
export interface DataQualityIndicatorProps {
  metrics: DataQualityMetrics;
  showTrend?: boolean;
  showBreakdown?: boolean;
  size?: "small" | "medium" | "large";
  className?: string;
}

/**
 * Props for SourceIndicator component
 * Displays the data source for warehouse items
 */
export interface SourceIndicatorProps {
  dataSource: DataSource;
  qualityScore: number;
  freshnessMinutes: number;
  discrepancyPercent?: number;
  showTooltip?: boolean;
  size?: "small" | "medium" | "large";
  className?: string;
}

/**
 * Props for ValidationStatusBadge component
 * Shows validation status with appropriate styling
 */
export interface ValidationStatusBadgeProps {
  status: ValidationStatus;
  showIcon?: boolean;
  showText?: boolean;
  size?: "small" | "medium" | "large";
  className?: string;
}

/**
 * Props for ETLProgressBar component
 * Displays progress of ongoing ETL operations
 */
export interface ETLProgressBarProps {
  progress: number; // 0-100
  status: ETLStatus;
  showPercentage?: boolean;
  showETA?: boolean;
  estimatedCompletion?: string;
  className?: string;
}

/**
 * Props for AlertsList component
 * Displays list of ETL alerts and notifications
 */
export interface AlertsListProps {
  alerts: ETLAlert[];
  maxItems?: number;
  showSeverityFilter?: boolean;
  onAlertClick?: (alert: ETLAlert) => void;
  onAlertResolve?: (alertId: string) => void;
  className?: string;
}

// =============================================================================
// ENHANCED TABLE COMPONENT TYPES
// =============================================================================

/**
 * Props for EnhancedWarehouseTable component
 * Extended warehouse table with Analytics API features
 */
export interface EnhancedWarehouseTableProps {
  data: WarehouseItemEnhanced[];
  qualityMetrics: DataQualityMetrics;
  sortConfig?: SortConfig;
  filterConfig?: FilterConfig;
  onSort?: (config: SortConfig) => void;
  onFilter?: (config: FilterConfig) => void;
  onItemClick?: (item: WarehouseItemEnhanced) => void;
  showQualityIndicators?: boolean;
  showSourceIndicators?: boolean;
  showFreshnessIndicators?: boolean;
  className?: string;
}

/**
 * Sort configuration for enhanced table
 */
export interface SortConfig {
  field: keyof WarehouseItemEnhanced;
  direction: "asc" | "desc";
}

/**
 * Filter configuration for enhanced table
 */
export interface FilterConfig {
  search?: string;
  warehouse?: string;
  cluster?: string;
  dataSource?: DataSource[];
  validationStatus?: ValidationStatus[];
  qualityScoreMin?: number;
  freshnessMaxMinutes?: number;
  showConflictsOnly?: boolean;
}

/**
 * Props for DataQualityHeader component
 * Header section showing overall data quality metrics
 */
export interface DataQualityHeaderProps {
  metrics: DataQualityMetrics;
  onRefresh?: () => void;
  onViewDetails?: () => void;
  showRefreshButton?: boolean;
  showDetailsButton?: boolean;
  className?: string;
}

/**
 * Props for SourceLegend component
 * Legend explaining data source indicators
 */
export interface SourceLegendProps {
  showCounts?: boolean;
  sourceCounts?: {
    api: number;
    ui_report: number;
    mixed: number;
    manual: number;
  };
  className?: string;
}

// =============================================================================
// TOOLTIP AND MODAL TYPES
// =============================================================================

/**
 * Content for source tooltip
 * Detailed information shown on hover
 */
export interface SourceTooltipContent {
  dataSource: DataSource;
  qualityScore: number;
  freshnessMinutes: number;
  lastApiSync?: string;
  lastUiSync?: string;
  discrepancyPercent?: number;
  validationStatus: ValidationStatus;
  originalWarehouseName?: string;
  normalizedWarehouseName?: string;
}

/**
 * Props for SourceTooltip component
 */
export interface SourceTooltipProps {
  content: SourceTooltipContent;
  className?: string;
}

/**
 * Props for ETLStatusModal component
 * Modal showing detailed ETL status information
 */
export interface ETLStatusModalProps {
  isOpen: boolean;
  onClose: () => void;
  syncStatus: AnalyticsETLStatus;
  onRetry?: (syncId: number) => void;
  onCancel?: (syncId: number) => void;
  showLogs?: boolean;
}

/**
 * Props for DataQualityModal component
 * Modal showing detailed data quality analysis
 */
export interface DataQualityModalProps {
  isOpen: boolean;
  onClose: () => void;
  metrics: DataQualityMetrics;
  warehouseDetails?: WarehouseQualityDetails[];
  showTrends?: boolean;
}

/**
 * Warehouse-specific quality details
 */
export interface WarehouseQualityDetails {
  warehouseName: string;
  qualityScore: number;
  apiCoverage: number;
  uiCoverage: number;
  validationPassRate: number;
  averageFreshness: number;
  conflictCount: number;
  lastValidation: string;
}

// =============================================================================
// FORM AND INPUT TYPES
// =============================================================================

/**
 * Props for ETLConfigForm component
 * Form for configuring ETL settings
 */
export interface ETLConfigFormProps {
  initialConfig?: Partial<ETLConfiguration>;
  onSubmit: (config: ETLConfiguration) => void;
  onCancel?: () => void;
  isLoading?: boolean;
  className?: string;
}

/**
 * ETL configuration form data
 */
export interface ETLConfiguration {
  syncInterval: number; // minutes
  batchSize: number;
  maxRetries: number;
  timeoutMinutes: number;
  enableAutoRetry: boolean;
  enableNotifications: boolean;
  qualityThreshold: number; // 0-100
  freshnessThreshold: number; // minutes
  enableConflictResolution: boolean;
  prioritizeApiData: boolean;
}

/**
 * Props for WarehouseFilter component with Analytics features
 */
export interface EnhancedWarehouseFilterProps {
  filters: FilterConfig;
  onChange: (filters: FilterConfig) => void;
  warehouses: string[];
  clusters: string[];
  showQualityFilters?: boolean;
  showSourceFilters?: boolean;
  showFreshnessFilters?: boolean;
  className?: string;
}

// =============================================================================
// CHART AND VISUALIZATION TYPES
// =============================================================================

/**
 * Props for QualityTrendChart component
 * Chart showing data quality trends over time
 */
export interface QualityTrendChartProps {
  data: QualityTrendDataPoint[];
  timeRange: "24h" | "7d" | "30d";
  showApiCoverage?: boolean;
  showValidationRate?: boolean;
  height?: number;
  className?: string;
}

/**
 * Data point for quality trend chart
 */
export interface QualityTrendDataPoint {
  timestamp: string;
  overallScore: number;
  apiCoverage: number;
  uiCoverage: number;
  validationPassRate: number;
  discrepancyRate: number;
}

/**
 * Props for SourceDistributionChart component
 * Pie chart showing distribution of data sources
 */
export interface SourceDistributionChartProps {
  data: {
    api: number;
    ui_report: number;
    mixed: number;
    manual: number;
  };
  showLabels?: boolean;
  showPercentages?: boolean;
  size?: number;
  className?: string;
}

/**
 * Props for ETLPerformanceChart component
 * Chart showing ETL performance metrics
 */
export interface ETLPerformanceChartProps {
  data: ETLPerformanceDataPoint[];
  metric: "duration" | "throughput" | "error_rate";
  timeRange: "24h" | "7d" | "30d";
  height?: number;
  className?: string;
}

/**
 * Data point for ETL performance chart
 */
export interface ETLPerformanceDataPoint {
  timestamp: string;
  duration: number; // minutes
  recordsProcessed: number;
  errorRate: number; // percentage
  throughput: number; // records per minute
}

// =============================================================================
// NOTIFICATION AND ALERT TYPES
// =============================================================================

/**
 * Props for NotificationCenter component
 * Central hub for all ETL-related notifications
 */
export interface NotificationCenterProps {
  alerts: ETLAlert[];
  maxVisible?: number;
  autoHideDelay?: number; // milliseconds
  position?: "top-right" | "top-left" | "bottom-right" | "bottom-left";
  onAlertClick?: (alert: ETLAlert) => void;
  onAlertDismiss?: (alertId: string) => void;
  className?: string;
}

/**
 * Props for individual notification item
 */
export interface NotificationItemProps {
  alert: ETLAlert;
  onDismiss?: (alertId: string) => void;
  onClick?: (alert: ETLAlert) => void;
  autoHide?: boolean;
  autoHideDelay?: number;
  className?: string;
}

// =============================================================================
// UTILITY AND HELPER TYPES
// =============================================================================

/**
 * Loading state for components
 */
export interface LoadingState {
  isLoading: boolean;
  error?: string;
  lastUpdated?: string;
}

/**
 * Pagination state for tables and lists
 */
export interface PaginationState {
  currentPage: number;
  pageSize: number;
  totalItems: number;
  totalPages: number;
}

/**
 * Theme configuration for components
 */
export interface ComponentTheme {
  colors: {
    primary: string;
    secondary: string;
    success: string;
    warning: string;
    error: string;
    info: string;
  };
  sizes: {
    small: string;
    medium: string;
    large: string;
  };
  spacing: {
    xs: string;
    sm: string;
    md: string;
    lg: string;
    xl: string;
  };
}

/**
 * Accessibility props for components
 */
export interface AccessibilityProps {
  "aria-label"?: string;
  "aria-describedby"?: string;
  "aria-expanded"?: boolean;
  "aria-hidden"?: boolean;
  role?: string;
  tabIndex?: number;
}
