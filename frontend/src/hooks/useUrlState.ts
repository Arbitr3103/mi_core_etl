/**
 * URL State Management Hook
 *
 * Provides URL-based state management for filters and sorting configuration
 * with browser history support and persistence across page refreshes.
 *
 * Requirements: 3.4, 4.4, 4.5
 */

import { useState, useEffect, useCallback, useMemo } from "react";
import { useSearchParams } from "react-router-dom";
import type {
  FilterState,
  SortConfig,
  StockStatus,
} from "../types/inventory-dashboard";
import { DEFAULT_FILTERS, DEFAULT_SORT } from "../types/inventory-dashboard";

/**
 * URL parameter keys
 */
const URL_PARAMS = {
  WAREHOUSES: "warehouses",
  STATUSES: "statuses",
  SEARCH: "search",
  URGENT_ONLY: "urgent",
  MIN_DAYS: "minDays",
  MAX_DAYS: "maxDays",
  SORT_BY: "sortBy",
  SORT_ORDER: "sortOrder",
} as const;

/**
 * Serialize filter state to URL parameters
 */
const serializeFilters = (filters: FilterState): Record<string, string> => {
  const params: Record<string, string> = {};

  if (filters.warehouses.length > 0) {
    params[URL_PARAMS.WAREHOUSES] = filters.warehouses.join(",");
  }

  if (filters.statuses.length > 0) {
    params[URL_PARAMS.STATUSES] = filters.statuses.join(",");
  }

  if (filters.searchTerm.trim()) {
    params[URL_PARAMS.SEARCH] = filters.searchTerm.trim();
  }

  if (filters.showOnlyUrgent) {
    params[URL_PARAMS.URGENT_ONLY] = "true";
  }

  if (filters.minDaysOfStock !== undefined) {
    params[URL_PARAMS.MIN_DAYS] = filters.minDaysOfStock.toString();
  }

  if (filters.maxDaysOfStock !== undefined) {
    params[URL_PARAMS.MAX_DAYS] = filters.maxDaysOfStock.toString();
  }

  return params;
};

/**
 * Deserialize URL parameters to filter state
 */
const deserializeFilters = (searchParams: URLSearchParams): FilterState => {
  const warehouses = searchParams.get(URL_PARAMS.WAREHOUSES);
  const statuses = searchParams.get(URL_PARAMS.STATUSES);
  const search = searchParams.get(URL_PARAMS.SEARCH);
  const urgent = searchParams.get(URL_PARAMS.URGENT_ONLY);
  const minDays = searchParams.get(URL_PARAMS.MIN_DAYS);
  const maxDays = searchParams.get(URL_PARAMS.MAX_DAYS);

  return {
    warehouses: warehouses ? warehouses.split(",").filter(Boolean) : [],
    statuses: statuses
      ? (statuses.split(",").filter(Boolean) as StockStatus[])
      : [],
    searchTerm: search || "",
    showOnlyUrgent: urgent === "true",
    showArchived: false,
    minDaysOfStock: minDays ? parseInt(minDays, 10) : undefined,
    maxDaysOfStock: maxDays ? parseInt(maxDays, 10) : undefined,
  };
};

/**
 * Serialize sort configuration to URL parameters
 */
const serializeSort = (sortConfig: SortConfig): Record<string, string> => {
  const params: Record<string, string> = {};

  // Only include sort params if they differ from defaults
  if (sortConfig.column !== DEFAULT_SORT.column) {
    params[URL_PARAMS.SORT_BY] = sortConfig.column;
  }

  if (sortConfig.direction !== DEFAULT_SORT.direction) {
    params[URL_PARAMS.SORT_ORDER] = sortConfig.direction;
  }

  return params;
};

/**
 * Deserialize URL parameters to sort configuration
 */
const deserializeSort = (searchParams: URLSearchParams): SortConfig => {
  const sortBy = searchParams.get(URL_PARAMS.SORT_BY);
  const sortOrder = searchParams.get(URL_PARAMS.SORT_ORDER);

  return {
    column: (sortBy as keyof typeof DEFAULT_SORT) || DEFAULT_SORT.column,
    direction: (sortOrder as "asc" | "desc") || DEFAULT_SORT.direction,
  };
};

/**
 * Validate filter values
 */
const validateFilters = (filters: FilterState): FilterState => {
  return {
    ...filters,
    warehouses: filters.warehouses.filter(Boolean),
    statuses: filters.statuses.filter((status) =>
      ["critical", "low", "normal", "excess", "no_sales"].includes(status)
    ),
    searchTerm: filters.searchTerm.slice(0, 100), // Limit search term length
    minDaysOfStock:
      filters.minDaysOfStock !== undefined && filters.minDaysOfStock >= 0
        ? filters.minDaysOfStock
        : undefined,
    maxDaysOfStock:
      filters.maxDaysOfStock !== undefined && filters.maxDaysOfStock >= 0
        ? filters.maxDaysOfStock
        : undefined,
  };
};

/**
 * Validate sort configuration
 */
const validateSort = (sortConfig: SortConfig): SortConfig => {
  const validColumns = [
    "productName",
    "sku",
    "warehouseName",
    "currentStock",
    "dailySales",
    "daysOfStock",
    "status",
    "recommendedQty",
    "recommendedValue",
    "urgencyScore",
    "lastUpdated",
  ];

  return {
    column: validColumns.includes(sortConfig.column)
      ? sortConfig.column
      : DEFAULT_SORT.column,
    direction: ["asc", "desc"].includes(sortConfig.direction)
      ? sortConfig.direction
      : DEFAULT_SORT.direction,
  };
};

/**
 * Hook for URL-based state management
 */
export const useUrlState = () => {
  const [searchParams, setSearchParams] = useSearchParams();

  // Initialize state from URL parameters
  const initialFilters = useMemo(() => {
    return validateFilters(deserializeFilters(searchParams));
  }, [searchParams]);

  const initialSort = useMemo(() => {
    return validateSort(deserializeSort(searchParams));
  }, [searchParams]);

  const [filters, setFiltersState] = useState<FilterState>(initialFilters);
  const [sortConfig, setSortConfigState] = useState<SortConfig>(initialSort);

  // Update URL when state changes
  const updateUrl = useCallback(
    (
      newFilters: FilterState,
      newSort: SortConfig,
      replace: boolean = false
    ) => {
      const params = new URLSearchParams();

      // Add filter parameters
      const filterParams = serializeFilters(newFilters);
      Object.entries(filterParams).forEach(([key, value]) => {
        params.set(key, value);
      });

      // Add sort parameters
      const sortParams = serializeSort(newSort);
      Object.entries(sortParams).forEach(([key, value]) => {
        params.set(key, value);
      });

      // Update URL
      setSearchParams(params, { replace });
    },
    [setSearchParams]
  );

  // Update filters with URL sync
  const setFilters = useCallback(
    (newFilters: FilterState) => {
      const validatedFilters = validateFilters(newFilters);
      setFiltersState(validatedFilters);
      updateUrl(validatedFilters, sortConfig);
    },
    [sortConfig, updateUrl]
  );

  // Update sort config with URL sync
  const setSortConfig = useCallback(
    (newSort: SortConfig) => {
      const validatedSort = validateSort(newSort);
      setSortConfigState(validatedSort);
      updateUrl(filters, validatedSort);
    },
    [filters, updateUrl]
  );

  // Reset to defaults
  const resetState = useCallback(() => {
    setFiltersState(DEFAULT_FILTERS);
    setSortConfigState(DEFAULT_SORT);
    setSearchParams(new URLSearchParams(), { replace: true });
  }, [setSearchParams]);

  // Check if current state differs from defaults
  const hasActiveFilters = useMemo(() => {
    return (
      filters.warehouses.length > 0 ||
      filters.statuses.length > 0 ||
      filters.searchTerm.trim() !== "" ||
      filters.showOnlyUrgent ||
      filters.minDaysOfStock !== undefined ||
      filters.maxDaysOfStock !== undefined ||
      sortConfig.column !== DEFAULT_SORT.column ||
      sortConfig.direction !== DEFAULT_SORT.direction
    );
  }, [filters, sortConfig]);

  // Sync state with URL changes (browser back/forward)
  useEffect(() => {
    const urlFilters = validateFilters(deserializeFilters(searchParams));
    const urlSort = validateSort(deserializeSort(searchParams));

    // Only update state if URL actually changed
    const filtersChanged =
      JSON.stringify(urlFilters) !== JSON.stringify(filters);
    const sortChanged = JSON.stringify(urlSort) !== JSON.stringify(sortConfig);

    if (filtersChanged) {
      setFiltersState(urlFilters);
    }

    if (sortChanged) {
      setSortConfigState(urlSort);
    }
  }, [searchParams]); // Don't include filters/sortConfig to avoid infinite loops

  // Generate shareable URL
  const getShareableUrl = useCallback(() => {
    const url = new URL(window.location.href);
    const params = new URLSearchParams();

    // Add current filter and sort parameters
    const filterParams = serializeFilters(filters);
    const sortParams = serializeSort(sortConfig);

    Object.entries({ ...filterParams, ...sortParams }).forEach(
      ([key, value]) => {
        params.set(key, value);
      }
    );

    url.search = params.toString();
    return url.toString();
  }, [filters, sortConfig]);

  return {
    // State
    filters,
    sortConfig,
    hasActiveFilters,

    // State setters
    setFilters,
    setSortConfig,
    resetState,

    // Utilities
    getShareableUrl,
  };
};

/**
 * Hook for debounced URL updates (useful for search input)
 */
export const useDebouncedUrlState = (debounceMs: number = 300) => {
  const urlState = useUrlState();
  const [debouncedFilters, setDebouncedFilters] = useState(urlState.filters);

  // Debounce filter updates
  useEffect(() => {
    const timer = setTimeout(() => {
      if (
        JSON.stringify(debouncedFilters) !== JSON.stringify(urlState.filters)
      ) {
        urlState.setFilters(debouncedFilters);
      }
    }, debounceMs);

    return () => clearTimeout(timer);
  }, [debouncedFilters, debounceMs, urlState]);

  return {
    ...urlState,
    filters: debouncedFilters,
    setFilters: setDebouncedFilters,
  };
};

export default useUrlState;
