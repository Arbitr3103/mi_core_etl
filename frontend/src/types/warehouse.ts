// Warehouse Dashboard Types

// Liquidity status types
export type LiquidityStatus = "critical" | "low" | "normal" | "excess";

// Warehouse item with all metrics
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
  liquidity_status: LiquidityStatus;

  // Replenishment
  target_stock: number;
  replenishment_need: number;

  // Metadata
  last_updated: string;
}

// Warehouse totals
export interface WarehouseTotals {
  total_items: number;
  total_available: number;
  total_replenishment_need: number;
}

// Warehouse group
export interface WarehouseGroup {
  warehouse_name: string;
  cluster: string;
  items: WarehouseItem[];
  totals: WarehouseTotals;
}

// Dashboard summary by liquidity status
export interface LiquiditySummary {
  critical: number;
  low: number;
  normal: number;
  excess: number;
}

// Dashboard summary
export interface DashboardSummary {
  total_products: number;
  active_products: number;
  total_replenishment_need: number;
  by_liquidity: LiquiditySummary;
}

// Filter state
export interface FilterState {
  warehouse?: string;
  cluster?: string;
  liquidity_status?: LiquidityStatus;
  active_only: boolean;
  has_replenishment_need?: boolean;
}

// Sort configuration
export type SortField =
  | "name"
  | "warehouse_name"
  | "available"
  | "daily_sales_avg"
  | "days_of_stock"
  | "replenishment_need";

export type SortOrder = "asc" | "desc";

export interface SortConfig {
  field: SortField;
  order: SortOrder;
}

// Pagination info
export interface PaginationInfo {
  limit: number;
  offset: number;
  total: number;
  current_page: number;
  total_pages: number;
  has_next: boolean;
  has_prev: boolean;
}

// Dashboard data response
export interface WarehouseDashboardData {
  warehouses: WarehouseGroup[];
  summary: DashboardSummary;
  filters_applied: FilterState;
  pagination: PaginationInfo;
  last_updated: string;
}

// Warehouse list item
export interface WarehouseListItem {
  warehouse_name: string;
  cluster: string;
  product_count: number;
}

// Cluster list item
export interface ClusterListItem {
  cluster: string;
  warehouse_count: number;
  product_count: number;
}

// API response wrapper
export interface WarehouseApiResponse<T> {
  success: boolean;
  data: T;
  message?: string;
  error?: string;
}

// Query parameters for dashboard
export interface WarehouseDashboardParams {
  warehouse?: string;
  cluster?: string;
  liquidity_status?: LiquidityStatus;
  active_only?: boolean;
  has_replenishment_need?: boolean;
  sort_by?: SortField;
  sort_order?: SortOrder;
  limit?: number;
  offset?: number;
}

// Export parameters
export interface ExportParams {
  warehouse?: string;
  cluster?: string;
  liquidity_status?: LiquidityStatus;
  active_only?: boolean;
  has_replenishment_need?: boolean;
}
