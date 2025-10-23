import React, { useMemo } from "react";

export interface ReplenishmentIndicatorProps {
  need: number;
  targetStock: number;
}

/**
 * ReplenishmentIndicator component with memoization
 * Displays replenishment need with urgency indicator
 *
 * Requirements: 3, 12
 */
export const ReplenishmentIndicator: React.FC<ReplenishmentIndicatorProps> =
  React.memo(({ need, targetStock }) => {
    // Memoize urgency calculation
    const isUrgent = useMemo(() => {
      const percentage = targetStock > 0 ? (need / targetStock) * 100 : 0;
      return percentage > 50;
    }, [need, targetStock]);

    if (need <= 0) {
      return (
        <span className="inline-flex items-center text-green-600 font-medium">
          <svg
            className="w-4 h-4 mr-1"
            fill="currentColor"
            viewBox="0 0 20 20"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              fillRule="evenodd"
              d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
              clipRule="evenodd"
            />
          </svg>
          Достаточно
        </span>
      );
    }

    // Memoize formatted need
    const formattedNeed = useMemo(() => {
      return need.toLocaleString("ru-RU");
    }, [need]);

    return (
      <div
        className={`inline-flex items-center font-semibold ${
          isUrgent ? "text-red-600" : "text-orange-600"
        }`}
      >
        <span>{formattedNeed} шт.</span>
        {isUrgent && (
          <span className="ml-1" title="Срочно! Более 50% от целевого запаса">
            🔥
          </span>
        )}
      </div>
    );
  });
