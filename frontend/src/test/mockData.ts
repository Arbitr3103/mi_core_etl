/**
 * Mock data for testing inventory dashboard components
 */

import { vi } from "vitest";
import type {
  ProductWarehouseItem,
  FilterState,
  SortConfig,
} from "../types/inventory-dashboard";
import { DEFAULT_FILTERS, DEFAULT_SORT } from "../types/inventory-dashboard";

/**
 * Mock product-warehouse items for testing
 */
export const mockInventoryItems: ProductWarehouseItem[] = [
  {
    productId: 1,
    productName: "Test Product A",
    sku: "SKU-001",
    warehouseName: "Warehouse Alpha",
    currentStock: 50,
    reservedStock: 10,
    availableStock: 40,
    dailySales: 5.2,
    sales7d: 36,
    sales28d: 145,
    salesTrend: "up",
    daysOfStock: 9.6,
    monthsOfStock: 0.3,
    turnoverRate: 12.5,
    status: "critical",
    recommendedQty: 200,
    recommendedValue: 5000,
    urgencyScore: 95,
    lastUpdated: "2024-01-15T10:30:00Z",
    lastSaleDate: "2024-01-14T15:20:00Z",
    stockoutRisk: 85,
  },
  {
    productId: 2,
    productName: "Test Product B",
    sku: "SKU-002",
    warehouseName: "Warehouse Beta",
    currentStock: 120,
    reservedStock: 20,
    availableStock: 100,
    dailySales: 4.8,
    sales7d: 34,
    sales28d: 134,
    salesTrend: "stable",
    daysOfStock: 25.0,
    monthsOfStock: 0.8,
    turnoverRate: 14.6,
    status: "low",
    recommendedQty: 80,
    recommendedValue: 2400,
    urgencyScore: 70,
    lastUpdated: "2024-01-15T10:30:00Z",
    lastSaleDate: "2024-01-14T12:45:00Z",
    stockoutRisk: 45,
  },
  {
    productId: 3,
    productName: "Test Product C",
    sku: "SKU-003",
    warehouseName: "Warehouse Gamma",
    currentStock: 300,
    reservedStock: 50,
    availableStock: 250,
    dailySales: 6.1,
    sales7d: 43,
    sales28d: 171,
    salesTrend: "down",
    daysOfStock: 49.2,
    monthsOfStock: 1.6,
    turnoverRate: 7.4,
    status: "normal",
    recommendedQty: 0,
    recommendedValue: 0,
    urgencyScore: 30,
    lastUpdated: "2024-01-15T10:30:00Z",
    lastSaleDate: "2024-01-13T09:15:00Z",
    stockoutRisk: 15,
  },
  {
    productId: 4,
    productName: "Test Product D",
    sku: "SKU-004",
    warehouseName: "Warehouse Delta",
    currentStock: 800,
    reservedStock: 100,
    availableStock: 700,
    dailySales: 2.3,
    sales7d: 16,
    sales28d: 64,
    salesTrend: "stable",
    daysOfStock: 347.8,
    monthsOfStock: 11.6,
    turnoverRate: 1.0,
    status: "excess",
    recommendedQty: 0,
    recommendedValue: 0,
    urgencyScore: 10,
    lastUpdated: "2024-01-15T10:30:00Z",
    lastSaleDate: "2024-01-10T14:30:00Z",
    stockoutRisk: 5,
  },
  {
    productId: 5,
    productName: "Test Product E",
    sku: "SKU-005",
    warehouseName: "Warehouse Echo",
    currentStock: 150,
    reservedStock: 0,
    availableStock: 150,
    dailySales: 0,
    sales7d: 0,
    sales28d: 0,
    salesTrend: "stable",
    daysOfStock: 0,
    monthsOfStock: 0,
    turnoverRate: 0,
    status: "no_sales",
    recommendedQty: 0,
    recommendedValue: 0,
    urgencyScore: 0,
    lastUpdated: "2024-01-15T10:30:00Z",
    lastSaleDate: null,
    stockoutRisk: 0,
  },
];

/**
 * Mock warehouse list
 */
export const mockWarehouses = [
  "Warehouse Alpha",
  "Warehouse Beta",
  "Warehouse Gamma",
  "Warehouse Delta",
  "Warehouse Echo",
];

/**
 * Mock filter states for testing
 */
export const mockFilters: Record<string, FilterState> = {
  default: DEFAULT_FILTERS,
  withSearch: {
    ...DEFAULT_FILTERS,
    searchTerm: "Test Product A",
  },
  withWarehouse: {
    ...DEFAULT_FILTERS,
    warehouses: ["Warehouse Alpha", "Warehouse Beta"],
  },
  withStatus: {
    ...DEFAULT_FILTERS,
    statuses: ["critical", "low"],
  },
  withUrgent: {
    ...DEFAULT_FILTERS,
    showOnlyUrgent: true,
  },
  withRange: {
    ...DEFAULT_FILTERS,
    minDaysOfStock: 10,
    maxDaysOfStock: 50,
  },
};

/**
 * Mock sort configurations
 */
export const mockSortConfigs: Record<string, SortConfig> = {
  default: DEFAULT_SORT,
  byProduct: {
    column: "productName",
    direction: "asc",
  },
  byStock: {
    column: "currentStock",
    direction: "desc",
  },
  byStatus: {
    column: "status",
    direction: "asc",
  },
};

/**
 * Mock API response
 */
export const mockApiResponse = {
  success: true,
  data: mockInventoryItems,
  metadata: {
    totalCount: mockInventoryItems.length,
    filteredCount: mockInventoryItems.length,
    timestamp: "2024-01-15T10:30:00Z",
    processingTime: 150,
  },
};

/**
 * Mock selected rows set
 */
export const mockSelectedRows = new Set<string>([
  "1-Warehouse Alpha",
  "2-Warehouse Beta",
]);

/**
 * Helper function to create mock props for components
 */
export const createMockProps = {
  inventoryTable: (overrides = {}) => ({
    data: mockInventoryItems,
    sortConfig: DEFAULT_SORT,
    onSort: vi.fn(),
    selectedRows: new Set<string>(),
    onRowSelection: vi.fn(),
    onBulkSelection: vi.fn(),
    loading: false,
    ...overrides,
  }),

  filterPanel: (overrides = {}) => ({
    filters: DEFAULT_FILTERS,
    onFilterChange: vi.fn(),
    warehouses: mockWarehouses,
    loading: false,
    ...overrides,
  }),

  exportControls: (overrides = {}) => ({
    data: mockInventoryItems,
    filters: DEFAULT_FILTERS,
    selectedRows: new Set<string>(),
    loading: false,
    ...overrides,
  }),

  statusIndicator: (overrides = {}) => ({
    status: "normal" as const,
    daysOfStock: 45,
    showTooltip: true,
    size: "md" as const,
    ...overrides,
  }),
};
