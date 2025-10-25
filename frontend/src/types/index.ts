/**
 * Central export file for all TypeScript types
 *
 * This file provides a single entry point for importing types
 * across the application, making imports cleaner and more maintainable.
 */

// Re-export all warehouse analytics types
export * from "./warehouse-analytics";

// Re-export admin interface types
export * from "./admin";

// Re-export component-specific types
export * from "./components";

// Type guards and utility functions
export const isValidDataSource = (source: string): source is DataSource => {
  return ["api", "ui_report", "mixed", "manual"].includes(source);
};

export const isValidValidationStatus = (
  status: string
): status is ValidationStatus => {
  return ["pending", "validated", "conflict", "error"].includes(status);
};

export const isValidETLStatus = (status: string): status is ETLStatus => {
  return [
    "pending",
    "running",
    "completed",
    "failed",
    "retrying",
    "cancelled",
  ].includes(status);
};

export const isValidSyncType = (type: string): type is SyncType => {
  return ["api_full", "api_incremental", "ui_import", "manual_sync"].includes(
    type
  );
};

// Quality assessment utilities
export const getQualityLevel = (score: number): string => {
  if (score >= 95) return "excellent";
  if (score >= 85) return "good";
  if (score >= 70) return "acceptable";
  if (score >= 50) return "poor";
  return "critical";
};

export const getFreshnessLevel = (minutes: number): string => {
  if (minutes <= 60) return "fresh";
  if (minutes <= 360) return "acceptable";
  return "stale";
};

// Import the types for re-export
import type {
  DataSource,
  ValidationStatus,
  ETLStatus,
  SyncType,
} from "./warehouse-analytics";
