/**
 * ETLStatusList Component
 *
 * Displays a list of ETL processes with their current status.
 * Provides filtering, sorting, and action capabilities.
 *
 * Requirements: 9.1, 9.2, 9.3
 */

import React, { useState, useMemo } from "react";
import type { AnalyticsETLStatus, ETLStatus, SyncType } from "../../types";
import { AnalyticsETLStatusIndicator } from "./AnalyticsETLStatusIndicator";

interface ETLStatusListProps {
  statuses: AnalyticsETLStatus[];
  onRetry?: (syncId: number) => void;
  onCancel?: (syncId: number) => void;
  onViewDetails?: (status: AnalyticsETLStatus) => void;
  showFilters?: boolean;
  maxItems?: number;
  className?: string;
}

interface FilterState {
  status?: ETLStatus;
  syncType?: SyncType;
  source?: string;
  search?: string;
}

/**
 * ETLStatusList Component
 */
export const ETLStatusList: React.FC<ETLStatusListProps> = ({
  statuses,
  onRetry,
  onCancel,
  onViewDetails,
  showFilters = true,
  maxItems,
  className = "",
}) => {
  const [filters, setFilters] = useState<FilterState>({});
  const [sortBy, setSortBy] = useState<"started_at" | "status" | "source_name">(
    "started_at"
  );
  const [sortOrder, setSortOrder] = useState<"asc" | "desc">("desc");

  // Filter and sort statuses
  const filteredAndSortedStatuses = useMemo(() => {
    let filtered = [...statuses];

    // Apply filters
    if (filters.status) {
      filtered = filtered.filter((s) => s.status === filters.status);
    }
    if (filters.syncType) {
      filtered = filtered.filter((s) => s.sync_type === filters.syncType);
    }
    if (filters.source) {
      filtered = filtered.filter((s) =>
        s.source_name.toLowerCase().includes(filters.source!.toLowerCase())
      );
    }
    if (filters.search) {
      const search = filters.search.toLowerCase();
      filtered = filtered.filter(
        (s) =>
          s.source_name.toLowerCase().includes(search) ||
          s.sync_type.toLowerCase().includes(search) ||
          s.batch_id?.toLowerCase().includes(search) ||
          s.error_message?.toLowerCase().includes(search)
      );
    }

    // Sort
    filtered.sort((a, b) => {
      let aValue: string | number | Date = a[sortBy];
      let bValue: string | number | Date = b[sortBy];

      if (sortBy === "started_at") {
        aValue = new Date(aValue).getTime();
        bValue = new Date(bValue).getTime();
      }

      if (sortOrder === "asc") {
        return aValue > bValue ? 1 : -1;
      } else {
        return aValue < bValue ? 1 : -1;
      }
    });

    // Limit items if specified
    if (maxItems) {
      filtered = filtered.slice(0, maxItems);
    }

    return filtered;
  }, [statuses, filters, sortBy, sortOrder, maxItems]);

  // Get unique values for filter options
  const uniqueStatuses = useMemo(
    () => [...new Set(statuses.map((s) => s.status))],
    [statuses]
  );
  const uniqueSyncTypes = useMemo(
    () => [...new Set(statuses.map((s) => s.sync_type))],
    [statuses]
  );
  const uniqueSources = useMemo(
    () => [...new Set(statuses.map((s) => s.source_name))],
    [statuses]
  );

  const handleSort = (field: typeof sortBy) => {
    if (sortBy === field) {
      setSortOrder(sortOrder === "asc" ? "desc" : "asc");
    } else {
      setSortBy(field);
      setSortOrder("desc");
    }
  };

  const getSortIcon = (field: typeof sortBy) => {
    if (sortBy !== field) return "↕️";
    return sortOrder === "asc" ? "↑" : "↓";
  };

  return (
    <div className={`space-y-4 ${className}`}>
      {/* Filters */}
      {showFilters && (
        <div className="bg-gray-50 p-4 rounded-lg">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            {/* Search */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Поиск
              </label>
              <input
                type="text"
                value={filters.search || ""}
                onChange={(e) =>
                  setFilters({ ...filters, search: e.target.value })
                }
                placeholder="Поиск по источнику, типу..."
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>

            {/* Status Filter */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Статус
              </label>
              <select
                value={filters.status || ""}
                onChange={(e) =>
                  setFilters({
                    ...filters,
                    status: (e.target.value as ETLStatus) || undefined,
                  })
                }
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Все статусы</option>
                {uniqueStatuses.map((status) => (
                  <option key={status} value={status}>
                    {status === "pending" && "Ожидание"}
                    {status === "running" && "Выполняется"}
                    {status === "completed" && "Завершен"}
                    {status === "failed" && "Ошибка"}
                    {status === "retrying" && "Повтор"}
                    {status === "cancelled" && "Отменен"}
                  </option>
                ))}
              </select>
            </div>

            {/* Sync Type Filter */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Тип синхронизации
              </label>
              <select
                value={filters.syncType || ""}
                onChange={(e) =>
                  setFilters({
                    ...filters,
                    syncType: (e.target.value as SyncType) || undefined,
                  })
                }
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Все типы</option>
                {uniqueSyncTypes.map((type) => (
                  <option key={type} value={type}>
                    {type === "api_full" && "Полная API"}
                    {type === "api_incremental" && "Инкрементальная API"}
                    {type === "ui_import" && "Импорт UI"}
                    {type === "manual_sync" && "Ручная"}
                  </option>
                ))}
              </select>
            </div>

            {/* Source Filter */}
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                Источник
              </label>
              <select
                value={filters.source || ""}
                onChange={(e) =>
                  setFilters({
                    ...filters,
                    source: e.target.value || undefined,
                  })
                }
                className="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">Все источники</option>
                {uniqueSources.map((source) => (
                  <option key={source} value={source}>
                    {source}
                  </option>
                ))}
              </select>
            </div>
          </div>

          {/* Clear Filters */}
          {(filters.search ||
            filters.status ||
            filters.syncType ||
            filters.source) && (
            <div className="mt-3">
              <button
                onClick={() => setFilters({})}
                className="text-sm text-blue-600 hover:text-blue-800"
              >
                Очистить фильтры
              </button>
            </div>
          )}
        </div>
      )}

      {/* Results Summary */}
      <div className="flex justify-between items-center">
        <div className="text-sm text-gray-600">
          Показано {filteredAndSortedStatuses.length} из {statuses.length}{" "}
          процессов
        </div>

        {/* Sort Controls */}
        <div className="flex items-center gap-2 text-sm">
          <span className="text-gray-600">Сортировка:</span>
          <button
            onClick={() => handleSort("started_at")}
            className={`px-2 py-1 rounded ${
              sortBy === "started_at"
                ? "bg-blue-100 text-blue-700"
                : "text-gray-600 hover:bg-gray-100"
            }`}
          >
            Время {getSortIcon("started_at")}
          </button>
          <button
            onClick={() => handleSort("status")}
            className={`px-2 py-1 rounded ${
              sortBy === "status"
                ? "bg-blue-100 text-blue-700"
                : "text-gray-600 hover:bg-gray-100"
            }`}
          >
            Статус {getSortIcon("status")}
          </button>
          <button
            onClick={() => handleSort("source_name")}
            className={`px-2 py-1 rounded ${
              sortBy === "source_name"
                ? "bg-blue-100 text-blue-700"
                : "text-gray-600 hover:bg-gray-100"
            }`}
          >
            Источник {getSortIcon("source_name")}
          </button>
        </div>
      </div>

      {/* Status List */}
      <div className="space-y-2">
        {filteredAndSortedStatuses.length === 0 ? (
          <div className="text-center py-8 text-gray-500">
            <div className="text-4xl mb-2">📭</div>
            <div>Нет ETL процессов для отображения</div>
            {Object.keys(filters).some(
              (key) => filters[key as keyof FilterState]
            ) && (
              <div className="text-sm mt-1">Попробуйте изменить фильтры</div>
            )}
          </div>
        ) : (
          filteredAndSortedStatuses.map((status) => (
            <div
              key={status.id}
              className="bg-white border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow"
            >
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-4 flex-1">
                  {/* Status Indicator */}
                  <AnalyticsETLStatusIndicator
                    status={status}
                    showProgress={true}
                    showDetails={true}
                    onRetry={onRetry}
                    onCancel={onCancel}
                  />

                  {/* Process Info */}
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <span className="font-medium text-gray-900">
                        {status.source_name}
                      </span>
                      <span className="text-sm text-gray-500">
                        ({status.sync_type === "api_full" && "Полная API"}
                        {status.sync_type === "api_incremental" &&
                          "Инкрементальная API"}
                        {status.sync_type === "ui_import" && "Импорт UI"}
                        {status.sync_type === "manual_sync" && "Ручная"})
                      </span>
                    </div>

                    <div className="text-sm text-gray-600 mt-1">
                      Начато: {new Date(status.started_at).toLocaleString()}
                      {status.batch_id && (
                        <span className="ml-2">• Batch: {status.batch_id}</span>
                      )}
                    </div>
                  </div>
                </div>

                {/* Actions */}
                <div className="flex items-center gap-2">
                  {onViewDetails && (
                    <button
                      onClick={() => onViewDetails(status)}
                      className="px-3 py-1 text-sm bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition-colors"
                    >
                      Детали
                    </button>
                  )}
                </div>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Load More */}
      {maxItems && statuses.length > maxItems && (
        <div className="text-center">
          <button
            onClick={() => {
              /* Handle load more */
            }}
            className="px-4 py-2 text-sm bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition-colors"
          >
            Показать еще ({statuses.length - maxItems} процессов)
          </button>
        </div>
      )}
    </div>
  );
};

export default ETLStatusList;
