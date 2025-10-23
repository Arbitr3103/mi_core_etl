import { api } from "./api";
import type {
  WarehouseDashboardData,
  WarehouseApiResponse,
  WarehouseDashboardParams,
  ExportParams,
  WarehouseListItem,
  ClusterListItem,
} from "@/types";

// Warehouse service endpoints
const ENDPOINTS = {
  DASHBOARD: "/warehouse-dashboard.php",
  EXPORT: "/warehouse-dashboard.php",
  WAREHOUSES: "/warehouse-dashboard.php",
  CLUSTERS: "/warehouse-dashboard.php",
} as const;

// Warehouse service interface
export const warehouseService = {
  /**
   * Get warehouse dashboard data with filters and sorting
   */
  async getDashboardData(
    params: WarehouseDashboardParams = {}
  ): Promise<WarehouseDashboardData> {
    const queryParams: Record<string, string | number | boolean> = {
      action: "dashboard",
    };

    // Add optional parameters
    if (params.warehouse) queryParams.warehouse = params.warehouse;
    if (params.cluster) queryParams.cluster = params.cluster;
    if (params.liquidity_status)
      queryParams.liquidity_status = params.liquidity_status;
    if (params.active_only !== undefined)
      queryParams.active_only = params.active_only;
    if (params.has_replenishment_need !== undefined)
      queryParams.has_replenishment_need = params.has_replenishment_need;
    if (params.sort_by) queryParams.sort_by = params.sort_by;
    if (params.sort_order) queryParams.sort_order = params.sort_order;
    if (params.limit) queryParams.limit = params.limit;
    if (params.offset) queryParams.offset = params.offset;

    const response = await api.get<
      WarehouseApiResponse<WarehouseDashboardData>
    >(ENDPOINTS.DASHBOARD, {
      params: queryParams,
    });

    if (!response.success) {
      throw new Error(response.error || "Failed to fetch dashboard data");
    }

    return response.data;
  },

  /**
   * Export warehouse data to CSV
   */
  async exportToCSV(params: ExportParams = {}): Promise<Blob> {
    const queryParams: Record<string, string | number | boolean> = {
      action: "export",
    };

    // Add optional parameters
    if (params.warehouse) queryParams.warehouse = params.warehouse;
    if (params.cluster) queryParams.cluster = params.cluster;
    if (params.liquidity_status)
      queryParams.liquidity_status = params.liquidity_status;
    if (params.active_only !== undefined)
      queryParams.active_only = params.active_only;
    if (params.has_replenishment_need !== undefined)
      queryParams.has_replenishment_need = params.has_replenishment_need;

    // Build URL with query parameters
    const url = new URL(
      `${import.meta.env.VITE_API_BASE_URL || "/api"}${ENDPOINTS.EXPORT}`,
      window.location.origin
    );

    Object.entries(queryParams).forEach(([key, value]) => {
      url.searchParams.append(key, String(value));
    });

    // Fetch CSV file
    const response = await fetch(url.toString());

    if (!response.ok) {
      throw new Error("Failed to export data");
    }

    return response.blob();
  },

  /**
   * Download CSV file
   */
  downloadCSV(blob: Blob, filename?: string): void {
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download =
      filename ||
      `warehouse_dashboard_${new Date().toISOString().split("T")[0]}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
  },

  /**
   * Get list of all warehouses
   */
  async getWarehouses(): Promise<WarehouseListItem[]> {
    const response = await api.get<WarehouseApiResponse<WarehouseListItem[]>>(
      ENDPOINTS.WAREHOUSES,
      {
        params: {
          action: "warehouses",
        },
      }
    );

    if (!response.success) {
      throw new Error(response.error || "Failed to fetch warehouses");
    }

    return response.data;
  },

  /**
   * Get list of all clusters
   */
  async getClusters(): Promise<ClusterListItem[]> {
    const response = await api.get<WarehouseApiResponse<ClusterListItem[]>>(
      ENDPOINTS.CLUSTERS,
      {
        params: {
          action: "clusters",
        },
      }
    );

    if (!response.success) {
      throw new Error(response.error || "Failed to fetch clusters");
    }

    return response.data;
  },
};

// Export types
export type WarehouseService = typeof warehouseService;
