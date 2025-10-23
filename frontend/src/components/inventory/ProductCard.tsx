import React from "react";
import type { Product, StockType } from "../../types/inventory";
import { formatNumber, formatDate } from "../../utils/formatters";

interface ProductCardProps {
  product: Product;
  type: StockType;
  compact?: boolean;
}

const stockStatusColors: Record<StockType, string> = {
  critical: "text-red-600 bg-red-50",
  low_stock: "text-yellow-600 bg-yellow-50",
  overstock: "text-blue-600 bg-blue-50",
};

const stockStatusIcons: Record<StockType, string> = {
  critical: "🚨",
  low_stock: "⚠️",
  overstock: "📈",
};

export const ProductCard: React.FC<ProductCardProps> = React.memo(
  ({ product, type, compact = false }) => {
    if (compact) {
      return (
        <div className="flex items-center justify-between py-2 hover:bg-gray-50 transition-colors">
          <div className="flex-1 min-w-0">
            <p className="text-sm font-medium text-gray-900 truncate">
              {product.name}
            </p>
            <p className="text-xs text-gray-500">SKU: {product.sku}</p>
          </div>
          <div className="flex items-center gap-3 ml-4">
            <div className="text-right">
              <p className={`text-sm font-semibold ${stockStatusColors[type]}`}>
                {formatNumber(product.current_stock)}
              </p>
              <p className="text-xs text-gray-500">{product.warehouse_name}</p>
            </div>
            <span className="text-lg">{stockStatusIcons[type]}</span>
          </div>
        </div>
      );
    }

    return (
      <div className="bg-white rounded-lg border border-gray-200 p-4 hover:shadow-md transition-shadow">
        <div className="flex items-start justify-between mb-3">
          <div className="flex-1 min-w-0">
            <h4 className="text-base font-semibold text-gray-900 mb-1 truncate">
              {product.name}
            </h4>
            <p className="text-sm text-gray-500">SKU: {product.sku}</p>
          </div>
          <span className="text-2xl ml-2">{stockStatusIcons[type]}</span>
        </div>

        <div className="grid grid-cols-2 gap-3 mb-3">
          <div>
            <p className="text-xs text-gray-500 mb-1">Текущий остаток</p>
            <p className={`text-lg font-bold ${stockStatusColors[type]}`}>
              {formatNumber(product.current_stock)}
            </p>
          </div>
          <div>
            <p className="text-xs text-gray-500 mb-1">Доступно</p>
            <p className="text-lg font-semibold text-gray-900">
              {formatNumber(product.available_stock)}
            </p>
          </div>
        </div>

        {product.reserved_stock > 0 && (
          <div className="mb-3">
            <p className="text-xs text-gray-500 mb-1">Зарезервировано</p>
            <p className="text-sm font-medium text-gray-700">
              {formatNumber(product.reserved_stock)}
            </p>
          </div>
        )}

        <div className="pt-3 border-t border-gray-100">
          <div className="flex items-center justify-between text-xs text-gray-500">
            <span>{product.warehouse_name}</span>
            <span>{formatDate(product.last_updated)}</span>
          </div>
        </div>
      </div>
    );
  },
  (prevProps, nextProps) => {
    // Custom comparison function for memo
    return (
      prevProps.product.id === nextProps.product.id &&
      prevProps.product.current_stock === nextProps.product.current_stock &&
      prevProps.product.available_stock === nextProps.product.available_stock &&
      prevProps.product.reserved_stock === nextProps.product.reserved_stock &&
      prevProps.type === nextProps.type &&
      prevProps.compact === nextProps.compact
    );
  }
);
