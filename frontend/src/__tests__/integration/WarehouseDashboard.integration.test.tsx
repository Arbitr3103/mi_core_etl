/**
 * Integration Tests for Warehouse Dashboard
 *
 * Tests component interactions, data flow, and user workflows
 * in the warehouse dashboard feature.
 *
 * Requirements: 1-12
 */

import { describe, it, expect, beforeEach, vi } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { WarehouseDashboardPage } from "@/pages/WarehouseDashboardPage";
import { warehouseService } from "@/services/warehouse";

// Mock the warehouse service
vi.mock("@/services/warehouse");

// Mock data
const mockDashboardData = {
  warehouses: [
    {
      warehouse_name: "АДЫГЕЙСК_РФЦ",
      cluster: "Юг",
      items: [
        {
          product_id: 1,
          sku: "TEST-001",
          name: "Test Product 1",
          warehouse_name: "АДЫГЕЙСК_РФЦ",
          cluster: "Юг",
          available: 100,
          reserved: 10,
          preparing_for_sale: 5,
          in_supply_requests: 0,
          in_transit: 20,
          in_inspection: 0,
          returning_from_customers: 0,
          expiring_soon: 0,
          defective: 0,
          excess_from_supply: 0,
          awaiting_upd: 0,
          preparing_for_removal: 0,
          daily_sales_avg: 5.5,
          sales_last_28_days: 154,
          days_without_sales: 0,
          days_of_stock: 18.2,
          liquidity_status: "normal" as const,
          target_stock: 165,
          replenishment_need: 45,
          last_updated: "2025-10-22T12:00:00Z",
        },
        {
          product_id: 2,
          sku: "TEST-002",
          name: "Test Product 2",
          warehouse_name: "АДЫГЕЙСК_РФЦ",
          cluster: "Юг",
          available: 5,
          reserved: 0,
          preparing_for_sale: 0,
          in_supply_requests: 0,
          in_transit: 0,
          in_inspection: 0,
          returning_from_customers: 0,
          expiring_soon: 0,
          defective: 0,
          excess_from_supply: 0,
          awaiting_upd: 0,
          preparing_for_removal: 0,
          daily_sales_avg: 2.0,
          sales_last_28_days: 56,
          days_without_sales: 0,
          days_of_stock: 2.5,
          liquidity_status: "critical" as const,
          target_stock: 60,
          replenishment_need: 55,
          last_updated: "2025-10-22T12:00:00Z",
        },
      ],
      totals: {
        total_items: 2,
        total_available: 105,
        total_replenishment_need: 100,
      },
    },
    {
      warehouse_name: "Ростов-на-Дону_РФЦ",
      cluster: "Юг",
      items: [
        {
          product_id: 3,
          sku: "TEST-003",
          name: "Test Product 3",
          warehouse_name: "Ростов-на-Дону_РФЦ",
          cluster: "Юг",
          available: 200,
          reserved: 20,
          preparing_for_sale: 10,
          in_supply_requests: 0,
          in_transit: 50,
          in_inspection: 0,
          returning_from_customers: 0,
          expiring_soon: 0,
          defective: 0,
          excess_from_supply: 0,
          awaiting_upd: 0,
          preparing_for_removal: 0,
          daily_sales_avg: 3.0,
          sales_last_28_days: 84,
          days_without_sales: 0,
          days_of_stock: 66.7,
          liquidity_status: "excess" as const,
          target_stock: 90,
          replenishment_need: 0,
          last_updated: "2025-10-22T12:00:00Z",
        },
      ],
      totals: {
        total_items: 1,
        total_available: 200,
        total_replenishment_need: 0,
      },
    },
  ],
  summary: {
    total_products: 3,
    active_products: 3,
    total_replenishment_need: 100,
    by_liquidity: {
      critical: 1,
      low: 0,
      normal: 1,
      excess: 1,
    },
  },
  filters_applied: {
    warehouse: undefined,
    cluster: undefined,
    liquidity_status: undefined,
    active_only: true,
    has_replenishment_need: undefined,
  },
  pagination: {
    limit: 100,
    offset: 0,
    total: 3,
    current_page: 1,
    total_pages: 1,
    has_next: false,
    has_prev: false,
  },
  last_updated: "2025-10-22T12:00:00Z",
};

const mockWarehouses = [
  { warehouse_name: "АДЫГЕЙСК_РФЦ", cluster: "Юг", product_count: 50 },
  { warehouse_name: "Ростов-на-Дону_РФЦ", cluster: "Юг", product_count: 30 },
];

const mockClusters = [
  { cluster: "Юг", warehouse_count: 2, product_count: 80 },
  { cluster: "Урал", warehouse_count: 3, product_count: 120 },
];

// Helper to render with providers
const renderWithProviders = (component: React.ReactElement) => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        retry: false,
      },
    },
  });

  return render(
    <QueryClientProvider client={queryClient}>{component}</QueryClientProvider>
  );
};

describe("Warehouse Dashboard Integration Tests", () => {
  beforeEach(() => {
    vi.clearAllMocks();

    // Setup default mocks
    vi.mocked(warehouseService.getDashboardData).mockResolvedValue(
      mockDashboardData
    );
    vi.mocked(warehouseService.getWarehouses).mockResolvedValue(mockWarehouses);
    vi.mocked(warehouseService.getClusters).mockResolvedValue(mockClusters);
  });

  /**
   * Test initial page load and data display
   * Requirements: 1, 2, 11
   */
  it("should load and display dashboard data on initial render", async () => {
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for data to load
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalled();
    });

    // Check summary statistics are displayed
    expect(screen.getByText(/3/)).toBeInTheDocument(); // total products
    expect(screen.getByText(/100/)).toBeInTheDocument(); // replenishment need

    // Check warehouse groups are displayed
    expect(screen.getByText("АДЫГЕЙСК_РФЦ")).toBeInTheDocument();
    expect(screen.getByText("Ростов-на-Дону_РФЦ")).toBeInTheDocument();

    // Check products are displayed
    expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    expect(screen.getByText("Test Product 2")).toBeInTheDocument();
    expect(screen.getByText("Test Product 3")).toBeInTheDocument();
  });

  /**
   * Test warehouse filter functionality
   * Requirements: 2, 9
   */
  it("should filter data by warehouse", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Find and click warehouse filter
    const warehouseSelect = screen.getByLabelText(/склад/i);
    await user.click(warehouseSelect);

    // Select a warehouse
    const option = screen.getByText("АДЫГЕЙСК_РФЦ");
    await user.click(option);

    // Verify API was called with filter
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(
        expect.objectContaining({
          warehouse: "АДЫГЕЙСК_РФЦ",
        })
      );
    });
  });

  /**
   * Test cluster filter functionality
   * Requirements: 2, 9
   */
  it("should filter data by cluster", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Find and click cluster filter
    const clusterSelect = screen.getByLabelText(/кластер/i);
    await user.click(clusterSelect);

    // Select a cluster
    const option = screen.getByText("Юг");
    await user.click(option);

    // Verify API was called with filter
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(
        expect.objectContaining({
          cluster: "Юг",
        })
      );
    });
  });

  /**
   * Test liquidity status filter
   * Requirements: 5, 7, 9
   */
  it("should filter data by liquidity status", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Find and click liquidity status filter
    const statusSelect = screen.getByLabelText(/статус ликвидности/i);
    await user.click(statusSelect);

    // Select critical status
    const option = screen.getByText(/дефицит/i);
    await user.click(option);

    // Verify API was called with filter
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(
        expect.objectContaining({
          liquidity_status: "critical",
        })
      );
    });
  });

  /**
   * Test active only filter
   * Requirements: 1, 9
   */
  it("should toggle active only filter", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Find and toggle active only checkbox
    const activeOnlyCheckbox = screen.getByLabelText(/только активные товары/i);
    await user.click(activeOnlyCheckbox);

    // Verify API was called with updated filter
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(
        expect.objectContaining({
          active_only: false,
        })
      );
    });
  });

  /**
   * Test replenishment need filter
   * Requirements: 3, 9
   */
  it("should filter by replenishment need", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Find and toggle replenishment need checkbox
    const replenishmentCheckbox = screen.getByLabelText(
      /только с потребностью в пополнении/i
    );
    await user.click(replenishmentCheckbox);

    // Verify API was called with filter
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(
        expect.objectContaining({
          has_replenishment_need: true,
        })
      );
    });
  });

  /**
   * Test sorting functionality
   * Requirements: 9
   */
  it("should sort data by clicking column headers", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Find and click on "Продажи/день" column header
    const salesHeader = screen.getByText(/продажи\/день/i);
    await user.click(salesHeader);

    // Verify API was called with sort parameters
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(
        expect.objectContaining({
          sort_by: "daily_sales_avg",
          sort_order: "desc",
        })
      );
    });

    // Click again to reverse sort order
    await user.click(salesHeader);

    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(
        expect.objectContaining({
          sort_by: "daily_sales_avg",
          sort_order: "asc",
        })
      );
    });
  });

  /**
   * Test liquidity badge display
   * Requirements: 5, 7
   */
  it("should display liquidity badges with correct colors", async () => {
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for data to load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Check for liquidity status badges
    const normalBadge = screen.getByText(/норма/i);
    expect(normalBadge).toBeInTheDocument();
    expect(normalBadge).toHaveClass("bg-green-100");

    const criticalBadge = screen.getByText(/дефицит/i);
    expect(criticalBadge).toBeInTheDocument();
    expect(criticalBadge).toHaveClass("bg-red-100");

    const excessBadge = screen.getByText(/избыток/i);
    expect(excessBadge).toBeInTheDocument();
    expect(excessBadge).toHaveClass("bg-blue-100");
  });

  /**
   * Test replenishment indicator display
   * Requirements: 3
   */
  it("should display replenishment indicators correctly", async () => {
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for data to load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Check for replenishment need values
    expect(screen.getByText(/45/)).toBeInTheDocument(); // Product 1
    expect(screen.getByText(/55/)).toBeInTheDocument(); // Product 2 (urgent)

    // Product 3 should show no replenishment need
    const product3Row = screen.getByText("Test Product 3").closest("tr");
    expect(product3Row).toBeInTheDocument();
  });

  /**
   * Test metrics tooltip display
   * Requirements: 6
   */
  it("should show metrics tooltip on hover", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for data to load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Find metrics icon and hover
    const metricsIcon = screen.getAllByTestId("metrics-icon")[0];
    await user.hover(metricsIcon);

    // Check tooltip appears with Ozon metrics
    await waitFor(() => {
      expect(screen.getByText(/готовим к продаже/i)).toBeInTheDocument();
      expect(screen.getByText(/в пути/i)).toBeInTheDocument();
    });
  });

  /**
   * Test data refresh functionality
   * Requirements: 11
   */
  it("should refresh data when refresh button is clicked", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Clear mock calls
    vi.clearAllMocks();

    // Find and click refresh button
    const refreshButton = screen.getByRole("button", { name: /обновить/i });
    await user.click(refreshButton);

    // Verify API was called again
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalled();
    });
  });

  /**
   * Test CSV export functionality
   * Requirements: 10
   */
  it("should export data to CSV when export button is clicked", async () => {
    const user = userEvent.setup();
    const mockBlob = new Blob(["test,csv,data"], { type: "text/csv" });

    vi.mocked(warehouseService.exportToCSV).mockResolvedValue(mockBlob);
    vi.mocked(warehouseService.downloadCSV).mockImplementation(() => {});

    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Find and click export button
    const exportButton = screen.getByRole("button", { name: /экспорт/i });
    await user.click(exportButton);

    // Verify export was called
    await waitFor(() => {
      expect(warehouseService.exportToCSV).toHaveBeenCalled();
      expect(warehouseService.downloadCSV).toHaveBeenCalledWith(
        mockBlob,
        expect.any(String)
      );
    });
  });

  /**
   * Test pagination functionality
   * Requirements: 12
   */
  it("should handle pagination correctly", async () => {
    const user = userEvent.setup();

    // Mock data with pagination
    const paginatedData = {
      ...mockDashboardData,
      pagination: {
        limit: 2,
        offset: 0,
        total: 10,
        current_page: 1,
        total_pages: 5,
        has_next: true,
        has_prev: false,
      },
    };

    vi.mocked(warehouseService.getDashboardData).mockResolvedValue(
      paginatedData
    );

    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Check pagination controls are displayed
    expect(screen.getByText(/страница 1 из 5/i)).toBeInTheDocument();

    // Find and click next page button
    const nextButton = screen.getByRole("button", { name: /следующая/i });
    expect(nextButton).not.toBeDisabled();

    await user.click(nextButton);

    // Verify API was called with new offset
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(
        expect.objectContaining({
          offset: 2,
        })
      );
    });
  });

  /**
   * Test error handling
   * Requirements: Error Handling
   */
  it("should display error message when API fails", async () => {
    vi.mocked(warehouseService.getDashboardData).mockRejectedValue(
      new Error("Failed to fetch data")
    );

    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for error message
    await waitFor(() => {
      expect(
        screen.getByText(/не удалось загрузить данные/i)
      ).toBeInTheDocument();
    });

    // Check retry button is available
    const retryButton = screen.getByRole("button", { name: /повторить/i });
    expect(retryButton).toBeInTheDocument();
  });

  /**
   * Test loading state
   * Requirements: 11
   */
  it("should display loading spinner while fetching data", async () => {
    // Mock slow API response
    vi.mocked(warehouseService.getDashboardData).mockImplementation(
      () =>
        new Promise((resolve) =>
          setTimeout(() => resolve(mockDashboardData), 1000)
        )
    );

    renderWithProviders(<WarehouseDashboardPage />);

    // Check loading spinner is displayed
    expect(screen.getByTestId("loading-spinner")).toBeInTheDocument();

    // Wait for data to load
    await waitFor(
      () => {
        expect(screen.getByText("Test Product 1")).toBeInTheDocument();
      },
      { timeout: 2000 }
    );

    // Loading spinner should be gone
    expect(screen.queryByTestId("loading-spinner")).not.toBeInTheDocument();
  });

  /**
   * Test multiple filters applied together
   * Requirements: 9
   */
  it("should apply multiple filters simultaneously", async () => {
    const user = userEvent.setup();
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for initial load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Apply warehouse filter
    const warehouseSelect = screen.getByLabelText(/склад/i);
    await user.click(warehouseSelect);
    await user.click(screen.getByText("АДЫГЕЙСК_РФЦ"));

    // Apply liquidity status filter
    const statusSelect = screen.getByLabelText(/статус ликвидности/i);
    await user.click(statusSelect);
    await user.click(screen.getByText(/дефицит/i));

    // Apply replenishment need filter
    const replenishmentCheckbox = screen.getByLabelText(
      /только с потребностью в пополнении/i
    );
    await user.click(replenishmentCheckbox);

    // Verify API was called with all filters
    await waitFor(() => {
      expect(warehouseService.getDashboardData).toHaveBeenCalledWith(
        expect.objectContaining({
          warehouse: "АДЫГЕЙСК_РФЦ",
          liquidity_status: "critical",
          has_replenishment_need: true,
        })
      );
    });
  });

  /**
   * Test warehouse grouping display
   * Requirements: 2
   */
  it("should display warehouse groups with totals", async () => {
    renderWithProviders(<WarehouseDashboardPage />);

    // Wait for data to load
    await waitFor(() => {
      expect(screen.getByText("Test Product 1")).toBeInTheDocument();
    });

    // Check warehouse group headers
    const warehouse1Header = screen.getByText("АДЫГЕЙСК_РФЦ");
    expect(warehouse1Header).toBeInTheDocument();

    // Check warehouse totals are displayed
    const warehouse1Section = warehouse1Header.closest("section");
    expect(warehouse1Section).toBeInTheDocument();

    if (warehouse1Section) {
      const totalsText = within(warehouse1Section).getByText(/итого/i);
      expect(totalsText).toBeInTheDocument();
    }
  });
});
