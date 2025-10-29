/**
 * Optimized Data Fetching Hook
 *
 * Provides intelligent data fetching with caching, deduplication, and performance optimizations.
 *
 * Requirements: 7.2, 7.3
 * Task: 4.2 Frontend performance optimization - optimized data fetching
 */

import { useState, useEffect, useRef, useCallback, useMemo } from "react";
import type { FilterState } from "../types/inventory-dashboard";

interface FetchState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  lastFetch: number;
  cacheHit: boolean;
}

interface FetchOptions {
  cacheTime?: number; // Cache duration in milliseconds
  staleTime?: number; // Time before data is considered stale
  retryCount?: number; // Number of retry attempts
  retryDelay?: number; // Delay between retries
  dedupe?: boolean; // Deduplicate identical requests
  background?: boolean; // Fetch in background without loading state
}

const DEFAULT_OPTIONS: Required<FetchOptions> = {
  cacheTime: 5 * 60 * 1000, // 5 minutes
  staleTime: 30 * 1000, // 30 seconds
  retryCount: 3,
  retryDelay: 1000,
  dedupe: true,
  background: false,
};

// Global cache for deduplication and caching
const globalCache = new Map<
  string,
  {
    data: any;
    timestamp: number;
    promise?: Promise<any>;
  }
>();

// Active requests for deduplication
const activeRequests = new Map<string, Promise<any>>();

/**
 * Generate cache key from parameters
 */
function generateCacheKey(url: string, params?: Record<string, any>): string {
  const paramString = params ? JSON.stringify(params) : "";
  return `${url}:${paramString}`;
}

/**
 * Check if cached data is still valid
 */
function isCacheValid(cacheEntry: any, staleTime: number): boolean {
  return Date.now() - cacheEntry.timestamp < staleTime;
}

/**
 * Optimized data fetching hook
 */
export function useOptimizedFetch<T>(
  url: string,
  params?: Record<string, any>,
  options: FetchOptions = {}
) {
  const opts = { ...DEFAULT_OPTIONS, ...options };
  const [state, setState] = useState<FetchState<T>>({
    data: null,
    loading: false,
    error: null,
    lastFetch: 0,
    cacheHit: false,
  });

  const abortControllerRef = useRef<AbortController | null>(null);
  const retryTimeoutRef = useRef<NodeJS.Timeout | null>(null);

  const cacheKey = useMemo(() => generateCacheKey(url, params), [url, params]);

  /**
   * Fetch data with retry logic
   */
  const fetchData = useCallback(
    async (attempt: number = 0, isBackground: boolean = false): Promise<T> => {
      // Check for active request (deduplication)
      if (opts.dedupe && activeRequests.has(cacheKey)) {
        return activeRequests.get(cacheKey)!;
      }

      // Create abort controller
      abortControllerRef.current = new AbortController();

      const fetchPromise = (async (): Promise<T> => {
        try {
          if (!isBackground) {
            setState((prev) => ({ ...prev, loading: true, error: null }));
          }

          // Build URL with query parameters
          const searchParams = new URLSearchParams();
          if (params) {
            Object.entries(params).forEach(([key, value]) => {
              if (value !== undefined && value !== null && value !== "") {
                if (Array.isArray(value)) {
                  value.forEach((v) =>
                    searchParams.append(`${key}[]`, String(v))
                  );
                } else {
                  searchParams.append(key, String(value));
                }
              }
            });
          }

          const fullUrl = searchParams.toString()
            ? `${url}?${searchParams.toString()}`
            : url;

          const response = await fetch(fullUrl, {
            signal: abortControllerRef.current?.signal,
            headers: {
              "Content-Type": "application/json",
            },
          });

          if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
          }

          const result = await response.json();

          if (!result.success) {
            throw new Error(result.error || "API request failed");
          }

          // Cache the result
          globalCache.set(cacheKey, {
            data: result,
            timestamp: Date.now(),
          });

          setState((prev) => ({
            ...prev,
            data: result,
            loading: false,
            error: null,
            lastFetch: Date.now(),
            cacheHit: false,
          }));

          return result;
        } catch (error: any) {
          // Don't update state if request was aborted
          if (error.name === "AbortError") {
            throw error;
          }

          // Retry logic
          if (
            attempt < opts.retryCount &&
            !abortControllerRef.current?.signal.aborted
          ) {
            console.warn(
              `Fetch attempt ${attempt + 1} failed, retrying...`,
              error
            );

            retryTimeoutRef.current = setTimeout(() => {
              fetchData(attempt + 1, isBackground);
            }, opts.retryDelay * Math.pow(2, attempt)); // Exponential backoff

            return new Promise(() => {}); // Keep promise pending
          }

          // Final failure
          const errorMessage = error.message || "Failed to fetch data";

          setState((prev) => ({
            ...prev,
            loading: false,
            error: errorMessage,
            cacheHit: false,
          }));

          throw error;
        } finally {
          // Clean up active request
          activeRequests.delete(cacheKey);
        }
      })();

      // Store active request for deduplication
      if (opts.dedupe) {
        activeRequests.set(cacheKey, fetchPromise);
      }

      return fetchPromise;
    },
    [url, params, cacheKey, opts]
  );

  /**
   * Refetch data
   */
  const refetch = useCallback(
    (background: boolean = false) => {
      return fetchData(0, background);
    },
    [fetchData]
  );

  /**
   * Invalidate cache for this query
   */
  const invalidate = useCallback(() => {
    globalCache.delete(cacheKey);
  }, [cacheKey]);

  // Initial fetch and cache check
  useEffect(() => {
    // Check cache first
    const cacheEntry = globalCache.get(cacheKey);

    if (cacheEntry && isCacheValid(cacheEntry, opts.staleTime)) {
      // Use cached data
      setState((prev) => ({
        ...prev,
        data: cacheEntry.data,
        loading: false,
        error: null,
        lastFetch: cacheEntry.timestamp,
        cacheHit: true,
      }));

      // If data is getting stale, fetch in background
      if (Date.now() - cacheEntry.timestamp > opts.staleTime / 2) {
        fetchData(0, true);
      }
    } else {
      // Fetch fresh data
      fetchData();
    }

    // Cleanup function
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      if (retryTimeoutRef.current) {
        clearTimeout(retryTimeoutRef.current);
      }
    };
  }, [cacheKey, fetchData, opts.staleTime]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      if (retryTimeoutRef.current) {
        clearTimeout(retryTimeoutRef.current);
      }
    };
  }, []);

  return {
    ...state,
    refetch,
    invalidate,
    isStale:
      state.lastFetch > 0 && Date.now() - state.lastFetch > opts.staleTime,
  };
}

/**
 * Hook for optimized inventory data fetching
 */
export function useOptimizedInventoryData(filters: FilterState) {
  return useOptimizedFetch("/detailed-stock.php", filters, {
    cacheTime: 5 * 60 * 1000, // 5 minutes
    staleTime: 30 * 1000, // 30 seconds
    dedupe: true,
    retryCount: 2,
  });
}

/**
 * Hook for optimized warehouse list fetching
 */
export function useOptimizedWarehouses() {
  return useOptimizedFetch(
    "/detailed-stock.php",
    { action: "warehouses" },
    {
      cacheTime: 30 * 60 * 1000, // 30 minutes
      staleTime: 10 * 60 * 1000, // 10 minutes
      dedupe: true,
      retryCount: 3,
    }
  );
}

/**
 * Hook for optimized summary statistics
 */
export function useOptimizedSummary() {
  return useOptimizedFetch(
    "/detailed-stock.php",
    { action: "summary" },
    {
      cacheTime: 10 * 60 * 1000, // 10 minutes
      staleTime: 2 * 60 * 1000, // 2 minutes
      dedupe: true,
      retryCount: 2,
    }
  );
}

/**
 * Clear all cached data
 */
export function clearAllCache(): void {
  globalCache.clear();
  activeRequests.clear();
}

/**
 * Get cache statistics
 */
export function getCacheStats() {
  return {
    cacheSize: globalCache.size,
    activeRequests: activeRequests.size,
    cacheEntries: Array.from(globalCache.keys()),
  };
}

/**
 * Preload data for better performance
 */
export function preloadData(url: string, params?: Record<string, any>): void {
  const cacheKey = generateCacheKey(url, params);

  // Don't preload if already cached or actively loading
  if (globalCache.has(cacheKey) || activeRequests.has(cacheKey)) {
    return;
  }

  // Start background fetch
  const searchParams = new URLSearchParams();
  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== "") {
        if (Array.isArray(value)) {
          value.forEach((v) => searchParams.append(`${key}[]`, String(v)));
        } else {
          searchParams.append(key, String(value));
        }
      }
    });
  }

  const fullUrl = searchParams.toString()
    ? `${url}?${searchParams.toString()}`
    : url;

  const preloadPromise = fetch(fullUrl)
    .then((response) => response.json())
    .then((result) => {
      if (result.success) {
        globalCache.set(cacheKey, {
          data: result,
          timestamp: Date.now(),
        });
      }
    })
    .catch((error) => {
      console.warn("Preload failed:", error);
    })
    .finally(() => {
      activeRequests.delete(cacheKey);
    });

  activeRequests.set(cacheKey, preloadPromise);
}
