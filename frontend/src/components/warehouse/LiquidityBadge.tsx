import React, { useMemo } from "react";
import type { LiquidityStatus } from "../../types/warehouse";

export interface LiquidityBadgeProps {
  status: LiquidityStatus;
  daysOfStock: number | null;
}

/**
 * LiquidityBadge component with memoization
 * Displays liquidity status with color-coded badge
 *
 * Requirements: 5, 7, 12
 */
export const LiquidityBadge: React.FC<LiquidityBadgeProps> = React.memo(
  ({ status, daysOfStock }) => {
    const colors: Record<LiquidityStatus, string> = {
      critical: "bg-red-100 text-red-800 border-red-300",
      low: "bg-yellow-100 text-yellow-800 border-yellow-300",
      normal: "bg-green-100 text-green-800 border-green-300",
      excess: "bg-blue-100 text-blue-800 border-blue-300",
    };

    const labels: Record<LiquidityStatus, string> = {
      critical: "Дефицит",
      low: "Низкий",
      normal: "Норма",
      excess: "Избыток",
    };

    // Memoize formatted days calculation
    const formattedDays = useMemo(() => {
      if (daysOfStock === null) return "∞";
      return `${Math.round(daysOfStock)} дн.`;
    }, [daysOfStock]);

    return (
      <span
        className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border ${colors[status]}`}
      >
        {labels[status]}
        {daysOfStock !== null && (
          <span className="ml-1 font-semibold">({formattedDays})</span>
        )}
      </span>
    );
  }
);
