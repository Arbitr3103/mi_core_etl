/**
 * Filter Panel Component
 *
 * Provides filtering controls for the inventory dashboard including
 * warehouse selection, status filters, and product search with debouncing.
 *
 * Requirements: 3.1, 3.2, 3.3, 3.4, 3.5
 */

import React, { useState, useEffect, useCallback, useMemo } from "react";
import type { FilterState, StockStatus } from "../../types/inventory-dashboard";
import {
  DEFAULT_FILTERS,
  DEFAULT_SEARCH_CONFIG,
} from "../../types/inventory-dashboard";
import { LoadingSpinner } from "../common/LoadingSpinner";

interface FilterPanelProps {
  filters: FilterState;
  onFilterChange: (filters: FilterState) => void;
  warehouses: string[];
  loading: boolean;
}

/**
 * Custom hook for debounced search
 */
const useDebounce = (value: string, delay: number) => {
  const [debouncedValue, setDebouncedValue] = useState(value);

  useEffect(() => {
    const handler = setTimeout(() => {
      setDebouncedValue(value);
    }, delay);

    return () => {
      clearTimeout(handler);
    };
  }, [value, delay]);

  return debouncedValue;
};

/**
 * Status filter options with labels and colors
 */
const STATUS_OPTIONS: Array<{
  value: StockStatus;
  label: string;
  description: string;
  color: string;
}> = [
  {
    value: "critical",
    label: "Критический",
    description: "< 14 дней",
    color: "text-red-600 bg-red-50 border-red-200",
  },
  {
    value: "low",
    label: "Низкий",
    description: "14-30 дней",
    color: "text-yellow-600 bg-yellow-50 border-yellow-200",
  },
  {
    value: "normal",
    label: "Нормальный",
    description: "30-60 дней",
    color: "text-green-600 bg-green-50 border-green-200",
  },
  {
    value: "excess",
    label: "Избыток",
    description: "> 60 дней",
    color: "text-blue-600 bg-blue-50 border-blue-200",
  },
  {
    value: "no_sales",
    label: "Нет продаж",
    description: "Нет продаж за период",
    color: "text-gray-600 bg-gray-50 border-gray-200",
  },
];

/**
 * Warehouse Filter Component
 */
const WarehouseFilter: React.FC<{
  warehouses: string[];
  selectedWarehouses: string[];
  onChange: (warehouses: string[]) => void;
  loading: boolean;
}> = ({ warehouses, selectedWarehouses, onChange, loading }) => {
  const [isExpanded, setIsExpanded] = useState(false);
  const [searchTerm, setSearchTerm] = useState("");

  // Filter warehouses based on search
  const filteredWarehouses = useMemo(() => {
    if (!searchTerm) return warehouses;
    return warehouses.filter((warehouse) =>
      warehouse.toLowerCase().includes(searchTerm.toLowerCase())
    );
  }, [warehouses, searchTerm]);

  const handleWarehouseToggle = (warehouse: string) => {
    const newSelection = selectedWarehouses.includes(warehouse)
      ? selectedWarehouses.filter((w) => w !== warehouse)
      : [...selectedWarehouses, warehouse];
    onChange(newSelection);
  };

  const handleSelectAll = () => {
    onChange(filteredWarehouses);
  };

  const handleClearAll = () => {
    onChange([]);
  };

  if (loading) {
    return (
      <div className="space-y-2">
        <label className="block text-sm font-medium text-gray-700">
          Warehouses
        </label>
        <div className="flex items-center justify-center py-4">
          <LoadingSpinner size="sm" />
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <label className="block text-sm font-medium text-gray-700">
          Warehouses
        </label>
        <button
          onClick={() => setIsExpanded(!isExpanded)}
          className="text-xs text-blue-600 hover:text-blue-800"
        >
          {isExpanded ? "Collapse" : "Expand"}
        </button>
      </div>

      {/* Selected count */}
      <div className="text-xs text-gray-500">
        {selectedWarehouses.length === 0
          ? "Все склады"
          : `${selectedWarehouses.length} of ${warehouses.length} selected`}
      </div>

      {/* Warehouse selection */}
      <div className={`space-y-2 ${isExpanded ? "block" : "hidden"}`}>
        {/* Search input */}
        <input
          type="text"
          placeholder="Поиск складов..."
          value={searchTerm}
          onChange={(e) => setSearchTerm(e.target.value)}
          className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
        />

        {/* Select all/clear buttons */}
        <div className="flex space-x-2">
          <button
            onClick={handleSelectAll}
            className="text-xs text-blue-600 hover:text-blue-800"
          >
            Select All
          </button>
          <button
            onClick={handleClearAll}
            className="text-xs text-gray-600 hover:text-gray-800"
          >
            Clear All
          </button>
        </div>

        {/* Warehouse list */}
        <div className="max-h-48 overflow-y-auto space-y-1">
          {filteredWarehouses.map((warehouse) => (
            <label
              key={warehouse}
              className="flex items-center space-x-2 text-sm cursor-pointer hover:bg-gray-50 p-1 rounded"
            >
              <input
                type="checkbox"
                checked={selectedWarehouses.includes(warehouse)}
                onChange={() => handleWarehouseToggle(warehouse)}
                className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
              />
              <span className="flex-1 truncate">{warehouse}</span>
            </label>
          ))}
        </div>

        {filteredWarehouses.length === 0 && searchTerm && (
          <div className="text-xs text-gray-500 text-center py-2">
            No warehouses match "{searchTerm}"
          </div>
        )}
      </div>
    </div>
  );
};

/**
 * Status Filter Component
 */
const StatusFilter: React.FC<{
  selectedStatuses: StockStatus[];
  onChange: (statuses: StockStatus[]) => void;
}> = ({ selectedStatuses, onChange }) => {
  const handleStatusToggle = (status: StockStatus) => {
    const newSelection = selectedStatuses.includes(status)
      ? selectedStatuses.filter((s) => s !== status)
      : [...selectedStatuses, status];
    onChange(newSelection);
  };

  const handleClearAll = () => {
    onChange([]);
  };

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <label className="block text-sm font-medium text-gray-700">
          Статус запаса
        </label>
        {selectedStatuses.length > 0 && (
          <button
            onClick={handleClearAll}
            className="text-xs text-gray-600 hover:text-gray-800"
          >
            Clear All
          </button>
        )}
      </div>

      <div className="space-y-2">
        {STATUS_OPTIONS.map((option) => (
          <label
            key={option.value}
            className="flex items-center space-x-3 cursor-pointer hover:bg-gray-50 p-2 rounded-md"
          >
            <input
              type="checkbox"
              checked={selectedStatuses.includes(option.value)}
              onChange={() => handleStatusToggle(option.value)}
              className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <div className="flex-1">
              <div className="flex items-center space-x-2">
                <span
                  className={`inline-block w-3 h-3 rounded-full ${
                    option.color.split(" ")[2]
                  }`}
                />
                <span className="text-sm font-medium text-gray-900">
                  {option.label}
                </span>
              </div>
              <div className="text-xs text-gray-500">{option.description}</div>
            </div>
          </label>
        ))}
      </div>
    </div>
  );
};

/**
 * Product Search Component
 */
const ProductSearch: React.FC<{
  searchTerm: string;
  onChange: (searchTerm: string) => void;
}> = ({ searchTerm, onChange }) => {
  const [localSearchTerm, setLocalSearchTerm] = useState(searchTerm);
  const debouncedSearchTerm = useDebounce(
    localSearchTerm,
    DEFAULT_SEARCH_CONFIG.debounceMs
  );

  // Update parent when debounced value changes
  useEffect(() => {
    if (debouncedSearchTerm !== searchTerm) {
      onChange(debouncedSearchTerm);
    }
  }, [debouncedSearchTerm, searchTerm, onChange]);

  // Update local state when parent changes (e.g., clear filters)
  useEffect(() => {
    if (searchTerm !== localSearchTerm) {
      setLocalSearchTerm(searchTerm);
    }
  }, [searchTerm]);

  const handleClear = () => {
    setLocalSearchTerm("");
    onChange("");
  };

  return (
    <div className="space-y-2">
      <label className="block text-sm font-medium text-gray-700">
        Поиск товара
      </label>
      <div className="relative">
        <input
          type="text"
          placeholder="Search by product name or SKU..."
          value={localSearchTerm}
          onChange={(e) => setLocalSearchTerm(e.target.value)}
          className="w-full px-3 py-2 pl-10 pr-10 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          aria-label="Search products by name or SKU"
          aria-describedby="search-help"
        />
        <div id="search-help" className="sr-only">
          Type to search for products by name or SKU. Results will update
          automatically as you type.
        </div>

        {/* Search icon */}
        <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
          <svg
            className="w-4 h-4 text-gray-400"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
            />
          </svg>
        </div>

        {/* Clear button */}
        {localSearchTerm && (
          <button
            onClick={handleClear}
            className="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600"
          >
            <svg
              className="w-4 h-4"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
          </button>
        )}
      </div>

      {localSearchTerm &&
        localSearchTerm.length < DEFAULT_SEARCH_CONFIG.minLength && (
          <div className="text-xs text-gray-500">
            Type at least {DEFAULT_SEARCH_CONFIG.minLength} characters to search
          </div>
        )}
    </div>
  );
};

/**
 * Advanced Filters Component
 */
const AdvancedFilters: React.FC<{
  filters: FilterState;
  onChange: (filters: Partial<FilterState>) => void;
}> = ({ filters, onChange }) => {
  const [isExpanded, setIsExpanded] = useState(false);

  return (
    <div className="space-y-2">
      <button
        onClick={() => setIsExpanded(!isExpanded)}
        className="flex items-center justify-between w-full text-sm font-medium text-gray-700 hover:text-gray-900"
      >
        <span>Расширенные фильтры</span>
        <svg
          className={`w-4 h-4 transition-transform ${
            isExpanded ? "rotate-180" : ""
          }`}
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
      </button>

      {isExpanded && (
        <div className="space-y-4 pt-2">
          {/* Show only urgent items */}
          <label className="flex items-center space-x-2 cursor-pointer">
            <input
              type="checkbox"
              checked={filters.showOnlyUrgent}
              onChange={(e) => onChange({ showOnlyUrgent: e.target.checked })}
              className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
            />
            <span className="text-sm text-gray-700">
              Показать только срочные позиции
            </span>
          </label>

          {/* Show archived (zero stock) items */}
          <label className="flex items-center space-x-2 cursor-pointer">
            <input
              type="checkbox"
              checked={filters.showArchived}
              onChange={(e) => onChange({ showArchived: e.target.checked })}
              className="rounded border-gray-300 text-gray-600 focus:ring-gray-500"
            />
            <span className="text-sm text-gray-700">
              Показать архивные товары (нулевой остаток)
            </span>
          </label>

          {/* Days of stock range */}
          <div className="space-y-2">
            <label className="block text-sm font-medium text-gray-700">
              Диапазон дней запаса
            </label>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <input
                  type="number"
                  placeholder="Мин. дней"
                  value={filters.minDaysOfStock || ""}
                  onChange={(e) =>
                    onChange({
                      minDaysOfStock: e.target.value
                        ? parseInt(e.target.value)
                        : undefined,
                    })
                  }
                  className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
              </div>
              <div>
                <input
                  type="number"
                  placeholder="Макс. дней"
                  value={filters.maxDaysOfStock || ""}
                  onChange={(e) =>
                    onChange({
                      maxDaysOfStock: e.target.value
                        ? parseInt(e.target.value)
                        : undefined,
                    })
                  }
                  className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

/**
 * Main Filter Panel Component
 */
export const FilterPanel: React.FC<FilterPanelProps> = ({
  filters,
  onFilterChange,
  warehouses,
  loading,
}) => {
  const [isMobileExpanded, setIsMobileExpanded] = useState(false);
  // Handle individual filter changes
  const handleWarehouseChange = useCallback(
    (warehouses: string[]) => {
      onFilterChange({ ...filters, warehouses });
    },
    [filters, onFilterChange]
  );

  const handleStatusChange = useCallback(
    (statuses: StockStatus[]) => {
      onFilterChange({ ...filters, statuses });
    },
    [filters, onFilterChange]
  );

  const handleSearchChange = useCallback(
    (searchTerm: string) => {
      onFilterChange({ ...filters, searchTerm });
    },
    [filters, onFilterChange]
  );

  const handleAdvancedChange = useCallback(
    (advancedFilters: Partial<FilterState>) => {
      onFilterChange({ ...filters, ...advancedFilters });
    },
    [filters, onFilterChange]
  );

  // Clear all filters
  const handleClearAll = useCallback(() => {
    onFilterChange(DEFAULT_FILTERS);
  }, [onFilterChange]);

  // Check if any filters are active
  const hasActiveFilters = useMemo(() => {
    return (
      filters.warehouses.length > 0 ||
      filters.statuses.length > 0 ||
      filters.searchTerm.length > 0 ||
      filters.showOnlyUrgent ||
      filters.minDaysOfStock !== undefined ||
      filters.maxDaysOfStock !== undefined
    );
  }, [filters]);

  return (
    <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4 space-y-4 sm:space-y-6 hover-lift transition-smooth">
      {/* Header with mobile toggle */}
      <div className="flex items-center justify-between">
        <div className="flex items-center space-x-2">
          <h3 className="text-base sm:text-lg font-medium text-gray-900">
            Filters
          </h3>
          {hasActiveFilters && (
            <span className="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
              {
                [
                  filters.warehouses.length > 0 && filters.warehouses.length,
                  filters.statuses.length > 0 && filters.statuses.length,
                  filters.searchTerm && "search",
                  filters.showOnlyUrgent && "urgent",
                ].filter(Boolean).length
              }
            </span>
          )}
        </div>
        <div className="flex items-center space-x-2">
          {hasActiveFilters && (
            <button
              onClick={handleClearAll}
              className="text-xs sm:text-sm text-blue-600 hover:text-blue-800 px-2 py-1 rounded-md hover:bg-blue-50"
            >
              Clear All
            </button>
          )}
          {/* Mobile toggle button */}
          <button
            onClick={() => setIsMobileExpanded(!isMobileExpanded)}
            className="lg:hidden p-1 rounded-md text-gray-500 hover:text-gray-700 hover:bg-gray-100"
            aria-label={
              isMobileExpanded ? "Collapse filters" : "Expand filters"
            }
          >
            <svg
              className={`w-5 h-5 transition-transform ${
                isMobileExpanded ? "rotate-180" : ""
              }`}
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
          </button>
        </div>
      </div>

      {/* Collapsible filter content for mobile */}
      <div
        className={`space-y-4 sm:space-y-6 ${
          isMobileExpanded ? "block" : "hidden lg:block"
        }`}
      >
        {/* Product Search */}
        <ProductSearch
          searchTerm={filters.searchTerm}
          onChange={handleSearchChange}
        />

        {/* Warehouse Filter */}
        <WarehouseFilter
          warehouses={warehouses}
          selectedWarehouses={filters.warehouses}
          onChange={handleWarehouseChange}
          loading={loading}
        />

        {/* Status Filter */}
        <StatusFilter
          selectedStatuses={filters.statuses}
          onChange={handleStatusChange}
        />

        {/* Advanced Filters */}
        <AdvancedFilters filters={filters} onChange={handleAdvancedChange} />
      </div>

      {/* Active filters summary */}
      {hasActiveFilters && (
        <div className="pt-4 border-t border-gray-200">
          <div className="text-xs text-gray-500 mb-2">Активные фильтры:</div>
          <div className="space-y-1 text-xs">
            {filters.warehouses.length > 0 && (
              <div>Склады: выбрано {filters.warehouses.length}</div>
            )}
            {filters.statuses.length > 0 && (
              <div>Статус: {filters.statuses.join(", ")}</div>
            )}
            {filters.searchTerm && <div>Поиск: "{filters.searchTerm}"</div>}
            {filters.showOnlyUrgent && <div>Urgent items only</div>}
            {(filters.minDaysOfStock !== undefined ||
              filters.maxDaysOfStock !== undefined) && (
              <div>
                Days: {filters.minDaysOfStock || "0"} -{" "}
                {filters.maxDaysOfStock || "∞"}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default FilterPanel;
