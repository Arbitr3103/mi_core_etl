/**
 * React Query Service Layer
 *
 * Provides React Query hooks for data fetching with proper caching,
 * error handling, and loading states.
 *
 * Requirements: 6.5, 7.1, 7.2, 7.4, 7.5
 */

import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import type {
  DetailedStockRequest,
  DetailedStockResponse,
  FilterState,
  SortConfig,
} from "../types/inventory-dashboard";
import { apiService, ApiError, NetworkError, TimeoutError } from "./api";

/**
 * Query keys for React Query
 */
export const QUERY_KEYS = {
  DETAILED_INVENTORY: "detailed-inventory",
  WAREHOUSES: "warehouses",
  API_HEALTH: "api-health",
} as const;

/**
 * Query configuration
 */
const QUERY_CONFIG = {
  staleTime: 5 * 60 * 1000, // 5 minutes
  gcTime: 10 * 60 * 1000, // 10 minutes (formerly cacheTime)
  retry: (failureCount: number, error: Error) => {
    // Don't retry client errors (4xx)
    if (error instanceof ApiError && error.status && error.status < 500) {
      return false;
    }
    // Retry up to 3 times for server errors and network issues
    return failureCount < 3;
  },
  retryDelay: (attemptIndex: number) =>
    Math.min(1000 * Math.pow(2, attemptIndex), 30000),
} as const;

/**
 * Hook for fetching detailed inventory data
 */
export const useDetailedInventory = (
  filters: FilterState,
  sortConfig: SortConfig,
  enabled: boolean = true
) => {
  const params: DetailedStockRequest = {
    warehouses: filters.warehouses.length > 0 ? filters.warehouses : undefined,
    status: filters.statuses.length > 0 ? filters.statuses : undefined,
    search: filters.searchTerm || undefined,
    sortBy: sortConfig.column,
    sortOrder: sortConfig.direction,
    limit: 1000, // Large limit for initial load
    offset: 0,
    active_only: !filters.showArchived, // Show only active products unless archived is enabled
  };

  return useQuery({
    queryKey: [QUERY_KEYS.DETAILED_INVENTORY, params],
    queryFn: () => apiService.fetchDetailedInventory(params),
    enabled,
    ...QUERY_CONFIG,
    select: (data: DetailedStockResponse) => {
      // Transform data if needed
      return {
        ...data,
        data: data.data.map((item) => ({
          ...item,
          // Ensure numeric values are properly typed
          currentStock: Number(item.currentStock),
          dailySales: Number(item.dailySales),
          daysOfStock: Number(item.daysOfStock),
          recommendedQty: Number(item.recommendedQty),
          recommendedValue: Number(item.recommendedValue),
          urgencyScore: Number(item.urgencyScore),
        })),
      };
    },
  });
};

/**
 * Hook for fetching warehouse list
 */
export const useWarehouses = (enabled: boolean = true) => {
  return useQuery({
    queryKey: [QUERY_KEYS.WAREHOUSES],
    queryFn: apiService.fetchWarehouses,
    enabled,
    ...QUERY_CONFIG,
    staleTime: 30 * 60 * 1000, // 30 minutes (warehouses change less frequently)
  });
};

/**
 * Hook for API health check
 */
export const useApiHealth = (enabled: boolean = true) => {
  return useQuery({
    queryKey: [QUERY_KEYS.API_HEALTH],
    queryFn: apiService.checkApiHealth,
    enabled,
    retry: 1, // Only retry once for health checks
    staleTime: 60 * 1000, // 1 minute
    gcTime: 2 * 60 * 1000, // 2 minutes
  });
};

/**
 * Hook for manual data refresh
 */
export const useRefreshData = () => {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async () => {
      // Invalidate all inventory-related queries
      await queryClient.invalidateQueries({
        queryKey: [QUERY_KEYS.DETAILED_INVENTORY],
      });
      await queryClient.invalidateQueries({
        queryKey: [QUERY_KEYS.WAREHOUSES],
      });
    },
    onSuccess: () => {
      // Optionally show success message
      console.log("Data refreshed successfully");
    },
    onError: (error) => {
      console.error("Failed to refresh data:", error);
    },
  });
};

/**
 * Hook for data export
 */
export const useExportData = () => {
  return useMutation({
    mutationFn: async ({
      params,
      format,
    }: {
      params: DetailedStockRequest;
      format: "csv" | "excel" | "procurement";
    }) => {
      const blob = await apiService.exportInventoryData(params, format);

      // Create download link
      const url = window.URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;

      // Set filename based on format
      const timestamp = new Date().toISOString().split("T")[0];
      const extension = format === "excel" ? "xlsx" : "csv";
      link.download = `inventory-${format}-${timestamp}.${extension}`;

      // Trigger download
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);

      // Clean up
      window.URL.revokeObjectURL(url);

      return { success: true };
    },
    onError: (error) => {
      console.error("Export failed:", error);
    },
  });
};

/**
 * Hook for prefetching data
 */
export const usePrefetchInventory = () => {
  const queryClient = useQueryClient();

  return (filters: FilterState, sortConfig: SortConfig) => {
    const params: DetailedStockRequest = {
      warehouses:
        filters.warehouses.length > 0 ? filters.warehouses : undefined,
      status: filters.statuses.length > 0 ? filters.statuses : undefined,
      search: filters.searchTerm || undefined,
      sortBy: sortConfig.column,
      sortOrder: sortConfig.direction,
      limit: 1000,
      offset: 0,
    };

    queryClient.prefetchQuery({
      queryKey: [QUERY_KEYS.DETAILED_INVENTORY, params],
      queryFn: () => apiService.fetchDetailedInventory(params),
      ...QUERY_CONFIG,
    });
  };
};

/**
 * Error handling utilities
 */
export const getErrorMessage = (error: unknown): string => {
  if (error instanceof ApiError) {
    return error.message;
  }
  if (error instanceof NetworkError) {
    return "Network connection failed. Please check your internet connection.";
  }
  if (error instanceof TimeoutError) {
    return "Request timed out. Please try again.";
  }
  if (error instanceof Error) {
    return error.message;
  }
  return "An unexpected error occurred";
};

/**
 * Check if error is recoverable (user can retry)
 */
export const isRecoverableError = (error: unknown): boolean => {
  if (error instanceof ApiError) {
    // Client errors (4xx) are usually not recoverable
    return !error.status || error.status >= 500;
  }
  if (error instanceof NetworkError || error instanceof TimeoutError) {
    return true;
  }
  return false;
};

/**
 * Get error severity level
 */
export const getErrorSeverity = (error: unknown): "low" | "medium" | "high" => {
  if (error instanceof NetworkError || error instanceof TimeoutError) {
    return "medium";
  }
  if (error instanceof ApiError) {
    if (error.status && error.status >= 500) {
      return "high";
    }
    return "medium";
  }
  return "low";
};
