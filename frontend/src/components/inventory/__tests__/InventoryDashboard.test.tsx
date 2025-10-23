import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { InventoryDashboard } from "../InventoryDashboard";
import * as inventoryHook from "../../../hooks/useInventory";

// Mock the useInventory hook
vi.mock("../../../hooks/useInventory");

const createWrapper = () => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });

  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
};

describe("InventoryDashboard", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("displays loading spinner while data is loading", () => {
    vi.spyOn(inventoryHook, "useInventory").mockReturnValue({
      data: undefined,
      isLoading: true,
      error: null,
      refetch: vi.fn(),
      isError: false,
      isSuccess: false,
    } as any);

    render(<InventoryDashboard />, { wrapper: createWrapper() });

    expect(screen.getByText("Загрузка данных...")).toBeInTheDocument();
  });

  it("displays error message when data fetch fails", () => {
    const error = new Error("API Error");
    vi.spyOn(inventoryHook, "useInventory").mockReturnValue({
      data: undefined,
      isLoading: false,
      error,
      refetch: vi.fn(),
      isError: true,
      isSuccess: false,
    } as any);

    render(<InventoryDashboard />, { wrapper: createWrapper() });

    expect(screen.getByText("Ошибка загрузки данных")).toBeInTheDocument();
    expect(screen.getByText("API Error")).toBeInTheDocument();
  });

  it("displays dashboard data after successful load", async () => {
    const mockData = {
      critical_products: { count: 32, items: [] },
      low_stock_products: { count: 53, items: [] },
      overstock_products: { count: 28, items: [] },
      last_updated: "2025-10-21 10:00:00",
      total_products: 113,
    };

    vi.spyOn(inventoryHook, "useInventory").mockReturnValue({
      data: mockData,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
      isError: false,
      isSuccess: true,
    } as any);

    render(<InventoryDashboard />, { wrapper: createWrapper() });

    await waitFor(() => {
      expect(screen.getByText("🚨 Критический остаток")).toBeInTheDocument();
      expect(screen.getByText("⚠️ Низкий остаток")).toBeInTheDocument();
      expect(screen.getByText("📈 Избыток товара")).toBeInTheDocument();
    });
  });

  it("displays no data message when data is null", () => {
    vi.spyOn(inventoryHook, "useInventory").mockReturnValue({
      data: null,
      isLoading: false,
      error: null,
      refetch: vi.fn(),
      isError: false,
      isSuccess: true,
    } as any);

    render(<InventoryDashboard />, { wrapper: createWrapper() });

    expect(screen.getByText("Нет данных для отображения")).toBeInTheDocument();
  });
});
