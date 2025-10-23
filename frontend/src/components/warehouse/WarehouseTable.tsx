import React, { useMemo, useRef } from "react";
import { useVirtualizer } from "@tanstack/react-virtual";
import type {
  WarehouseGroup,
  WarehouseItem,
  SortConfig,
  SortField,
  SortOrder,
} from "../../types/warehouse";
import { LiquidityBadge } from "./LiquidityBadge";
import { ReplenishmentIndicator } from "./ReplenishmentIndicator";
import { MetricsTooltip } from "./MetricsTooltip";

export interface WarehouseTableProps {
  data: WarehouseGroup[];
  sortConfig: SortConfig;
  onSort: (config: SortConfig) => void;
}

// Row types for virtualization
type TableRow =
  | { type: "warehouse-header"; warehouse: WarehouseGroup }
  | { type: "item"; item: WarehouseItem; warehouse: WarehouseGroup }
  | { type: "warehouse-footer"; warehouse: WarehouseGroup };

export const WarehouseTable: React.FC<WarehouseTableProps> = ({
  data,
  sortConfig,
  onSort,
}) => {
  const parentRef = useRef<HTMLDivElement>(null);

  // Flatten data structure for virtualization
  const rows = useMemo<TableRow[]>(() => {
    const flatRows: TableRow[] = [];

    data.forEach((warehouse) => {
      // Add warehouse header
      flatRows.push({ type: "warehouse-header", warehouse });

      // Add items
      warehouse.items.forEach((item) => {
        flatRows.push({ type: "item", item, warehouse });
      });

      // Add warehouse footer
      flatRows.push({ type: "warehouse-footer", warehouse });
    });

    return flatRows;
  }, [data]);

  // Calculate total item count
  const totalItems = useMemo(() => {
    return data.reduce((sum, warehouse) => sum + warehouse.items.length, 0);
  }, [data]);

  // Use virtualization only for large datasets (> 50 items)
  const shouldVirtualize = totalItems > 50;

  // Setup virtualizer
  const virtualizer = useVirtualizer({
    count: rows.length,
    getScrollElement: () => parentRef.current,
    estimateSize: (index) => {
      const row = rows[index];
      if (row.type === "warehouse-header" || row.type === "warehouse-footer") {
        return 48; // Header/footer height
      }
      return 72; // Item row height
    },
    overscan: 5,
    enabled: shouldVirtualize,
  });
  const handleSort = (field: SortField) => {
    const newOrder: SortOrder =
      sortConfig.field === field && sortConfig.order === "asc" ? "desc" : "asc";
    onSort({ field, order: newOrder });
  };

  const getSortIcon = (field: SortField) => {
    if (sortConfig.field !== field) {
      return (
        <svg
          className="w-4 h-4 ml-1 text-gray-400"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={2}
            d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"
          />
        </svg>
      );
    }

    return sortConfig.order === "asc" ? (
      <svg
        className="w-4 h-4 ml-1 text-primary-600"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
      >
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M5 15l7-7 7 7"
        />
      </svg>
    ) : (
      <svg
        className="w-4 h-4 ml-1 text-primary-600"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
      >
        <path
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={2}
          d="M19 9l-7 7-7-7"
        />
      </svg>
    );
  };

  // Render row component
  const renderRow = (row: TableRow) => {
    if (row.type === "warehouse-header") {
      return (
        <tr className="bg-gray-100 font-semibold">
          <td
            colSpan={7}
            className="px-6 py-3 text-sm text-gray-900 border-t-2 border-gray-300"
          >
            <div className="flex items-center justify-between">
              <span>
                {row.warehouse.warehouse_name}
                <span className="ml-2 text-xs font-normal text-gray-600">
                  ({row.warehouse.cluster})
                </span>
              </span>
              <span className="text-xs font-normal text-gray-600">
                {row.warehouse.totals.total_items} товаров
              </span>
            </div>
          </td>
        </tr>
      );
    }

    if (row.type === "warehouse-footer") {
      return (
        <tr className="bg-gray-50 font-semibold border-b-2 border-gray-300">
          <td
            colSpan={2}
            className="px-6 py-3 text-sm text-gray-900 text-right"
          >
            Итого по складу:
          </td>
          <td className="px-6 py-3 text-sm text-gray-900 text-right">
            {row.warehouse.totals.total_available.toLocaleString("ru-RU")}
          </td>
          <td className="px-6 py-3"></td>
          <td className="px-6 py-3"></td>
          <td className="px-6 py-3 text-sm text-gray-900 text-right">
            {row.warehouse.totals.total_replenishment_need.toLocaleString(
              "ru-RU"
            )}{" "}
            шт.
          </td>
          <td className="px-6 py-3"></td>
        </tr>
      );
    }

    // Item row
    const { item, warehouse } = row;
    return (
      <tr
        key={`${item.product_id}-${item.warehouse_name}`}
        className="hover:bg-gray-50 transition-colors"
      >
        <td className="px-6 py-4">
          <div className="text-sm font-medium text-gray-900 truncate max-w-xs">
            {item.name}
          </div>
          <div className="text-xs text-gray-500 truncate">{item.sku}</div>
        </td>
        <td className="px-6 py-4 text-sm text-gray-700">
          <div className="truncate max-w-xs">{warehouse.warehouse_name}</div>
        </td>
        <td className="px-6 py-4 text-right">
          <MetricsTooltip item={item}>
            <span className="text-sm font-semibold text-gray-900 cursor-help border-b border-dashed border-gray-400 whitespace-nowrap">
              {item.available.toLocaleString("ru-RU")}
            </span>
          </MetricsTooltip>
        </td>
        <td className="px-6 py-4 text-right text-sm text-gray-700 whitespace-nowrap">
          {item.daily_sales_avg.toFixed(2)}
        </td>
        <td className="px-6 py-4 text-right text-sm text-gray-700 whitespace-nowrap">
          {item.days_of_stock !== null ? Math.round(item.days_of_stock) : "∞"}
        </td>
        <td className="px-6 py-4 text-right">
          <ReplenishmentIndicator
            need={item.replenishment_need}
            targetStock={item.target_stock}
          />
        </td>
        <td className="px-6 py-4">
          <LiquidityBadge
            status={item.liquidity_status}
            daysOfStock={item.days_of_stock}
          />
        </td>
      </tr>
    );
  };

  if (!data || data.length === 0) {
    return (
      <div className="bg-white rounded-lg shadow-md p-8 text-center">
        <p className="text-gray-500">Нет данных для отображения</p>
      </div>
    );
  }

  return (
    <div className="bg-white rounded-lg shadow overflow-hidden">
      <div
        ref={parentRef}
        className="overflow-x-auto relative"
        style={{
          maxHeight: shouldVirtualize ? "800px" : "none",
          overflowY: shouldVirtualize ? "auto" : "visible",
        }}
      >
        <table className="min-w-full divide-y divide-gray-200">
          <thead className="bg-gray-50 sticky top-28 z-10">
            <tr>
              <th
                onClick={() => handleSort("name")}
                className="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-gray-100 whitespace-nowrap"
              >
                <div className="flex items-center">
                  Товар
                  {getSortIcon("name")}
                </div>
              </th>
              <th
                onClick={() => handleSort("warehouse_name")}
                className="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-gray-100 whitespace-nowrap"
              >
                <div className="flex items-center">
                  Склад
                  {getSortIcon("warehouse_name")}
                </div>
              </th>
              <th
                onClick={() => handleSort("available")}
                className="px-6 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-gray-100 whitespace-nowrap"
              >
                <div className="flex items-center justify-end">
                  Доступно
                  {getSortIcon("available")}
                </div>
              </th>
              <th
                onClick={() => handleSort("daily_sales_avg")}
                className="px-6 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-gray-100 whitespace-nowrap"
              >
                <div className="flex items-center justify-end">
                  Продажи/день
                  {getSortIcon("daily_sales_avg")}
                </div>
              </th>
              <th
                onClick={() => handleSort("days_of_stock")}
                className="px-6 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-gray-100 whitespace-nowrap"
              >
                <div className="flex items-center justify-end">
                  Дней запаса
                  {getSortIcon("days_of_stock")}
                </div>
              </th>
              <th
                onClick={() => handleSort("replenishment_need")}
                className="px-6 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider cursor-pointer hover:bg-gray-100 whitespace-nowrap"
              >
                <div className="flex items-center justify-end">
                  Нужно заказать
                  {getSortIcon("replenishment_need")}
                </div>
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider whitespace-nowrap">
                Статус
              </th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {shouldVirtualize ? (
              <>
                {/* Spacer for virtual scrolling */}
                <tr style={{ height: `${virtualizer.getTotalSize()}px` }}>
                  <td colSpan={7} style={{ padding: 0 }} />
                </tr>
                {/* Virtualized rows */}
                {virtualizer.getVirtualItems().map((virtualRow) => {
                  const row = rows[virtualRow.index];
                  return (
                    <React.Fragment key={virtualRow.key}>
                      <tr
                        style={{
                          position: "absolute",
                          top: 0,
                          left: 0,
                          width: "100%",
                          transform: `translateY(${virtualRow.start}px)`,
                        }}
                      >
                        <td colSpan={7} style={{ padding: 0 }}>
                          <table className="min-w-full">
                            <tbody>{renderRow(row)}</tbody>
                          </table>
                        </td>
                      </tr>
                    </React.Fragment>
                  );
                })}
              </>
            ) : (
              // Non-virtualized rendering for small datasets
              <>
                {data.map((warehouse) => (
                  <React.Fragment key={warehouse.warehouse_name}>
                    {renderRow({ type: "warehouse-header", warehouse })}
                    {warehouse.items.map((item) =>
                      renderRow({ type: "item", item, warehouse })
                    )}
                    {renderRow({ type: "warehouse-footer", warehouse })}
                  </React.Fragment>
                ))}
              </>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
};
