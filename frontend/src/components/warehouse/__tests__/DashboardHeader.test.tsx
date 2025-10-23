import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { DashboardHeader } from "../DashboardHeader";
import type { DashboardSummary } from "@/types";

const mockSummary: DashboardSummary = {
  total_products: 271,
  active_products: 180,
  total_replenishment_need: 5000,
  by_liquidity: {
    critical: 15,
    low: 30,
    normal: 120,
    excess: 15,
  },
};

describe("DashboardHeader", () => {
  it("renders title and buttons", () => {
    const onRefresh = vi.fn();
    const onExport = vi.fn();

    render(<DashboardHeader onRefresh={onRefresh} onExport={onExport} />);

    expect(screen.getByText(/Дашборд складских остатков/)).toBeInTheDocument();
    expect(screen.getByText(/Обновить данные/)).toBeInTheDocument();
    expect(screen.getByText(/Экспорт в CSV/)).toBeInTheDocument();
  });

  it("calls onRefresh when refresh button is clicked", async () => {
    const user = userEvent.setup();
    const onRefresh = vi.fn();
    const onExport = vi.fn();

    render(<DashboardHeader onRefresh={onRefresh} onExport={onExport} />);

    await user.click(screen.getByText(/Обновить данные/));
    expect(onRefresh).toHaveBeenCalledTimes(1);
  });

  it("calls onExport when export button is clicked", async () => {
    const user = userEvent.setup();
    const onRefresh = vi.fn();
    const onExport = vi.fn();

    render(<DashboardHeader onRefresh={onRefresh} onExport={onExport} />);

    await user.click(screen.getByText(/Экспорт в CSV/));
    expect(onExport).toHaveBeenCalledTimes(1);
  });

  it("displays summary statistics when provided", () => {
    const onRefresh = vi.fn();
    const onExport = vi.fn();

    render(
      <DashboardHeader
        summary={mockSummary}
        onRefresh={onRefresh}
        onExport={onExport}
      />
    );

    expect(screen.getByText("271")).toBeInTheDocument();
    expect(screen.getByText("180")).toBeInTheDocument();
    expect(screen.getByText("15")).toBeInTheDocument();
    expect(screen.getByText("30")).toBeInTheDocument();
    expect(screen.getByText("120")).toBeInTheDocument();
  });

  it("displays replenishment need alert when greater than zero", () => {
    const onRefresh = vi.fn();
    const onExport = vi.fn();

    render(
      <DashboardHeader
        summary={mockSummary}
        onRefresh={onRefresh}
        onExport={onExport}
      />
    );

    expect(
      screen.getByText(/Общая потребность в пополнении:/)
    ).toBeInTheDocument();
    expect(screen.getByText(/5 000 шт\./)).toBeInTheDocument();
  });

  it("does not display replenishment alert when zero", () => {
    const onRefresh = vi.fn();
    const onExport = vi.fn();
    const summaryWithoutNeed = {
      ...mockSummary,
      total_replenishment_need: 0,
    };

    render(
      <DashboardHeader
        summary={summaryWithoutNeed}
        onRefresh={onRefresh}
        onExport={onExport}
      />
    );

    expect(
      screen.queryByText(/Общая потребность в пополнении:/)
    ).not.toBeInTheDocument();
  });

  it("formats last updated date correctly", () => {
    const onRefresh = vi.fn();
    const onExport = vi.fn();

    render(
      <DashboardHeader
        lastUpdated="2025-10-22T12:30:00Z"
        onRefresh={onRefresh}
        onExport={onExport}
      />
    );

    expect(screen.getByText(/Последнее обновление:/)).toBeInTheDocument();
  });

  it("shows unknown when last updated is not provided", () => {
    const onRefresh = vi.fn();
    const onExport = vi.fn();

    render(<DashboardHeader onRefresh={onRefresh} onExport={onExport} />);

    expect(screen.getByText(/Неизвестно/)).toBeInTheDocument();
  });

  it("disables refresh button when refreshing", () => {
    const onRefresh = vi.fn();
    const onExport = vi.fn();

    render(
      <DashboardHeader
        onRefresh={onRefresh}
        onExport={onExport}
        isRefreshing={true}
      />
    );

    const refreshButton = screen.getByText(/Обновить данные/).closest("button");
    expect(refreshButton).toBeDisabled();
  });
});
