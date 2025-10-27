/**
 * TypeScript types for Warehouse Dashboard Redesign
 *
 * This file defines the types for the new detailed inventory dashboard
 * that shows product-warehouse pairs instead of aggregated warehouse data.
 *
 * Requirements: 1.1, 1.2, 2.1, 2.2, 2.3, 3.1, 3.2, 4.1, 4.2, 5.1, 5.2, 5.3, 5.4, 5.5
 */

/**
 * Core data structure for product-warehouse pairs
 */
export interface ProductWarehouseItem {
  // Identifiers
  productId: number;
  productName: string;
  sku: string;
  warehouseName: string;

  // Stock Information
  currentStock: number;
  reservedStock: number;
  availableStock: number;

  // Sales Metrics
  dailySales: number; // Average daily sales
  sales7d: number; // Sales last 7 days
  sales28d: number; // Sales last 28 days
  salesTrend: "up" | "down" | "stable";

  // Calculated Metrics
  daysOfStock: number; // Days until stockout
  monthsOfStock: number; // Months of stock
  turnoverRate: number; // Annual turnover rate

  // Status and Recommendations
  status: StockStatus;
  recommendedQty: number;
  recommendedValue: number;
  urgencyScore: number; // 0-100 priority score

  // Metadata
  lastUpdated: string;
  lastSaleDate: string | null;
  stockoutRisk: number;
}

/**
 * Stock status levels based on days of stock
 */
export type StockStatus = "critical" | "low" | "normal" | "excess" | "no_sales";

/**
 * Filter state for the dashboard
 */
export interface FilterState {
  warehouses: string[];
  statuses: StockStatus[];
  searchTerm: string;
  showOnlyUrgent: boolean;
  showArchived: boolean; // Show products with zero stock
  minDaysOfStock?: number;
  maxDaysOfStock?: number;
}

/**
 * Sort configuration for table columns
 */
export interface SortConfig {
  column: keyof ProductWarehouseItem;
  direction: "asc" | "desc";
}

/**
 * API response for detailed inventory data
 */
export interface DetailedStockResponse {
  success: boolean;
  data: ProductWarehouseItem[];
  metadata: {
    totalCount: number;
    filteredCount: number;
    timestamp: string;
    processingTime: number;
  };
}

/**
 * API request parameters for detailed inventory
 */
export interface DetailedStockRequest {
  warehouses?: string[]; // Filter by specific warehouses
  status?: string[]; // Filter by status levels
  search?: string; // Product name/SKU search
  sortBy?: string; // Sort column
  sortOrder?: "asc" | "desc"; // Sort direction
  limit?: number; // Pagination limit
  offset?: number; // Pagination offset
  active_only?: boolean; // Show only products with stock > 0
}

/**
 * Error response format
 */
export interface ErrorResponse {
  success: false;
  error: {
    code: string;
    message: string;
    details?: any;
  };
  timestamp: string;
}

/**
 * Loading states for different operations
 */
export interface LoadingState {
  initial: boolean;
  refresh: boolean;
  filter: boolean;
  export: boolean;
}

/**
 * Export configuration
 */
export interface ExportConfig {
  format: "csv" | "excel" | "procurement";
  includeFilters: boolean;
  filename?: string;
}

/**
 * Dashboard state interface
 */
export interface DashboardState {
  data: ProductWarehouseItem[];
  filteredData: ProductWarehouseItem[];
  filters: FilterState;
  sortConfig: SortConfig;
  loading: LoadingState;
  error: string | null;
  lastUpdated: string | null;
  selectedRows: Set<string>; // Set of product-warehouse pair IDs
}

/**
 * Status indicator props
 */
export interface StatusIndicatorProps {
  status: StockStatus;
  daysOfStock: number;
  showTooltip?: boolean;
  size?: "sm" | "md" | "lg";
}

/**
 * Table column definition
 */
export interface TableColumn {
  key: keyof ProductWarehouseItem;
  label: string;
  sortable: boolean;
  width?: string;
  align?: "left" | "center" | "right";
  format?: (value: any) => string;
}

/**
 * Warehouse list for filters
 */
export interface WarehouseOption {
  name: string;
  count: number;
  cluster?: string;
}

/**
 * Status distribution for summary
 */
export interface StatusDistribution {
  critical: number;
  low: number;
  normal: number;
  excess: number;
  no_sales: number;
}

/**
 * Dashboard summary data
 */
export interface DashboardSummary {
  totalItems: number;
  filteredItems: number;
  statusDistribution: StatusDistribution;
  totalRecommendedValue: number;
  urgentItems: number; // Items with urgencyScore > 80
}

/**
 * Virtual scrolling configuration
 */
export interface VirtualScrollConfig {
  itemHeight: number;
  overscan: number;
  scrollMargin: number;
}

/**
 * Debounced search configuration
 */
export interface SearchConfig {
  debounceMs: number;
  minLength: number;
}

/**
 * Color scheme for status indicators
 */
export const STATUS_COLORS = {
  critical: {
    bg: "bg-red-100",
    text: "text-red-800",
    border: "border-red-200",
    dot: "bg-red-500",
  },
  low: {
    bg: "bg-yellow-100",
    text: "text-yellow-800",
    border: "border-yellow-200",
    dot: "bg-yellow-500",
  },
  normal: {
    bg: "bg-green-100",
    text: "text-green-800",
    border: "border-green-200",
    dot: "bg-green-500",
  },
  excess: {
    bg: "bg-blue-100",
    text: "text-blue-800",
    border: "border-blue-200",
    dot: "bg-blue-500",
  },
  no_sales: {
    bg: "bg-gray-100",
    text: "text-gray-800",
    border: "border-gray-200",
    dot: "bg-gray-500",
  },
} as const;

/**
 * Default filter state
 */
export const DEFAULT_FILTERS: FilterState = {
  warehouses: [],
  statuses: ["critical", "low"], // By default, show only critical and low stock items
  searchTerm: "",
  showOnlyUrgent: false,
  showArchived: false, // By default, hide archived (zero stock) products
};

/**
 * Default sort configuration
 */
export const DEFAULT_SORT: SortConfig = {
  column: "daysOfStock",
  direction: "asc",
};

/**
 * Default virtual scroll configuration
 */
export const DEFAULT_VIRTUAL_CONFIG: VirtualScrollConfig = {
  itemHeight: 60,
  overscan: 10,
  scrollMargin: 8,
};

/**
 * Default search configuration
 */
export const DEFAULT_SEARCH_CONFIG: SearchConfig = {
  debounceMs: 300,
  minLength: 2,
};
