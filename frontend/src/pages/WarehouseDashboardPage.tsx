import React, { useState, useMemo, useCallback } from "react";
import { LoadingSpinner } from "../components/ui/LoadingSpinner";
import { ErrorMessage } from "../components/ui/ErrorMessage";
import {
  DashboardHeader,
  WarehouseFilters,
  WarehouseTable,
  Pagination,
} from "../components/warehouse";
import { useWarehouseDashboardFull } from "../hooks/useWarehouse";
import type { FilterState, SortConfig } from "../types/warehouse";

/**
 * WarehouseDashboardPage - Main page for warehouse inventory management
 *
 * Features:
 * - Dashboard summary with key metrics
 * - Filtering by warehouse, cluster, liquidity status
 * - Sorting by various fields
 * - Pagination for large datasets
 * - Export to CSV
 * - Auto-refresh capability
 */
export const WarehouseDashboardPage: React.FC = () => {
  // State management for filters and sorting
  const [filters, setFilters] = useState<FilterState>({
    active_only: true,
  });

  const [sortConfig, setSortConfig] = useState<SortConfig>({
    field: "replenishment_need",
    order: "desc",
  });

  // State for pagination
  const [currentPage, setCurrentPage] = useState(1);
  const itemsPerPage = 100;

  // Memoize query parameters
  const queryParams = useMemo(
    () => ({
      ...filters,
      sort_by: sortConfig.field,
      sort_order: sortConfig.order,
      limit: itemsPerPage,
      offset: (currentPage - 1) * itemsPerPage,
      refetchInterval: 5 * 60 * 1000, // Auto-refresh every 5 minutes
    }),
    [filters, sortConfig, currentPage, itemsPerPage]
  );

  // Fetch dashboard data with all related information
  const {
    dashboard,
    warehouses,
    clusters,
    exportMutation,
    isLoading,
    hasError,
  } = useWarehouseDashboardFull(queryParams);

  // Memoize export parameters
  const exportParams = useMemo(
    () => ({
      warehouse: filters.warehouse,
      cluster: filters.cluster,
      liquidity_status: filters.liquidity_status,
      active_only: filters.active_only,
      has_replenishment_need: filters.has_replenishment_need,
    }),
    [filters]
  );

  // Handle refresh action (memoized)
  const handleRefresh = useCallback(() => {
    dashboard.refetch();
  }, [dashboard]);

  // Handle export action (memoized)
  const handleExport = useCallback(() => {
    exportMutation.mutate(exportParams);
  }, [exportMutation, exportParams]);

  // Handle filter changes (memoized)
  const handleFilterChange = useCallback((newFilters: FilterState) => {
    setFilters(newFilters);
    setCurrentPage(1); // Reset to first page when filters change
  }, []);

  // Handle sort changes (memoized)
  const handleSortChange = useCallback((newSortConfig: SortConfig) => {
    setSortConfig(newSortConfig);
    setCurrentPage(1); // Reset to first page when sort changes
  }, []);

  // Handle page changes (memoized)
  const handlePageChange = useCallback((page: number) => {
    setCurrentPage(page);
    // Scroll to top of table
    window.scrollTo({ top: 0, behavior: "smooth" });
  }, []);

  // Loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <LoadingSpinner size="xl" text="Загрузка данных дашборда..." />
      </div>
    );
  }

  // Error state
  if (hasError) {
    const error = dashboard.error || warehouses.error || clusters.error;
    return (
      <ErrorMessage
        error={error || "Не удалось загрузить данные"}
        title="Ошибка загрузки дашборда"
        onRetry={handleRefresh}
        showDetails={true}
      />
    );
  }

  // Empty state
  if (!dashboard.data || dashboard.data.warehouses.length === 0) {
    return (
      <div>
        <DashboardHeader
          summary={dashboard.data?.summary}
          lastUpdated={dashboard.data?.last_updated}
          onRefresh={handleRefresh}
          onExport={handleExport}
          isRefreshing={dashboard.isFetching}
        />
        <WarehouseFilters
          filters={filters}
          onChange={handleFilterChange}
          warehouses={warehouses.data}
          clusters={clusters.data}
        />
        <div className="bg-white rounded-lg shadow-md p-8 text-center">
          <svg
            className="mx-auto h-12 w-12 text-gray-400"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"
            />
          </svg>
          <h3 className="mt-2 text-sm font-medium text-gray-900">
            Нет данных для отображения
          </h3>
          <p className="mt-1 text-sm text-gray-500">
            Попробуйте изменить фильтры или обновить данные
          </p>
        </div>
      </div>
    );
  }

  // Main content
  return (
    <div className="space-y-6">
      <DashboardHeader
        summary={dashboard.data.summary}
        lastUpdated={dashboard.data.last_updated}
        onRefresh={handleRefresh}
        onExport={handleExport}
        isRefreshing={dashboard.isFetching}
      />

      <WarehouseFilters
        filters={filters}
        onChange={handleFilterChange}
        warehouses={warehouses.data}
        clusters={clusters.data}
      />

      <WarehouseTable
        data={dashboard.data.warehouses}
        sortConfig={sortConfig}
        onSort={handleSortChange}
      />

      {/* Pagination */}
      {dashboard.data.pagination && (
        <Pagination
          pagination={dashboard.data.pagination}
          onPageChange={handlePageChange}
        />
      )}

      {/* Export status notification */}
      {exportMutation.isPending && (
        <div className="fixed bottom-4 right-4 bg-blue-500 text-white px-6 py-3 rounded-lg shadow-lg">
          <div className="flex items-center">
            <LoadingSpinner size="sm" />
            <span className="ml-3">Экспорт данных...</span>
          </div>
        </div>
      )}

      {exportMutation.isSuccess && (
        <div className="fixed bottom-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg">
          <div className="flex items-center">
            <svg
              className="w-5 h-5 mr-2"
              fill="currentColor"
              viewBox="0 0 20 20"
            >
              <path
                fillRule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                clipRule="evenodd"
              />
            </svg>
            <span>Данные успешно экспортированы</span>
          </div>
        </div>
      )}

      {exportMutation.isError && (
        <div className="fixed bottom-4 right-4 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg">
          <div className="flex items-center">
            <svg
              className="w-5 h-5 mr-2"
              fill="currentColor"
              viewBox="0 0 20 20"
            >
              <path
                fillRule="evenodd"
                d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                clipRule="evenodd"
              />
            </svg>
            <span>Ошибка экспорта данных</span>
          </div>
        </div>
      )}
    </div>
  );
};

export default WarehouseDashboardPage;
