/**
 * Frontend Error Tracking System
 *
 * Captures and reports frontend errors to the backend logging system
 */

class ErrorTracker {
  constructor() {
    this.apiEndpoint = "/api/log-frontend-error.php";
    this.sessionId = this.generateSessionId();
    this.userId = null;
    this.setupGlobalErrorHandlers();
  }

  generateSessionId() {
    return "sess_" + Date.now() + "_" + Math.random().toString(36).substr(2, 9);
  }

  setUserId(userId) {
    this.userId = userId;
  }

  setupGlobalErrorHandlers() {
    // Capture JavaScript errors
    window.addEventListener("error", (event) => {
      this.logError("javascript_error", {
        message: event.message,
        filename: event.filename,
        lineno: event.lineno,
        colno: event.colno,
        stack: event.error?.stack,
        timestamp: new Date().toISOString(),
      });
    });

    // Capture unhandled promise rejections
    window.addEventListener("unhandledrejection", (event) => {
      this.logError("unhandled_promise_rejection", {
        reason: event.reason?.toString() || "Unknown reason",
        stack: event.reason?.stack,
        timestamp: new Date().toISOString(),
      });
    });

    // Capture React errors (if using React error boundary)
    this.setupReactErrorBoundary();
  }

  setupReactErrorBoundary() {
    // This will be used by React Error Boundary component
    window.reportReactError = (error, errorInfo) => {
      this.logError("react_error", {
        message: error.message,
        stack: error.stack,
        componentStack: errorInfo.componentStack,
        timestamp: new Date().toISOString(),
      });
    };
  }

  async logError(type, errorData) {
    try {
      const payload = {
        type,
        error: errorData,
        context: {
          sessionId: this.sessionId,
          userId: this.userId,
          url: window.location.href,
          userAgent: navigator.userAgent,
          timestamp: new Date().toISOString(),
          viewport: {
            width: window.innerWidth,
            height: window.innerHeight,
          },
          screen: {
            width: screen.width,
            height: screen.height,
          },
          connection: navigator.connection
            ? {
                effectiveType: navigator.connection.effectiveType,
                downlink: navigator.connection.downlink,
              }
            : null,
        },
      };

      // Send to backend (don't await to avoid blocking)
      fetch(this.apiEndpoint, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(payload),
      }).catch((err) => {
        // Fallback: store in localStorage for later retry
        this.storeErrorForRetry(payload);
      });
    } catch (err) {
      // Fallback: store in localStorage
      this.storeErrorForRetry({ type, error: errorData });
    }
  }

  storeErrorForRetry(errorData) {
    try {
      const stored = JSON.parse(localStorage.getItem("pendingErrors") || "[]");
      stored.push({
        ...errorData,
        storedAt: Date.now(),
      });

      // Keep only last 50 errors
      if (stored.length > 50) {
        stored.splice(0, stored.length - 50);
      }

      localStorage.setItem("pendingErrors", JSON.stringify(stored));
    } catch (err) {
      // If localStorage fails, just ignore
    }
  }

  async retryPendingErrors() {
    try {
      const stored = JSON.parse(localStorage.getItem("pendingErrors") || "[]");
      if (stored.length === 0) return;

      for (const errorData of stored) {
        await fetch(this.apiEndpoint, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
          },
          body: JSON.stringify(errorData),
        });
      }

      // Clear stored errors after successful retry
      localStorage.removeItem("pendingErrors");
    } catch (err) {
      // Keep errors for next retry
    }
  }

  // Manual error logging for specific cases
  logApiError(endpoint, method, error, responseData = null) {
    this.logError("api_error", {
      endpoint,
      method,
      error: error.message || error.toString(),
      responseData,
      timestamp: new Date().toISOString(),
    });
  }

  logPerformanceIssue(metric, value, threshold) {
    this.logError("performance_issue", {
      metric,
      value,
      threshold,
      timestamp: new Date().toISOString(),
    });
  }

  logUserAction(action, data = {}) {
    // Log important user actions for debugging
    this.logError("user_action", {
      action,
      data,
      timestamp: new Date().toISOString(),
    });
  }

  // Performance monitoring
  startPerformanceMonitoring() {
    // Monitor page load performance
    window.addEventListener("load", () => {
      setTimeout(() => {
        const perfData = performance.getEntriesByType("navigation")[0];
        if (perfData) {
          const loadTime = perfData.loadEventEnd - perfData.fetchStart;

          // Log slow page loads (> 5 seconds)
          if (loadTime > 5000) {
            this.logPerformanceIssue("page_load_time", loadTime, 5000);
          }
        }
      }, 1000);
    });

    // Monitor API response times
    this.monitorFetchRequests();
  }

  monitorFetchRequests() {
    const originalFetch = window.fetch;

    window.fetch = async (...args) => {
      const startTime = performance.now();

      try {
        const response = await originalFetch(...args);
        const endTime = performance.now();
        const duration = endTime - startTime;

        // Log slow API calls (> 3 seconds)
        if (duration > 3000) {
          this.logPerformanceIssue("api_response_time", duration, 3000);
        }

        return response;
      } catch (error) {
        const endTime = performance.now();
        const duration = endTime - startTime;

        this.logApiError(args[0], args[1]?.method || "GET", error);
        throw error;
      }
    };
  }
}

// React Error Boundary Component
export class ErrorBoundary extends React.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError(error) {
    return { hasError: true };
  }

  componentDidCatch(error, errorInfo) {
    if (window.reportReactError) {
      window.reportReactError(error, errorInfo);
    }
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="error-boundary">
          <h2>Something went wrong</h2>
          <p>We've been notified of this error and are working to fix it.</p>
          <button onClick={() => window.location.reload()}>Reload Page</button>
        </div>
      );
    }

    return this.props.children;
  }
}

// Create global instance
const errorTracker = new ErrorTracker();

// Start monitoring when DOM is ready
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => {
    errorTracker.startPerformanceMonitoring();
    errorTracker.retryPendingErrors();
  });
} else {
  errorTracker.startPerformanceMonitoring();
  errorTracker.retryPendingErrors();
}

export default errorTracker;
