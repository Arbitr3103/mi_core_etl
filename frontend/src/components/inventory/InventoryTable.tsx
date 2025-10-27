/**
 * Inventory Table Component with Virtual Scrolling
 *
 * Displays product-warehouse pairs in a sortable table with virtual scrolling
 * for performance with large datasets. Includes row selection for bulk operations.
 *
 * Requirements: 1.1, 1.2, 2.1, 2.2, 2.3, 4.1, 4.2, 7.3
 */

import React, { useMemo, useCallback, useState } from "react";
import { useVirtualizer } from "@tanstack/react-virtual";
import type {
  ProductWarehouseItem,
  SortConfig,
  TableColumn,
} from "../../types/inventory-dashboard";

import { StatusIndicator } from "./StatusIndicator";
import { TableSkeleton } from "../common/SkeletonLoader";
import { COMPACT_TABLE_COLUMNS } from "./TableColumns";

interface InventoryTableProps {
  data: ProductWarehouseItem[];
  sortConfig: SortConfig;
  onSort: (column: keyof ProductWarehouseItem) => void;
  selectedRows: Set<string>;
  onRowSelection: (itemId: string, selected: boolean) => void;
  onBulkSelection: (selectAll: boolean) => void;
  loading: boolean;
}

/**
 * Table column definitions with formatting
 */
const TABLE_COLUMNS: TableColumn[] = [
  {
    key: "productName",
    label: "Product",
    sortable: true,
    width: "w-64",
    align: "left",
  },
  {
    key: "sku",
    label: "SKU",
    sortable: true,
    width: "w-32",
    align: "left",
  },
  {
    key: "warehouseName",
    label: "Warehouse",
    sortable: true,
    width: "w-40",
    align: "left",
  },
  {
    key: "currentStock",
    label: "Current Stock",
    sortable: true,
    width: "w-28",
    align: "right",
    format: (value: number) => value.toLocaleString(),
  },
  {
    key: "dailySales",
    label: "Daily Sales",
    sortable: true,
    width: "w-28",
    align: "right",
    format: (value: number) => value.toFixed(1),
  },
  {
    key: "sales28d",
    label: "28d Sales",
    sortable: true,
    width: "w-28",
    align: "right",
    format: (value: number) => value.toLocaleString(),
  },
  {
    key: "daysOfStock",
    label: "Days of Stock",
    sortable: true,
    width: "w-32",
    align: "right",
    format: (value: number) => (value > 999 ? "999+" : value.toFixed(0)),
  },
  {
    key: "status",
    label: "Status",
    sortable: true,
    width: "w-32",
    align: "center",
  },
  {
    key: "recommendedQty",
    label: "Recommended",
    sortable: true,
    width: "w-32",
    align: "right",
    format: (value: number) => value.toLocaleString(),
  },
  {
    key: "recommendedValue",
    label: "Value",
    sortable: true,
    width: "w-32",
    align: "right",
    format: (value: number) => `$${(value / 1000).toFixed(0)}k`,
  },
];

/**
 * Table header component
 */
const TableHeader: React.FC<{
  columns: TableColumn[];
  sortConfig: SortConfig;
  onSort: (column: keyof ProductWarehouseItem) => void;
  selectedCount: number;
  totalCount: number;
  onBulkSelection: (selectAll: boolean) => void;
}> = ({
  columns,
  sortConfig,
  onSort,
  selectedCount,
  totalCount,
  onBulkSelection,
}) => {
  const isAllSelected = selectedCount === totalCount && totalCount > 0;
  const isIndeterminate = selectedCount > 0 && selectedCount < totalCount;

  return (
    <thead className="bg-gray-50 sticky top-0 z-10" role="rowgroup">
      <tr role="row">
        {/* Selection checkbox */}
        <th
          className="w-12 px-3 py-3 text-left"
          role="columnheader"
          scope="col"
        >
          <input
            type="checkbox"
            checked={isAllSelected}
            ref={(input) => {
              if (input) input.indeterminate = isIndeterminate;
            }}
            onChange={(e) => onBulkSelection(e.target.checked)}
            className="rounded border-gray-300 text-blue-600 focus:ring-blue-500 focus:ring-2"
            aria-label={`Select all ${totalCount} items`}
            aria-describedby="bulk-selection-help"
          />
          <div id="bulk-selection-help" className="sr-only">
            {isAllSelected
              ? "All items are selected. Uncheck to deselect all."
              : isIndeterminate
              ? `${selectedCount} of ${totalCount} items selected. Check to select all.`
              : "No items selected. Check to select all items."}
          </div>
        </th>

        {/* Column headers */}
        {columns.map((column) => (
          <th
            key={column.key}
            className={`${
              column.width
            } px-3 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider ${
              column.sortable
                ? "cursor-pointer hover:bg-gray-100 focus:bg-gray-100"
                : ""
            } ${
              column.align === "right"
                ? "text-right"
                : column.align === "center"
                ? "text-center"
                : "text-left"
            }`}
            onClick={() => column.sortable && onSort(column.key)}
            onKeyDown={(e) => {
              if (column.sortable && (e.key === "Enter" || e.key === " ")) {
                e.preventDefault();
                onSort(column.key);
              }
            }}
            role="columnheader"
            scope="col"
            tabIndex={column.sortable ? 0 : -1}
            aria-sort={
              sortConfig.column === column.key
                ? sortConfig.direction === "asc"
                  ? "ascending"
                  : "descending"
                : column.sortable
                ? "none"
                : undefined
            }
            aria-label={
              column.sortable
                ? `${column.label}. ${
                    sortConfig.column === column.key
                      ? `Currently sorted ${
                          sortConfig.direction === "asc"
                            ? "ascending"
                            : "descending"
                        }.`
                      : "Not sorted."
                  } Click to sort.`
                : column.label
            }
          >
            <div className="flex items-center space-x-1">
              <span>{column.label}</span>
              {column.sortable && (
                <div className="flex flex-col">
                  <svg
                    className={`w-3 h-3 ${
                      sortConfig.column === column.key &&
                      sortConfig.direction === "asc"
                        ? "text-blue-600"
                        : "text-gray-400"
                    }`}
                    fill="currentColor"
                    viewBox="0 0 20 20"
                  >
                    <path
                      fillRule="evenodd"
                      d="M14.707 12.707a1 1 0 01-1.414 0L10 9.414l-3.293 3.293a1 1 0 01-1.414-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 010 1.414z"
                      clipRule="evenodd"
                    />
                  </svg>
                  <svg
                    className={`w-3 h-3 -mt-1 ${
                      sortConfig.column === column.key &&
                      sortConfig.direction === "desc"
                        ? "text-blue-600"
                        : "text-gray-400"
                    }`}
                    fill="currentColor"
                    viewBox="0 0 20 20"
                  >
                    <path
                      fillRule="evenodd"
                      d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                      clipRule="evenodd"
                    />
                  </svg>
                </div>
              )}
            </div>
          </th>
        ))}
      </tr>
    </thead>
  );
};

/**
 * Table row component
 */
const TableRow: React.FC<{
  item: ProductWarehouseItem;
  columns: TableColumn[];
  isSelected: boolean;
  onSelection: (selected: boolean) => void;
}> = ({ item, columns, isSelected, onSelection }) => {
  const formatCellValue = (column: TableColumn, item: ProductWarehouseItem) => {
    const value = item[column.key];

    if (column.key === "status") {
      return (
        <StatusIndicator
          status={item.status}
          daysOfStock={item.daysOfStock}
          size="sm"
        />
      );
    }

    if (column.format && typeof value === "number") {
      return column.format(value);
    }

    return value?.toString() || "-";
  };

  return (
    <tr
      className={`${
        isSelected ? "bg-blue-50 ring-2 ring-blue-200" : "bg-white"
      } table-row-hover border-b border-gray-200 transition-smooth`}
      role="row"
      aria-selected={isSelected}
    >
      {/* Selection checkbox */}
      <td className="w-12 px-3 py-4" role="gridcell">
        <input
          type="checkbox"
          checked={isSelected}
          onChange={(e) => onSelection(e.target.checked)}
          className="rounded border-gray-300 text-blue-600 focus:ring-blue-500 focus:ring-2"
          aria-label={`Select ${item.productName} in ${item.warehouseName}`}
        />
      </td>

      {/* Data cells */}
      {columns.map((column) => (
        <td
          key={column.key}
          className={`${column.width} px-3 py-4 text-sm ${
            column.align === "right"
              ? "text-right"
              : column.align === "center"
              ? "text-center"
              : "text-left"
          }`}
          role="gridcell"
          aria-describedby={
            column.key === "status"
              ? `status-${item.productId}-${item.warehouseName}`
              : undefined
          }
        >
          <div
            className={`${
              column.key === "productName"
                ? "font-medium text-gray-900"
                : "text-gray-700"
            }`}
          >
            {formatCellValue(column, item)}
          </div>
          {column.key === "productName" && (
            <div className="text-xs text-gray-500 mt-1">
              ID: {item.productId}
            </div>
          )}
        </td>
      ))}
    </tr>
  );
};

/**
 * Main Inventory Table Component
 */
export const InventoryTable: React.FC<InventoryTableProps> = ({
  data,
  sortConfig,
  onSort,
  selectedRows,
  onRowSelection,
  onBulkSelection,
  loading,
}) => {
  const [tableContainer, setTableContainer] = useState<HTMLDivElement | null>(
    null
  );

  // Create unique IDs for each item
  const itemsWithIds = useMemo(
    () =>
      data.map((item) => ({
        ...item,
        id: `${item.productId}-${item.warehouseName}`,
      })),
    [data]
  );

  // Virtual scrolling setup
  const virtualizer = useVirtualizer({
    count: itemsWithIds.length,
    getScrollElement: () => tableContainer,
    estimateSize: () => 60, // Estimated row height
    overscan: 10, // Render extra items for smooth scrolling
  });

  // Handle row selection
  const handleRowSelection = useCallback(
    (itemId: string, selected: boolean) => {
      onRowSelection(itemId, selected);
    },
    [onRowSelection]
  );

  // Debug: Log data
  console.log("InventoryTable received data:", {
    dataLength: data.length,
    loading,
    firstItem: data[0],
  });

  // Use compact columns
  const columns = COMPACT_TABLE_COLUMNS;

  // Loading state
  if (loading) {
    return (
      <div className="p-4">
        <TableSkeleton rows={8} columns={columns.length} />
      </div>
    );
  }

  // Empty state
  if (data.length === 0) {
    return (
      <div className="text-center py-16 px-4">
        <div className="max-w-md mx-auto">
          <div className="w-16 h-16 mx-auto mb-6 bg-gray-100 rounded-full flex items-center justify-center">
            <svg
              className="w-8 h-8 text-gray-400"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2M4 13h2m13-8V4a1 1 0 00-1-1H7a1 1 0 00-1 1v1m8 0V4a1 1 0 00-1-1H9a1 1 0 00-1 1v1"
              />
            </svg>
          </div>
          <h3 className="text-xl font-semibold text-gray-900 mb-3">
            Данные инвентаря не найдены
          </h3>
          <p className="text-gray-600 mb-6">
            Ни один товар не соответствует вашим фильтрам. Попробуйте изменить
            критерии поиска или очистить фильтры, чтобы увидеть весь инвентарь.
          </p>
          <div className="flex flex-col sm:flex-row gap-3 justify-center">
            <button className="px-4 py-2 text-sm font-medium text-blue-600 bg-blue-50 rounded-md hover:bg-blue-100 transition-colors">
              Очистить фильтры
            </button>
            <button className="px-4 py-2 text-sm font-medium text-gray-600 bg-gray-50 rounded-md hover:bg-gray-100 transition-colors">
              Обновить данные
            </button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="overflow-hidden">
      {/* Simple table without virtual scrolling - TEMPORARY FIX */}
      <div className="overflow-auto" style={{ maxHeight: "600px" }}>
        <table
          className="min-w-full divide-y divide-gray-200"
          style={{ minWidth: "800px" }}
          role="table"
          aria-label="Inventory data table"
        >
          <TableHeader
            columns={columns}
            sortConfig={sortConfig}
            onSort={onSort}
            selectedCount={selectedRows.size}
            totalCount={data.length}
            onBulkSelection={onBulkSelection}
          />
          <tbody className="bg-white divide-y divide-gray-200">
            {itemsWithIds.map((item) => {
              const isSelected = selectedRows.has(item.id);
              return (
                <TableRow
                  key={item.id}
                  item={item}
                  columns={columns}
                  isSelected={isSelected}
                  onSelection={(selected) =>
                    handleRowSelection(item.id, selected)
                  }
                />
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Table footer with summary */}
      <div className="bg-gray-50 px-4 py-3 border-t border-gray-200">
        <div className="flex items-center justify-between text-sm text-gray-700">
          <div>
            Showing {data.length.toLocaleString()} items
            {selectedRows.size > 0 && (
              <span className="ml-2 text-blue-600">
                ({selectedRows.size} selected)
              </span>
            )}
          </div>
          <div className="text-xs text-gray-500">Scroll to see more items</div>
        </div>
      </div>
    </div>
  );
};

export default InventoryTable;
