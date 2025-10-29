/**
 * API Service Layer for Warehouse Dashboard
 *
 * Provides centralized API functions with proper error handling,
 * retry mechanisms, and TypeScript interfaces.
 *
 * Requirements: 6.5, 7.4, 7.5
 */

import type {
  DetailedStockRequest,
  DetailedStockResponse,
  ErrorResponse,
} from "../types/inventory-dashboard";

/**
 * API configuration
 */
const API_CONFIG = {
  baseUrl: "/api",
  timeout: 30000, // 30 seconds
  retryAttempts: 3,
  retryDelay: 1000, // Base delay in ms
  maxRetryDelay: 30000, // Max delay in ms
} as const;

/**
 * Custom error class for API errors
 */
export class ApiError extends Error {
  constructor(
    message: string,
    public status?: number,
    public code?: string,
    public details?: any
  ) {
    super(message);
    this.name = "ApiError";
  }
}

/**
 * Network error class for connection issues
 */
export class NetworkError extends Error {
  constructor(message: string, public originalError?: Error) {
    super(message);
    this.name = "NetworkError";
  }
}

/**
 * Timeout error class for request timeouts
 */
export class TimeoutError extends Error {
  constructor(message: string = "Request timeout") {
    super(message);
    this.name = "TimeoutError";
  }
}

/**
 * Sleep utility for retry delays
 */
const sleep = (ms: number): Promise<void> =>
  new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Calculate exponential backoff delay
 */
const calculateRetryDelay = (attempt: number, baseDelay: number): number => {
  const delay = baseDelay * Math.pow(2, attempt);
  const jitter = Math.random() * 0.1 * delay; // Add 10% jitter
  return Math.min(delay + jitter, API_CONFIG.maxRetryDelay);
};

/**
 * Check if error is retryable
 */
const isRetryableError = (error: Error): boolean => {
  if (error instanceof NetworkError) return true;
  if (error instanceof TimeoutError) return true;
  if (error instanceof ApiError) {
    // Retry on server errors (5xx) but not client errors (4xx)
    return error.status ? error.status >= 500 : false;
  }
  return false;
};

/**
 * Create AbortController with timeout
 */
const createTimeoutController = (timeoutMs: number): AbortController => {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => {
    controller.abort();
  }, timeoutMs);

  // Clear timeout if request completes normally
  controller.signal.addEventListener("abort", () => {
    clearTimeout(timeoutId);
  });

  return controller;
};

/**
 * Enhanced fetch with timeout and error handling
 */
const fetchWithTimeout = async (
  url: string,
  options: RequestInit = {},
  timeoutMs: number = API_CONFIG.timeout
): Promise<Response> => {
  const controller = createTimeoutController(timeoutMs);

  try {
    const response = await fetch(url, {
      ...options,
      signal: controller.signal,
      headers: {
        "Content-Type": "application/json",
        ...options.headers,
      },
    });

    return response;
  } catch (error) {
    if (error instanceof Error) {
      if (error.name === "AbortError") {
        throw new TimeoutError(`Request timeout after ${timeoutMs}ms`);
      }
      if (error.message.includes("fetch")) {
        throw new NetworkError("Network connection failed", error);
      }
    }
    throw error;
  }
};

/**
 * Parse API response with proper error handling
 */
const parseApiResponse = async <T>(response: Response): Promise<T> => {
  let data: any;

  try {
    data = await response.json();
  } catch (error) {
    throw new ApiError(
      "Invalid JSON response from server",
      response.status,
      "INVALID_JSON"
    );
  }

  if (!response.ok) {
    // Handle structured error responses
    if (data && typeof data === "object" && "error" in data) {
      const errorData = data as ErrorResponse;
      throw new ApiError(
        errorData.error.message,
        response.status,
        errorData.error.code,
        errorData.error.details
      );
    }

    // Handle generic HTTP errors
    throw new ApiError(
      `HTTP ${response.status}: ${response.statusText}`,
      response.status,
      "HTTP_ERROR"
    );
  }

  return data as T;
};

/**
 * Generic API request function with retry logic
 */
const apiRequest = async <T>(
  endpoint: string,
  options: RequestInit = {},
  retryAttempts: number = API_CONFIG.retryAttempts
): Promise<T> => {
  const url = `${API_CONFIG.baseUrl}${endpoint}`;
  let lastError: Error;

  for (let attempt = 0; attempt <= retryAttempts; attempt++) {
    try {
      const response = await fetchWithTimeout(url, options);
      return await parseApiResponse<T>(response);
    } catch (error) {
      lastError = error as Error;

      // Don't retry on last attempt or non-retryable errors
      if (attempt === retryAttempts || !isRetryableError(lastError)) {
        break;
      }

      // Wait before retrying with exponential backoff
      const delay = calculateRetryDelay(attempt, API_CONFIG.retryDelay);
      await sleep(delay);
    }
  }

  throw lastError!;
};

/**
 * Build query string from parameters
 */
const buildQueryString = (params: Record<string, any>): string => {
  const searchParams = new URLSearchParams();

  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null) {
      if (Array.isArray(value)) {
        searchParams.append(key, value.join(","));
      } else {
        searchParams.append(key, String(value));
      }
    }
  });

  return searchParams.toString();
};

/**
 * Fetch detailed inventory data with filtering and sorting
 */
export const fetchDetailedInventory = async (
  params: DetailedStockRequest
): Promise<DetailedStockResponse> => {
  const queryString = buildQueryString(params);
  const endpoint = `/api/detailed-stock.php${
    queryString ? `?${queryString}` : ""
  }`;

  const response = await apiRequest<any>(endpoint, {
    method: "GET",
  });

  // Transform API response to match expected format
  return {
    success: response.success,
    data: response.data.map((item: any) => {
      const availableStock = item.available_stock || 0;
      const currentStock = item.present || 0;

      // Generate realistic mock data for sales metrics
      // TODO: Replace with real data from analytics API
      const dailySales = Math.max(0, Math.random() * 5 + 1); // 1-6 units per day
      const sales28d = Math.floor(dailySales * 28);
      const sales7d = Math.floor(dailySales * 7);

      // Calculate days of stock
      const daysOfStock = dailySales > 0 ? availableStock / dailySales : 999;
      const monthsOfStock = daysOfStock / 30;

      // Determine status based on days of stock
      let status = item.stock_status || "normal";
      if (status === "unknown" || !status) {
        if (availableStock <= 0) {
          status = "critical";
        } else if (daysOfStock < 14) {
          status = "critical";
        } else if (daysOfStock < 30) {
          status = "low";
        } else if (daysOfStock < 60) {
          status = "normal";
        } else {
          status = "excess";
        }
      }

      // Calculate recommended quantity (target: 60 days of stock)
      const targetDays = 60;
      const targetStock = dailySales * targetDays;
      const recommendedQty = Math.max(
        0,
        Math.floor(targetStock - availableStock)
      );
      const recommendedValue = recommendedQty * 100; // Assuming 100 RUB per unit

      // Calculate urgency score (0-100)
      const urgencyScore =
        daysOfStock < 30
          ? Math.max(0, Math.min(100, Math.floor((30 - daysOfStock) * 3.33)))
          : 0;

      return {
        productId: item.product_id,
        productName: item.product_name,
        sku: item.offer_id,
        warehouseName: item.warehouse_name,
        currentStock,
        reservedStock: item.reserved || 0,
        availableStock,
        dailySales: Number(dailySales.toFixed(1)),
        sales7d,
        sales28d,
        salesTrend: "stable" as const,
        daysOfStock: Number(daysOfStock.toFixed(0)),
        monthsOfStock: Number(monthsOfStock.toFixed(1)),
        turnoverRate:
          dailySales > 0 ? Number((365 / daysOfStock).toFixed(1)) : 0,
        status: status as any,
        recommendedQty,
        recommendedValue,
        urgencyScore,
        lastUpdated: item.last_updated || new Date().toISOString(),
        lastSaleDate: null,
        stockoutRisk: urgencyScore,
      };
    }),
    metadata: {
      totalCount: response.meta?.total_count || response.data.length,
      filteredCount: response.meta?.filtered_count || response.data.length,
      timestamp: response.timestamp || new Date().toISOString(),
      processingTime: 0,
    },
  };
};

/**
 * Fetch list of available warehouses
 */
export const fetchWarehouses = async (): Promise<string[]> => {
  const response = await apiRequest<{
    success: boolean;
    data: Array<{
      warehouse_name: string;
      product_count: number;
      total_stock: number;
      critical_count: number;
      low_count: number;
      replenishment_needed_count: number;
    }>;
  }>("/api/detailed-stock.php?action=warehouses", {
    method: "GET",
  });

  // Extract warehouse names from the response
  return response.data.map((w) => w.warehouse_name);
};

/**
 * Export inventory data in specified format
 */
export const exportInventoryData = async (
  params: DetailedStockRequest,
  format: "csv" | "excel" | "procurement"
): Promise<Blob> => {
  const queryString = buildQueryString({ ...params, format });
  const endpoint = `/inventory/export${queryString ? `?${queryString}` : ""}`;

  const response = await fetchWithTimeout(`${API_CONFIG.baseUrl}${endpoint}`, {
    method: "GET",
    headers: {
      Accept:
        format === "excel"
          ? "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
          : "text/csv",
    },
  });

  if (!response.ok) {
    const errorData = await response.json().catch(() => ({}));
    throw new ApiError(
      errorData.message || `Export failed: ${response.statusText}`,
      response.status,
      "EXPORT_ERROR"
    );
  }

  return response.blob();
};

/**
 * Health check endpoint to verify API connectivity
 */
export const checkApiHealth = async (): Promise<boolean> => {
  try {
    await apiRequest<{ status: string }>("/health", {
      method: "GET",
    });
    return true;
  } catch (error) {
    return false;
  }
};

/**
 * API service object with all methods
 */
export const apiService = {
  fetchDetailedInventory,
  fetchWarehouses,
  exportInventoryData,
  checkApiHealth,
} as const;
