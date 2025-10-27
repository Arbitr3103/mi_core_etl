/**
 * Tests for StatusIndicator component
 *
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5
 */

import { describe, it, expect, vi } from "vitest";
import { render, screen } from "../../../test/testUtils";
import { StatusIndicator, StatusLegend } from "../StatusIndicator";
import { createMockProps } from "../../../test/mockData";

describe("StatusIndicator", () => {
  describe("Status Display", () => {
    it("renders critical status with correct styling", () => {
      const props = createMockProps.statusIndicator({
        status: "critical",
        daysOfStock: 7,
      });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toBeInTheDocument();
      expect(indicator).toHaveTextContent("Critical");
      expect(indicator).toHaveTextContent("7d");
      expect(indicator).toHaveClass("text-red-800", "bg-red-100");
    });

    it("renders low status with correct styling", () => {
      const props = createMockProps.statusIndicator({
        status: "low",
        daysOfStock: 21,
      });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toHaveTextContent("Low Stock");
      expect(indicator).toHaveTextContent("21d");
      expect(indicator).toHaveClass("text-yellow-800", "bg-yellow-100");
    });

    it("renders normal status with correct styling", () => {
      const props = createMockProps.statusIndicator({
        status: "normal",
        daysOfStock: 45,
      });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toHaveTextContent("Normal");
      expect(indicator).toHaveTextContent("45d");
      expect(indicator).toHaveClass("text-green-800", "bg-green-100");
    });

    it("renders excess status with correct styling", () => {
      const props = createMockProps.statusIndicator({
        status: "excess",
        daysOfStock: 120,
      });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toHaveTextContent("Excess");
      expect(indicator).toHaveTextContent("120d");
      expect(indicator).toHaveClass("text-blue-800", "bg-blue-100");
    });

    it("renders no_sales status without days display", () => {
      const props = createMockProps.statusIndicator({
        status: "no_sales",
        daysOfStock: 0,
      });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toHaveTextContent("No Sales");
      expect(indicator).not.toHaveTextContent("0d");
      expect(indicator).toHaveClass("text-gray-800", "bg-gray-100");
    });
  });

  describe("Size Variants", () => {
    it("renders small size correctly", () => {
      const props = createMockProps.statusIndicator({ size: "sm" });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toHaveClass("px-2", "py-1", "text-xs");
      // Small size should not show days
      expect(indicator).not.toHaveTextContent("45d");
    });

    it("renders medium size correctly", () => {
      const props = createMockProps.statusIndicator({ size: "md" });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toHaveClass("px-3", "py-1", "text-sm");
      expect(indicator).toHaveTextContent("45d");
    });

    it("renders large size correctly", () => {
      const props = createMockProps.statusIndicator({ size: "lg" });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toHaveClass("px-4", "py-2", "text-base");
      expect(indicator).toHaveTextContent("45d");
    });
  });

  describe("Days of Stock Display", () => {
    it("displays days correctly for normal values", () => {
      const props = createMockProps.statusIndicator({ daysOfStock: 42.7 });

      render(<StatusIndicator {...props} />);

      expect(screen.getByText("43d")).toBeInTheDocument();
    });

    it("displays 999+ for very high values", () => {
      const props = createMockProps.statusIndicator({ daysOfStock: 1500 });

      render(<StatusIndicator {...props} />);

      expect(screen.getByText("999+d")).toBeInTheDocument();
    });

    it("handles zero days correctly", () => {
      const props = createMockProps.statusIndicator({
        status: "critical",
        daysOfStock: 0,
      });

      render(<StatusIndicator {...props} />);

      expect(screen.getByText("0d")).toBeInTheDocument();
    });
  });

  describe("Tooltip Functionality", () => {
    it("shows tooltip on hover when enabled", async () => {
      const props = createMockProps.statusIndicator({
        status: "critical",
        daysOfStock: 7,
        showTooltip: true,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");

      // Hover to show tooltip
      await user.hover(indicator);

      expect(
        screen.getByText(/Critical stock level - only 7 days remaining/)
      ).toBeInTheDocument();
    });

    it("does not show tooltip when disabled", async () => {
      const props = createMockProps.statusIndicator({
        status: "critical",
        daysOfStock: 7,
        showTooltip: false,
      });

      const { userEvent } = await import("@testing-library/user-event");
      const user = userEvent.setup();

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");

      // Hover should not show tooltip
      await user.hover(indicator);

      expect(
        screen.queryByText(/Critical stock level/)
      ).not.toBeInTheDocument();
    });
  });

  describe("Accessibility", () => {
    it("has proper ARIA labels", () => {
      const props = createMockProps.statusIndicator({
        status: "critical",
        daysOfStock: 7,
      });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toHaveAttribute("aria-label");

      const ariaLabel = indicator.getAttribute("aria-label");
      expect(ariaLabel).toContain("Status: Critical");
      expect(ariaLabel).toContain("7 days remaining");
    });

    it("has cursor help when tooltip is enabled", () => {
      const props = createMockProps.statusIndicator({ showTooltip: true });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).toHaveClass("cursor-help");
    });

    it("does not have cursor help when tooltip is disabled", () => {
      const props = createMockProps.statusIndicator({ showTooltip: false });

      render(<StatusIndicator {...props} />);

      const indicator = screen.getByRole("status");
      expect(indicator).not.toHaveClass("cursor-help");
    });
  });
});

describe("StatusLegend", () => {
  it("renders all status types", () => {
    render(<StatusLegend />);

    expect(screen.getByText("Status Legend:")).toBeInTheDocument();
    expect(screen.getByText("Critical")).toBeInTheDocument();
    expect(screen.getByText("Low Stock")).toBeInTheDocument();
    expect(screen.getByText("Normal")).toBeInTheDocument();
    expect(screen.getByText("Excess")).toBeInTheDocument();
    expect(screen.getByText("No Sales")).toBeInTheDocument();
  });

  it("renders in horizontal orientation by default", () => {
    const { container } = render(<StatusLegend />);

    const legendContainer = container.querySelector(".flex.flex-wrap.gap-2");
    expect(legendContainer).toBeInTheDocument();
  });

  it("renders in vertical orientation when specified", () => {
    const { container } = render(<StatusLegend orientation="vertical" />);

    const legendContainer = container.querySelector(".space-y-2");
    expect(legendContainer).toBeInTheDocument();
  });

  it("renders with different sizes", () => {
    const { rerender } = render(<StatusLegend size="sm" />);
    expect(screen.getByText("Status Legend:")).toHaveClass("text-xs");

    rerender(<StatusLegend size="md" />);
    expect(screen.getByText("Status Legend:")).toHaveClass("text-sm");
  });
});
