/**
 * Services Index
 *
 * Centralized exports for all service modules
 */

// API Service
export * from "./api";

// React Query Service
export * from "./queries";

// Re-export commonly used items
export { apiService } from "./api";
export {
  useDetailedInventory,
  useWarehouses,
  useRefreshData,
  useExportData,
  QUERY_KEYS,
} from "./queries";
