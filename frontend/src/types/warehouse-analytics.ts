/**
 * Enhanced TypeScript types for Warehouse Analytics API Integration
 *
 * This file extends the base warehouse types with Analytics API specific fields
 * for multi-source data integration, quality metrics, and ETL monitoring.
 *
 * Requirements: 9.1, 9.2, 17.3
 */

// Base types (assuming they exist in warehouse.ts)
export interface WarehouseItem {
  // Product info
  product_id: number;
  sku: string;
  name: string;

  // Warehouse info
  warehouse_name: string;
  cluster: string;

  // Current stock
  available: number;
  reserved: number;

  // Ozon metrics
  preparing_for_sale: number;
  in_supply_requests: number;
  in_transit: number;
  in_inspection: number;
  returning_from_customers: number;
  expiring_soon: number;
  defective: number;
  excess_from_supply: number;
  awaiting_upd: number;
  preparing_for_removal: number;

  // Sales metrics
  daily_sales_avg: number;
  sales_last_28_days: number;
  days_without_sales: number;

  // Liquidity metrics
  days_of_stock: number | null;
  liquidity_status: "critical" | "low" | "normal" | "excess";

  // Replenishment
  target_stock: number;
  replenishment_need: number;

  // Metadata
  last_updated: string;
}

export interface WarehouseDashboardData {
  warehouses: WarehouseGroup[];
  summary: DashboardSummary;
  filters_applied: FilterState;
  last_updated: string;
}

export interface WarehouseGroup {
  warehouse_name: string;
  cluster: string;
  items: WarehouseItem[];
  totals: WarehouseTotals;
}

export interface DashboardSummary {
  total_products: number;
  active_products: number;
  total_replenishment_need: number;
  by_liquidity: {
    critical: number;
    low: number;
    normal: number;
    excess: number;
  };
}

export interface WarehouseTotals {
  available: number;
  reserved: number;
  preparing_for_sale: number;
  replenishment_need: number;
}

export interface FilterState {
  search?: string;
  warehouse?: string;
  cluster?: string;
  liquidity_status?: string;
  has_replenishment?: boolean;
}

// =============================================================================
// ENHANCED TYPES FOR ANALYTICS API INTEGRATION
// =============================================================================

/**
 * Enhanced warehouse item with Analytics API source metadata
 * Extends base WarehouseItem with multi-source integration fields
 */
export interface WarehouseItemEnhanced extends WarehouseItem {
  // Source metadata
  data_source: "api" | "ui_report" | "mixed" | "manual";
  data_quality_score: number; // 0-100
  last_api_sync: string | null;
  last_ui_sync: string | null;
  validation_status: "pending" | "validated" | "conflict" | "error";

  // Normalization
  normalized_warehouse_name: string;
  original_warehouse_name: string;

  // Quality indicators
  freshness_minutes: number;
  discrepancy_percent?: number;
  source_priority: number;
}

/**
 * Data quality metrics for Analytics API integration
 * Provides comprehensive quality assessment across data sources
 */
export interface DataQualityMetrics {
  overall_score: number; // 0-100
  api_coverage_percent: number; // Percentage of data from API vs UI
  ui_coverage_percent: number; // Percentage of data from UI reports
  validation_pass_rate: number; // Percentage of validations that passed
  average_freshness_minutes: number; // Average age of data in minutes
  discrepancy_rate: number; // Percentage of records with discrepancies
  last_validation: string; // ISO timestamp of last validation
}

/**
 * Analytics ETL process status
 * Tracks the status and progress of ETL operations
 */
export interface AnalyticsETLStatus {
  id: number;
  sync_type: "api_full" | "api_incremental" | "ui_import" | "manual_sync";
  source_name: string;
  status:
    | "pending"
    | "running"
    | "completed"
    | "failed"
    | "retrying"
    | "cancelled";
  started_at: string;
  completed_at?: string;
  records_processed: number;
  records_inserted: number;
  records_updated: number;
  records_failed: number;
  error_message?: string;
  retry_count: number;
  max_retries: number;
  next_retry_at?: string;
  progress_percent?: number;
  batch_id?: string;
  file_path?: string; // For UI imports
  api_endpoint?: string; // For API calls
}

/**
 * Enhanced dashboard data with Analytics API integration
 * Extends base dashboard data with quality metrics and ETL status
 */
export interface EnhancedDashboardData extends WarehouseDashboardData {
  data_quality: DataQualityMetrics;
  sync_status: AnalyticsETLStatus[];
  source_distribution: {
    api_only: number;
    ui_only: number;
    mixed: number;
    conflicts: number;
  };
  freshness_distribution: {
    fresh: number; // < 1 hour
    acceptable: number; // 1-6 hours
    stale: number; // > 6 hours
  };
}

// =============================================================================
// ADMIN INTERFACE TYPES FOR ETL MONITORING
// =============================================================================

/**
 * Source priority configuration for admin interface
 * Controls how different data sources are prioritized
 */
export interface SourcePriorityConfig {
  api_priority: number; // 1-10 priority level
  ui_priority: number; // 1-10 priority level
  fallback_enabled: boolean;
  max_discrepancy_percent: number; // Threshold for conflict detection
  auto_resolution_enabled: boolean;
}

/**
 * Validation rule configuration for admin interface
 * Defines how data validation should be performed
 */
export interface ValidationRule {
  id: string;
  name: string;
  description: string;
  enabled: boolean;
  threshold_percent: number;
  action: "warn" | "block" | "auto_fix";
}

/**
 * ETL monitoring dashboard data
 * Comprehensive view of ETL system health and performance
 */
export interface ETLMonitoringData {
  current_status: {
    active_syncs: number;
    failed_syncs: number;
    last_successful_sync: string;
    system_health: "healthy" | "warning" | "critical";
  };
  recent_syncs: AnalyticsETLStatus[];
  performance_metrics: {
    average_sync_duration_minutes: number;
    success_rate_24h: number;
    records_processed_24h: number;
    error_rate_24h: number;
  };
  data_quality_trends: {
    date: string;
    overall_score: number;
    api_coverage: number;
    validation_pass_rate: number;
  }[];
  alerts: ETLAlert[];
}

/**
 * ETL alert for monitoring interface
 * Represents system alerts and notifications
 */
export interface ETLAlert {
  id: string;
  type: "error" | "warning" | "info";
  title: string;
  message: string;
  created_at: string;
  resolved_at?: string;
  severity: "low" | "medium" | "high" | "critical";
  source: string; // Which component generated the alert
  metadata?: Record<string, any>;
}

/**
 * Warehouse normalization rule for admin interface
 * Manages how warehouse names are normalized across sources
 */
export interface WarehouseNormalizationRule {
  id: number;
  original_name: string;
  normalized_name: string;
  source_type: "api" | "ui_report" | "manual";
  confidence_score: number; // 0.0 - 1.0
  created_at: string;
  updated_at: string;
  created_by: string;
  cluster_name?: string;
  warehouse_code?: string;
  is_active: boolean;
}

/**
 * Data quality log entry for detailed analysis
 * Tracks individual validation results
 */
export interface DataQualityLogEntry {
  id: number;
  validation_batch_id: string;
  warehouse_name?: string;
  product_id?: number;
  api_value?: number;
  ui_value?: number;
  discrepancy_percent?: number;
  discrepancy_absolute?: number;
  validation_status:
    | "passed"
    | "warning"
    | "failed"
    | "manual_review"
    | "resolved";
  resolution_action?: string;
  quality_score: number;
  validated_at: string;
  validator_version?: string;
  notes?: string;
}

/**
 * Sync configuration for admin interface
 * Controls how ETL processes are configured and scheduled
 */
export interface SyncConfiguration {
  id: string;
  name: string;
  sync_type: "api_full" | "api_incremental" | "ui_import";
  enabled: boolean;
  schedule_cron?: string; // Cron expression for scheduled syncs
  auto_retry: boolean;
  max_retries: number;
  retry_delay_minutes: number;
  timeout_minutes: number;
  batch_size: number;
  parallel_workers: number;
  notification_settings: {
    on_success: boolean;
    on_failure: boolean;
    on_warning: boolean;
    email_recipients: string[];
    slack_webhook?: string;
  };
}

// =============================================================================
// UTILITY TYPES AND ENUMS
// =============================================================================

/**
 * Data source types
 */
export type DataSource = "api" | "ui_report" | "mixed" | "manual";

/**
 * Validation status types
 */
export type ValidationStatus = "pending" | "validated" | "conflict" | "error";

/**
 * ETL sync types
 */
export type SyncType =
  | "api_full"
  | "api_incremental"
  | "ui_import"
  | "manual_sync";

/**
 * ETL status types
 */
export type ETLStatus =
  | "pending"
  | "running"
  | "completed"
  | "failed"
  | "retrying"
  | "cancelled";

/**
 * Alert severity levels
 */
export type AlertSeverity = "low" | "medium" | "high" | "critical";

/**
 * System health status
 */
export type SystemHealth = "healthy" | "warning" | "critical";

/**
 * Validation actions
 */
export type ValidationAction = "warn" | "block" | "auto_fix";

/**
 * Quality score thresholds
 */
export const QUALITY_THRESHOLDS = {
  EXCELLENT: 95,
  GOOD: 85,
  ACCEPTABLE: 70,
  POOR: 50,
  CRITICAL: 30,
} as const;

/**
 * Freshness thresholds in minutes
 */
export const FRESHNESS_THRESHOLDS = {
  FRESH: 60, // < 1 hour
  ACCEPTABLE: 360, // < 6 hours
  STALE: 1440, // < 24 hours
} as const;

/**
 * Default retry configuration
 */
export const DEFAULT_RETRY_CONFIG = {
  MAX_RETRIES: 3,
  RETRY_DELAYS: [1, 2, 4, 8, 16], // seconds
  TIMEOUT_MINUTES: 30,
} as const;
