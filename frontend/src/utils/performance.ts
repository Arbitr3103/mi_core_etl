/**
 * Performance monitoring utilities
 */

// Mark performance measurement
export function markPerformance(name: string): void {
  if (typeof window !== "undefined" && window.performance) {
    performance.mark(name);
  }
}

// Measure performance between two marks
export function measurePerformance(
  name: string,
  startMark: string,
  endMark: string
): number | null {
  if (typeof window !== "undefined" && window.performance) {
    try {
      performance.measure(name, startMark, endMark);
      const measure = performance.getEntriesByName(name)[0];
      return measure ? measure.duration : null;
    } catch (error) {
      console.warn("Performance measurement failed:", error);
      return null;
    }
  }
  return null;
}

// Clear performance marks and measures
export function clearPerformance(name?: string): void {
  if (typeof window !== "undefined" && window.performance) {
    if (name) {
      performance.clearMarks(name);
      performance.clearMeasures(name);
    } else {
      performance.clearMarks();
      performance.clearMeasures();
    }
  }
}

// Get performance metrics
export function getPerformanceMetrics(): Record<string, number> | null {
  if (typeof window !== "undefined" && window.performance) {
    const navigation = performance.getEntriesByType(
      "navigation"
    )[0] as PerformanceNavigationTiming;

    if (navigation) {
      return {
        dnsLookup: navigation.domainLookupEnd - navigation.domainLookupStart,
        tcpConnection: navigation.connectEnd - navigation.connectStart,
        requestTime: navigation.responseStart - navigation.requestStart,
        responseTime: navigation.responseEnd - navigation.responseStart,
        domProcessing: navigation.domComplete - navigation.domInteractive,
        loadComplete: navigation.loadEventEnd - navigation.loadEventStart,
        totalLoadTime: navigation.loadEventEnd - navigation.fetchStart,
      };
    }
  }
  return null;
}

// Monitor component render time
export function useRenderTime(componentName: string): void {
  if (process.env.NODE_ENV === "development") {
    const startTime = performance.now();

    // Use effect to measure after render
    React.useEffect(() => {
      const endTime = performance.now();
      const renderTime = endTime - startTime;

      if (renderTime > 16) {
        // Warn if render takes longer than one frame (16ms)
        console.warn(`${componentName} render took ${renderTime.toFixed(2)}ms`);
      }
    });
  }
}

// Debounce function for performance optimization
export function debounce<T extends (...args: unknown[]) => unknown>(
  func: T,
  wait: number
): (...args: Parameters<T>) => void {
  let timeout: ReturnType<typeof setTimeout> | null = null;

  return function executedFunction(...args: Parameters<T>) {
    const later = () => {
      timeout = null;
      func(...args);
    };

    if (timeout) {
      clearTimeout(timeout);
    }
    timeout = setTimeout(later, wait);
  };
}

// Throttle function for performance optimization
export function throttle<T extends (...args: unknown[]) => unknown>(
  func: T,
  limit: number
): (...args: Parameters<T>) => void {
  let inThrottle: boolean;

  return function executedFunction(...args: Parameters<T>) {
    if (!inThrottle) {
      func(...args);
      inThrottle = true;
      setTimeout(() => {
        inThrottle = false;
      }, limit);
    }
  };
}

// Import React for useEffect
import React from "react";
