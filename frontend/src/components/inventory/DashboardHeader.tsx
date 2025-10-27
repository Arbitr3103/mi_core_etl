/**
 * Dashboard Header Component
 *
 * Displays dashboard title, summary statistics, and refresh controls.
 *
 * Requirements: 1.1, 7.4
 */

import React from "react";
import type { DashboardSummary } from "../../types/inventory-dashboard";
import { LoadingSpinner } from "../common/LoadingSpinner";

interface DashboardHeaderProps {
  summary: DashboardSummary | null;
  lastUpdated?: string;
  onRefresh: () => void;
  loading: boolean;
  hasActiveFilters?: boolean;
  onResetFilters?: () => void;
  onShareUrl?: () => void;
  autoRefreshEnabled?: boolean;
  onToggleAutoRefresh?: () => void;
  nextUpdateCountdown?: string | null;
}

export const DashboardHeader: React.FC<DashboardHeaderProps> = ({
  summary,
  lastUpdated,
  onRefresh,
  loading,
  hasActiveFilters = false,
  onResetFilters,
  onShareUrl,
  autoRefreshEnabled = false,
  onToggleAutoRefresh,
  nextUpdateCountdown,
}) => {
  const formatLastUpdated = (timestamp?: string) => {
    if (!timestamp) return "Never";

    const date = new Date(timestamp);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / (1000 * 60));

    if (diffMins < 1) return "Just now";
    if (diffMins < 60) return `${diffMins} minutes ago`;

    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours} hours ago`;

    return date.toLocaleDateString();
  };

  const formatCurrency = (value: number) => {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: "USD",
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(value);
  };

  return (
    <div className="bg-white border-b border-gray-200">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        {/* Header Title and Controls */}
        <div className="flex items-center justify-between mb-6">
          <div>
            <h1 className="text-2xl font-bold text-gray-900">
              Складской дашборд
            </h1>
            <p className="text-sm text-gray-600 mt-1">
              Детальный мониторинг инвентаря и рекомендации по пополнению
            </p>
          </div>

          <div className="flex items-center space-x-4">
            {/* Last Updated and Auto-refresh Status */}
            <div className="text-sm text-gray-500">
              <div className="flex items-center space-x-2">
                <span className="font-medium">Последнее обновление:</span>
                <span>
                  {typeof lastUpdated === "string"
                    ? lastUpdated
                    : formatLastUpdated(lastUpdated)}
                </span>
                {autoRefreshEnabled && nextUpdateCountdown && (
                  <span className="text-blue-600">
                    (next in {nextUpdateCountdown})
                  </span>
                )}
              </div>
            </div>

            {/* Filter Status and Reset */}
            {hasActiveFilters && onResetFilters && (
              <button
                onClick={onResetFilters}
                className="inline-flex items-center px-3 py-1 border border-orange-300 rounded-md text-sm font-medium text-orange-700 bg-orange-50 hover:bg-orange-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500"
              >
                <svg
                  className="w-4 h-4 mr-1"
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
                Очистить фильтры
              </button>
            )}

            {/* Auto-refresh Toggle */}
            {onToggleAutoRefresh && (
              <button
                onClick={onToggleAutoRefresh}
                className={`inline-flex items-center px-3 py-2 border rounded-md shadow-sm text-sm font-medium focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 ${
                  autoRefreshEnabled
                    ? "border-green-300 text-green-700 bg-green-50 hover:bg-green-100"
                    : "border-gray-300 text-gray-700 bg-white hover:bg-gray-50"
                }`}
              >
                <svg
                  className="w-4 h-4 mr-2"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                  />
                </svg>
                Auto-refresh {autoRefreshEnabled ? "ON" : "OFF"}
              </button>
            )}

            {/* Share URL Button */}
            {onShareUrl && (
              <button
                onClick={onShareUrl}
                className="inline-flex items-center px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
              >
                <svg
                  className="w-4 h-4 mr-2"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    strokeWidth={2}
                    d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.367 2.684 3 3 0 00-5.367-2.684z"
                  />
                </svg>
                Share
              </button>
            )}

            {/* Refresh Button */}
            <button
              onClick={onRefresh}
              disabled={loading}
              className="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {loading ? (
                <>
                  <LoadingSpinner size="sm" className="mr-2" />
                  Refreshing...
                </>
              ) : (
                <>
                  <svg
                    className="w-4 h-4 mr-2"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                  >
                    <path
                      strokeLinecap="round"
                      strokeLinejoin="round"
                      strokeWidth={2}
                      d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                    />
                  </svg>
                  Refresh
                </>
              )}
            </button>
          </div>
        </div>

        {/* Summary Statistics */}
        {summary && (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            {/* Total Items */}
            <div className="bg-gray-50 rounded-lg p-4">
              <div className="text-2xl font-bold text-gray-900">
                {summary.totalItems.toLocaleString()}
              </div>
              <div className="text-sm text-gray-600">Всего позиций</div>
              {summary.filteredItems !== summary.totalItems && (
                <div className="text-xs text-blue-600 mt-1">
                  {summary.filteredItems.toLocaleString()} filtered
                </div>
              )}
            </div>

            {/* Critical Items */}
            <div className="bg-red-50 rounded-lg p-4">
              <div className="text-2xl font-bold text-red-900">
                {summary.statusDistribution.critical.toLocaleString()}
              </div>
              <div className="text-sm text-red-700">Критический запас</div>
              <div className="text-xs text-red-600 mt-1">&lt; 14 days</div>
            </div>

            {/* Low Items */}
            <div className="bg-yellow-50 rounded-lg p-4">
              <div className="text-2xl font-bold text-yellow-900">
                {summary.statusDistribution.low.toLocaleString()}
              </div>
              <div className="text-sm text-yellow-700">Низкий запас</div>
              <div className="text-xs text-yellow-600 mt-1">14-30 days</div>
            </div>

            {/* Urgent Items */}
            <div className="bg-orange-50 rounded-lg p-4">
              <div className="text-2xl font-bold text-orange-900">
                {summary.urgentItems.toLocaleString()}
              </div>
              <div className="text-sm text-orange-700">Срочные действия</div>
              <div className="text-xs text-orange-600 mt-1">
                Высокий приоритет
              </div>
            </div>

            {/* Recommended Value */}
            <div className="bg-blue-50 rounded-lg p-4">
              <div className="text-2xl font-bold text-blue-900">
                {formatCurrency(summary.totalRecommendedValue)}
              </div>
              <div className="text-sm text-blue-700">Сумма пополнения</div>
              <div className="text-xs text-blue-600 mt-1">
                Всего рекомендовано
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default DashboardHeader;
