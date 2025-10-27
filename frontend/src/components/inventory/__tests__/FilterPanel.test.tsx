/**
 * Tests for FilterPanel component
 *
 * Requirements: 3.1, 3.2, 3.3, 3.4, 3.5
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "../../../test/testUtils";
import { FilterPanel } from "../FilterPanel";
import { createMockProps, mockFilters } from "../../../test/mockData";
import { simulateTypingWithDebounce } from "../../../test/testUtils";

describe("FilterPanel", () => {
  const mockOnFilterChange = vi.fn();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("Basic Rendering", () => {
    it("renders filter panel with all sections", () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(screen.getByText("Filters")).toBeInTheDocument();
      expect(screen.getByText("Product Search")).toBeInTheDocument();
      expect(screen.getByText("Warehouses")).toBeInTheDocument();
      expect(screen.getByText("Stock Status")).toBeInTheDocument();
      expect(screen.getByText("Advanced Filters")).toBeInTheDocument();
    });

    it("shows clear all button when filters are active", () => {
      const props = createMockProps.filterPanel({
        filters: mockFilters.withSearch,
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(screen.getByText("Clear All")).toBeInTheDocument();
    });

    it("does not show clear all button when no filters are active", () => {
      const props = createMockProps.filterPanel({
        filters: mockFilters.default,
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(screen.queryByText("Clear All")).not.toBeInTheDocument();
    });
  });

  describe("Product Search", () => {
    it("renders search input with placeholder", () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      const searchInput = screen.getByPlaceholderText(
        "Search by product name or SKU..."
      );
      expect(searchInput).toBeInTheDocument();
    });

    it("calls onFilterChange with debounced search term", async () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      const searchInput = screen.getByPlaceholderText(
        "Search by product name or SKU..."
      );

      await simulateTypingWithDebounce(searchInput, "test product");

      await waitFor(() => {
        expect(mockOnFilterChange).toHaveBeenCalledWith({
          ...mockFilters.default,
          searchTerm: "test product",
        });
      });
    });

    it("shows clear button when search term exists", async () => {
      const props = createMockProps.filterPanel({
        filters: mockFilters.withSearch,
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      const clearButton = screen.getByRole("button", { name: /clear/i });
      expect(clearButton).toBeInTheDocument();
    });

    it("clears search when clear button is clicked", async () => {
      const props = createMockProps.filterPanel({
        filters: mockFilters.withSearch,
        onFilterChange: mockOnFilterChange,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<FilterPanel {...props} />);

      const clearButton = screen.getByRole("button", { name: /clear/i });
      await user.click(clearButton);

      expect(mockOnFilterChange).toHaveBeenCalledWith({
        ...mockFilters.withSearch,
        searchTerm: "",
      });
    });

    it("shows minimum length message for short search terms", async () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      const searchInput = screen.getByPlaceholderText(
        "Search by product name or SKU..."
      );

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      await user.type(searchInput, "a");

      expect(
        screen.getByText(/Type at least 2 characters to search/)
      ).toBeInTheDocument();
    });
  });

  describe("Warehouse Filter", () => {
    it("shows warehouse count when collapsed", () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(screen.getByText("All warehouses")).toBeInTheDocument();
    });

    it("shows selected count when warehouses are selected", () => {
      const props = createMockProps.filterPanel({
        filters: mockFilters.withWarehouse,
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(screen.getByText("2 of 5 selected")).toBeInTheDocument();
    });

    it("expands warehouse list when expand button is clicked", async () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<FilterPanel {...props} />);

      const expandButton = screen.getByText("Expand");
      await user.click(expandButton);

      expect(
        screen.getByPlaceholderText("Search warehouses...")
      ).toBeInTheDocument();
      expect(screen.getByText("Select All")).toBeInTheDocument();
      expect(screen.getByText("Clear All")).toBeInTheDocument();
    });

    it("filters warehouses based on search", async () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<FilterPanel {...props} />);

      // Expand warehouse list
      const expandButton = screen.getByText("Expand");
      await user.click(expandButton);

      // Search for specific warehouse
      const warehouseSearch = screen.getByPlaceholderText(
        "Search warehouses..."
      );
      await user.type(warehouseSearch, "Alpha");

      expect(screen.getByText("Warehouse Alpha")).toBeInTheDocument();
      expect(screen.queryByText("Warehouse Beta")).not.toBeInTheDocument();
    });

    it("selects warehouse when checkbox is clicked", async () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<FilterPanel {...props} />);

      // Expand warehouse list
      const expandButton = screen.getByText("Expand");
      await user.click(expandButton);

      // Click warehouse checkbox
      const warehouseCheckbox = screen.getByRole("checkbox", {
        name: /Warehouse Alpha/,
      });
      await user.click(warehouseCheckbox);

      expect(mockOnFilterChange).toHaveBeenCalledWith({
        ...mockFilters.default,
        warehouses: ["Warehouse Alpha"],
      });
    });
  });

  describe("Status Filter", () => {
    it("renders all status options", () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(screen.getByText("Critical")).toBeInTheDocument();
      expect(screen.getByText("Low Stock")).toBeInTheDocument();
      expect(screen.getByText("Normal")).toBeInTheDocument();
      expect(screen.getByText("Excess")).toBeInTheDocument();
      expect(screen.getByText("No Sales")).toBeInTheDocument();
    });

    it("shows status descriptions", () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(screen.getByText("< 14 days")).toBeInTheDocument();
      expect(screen.getByText("14-30 days")).toBeInTheDocument();
      expect(screen.getByText("30-60 days")).toBeInTheDocument();
      expect(screen.getByText("> 60 days")).toBeInTheDocument();
      expect(screen.getByText("No recent sales")).toBeInTheDocument();
    });

    it("selects status when checkbox is clicked", async () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<FilterPanel {...props} />);

      const criticalCheckbox = screen.getByRole("checkbox", {
        name: /Critical/,
      });
      await user.click(criticalCheckbox);

      expect(mockOnFilterChange).toHaveBeenCalledWith({
        ...mockFilters.default,
        statuses: ["critical"],
      });
    });

    it("shows clear all button when statuses are selected", () => {
      const props = createMockProps.filterPanel({
        filters: mockFilters.withStatus,
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      const clearButtons = screen.getAllByText("Clear All");
      expect(clearButtons.length).toBeGreaterThan(0);
    });
  });

  describe("Advanced Filters", () => {
    it("expands advanced filters when clicked", async () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<FilterPanel {...props} />);

      const advancedButton = screen.getByText("Advanced Filters");
      await user.click(advancedButton);

      expect(screen.getByText("Show only urgent items")).toBeInTheDocument();
      expect(screen.getByText("Days of Stock Range")).toBeInTheDocument();
    });

    it("toggles urgent items filter", async () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<FilterPanel {...props} />);

      // Expand advanced filters
      const advancedButton = screen.getByText("Advanced Filters");
      await user.click(advancedButton);

      // Toggle urgent items
      const urgentCheckbox = screen.getByRole("checkbox", {
        name: /Show only urgent items/,
      });
      await user.click(urgentCheckbox);

      expect(mockOnFilterChange).toHaveBeenCalledWith({
        ...mockFilters.default,
        showOnlyUrgent: true,
      });
    });

    it("updates days of stock range", async () => {
      const props = createMockProps.filterPanel({
        onFilterChange: mockOnFilterChange,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<FilterPanel {...props} />);

      // Expand advanced filters
      const advancedButton = screen.getByText("Advanced Filters");
      await user.click(advancedButton);

      // Update min days
      const minDaysInput = screen.getByPlaceholderText("Min days");
      await user.type(minDaysInput, "10");

      expect(mockOnFilterChange).toHaveBeenCalledWith({
        ...mockFilters.default,
        minDaysOfStock: 10,
      });
    });
  });

  describe("Active Filters Summary", () => {
    it("shows active filters summary when filters are applied", () => {
      const props = createMockProps.filterPanel({
        filters: {
          ...mockFilters.default,
          warehouses: ["Warehouse Alpha"],
          statuses: ["critical", "low"],
          searchTerm: "test",
          showOnlyUrgent: true,
        },
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(screen.getByText("Active Filters:")).toBeInTheDocument();
      expect(screen.getByText("Warehouses: 1 selected")).toBeInTheDocument();
      expect(screen.getByText("Status: critical, low")).toBeInTheDocument();
      expect(screen.getByText('Search: "test"')).toBeInTheDocument();
      expect(screen.getByText("Urgent items only")).toBeInTheDocument();
    });

    it("does not show summary when no filters are active", () => {
      const props = createMockProps.filterPanel({
        filters: mockFilters.default,
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(screen.queryByText("Active Filters:")).not.toBeInTheDocument();
    });
  });

  describe("Clear All Functionality", () => {
    it("clears all filters when clear all is clicked", async () => {
      const props = createMockProps.filterPanel({
        filters: mockFilters.withSearch,
        onFilterChange: mockOnFilterChange,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<FilterPanel {...props} />);

      const clearAllButton = screen.getByText("Clear All");
      await user.click(clearAllButton);

      expect(mockOnFilterChange).toHaveBeenCalledWith(mockFilters.default);
    });
  });

  describe("Loading State", () => {
    it("shows loading spinner for warehouses when loading", () => {
      const props = createMockProps.filterPanel({
        loading: true,
        onFilterChange: mockOnFilterChange,
      });

      render(<FilterPanel {...props} />);

      expect(
        screen.getByRole("status", { name: "Loading" })
      ).toBeInTheDocument();
    });
  });
});
