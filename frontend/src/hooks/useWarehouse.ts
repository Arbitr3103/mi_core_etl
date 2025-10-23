import { useQuery, useMutation } from "@tanstack/react-query";
import type { UseQueryResult, UseMutationResult } from "@tanstack/react-query";
import { warehouseService } from "@/services";
import type {
  WarehouseDashboardData,
  WarehouseDashboardParams,
  ExportParams,
  WarehouseListItem,
  ClusterListItem,
} from "@/types";

// Query keys for caching
export const warehouseKeys = {
  all: ["warehouse"] as const,
  dashboard: (params?: WarehouseDashboardParams) =>
    [...warehouseKeys.all, "dashboard", params] as const,
  warehouses: () => [...warehouseKeys.all, "warehouses"] as const,
  clusters: () => [...warehouseKeys.all, "clusters"] as const,
};

// Hook options for warehouse dashboard
export interface UseWarehouseDashboardOptions extends WarehouseDashboardParams {
  enabled?: boolean;
  refetchInterval?: number;
}

/**
 * Hook to fetch warehouse dashboard data with filters and sorting
 */
export function useWarehouseDashboard(
  options: UseWarehouseDashboardOptions = {}
): UseQueryResult<WarehouseDashboardData, Error> {
  const { enabled = true, refetchInterval, ...params } = options;

  return useQuery({
    queryKey: warehouseKeys.dashboard(params),
    queryFn: () => warehouseService.getDashboardData(params),
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
 * Hook to fetch list of warehouses
 */
export function useWarehouses(
  options: {
    enabled?: boolean;
  } = {}
): UseQueryResult<WarehouseListItem[], Error> {
  const { enabled = true } = options;

  return useQuery({
    queryKey: warehouseKeys.warehouses(),
    queryFn: () => warehouseService.getWarehouses(),
    staleTime: 30 * 60 * 1000, // 30 minutes (warehouses don't change often)
    gcTime: 60 * 60 * 1000, // 1 hour
    enabled,
    retry: 2,
  });
}

/**
 * Hook to fetch list of clusters
 */
export function useClusters(
  options: {
    enabled?: boolean;
  } = {}
): UseQueryResult<ClusterListItem[], Error> {
  const { enabled = true } = options;

  return useQuery({
    queryKey: warehouseKeys.clusters(),
    queryFn: () => warehouseService.getClusters(),
    staleTime: 30 * 60 * 1000, // 30 minutes (clusters don't change often)
    gcTime: 60 * 60 * 1000, // 1 hour
    enabled,
    retry: 2,
  });
}

/**
 * Hook to export warehouse data to CSV
 */
export function useWarehouseExport(): UseMutationResult<
  Blob,
  Error,
  ExportParams,
  unknown
> {
  return useMutation({
    mutationFn: (params: ExportParams) => warehouseService.exportToCSV(params),
    onSuccess: (blob) => {
      // Automatically download the CSV file
      warehouseService.downloadCSV(blob);
    },
    onError: (error) => {
      console.error("Failed to export warehouse data:", error);
    },
  });
}

/**
 * Combined hook for warehouse dashboard with all related data
 */
export interface UseWarehouseDashboardFullOptions
  extends UseWarehouseDashboardOptions {
  loadWarehouses?: boolean;
  loadClusters?: boolean;
}

export interface UseWarehouseDashboardFullResult {
  dashboard: UseQueryResult<WarehouseDashboardData, Error>;
  warehouses: UseQueryResult<WarehouseListItem[], Error>;
  clusters: UseQueryResult<ClusterListItem[], Error>;
  exportMutation: UseMutationResult<Blob, Error, ExportParams, unknown>;
  isLoading: boolean;
  hasError: boolean;
}

/**
 * Convenience hook that combines dashboard data, warehouses, and clusters
 */
export function useWarehouseDashboardFull(
  options: UseWarehouseDashboardFullOptions = {}
): UseWarehouseDashboardFullResult {
  const {
    loadWarehouses = true,
    loadClusters = true,
    ...dashboardOptions
  } = options;

  const dashboard = useWarehouseDashboard(dashboardOptions);
  const warehouses = useWarehouses({ enabled: loadWarehouses });
  const clusters = useClusters({ enabled: loadClusters });
  const exportMutation = useWarehouseExport();

  return {
    dashboard,
    warehouses,
    clusters,
    exportMutation,
    isLoading:
      dashboard.isLoading || warehouses.isLoading || clusters.isLoading,
    hasError: dashboard.isError || warehouses.isError || clusters.isError,
  };
}
