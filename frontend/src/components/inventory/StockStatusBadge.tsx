import React from "react";
import type { StockType } from "../../types/inventory";

interface StockStatusBadgeProps {
  count: number;
  displayed: number;
  type: StockType;
}

const badgeStyles: Record<StockType, string> = {
  critical: "bg-red-100 text-red-800 border-red-200",
  low_stock: "bg-yellow-100 text-yellow-800 border-yellow-200",
  overstock: "bg-blue-100 text-blue-800 border-blue-200",
};

export const StockStatusBadge: React.FC<StockStatusBadgeProps> = ({
  count,
  displayed,
  type,
}) => {
  const showingAll = count === displayed;

  return (
    <div className="flex items-center gap-2">
      <span
        className={`
          inline-flex items-center px-3 py-1 rounded-full text-sm font-medium border
          ${badgeStyles[type]}
        `}
      >
        {showingAll ? (
          <span>Всего: {count}</span>
        ) : (
          <span>
            Показано: {displayed} из {count}
          </span>
        )}
      </span>
    </div>
  );
};
