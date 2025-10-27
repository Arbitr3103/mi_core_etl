/**
 * Performance Monitoring Hook
 *
 * Provides performance monitoring and optimization utilities for the dashboard.
 *
 * Requirements: 7.2, 7.3
 * Task: 4.2 Frontend performance optimization - monitoring
 */

import { useEffect, useRef, useCallback, useState } from "react";

interface PerformanceMetrics {
  renderTime: number;
  dataProcessingTime: number;
  apiResponseTime: number;
  memoryUsage?: number;
  componentRenderCount: number;
}

interface PerformanceConfig {
  enableLogging: boolean;
  sampleRate: number; // 0-1, percentage of operations to measure
  slowThreshold: number; // milliseconds
}

const DEFAULT_CONFIG: PerformanceConfig = {
  enableLogging: process.env.NODE_ENV === "development",
  sampleRate: 0.1, // 10% sampling in production
  slowThreshold: 100, // 100ms
};

/**
 * Hook for monitoring component performance
 */
export function usePerformanceMonitoring(
  componentName: string,
  config: Partial<PerformanceConfig> = {}
) {
  const finalConfig = { ...DEFAULT_CONFIG, ...config };
  const [metrics, setMetrics] = useState<PerformanceMetrics>({
    renderTime: 0,
    dataProcessingTime: 0,
    apiResponseTime: 0,
    componentRenderCount: 0,
  });

  const renderStartTime = useRef<number>(0);
  const renderCount = useRef<number>(0);
  const shouldSample = useRef<boolean>(false);

  // Determine if we should sample this render
  useEffect(() => {
    shouldSample.current = Math.random() < finalConfig.sampleRate;
  });

  // Start render timing
  const startRenderTiming = useCallback(() => {
    if (shouldSample.current && finalConfig.enableLogging) {
      renderStartTime.current = performance.now();
    }
  }, [finalConfig.enableLogging]);

  // End render timing
  const endRenderTiming = useCallback(() => {
    if (
      shouldSample.current &&
      finalConfig.enableLogging &&
      renderStartTime.current > 0
    ) {
      const renderTime = performance.now() - renderStartTime.current;
      renderCount.current++;

      setMetrics((prev) => ({
        ...prev,
        renderTime,
        componentRenderCount: renderCount.current,
      }));

      if (renderTime > finalConfig.slowThreshold) {
        console.warn(
          `Slow render detected in ${componentName}: ${renderTime.toFixed(2)}ms`
        );
      }

      renderStartTime.current = 0;
    }
  }, [componentName, finalConfig.enableLogging, finalConfig.slowThreshold]);

  // Measure data processing time
  const measureDataProcessing = useCallback(
    <T>(operation: () => T, operationName: string = "data processing"): T => {
      if (!shouldSample.current || !finalConfig.enableLogging) {
        return operation();
      }

      const startTime = performance.now();
      const result = operation();
      const processingTime = performance.now() - startTime;

      setMetrics((prev) => ({
        ...prev,
        dataProcessingTime: processingTime,
      }));

      if (processingTime > finalConfig.slowThreshold) {
        console.warn(
          `Slow ${operationName} in ${componentName}: ${processingTime.toFixed(
            2
          )}ms`
        );
      }

      return result;
    },
    [componentName, finalConfig.enableLogging, finalConfig.slowThreshold]
  );

  // Measure API response time
  const measureApiCall = useCallback(
    async <T>(
      apiCall: () => Promise<T>,
      apiName: string = "API call"
    ): Promise<T> => {
      if (!shouldSample.current || !finalConfig.enableLogging) {
        return apiCall();
      }

      const startTime = performance.now();
      try {
        const result = await apiCall();
        const responseTime = performance.now() - startTime;

        setMetrics((prev) => ({
          ...prev,
          apiResponseTime: responseTime,
        }));

        if (responseTime > finalConfig.slowThreshold * 5) {
          // APIs have higher threshold
          console.warn(
            `Slow ${apiName} in ${componentName}: ${responseTime.toFixed(2)}ms`
          );
        }

        return result;
      } catch (error) {
        const responseTime = performance.now() - startTime;
        console.error(
          `Failed ${apiName} in ${componentName} after ${responseTime.toFixed(
            2
          )}ms:`,
          error
        );
        throw error;
      }
    },
    [componentName, finalConfig.enableLogging, finalConfig.slowThreshold]
  );

  // Get memory usage (if available)
  const getMemoryUsage = useCallback(() => {
    if ("memory" in performance) {
      const memory = (performance as any).memory;
      return {
        used: memory.usedJSHeapSize,
        total: memory.totalJSHeapSize,
        limit: memory.jsHeapSizeLimit,
      };
    }
    return null;
  }, []);

  // Log performance summary
  const logPerformanceSummary = useCallback(() => {
    if (!finalConfig.enableLogging) return;

    const memory = getMemoryUsage();
    console.group(`Performance Summary - ${componentName}`);
    console.log(`Render time: ${metrics.renderTime.toFixed(2)}ms`);
    console.log(`Data processing: ${metrics.dataProcessingTime.toFixed(2)}ms`);
    console.log(`API response: ${metrics.apiResponseTime.toFixed(2)}ms`);
    console.log(`Render count: ${metrics.componentRenderCount}`);

    if (memory) {
      console.log(`Memory usage: ${(memory.used / 1024 / 1024).toFixed(2)}MB`);
    }

    console.groupEnd();
  }, [componentName, metrics, finalConfig.enableLogging, getMemoryUsage]);

  return {
    metrics,
    startRenderTiming,
    endRenderTiming,
    measureDataProcessing,
    measureApiCall,
    getMemoryUsage,
    logPerformanceSummary,
  };
}

/**
 * Hook for monitoring data processing performance
 */
export function useDataProcessingPerformance() {
  const [processingTimes, setProcessingTimes] = useState<Map<string, number>>(
    new Map()
  );

  const measureProcessing = useCallback(
    <T>(operation: () => T, operationName: string): T => {
      const startTime = performance.now();
      const result = operation();
      const endTime = performance.now();
      const duration = endTime - startTime;

      setProcessingTimes((prev) => new Map(prev.set(operationName, duration)));

      return result;
    },
    []
  );

  const getAverageTime = useCallback(
    (operationName: string): number => {
      return processingTimes.get(operationName) || 0;
    },
    [processingTimes]
  );

  const getAllTimes = useCallback(() => {
    return Object.fromEntries(processingTimes);
  }, [processingTimes]);

  return {
    measureProcessing,
    getAverageTime,
    getAllTimes,
    clearTimes: () => setProcessingTimes(new Map()),
  };
}

/**
 * Hook for monitoring render performance
 */
export function useRenderPerformance(componentName: string) {
  const renderCount = useRef(0);
  const lastRenderTime = useRef(0);
  const [averageRenderTime, setAverageRenderTime] = useState(0);

  useEffect(() => {
    const startTime = performance.now();

    return () => {
      const endTime = performance.now();
      const renderTime = endTime - startTime;

      renderCount.current++;
      lastRenderTime.current = renderTime;

      // Calculate running average
      setAverageRenderTime((prev) => {
        const count = renderCount.current;
        return (prev * (count - 1) + renderTime) / count;
      });

      if (process.env.NODE_ENV === "development" && renderTime > 16) {
        console.warn(
          `Slow render in ${componentName}: ${renderTime.toFixed(
            2
          )}ms (target: <16ms)`
        );
      }
    };
  });

  return {
    renderCount: renderCount.current,
    lastRenderTime: lastRenderTime.current,
    averageRenderTime,
  };
}

/**
 * Performance observer for Web Vitals
 */
export function useWebVitals() {
  const [vitals, setVitals] = useState<{
    FCP?: number;
    LCP?: number;
    FID?: number;
    CLS?: number;
  }>({});

  useEffect(() => {
    // Only run in browser environment
    if (typeof window === "undefined") return;

    // Observe First Contentful Paint
    const fcpObserver = new PerformanceObserver((list) => {
      const entries = list.getEntries();
      const fcp = entries.find(
        (entry) => entry.name === "first-contentful-paint"
      );
      if (fcp) {
        setVitals((prev) => ({ ...prev, FCP: fcp.startTime }));
      }
    });

    // Observe Largest Contentful Paint
    const lcpObserver = new PerformanceObserver((list) => {
      const entries = list.getEntries();
      const lastEntry = entries[entries.length - 1];
      if (lastEntry) {
        setVitals((prev) => ({ ...prev, LCP: lastEntry.startTime }));
      }
    });

    // Observe First Input Delay
    const fidObserver = new PerformanceObserver((list) => {
      const entries = list.getEntries();
      entries.forEach((entry) => {
        // Type assertion for PerformanceEventTiming which has processingStart
        const eventEntry = entry as any;
        if (eventEntry.processingStart && entry.startTime) {
          const fid = eventEntry.processingStart - entry.startTime;
          setVitals((prev) => ({ ...prev, FID: fid }));
        }
      });
    });

    try {
      fcpObserver.observe({ entryTypes: ["paint"] });
      lcpObserver.observe({ entryTypes: ["largest-contentful-paint"] });
      fidObserver.observe({ entryTypes: ["first-input"] });
    } catch (error) {
      console.warn("Performance Observer not supported:", error);
    }

    return () => {
      fcpObserver.disconnect();
      lcpObserver.disconnect();
      fidObserver.disconnect();
    };
  }, []);

  return vitals;
}
