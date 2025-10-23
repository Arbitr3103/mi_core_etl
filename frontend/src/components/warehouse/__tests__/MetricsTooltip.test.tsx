import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MetricsTooltip } from "../MetricsTooltip";
import type { WarehouseItem } from "@/types";

const createMockItem = (overrides?: Partial<WarehouseItem>): WarehouseItem => ({
  product_id: 1,
  sku: "TEST-SKU",
  name: "Test Product",
  warehouse_name: "Test Warehouse",
  cluster: "Test Cluster",
  available: 100,
  reserved: 10,
  preparing_for_sale: 20,
  in_supply_requests: 5,
  in_transit: 15,
  in_inspection: 3,
  returning_from_customers: 2,
  expiring_soon: 0,
  defective: 0,
  excess_from_supply: 0,
  awaiting_upd: 0,
  preparing_for_removal: 0,
  daily_sales_avg: 5.5,
  sales_last_28_days: 154,
  days_without_sales: 0,
  days_of_stock: 18,
  liquidity_status: "normal",
  target_stock: 165,
  replenishment_need: 25,
  last_updated: "2025-10-22T12:00:00Z",
  ...overrides,
});

describe("MetricsTooltip", () => {
  it("renders children without tooltip initially", () => {
    const item = createMockItem();
    render(
      <MetricsTooltip item={item}>
        <span>Hover me</span>
      </MetricsTooltip>
    );

    expect(screen.getByText("Hover me")).toBeInTheDocument();
    expect(
      screen.queryByText(/Детальные метрики Ozon/)
    ).not.toBeInTheDocument();
  });

  it("shows tooltip on mouse enter", async () => {
    const user = userEvent.setup();
    const item = createMockItem();

    render(
      <MetricsTooltip item={item}>
        <span>Hover me</span>
      </MetricsTooltip>
    );

    await user.hover(screen.getByText("Hover me"));

    expect(screen.getByText(/Детальные метрики Ozon/)).toBeInTheDocument();
  });

  it("hides tooltip on mouse leave", async () => {
    const user = userEvent.setup();
    const item = createMockItem();

    render(
      <MetricsTooltip item={item}>
        <span>Hover me</span>
      </MetricsTooltip>
    );

    await user.hover(screen.getByText("Hover me"));
    expect(screen.getByText(/Детальные метрики Ozon/)).toBeInTheDocument();

    await user.unhover(screen.getByText("Hover me"));
    expect(
      screen.queryByText(/Детальные метрики Ozon/)
    ).not.toBeInTheDocument();
  });

  it("displays all non-zero metrics", async () => {
    const user = userEvent.setup();
    const item = createMockItem({
      available: 100,
      preparing_for_sale: 20,
      in_transit: 15,
    });

    render(
      <MetricsTooltip item={item}>
        <span>Hover me</span>
      </MetricsTooltip>
    );

    await user.hover(screen.getByText("Hover me"));

    expect(screen.getByText(/Доступно к продаже:/)).toBeInTheDocument();
    expect(screen.getByText(/Готовим к продаже:/)).toBeInTheDocument();
    expect(screen.getByText(/В поставках в пути:/)).toBeInTheDocument();
  });

  it("highlights expiring items", async () => {
    const user = userEvent.setup();
    const item = createMockItem({
      expiring_soon: 5,
    });

    render(
      <MetricsTooltip item={item}>
        <span>Hover me</span>
      </MetricsTooltip>
    );

    await user.hover(screen.getByText("Hover me"));

    expect(screen.getByText(/Истекает срок годности:/)).toBeInTheDocument();
  });

  it("highlights defective items", async () => {
    const user = userEvent.setup();
    const item = createMockItem({
      defective: 3,
    });

    render(
      <MetricsTooltip item={item}>
        <span>Hover me</span>
      </MetricsTooltip>
    );

    await user.hover(screen.getByText("Hover me"));

    expect(screen.getByText(/Брак, доступный к вывозу:/)).toBeInTheDocument();
  });

  it("shows message when no additional metrics", async () => {
    const user = userEvent.setup();
    const item = createMockItem({
      available: 0,
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
    });

    render(
      <MetricsTooltip item={item}>
        <span>Hover me</span>
      </MetricsTooltip>
    );

    await user.hover(screen.getByText("Hover me"));

    expect(screen.getByText(/Нет дополнительных метрик/)).toBeInTheDocument();
  });

  it("formats numbers with locale separator", async () => {
    const user = userEvent.setup();
    const item = createMockItem({
      available: 1500,
    });

    render(
      <MetricsTooltip item={item}>
        <span>Hover me</span>
      </MetricsTooltip>
    );

    await user.hover(screen.getByText("Hover me"));

    expect(screen.getByText(/1 500/)).toBeInTheDocument();
  });
});
