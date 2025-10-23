import React, { useState, useMemo } from "react";
import type { WarehouseItem } from "../../types/warehouse";

export interface MetricsTooltipProps {
  item: WarehouseItem;
  children: React.ReactNode;
}

/**
 * MetricsTooltip component with memoization
 * Displays detailed Ozon metrics in a tooltip
 *
 * Requirements: 6, 12
 */
export const MetricsTooltip: React.FC<MetricsTooltipProps> = React.memo(
  ({ item, children }) => {
    const [isVisible, setIsVisible] = useState(false);

    // Memoize metrics calculation
    const visibleMetrics = useMemo(() => {
      const metrics = [
        {
          label: "Доступно к продаже",
          value: item.available,
          color: "text-gray-700",
        },
        {
          label: "Готовим к продаже",
          value: item.preparing_for_sale,
          color: "text-blue-600",
        },
        {
          label: "В заявках на поставку",
          value: item.in_supply_requests,
          color: "text-purple-600",
        },
        {
          label: "В поставках в пути",
          value: item.in_transit,
          color: "text-indigo-600",
        },
        {
          label: "Проходят проверку",
          value: item.in_inspection,
          color: "text-yellow-600",
        },
        {
          label: "Возвращаются от покупателей",
          value: item.returning_from_customers,
          color: "text-orange-600",
        },
        {
          label: "Истекает срок годности",
          value: item.expiring_soon,
          color: "text-red-600",
          highlight: item.expiring_soon > 0,
        },
        {
          label: "Брак, доступный к вывозу",
          value: item.defective,
          color: "text-red-500",
          highlight: item.defective > 0,
        },
        {
          label: "Избыток от поставки",
          value: item.excess_from_supply,
          color: "text-gray-600",
        },
        {
          label: "Ожидают УПД",
          value: item.awaiting_upd,
          color: "text-gray-600",
        },
        {
          label: "Готовятся к вывозу",
          value: item.preparing_for_removal,
          color: "text-gray-600",
        },
      ];

      // Filter out zero values unless they're highlighted
      return metrics.filter((m) => m.value > 0 || m.highlight);
    }, [item]);

    return (
      <div className="relative inline-block">
        <div
          onMouseEnter={() => setIsVisible(true)}
          onMouseLeave={() => setIsVisible(false)}
          className="cursor-help"
        >
          {children}
        </div>

        {isVisible && (
          <div className="absolute z-50 w-72 p-4 bg-white border border-gray-300 rounded-lg shadow-xl bottom-full left-1/2 transform -translate-x-1/2 mb-2">
            <div className="text-sm font-semibold text-gray-900 mb-3 border-b pb-2">
              Детальные метрики Ozon
            </div>
            <div className="space-y-2">
              {visibleMetrics.length > 0 ? (
                visibleMetrics.map((metric, index) => (
                  <div
                    key={index}
                    className={`flex justify-between items-center ${
                      metric.highlight
                        ? "bg-red-50 -mx-2 px-2 py-1 rounded"
                        : ""
                    }`}
                  >
                    <span className="text-xs text-gray-600">
                      {metric.label}:
                    </span>
                    <span
                      className={`text-xs font-semibold ${metric.color} ${
                        metric.highlight ? "animate-pulse" : ""
                      }`}
                    >
                      {metric.value.toLocaleString("ru-RU")}
                    </span>
                  </div>
                ))
              ) : (
                <div className="text-xs text-gray-500 italic">
                  Нет дополнительных метрик
                </div>
              )}
            </div>

            {/* Arrow pointer */}
            <div className="absolute top-full left-1/2 transform -translate-x-1/2 -mt-1">
              <div className="w-3 h-3 bg-white border-b border-r border-gray-300 transform rotate-45"></div>
            </div>
          </div>
        )}
      </div>
    );
  }
);
