import React from "react";
import type { DashboardSummary } from "../../types/warehouse";
import { Button } from "../ui/Button";

export interface DashboardHeaderProps {
  summary?: DashboardSummary;
  lastUpdated?: string;
  onRefresh: () => void;
  onExport: () => void;
  isRefreshing?: boolean;
}

export const DashboardHeader: React.FC<DashboardHeaderProps> = ({
  summary,
  lastUpdated,
  onRefresh,
  onExport,
  isRefreshing = false,
}) => {
  const formatLastUpdated = (dateString?: string): string => {
    if (!dateString) return "Неизвестно";

    try {
      const date = new Date(dateString);
      return new Intl.DateTimeFormat("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      }).format(date);
    } catch {
      return "Неизвестно";
    }
  };

  return (
    <div className="bg-white rounded-lg shadow-md p-6">
      <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between mb-6">
        <div>
          <p className="text-sm text-gray-600">
            Последнее обновление: {formatLastUpdated(lastUpdated)}
          </p>
        </div>

        <div className="flex items-center gap-3 mt-4 lg:mt-0">
          <Button
            variant="secondary"
            onClick={onRefresh}
            isLoading={isRefreshing}
            disabled={isRefreshing}
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
            Обновить данные
          </Button>

          <Button variant="primary" onClick={onExport}>
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
                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
              />
            </svg>
            Экспорт в CSV
          </Button>
        </div>
      </div>

      {summary && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Total products */}
          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex flex-col">
              <span className="text-sm font-medium text-gray-500">
                Всего товаров
              </span>
              <span className="mt-2 text-3xl font-bold text-gray-900">
                {summary.total_products.toLocaleString("ru-RU")}
              </span>
            </div>
          </div>

          {/* Active products */}
          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex flex-col">
              <span className="text-sm font-medium text-gray-500">
                Активных
              </span>
              <span className="mt-2 text-3xl font-bold text-blue-600">
                {summary.active_products.toLocaleString("ru-RU")}
              </span>
            </div>
          </div>

          {/* Critical status */}
          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex flex-col">
              <span className="text-sm font-medium text-gray-500">Дефицит</span>
              <span className="mt-2 text-3xl font-bold text-red-600">
                {summary.by_liquidity.critical.toLocaleString("ru-RU")}
              </span>
            </div>
          </div>

          {/* Low status */}
          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex flex-col">
              <span className="text-sm font-medium text-gray-500">
                Низкий запас
              </span>
              <span className="mt-2 text-3xl font-bold text-yellow-600">
                {summary.by_liquidity.low.toLocaleString("ru-RU")}
              </span>
            </div>
          </div>

          {/* Normal status */}
          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex flex-col">
              <span className="text-sm font-medium text-gray-500">Норма</span>
              <span className="mt-2 text-3xl font-bold text-green-600">
                {summary.by_liquidity.normal.toLocaleString("ru-RU")}
              </span>
            </div>
          </div>

          {/* Excess status */}
          <div className="bg-white rounded-lg shadow p-6">
            <div className="flex flex-col">
              <span className="text-sm font-medium text-gray-500">Избыток</span>
              <span className="mt-2 text-3xl font-bold text-blue-600">
                {summary.by_liquidity.excess.toLocaleString("ru-RU")}
              </span>
            </div>
          </div>
        </div>
      )}

      {summary && summary.total_replenishment_need > 0 && (
        <div className="mt-4 p-4 bg-orange-50 border border-orange-200 rounded-lg">
          <div className="flex items-center">
            <svg
              className="w-5 h-5 text-orange-600 mr-2"
              fill="currentColor"
              viewBox="0 0 20 20"
            >
              <path
                fillRule="evenodd"
                d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                clipRule="evenodd"
              />
            </svg>
            <span className="text-sm font-medium text-orange-900">
              Общая потребность в пополнении:{" "}
              <span className="font-bold">
                {summary.total_replenishment_need.toLocaleString("ru-RU")} шт.
              </span>
            </span>
          </div>
        </div>
      )}
    </div>
  );
};
