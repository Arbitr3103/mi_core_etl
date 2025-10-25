/**
 * Admin interface specific types for ETL monitoring and management
 *
 * These types are specifically designed for administrative interfaces
 * that manage and monitor the Analytics API ETL system.
 *
 * Requirements: 9.1, 9.2, 17.3
 */

import type {
  AnalyticsETLStatus,
  DataQualityMetrics,
  ETLAlert,
  WarehouseNormalizationRule,
  DataQualityLogEntry,
  SyncConfiguration,
} from "./warehouse-analytics";

// =============================================================================
// ADMIN DASHBOARD TYPES
// =============================================================================

/**
 * Main admin dashboard data structure
 * Provides comprehensive overview of ETL system status
 */
export interface AdminDashboardData {
  system_overview: SystemOverview;
  etl_status: ETLStatusOverview;
  data_quality: DataQualityOverview;
  recent_activity: RecentActivity;
  alerts: ETLAlert[];
  performance_metrics: PerformanceMetrics;
}

/**
 * System overview for admin dashboard
 */
export interface SystemOverview {
  total_warehouses: number;
  active_syncs: number;
  failed_syncs_24h: number;
  data_sources_active: {
    api: boolean;
    ui_reports: boolean;
  };
  last_successful_full_sync: string;
  system_uptime_hours: number;
  disk_usage_percent: number;
  memory_usage_percent: number;
}

/**
 * ETL status overview for admin dashboard
 */
export interface ETLStatusOverview {
  current_syncs: AnalyticsETLStatus[];
  queued_syncs: number;
  completed_today: number;
  failed_today: number;
  average_sync_duration_minutes: number;
  next_scheduled_sync: string;
}

/**
 * Data quality overview for admin dashboard
 */
export interface DataQualityOverview {
  current_metrics: DataQualityMetrics;
  quality_trend_7d: QualityTrendPoint[];
  validation_issues: ValidationIssue[];
  normalization_conflicts: number;
}

/**
 * Recent activity for admin dashboard
 */
export interface RecentActivity {
  recent_syncs: AnalyticsETLStatus[];
  recent_alerts: ETLAlert[];
  recent_quality_checks: DataQualityLogEntry[];
  user_actions: UserAction[];
}

/**
 * Performance metrics for admin dashboard
 */
export interface PerformanceMetrics {
  sync_performance: {
    records_per_minute: number;
    api_response_time_ms: number;
    database_write_time_ms: number;
    validation_time_ms: number;
  };
  resource_usage: {
    cpu_percent: number;
    memory_mb: number;
    disk_io_mb_per_sec: number;
    network_mb_per_sec: number;
  };
  error_rates: {
    api_error_rate: number;
    validation_error_rate: number;
    database_error_rate: number;
  };
}

// =============================================================================
// DETAILED MONITORING TYPES
// =============================================================================

/**
 * Quality trend point for charts and graphs
 */
export interface QualityTrendPoint {
  timestamp: string;
  overall_score: number;
  api_coverage: number;
  ui_coverage: number;
  validation_pass_rate: number;
  discrepancy_rate: number;
}

/**
 * Validation issue for quality monitoring
 */
export interface ValidationIssue {
  id: string;
  warehouse_name: string;
  product_id: number;
  issue_type: "discrepancy" | "missing_data" | "invalid_format" | "timeout";
  severity: "low" | "medium" | "high" | "critical";
  description: string;
  api_value?: number;
  ui_value?: number;
  discrepancy_percent?: number;
  detected_at: string;
  resolved_at?: string;
  resolution_action?: string;
}

/**
 * User action for audit trail
 */
export interface UserAction {
  id: string;
  user_id: string;
  user_name: string;
  action_type:
    | "sync_trigger"
    | "config_change"
    | "alert_resolve"
    | "manual_fix";
  description: string;
  target_resource: string;
  timestamp: string;
  ip_address: string;
  user_agent: string;
  success: boolean;
  error_message?: string;
}

// =============================================================================
// CONFIGURATION MANAGEMENT TYPES
// =============================================================================

/**
 * ETL configuration management
 */
export interface ETLConfigurationManager {
  sync_configs: SyncConfiguration[];
  normalization_rules: WarehouseNormalizationRule[];
  validation_rules: ValidationRuleConfig[];
  alert_rules: AlertRuleConfig[];
  system_settings: SystemSettings;
}

/**
 * Validation rule configuration
 */
export interface ValidationRuleConfig {
  id: string;
  name: string;
  description: string;
  enabled: boolean;
  rule_type:
    | "discrepancy_check"
    | "freshness_check"
    | "completeness_check"
    | "format_check";
  threshold_value: number;
  threshold_unit: "percent" | "minutes" | "count" | "ratio";
  action: "warn" | "block" | "auto_fix" | "escalate";
  notification_enabled: boolean;
  created_at: string;
  updated_at: string;
  created_by: string;
}

/**
 * Alert rule configuration
 */
export interface AlertRuleConfig {
  id: string;
  name: string;
  description: string;
  enabled: boolean;
  trigger_condition: string; // SQL-like condition
  severity: "low" | "medium" | "high" | "critical";
  notification_channels: ("email" | "slack" | "webhook")[];
  cooldown_minutes: number; // Prevent spam
  auto_resolve: boolean;
  escalation_rules: EscalationRule[];
}

/**
 * Escalation rule for alerts
 */
export interface EscalationRule {
  delay_minutes: number;
  target_channels: ("email" | "slack" | "webhook")[];
  target_users: string[];
  message_template: string;
}

/**
 * System settings for ETL
 */
export interface SystemSettings {
  general: {
    timezone: string;
    date_format: string;
    default_page_size: number;
    session_timeout_minutes: number;
  };
  etl: {
    default_batch_size: number;
    max_parallel_syncs: number;
    default_timeout_minutes: number;
    retry_exponential_base: number;
    cleanup_logs_after_days: number;
  };
  monitoring: {
    metrics_retention_days: number;
    alert_retention_days: number;
    performance_sampling_interval_seconds: number;
    health_check_interval_seconds: number;
  };
  notifications: {
    default_email_from: string;
    smtp_settings: SMTPSettings;
    slack_webhook_url?: string;
    webhook_timeout_seconds: number;
  };
}

/**
 * SMTP settings for email notifications
 */
export interface SMTPSettings {
  host: string;
  port: number;
  username: string;
  password: string; // Should be encrypted
  use_tls: boolean;
  use_ssl: boolean;
}

// =============================================================================
// REAL-TIME MONITORING TYPES
// =============================================================================

/**
 * Real-time sync progress for live monitoring
 */
export interface SyncProgress {
  sync_id: number;
  current_step: string;
  total_steps: number;
  completed_steps: number;
  current_operation: string;
  records_processed: number;
  estimated_total_records: number;
  start_time: string;
  estimated_completion: string;
  current_rate_per_minute: number;
  errors_encountered: number;
  warnings_encountered: number;
}

/**
 * Live system metrics for real-time monitoring
 */
export interface LiveSystemMetrics {
  timestamp: string;
  cpu_usage: number;
  memory_usage: number;
  disk_usage: number;
  network_in_mbps: number;
  network_out_mbps: number;
  active_connections: number;
  queue_size: number;
  cache_hit_rate: number;
}

/**
 * WebSocket message types for real-time updates
 */
export interface WebSocketMessage {
  type: "sync_progress" | "system_metrics" | "alert" | "status_change";
  timestamp: string;
  data: SyncProgress | LiveSystemMetrics | ETLAlert | AnalyticsETLStatus;
}

// =============================================================================
// REPORTING TYPES
// =============================================================================

/**
 * Report generation request
 */
export interface ReportRequest {
  report_type:
    | "quality_summary"
    | "performance_analysis"
    | "error_analysis"
    | "usage_statistics";
  date_range: {
    start_date: string;
    end_date: string;
  };
  filters: {
    warehouses?: string[];
    data_sources?: string[];
    sync_types?: string[];
    include_resolved_issues?: boolean;
  };
  format: "json" | "csv" | "pdf";
  email_recipients?: string[];
}

/**
 * Generated report metadata
 */
export interface GeneratedReport {
  id: string;
  report_type: string;
  generated_at: string;
  generated_by: string;
  file_path: string;
  file_size_bytes: number;
  record_count: number;
  date_range: {
    start_date: string;
    end_date: string;
  };
  status: "generating" | "completed" | "failed";
  download_url?: string;
  expires_at: string;
}

// =============================================================================
// UTILITY TYPES FOR ADMIN INTERFACES
// =============================================================================

/**
 * Pagination parameters for admin lists
 */
export interface AdminPagination {
  page: number;
  page_size: number;
  total_records: number;
  total_pages: number;
  has_next: boolean;
  has_previous: boolean;
}

/**
 * Sorting parameters for admin tables
 */
export interface AdminSorting {
  field: string;
  direction: "asc" | "desc";
}

/**
 * Filtering parameters for admin views
 */
export interface AdminFilters {
  search?: string;
  date_range?: {
    start: string;
    end: string;
  };
  status?: string[];
  severity?: string[];
  source?: string[];
  [key: string]: string[] | undefined;
}

/**
 * Admin table configuration
 */
export interface AdminTableConfig {
  columns: AdminTableColumn[];
  pagination: AdminPagination;
  sorting: AdminSorting;
  filters: AdminFilters;
  actions: AdminTableAction[];
}

/**
 * Admin table column definition
 */
export interface AdminTableColumn {
  key: string;
  label: string;
  sortable: boolean;
  filterable: boolean;
  width?: string;
  align?: "left" | "center" | "right";
  format?: "date" | "number" | "percent" | "duration" | "bytes";
}

/**
 * Admin table action definition
 */
export interface AdminTableAction {
  key: string;
  label: string;
  icon?: string;
  variant: "primary" | "secondary" | "danger" | "warning";
  requires_confirmation?: boolean;
  confirmation_message?: string;
  disabled_when?: (row: Record<string, unknown>) => boolean;
}
