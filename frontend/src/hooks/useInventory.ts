import { useQuery } from "@tanstack/react-query";
import type { UseQueryResult } from "@tanstack/react-query";
import { inventoryService } from "@/services";
import type { DashboardData, Product } from "@/types";

// Query keys for caching
export const inventoryKeys = {
  all: ["inventory"] as const,
  dashboard: (limit?: number) =>
    [...inventoryKeys.all, "dashboard", limit] as const,
  product: (sku: string) => [...inventoryKeys.all, "product", sku] as const,
  products: (filters?: Record<string, unknown>) =>
    [...inventoryKeys.all, "products", filters] as const,
};

// Hook options
export interface UseInventoryOptions {
  limit?: number;
  enabled?: boolean;
  refetchInterval?: number;
}

/**
 * Hook to fetch dashboard inventory data
 */
export function useInventory(
  options: UseInventoryOptions = {}
): UseQueryResult<DashboardData, Error> {
  const { limit = 10, enabled = true, refetchInterval } = options;

  return useQuery({
    queryKey: inventoryKeys.dashboard(limit),
    queryFn: () => inventoryService.getDashboardData({ limit }),
    staleTime: 5 * 60 * 1000, // 5 minutes
    gcTime: 10 * 60 * 1000, // 10 minutes (formerly cacheTime)
    enabled,
    refetchInterval,
    retry: (failureCount, error) => {
      // Don't retry on 4xx errors (client errors)
      if (error instanceof Error && "statusCode" in error) {
        const statusCode = (error as { statusCode?: number }).statusCode;
        if (statusCode && statusCode >= 400 && statusCode < 500) {
          return false;
        }
      }
      // Retry on 5xx errors and network errors up to 3 times
      return failureCount < 3;
    },
    retryDelay: (attemptIndex) => Math.min(1000 * 2 ** attemptIndex, 30000), // Exponential backoff
  });
}

/**
 * Hook to fetch product details by SKU
 */
export function useProduct(
  sku: string,
  options: { enabled?: boolean } = {}
): UseQueryResult<Product, Error> {
  const { enabled = true } = options;

  return useQuery({
    queryKey: inventoryKeys.product(sku),
    queryFn: () => inventoryService.getProductBySku(sku),
    staleTime: 5 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
    enabled: enabled && !!sku,
    retry: 2,
  });
}

/**
 * Hook to fetch products with filters
 */
export interface UseProductsOptions {
  warehouse?: string;
  category?: string;
  stockStatus?: string;
  limit?: number;
  offset?: number;
  enabled?: boolean;
}

export function useProducts(
  options: UseProductsOptions = {}
): UseQueryResult<Product[], Error> {
  const { enabled = true, ...filters } = options;

  return useQuery({
    queryKey: inventoryKeys.products(filters),
    queryFn: () => inventoryService.getProducts(filters),
    staleTime: 5 * 60 * 1000,
    gcTime: 10 * 60 * 1000,
    enabled,
    retry: 2,
  });
}
