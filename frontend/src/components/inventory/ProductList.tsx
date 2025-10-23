import React from "react";
import { useVirtualizer } from "@tanstack/react-virtual";
import type { ProductGroup, StockType, ViewMode } from "../../types/inventory";
import { ProductCard } from "./ProductCard";
import { StockStatusBadge } from "./StockStatusBadge";
import { Card } from "../ui/Card";

interface ProductListProps {
  title: string;
  products: ProductGroup;
  type: StockType;
  viewMode: ViewMode;
}

export const ProductList: React.FC<ProductListProps> = React.memo(
  ({ title, products, type, viewMode }) => {
    const parentRef = React.useRef<HTMLDivElement>(null);

    // Determine which products to display based on view mode
    const displayedProducts =
      viewMode === "all" ? products.items : products.items.slice(0, 10);

    // Calculate list height based on view mode
    const itemHeight = 80; // Height for compact mode
    const maxHeight =
      viewMode === "all"
        ? 600
        : Math.min(displayedProducts.length * itemHeight, 500);

    // Setup virtualizer for efficient rendering of large lists
    const virtualizer = useVirtualizer({
      count: displayedProducts.length,
      getScrollElement: () => parentRef.current,
      estimateSize: () => itemHeight,
      overscan: 5,
    });

    return (
      <Card className="flex flex-col h-fit">
        <div className="p-4 border-b border-gray-200">
          <div className="flex justify-between items-center">
            <h3 className="text-lg font-semibold text-gray-900">{title}</h3>
            <StockStatusBadge
              count={products.count}
              displayed={displayedProducts.length}
              type={type}
            />
          </div>
        </div>

        <div className="flex-1">
          {displayedProducts.length > 0 ? (
            <div
              ref={parentRef}
              style={{ height: `${maxHeight}px`, overflow: "auto" }}
              className="px-4"
            >
              <div
                style={{
                  height: `${virtualizer.getTotalSize()}px`,
                  width: "100%",
                  position: "relative",
                }}
              >
                {virtualizer.getVirtualItems().map((virtualItem) => {
                  const product = displayedProducts[virtualItem.index];
                  return (
                    <div
                      key={product.id}
                      style={{
                        position: "absolute",
                        top: 0,
                        left: 0,
                        width: "100%",
                        height: `${virtualItem.size}px`,
                        transform: `translateY(${virtualItem.start}px)`,
                      }}
                    >
                      <ProductCard product={product} type={type} compact />
                    </div>
                  );
                })}
              </div>
            </div>
          ) : (
            <div className="p-8 text-center text-gray-500">
              <p>Товары не найдены</p>
            </div>
          )}
        </div>
      </Card>
    );
  },
  (prevProps, nextProps) => {
    // Only re-render if products count, items length, or view mode changes
    return (
      prevProps.products.count === nextProps.products.count &&
      prevProps.products.items.length === nextProps.products.items.length &&
      prevProps.viewMode === nextProps.viewMode &&
      prevProps.type === nextProps.type
    );
  }
);
