/**
 * Responsive Design Tests for Warehouse Dashboard
 * Tests mobile (375px-767px), tablet (768px-1023px), and desktop (1024px+) layouts
 */

import { describe, it, expect, beforeEach } from "vitest";
import { render } from "@testing-library/react";
import { BrowserRouter } from "react-router-dom";
import { DashboardHeader } from "../components/warehouse/DashboardHeader";
import { WarehouseFilters } from "../components/warehouse/WarehouseFilters";
import { WarehouseTable } from "../components/warehouse/WarehouseTable";
import type {
  DashboardSummary,
  FilterState,
  WarehouseGroup,
} from "../types/warehouse";

// Mock data
const mockSummary: DashboardSummary = {
  total_products: 1234,
  active_products: 987,
  by_liquidity: {
    critical: 45,
    low: 123,
    normal: 654,
    excess: 165,
  },
  total_replenishment_need: 5678,
};

const mockFilters: FilterState = {
  warehouse: undefined,
  cluster: undefined,
  liquidity_status: undefined,
  active_only: true,
  has_replenishment_need: undefined,
};

const mockWarehouseData: WarehouseGroup[] = [
  {
    warehouse_name: "Склад Москва",
    cluster: "Центр",
    totals: {
      total_items: 10,
      total_available: 500,
      total_replenishment_need: 100,
    },
    items: [
      {
        product_id: 1,
        sku: "SKU-001",
        name: "Тестовый товар 1",
        warehouse_name: "Склад Москва",
        cluster: "Центр",
        available: 50,
        reserved: 10,
        preparing_for_sale: 0,
        in_supply_requests: 0,
        in_transit: 5,
        in_inspection: 0,
        returning_from_customers: 0,
        expiring_soon: 0,
        defective: 0,
        excess_from_supply: 0,
        awaiting_upd: 0,
        preparing_for_removal: 0,
        daily_sales_avg: 2.5,
        sales_last_28_days: 70,
        days_without_sales: 0,
        days_of_stock: 20,
        replenishment_need: 10,
        target_stock: 60,
        liquidity_status: "normal",
        last_updated: "2024-01-01T12:00:00Z",
      },
    ],
  },
];

// Helper to set viewport size
const setViewport = (width: number, height: number = 800) => {
  Object.defineProperty(window, "innerWidth", {
    writable: true,
    configurable: true,
    value: width,
  });
  Object.defineProperty(window, "innerHeight", {
    writable: true,
    configurable: true,
    value: height,
  });
  window.dispatchEvent(new Event("resize"));
};

describe("Responsive Design Tests", () => {
  describe("Task 7.1: Mobile Layout (375px - 767px)", () => {
    beforeEach(() => {
      setViewport(375);
    });

    it("should render DashboardHeader metrics in single column on mobile", () => {
      const { container } = render(
        <DashboardHeader
          summary={mockSummary}
          lastUpdated="2024-01-01T12:00:00Z"
          onRefresh={() => {}}
          onExport={() => {}}
        />
      );

      // Check that the grid container has mobile-first single column class
      const gridContainer = container.querySelector(".grid");
      expect(gridContainer).toBeTruthy();
      expect(gridContainer?.className).toContain("grid-cols-1");
      expect(gridContainer?.className).toContain("md:grid-cols-2");
      expect(gridContainer?.className).toContain("lg:grid-cols-4");
    });

    it("should render WarehouseFilters in single column on mobile", () => {
      const { container } = render(
        <WarehouseFilters
          filters={mockFilters}
          onChange={() => {}}
          warehouses={[]}
          clusters={[]}
        />
      );

      // Check that the grid container has mobile-first single column class
      const gridContainer = container.querySelector(".grid");
      expect(gridContainer).toBeTruthy();
      expect(gridContainer?.className).toContain("grid-cols-1");
      expect(gridContainer?.className).toContain("md:grid-cols-2");
      expect(gridContainer?.className).toContain("lg:grid-cols-4");
    });

    it("should have horizontal scroll enabled for table on mobile", () => {
      const { container } = render(
        <BrowserRouter>
          <WarehouseTable
            data={mockWarehouseData}
            sortConfig={{ field: "name", order: "asc" }}
            onSort={() => {}}
          />
        </BrowserRouter>
      );

      // Check for overflow-x-auto class on table wrapper
      const tableWrapper = container.querySelector(".overflow-x-auto");
      expect(tableWrapper).toBeTruthy();

      // Check that table has min-w-full to enable scrolling
      const table = container.querySelector("table");
      expect(table?.className).toContain("min-w-full");
    });

    it("should have proper spacing and padding on mobile", () => {
      const { container } = render(
        <WarehouseFilters
          filters={mockFilters}
          onChange={() => {}}
          warehouses={[]}
          clusters={[]}
        />
      );

      // Check card padding
      const card = container.querySelector(".bg-white.rounded-lg.shadow");
      expect(card?.className).toContain("p-6");

      // Check grid gap
      const grid = container.querySelector(".grid");
      expect(grid?.className).toContain("gap-4");
    });
  });

  describe("Task 7.2: Tablet Layout (768px - 1023px)", () => {
    beforeEach(() => {
      setViewport(768);
    });

    it("should render DashboardHeader metrics in 2-column layout on tablet", () => {
      const { container } = render(
        <DashboardHeader
          summary={mockSummary}
          lastUpdated="2024-01-01T12:00:00Z"
          onRefresh={() => {}}
          onExport={() => {}}
        />
      );

      // At 768px, md:grid-cols-2 should apply
      const gridContainer = container.querySelector(".grid");
      expect(gridContainer).toBeTruthy();
      expect(gridContainer?.className).toContain("md:grid-cols-2");
    });

    it("should render WarehouseFilters in 2-column layout on tablet", () => {
      const { container } = render(
        <WarehouseFilters
          filters={mockFilters}
          onChange={() => {}}
          warehouses={[]}
          clusters={[]}
        />
      );

      // At 768px, md:grid-cols-2 should apply
      const gridContainer = container.querySelector(".grid");
      expect(gridContainer).toBeTruthy();
      expect(gridContainer?.className).toContain("md:grid-cols-2");
    });

    it("should maintain proper spacing on tablet", () => {
      const { container } = render(
        <DashboardHeader
          summary={mockSummary}
          lastUpdated="2024-01-01T12:00:00Z"
          onRefresh={() => {}}
          onExport={() => {}}
        />
      );

      // Check consistent gap
      const grid = container.querySelector(".grid");
      expect(grid?.className).toContain("gap-4");

      // Check margin bottom
      expect(grid?.className).toContain("mb-6");
    });
  });

  describe("Task 7.3: Desktop Layout (1024px+)", () => {
    beforeEach(() => {
      setViewport(1024);
    });

    it("should render DashboardHeader metrics in 4-column layout on desktop", () => {
      const { container } = render(
        <DashboardHeader
          summary={mockSummary}
          lastUpdated="2024-01-01T12:00:00Z"
          onRefresh={() => {}}
          onExport={() => {}}
        />
      );

      // At 1024px, lg:grid-cols-4 should apply
      const gridContainer = container.querySelector(".grid");
      expect(gridContainer).toBeTruthy();
      expect(gridContainer?.className).toContain("lg:grid-cols-4");
    });

    it("should render WarehouseFilters in 4-column layout on desktop", () => {
      const { container } = render(
        <WarehouseFilters
          filters={mockFilters}
          onChange={() => {}}
          warehouses={[]}
          clusters={[]}
        />
      );

      // At 1024px, lg:grid-cols-4 should apply
      const gridContainer = container.querySelector(".grid");
      expect(gridContainer).toBeTruthy();
      expect(gridContainer?.className).toContain("lg:grid-cols-4");
    });

    it("should display all table columns on desktop", () => {
      const { container } = render(
        <BrowserRouter>
          <WarehouseTable
            data={mockWarehouseData}
            sortConfig={{ field: "name", order: "asc" }}
            onSort={() => {}}
          />
        </BrowserRouter>
      );

      // Check that all 7 columns are present
      const headers = container.querySelectorAll("thead th");
      expect(headers.length).toBe(7);
    });

    it("should work on large screens (1920px+)", () => {
      setViewport(1920);

      const { container } = render(
        <DashboardHeader
          summary={mockSummary}
          lastUpdated="2024-01-01T12:00:00Z"
          onRefresh={() => {}}
          onExport={() => {}}
        />
      );

      // Should still use 4-column layout
      const gridContainer = container.querySelector(".grid");
      expect(gridContainer?.className).toContain("lg:grid-cols-4");

      // Content should be constrained by max-w-7xl
      const card = container.querySelector(".bg-white.rounded-lg");
      expect(card).toBeTruthy();
    });
  });

  describe("Responsive Grid Classes Verification", () => {
    it("should have correct responsive grid classes on DashboardHeader", () => {
      const { container } = render(
        <DashboardHeader
          summary={mockSummary}
          lastUpdated="2024-01-01T12:00:00Z"
          onRefresh={() => {}}
          onExport={() => {}}
        />
      );

      const grid = container.querySelector(".grid");
      const classes = grid?.className || "";

      // Verify all breakpoint classes are present
      expect(classes).toContain("grid-cols-1"); // Mobile
      expect(classes).toContain("md:grid-cols-2"); // Tablet
      expect(classes).toContain("lg:grid-cols-4"); // Desktop
      expect(classes).toContain("gap-4"); // Consistent gap
    });

    it("should have correct responsive grid classes on WarehouseFilters", () => {
      const { container } = render(
        <WarehouseFilters
          filters={mockFilters}
          onChange={() => {}}
          warehouses={[]}
          clusters={[]}
        />
      );

      const grid = container.querySelector(".grid");
      const classes = grid?.className || "";

      // Verify all breakpoint classes are present
      expect(classes).toContain("grid-cols-1"); // Mobile
      expect(classes).toContain("md:grid-cols-2"); // Tablet
      expect(classes).toContain("lg:grid-cols-4"); // Desktop
      expect(classes).toContain("gap-4"); // Consistent gap
    });
  });

  describe("Table Scrolling Behavior", () => {
    it("should have overflow-x-auto for horizontal scrolling", () => {
      const { container } = render(
        <BrowserRouter>
          <WarehouseTable
            data={mockWarehouseData}
            sortConfig={{ field: "name", order: "asc" }}
            onSort={() => {}}
          />
        </BrowserRouter>
      );

      const scrollContainer = container.querySelector(".overflow-x-auto");
      expect(scrollContainer).toBeTruthy();
    });

    it("should have min-w-full on table for proper width", () => {
      const { container } = render(
        <BrowserRouter>
          <WarehouseTable
            data={mockWarehouseData}
            sortConfig={{ field: "name", order: "asc" }}
            onSort={() => {}}
          />
        </BrowserRouter>
      );

      const table = container.querySelector("table");
      expect(table?.className).toContain("min-w-full");
    });
  });
});
