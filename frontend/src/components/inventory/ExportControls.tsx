/**
 * Export Controls Component
 *
 * Provides data export functionality including Excel, CSV export
 * and procurement order generation for filtered inventory data.
 *
 * Requirements: 7.4
 */

import React, { useState, useCallback, useMemo } from "react";
import type {
  ProductWarehouseItem,
  FilterState,
  ExportConfig,
} from "../../types/inventory-dashboard";
import { LoadingSpinner } from "../common/LoadingSpinner";

interface ExportControlsProps {
  data: ProductWarehouseItem[];
  filters: FilterState;
  selectedRows: Set<string>;
  loading: boolean;
}

/**
 * Export format options
 */
const EXPORT_FORMATS = [
  {
    key: "csv" as const,
    label: "CSV экспорт",
    description: "Файл со значениями, разделёнными запятыми",
    icon: "📄",
    mimeType: "text/csv",
    extension: "csv",
  },
  {
    key: "excel" as const,
    label: "Excel экспорт",
    description: "Таблица Microsoft Excel",
    icon: "📊",
    mimeType:
      "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    extension: "xlsx",
  },
  {
    key: "procurement" as const,
    label: "Заказ на закупку",
    description: "Формат заказа на пополнение",
    icon: "📋",
    mimeType: "text/csv",
    extension: "csv",
  },
];

/**
 * Generate CSV content from data
 */
const generateCSV = (
  data: ProductWarehouseItem[],
  includeHeaders = true
): string => {
  const headers = [
    "Product ID",
    "Product Name",
    "SKU",
    "Warehouse",
    "Current Stock",
    "Reserved Stock",
    "Available Stock",
    "Daily Sales",
    "7d Sales",
    "28d Sales",
    "Days of Stock",
    "Status",
    "Recommended Qty",
    "Recommended Value",
    "Urgency Score",
    "Last Sale Date",
    "Stockout Risk",
  ];

  const rows = data.map((item) => [
    item.productId,
    `"${item.productName.replace(/"/g, '""')}"`, // Escape quotes
    item.sku,
    item.warehouseName,
    item.currentStock,
    item.reservedStock,
    item.availableStock,
    item.dailySales,
    item.sales7d,
    item.sales28d,
    item.daysOfStock,
    item.status,
    item.recommendedQty,
    item.recommendedValue,
    item.urgencyScore,
    item.lastSaleDate || "",
    item.stockoutRisk,
  ]);

  const csvContent = [...(includeHeaders ? [headers] : []), ...rows]
    .map((row) => row.join(","))
    .join("\n");

  return csvContent;
};

/**
 * Generate procurement order CSV
 */
const generateProcurementCSV = (data: ProductWarehouseItem[]): string => {
  // Filter items that need replenishment
  const replenishmentItems = data.filter((item) => item.recommendedQty > 0);

  const headers = [
    "Product ID",
    "Product Name",
    "SKU",
    "Warehouse",
    "Current Stock",
    "Recommended Qty",
    "Estimated Value",
    "Priority",
    "Status",
    "Days of Stock",
    "Notes",
  ];

  const rows = replenishmentItems.map((item) => {
    const priority =
      item.urgencyScore > 80
        ? "High"
        : item.urgencyScore > 60
        ? "Medium"
        : "Low";
    const notes =
      item.status === "critical"
        ? "URGENT: Critical stock level"
        : item.status === "low"
        ? "Low stock - replenish soon"
        : "Normal replenishment";

    return [
      item.productId,
      `"${item.productName.replace(/"/g, '""')}"`,
      item.sku,
      item.warehouseName,
      item.currentStock,
      item.recommendedQty,
      item.recommendedValue,
      priority,
      item.status,
      item.daysOfStock,
      `"${notes}"`,
    ];
  });

  const csvContent = [headers, ...rows].map((row) => row.join(",")).join("\n");

  return csvContent;
};

/**
 * Download file helper
 */
const downloadFile = (content: string, filename: string, mimeType: string) => {
  const blob = new Blob([content], { type: mimeType });
  const url = URL.createObjectURL(blob);

  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);

  URL.revokeObjectURL(url);
};

/**
 * Generate filename with timestamp
 */
const generateFilename = (format: string, prefix = "inventory"): string => {
  const timestamp = new Date().toISOString().slice(0, 19).replace(/[:-]/g, "");
  return `${prefix}_${timestamp}.${format}`;
};

/**
 * Export Button Component
 */
const ExportButton: React.FC<{
  format: (typeof EXPORT_FORMATS)[0];
  onClick: () => void;
  disabled: boolean;
  loading: boolean;
}> = ({ format, onClick, disabled, loading }) => {
  return (
    <button
      onClick={onClick}
      disabled={disabled || loading}
      className={`
        flex items-center space-x-2 sm:space-x-3 w-full p-2 sm:p-3 text-left rounded-lg border transition-smooth
        ${
          disabled || loading
            ? "bg-gray-50 text-gray-400 border-gray-200 cursor-not-allowed opacity-60"
            : "bg-white text-gray-700 border-gray-300 hover:bg-gray-50 hover:border-gray-400 hover-lift focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
        }
      `}
    >
      <span className="text-lg sm:text-2xl">{format.icon}</span>
      <div className="flex-1 min-w-0">
        <div className="font-medium text-sm sm:text-base truncate">
          {format.label}
        </div>
        <div className="text-xs sm:text-sm text-gray-500 truncate">
          {format.description}
        </div>
      </div>
      {loading && <LoadingSpinner size="sm" />}
    </button>
  );
};

/**
 * Export Summary Component
 */
const ExportSummary: React.FC<{
  totalItems: number;
  selectedItems: number;
  filteredItems: number;
  replenishmentItems: number;
}> = ({ totalItems, selectedItems, filteredItems, replenishmentItems }) => {
  return (
    <div className="bg-gray-50 rounded-lg p-3 space-y-2">
      <div className="text-sm font-medium text-gray-700">Сводка экспорта</div>
      <div className="grid grid-cols-2 gap-2 text-xs text-gray-600">
        <div>Всего позиций: {totalItems.toLocaleString()}</div>
        <div>Filtered: {filteredItems.toLocaleString()}</div>
        <div>Selected: {selectedItems.toLocaleString()}</div>
        <div>Need Replenishment: {replenishmentItems.toLocaleString()}</div>
      </div>
    </div>
  );
};

/**
 * Main Export Controls Component
 */
export const ExportControls: React.FC<ExportControlsProps> = ({
  data,
  selectedRows,
  loading,
}) => {
  const [exportLoading, setExportLoading] = useState<string | null>(null);

  // Calculate export data based on selection
  const exportData = useMemo(() => {
    if (selectedRows.size > 0) {
      // Export only selected items
      return data.filter((item) =>
        selectedRows.has(`${item.productId}-${item.warehouseName}`)
      );
    }
    // Export all filtered data
    return data;
  }, [data, selectedRows]);

  // Calculate summary statistics
  const summary = useMemo(() => {
    const replenishmentItems = exportData.filter(
      (item) => item.recommendedQty > 0
    );

    return {
      totalItems: data.length,
      selectedItems: selectedRows.size,
      filteredItems: data.length,
      replenishmentItems: replenishmentItems.length,
    };
  }, [data, selectedRows, exportData]);

  // Handle export action
  const handleExport = useCallback(
    async (format: ExportConfig["format"]) => {
      if (exportData.length === 0) return;

      setExportLoading(format);

      try {
        let content: string;
        let filename: string;
        let mimeType: string;

        switch (format) {
          case "csv":
            content = generateCSV(exportData);
            filename = generateFilename("csv", "inventory_export");
            mimeType = "text/csv";
            break;

          case "excel":
            // For now, generate CSV (in a real implementation, you'd use a library like xlsx)
            content = generateCSV(exportData);
            filename = generateFilename("csv", "inventory_export");
            mimeType = "text/csv";
            break;

          case "procurement":
            content = generateProcurementCSV(exportData);
            filename = generateFilename("csv", "procurement_order");
            mimeType = "text/csv";
            break;

          default:
            throw new Error(`Unsupported export format: ${format}`);
        }

        // Simulate API delay for better UX
        await new Promise((resolve) => setTimeout(resolve, 500));

        downloadFile(content, filename, mimeType);
      } catch (error) {
        console.error("Export failed:", error);
        // In a real app, you'd show a toast notification or error message
        alert("Export failed. Please try again.");
      } finally {
        setExportLoading(null);
      }
    },
    [exportData]
  );

  // Check if export is available
  const canExport = exportData.length > 0 && !loading;

  return (
    <div className="space-y-3 sm:space-y-4">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between space-y-1 sm:space-y-0">
        <h3 className="text-base sm:text-lg font-medium text-gray-900">
          Экспорт данных
        </h3>
        <div className="text-xs sm:text-sm text-gray-500">
          {selectedRows.size > 0
            ? `${selectedRows.size} items selected`
            : `${data.length} items available`}
        </div>
      </div>

      {/* Export Summary */}
      <ExportSummary {...summary} />

      {/* Export Options */}
      <div className="space-y-2">
        <div className="text-xs sm:text-sm font-medium text-gray-700 mb-2 sm:mb-3">
          Выберите формат экспорта:
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
          {EXPORT_FORMATS.map((format) => (
            <ExportButton
              key={format.key}
              format={format}
              onClick={() => handleExport(format.key)}
              disabled={!canExport}
              loading={exportLoading === format.key}
            />
          ))}
        </div>
      </div>

      {/* Export Options */}
      <div className="pt-3 border-t border-gray-200">
        <div className="text-xs text-gray-500 space-y-1">
          <div>
            • CSV: Совместим с Excel и другими приложениями для работы с
            таблицами
          </div>
          <div>• Excel: Нативный формат Excel с форматированием (скоро)</div>
          <div>
            • Закупка: Отфильтровано только позиции, требующие пополнения
          </div>
        </div>
      </div>

      {/* No data message */}
      {!canExport && !loading && (
        <div className="text-center py-4 text-gray-500">
          <svg
            className="w-8 h-8 mx-auto mb-2 text-gray-400"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
            />
          </svg>
          <div className="text-sm">Нет данных для экспорта</div>
          <div className="text-xs">
            Измените фильтры, чтобы увидеть данные для экспорта
          </div>
        </div>
      )}
    </div>
  );
};

export default ExportControls;
