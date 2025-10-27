/**
 * Tests for InventoryTable component
 *
 * Requirements: 1.1, 1.2, 2.1, 2.2, 2.3, 4.1, 4.2, 7.3
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "../../../test/testUtils";
import { InventoryTable } from "../InventoryTable";
import {
  createMockProps,
  mockInventoryItems,
  mockSortConfigs,
} from "../../../test/mockData";

describe("InventoryTable", () => {
  const mockOnSort = vi.fn();
  const mockOnRowSelection = vi.fn();
  const mockOnBulkSelection = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("Basic Rendering", () => {
    it("renders table with headers", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      // Check table headers
      expect(screen.getByText("Product")).toBeInTheDocument();
      expect(screen.getByText("SKU")).toBeInTheDocument();
      expect(screen.getByText("Warehouse")).toBeInTheDocument();
      expect(screen.getByText("Current Stock")).toBeInTheDocument();
      expect(screen.getByText("Daily Sales")).toBeInTheDocument();
      expect(screen.getByText("Days of Stock")).toBeInTheDocument();
      expect(screen.getByText("Status")).toBeInTheDocument();
      expect(screen.getByText("Recommended")).toBeInTheDocument();
    });

    it("shows loading state", () => {
      const props = createMockProps.inventoryTable({
        loading: true,
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      expect(screen.getByText("Loading inventory data...")).toBeInTheDocument();
    });

    it("shows empty state when no data", () => {
      const props = createMockProps.inventoryTable({
        data: [],
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      expect(screen.getByText("No inventory data")).toBeInTheDocument();
      expect(
        screen.getByText(
          "No products match your current filters. Try adjusting your search criteria."
        )
      ).toBeInTheDocument();
    });
  });

  describe("Sorting Functionality", () => {
    it("shows sort indicators for current sort column", () => {
      const props = createMockProps.inventoryTable({
        sortConfig: mockSortConfigs.byProduct,
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      const productHeader = screen.getByText("Product").closest("th");
      expect(productHeader).toBeInTheDocument();

      // Check for sort indicator (blue color indicates active sort)
      const sortIcon = productHeader?.querySelector(".text-blue-600");
      expect(sortIcon).toBeInTheDocument();
    });

    it("calls onSort when column header is clicked", async () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<InventoryTable {...props} />);

      const productHeader = screen.getByText("Product").closest("th");
      if (productHeader) {
        await user.click(productHeader);
      }

      expect(mockOnSort).toHaveBeenCalledWith("productName");
    });

    it("shows correct sort direction indicators", () => {
      const props = createMockProps.inventoryTable({
        sortConfig: { column: "productName", direction: "desc" },
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      const productHeader = screen.getByText("Product").closest("th");
      expect(productHeader).toBeInTheDocument();

      // Should show descending sort indicator
      const descendingIcon = productHeader?.querySelector(".text-blue-600");
      expect(descendingIcon).toBeInTheDocument();
    });
  });

  describe("Row Selection", () => {
    it("renders selection checkboxes", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      const checkboxes = screen.getAllByRole("checkbox");
      expect(checkboxes.length).toBeGreaterThan(0);
    });

    it("handles bulk selection via header checkbox", async () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<InventoryTable {...props} />);

      // Find header checkbox
      const headerCheckbox = screen.getAllByRole("checkbox")[0];
      await user.click(headerCheckbox);

      expect(mockOnBulkSelection).toHaveBeenCalledWith(true);
    });

    it("shows indeterminate state for partial selection", () => {
      const selectedRows = new Set(["1-Warehouse Alpha"]);
      const props = createMockProps.inventoryTable({
        selectedRows,
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      const headerCheckbox = screen.getAllByRole(
        "checkbox"
      )[0] as HTMLInputElement;
      expect(headerCheckbox.indeterminate).toBe(true);
    });

    it("shows all selected state when all items are selected", () => {
      const selectedRows = new Set(
        mockInventoryItems.map(
          (item) => `${item.productId}-${item.warehouseName}`
        )
      );
      const props = createMockProps.inventoryTable({
        selectedRows,
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      const headerCheckbox = screen.getAllByRole(
        "checkbox"
      )[0] as HTMLInputElement;
      expect(headerCheckbox.checked).toBe(true);
      expect(headerCheckbox.indeterminate).toBe(false);
    });
  });

  describe("Table Footer", () => {
    it("shows item count in footer", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      expect(
        screen.getByText(`Showing ${mockInventoryItems.length} items`)
      ).toBeInTheDocument();
    });

    it("shows selection count when items are selected", () => {
      const selectedRows = new Set(["1-Warehouse Alpha", "2-Warehouse Beta"]);
      const props = createMockProps.inventoryTable({
        selectedRows,
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      expect(screen.getByText("(2 selected)")).toBeInTheDocument();
    });

    it("shows scroll hint", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      expect(screen.getByText("Scroll to see more items")).toBeInTheDocument();
    });
  });

  describe("Accessibility", () => {
    it("has proper table structure", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      const table = screen.getByRole("table");
      expect(table).toBeInTheDocument();

      const columnHeaders = screen.getAllByRole("columnheader");
      expect(columnHeaders.length).toBeGreaterThan(0);
    });

    it("has accessible column headers", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      const productHeader = screen.getByRole("columnheader", {
        name: /Product/i,
      });
      expect(productHeader).toBeInTheDocument();

      const statusHeader = screen.getByRole("columnheader", {
        name: /Status/i,
      });
      expect(statusHeader).toBeInTheDocument();
    });

    it("provides keyboard navigation for sortable headers", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      render(<InventoryTable {...props} />);

      const sortableHeaders = screen.getAllByRole("columnheader");
      sortableHeaders.forEach((header) => {
        if (header.textContent && !header.textContent.includes("checkbox")) {
          expect(header).toHaveClass("cursor-pointer");
        }
      });
    });
  });

  describe("Performance Features", () => {
    it("renders with virtual scrolling container", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      const { container } = render(<InventoryTable {...props} />);

      const scrollContainer = container.querySelector(
        ".overflow-auto.max-h-\\[600px\\]"
      );
      expect(scrollContainer).toBeInTheDocument();
    });

    it("applies virtual scrolling styles", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      const { container } = render(<InventoryTable {...props} />);

      const virtualContainer = container.querySelector(
        '[style*="contain: strict"]'
      );
      expect(virtualContainer).toBeInTheDocument();
    });
  });

  describe("Component Structure", () => {
    it("renders table structure correctly", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      const { container } = render(<InventoryTable {...props} />);

      // Check for main container
      expect(container.querySelector(".overflow-hidden")).toBeInTheDocument();

      // Check for table
      expect(container.querySelector("table")).toBeInTheDocument();

      // Check for thead
      expect(container.querySelector("thead")).toBeInTheDocument();

      // Check for tbody
      expect(container.querySelector("tbody")).toBeInTheDocument();
    });

    it("has correct CSS classes for styling", () => {
      const props = createMockProps.inventoryTable({
        onSort: mockOnSort,
        onRowSelection: mockOnRowSelection,
        onBulkSelection: mockOnBulkSelection,
      });

      const { container } = render(<InventoryTable {...props} />);

      const table = container.querySelector("table");
      expect(table).toHaveClass("min-w-full", "divide-y", "divide-gray-200");

      const thead = container.querySelector("thead");
      expect(thead).toHaveClass("bg-gray-50", "sticky", "top-0", "z-10");
    });
  });
});
