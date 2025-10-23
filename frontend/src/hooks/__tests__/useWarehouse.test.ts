import { describe, it, expect, vi, beforeEach } from "vitest";
import { renderHook, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import React from "react";
import {
  useWarehouseDashboard,
  useWarehouses,
  useClusters,
  useWarehouseDashboardFull,
} from "../useWarehouse";
import { warehouseService } from "@/services";
import type {
  WarehouseDashboardData,
  WarehouseListItem,
  ClusterListItem,
} from "@/types";

// Mock the warehouse service
vi.mock("@/services", () => ({
  warehouseService: {
    getDashboardData: vi.fn(),
    getWarehouses: vi.fn(),
    getClusters: vi.fn(),
    exportToCSV: vi.fn(),
    downloadCSV: vi.fn(),
  },
}));

const mockDashboardData: WarehouseDashboardData = {
  warehouses: [
    {
      warehouse_name: "Test Warehouse",
      cluster: "Test Cluster",
      items: [],
      totals: {
        total_items: 0,
        total_available: 0,
        total_replenishment_need: 0,
      },
    },
  ],
  summary: {
    total_products: 100,
    active_products: 80,
    total_replenishment_need: 500,
    by_liquidity: {
      critical: 10,
      low: 20,
      normal: 40,
      excess: 10,
    },
  },
  filters_applied: {
    active_only: true,
  },
  pagination: {
    limit: 100,
    offset: 0,
    total: 100,
    current_page: 1,
    total_pages: 1,
    has_next: false,
    has_prev: false,
  },
  last_updated: "2025-10-22T12:00:00Z",
};

const mockWarehouses: WarehouseListItem[] = [
  { warehouse_name: "Warehouse 1", cluster: "Cluster 1", product_count: 10 },
  { warehouse_name: "Warehouse 2", cluster: "Cluster 2", product_count: 15 },
];

const mockClusters: ClusterListItem[] = [
  { cluster: "Cluster 1", warehouse_count: 5, product_count: 25 },
  { cluster: "Cluster 2", warehouse_count: 3, product_count: 18 },
];

const createWrapper = () => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
      },
    },
  });

  return ({ children }: { children: React.ReactNode }) =>
    React.createElement(QueryClientProvider, { client: queryClient }, children);
};

describe("useWarehouseDashboard", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("fetches dashboard data successfully", async () => {
    vi.mocked(warehouseService.getDashboardData).mockResolvedValue(
      mockDashboardData
    );

    const { result } = renderHook(() => useWarehouseDashboard(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data).toEqual(mockDashboardData);
    expect(warehouseService.getDashboardData).toHaveBeenCalledTimes(1);
  });

  it("passes filter parameters to service", async () => {
    vi.mocked(warehouseService.getDashboardData).mockResolvedValue(
      mockDashboardData
    );

    const filters = {
      warehouse: "Test Warehouse",
      cluster: "Test Cluster",
      active_only: false,
    };

    renderHook(() => useWarehouseDashboard(filters), {
      wrapper: createWrapper(),
    });

    await waitFor(() =>
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(filters)
    );
  });

  it("handles errors gracefully", async () => {
    const error = new Error("Failed to fetch");
    vi.mocked(warehouseService.getDashboardData).mockRejectedValue(error);

    const { result } = renderHook(() => useWarehouseDashboard(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isError).toBe(true));

    expect(result.current.error).toEqual(error);
  });

  it("can be disabled", async () => {
    vi.mocked(warehouseService.getDashboardData).mockResolvedValue(
      mockDashboardData
    );

    renderHook(() => useWarehouseDashboard({ enabled: false }), {
      wrapper: createWrapper(),
    });

    // Wait a bit to ensure it doesn't fetch
    await new Promise((resolve) => setTimeout(resolve, 100));

    expect(warehouseService.getDashboardData).not.toHaveBeenCalled();
  });
});

describe("useWarehouses", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("fetches warehouses successfully", async () => {
    vi.mocked(warehouseService.getWarehouses).mockResolvedValue(mockWarehouses);

    const { result } = renderHook(() => useWarehouses(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data).toEqual(mockWarehouses);
    expect(warehouseService.getWarehouses).toHaveBeenCalledTimes(1);
  });

  it("handles errors gracefully", async () => {
    const error = new Error("Failed to fetch warehouses");
    vi.mocked(warehouseService.getWarehouses).mockRejectedValue(error);

    const { result } = renderHook(() => useWarehouses(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isError).toBe(true));

    expect(result.current.error).toEqual(error);
  });

  it("can be disabled", async () => {
    vi.mocked(warehouseService.getWarehouses).mockResolvedValue(mockWarehouses);

    renderHook(() => useWarehouses({ enabled: false }), {
      wrapper: createWrapper(),
    });

    await new Promise((resolve) => setTimeout(resolve, 100));

    expect(warehouseService.getWarehouses).not.toHaveBeenCalled();
  });
});

describe("useClusters", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("fetches clusters successfully", async () => {
    vi.mocked(warehouseService.getClusters).mockResolvedValue(mockClusters);

    const { result } = renderHook(() => useClusters(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(result.current.data).toEqual(mockClusters);
    expect(warehouseService.getClusters).toHaveBeenCalledTimes(1);
  });

  it("handles errors gracefully", async () => {
    const error = new Error("Failed to fetch clusters");
    vi.mocked(warehouseService.getClusters).mockRejectedValue(error);

    const { result } = renderHook(() => useClusters(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isError).toBe(true));

    expect(result.current.error).toEqual(error);
  });
});

describe("useWarehouseDashboardFull", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("fetches all data successfully", async () => {
    vi.mocked(warehouseService.getDashboardData).mockResolvedValue(
      mockDashboardData
    );
    vi.mocked(warehouseService.getWarehouses).mockResolvedValue(mockWarehouses);
    vi.mocked(warehouseService.getClusters).mockResolvedValue(mockClusters);

    const { result } = renderHook(() => useWarehouseDashboardFull(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.isLoading).toBe(false));

    expect(result.current.dashboard.data).toEqual(mockDashboardData);
    expect(result.current.warehouses.data).toEqual(mockWarehouses);
    expect(result.current.clusters.data).toEqual(mockClusters);
    expect(result.current.hasError).toBe(false);
  });

  it("sets hasError when any query fails", async () => {
    vi.mocked(warehouseService.getDashboardData).mockRejectedValue(
      new Error("Failed")
    );
    vi.mocked(warehouseService.getWarehouses).mockResolvedValue(mockWarehouses);
    vi.mocked(warehouseService.getClusters).mockResolvedValue(mockClusters);

    const { result } = renderHook(() => useWarehouseDashboardFull(), {
      wrapper: createWrapper(),
    });

    await waitFor(() => expect(result.current.hasError).toBe(true));
  });

  it("can disable loading warehouses and clusters", async () => {
    vi.mocked(warehouseService.getDashboardData).mockResolvedValue(
      mockDashboardData
    );

    renderHook(
      () =>
        useWarehouseDashboardFull({
          loadWarehouses: false,
          loadClusters: false,
        }),
      {
        wrapper: createWrapper(),
      }
    );

    await waitFor(() =>
      expect(warehouseService.getDashboardData).toHaveBeenCalled()
    );

    expect(warehouseService.getWarehouses).not.toHaveBeenCalled();
    expect(warehouseService.getClusters).not.toHaveBeenCalled();
  });
});
