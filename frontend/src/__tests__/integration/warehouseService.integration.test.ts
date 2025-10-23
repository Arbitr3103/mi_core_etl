/**
 * Integration Tests for Warehouse Service
 *
 * Tests API service integration and data flow
 * Requirements: 1-12
 */

import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { warehouseService } from "@/services/warehouse";
import type { WarehouseDashboardParams } from "@/types";

// Mock fetch for API calls
global.fetch = vi.fn();

describe("Warehouse Service Integration Tests", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  /**
   * Test getDashboardData API integration
   * Requirements: 1, 2, 9, 11
   */
  it("should fetch dashboard data with default parameters", async () => {
    const mockResponse = {
      success: true,
      data: {
        warehouses: [
          {
            warehouse_name: "АДЫГЕЙСК_РФЦ",
            cluster: "Юг",
            items: [],
            totals: {
              total_items: 0,
              total_available: 0,
              total_replenishment_need: 0,
            },
          },
        ],
        summary: {
          total_products: 10,
          active_products: 8,
          total_replenishment_need: 100,
          by_liquidity: {
            critical: 2,
            low: 3,
            normal: 4,
            excess: 1,
          },
        },
        filters_applied: {
          warehouse: null,
          cluster: null,
          liquidity_status: null,
          active_only: true,
          has_replenishment_need: null,
          sort_by: "replenishment_need",
          sort_order: "desc",
        },
        pagination: {
          limit: 100,
          offset: 0,
          total: 10,
          current_page: 1,
          total_pages: 1,
          has_next: false,
          has_prev: false,
        },
        last_updated: "2025-10-22T12:00:00Z",
      },
    };

    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockResponse,
    } as Response);

    const result = await warehouseService.getDashboardData();

    expect(fetch).toHaveBeenCalledWith(
      expect.stringContaining("/warehouse-dashboard.php"),
      expect.any(Object)
    );
    expect(result).toEqual(mockResponse.data);
    expect(result.summary.total_products).toBe(10);
    expect(result.warehouses).toHaveLength(1);
  });

  /**
   * Test getDashboardData with warehouse filter
   * Requirements: 2, 9
   */
  it("should fetch dashboard data with warehouse filter", async () => {
    const mockResponse = {
      success: true,
      data: {
        warehouses: [],
        summary: {
          total_products: 5,
          active_products: 5,
          total_replenishment_need: 50,
          by_liquidity: {
            critical: 1,
            low: 1,
            normal: 2,
            excess: 1,
          },
        },
        filters_applied: {
          warehouse: "АДЫГЕЙСК_РФЦ",
          cluster: null,
          liquidity_status: null,
          active_only: true,
          has_replenishment_need: null,
          sort_by: "replenishment_need",
          sort_order: "desc",
        },
        pagination: {
          limit: 100,
          offset: 0,
          total: 5,
          current_page: 1,
          total_pages: 1,
          has_next: false,
          has_prev: false,
        },
        last_updated: "2025-10-22T12:00:00Z",
      },
    };

    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockResponse,
    } as Response);

    const params: WarehouseDashboardParams = {
      warehouse: "АДЫГЕЙСК_РФЦ",
    };

    const result = await warehouseService.getDashboardData(params);

    expect(fetch).toHaveBeenCalledWith(
      expect.stringContaining("warehouse=АДЫГЕЙСК_РФЦ"),
      expect.any(Object)
    );
    expect(result.filters_applied.warehouse).toBe("АДЫГЕЙСК_РФЦ");
  });

  /**
   * Test getDashboardData with multiple filters
   * Requirements: 9
   */
  it("should fetch dashboard data with multiple filters", async () => {
    const mockResponse = {
      success: true,
      data: {
        warehouses: [],
        summary: {
          total_products: 2,
          active_products: 2,
          total_replenishment_need: 20,
          by_liquidity: {
            critical: 2,
            low: 0,
            normal: 0,
            excess: 0,
          },
        },
        filters_applied: {
          warehouse: "АДЫГЕЙСК_РФЦ",
          cluster: "Юг",
          liquidity_status: "critical",
          active_only: true,
          has_replenishment_need: true,
          sort_by: "replenishment_need",
          sort_order: "desc",
        },
        pagination: {
          limit: 100,
          offset: 0,
          total: 2,
          current_page: 1,
          total_pages: 1,
          has_next: false,
          has_prev: false,
        },
        last_updated: "2025-10-22T12:00:00Z",
      },
    };

    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockResponse,
    } as Response);

    const params: WarehouseDashboardParams = {
      warehouse: "АДЫГЕЙСК_РФЦ",
      cluster: "Юг",
      liquidity_status: "critical",
      has_replenishment_need: true,
    };

    const result = await warehouseService.getDashboardData(params);

    const fetchUrl = vi.mocked(fetch).mock.calls[0][0] as string;
    expect(fetchUrl).toContain("warehouse=АДЫГЕЙСК_РФЦ");
    expect(fetchUrl).toContain("cluster=Юг");
    expect(fetchUrl).toContain("liquidity_status=critical");
    expect(fetchUrl).toContain("has_replenishment_need=true");
    expect(result.summary.total_products).toBe(2);
  });

  /**
   * Test getDashboardData with sorting
   * Requirements: 9
   */
  it("should fetch dashboard data with sorting parameters", async () => {
    const mockResponse = {
      success: true,
      data: {
        warehouses: [],
        summary: {
          total_products: 10,
          active_products: 10,
          total_replenishment_need: 100,
          by_liquidity: {
            critical: 2,
            low: 3,
            normal: 4,
            excess: 1,
          },
        },
        filters_applied: {
          warehouse: null,
          cluster: null,
          liquidity_status: null,
          active_only: true,
          has_replenishment_need: null,
          sort_by: "daily_sales_avg",
          sort_order: "desc",
        },
        pagination: {
          limit: 100,
          offset: 0,
          total: 10,
          current_page: 1,
          total_pages: 1,
          has_next: false,
          has_prev: false,
        },
        last_updated: "2025-10-22T12:00:00Z",
      },
    };

    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockResponse,
    } as Response);

    const params: WarehouseDashboardParams = {
      sort_by: "daily_sales_avg",
      sort_order: "desc",
    };

    const result = await warehouseService.getDashboardData(params);

    const fetchUrl = vi.mocked(fetch).mock.calls[0][0] as string;
    expect(fetchUrl).toContain("sort_by=daily_sales_avg");
    expect(fetchUrl).toContain("sort_order=desc");
    // Verify the result structure is correct
    expect(result.summary.total_products).toBe(10);
  });

  /**
   * Test getDashboardData with pagination
   * Requirements: 12
   */
  it("should fetch dashboard data with pagination parameters", async () => {
    const mockResponse = {
      success: true,
      data: {
        warehouses: [],
        summary: {
          total_products: 100,
          active_products: 100,
          total_replenishment_need: 1000,
          by_liquidity: {
            critical: 20,
            low: 30,
            normal: 40,
            excess: 10,
          },
        },
        filters_applied: {
          warehouse: null,
          cluster: null,
          liquidity_status: null,
          active_only: true,
          has_replenishment_need: null,
          sort_by: "replenishment_need",
          sort_order: "desc",
        },
        pagination: {
          limit: 20,
          offset: 40,
          total: 100,
          current_page: 3,
          total_pages: 5,
          has_next: true,
          has_prev: true,
        },
        last_updated: "2025-10-22T12:00:00Z",
      },
    };

    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockResponse,
    } as Response);

    const params: WarehouseDashboardParams = {
      limit: 20,
      offset: 40,
    };

    const result = await warehouseService.getDashboardData(params);

    const fetchUrl = vi.mocked(fetch).mock.calls[0][0] as string;
    expect(fetchUrl).toContain("limit=20");
    expect(fetchUrl).toContain("offset=40");
    expect(result.pagination.current_page).toBe(3);
    expect(result.pagination.has_next).toBe(true);
    expect(result.pagination.has_prev).toBe(true);
  });

  /**
   * Test getWarehouses API integration
   * Requirements: 2, 9
   */
  it("should fetch list of warehouses", async () => {
    const mockResponse = {
      success: true,
      data: [
        { warehouse_name: "АДЫГЕЙСК_РФЦ", cluster: "Юг", product_count: 50 },
        {
          warehouse_name: "Ростов-на-Дону_РФЦ",
          cluster: "Юг",
          product_count: 30,
        },
        {
          warehouse_name: "Екатеринбург_РФЦ",
          cluster: "Урал",
          product_count: 40,
        },
      ],
    };

    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockResponse,
    } as Response);

    const result = await warehouseService.getWarehouses();

    expect(fetch).toHaveBeenCalledWith(
      expect.stringContaining("action=warehouses"),
      expect.any(Object)
    );
    expect(result).toHaveLength(3);
    expect(result[0].warehouse_name).toBe("АДЫГЕЙСК_РФЦ");
    expect(result[0].product_count).toBe(50);
  });

  /**
   * Test getClusters API integration
   * Requirements: 2, 9
   */
  it("should fetch list of clusters", async () => {
    const mockResponse = {
      success: true,
      data: [
        { cluster: "Юг", warehouse_count: 2, product_count: 80 },
        { cluster: "Урал", warehouse_count: 3, product_count: 120 },
      ],
    };

    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockResponse,
    } as Response);

    const result = await warehouseService.getClusters();

    expect(fetch).toHaveBeenCalledWith(
      expect.stringContaining("action=clusters"),
      expect.any(Object)
    );
    expect(result).toHaveLength(2);
    expect(result[0].cluster).toBe("Юг");
    expect(result[0].warehouse_count).toBe(2);
  });

  /**
   * Test exportToCSV API integration
   * Requirements: 10
   */
  it("should export data to CSV", async () => {
    const mockBlob = new Blob(["test,csv,data"], { type: "text/csv" });

    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      blob: async () => mockBlob,
    } as Response);

    const result = await warehouseService.exportToCSV({
      warehouse: "АДЫГЕЙСК_РФЦ",
    });

    expect(fetch).toHaveBeenCalledWith(
      expect.stringContaining("action=export"),
      expect.any(Object)
    );
    expect(fetch).toHaveBeenCalledWith(
      expect.stringContaining("warehouse=АДЫГЕЙСК_РФЦ"),
      expect.any(Object)
    );
    expect(result).toBeInstanceOf(Blob);
  });

  /**
   * Test error handling for failed API requests
   * Requirements: Error Handling
   */
  it("should throw error when API request fails", async () => {
    const mockResponse = {
      success: false,
      error: "Database connection failed",
    };

    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockResponse,
    } as Response);

    await expect(warehouseService.getDashboardData()).rejects.toThrow(
      "Failed to fetch dashboard data"
    );
  });

  /**
   * Test error handling for network errors
   * Requirements: Error Handling
   */
  it("should throw error when network request fails", async () => {
    vi.mocked(fetch).mockRejectedValueOnce(new Error("Network error"));

    await expect(warehouseService.getDashboardData()).rejects.toThrow(
      "Network error"
    );
  });

  /**
   * Test error handling for invalid response
   * Requirements: Error Handling
   */
  it("should throw error when response is not ok", async () => {
    vi.mocked(fetch).mockResolvedValueOnce({
      ok: false,
      status: 500,
      statusText: "Internal Server Error",
    } as Response);

    await expect(warehouseService.getDashboardData()).rejects.toThrow();
  });

  /**
   * Test data consistency across multiple API calls
   * Requirements: 1, 2, 9
   */
  it("should maintain data consistency across multiple API calls", async () => {
    const mockDashboardResponse = {
      success: true,
      data: {
        warehouses: [
          {
            warehouse_name: "АДЫГЕЙСК_РФЦ",
            cluster: "Юг",
            items: [],
            totals: {
              total_items: 50,
              total_available: 1000,
              total_replenishment_need: 500,
            },
          },
        ],
        summary: {
          total_products: 50,
          active_products: 50,
          total_replenishment_need: 500,
          by_liquidity: {
            critical: 10,
            low: 15,
            normal: 20,
            excess: 5,
          },
        },
        filters_applied: {
          warehouse: null,
          cluster: null,
          liquidity_status: null,
          active_only: true,
          has_replenishment_need: null,
          sort_by: "replenishment_need",
          sort_order: "desc",
        },
        pagination: {
          limit: 100,
          offset: 0,
          total: 50,
          current_page: 1,
          total_pages: 1,
          has_next: false,
          has_prev: false,
        },
        last_updated: "2025-10-22T12:00:00Z",
      },
    };

    const mockWarehousesResponse = {
      success: true,
      data: [
        { warehouse_name: "АДЫГЕЙСК_РФЦ", cluster: "Юг", product_count: 50 },
      ],
    };

    // First call - dashboard data
    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockDashboardResponse,
    } as Response);

    const dashboardResult = await warehouseService.getDashboardData();

    // Second call - warehouses list
    vi.mocked(fetch).mockResolvedValueOnce({
      ok: true,
      json: async () => mockWarehousesResponse,
    } as Response);

    const warehousesResult = await warehouseService.getWarehouses();

    // Verify data consistency
    expect(dashboardResult.warehouses[0].warehouse_name).toBe(
      warehousesResult[0].warehouse_name
    );
    expect(dashboardResult.warehouses[0].totals.total_items).toBe(
      warehousesResult[0].product_count
    );
  });
});
