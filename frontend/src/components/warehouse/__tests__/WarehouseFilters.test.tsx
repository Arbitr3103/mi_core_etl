import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { WarehouseFilters } from "../WarehouseFilters";
import type { FilterState, WarehouseListItem, ClusterListItem } from "@/types";

const mockWarehouses: WarehouseListItem[] = [
  { warehouse_name: "АДЫГЕЙСК_РФЦ", cluster: "Юг", product_count: 50 },
  { warehouse_name: "Ростов-на-Дону_РФЦ", cluster: "Юг", product_count: 30 },
  { warehouse_name: "Екатеринбург_РФЦ", cluster: "Урал", product_count: 40 },
];

const mockClusters: ClusterListItem[] = [
  { cluster: "Юг", warehouse_count: 2, product_count: 80 },
  { cluster: "Урал", warehouse_count: 1, product_count: 40 },
];

const defaultFilters: FilterState = {
  active_only: true,
};

describe("WarehouseFilters", () => {
  it("renders all filter controls", () => {
    const onChange = vi.fn();

    render(
      <WarehouseFilters
        filters={defaultFilters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    expect(screen.getByLabelText(/Склад/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Кластер/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Статус ликвидности/)).toBeInTheDocument();
    expect(screen.getByLabelText(/Только активные товары/)).toBeInTheDocument();
    expect(
      screen.getByLabelText(/Только с потребностью в пополнении/)
    ).toBeInTheDocument();
  });

  it("displays warehouse options", () => {
    const onChange = vi.fn();

    render(
      <WarehouseFilters
        filters={defaultFilters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    const warehouseSelect = screen.getByLabelText(/Склад/);
    expect(warehouseSelect).toBeInTheDocument();
    expect(screen.getByText(/АДЫГЕЙСК_РФЦ/)).toBeInTheDocument();
    expect(screen.getByText(/Екатеринбург_РФЦ/)).toBeInTheDocument();
  });

  it("displays cluster options", () => {
    const onChange = vi.fn();

    render(
      <WarehouseFilters
        filters={defaultFilters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    const clusterSelect = screen.getByLabelText(/Кластер/);
    expect(clusterSelect).toBeInTheDocument();
  });

  it("calls onChange when warehouse is selected", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <WarehouseFilters
        filters={defaultFilters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    const warehouseSelect = screen.getByLabelText(/Склад/);
    await user.selectOptions(warehouseSelect, "АДЫГЕЙСК_РФЦ");

    expect(onChange).toHaveBeenCalledWith({
      ...defaultFilters,
      warehouse: "АДЫГЕЙСК_РФЦ",
    });
  });

  it("calls onChange when cluster is selected", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <WarehouseFilters
        filters={defaultFilters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    const clusterSelect = screen.getByLabelText(/Кластер/);
    await user.selectOptions(clusterSelect, "Юг");

    expect(onChange).toHaveBeenCalledWith({
      ...defaultFilters,
      cluster: "Юг",
    });
  });

  it("calls onChange when liquidity status is selected", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <WarehouseFilters
        filters={defaultFilters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    const liquiditySelect = screen.getByLabelText(/Статус ликвидности/);
    await user.selectOptions(liquiditySelect, "critical");

    expect(onChange).toHaveBeenCalledWith({
      ...defaultFilters,
      liquidity_status: "critical",
    });
  });

  it("calls onChange when active only checkbox is toggled", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <WarehouseFilters
        filters={defaultFilters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    const activeOnlyCheckbox = screen.getByLabelText(/Только активные товары/);
    await user.click(activeOnlyCheckbox);

    expect(onChange).toHaveBeenCalledWith({
      ...defaultFilters,
      active_only: false,
    });
  });

  it("calls onChange when replenishment need checkbox is toggled", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();

    render(
      <WarehouseFilters
        filters={defaultFilters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    const replenishmentCheckbox = screen.getByLabelText(
      /Только с потребностью в пополнении/
    );
    await user.click(replenishmentCheckbox);

    expect(onChange).toHaveBeenCalledWith({
      ...defaultFilters,
      has_replenishment_need: true,
    });
  });

  it("reflects current filter state", () => {
    const onChange = vi.fn();
    const filters: FilterState = {
      warehouse: "АДЫГЕЙСК_РФЦ",
      cluster: "Юг",
      liquidity_status: "critical",
      active_only: false,
      has_replenishment_need: true,
    };

    render(
      <WarehouseFilters
        filters={filters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    expect(screen.getByLabelText(/Склад/)).toHaveValue("АДЫГЕЙСК_РФЦ");
    expect(screen.getByLabelText(/Кластер/)).toHaveValue("Юг");
    expect(screen.getByLabelText(/Статус ликвидности/)).toHaveValue("critical");
    expect(screen.getByLabelText(/Только активные товары/)).not.toBeChecked();
    expect(
      screen.getByLabelText(/Только с потребностью в пополнении/)
    ).toBeChecked();
  });

  it("clears warehouse filter when empty option is selected", async () => {
    const user = userEvent.setup();
    const onChange = vi.fn();
    const filters: FilterState = {
      warehouse: "АДЫГЕЙСК_РФЦ",
      active_only: true,
    };

    render(
      <WarehouseFilters
        filters={filters}
        onChange={onChange}
        warehouses={mockWarehouses}
        clusters={mockClusters}
      />
    );

    const warehouseSelect = screen.getByLabelText(/Склад/);
    await user.selectOptions(warehouseSelect, "");

    expect(onChange).toHaveBeenCalledWith({
      active_only: true,
      warehouse: undefined,
    });
  });
});
