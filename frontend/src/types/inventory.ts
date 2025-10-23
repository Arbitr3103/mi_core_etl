// Product types
export interface Product {
  id: number;
  sku: string;
  name: string;
  current_stock: number;
  available_stock: number;
  reserved_stock: number;
  warehouse_name: string;
  stock_status: "critical" | "low_stock" | "normal" | "overstock";
  last_updated: string;
  price?: number;
  category?: string;
}

// Product group types
export interface ProductGroup {
  count: number;
  items: Product[];
}

// Dashboard data types
export interface DashboardData {
  critical_products: ProductGroup;
  low_stock_products: ProductGroup;
  overstock_products: ProductGroup;
  last_updated: string;
  total_products: number;
}

// API response types
export interface ApiResponse<T> {
  data: T;
  success: boolean;
  message?: string;
  error?: string;
}

export interface ApiError {
  error: string;
  message?: string;
  code?: number;
}

// View mode types
export type ViewMode = "top10" | "all";
export type StockType = "critical" | "low_stock" | "overstock";

// Filter types
export interface InventoryFilters {
  warehouse?: string;
  category?: string;
  stockStatus?: StockType;
  searchQuery?: string;
}

// Sort types
export type SortField = "name" | "current_stock" | "last_updated";
export type SortOrder = "asc" | "desc";

export interface SortConfig {
  field: SortField;
  order: SortOrder;
}
