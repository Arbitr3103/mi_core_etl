/**
 * Virtual Scroll Table Component
 *
 * High-performance table component with virtual scrolling for handling
 * large datasets efficiently.
 *
 * Requirements: 7.2, 7.3
 * Task: 4.2 Frontend performance optimization - virtual scrolling
 */

import React, {
  useState,
  useEffect,
  useRef,
  useCallback,
  useMemo,
  memo,
} from "react";

interface Column<T> {
  key: keyof T;
  header: string;
  width?: number;
  minWidth?: number;
  sortable?: boolean;
  render?: (value: any, item: T, index: number) => React.ReactNode;
  className?: string;
}

interface VirtualScrollTableProps<T> {
  data: T[];
  columns: Column<T>[];
  rowHeight?: number;
  containerHeight?: number;
  overscan?: number;
  sortConfig?: {
    column: keyof T;
    direction: "asc" | "desc";
  };
  onSort?: (column: keyof T) => void;
  onRowClick?: (item: T, index: number) => void;
  selectedRows?: Set<string>;
  onRowSelect?: (item: T, selected: boolean) => void;
  getRowId?: (item: T) => string;
  className?: string;
  loading?: boolean;
  emptyMessage?: string;
}

/**
 * Props interface for TableRow component
 */
interface TableRowProps<T> {
  item: T;
  index: number;
  columns: Column<T>[];
  style: React.CSSProperties;
  isSelected: boolean;
  onClick?: (item: T, index: number) => void;
  onSelect?: (item: T, selected: boolean) => void;
}

/**
 * Memoized table row component for performance
 */
function TableRowComponent<T>({
  item,
  index,
  columns,
  style,
  isSelected,
  onClick,
  onSelect,
}: TableRowProps<T>) {
  const handleClick = useCallback(() => {
    onClick?.(item, index);
  }, [item, index, onClick]);

  const handleSelectChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      e.stopPropagation();
      onSelect?.(item, e.target.checked);
    },
    [item, onSelect]
  );

  return (
    <div
      style={style}
      className={`virtual-table-row ${isSelected ? "selected" : ""} ${
        index % 2 === 0 ? "even" : "odd"
      }`}
      onClick={handleClick}
    >
      {onSelect && (
        <div className="virtual-table-cell checkbox-cell">
          <input
            type="checkbox"
            checked={isSelected}
            onChange={handleSelectChange}
            onClick={(e) => e.stopPropagation()}
          />
        </div>
      )}
      {columns.map((column) => (
        <div
          key={String(column.key)}
          className={`virtual-table-cell ${column.className || ""}`}
          style={{
            width: column.width || "auto",
            minWidth: column.minWidth || 100,
          }}
        >
          {column.render
            ? column.render(item[column.key], item, index)
            : String(item[column.key] || "")}
        </div>
      ))}
    </div>
  );
}

/**
 * Memoized version of TableRow
 */
const TableRow = memo(TableRowComponent) as typeof TableRowComponent;

/**
 * Header checkbox component that properly handles indeterminate state
 */
interface HeaderCheckboxProps {
  checked: boolean;
  indeterminate?: boolean;
  onChange: (checked: boolean) => void;
}

function HeaderCheckbox({
  checked,
  indeterminate,
  onChange,
}: HeaderCheckboxProps) {
  const checkboxRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (checkboxRef.current) {
      checkboxRef.current.indeterminate = indeterminate || false;
    }
  }, [indeterminate]);

  const handleChange = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      onChange(e.target.checked);
    },
    [onChange]
  );

  return (
    <input
      ref={checkboxRef}
      type="checkbox"
      checked={checked}
      onChange={handleChange}
    />
  );
}

/**
 * Virtual Scroll Table Component
 */
export function VirtualScrollTable<T>({
  data,
  columns,
  rowHeight = 50,
  containerHeight = 600,
  overscan = 5,
  sortConfig,
  onSort,
  onRowClick,
  selectedRows,
  onRowSelect,
  getRowId,
  className = "",
  loading = false,
  emptyMessage = "No data available",
}: VirtualScrollTableProps<T>) {
  const [scrollTop, setScrollTop] = useState(0);
  const [containerWidth, setContainerWidth] = useState(0);
  const containerRef = useRef<HTMLDivElement>(null);
  const scrollElementRef = useRef<HTMLDivElement>(null);

  // Calculate visible range
  const visibleRange = useMemo(() => {
    const startIndex = Math.max(
      0,
      Math.floor(scrollTop / rowHeight) - overscan
    );
    const endIndex = Math.min(
      data.length - 1,
      Math.ceil((scrollTop + containerHeight) / rowHeight) + overscan
    );
    return { startIndex, endIndex };
  }, [scrollTop, rowHeight, containerHeight, overscan, data.length]);

  // Get visible items
  const visibleItems = useMemo(() => {
    return data.slice(visibleRange.startIndex, visibleRange.endIndex + 1);
  }, [data, visibleRange]);

  // Handle scroll
  const handleScroll = useCallback((e: React.UIEvent<HTMLDivElement>) => {
    setScrollTop(e.currentTarget.scrollTop);
  }, []);

  // Handle column sort
  const handleSort = useCallback(
    (column: keyof T) => {
      if (onSort) {
        onSort(column);
      }
    },
    [onSort]
  );

  // Update container width on resize
  useEffect(() => {
    const updateWidth = () => {
      if (containerRef.current) {
        setContainerWidth(containerRef.current.clientWidth);
      }
    };

    updateWidth();
    window.addEventListener("resize", updateWidth);
    return () => window.removeEventListener("resize", updateWidth);
  }, []);

  // Calculate total height
  const totalHeight = data.length * rowHeight;

  // Calculate column widths
  const totalFixedWidth = columns.reduce(
    (sum, col) => sum + (col.width || 0),
    0
  );
  const flexColumns = columns.filter((col) => !col.width);
  const availableWidth =
    containerWidth - totalFixedWidth - (onRowSelect ? 50 : 0);
  const flexColumnWidth =
    flexColumns.length > 0 ? availableWidth / flexColumns.length : 0;

  if (loading) {
    return (
      <div className={`virtual-table-container loading ${className}`}>
        <div className="virtual-table-loading">
          <div className="loading-spinner"></div>
          <span>Loading data...</span>
        </div>
      </div>
    );
  }

  if (data.length === 0) {
    return (
      <div className={`virtual-table-container empty ${className}`}>
        <div className="virtual-table-empty">{emptyMessage}</div>
      </div>
    );
  }

  return (
    <div
      ref={containerRef}
      className={`virtual-table-container ${className}`}
      style={{ height: containerHeight }}
    >
      {/* Header */}
      <div className="virtual-table-header">
        {onRowSelect && (
          <div className="virtual-table-header-cell checkbox-cell">
            <HeaderCheckbox
              checked={selectedRows?.size === data.length && data.length > 0}
              indeterminate={
                selectedRows &&
                selectedRows.size > 0 &&
                selectedRows.size < data.length
              }
              onChange={(checked) => {
                if (onRowSelect && getRowId) {
                  data.forEach((item) => {
                    onRowSelect(item, checked);
                  });
                }
              }}
            />
          </div>
        )}
        {columns.map((column) => (
          <div
            key={String(column.key)}
            className={`virtual-table-header-cell ${
              column.sortable ? "sortable" : ""
            } ${sortConfig?.column === column.key ? "sorted" : ""} ${
              column.className || ""
            }`}
            style={{
              width: column.width || flexColumnWidth,
              minWidth: column.minWidth || 100,
            }}
            onClick={() => column.sortable && handleSort(column.key)}
          >
            <span className="header-text">{column.header}</span>
            {column.sortable && (
              <span className="sort-indicator">
                {sortConfig?.column === column.key
                  ? sortConfig.direction === "asc"
                    ? "↑"
                    : "↓"
                  : "↕"}
              </span>
            )}
          </div>
        ))}
      </div>

      {/* Scrollable content */}
      <div
        ref={scrollElementRef}
        className="virtual-table-scroll-container"
        style={{
          height: containerHeight - 50, // Subtract header height
          overflow: "auto",
        }}
        onScroll={handleScroll}
      >
        {/* Virtual spacer for total height */}
        <div style={{ height: totalHeight, position: "relative" }}>
          {/* Visible rows */}
          {visibleItems.map((item, index) => {
            const actualIndex = visibleRange.startIndex + index;
            const rowId = getRowId ? getRowId(item) : String(actualIndex);
            const isSelected = selectedRows?.has(rowId) || false;

            return (
              <TableRow<T>
                key={rowId}
                item={item}
                index={actualIndex}
                columns={columns}
                style={{
                  position: "absolute",
                  top: actualIndex * rowHeight,
                  left: 0,
                  right: 0,
                  height: rowHeight,
                }}
                isSelected={isSelected}
                onClick={onRowClick}
                onSelect={onRowSelect}
              />
            );
          })}
        </div>
      </div>
    </div>
  );
}

// CSS styles (would typically be in a separate CSS file)
export const virtualTableStyles = `
.virtual-table-container {
  border: 1px solid #e0e0e0;
  border-radius: 4px;
  overflow: hidden;
  background: white;
}

.virtual-table-container.loading,
.virtual-table-container.empty {
  display: flex;
  align-items: center;
  justify-content: center;
}

.virtual-table-loading,
.virtual-table-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
  padding: 40px;
  color: #666;
}

.loading-spinner {
  width: 32px;
  height: 32px;
  border: 3px solid #f3f3f3;
  border-top: 3px solid #007bff;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.virtual-table-header {
  display: flex;
  background: #f8f9fa;
  border-bottom: 1px solid #e0e0e0;
  height: 50px;
  align-items: center;
  font-weight: 600;
  position: sticky;
  top: 0;
  z-index: 10;
}

.virtual-table-header-cell {
  padding: 12px 16px;
  border-right: 1px solid #e0e0e0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  overflow: hidden;
}

.virtual-table-header-cell.sortable {
  cursor: pointer;
  user-select: none;
}

.virtual-table-header-cell.sortable:hover {
  background: #e9ecef;
}

.virtual-table-header-cell.sorted {
  background: #e3f2fd;
}

.virtual-table-header-cell.checkbox-cell {
  width: 50px;
  min-width: 50px;
  justify-content: center;
}

.header-text {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sort-indicator {
  margin-left: 8px;
  opacity: 0.6;
}

.virtual-table-scroll-container {
  position: relative;
}

.virtual-table-row {
  display: flex;
  align-items: center;
  border-bottom: 1px solid #f0f0f0;
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.virtual-table-row:hover {
  background: #f8f9fa;
}

.virtual-table-row.selected {
  background: #e3f2fd;
}

.virtual-table-row.even {
  background: #fafafa;
}

.virtual-table-row.even:hover {
  background: #f0f0f0;
}

.virtual-table-cell {
  padding: 12px 16px;
  border-right: 1px solid #f0f0f0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  display: flex;
  align-items: center;
}

.virtual-table-cell.checkbox-cell {
  width: 50px;
  min-width: 50px;
  justify-content: center;
}
`;

export default VirtualScrollTable;
