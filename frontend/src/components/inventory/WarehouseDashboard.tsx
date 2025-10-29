/**
 * Main Warehouse Dashboard Container Component
 *
 * This is the root component for the redesigned warehouse dashboard that manages
 * state and data fetching for the detailed product-warehouse inventory view.
 *
 * Requirements: 1.1, 7.4, 7.5
 */

import React, { useState, useEffect, useCallback, useMemo } from "react";
import type {
  ProductWarehouseItem,
  FilterState,
  LoadingState,
  DashboardSummary,
  StatusDistribution,
} from "../../types/inventory-dashboard";
import {
  useDetailedInventory,
  useWarehouses,
  useRefreshData,
  getErrorMessage,
  isRecoverableError,
} from "../../services";
import { useUrlState, useRealTimeUpdates } from "../../hooks";

// Import child components (will be created in subsequent tasks)
import { InventoryTable } from "./InventoryTable";
import { FilterPanel } from "./FilterPanel";
import { ExportControls } from "./ExportControls";
import { DashboardHeader } from "./DashboardHeader";
import { ErrorBoundary } from "../common/ErrorBoundary";
import { LoadingSpinner } from "../common/LoadingSpinner";

/**
 * Hook for managing dashboard state with URL persistence
 */
const useDashboardState = () => {
  const urlState = useUrlState();
  const [selectedRows, setSelectedRows] = useState<Set<string>>(new Set());
  const [loading, setLoading] = useState<LoadingState>({
    initial: true,
    refresh: false,
    filter: false,
    export: false,
  });

  return {
    ...urlState,
    selectedRows,
    setSelectedRows,
    loading,
    setLoading,
  };
};

/**
 * Main Warehouse Dashboard Component
 */
export const WarehouseDashboard: React.FC = () => {
  const {
    filters,
    setFilters,
    sortConfig,
    setSortConfig,
    hasActiveFilters,
    resetState,
    getShareableUrl,
    selectedRows,
    setSelectedRows,
    loading,
    setLoading,
  } = useDashboardState();

  // Use the new API service hooks
  const {
    data: response,
    isLoading,
    isError,
    error,
    isFetching,
  } = useDetailedInventory(filters, sortConfig);

  // Fetch warehouses for filter options
  const { data: warehouses = [], isLoading: warehousesLoading } =
    useWarehouses();

  // Manual refresh mutation
  const refreshMutation = useRefreshData();

  // Real-time updates
  const realTimeUpdates = useRealTimeUpdates({
    enabled: true,
    intervalMs: 4 * 60 * 60 * 1000, // 4 hours (data updates once daily)
    onUpdate: () => {
      console.log("Data automatically refreshed");
    },
    onError: (error) => {
      console.error("Auto-refresh failed:", error);
    },
  });

  // Update loading states based on query status
  useEffect(() => {
    setLoading((prev) => ({
      ...prev,
      initial: false, // Set to false after first render
      refresh: isFetching && !isLoading,
      filter: isFetching && !isLoading,
    }));
  }, [isLoading, isFetching, setLoading]);

  // Calculate dashboard summary from data
  const summary = useMemo<DashboardSummary | null>(() => {
    if (!response?.data) return null;

    const statusDistribution: StatusDistribution = {
      critical: 0,
      low: 0,
      normal: 0,
      excess: 0,
      no_sales: 0,
    };

    let totalRecommendedValue = 0;
    let urgentItems = 0;

    response.data.forEach((item) => {
      statusDistribution[item.status]++;
      totalRecommendedValue += item.recommendedValue;
      if (item.urgencyScore > 80) {
        urgentItems++;
      }
    });

    return {
      totalItems: response.metadata.totalCount,
      filteredItems: response.data.length,
      statusDistribution,
      totalRecommendedValue,
      urgentItems,
    };
  }, [response]);

  // Handle filter changes with debouncing for search
  const handleFilterChange = useCallback(
    (newFilters: FilterState) => {
      setFilters(newFilters);
      setSelectedRows(new Set()); // Clear selection when filters change
    },
    [setFilters, setSelectedRows]
  );

  // Handle sharing URL
  const handleShareUrl = useCallback(async () => {
    const url = getShareableUrl();
    try {
      await navigator.clipboard.writeText(url);
      // Could show a toast notification here
      console.log("URL copied to clipboard");
    } catch (error) {
      // Fallback for browsers that don't support clipboard API
      console.log("Shareable URL:", url);
    }
  }, [getShareableUrl]);

  // Handle sort changes
  const handleSortChange = useCallback(
    (column: keyof ProductWarehouseItem) => {
      const newDirection: "asc" | "desc" =
        sortConfig.column === column && sortConfig.direction === "asc"
          ? "desc"
          : "asc";
      setSortConfig({
        column,
        direction: newDirection,
      });
    },
    [sortConfig, setSortConfig]
  );

  // Handle manual refresh
  const handleRefresh = useCallback(async () => {
    setLoading((prev) => ({ ...prev, refresh: true }));
    try {
      await realTimeUpdates.manualRefresh();
    } finally {
      setLoading((prev) => ({ ...prev, refresh: false }));
    }
  }, [realTimeUpdates, setLoading]);

  // Handle row selection
  const handleRowSelection = useCallback(
    (itemId: string, selected: boolean) => {
      setSelectedRows((prev) => {
        const newSet = new Set(prev);
        if (selected) {
          newSet.add(itemId);
        } else {
          newSet.delete(itemId);
        }
        return newSet;
      });
    },
    [setSelectedRows]
  );

  // Handle bulk selection
  const handleBulkSelection = useCallback(
    (selectAll: boolean) => {
      if (selectAll && response?.data) {
        const allIds = response.data.map(
          (item) => `${item.productId}-${item.warehouseName}`
        );
        setSelectedRows(new Set(allIds));
      } else {
        setSelectedRows(new Set());
      }
    },
    [response?.data, setSelectedRows]
  );

  // Error boundary fallback with improved error handling
  if (isError) {
    const errorMessage = getErrorMessage(error);
    const canRetry = isRecoverableError(error);

    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center p-4">
        <div className="bg-white p-8 rounded-xl shadow-lg max-w-md w-full hover-lift transition-smooth">
          <div className="text-center mb-6">
            <div className="w-16 h-16 mx-auto mb-4 bg-red-100 rounded-full flex items-center justify-center">
              <svg
                className="w-8 h-8 text-red-600"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  strokeLinecap="round"
                  strokeLinejoin="round"
                  strokeWidth={2}
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"
                />
              </svg>
            </div>
            <h3 className="text-xl font-semibold text-gray-900 mb-3">
              Failed to Load Dashboard
            </h3>
            <p className="text-gray-600 mb-6">{errorMessage}</p>
          </div>

          <div className="space-y-3">
            {canRetry && (
              <button
                onClick={handleRefresh}
                className="w-full btn-primary text-white py-3 px-4 rounded-lg font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                disabled={loading.refresh || refreshMutation.isPending}
              >
                {loading.refresh || refreshMutation.isPending ? (
                  <div className="flex items-center justify-center space-x-2">
                    <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                    <span>Retrying...</span>
                  </div>
                ) : (
                  "Try Again"
                )}
              </button>
            )}

            <button
              onClick={() => window.location.reload()}
              className="w-full bg-gray-100 text-gray-700 py-3 px-4 rounded-lg font-medium hover:bg-gray-200 transition-colors"
            >
              Refresh Page
            </button>
          </div>

          {!canRetry && (
            <div className="mt-6 p-4 bg-gray-50 rounded-lg">
              <p className="text-sm text-gray-600 text-center">
                This error cannot be automatically resolved. Please contact
                support if this problem persists.
              </p>
            </div>
          )}
        </div>
      </div>
    );
  }

  // Initial loading state
  if (loading.initial || isLoading) {
    return (
      <div className="min-h-screen bg-gray-50 flex items-center justify-center">
        <LoadingSpinner size="lg" message="Loading warehouse dashboard..." />
      </div>
    );
  }

  return (
    <ErrorBoundary>
      <div className="min-h-screen bg-gray-50">
        {/* Dashboard Header */}
        <DashboardHeader
          summary={summary}
          lastUpdated={realTimeUpdates.getLastUpdateFormatted()}
          onRefresh={handleRefresh}
          loading={loading.refresh || realTimeUpdates.isManualRefreshing}
          hasActiveFilters={hasActiveFilters}
          onResetFilters={resetState}
          onShareUrl={handleShareUrl}
          autoRefreshEnabled={realTimeUpdates.isAutoRefreshEnabled}
          onToggleAutoRefresh={realTimeUpdates.toggleAutoRefresh}
          nextUpdateCountdown={realTimeUpdates.getTimeUntilNextUpdateFormatted()}
        />

        <div className="max-w-7xl mx-auto px-2 sm:px-4 lg:px-8 py-4 sm:py-6">
          {/* Mobile-first responsive layout */}
          <div className="flex flex-col lg:grid lg:grid-cols-4 gap-4 lg:gap-6">
            {/* Filter Panel - Mobile: Collapsible, Desktop: Left Sidebar */}
            <div className="lg:col-span-1 order-2 lg:order-1">
              <div className="lg:sticky lg:top-6">
                <FilterPanel
                  filters={filters}
                  onFilterChange={handleFilterChange}
                  warehouses={warehouses}
                  loading={loading.filter || warehousesLoading}
                />
              </div>
            </div>

            {/* Main Content Area */}
            <div className="lg:col-span-3 space-y-4 lg:space-y-6 order-1 lg:order-2">
              {/* Export Controls - Mobile: Compact, Desktop: Full */}
              <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-3 sm:p-4">
                <ExportControls
                  data={response?.data || []}
                  filters={filters}
                  selectedRows={selectedRows}
                  loading={loading.export}
                />
              </div>

              {/* Inventory Table - Mobile: Horizontal scroll, Desktop: Full width */}
              <div className="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
                <InventoryTable
                  data={response?.data || []}
                  sortConfig={sortConfig}
                  onSort={handleSortChange}
                  selectedRows={selectedRows}
                  onRowSelection={handleRowSelection}
                  onBulkSelection={handleBulkSelection}
                  loading={loading.filter}
                />
              </div>
            </div>
          </div>
        </div>
      </div>
    </ErrorBoundary>
  );
};

export default WarehouseDashboard;
