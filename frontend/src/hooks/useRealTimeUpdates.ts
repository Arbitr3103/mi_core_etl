/**
 * Real-Time Data Updates Hook
 *
 * Provides automatic data refresh functionality with configurable intervals,
 * manual refresh controls, and last update tracking.
 *
 * Default interval is set to 4 hours since warehouse data typically updates once daily.
 * This prevents unnecessary server load while ensuring data freshness.
 *
 * Requirements: 7.1, 7.4
 */

import { useState, useEffect, useCallback, useRef } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { QUERY_KEYS } from "../services/queries";

/**
 * Configuration for real-time updates
 */
interface RealTimeConfig {
  enabled: boolean;
  intervalMs: number;
  onUpdate?: () => void;
  onError?: (error: Error) => void;
}

/**
 * Default configuration
 */
const DEFAULT_CONFIG: RealTimeConfig = {
  enabled: true,
  intervalMs: 4 * 60 * 60 * 1000, // 4 hours (since data updates once daily)
};

/**
 * Hook for managing real-time data updates
 */
export const useRealTimeUpdates = (config: Partial<RealTimeConfig> = {}) => {
  const finalConfig = { ...DEFAULT_CONFIG, ...config };
  const queryClient = useQueryClient();

  const [isAutoRefreshEnabled, setIsAutoRefreshEnabled] = useState(
    finalConfig.enabled
  );
  const [lastUpdateTime, setLastUpdateTime] = useState<Date | null>(null);
  const [nextUpdateTime, setNextUpdateTime] = useState<Date | null>(null);
  const [isManualRefreshing, setIsManualRefreshing] = useState(false);

  const intervalRef = useRef<NodeJS.Timeout | null>(null);
  const configRef = useRef(finalConfig);

  // Update config ref when config changes
  useEffect(() => {
    configRef.current = finalConfig;
  }, [finalConfig]);

  /**
   * Perform data refresh
   */
  const performRefresh = useCallback(
    async (isManual: boolean = false) => {
      try {
        if (isManual) {
          setIsManualRefreshing(true);
        }

        // Invalidate all inventory-related queries to trigger refetch
        await queryClient.invalidateQueries({
          queryKey: [QUERY_KEYS.DETAILED_INVENTORY],
        });

        // Also refresh warehouses list occasionally
        await queryClient.invalidateQueries({
          queryKey: [QUERY_KEYS.WAREHOUSES],
        });

        const now = new Date();
        setLastUpdateTime(now);

        // Calculate next update time
        if (isAutoRefreshEnabled) {
          const nextUpdate = new Date(
            now.getTime() + configRef.current.intervalMs
          );
          setNextUpdateTime(nextUpdate);
        }

        // Call onUpdate callback if provided
        configRef.current.onUpdate?.();
      } catch (error) {
        console.error("Failed to refresh data:", error);
        configRef.current.onError?.(error as Error);
      } finally {
        if (isManual) {
          setIsManualRefreshing(false);
        }
      }
    },
    [queryClient, isAutoRefreshEnabled]
  );

  /**
   * Manual refresh function
   */
  const manualRefresh = useCallback(() => {
    return performRefresh(true);
  }, [performRefresh]);

  /**
   * Toggle auto-refresh
   */
  const toggleAutoRefresh = useCallback(() => {
    setIsAutoRefreshEnabled((prev) => !prev);
  }, []);

  /**
   * Set up automatic refresh interval
   */
  useEffect(() => {
    if (isAutoRefreshEnabled) {
      // Clear existing interval
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
      }

      // Set up new interval
      intervalRef.current = setInterval(() => {
        performRefresh(false);
      }, configRef.current.intervalMs);

      // Set initial next update time
      const now = new Date();
      const nextUpdate = new Date(now.getTime() + configRef.current.intervalMs);
      setNextUpdateTime(nextUpdate);

      // Perform initial refresh if no last update time
      if (!lastUpdateTime) {
        performRefresh(false);
      }
    } else {
      // Clear interval when auto-refresh is disabled
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
      setNextUpdateTime(null);
    }

    // Cleanup on unmount or dependency change
    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
      }
    };
  }, [isAutoRefreshEnabled, performRefresh, lastUpdateTime]);

  /**
   * Cleanup on unmount
   */
  useEffect(() => {
    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
      }
    };
  }, []);

  /**
   * Calculate time until next update
   */
  const getTimeUntilNextUpdate = useCallback((): number | null => {
    if (!nextUpdateTime || !isAutoRefreshEnabled) {
      return null;
    }

    const now = new Date();
    const diff = nextUpdateTime.getTime() - now.getTime();
    return Math.max(0, diff);
  }, [nextUpdateTime, isAutoRefreshEnabled]);

  /**
   * Format time remaining as human-readable string
   */
  const getTimeUntilNextUpdateFormatted = useCallback((): string | null => {
    const timeMs = getTimeUntilNextUpdate();

    if (timeMs === null) {
      return null;
    }

    const minutes = Math.floor(timeMs / (1000 * 60));
    const seconds = Math.floor((timeMs % (1000 * 60)) / 1000);

    if (minutes > 0) {
      return `${minutes}m ${seconds}s`;
    } else {
      return `${seconds}s`;
    }
  }, [getTimeUntilNextUpdate]);

  /**
   * Format last update time as human-readable string
   */
  const getLastUpdateFormatted = useCallback((): string => {
    if (!lastUpdateTime) {
      return "Never";
    }

    const now = new Date();
    const diffMs = now.getTime() - lastUpdateTime.getTime();
    const diffMinutes = Math.floor(diffMs / (1000 * 60));

    if (diffMinutes < 1) {
      return "Just now";
    } else if (diffMinutes < 60) {
      return `${diffMinutes} minute${diffMinutes === 1 ? "" : "s"} ago`;
    } else {
      const diffHours = Math.floor(diffMinutes / 60);
      return `${diffHours} hour${diffHours === 1 ? "" : "s"} ago`;
    }
  }, [lastUpdateTime]);

  /**
   * Check if data is stale (older than interval)
   */
  const isDataStale = useCallback((): boolean => {
    if (!lastUpdateTime) {
      return true;
    }

    const now = new Date();
    const diffMs = now.getTime() - lastUpdateTime.getTime();
    return diffMs > configRef.current.intervalMs;
  }, [lastUpdateTime]);

  return {
    // State
    isAutoRefreshEnabled,
    lastUpdateTime,
    nextUpdateTime,
    isManualRefreshing,

    // Actions
    manualRefresh,
    toggleAutoRefresh,

    // Computed values
    getTimeUntilNextUpdate,
    getTimeUntilNextUpdateFormatted,
    getLastUpdateFormatted,
    isDataStale,

    // Configuration
    intervalMs: configRef.current.intervalMs,
  };
};

/**
 * Hook for countdown timer to next update
 */
export const useUpdateCountdown = (
  getTimeUntilNextUpdate: () => number | null,
  enabled: boolean = true
) => {
  const [countdown, setCountdown] = useState<string | null>(null);

  useEffect(() => {
    if (!enabled) {
      setCountdown(null);
      return;
    }

    const updateCountdown = () => {
      const timeMs = getTimeUntilNextUpdate();

      if (timeMs === null) {
        setCountdown(null);
        return;
      }

      const minutes = Math.floor(timeMs / (1000 * 60));
      const seconds = Math.floor((timeMs % (1000 * 60)) / 1000);

      if (minutes > 0) {
        setCountdown(`${minutes}:${seconds.toString().padStart(2, "0")}`);
      } else {
        setCountdown(`0:${seconds.toString().padStart(2, "0")}`);
      }
    };

    // Update immediately
    updateCountdown();

    // Update every second
    const interval = setInterval(updateCountdown, 1000);

    return () => clearInterval(interval);
  }, [getTimeUntilNextUpdate, enabled]);

  return countdown;
};

export default useRealTimeUpdates;
