// Stock status thresholds
export const STOCK_THRESHOLDS = {
  CRITICAL: 5,
  LOW: 20,
  OVERSTOCK: 100,
} as const;

// Stock status colors
export const STOCK_STATUS_COLORS = {
  critical: {
    bg: "bg-red-50",
    border: "border-red-200",
    text: "text-red-800",
    badge: "bg-red-100 text-red-800",
  },
  low_stock: {
    bg: "bg-yellow-50",
    border: "border-yellow-200",
    text: "text-yellow-800",
    badge: "bg-yellow-100 text-yellow-800",
  },
  normal: {
    bg: "bg-green-50",
    border: "border-green-200",
    text: "text-green-800",
    badge: "bg-green-100 text-green-800",
  },
  overstock: {
    bg: "bg-blue-50",
    border: "border-blue-200",
    text: "text-blue-800",
    badge: "bg-blue-100 text-blue-800",
  },
} as const;

// API configuration
export const API_CONFIG = {
  TIMEOUT: 30000,
  RETRY_ATTEMPTS: 3,
  RETRY_DELAY: 1000,
  CACHE_TIME: 5 * 60 * 1000, // 5 minutes
} as const;

// Pagination
export const PAGINATION = {
  DEFAULT_PAGE_SIZE: 10,
  PAGE_SIZE_OPTIONS: [10, 25, 50, 100],
} as const;

// Virtualization
export const VIRTUALIZATION = {
  ITEM_HEIGHT: 80,
  OVERSCAN: 5,
} as const;

// Local storage keys
export const STORAGE_KEYS = {
  VIEW_MODE: "inventory-view-mode",
  FILTERS: "inventory-filters",
  SORT: "inventory-sort",
  THEME: "app-theme",
} as const;

// View modes
export const VIEW_MODES = {
  TOP_10: "top10",
  ALL: "all",
} as const;

// Date formats
export const DATE_FORMATS = {
  SHORT: "short",
  LONG: "long",
  TIME: "time",
} as const;
