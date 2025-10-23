import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { ProductList } from "../ProductList";
import type { ProductGroup } from "../../../types/inventory";

describe("ProductList", () => {
  const mockProducts: ProductGroup = {
    count: 3,
    items: [
      {
        id: 1,
        sku: "SKU001",
        name: "Product 1",
        current_stock: 5,
        available_stock: 5,
        reserved_stock: 0,
        warehouse_name: "Warehouse A",
        stock_status: "critical",
        last_updated: "2025-10-21 10:00:00",
      },
      {
        id: 2,
        sku: "SKU002",
        name: "Product 2",
        current_stock: 15,
        available_stock: 15,
        reserved_stock: 0,
        warehouse_name: "Warehouse B",
        stock_status: "low_stock",
        last_updated: "2025-10-21 10:00:00",
      },
      {
        id: 3,
        sku: "SKU003",
        name: "Product 3",
        current_stock: 150,
        available_stock: 150,
        reserved_stock: 0,
        warehouse_name: "Warehouse C",
        stock_status: "overstock",
        last_updated: "2025-10-21 10:00:00",
      },
    ],
  };

  it("renders product list with title", () => {
    render(
      <ProductList
        title="Test Products"
        products={mockProducts}
        type="critical"
        viewMode="top10"
      />
    );

    expect(screen.getByText("Test Products")).toBeInTheDocument();
  });

  it('renders in "all" view mode', () => {
    const { container } = render(
      <ProductList
        title="Test Products"
        products={mockProducts}
        type="critical"
        viewMode="all"
      />
    );

    // Check that the virtualized container is rendered with correct height
    const virtualContainer = container.querySelector(
      '[style*="height: 600px"]'
    );
    expect(virtualContainer).toBeInTheDocument();
  });

  it('displays "no products" message when list is empty', () => {
    const emptyProducts: ProductGroup = {
      count: 0,
      items: [],
    };

    render(
      <ProductList
        title="Test Products"
        products={emptyProducts}
        type="critical"
        viewMode="top10"
      />
    );

    expect(screen.getByText("Товары не найдены")).toBeInTheDocument();
  });

  it("shows correct badge count", () => {
    render(
      <ProductList
        title="Test Products"
        products={mockProducts}
        type="critical"
        viewMode="all"
      />
    );

    expect(screen.getByText("Всего: 3")).toBeInTheDocument();
  });
});
