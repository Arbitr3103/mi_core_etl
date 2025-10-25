/**
 * ETLStatusTooltip Component
 *
 * Provides detailed tooltip information for ETL status indicators.
 * Shows comprehensive information about ETL process progress, timing,
 * and error details.
 *
 * Requirements: 9.1, 9.2, 9.3
 */

import React, { useState, useMemo } from "react";
import type { AnalyticsETLStatus } from "../../types";

interface ETLStatusTooltipProps {
  status: AnalyticsETLStatus;
  className?: string;
}

/**
 * Format bytes to human readable format
 * Currently unused but kept for future file size displays
 */
// const formatBytes = (bytes: number): string => {
//   if (bytes === 0) return "0 B";
//   const k = 1024;
//   const sizes = ["B", "KB", "MB", "GB"];
//   const i = Math.floor(Math.log(bytes) / Math.log(k));
//   return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
// };

/**
 * Format duration from start to end time
 */
const formatDuration = (startTime: string, endTime?: string): string => {
  const start = new Date(startTime);
  const end = endTime ? new Date(endTime) : new Date();
  const diffMs = end.getTime() - start.getTime();

  const hours = Math.floor(diffMs / 3600000);
  const minutes = Math.floor((diffMs % 3600000) / 60000);
  const seconds = Math.floor((diffMs % 60000) / 1000);

  if (hours > 0) {
    return `${hours}ч ${minutes}м ${seconds}с`;
  } else if (minutes > 0) {
    return `${minutes}м ${seconds}с`;
  }
  return `${seconds}с`;
};

/**
 * Get sync type display name
 */
const getSyncTypeDisplay = (syncType: string): string => {
  const types = {
    api_full: "Полная синхронизация API",
    api_incremental: "Инкрементальная синхронизация API",
    ui_import: "Импорт UI отчетов",
    manual_sync: "Ручная синхронизация",
  };
  return types[syncType as keyof typeof types] || syncType;
};

/**
 * ETLStatusTooltip Component
 */
export const ETLStatusTooltip: React.FC<ETLStatusTooltipProps> = ({
  status,
  className = "",
}) => {
  const [isVisible, setIsVisible] = useState(false);

  const showTooltip = () => setIsVisible(true);
  const hideTooltip = () => setIsVisible(false);

  // Calculate processing rate
  const processingRate = useMemo(() => {
    if (status.records_processed === 0) return 0;

    const start = new Date(status.started_at);
    const end = status.completed_at
      ? new Date(status.completed_at)
      : new Date();
    const durationMinutes = (end.getTime() - start.getTime()) / 60000;

    return durationMinutes > 0
      ? Math.round(status.records_processed / durationMinutes)
      : 0;
  }, [status]);

  // Calculate ETA for running processes
  const estimatedCompletion = useMemo(() => {
    if (
      status.status !== "running" ||
      !status.progress_percent ||
      status.progress_percent === 0
    ) {
      return null;
    }

    const start = new Date(status.started_at);
    const now = new Date();
    const elapsed = now.getTime() - start.getTime();
    const totalEstimated = (elapsed / status.progress_percent) * 100;
    const remaining = totalEstimated - elapsed;

    const eta = new Date(now.getTime() + remaining);
    return eta;
  }, [status]);

  return (
    <div className={`relative inline-block ${className}`}>
      {/* Trigger */}
      <div
        className="cursor-help"
        onMouseEnter={showTooltip}
        onMouseLeave={hideTooltip}
      >
        <span className="text-gray-400 hover:text-gray-600 text-xs ml-1">
          ℹ️
        </span>
      </div>

      {/* Tooltip */}
      {isVisible && (
        <div className="absolute z-50 bottom-full left-1/2 transform -translate-x-1/2 mb-2">
          <div className="bg-gray-900 text-white text-xs rounded-lg p-3 shadow-lg min-w-64 max-w-80">
            {/* Arrow */}
            <div className="absolute top-full left-1/2 transform -translate-x-1/2">
              <div className="border-4 border-transparent border-t-gray-900"></div>
            </div>

            {/* Header */}
            <div className="border-b border-gray-700 pb-2 mb-2">
              <div className="font-semibold text-sm">
                {getSyncTypeDisplay(status.sync_type)}
              </div>
              <div className="text-gray-300 text-xs">
                ID: {status.id} • Источник: {status.source_name}
              </div>
            </div>

            {/* Status Details */}
            <div className="space-y-2">
              {/* Timing */}
              <div>
                <div className="text-gray-300 text-xs mb-1">
                  ⏱️ Время выполнения
                </div>
                <div className="text-xs">
                  <div>
                    Начало: {new Date(status.started_at).toLocaleString()}
                  </div>
                  {status.completed_at && (
                    <div>
                      Завершение:{" "}
                      {new Date(status.completed_at).toLocaleString()}
                    </div>
                  )}
                  <div>
                    Длительность:{" "}
                    {formatDuration(status.started_at, status.completed_at)}
                  </div>
                  {estimatedCompletion && (
                    <div className="text-blue-300">
                      Ожидаемое завершение:{" "}
                      {estimatedCompletion.toLocaleTimeString()}
                    </div>
                  )}
                </div>
              </div>

              {/* Progress */}
              <div>
                <div className="text-gray-300 text-xs mb-1">📊 Прогресс</div>
                <div className="text-xs space-y-1">
                  <div className="flex justify-between">
                    <span>Обработано:</span>
                    <span className="text-green-300">
                      {status.records_processed.toLocaleString()}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span>Добавлено:</span>
                    <span className="text-blue-300">
                      {status.records_inserted.toLocaleString()}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span>Обновлено:</span>
                    <span className="text-yellow-300">
                      {status.records_updated.toLocaleString()}
                    </span>
                  </div>
                  {status.records_failed > 0 && (
                    <div className="flex justify-between">
                      <span>Ошибки:</span>
                      <span className="text-red-300">
                        {status.records_failed.toLocaleString()}
                      </span>
                    </div>
                  )}
                  {processingRate > 0 && (
                    <div className="flex justify-between">
                      <span>Скорость:</span>
                      <span className="text-gray-300">
                        {processingRate} зап/мин
                      </span>
                    </div>
                  )}
                </div>
              </div>

              {/* Progress Bar */}
              {status.progress_percent !== undefined &&
                status.progress_percent > 0 && (
                  <div>
                    <div className="text-gray-300 text-xs mb-1">
                      Прогресс: {Math.round(status.progress_percent)}%
                    </div>
                    <div className="w-full bg-gray-700 rounded-full h-2">
                      <div
                        className="bg-blue-500 h-2 rounded-full transition-all duration-300"
                        style={{ width: `${status.progress_percent}%` }}
                      />
                    </div>
                  </div>
                )}

              {/* Retry Information */}
              {(status.status === "failed" || status.status === "retrying") && (
                <div>
                  <div className="text-gray-300 text-xs mb-1">🔄 Повторы</div>
                  <div className="text-xs">
                    <div>
                      Попытка: {status.retry_count + 1} из {status.max_retries}
                    </div>
                    {status.next_retry_at && (
                      <div>
                        Следующий повтор:{" "}
                        {new Date(status.next_retry_at).toLocaleTimeString()}
                      </div>
                    )}
                  </div>
                </div>
              )}

              {/* Error Information */}
              {status.error_message && (
                <div>
                  <div className="text-red-300 text-xs mb-1">❌ Ошибка</div>
                  <div className="text-xs text-red-200 bg-red-900/30 p-2 rounded">
                    {status.error_message}
                  </div>
                </div>
              )}

              {/* Additional Metadata */}
              {(status.batch_id || status.file_path || status.api_endpoint) && (
                <div>
                  <div className="text-gray-300 text-xs mb-1">🔧 Детали</div>
                  <div className="text-xs space-y-1">
                    {status.batch_id && (
                      <div>
                        Batch ID:{" "}
                        <span className="font-mono text-gray-300">
                          {status.batch_id}
                        </span>
                      </div>
                    )}
                    {status.file_path && (
                      <div>
                        Файл:{" "}
                        <span className="font-mono text-gray-300">
                          {status.file_path}
                        </span>
                      </div>
                    )}
                    {status.api_endpoint && (
                      <div>
                        API:{" "}
                        <span className="font-mono text-gray-300">
                          {status.api_endpoint}
                        </span>
                      </div>
                    )}
                  </div>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default ETLStatusTooltip;
