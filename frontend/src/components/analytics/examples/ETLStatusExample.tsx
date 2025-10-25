/**
 * ETLStatusExample Component
 *
 * Example usage of Analytics ETL Status components.
 * Demonstrates different states and configurations.
 *
 * This is for development and testing purposes.
 */

import React from "react";
import type { AnalyticsETLStatus } from "../../../types";
import {
  AnalyticsETLStatusIndicator,
  CompactETLStatusIndicator,
  ETLStatusList,
} from "../index";

/**
 * Mock ETL status data for examples
 */
const mockETLStatuses: AnalyticsETLStatus[] = [
  {
    id: 1,
    sync_type: "api_full",
    source_name: "Ozon Analytics API",
    status: "running",
    started_at: new Date(Date.now() - 300000).toISOString(), // 5 minutes ago
    records_processed: 1250,
    records_inserted: 800,
    records_updated: 450,
    records_failed: 0,
    retry_count: 0,
    max_retries: 3,
    progress_percent: 65,
    batch_id: "batch_2025_001",
  },
  {
    id: 2,
    sync_type: "ui_import",
    source_name: "Warehouse Report CSV",
    status: "completed",
    started_at: new Date(Date.now() - 1800000).toISOString(), // 30 minutes ago
    completed_at: new Date(Date.now() - 1200000).toISOString(), // 20 minutes ago
    records_processed: 5000,
    records_inserted: 3200,
    records_updated: 1800,
    records_failed: 0,
    retry_count: 0,
    max_retries: 3,
    progress_percent: 100,
    file_path: "/uploads/warehouse_report_2025_01_27.csv",
  },
  {
    id: 3,
    sync_type: "api_incremental",
    source_name: "Ozon Stock API",
    status: "failed",
    started_at: new Date(Date.now() - 600000).toISOString(), // 10 minutes ago
    records_processed: 150,
    records_inserted: 100,
    records_updated: 50,
    records_failed: 25,
    retry_count: 2,
    max_retries: 3,
    next_retry_at: new Date(Date.now() + 300000).toISOString(), // in 5 minutes
    error_message:
      "API rate limit exceeded. Retrying with exponential backoff.",
    api_endpoint: "/api/v1/analytics/stock",
  },
  {
    id: 4,
    sync_type: "manual_sync",
    source_name: "Manual Data Correction",
    status: "pending",
    started_at: new Date().toISOString(),
    records_processed: 0,
    records_inserted: 0,
    records_updated: 0,
    records_failed: 0,
    retry_count: 0,
    max_retries: 1,
  },
  {
    id: 5,
    sync_type: "api_full",
    source_name: "Wildberries Analytics",
    status: "retrying",
    started_at: new Date(Date.now() - 900000).toISOString(), // 15 minutes ago
    records_processed: 800,
    records_inserted: 600,
    records_updated: 200,
    records_failed: 50,
    retry_count: 1,
    max_retries: 3,
    next_retry_at: new Date(Date.now() + 120000).toISOString(), // in 2 minutes
    error_message: "Connection timeout. Retrying...",
    progress_percent: 40,
  },
];

/**
 * ETLStatusExample Component
 */
export const ETLStatusExample: React.FC = () => {
  const handleRetry = (syncId: number) => {
    console.log("Retry ETL process:", syncId);
    // In real app, this would trigger API call to retry the process
  };

  const handleCancel = (syncId: number) => {
    console.log("Cancel ETL process:", syncId);
    // In real app, this would trigger API call to cancel the process
  };

  const handleViewDetails = (status: AnalyticsETLStatus) => {
    console.log("View ETL details:", status);
    // In real app, this would open a modal or navigate to details page
  };

  return (
    <div className="p-6 space-y-8 bg-gray-50 min-h-screen">
      <div className="max-w-6xl mx-auto">
        <h1 className="text-3xl font-bold text-gray-900 mb-8">
          Analytics ETL Status Components Examples
        </h1>

        {/* Individual Status Indicators */}
        <section className="bg-white rounded-lg p-6 shadow-sm mb-8">
          <h2 className="text-xl font-semibold text-gray-900 mb-4">
            Individual Status Indicators
          </h2>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            {mockETLStatuses.map((status) => (
              <div
                key={status.id}
                className="border border-gray-200 rounded-lg p-4"
              >
                <h3 className="font-medium text-gray-900 mb-2">
                  {status.source_name}
                </h3>
                <AnalyticsETLStatusIndicator
                  status={status}
                  showProgress={true}
                  showDetails={true}
                  onRetry={handleRetry}
                  onCancel={handleCancel}
                />
              </div>
            ))}
          </div>
        </section>

        {/* Compact Indicators */}
        <section className="bg-white rounded-lg p-6 shadow-sm mb-8">
          <h2 className="text-xl font-semibold text-gray-900 mb-4">
            Compact Status Indicators (for tables)
          </h2>

          <div className="space-y-2">
            {mockETLStatuses.map((status) => (
              <div
                key={status.id}
                className="flex items-center justify-between p-3 border border-gray-200 rounded"
              >
                <span className="font-medium text-gray-900">
                  {status.source_name}
                </span>
                <CompactETLStatusIndicator
                  status={status}
                  onRetry={handleRetry}
                  onCancel={handleCancel}
                />
              </div>
            ))}
          </div>
        </section>

        {/* Status List */}
        <section className="bg-white rounded-lg p-6 shadow-sm">
          <h2 className="text-xl font-semibold text-gray-900 mb-4">
            ETL Status List with Filtering
          </h2>

          <ETLStatusList
            statuses={mockETLStatuses}
            onRetry={handleRetry}
            onCancel={handleCancel}
            onViewDetails={handleViewDetails}
            showFilters={true}
          />
        </section>

        {/* Usage Instructions */}
        <section className="bg-blue-50 rounded-lg p-6">
          <h2 className="text-xl font-semibold text-blue-900 mb-4">
            Usage Instructions
          </h2>

          <div className="space-y-4 text-blue-800">
            <div>
              <h3 className="font-medium">AnalyticsETLStatusIndicator</h3>
              <p className="text-sm">
                Main component for displaying ETL status with progress
                indicators, tooltips, and action buttons. Use for detailed
                status displays in dashboards and monitoring pages.
              </p>
            </div>

            <div>
              <h3 className="font-medium">CompactETLStatusIndicator</h3>
              <p className="text-sm">
                Compact version for use in tables and lists where space is
                limited. Shows essential status information without detailed
                progress.
              </p>
            </div>

            <div>
              <h3 className="font-medium">ETLStatusList</h3>
              <p className="text-sm">
                Complete list component with filtering, sorting, and bulk
                actions. Perfect for admin interfaces and monitoring dashboards.
              </p>
            </div>
          </div>
        </section>
      </div>
    </div>
  );
};

export default ETLStatusExample;
