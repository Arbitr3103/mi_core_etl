/**
 * OzonSecurityIntegration - Frontend security integration for Ozon Analytics
 *
 * Handles security-related functionality on the frontend including:
 * - User authentication status
 * - Permission checking
 * - Rate limiting feedback
 * - Security event logging
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonSecurityIntegration {
  constructor(config = {}) {
    this.config = {
      apiBaseUrl: "/src/api/ozon-analytics.php",
      checkInterval: 30000, // 30 seconds
      maxRetries: 3,
      retryDelay: 1000,
      showSecurityAlerts: true,
      logClientEvents: true,
      ...config,
    };

    this.userPermissions = null;
    this.rateLimits = null;
    this.securityStatus = "unknown";
    this.lastSecurityCheck = 0;
    this.eventListeners = new Map();

    this.init();
  }

  /**
   * Initialize security integration
   */
  init() {
    this.checkUserPermissions();
    this.setupPeriodicChecks();
    this.setupErrorHandling();
    this.bindSecurityEvents();

    console.log("OzonSecurityIntegration initialized");
  }

  /**
   * Check user permissions and security status
   */
  async checkUserPermissions() {
    try {
      const response = await this.makeSecureRequest("user-permissions");

      if (response.success) {
        this.userPermissions = response.data.permissions || [];
        this.rateLimits = response.data.rate_limits || {};
        this.securityStatus = "authenticated";

        this.updateUIBasedOnPermissions();
        this.triggerEvent("permissionsUpdated", response.data);
      } else {
        this.handleSecurityError(response);
      }
    } catch (error) {
      console.error("Error checking user permissions:", error);
      this.securityStatus = "error";
      this.handleSecurityError(error);
    }

    this.lastSecurityCheck = Date.now();
  }

  /**
   * Check if user has permission for specific operation
   */
  hasPermission(operation) {
    if (!this.userPermissions) {
      return false;
    }

    return this.userPermissions.includes(operation);
  }

  /**
   * Check if operation is within rate limits
   */
  checkRateLimit(operation) {
    if (!this.rateLimits || !this.rateLimits[operation]) {
      return { allowed: true, remaining: null };
    }

    const limit = this.rateLimits[operation];
    // This is a simplified check - in real implementation,
    // you'd track actual usage

    return {
      allowed: true,
      remaining: limit,
      resetTime: Date.now() + 3600000, // 1 hour from now
    };
  }

  /**
   * Make a secure API request with error handling
   */
  async makeSecureRequest(endpoint, options = {}) {
    const url = `${this.config.apiBaseUrl}?action=${endpoint}`;
    const requestOptions = {
      method: "GET",
      headers: {
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        ...options.headers,
      },
      ...options,
    };

    // Add user identification if available
    const userId = this.getCurrentUserId();
    if (userId) {
      requestOptions.headers["X-User-ID"] = userId;
    }

    let lastError;

    for (let attempt = 1; attempt <= this.config.maxRetries; attempt++) {
      try {
        const response = await fetch(url, requestOptions);
        const data = await response.json();

        // Handle security-specific errors
        if (!response.ok) {
          this.handleHttpError(response, data);
        }

        // Log successful request if enabled
        if (this.config.logClientEvents) {
          this.logSecurityEvent("API_REQUEST_SUCCESS", {
            endpoint,
            attempt,
            status: response.status,
          });
        }

        return data;
      } catch (error) {
        lastError = error;

        if (attempt < this.config.maxRetries) {
          await this.delay(this.config.retryDelay * attempt);
          continue;
        }

        // Log failed request
        if (this.config.logClientEvents) {
          this.logSecurityEvent("API_REQUEST_FAILED", {
            endpoint,
            attempts: attempt,
            error: error.message,
          });
        }
      }
    }

    throw lastError;
  }

  /**
   * Handle HTTP errors with security context
   */
  handleHttpError(response, data) {
    const errorType = data.error_type || "unknown";

    switch (response.status) {
      case 401:
        this.handleAuthenticationError(data);
        break;
      case 403:
        this.handleAccessDeniedError(data);
        break;
      case 429:
        this.handleRateLimitError(data);
        break;
      default:
        this.handleGenericError(response, data);
    }

    throw new SecurityError(
      data.message || "Security error",
      response.status,
      errorType
    );
  }

  /**
   * Handle authentication errors
   */
  handleAuthenticationError(data) {
    this.securityStatus = "unauthenticated";
    this.userPermissions = null;
    this.rateLimits = null;

    if (this.config.showSecurityAlerts) {
      this.showSecurityAlert("Требуется аутентификация", "warning");
    }

    this.triggerEvent("authenticationRequired", data);
  }

  /**
   * Handle access denied errors
   */
  handleAccessDeniedError(data) {
    if (this.config.showSecurityAlerts) {
      this.showSecurityAlert("Доступ запрещен: " + data.message, "error");
    }

    this.triggerEvent("accessDenied", data);
  }

  /**
   * Handle rate limit errors
   */
  handleRateLimitError(data) {
    if (this.config.showSecurityAlerts) {
      this.showSecurityAlert(
        "Превышен лимит запросов. Попробуйте позже.",
        "warning"
      );
    }

    this.triggerEvent("rateLimitExceeded", data);

    // Disable UI temporarily
    this.temporarilyDisableUI(60000); // 1 minute
  }

  /**
   * Handle generic errors
   */
  handleGenericError(response, data) {
    console.error("Security error:", response.status, data);

    if (this.config.showSecurityAlerts) {
      this.showSecurityAlert("Ошибка безопасности: " + data.message, "error");
    }
  }

  /**
   * Show security alert to user
   */
  showSecurityAlert(message, type = "info") {
    // Create alert element
    const alert = document.createElement("div");
    alert.className = `alert alert-${type} alert-dismissible fade show security-alert`;
    alert.innerHTML = `
            <strong>Безопасность:</strong> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

    // Add to page
    const container =
      document.querySelector(".container-fluid") || document.body;
    container.insertBefore(alert, container.firstChild);

    // Auto-remove after 5 seconds
    setTimeout(() => {
      if (alert.parentNode) {
        alert.remove();
      }
    }, 5000);
  }

  /**
   * Update UI based on user permissions
   */
  updateUIBasedOnPermissions() {
    // Hide/show elements based on permissions
    const permissionElements = document.querySelectorAll("[data-permission]");

    permissionElements.forEach((element) => {
      const requiredPermission = element.dataset.permission;
      const hasAccess = this.hasPermission(requiredPermission);

      if (hasAccess) {
        element.style.display = "";
        element.removeAttribute("disabled");
      } else {
        element.style.display = "none";
        element.setAttribute("disabled", "disabled");
      }
    });

    // Update rate limit indicators
    this.updateRateLimitIndicators();
  }

  /**
   * Update rate limit indicators in UI
   */
  updateRateLimitIndicators() {
    const rateLimitElements = document.querySelectorAll("[data-rate-limit]");

    rateLimitElements.forEach((element) => {
      const operation = element.dataset.rateLimit;
      const limitInfo = this.checkRateLimit(operation);

      if (limitInfo.remaining !== null) {
        const indicator =
          element.querySelector(".rate-limit-indicator") ||
          this.createRateLimitIndicator(element);

        indicator.textContent = `Осталось: ${limitInfo.remaining}`;

        if (limitInfo.remaining < 10) {
          indicator.className = "rate-limit-indicator text-warning";
        } else if (limitInfo.remaining < 5) {
          indicator.className = "rate-limit-indicator text-danger";
        } else {
          indicator.className = "rate-limit-indicator text-muted";
        }
      }
    });
  }

  /**
   * Create rate limit indicator element
   */
  createRateLimitIndicator(parentElement) {
    const indicator = document.createElement("small");
    indicator.className = "rate-limit-indicator text-muted";
    parentElement.appendChild(indicator);
    return indicator;
  }

  /**
   * Temporarily disable UI elements
   */
  temporarilyDisableUI(duration) {
    const disableableElements = document.querySelectorAll(
      'button, input[type="submit"]'
    );

    disableableElements.forEach((element) => {
      element.disabled = true;
      element.dataset.temporarilyDisabled = "true";
    });

    setTimeout(() => {
      disableableElements.forEach((element) => {
        if (element.dataset.temporarilyDisabled === "true") {
          element.disabled = false;
          delete element.dataset.temporarilyDisabled;
        }
      });
    }, duration);
  }

  /**
   * Setup periodic security checks
   */
  setupPeriodicChecks() {
    setInterval(() => {
      const timeSinceLastCheck = Date.now() - this.lastSecurityCheck;

      if (timeSinceLastCheck >= this.config.checkInterval) {
        this.checkUserPermissions();
      }
    }, this.config.checkInterval);
  }

  /**
   * Setup global error handling
   */
  setupErrorHandling() {
    window.addEventListener("unhandledrejection", (event) => {
      if (event.reason instanceof SecurityError) {
        console.error("Unhandled security error:", event.reason);
        this.handleSecurityError(event.reason);
        event.preventDefault();
      }
    });
  }

  /**
   * Bind security-related events
   */
  bindSecurityEvents() {
    // Monitor form submissions for security
    document.addEventListener("submit", (event) => {
      const form = event.target;
      if (form.dataset.securityCheck) {
        const operation = form.dataset.securityCheck;

        if (!this.hasPermission(operation)) {
          event.preventDefault();
          this.showSecurityAlert(
            "У вас нет прав для выполнения этой операции",
            "error"
          );
          return;
        }

        const rateLimitCheck = this.checkRateLimit(operation);
        if (!rateLimitCheck.allowed) {
          event.preventDefault();
          this.showSecurityAlert(
            "Превышен лимит запросов для этой операции",
            "warning"
          );
          return;
        }
      }
    });

    // Monitor button clicks for security
    document.addEventListener("click", (event) => {
      const button = event.target.closest("[data-security-check]");
      if (button) {
        const operation = button.dataset.securityCheck;

        if (!this.hasPermission(operation)) {
          event.preventDefault();
          this.showSecurityAlert(
            "У вас нет прав для выполнения этой операции",
            "error"
          );
          return;
        }
      }
    });
  }

  /**
   * Log security event on client side
   */
  logSecurityEvent(eventType, details) {
    if (!this.config.logClientEvents) {
      return;
    }

    const logEntry = {
      timestamp: new Date().toISOString(),
      event_type: eventType,
      user_id: this.getCurrentUserId(),
      details: details,
      user_agent: navigator.userAgent,
      url: window.location.href,
    };

    // Store in localStorage for later transmission
    const logs = JSON.parse(localStorage.getItem("ozon_security_logs") || "[]");
    logs.push(logEntry);

    // Keep only last 100 entries
    if (logs.length > 100) {
      logs.splice(0, logs.length - 100);
    }

    localStorage.setItem("ozon_security_logs", JSON.stringify(logs));

    // Send logs periodically
    this.sendPendingLogs();
  }

  /**
   * Send pending security logs to server
   */
  async sendPendingLogs() {
    const logs = JSON.parse(localStorage.getItem("ozon_security_logs") || "[]");

    if (logs.length === 0) {
      return;
    }

    try {
      await this.makeSecureRequest("log-client-events", {
        method: "POST",
        body: JSON.stringify({ logs }),
      });

      // Clear sent logs
      localStorage.removeItem("ozon_security_logs");
    } catch (error) {
      console.error("Failed to send security logs:", error);
    }
  }

  /**
   * Get current user ID
   */
  getCurrentUserId() {
    // Try multiple sources for user ID
    return (
      sessionStorage.getItem("user_id") ||
      localStorage.getItem("user_id") ||
      document.querySelector('meta[name="user-id"]')?.content ||
      "anonymous"
    );
  }

  /**
   * Add event listener
   */
  addEventListener(eventType, callback) {
    if (!this.eventListeners.has(eventType)) {
      this.eventListeners.set(eventType, []);
    }
    this.eventListeners.get(eventType).push(callback);
  }

  /**
   * Remove event listener
   */
  removeEventListener(eventType, callback) {
    if (this.eventListeners.has(eventType)) {
      const listeners = this.eventListeners.get(eventType);
      const index = listeners.indexOf(callback);
      if (index > -1) {
        listeners.splice(index, 1);
      }
    }
  }

  /**
   * Trigger event
   */
  triggerEvent(eventType, data) {
    if (this.eventListeners.has(eventType)) {
      this.eventListeners.get(eventType).forEach((callback) => {
        try {
          callback(data);
        } catch (error) {
          console.error(`Error in event listener for ${eventType}:`, error);
        }
      });
    }
  }

  /**
   * Handle security error
   */
  handleSecurityError(error) {
    this.logSecurityEvent("CLIENT_SECURITY_ERROR", {
      error_type: error.errorType || "unknown",
      error_message: error.message,
      error_code: error.code,
    });

    this.triggerEvent("securityError", error);
  }

  /**
   * Utility: delay function
   */
  delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  /**
   * Get security status
   */
  getSecurityStatus() {
    return {
      status: this.securityStatus,
      permissions: this.userPermissions,
      rateLimits: this.rateLimits,
      lastCheck: this.lastSecurityCheck,
    };
  }
}

/**
 * Custom Security Error class
 */
class SecurityError extends Error {
  constructor(message, code = 0, errorType = "SECURITY_ERROR") {
    super(message);
    this.name = "SecurityError";
    this.code = code;
    this.errorType = errorType;
  }
}

// Export for use in other modules
if (typeof module !== "undefined" && module.exports) {
  module.exports = { OzonSecurityIntegration, SecurityError };
}
