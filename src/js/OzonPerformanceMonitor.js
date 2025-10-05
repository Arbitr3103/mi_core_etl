/**
 * Ozon Performance Monitor
 *
 * Monitors and optimizes performance of Ozon analytics components
 * Provides metrics, memory usage tracking, and performance recommendations
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonPerformanceMonitor {
  constructor(options = {}) {
    this.options = {
      enableMetrics: true,
      enableMemoryTracking: true,
      enableNetworkTracking: true,
      reportInterval: 30000, // 30 seconds
      maxMetricsHistory: 100,
      memoryThreshold: 50 * 1024 * 1024, // 50MB
      ...options,
    };

    this.metrics = {
      apiCalls: [],
      renderTimes: [],
      memoryUsage: [],
      networkRequests: [],
      cacheHits: 0,
      cacheMisses: 0,
      errors: [],
    };

    this.timers = new Map();
    this.observers = new Map();
    this.reportTimer = null;

    this.init();
  }

  /**
   * Initialize performance monitoring
   */
  init() {
    if (this.options.enableMetrics) {
      this.setupPerformanceObserver();
    }

    if (this.options.enableMemoryTracking) {
      this.startMemoryTracking();
    }

    if (this.options.enableNetworkTracking) {
      this.setupNetworkTracking();
    }

    this.startReporting();
  }

  /**
   * Setup Performance Observer for measuring various metrics
   */
  setupPerformanceObserver() {
    if ("PerformanceObserver" in window) {
      // Measure navigation timing
      const navObserver = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          this.recordMetric("navigation", {
            name: entry.name,
            duration: entry.duration,
            startTime: entry.startTime,
            type: entry.entryType,
          });
        }
      });

      try {
        navObserver.observe({ entryTypes: ["navigation"] });
        this.observers.set("navigation", navObserver);
      } catch (e) {
        console.warn("Navigation timing not supported");
      }

      // Measure resource loading
      const resourceObserver = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          if (
            entry.name.includes("ozon-analytics") ||
            entry.name.includes("api")
          ) {
            this.recordMetric("resource", {
              name: entry.name,
              duration: entry.duration,
              transferSize: entry.transferSize,
              type: entry.initiatorType,
            });
          }
        }
      });

      try {
        resourceObserver.observe({ entryTypes: ["resource"] });
        this.observers.set("resource", resourceObserver);
      } catch (e) {
        console.warn("Resource timing not supported");
      }

      // Measure long tasks
      const longTaskObserver = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          this.recordMetric("longtask", {
            duration: entry.duration,
            startTime: entry.startTime,
            attribution: entry.attribution,
          });
        }
      });

      try {
        longTaskObserver.observe({ entryTypes: ["longtask"] });
        this.observers.set("longtask", longTaskObserver);
      } catch (e) {
        console.warn("Long task timing not supported");
      }
    }
  }

  /**
   * Setup network request tracking
   */
  setupNetworkTracking() {
    // Intercept fetch requests
    const originalFetch = window.fetch;
    window.fetch = async (...args) => {
      const startTime = performance.now();
      const url = args[0];

      try {
        const response = await originalFetch.apply(window, args);
        const endTime = performance.now();

        if (url.includes("ozon-analytics") || url.includes("api")) {
          this.recordNetworkRequest({
            url: url,
            method: args[1]?.method || "GET",
            duration: endTime - startTime,
            status: response.status,
            success: response.ok,
            size: response.headers.get("content-length") || 0,
          });
        }

        return response;
      } catch (error) {
        const endTime = performance.now();

        this.recordNetworkRequest({
          url: url,
          method: args[1]?.method || "GET",
          duration: endTime - startTime,
          status: 0,
          success: false,
          error: error.message,
        });

        throw error;
      }
    };

    // Intercept XMLHttpRequest
    const originalXHROpen = XMLHttpRequest.prototype.open;
    const originalXHRSend = XMLHttpRequest.prototype.send;

    XMLHttpRequest.prototype.open = function (method, url, ...args) {
      this._perfMonitor = {
        method: method,
        url: url,
        startTime: null,
      };
      return originalXHROpen.apply(this, [method, url, ...args]);
    };

    XMLHttpRequest.prototype.send = function (...args) {
      if (this._perfMonitor) {
        this._perfMonitor.startTime = performance.now();

        this.addEventListener("loadend", () => {
          const endTime = performance.now();
          const monitor = this._perfMonitor;

          if (
            monitor.url.includes("ozon-analytics") ||
            monitor.url.includes("api")
          ) {
            this.recordNetworkRequest({
              url: monitor.url,
              method: monitor.method,
              duration: endTime - monitor.startTime,
              status: this.status,
              success: this.status >= 200 && this.status < 300,
              size: this.getResponseHeader("content-length") || 0,
            });
          }
        });
      }

      return originalXHRSend.apply(this, args);
    };
  }

  /**
   * Start memory usage tracking
   */
  startMemoryTracking() {
    if ("memory" in performance) {
      setInterval(() => {
        const memInfo = performance.memory;
        this.recordMemoryUsage({
          used: memInfo.usedJSHeapSize,
          total: memInfo.totalJSHeapSize,
          limit: memInfo.jsHeapSizeLimit,
          timestamp: Date.now(),
        });

        // Check memory threshold
        if (memInfo.usedJSHeapSize > this.options.memoryThreshold) {
          this.reportMemoryWarning(memInfo);
        }
      }, 5000); // Check every 5 seconds
    }
  }

  /**
   * Start performance timer
   * @param {string} name - Timer name
   */
  startTimer(name) {
    this.timers.set(name, {
      startTime: performance.now(),
      startMark: `${name}-start`,
    });

    if ("performance" in window && "mark" in performance) {
      performance.mark(`${name}-start`);
    }
  }

  /**
   * End performance timer
   * @param {string} name - Timer name
   * @returns {number} Duration in milliseconds
   */
  endTimer(name) {
    const timer = this.timers.get(name);
    if (!timer) {
      console.warn(`Timer '${name}' not found`);
      return 0;
    }

    const endTime = performance.now();
    const duration = endTime - timer.startTime;

    if (
      "performance" in window &&
      "mark" in performance &&
      "measure" in performance
    ) {
      performance.mark(`${name}-end`);
      performance.measure(name, `${name}-start`, `${name}-end`);
    }

    this.recordMetric("timer", {
      name: name,
      duration: duration,
      startTime: timer.startTime,
      endTime: endTime,
    });

    this.timers.delete(name);
    return duration;
  }

  /**
   * Record a performance metric
   * @param {string} type - Metric type
   * @param {Object} data - Metric data
   */
  recordMetric(type, data) {
    const metric = {
      type: type,
      timestamp: Date.now(),
      ...data,
    };

    switch (type) {
      case "api":
        this.metrics.apiCalls.push(metric);
        this.limitArraySize(this.metrics.apiCalls);
        break;
      case "render":
        this.metrics.renderTimes.push(metric);
        this.limitArraySize(this.metrics.renderTimes);
        break;
      case "timer":
      case "navigation":
      case "resource":
      case "longtask":
        if (!this.metrics[type]) {
          this.metrics[type] = [];
        }
        this.metrics[type].push(metric);
        this.limitArraySize(this.metrics[type]);
        break;
    }
  }

  /**
   * Record network request
   * @param {Object} requestData - Request data
   */
  recordNetworkRequest(requestData) {
    this.metrics.networkRequests.push({
      timestamp: Date.now(),
      ...requestData,
    });
    this.limitArraySize(this.metrics.networkRequests);
  }

  /**
   * Record memory usage
   * @param {Object} memoryData - Memory data
   */
  recordMemoryUsage(memoryData) {
    this.metrics.memoryUsage.push(memoryData);
    this.limitArraySize(this.metrics.memoryUsage);
  }

  /**
   * Record cache hit
   */
  recordCacheHit() {
    this.metrics.cacheHits++;
  }

  /**
   * Record cache miss
   */
  recordCacheMiss() {
    this.metrics.cacheMisses++;
  }

  /**
   * Record error
   * @param {Error|string} error - Error object or message
   * @param {string} context - Error context
   */
  recordError(error, context = "unknown") {
    this.metrics.errors.push({
      timestamp: Date.now(),
      message: error.message || error,
      stack: error.stack || null,
      context: context,
    });
    this.limitArraySize(this.metrics.errors);
  }

  /**
   * Get performance report
   * @returns {Object} Performance report
   */
  getReport() {
    const now = Date.now();
    const oneMinuteAgo = now - 60000;

    // Calculate averages for recent metrics
    const recentApiCalls = this.metrics.apiCalls.filter(
      (m) => m.timestamp > oneMinuteAgo
    );
    const recentRenderTimes = this.metrics.renderTimes.filter(
      (m) => m.timestamp > oneMinuteAgo
    );
    const recentNetworkRequests = this.metrics.networkRequests.filter(
      (m) => m.timestamp > oneMinuteAgo
    );

    const avgApiTime = this.calculateAverage(recentApiCalls, "duration");
    const avgRenderTime = this.calculateAverage(recentRenderTimes, "duration");
    const avgNetworkTime = this.calculateAverage(
      recentNetworkRequests,
      "duration"
    );

    // Cache hit rate
    const totalCacheRequests =
      this.metrics.cacheHits + this.metrics.cacheMisses;
    const cacheHitRate =
      totalCacheRequests > 0
        ? (this.metrics.cacheHits / totalCacheRequests) * 100
        : 0;

    // Memory usage
    const latestMemory =
      this.metrics.memoryUsage[this.metrics.memoryUsage.length - 1];

    return {
      timestamp: now,
      performance: {
        avgApiResponseTime: avgApiTime,
        avgRenderTime: avgRenderTime,
        avgNetworkTime: avgNetworkTime,
        apiCallsPerMinute: recentApiCalls.length,
        networkRequestsPerMinute: recentNetworkRequests.length,
      },
      cache: {
        hitRate: cacheHitRate,
        hits: this.metrics.cacheHits,
        misses: this.metrics.cacheMisses,
      },
      memory: latestMemory
        ? {
            used: this.formatBytes(latestMemory.used),
            total: this.formatBytes(latestMemory.total),
            usagePercentage: (
              (latestMemory.used / latestMemory.total) *
              100
            ).toFixed(1),
          }
        : null,
      errors: {
        count: this.metrics.errors.length,
        recentCount: this.metrics.errors.filter(
          (e) => e.timestamp > oneMinuteAgo
        ).length,
      },
      recommendations: this.generateRecommendations(),
    };
  }

  /**
   * Generate performance recommendations
   * @returns {Array} Array of recommendations
   */
  generateRecommendations() {
    const recommendations = [];
    const report = this.getReport();

    // API performance recommendations
    if (report.performance.avgApiResponseTime > 2000) {
      recommendations.push({
        type: "warning",
        category: "api",
        message:
          "Среднее время ответа API превышает 2 секунды. Рассмотрите возможность оптимизации запросов или увеличения кэширования.",
      });
    }

    // Cache recommendations
    if (report.cache.hitRate < 50) {
      recommendations.push({
        type: "info",
        category: "cache",
        message:
          "Низкий процент попаданий в кэш. Увеличьте время жизни кэша или оптимизируйте стратегию кэширования.",
      });
    }

    // Memory recommendations
    if (report.memory && parseFloat(report.memory.usagePercentage) > 80) {
      recommendations.push({
        type: "warning",
        category: "memory",
        message:
          "Высокое использование памяти. Рассмотрите возможность очистки неиспользуемых данных или оптимизации компонентов.",
      });
    }

    // Error recommendations
    if (report.errors.recentCount > 5) {
      recommendations.push({
        type: "error",
        category: "errors",
        message: "Высокая частота ошибок. Проверьте логи и исправьте проблемы.",
      });
    }

    return recommendations;
  }

  /**
   * Start automatic reporting
   */
  startReporting() {
    if (this.reportTimer) {
      clearInterval(this.reportTimer);
    }

    this.reportTimer = setInterval(() => {
      const report = this.getReport();
      this.onReport(report);
    }, this.options.reportInterval);
  }

  /**
   * Handle performance report
   * @param {Object} report - Performance report
   */
  onReport(report) {
    // Log to console in development
    if (process.env.NODE_ENV === "development") {
      console.group("🔍 Ozon Performance Report");
      console.table(report.performance);
      console.log("Cache:", report.cache);
      console.log("Memory:", report.memory);
      if (report.recommendations.length > 0) {
        console.warn("Recommendations:", report.recommendations);
      }
      console.groupEnd();
    }

    // Send to analytics service (if configured)
    if (this.options.analyticsEndpoint) {
      this.sendToAnalytics(report);
    }

    // Trigger custom event
    window.dispatchEvent(
      new CustomEvent("ozonPerformanceReport", {
        detail: report,
      })
    );
  }

  /**
   * Send report to analytics service
   * @param {Object} report - Performance report
   */
  async sendToAnalytics(report) {
    try {
      await fetch(this.options.analyticsEndpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          type: "performance_report",
          data: report,
        }),
      });
    } catch (error) {
      console.warn("Failed to send performance report:", error);
    }
  }

  /**
   * Report memory warning
   * @param {Object} memInfo - Memory information
   */
  reportMemoryWarning(memInfo) {
    console.warn("⚠️ High memory usage detected:", {
      used: this.formatBytes(memInfo.usedJSHeapSize),
      total: this.formatBytes(memInfo.totalJSHeapSize),
      percentage:
        ((memInfo.usedJSHeapSize / memInfo.totalJSHeapSize) * 100).toFixed(1) +
        "%",
    });

    // Trigger memory warning event
    window.dispatchEvent(
      new CustomEvent("ozonMemoryWarning", {
        detail: memInfo,
      })
    );
  }

  /**
   * Calculate average of array values
   * @param {Array} array - Array of objects
   * @param {string} property - Property to average
   * @returns {number} Average value
   */
  calculateAverage(array, property) {
    if (array.length === 0) return 0;
    const sum = array.reduce((acc, item) => acc + (item[property] || 0), 0);
    return Math.round(sum / array.length);
  }

  /**
   * Format bytes to human readable format
   * @param {number} bytes - Bytes
   * @returns {string} Formatted string
   */
  formatBytes(bytes) {
    if (bytes === 0) return "0 Bytes";
    const k = 1024;
    const sizes = ["Bytes", "KB", "MB", "GB"];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
  }

  /**
   * Limit array size to prevent memory leaks
   * @param {Array} array - Array to limit
   */
  limitArraySize(array) {
    if (array.length > this.options.maxMetricsHistory) {
      array.splice(0, array.length - this.options.maxMetricsHistory);
    }
  }

  /**
   * Clear all metrics
   */
  clearMetrics() {
    this.metrics = {
      apiCalls: [],
      renderTimes: [],
      memoryUsage: [],
      networkRequests: [],
      cacheHits: 0,
      cacheMisses: 0,
      errors: [],
    };
  }

  /**
   * Destroy the performance monitor
   */
  destroy() {
    // Clear reporting timer
    if (this.reportTimer) {
      clearInterval(this.reportTimer);
      this.reportTimer = null;
    }

    // Disconnect observers
    this.observers.forEach((observer) => {
      observer.disconnect();
    });
    this.observers.clear();

    // Clear timers
    this.timers.clear();

    // Clear metrics
    this.clearMetrics();
  }
}

// Create global instance
const ozonPerformanceMonitor = new OzonPerformanceMonitor();

// Export for use in other modules
if (typeof module !== "undefined" && module.exports) {
  module.exports = { OzonPerformanceMonitor, ozonPerformanceMonitor };
} else {
  window.OzonPerformanceMonitor = OzonPerformanceMonitor;
  window.ozonPerformanceMonitor = ozonPerformanceMonitor;
}
