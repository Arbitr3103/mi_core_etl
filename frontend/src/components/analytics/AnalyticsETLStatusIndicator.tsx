/**
 * AnalyticsETLStatusIndicator Component
 *
 * Displays the status of Analytics ETL processes with visual indicators
 * and detailed tooltips. Supports running, completed, failed states.
 *
 * Requirements: 9.1, 9.2, 9.3
 */

import React, { useMemo } from "react";
import type { AnalyticsETLStatusIndicatorProps } from "../../types";
import { ETLStatusTooltip } from "./ETLStatusTooltip";

/**
 * Status configuration for visual indicators
 */
const STATUS_CONFIG = {
  pending: {
    color: "bg-gray-400",
    textColor: "text-gray-700",
    icon: "⏳",
    label: "Ожидание",
    description: "ETL процесс в очереди",
  },
  running: {
    color: "bg-blue-500",
    textColor: "text-blue-700",
    icon: "🔄",
    label: "Выполняется",
    description: "ETL процесс активен",
    animated: true,
  },
  completed: {
    color: "bg-green-500",
    textColor: "text-green-700",
    icon: "✅",
    label: "Завершен",
    description: "ETL процесс успешно завершен",
  },
  failed: {
    color: "bg-red-500",
    textColor: "text-red-700",
    icon: "❌",
    label: "Ошибка",
    description: "ETL процесс завершился с ошибкой",
  },
  retrying: {
    color: "bg-yellow-500",
    textColor: "text-yellow-700",
    icon: "🔄",
    label: "Повтор",
    description: "ETL процесс повторяется после ошибки",
    animated: true,
  },
  cancelled: {
    color: "bg-gray-500",
    textColor: "text-gray-700",
    icon: "⏹️",
    label: "Отменен",
    description: "ETL процесс был отменен",
  },
} as const;

/**
 * AnalyticsETLStatusIndicator Component
 *
 * Displays ETL status with visual indicators and interactive tooltips
 */
export const AnalyticsETLStatusIndicator: React.FC<
  AnalyticsETLStatusIndicatorProps
> = ({
  status,
  showProgress = true,
  showDetails = true,
  onRetry,
  onCancel,
  className = "",
}) => {
  const statusConfig = STATUS_CONFIG[status.status];

  // Calculate progress percentage
  const progressPercent = useMemo(() => {
    if (status.progress_percent !== undefined) {
      return status.progress_percent;
    }

    // Calculate based on records if progress_percent is not available
    if (status.records_processed > 0 && status.records_processed > 0) {
      // Estimate total records if not available
      const estimatedTotal = status.records_processed + status.records_failed;
      return estimatedTotal > 0
        ? (status.records_processed / estimatedTotal) * 100
        : 0;
    }

    return 0;
  }, [status]);

  // Format duration
  const formatDuration = (startTime: string, endTime?: string) => {
    const start = new Date(startTime);
    const end = endTime ? new Date(endTime) : new Date();
    const diffMs = end.getTime() - start.getTime();
    const diffMinutes = Math.floor(diffMs / 60000);
    const diffSeconds = Math.floor((diffMs % 60000) / 1000);

    if (diffMinutes > 0) {
      return `${diffMinutes}м ${diffSeconds}с`;
    }
    return `${diffSeconds}с`;
  };

  // Show retry button for failed status
  const showRetryButton =
    status.status === "failed" &&
    onRetry &&
    status.retry_count < status.max_retries;

  // Show cancel button for running/pending status
  const showCancelButton =
    (status.status === "running" || status.status === "pending") && onCancel;

  return (
    <div className={`relative inline-flex items-center gap-2 ${className}`}>
      {/* Status Indicator */}
      <div className="relative">
        <div
          className={`
            w-3 h-3 rounded-full ${statusConfig.color}
            ${
              "animated" in statusConfig && statusConfig.animated
                ? "animate-pulse"
                : ""
            }
            shadow-sm
          `}
          title={statusConfig.description}
        />

        {/* Progress ring for running status */}
        {status.status === "running" && showProgress && progressPercent > 0 && (
          <div className="absolute inset-0 -m-1">
            <svg className="w-5 h-5 transform -rotate-90" viewBox="0 0 20 20">
              <circle
                cx="10"
                cy="10"
                r="8"
                stroke="currentColor"
                strokeWidth="2"
                fill="none"
                className="text-gray-200"
              />
              <circle
                cx="10"
                cy="10"
                r="8"
                stroke="currentColor"
                strokeWidth="2"
                fill="none"
                strokeDasharray={`${2 * Math.PI * 8}`}
                strokeDashoffset={`${
                  2 * Math.PI * 8 * (1 - progressPercent / 100)
                }`}
                className="text-blue-500 transition-all duration-300"
              />
            </svg>
          </div>
        )}
      </div>

      {/* Status Text */}
      <div className="flex flex-col">
        <div className="flex items-center gap-1">
          <span className="text-sm font-medium">{statusConfig.icon}</span>
          <span className={`text-sm font-medium ${statusConfig.textColor}`}>
            {statusConfig.label}
          </span>

          {/* Progress percentage */}
          {status.status === "running" &&
            showProgress &&
            progressPercent > 0 && (
              <span className="text-xs text-gray-500">
                ({Math.round(progressPercent)}%)
              </span>
            )}
        </div>

        {/* Additional info */}
        {showDetails && (
          <div className="text-xs text-gray-500">
            {status.status === "running" && (
              <span>
                {status.records_processed.toLocaleString()} записей •{" "}
                {formatDuration(status.started_at)}
              </span>
            )}
            {status.status === "completed" && status.completed_at && (
              <span>
                {status.records_processed.toLocaleString()} записей •{" "}
                {formatDuration(status.started_at, status.completed_at)}
              </span>
            )}
            {status.status === "failed" && (
              <span>
                Попытка {status.retry_count + 1}/{status.max_retries}
                {status.next_retry_at && (
                  <>
                    {" "}
                    • Повтор в{" "}
                    {new Date(status.next_retry_at).toLocaleTimeString()}
                  </>
                )}
              </span>
            )}
            {status.status === "retrying" && (
              <span>
                Попытка {status.retry_count + 1}/{status.max_retries}
              </span>
            )}
          </div>
        )}
      </div>

      {/* Action Buttons */}
      {(showRetryButton || showCancelButton) && (
        <div className="flex gap-1 ml-2">
          {showRetryButton && (
            <button
              onClick={() => onRetry?.(status.id)}
              className="px-2 py-1 text-xs bg-blue-100 text-blue-700 rounded hover:bg-blue-200 transition-colors"
              title="Повторить ETL процесс"
            >
              🔄 Повтор
            </button>
          )}

          {showCancelButton && (
            <button
              onClick={() => onCancel?.(status.id)}
              className="px-2 py-1 text-xs bg-red-100 text-red-700 rounded hover:bg-red-200 transition-colors"
              title="Отменить ETL процесс"
            >
              ⏹️ Отмена
            </button>
          )}
        </div>
      )}

      {/* Detailed Tooltip */}
      {showDetails && <ETLStatusTooltip status={status} />}
    </div>
  );
};

/**
 * Compact version of the status indicator for use in tables
 */
export const CompactETLStatusIndicator: React.FC<
  Omit<AnalyticsETLStatusIndicatorProps, "showProgress" | "showDetails">
> = ({ status, className = "" }) => {
  const statusConfig = STATUS_CONFIG[status.status];

  return (
    <div className={`inline-flex items-center gap-1 ${className}`}>
      <div
        className={`
          w-2 h-2 rounded-full ${statusConfig.color}
          ${
            "animated" in statusConfig && statusConfig.animated
              ? "animate-pulse"
              : ""
          }
        `}
        title={`${statusConfig.label}: ${statusConfig.description}`}
      />
      <span className={`text-xs ${statusConfig.textColor}`}>
        {statusConfig.label}
      </span>
    </div>
  );
};

export default AnalyticsETLStatusIndicator;
