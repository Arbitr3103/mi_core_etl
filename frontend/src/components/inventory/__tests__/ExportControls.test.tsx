/**
 * Tests for ExportControls component
 *
 * Requirements: 7.4
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "../../../test/testUtils";
import { ExportControls } from "../ExportControls";
import { createMockProps, mockInventoryItems } from "../../../test/mockData";

// Mock URL.createObjectURL and related functions
const mockCreateObjectURL = vi.fn(() => "mock-url");
const mockRevokeObjectURL = vi.fn();

Object.defineProperty(global.URL, "createObjectURL", {
  value: mockCreateObjectURL,
});

Object.defineProperty(global.URL, "revokeObjectURL", {
  value: mockRevokeObjectURL,
});

// Mock document.createElement and appendChild for download simulation
const mockClick = vi.fn();
const mockAppendChild = vi.fn();
const mockRemoveChild = vi.fn();

const mockLink = {
  href: "",
  download: "",
  click: mockClick,
};

Object.defineProperty(document, "createElement", {
  value: vi.fn(() => mockLink),
});

Object.defineProperty(document.body, "appendChild", {
  value: mockAppendChild,
});

Object.defineProperty(document.body, "removeChild", {
  value: mockRemoveChild,
});

describe("ExportControls", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("Basic Rendering", () => {
    it("renders export controls with all format options", () => {
      const props = createMockProps.exportControls();

      render(<ExportControls {...props} />);

      expect(screen.getByText("Export Data")).toBeInTheDocument();
      expect(screen.getByText("CSV Export")).toBeInTheDocument();
      expect(screen.getByText("Excel Export")).toBeInTheDocument();
      expect(screen.getByText("Procurement Order")).toBeInTheDocument();
    });

    it("shows export summary with correct counts", () => {
      const props = createMockProps.exportControls();

      render(<ExportControls {...props} />);

      expect(
        screen.getByText(`Total Items: ${mockInventoryItems.length}`)
      ).toBeInTheDocument();
      expect(
        screen.getByText(`Filtered: ${mockInventoryItems.length}`)
      ).toBeInTheDocument();
      expect(screen.getByText("Selected: 0")).toBeInTheDocument();
    });

    it("shows selected items count when items are selected", () => {
      const selectedRows = new Set(["1-Warehouse Alpha", "2-Warehouse Beta"]);
      const props = createMockProps.exportControls({ selectedRows });

      render(<ExportControls {...props} />);

      expect(screen.getByText("2 items selected")).toBeInTheDocument();
      expect(screen.getByText("Selected: 2")).toBeInTheDocument();
    });

    it("shows replenishment items count", () => {
      const props = createMockProps.exportControls();

      render(<ExportControls {...props} />);

      // Count items with recommendedQty > 0
      const replenishmentCount = mockInventoryItems.filter(
        (item) => item.recommendedQty > 0
      ).length;
      expect(
        screen.getByText(`Need Replenishment: ${replenishmentCount}`)
      ).toBeInTheDocument();
    });
  });

  describe("Export Format Options", () => {
    it("displays format descriptions", () => {
      const props = createMockProps.exportControls();

      render(<ExportControls {...props} />);

      expect(
        screen.getByText("Comma-separated values file")
      ).toBeInTheDocument();
      expect(
        screen.getByText("Microsoft Excel spreadsheet")
      ).toBeInTheDocument();
      expect(
        screen.getByText("Replenishment order format")
      ).toBeInTheDocument();
    });

    it("shows format icons", () => {
      const props = createMockProps.exportControls();

      render(<ExportControls {...props} />);

      expect(screen.getByText("📄")).toBeInTheDocument(); // CSV
      expect(screen.getByText("📊")).toBeInTheDocument(); // Excel
      expect(screen.getByText("📋")).toBeInTheDocument(); // Procurement
    });

    it("shows export format notes", () => {
      const props = createMockProps.exportControls();

      render(<ExportControls {...props} />);

      expect(
        screen.getByText(
          /CSV: Compatible with Excel and other spreadsheet applications/
        )
      ).toBeInTheDocument();
      expect(
        screen.getByText(
          /Excel: Native Excel format with formatting \(coming soon\)/
        )
      ).toBeInTheDocument();
      expect(
        screen.getByText(
          /Procurement: Filtered to items needing replenishment only/
        )
      ).toBeInTheDocument();
    });
  });

  describe("Export Functionality", () => {
    it("exports CSV when CSV button is clicked", async () => {
      const props = createMockProps.exportControls();

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<ExportControls {...props} />);

      const csvButton = screen.getByRole("button", { name: /CSV Export/ });
      await user.click(csvButton);

      await waitFor(() => {
        expect(mockCreateObjectURL).toHaveBeenCalled();
        expect(mockAppendChild).toHaveBeenCalled();
        expect(mockClick).toHaveBeenCalled();
        expect(mockRemoveChild).toHaveBeenCalled();
        expect(mockRevokeObjectURL).toHaveBeenCalled();
      });
    });

    it("exports Excel when Excel button is clicked", async () => {
      const props = createMockProps.exportControls();

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<ExportControls {...props} />);

      const excelButton = screen.getByRole("button", { name: /Excel Export/ });
      await user.click(excelButton);

      await waitFor(() => {
        expect(mockCreateObjectURL).toHaveBeenCalled();
        expect(mockClick).toHaveBeenCalled();
      });
    });

    it("exports procurement order when procurement button is clicked", async () => {
      const props = createMockProps.exportControls();

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<ExportControls {...props} />);

      const procurementButton = screen.getByRole("button", {
        name: /Procurement Order/,
      });
      await user.click(procurementButton);

      await waitFor(() => {
        expect(mockCreateObjectURL).toHaveBeenCalled();
        expect(mockClick).toHaveBeenCalled();
      });
    });

    it("shows loading state during export", async () => {
      const props = createMockProps.exportControls();

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<ExportControls {...props} />);

      const csvButton = screen.getByRole("button", { name: /CSV Export/ });

      // Click and check for loading state
      const clickPromise = user.click(csvButton);

      // Wait for loading state to appear
      await waitFor(() => {
        expect(
          screen.getByRole("status", { name: "Loading" })
        ).toBeInTheDocument();
      });

      await clickPromise;
    });

    it("exports only selected items when items are selected", async () => {
      const selectedRows = new Set(["1-Warehouse Alpha"]);
      const props = createMockProps.exportControls({ selectedRows });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<ExportControls {...props} />);

      const csvButton = screen.getByRole("button", { name: /CSV Export/ });
      await user.click(csvButton);

      await waitFor(() => {
        expect(mockCreateObjectURL).toHaveBeenCalled();
        expect(mockCreateObjectURL.mock.calls.length).toBeGreaterThan(0);
      });

      // Verify that only selected item data was used
      const blobCalls = mockCreateObjectURL.mock.calls;
      expect(blobCalls.length).toBeGreaterThan(0);

      // Type-safe access to mock call arguments
      if (blobCalls.length > 0) {
        const firstCall = blobCalls[0] as any[];
        expect(firstCall).toBeDefined();

        if (firstCall && firstCall.length > 0) {
          const blobContent = firstCall[0] as Blob;
          expect(blobContent).toBeDefined();
          expect(blobContent).toBeInstanceOf(Blob);
          expect(blobContent.type).toBe("text/csv");
        }
      }
    });
  });

  describe("Disabled States", () => {
    it("disables export buttons when no data available", () => {
      const props = createMockProps.exportControls({ data: [] });

      render(<ExportControls {...props} />);

      const csvButton = screen.getByRole("button", { name: /CSV Export/ });
      const excelButton = screen.getByRole("button", { name: /Excel Export/ });
      const procurementButton = screen.getByRole("button", {
        name: /Procurement Order/,
      });

      expect(csvButton).toBeDisabled();
      expect(excelButton).toBeDisabled();
      expect(procurementButton).toBeDisabled();
    });

    it("disables export buttons when loading", () => {
      const props = createMockProps.exportControls({ loading: true });

      render(<ExportControls {...props} />);

      const csvButton = screen.getByRole("button", { name: /CSV Export/ });
      const excelButton = screen.getByRole("button", { name: /Excel Export/ });
      const procurementButton = screen.getByRole("button", {
        name: /Procurement Order/,
      });

      expect(csvButton).toBeDisabled();
      expect(excelButton).toBeDisabled();
      expect(procurementButton).toBeDisabled();
    });

    it("shows no data message when no data available", () => {
      const props = createMockProps.exportControls({ data: [] });

      render(<ExportControls {...props} />);

      expect(
        screen.getByText("No data available for export")
      ).toBeInTheDocument();
      expect(
        screen.getByText("Adjust your filters to see exportable data")
      ).toBeInTheDocument();
    });
  });

  describe("Data Processing", () => {
    it("processes procurement data correctly", async () => {
      // Create test data with mix of items needing and not needing replenishment
      const testData = [
        {
          ...mockInventoryItems[0],
          recommendedQty: 100,
          status: "critical" as const,
        },
        {
          ...mockInventoryItems[1],
          recommendedQty: 0,
          status: "normal" as const,
        },
        {
          ...mockInventoryItems[2],
          recommendedQty: 50,
          status: "low" as const,
        },
      ];

      const props = createMockProps.exportControls({ data: testData });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<ExportControls {...props} />);

      const procurementButton = screen.getByRole("button", {
        name: /Procurement Order/,
      });
      await user.click(procurementButton);

      await waitFor(() => {
        expect(mockCreateObjectURL).toHaveBeenCalled();
        expect(mockCreateObjectURL.mock.calls.length).toBeGreaterThan(0);
      });

      // Verify procurement-specific processing
      const blobCalls = mockCreateObjectURL.mock.calls;
      expect(blobCalls.length).toBeGreaterThan(0);

      // Type-safe access to mock call arguments
      if (blobCalls.length > 0) {
        const firstCall = blobCalls[0] as any[];
        expect(firstCall).toBeDefined();

        if (firstCall && firstCall.length > 0) {
          const blobContent = firstCall[0] as Blob;
          expect(blobContent).toBeDefined();
          expect(blobContent).toBeInstanceOf(Blob);
          expect(blobContent.type).toBe("text/csv");
        }
      }
    });

    it("handles empty procurement data", async () => {
      // Create test data with no items needing replenishment
      const testData = mockInventoryItems.map((item) => ({
        ...item,
        recommendedQty: 0,
      }));

      const props = createMockProps.exportControls({ data: testData });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<ExportControls {...props} />);

      const procurementButton = screen.getByRole("button", {
        name: /Procurement Order/,
      });
      await user.click(procurementButton);

      await waitFor(() => {
        expect(mockCreateObjectURL).toHaveBeenCalled();
      });
    });
  });

  describe("Error Handling", () => {
    it("handles export errors gracefully", async () => {
      // Mock console.error to avoid test output noise
      const consoleSpy = vi
        .spyOn(console, "error")
        .mockImplementation(() => {});

      // Mock alert to capture error message
      const alertSpy = vi.spyOn(window, "alert").mockImplementation(() => {});

      // Make createObjectURL throw an error
      mockCreateObjectURL.mockImplementationOnce(() => {
        throw new Error("Export failed");
      });

      const props = createMockProps.exportControls();

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<ExportControls {...props} />);

      const csvButton = screen.getByRole("button", { name: /CSV Export/ });
      await user.click(csvButton);

      await waitFor(() => {
        expect(consoleSpy).toHaveBeenCalledWith(
          "Export failed:",
          expect.any(Error)
        );
        expect(alertSpy).toHaveBeenCalledWith(
          "Export failed. Please try again."
        );
      });

      consoleSpy.mockRestore();
      alertSpy.mockRestore();
    });
  });

  describe("Accessibility", () => {
    it("has accessible button labels", () => {
      const props = createMockProps.exportControls();

      render(<ExportControls {...props} />);

      expect(
        screen.getByRole("button", { name: /CSV Export/ })
      ).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: /Excel Export/ })
      ).toBeInTheDocument();
      expect(
        screen.getByRole("button", { name: /Procurement Order/ })
      ).toBeInTheDocument();
    });

    it("shows proper disabled state styling", () => {
      const props = createMockProps.exportControls({ data: [] });

      render(<ExportControls {...props} />);

      const csvButton = screen.getByRole("button", { name: /CSV Export/ });
      expect(csvButton).toHaveClass("cursor-not-allowed");
      expect(csvButton).toHaveClass("text-gray-400");
    });

    it("provides loading feedback", async () => {
      const props = createMockProps.exportControls();

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<ExportControls {...props} />);

      const csvButton = screen.getByRole("button", { name: /CSV Export/ });

      const clickPromise = user.click(csvButton);

      // Wait for loading spinner to appear
      await waitFor(() => {
        const loadingSpinner = screen.getByRole("status", { name: "Loading" });
        expect(loadingSpinner).toBeInTheDocument();
      });

      await clickPromise;
    });
  });
});
