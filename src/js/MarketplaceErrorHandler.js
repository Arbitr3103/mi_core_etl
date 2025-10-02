/**
 * MarketplaceErrorHandler - Обработка ошибок и отображение fallback состояний
 *
 * Класс для обработки ошибок при работе с данными маркетплейсов,
 * отображения пользовательских сообщений и fallback состояний
 *
 * @version 1.0
 * @author Manhattan System
 */

class MarketplaceErrorHandler {
  constructor(options = {}) {
    this.options = {
      containerSelector: ".marketplace-error-container",
      showToasts: true,
      autoHideToasts: true,
      toastDuration: 5000,
      logErrors: true,
      ...options,
    };

    this.errorContainer = null;
    this.toastContainer = null;
    this.init();
  }

  /**
   * Инициализация обработчика ошибок
   */
  init() {
    this.createErrorContainer();
    this.createToastContainer();
    this.setupGlobalErrorHandling();
  }

  /**
   * Создать контейнер для отображения ошибок
   */
  createErrorContainer() {
    let container = document.querySelector(this.options.containerSelector);
    if (!container) {
      container = document.createElement("div");
      container.className = "marketplace-error-container";
      document.body.appendChild(container);
    }
    this.errorContainer = container;
  }

  /**
   * Создать контейнер для toast уведомлений
   */
  createToastContainer() {
    let container = document.querySelector(".toast-container");
    if (!container) {
      container = document.createElement("div");
      container.className = "toast-container position-fixed top-0 end-0 p-3";
      container.style.zIndex = "9999";
      document.body.appendChild(container);
    }
    this.toastContainer = container;
  }

  /**
   * Настроить глобальную обработку ошибок
   */
  setupGlobalErrorHandling() {
    // Обработка необработанных промисов
    window.addEventListener("unhandledrejection", (event) => {
      this.handleError({
        type: "unhandled_promise",
        message: "Необработанная ошибка в промисе",
        details: event.reason,
      });
    });

    // Обработка JavaScript ошибок
    window.addEventListener("error", (event) => {
      this.handleError({
        type: "javascript_error",
        message: event.message,
        details: {
          filename: event.filename,
          lineno: event.lineno,
          colno: event.colno,
        },
      });
    });
  }

  /**
   * Обработать ошибку API
   *
   * @param {Object} error - объект ошибки
   * @param {string} context - контекст ошибки
   * @returns {Object} обработанная ошибка
   */
  handleAPIError(error, context = "") {
    const processedError = this.processError(error, context);

    if (this.options.logErrors) {
      console.error("Marketplace API Error:", processedError);
    }

    // Показываем пользовательское сообщение
    if (processedError.userMessage) {
      this.showUserMessage(processedError);
    }

    return processedError;
  }

  /**
   * Обработать отсутствие данных
   *
   * @param {string} marketplace - маркетплейс
   * @param {string} period - период
   * @param {HTMLElement} targetElement - элемент для отображения fallback
   */
  handleMissingData(marketplace, period, targetElement) {
    const fallbackData = this.createMissingDataFallback(marketplace, period);

    if (targetElement) {
      this.renderFallbackState(targetElement, fallbackData);
    }

    if (this.options.showToasts) {
      this.showToast({
        type: "warning",
        title: "Нет данных",
        message: fallbackData.user_message,
        suggestions: fallbackData.suggestions,
      });
    }

    return fallbackData;
  }

  /**
   * Обработать ошибку валидации
   *
   * @param {Object} validationResult - результат валидации
   * @param {HTMLElement} targetElement - элемент для отображения
   */
  handleValidationError(validationResult, targetElement) {
    const severity = this.getValidationSeverity(validationResult);

    if (targetElement) {
      this.renderValidationResults(targetElement, validationResult);
    }

    if (severity === "critical" || severity === "high") {
      this.showToast({
        type: "error",
        title: "Проблемы с данными",
        message: "Обнаружены критические проблемы с качеством данных",
        persistent: true,
      });
    } else if (severity === "medium") {
      this.showToast({
        type: "warning",
        title: "Предупреждения",
        message: "Обнаружены проблемы с качеством данных",
      });
    }

    return validationResult;
  }

  /**
   * Обработать общую ошибку
   *
   * @param {Object} error - объект ошибки
   */
  handleError(error) {
    const processedError = this.processError(error);

    if (this.options.logErrors) {
      console.error("Marketplace Error:", processedError);
    }

    if (this.options.showToasts) {
      this.showToast({
        type: "error",
        title: "Ошибка",
        message: processedError.userMessage || processedError.message,
      });
    }
  }

  /**
   * Обработать и нормализовать ошибку
   *
   * @param {Object} error - исходная ошибка
   * @param {string} context - контекст ошибки
   * @returns {Object} обработанная ошибка
   */
  processError(error, context = "") {
    // Если это уже обработанная ошибка от fallback handler
    if (error.error_code && error.user_message) {
      return error;
    }

    // Если это ошибка API
    if (error.success === false && error.error) {
      return {
        error_code: error.error.code || "API_ERROR",
        message: error.error.message || "Неизвестная ошибка API",
        user_message: this.createUserFriendlyMessage(error.error),
        context: context,
        timestamp: new Date().toISOString(),
        severity: "high",
      };
    }

    // Если это стандартная JavaScript ошибка
    if (error instanceof Error) {
      return {
        error_code: "JAVASCRIPT_ERROR",
        message: error.message,
        user_message:
          "Произошла техническая ошибка. Попробуйте обновить страницу.",
        context: context,
        timestamp: new Date().toISOString(),
        severity: "medium",
        stack: error.stack,
      };
    }

    // Общий случай
    return {
      error_code: "UNKNOWN_ERROR",
      message: error.message || "Неизвестная ошибка",
      user_message:
        "Произошла неожиданная ошибка. Обратитесь к администратору.",
      context: context,
      timestamp: new Date().toISOString(),
      severity: "medium",
    };
  }

  /**
   * Создать fallback данные для отсутствующих данных
   *
   * @param {string} marketplace - маркетплейс
   * @param {string} period - период
   * @returns {Object} fallback данные
   */
  createMissingDataFallback(marketplace, period) {
    const marketplaceNames = {
      ozon: "Ozon",
      wildberries: "Wildberries",
      all: "всем маркетплейсам",
    };

    const marketplaceName = marketplaceNames[marketplace] || marketplace;

    return {
      success: true,
      has_data: false,
      marketplace: marketplace,
      marketplace_name: marketplaceName,
      period: period,
      message: `Данные по ${marketplaceName} за указанный период отсутствуют`,
      user_message: `За выбранный период нет данных по ${marketplaceName}. Попробуйте выбрать другой период или проверьте настройки импорта данных.`,
      fallback_data: this.createEmptyMarketplaceData(marketplace),
      suggestions: [
        "Проверьте правильность выбранного периода",
        "Убедитесь, что данные импортируются корректно",
        "Попробуйте расширить период поиска",
        "Обратитесь к администратору системы",
      ],
      error_code: "NO_DATA",
      severity: "medium",
    };
  }

  /**
   * Создать пустую структуру данных маркетплейса
   *
   * @param {string} marketplace - маркетплейс
   * @returns {Object} пустые данные
   */
  createEmptyMarketplaceData(marketplace) {
    return {
      marketplace: marketplace,
      kpi: {
        total_revenue: 0,
        total_orders: 0,
        total_profit: 0,
        avg_margin_percent: null,
        unique_products: 0,
      },
      top_products: [],
      daily_chart: [],
      recommendations: [],
    };
  }

  /**
   * Отобразить fallback состояние
   *
   * @param {HTMLElement} element - элемент для отображения
   * @param {Object} fallbackData - данные fallback
   */
  renderFallbackState(element, fallbackData) {
    const fallbackHTML = `
            <div class="marketplace-fallback-state text-center p-4">
                <div class="fallback-icon mb-3">
                    <i class="fas fa-chart-line fa-3x text-muted"></i>
                </div>
                <h5 class="fallback-title text-muted">${
                  fallbackData.message
                }</h5>
                <p class="fallback-description text-muted mb-3">${
                  fallbackData.user_message
                }</p>
                
                ${
                  fallbackData.suggestions
                    ? `
                    <div class="fallback-suggestions">
                        <h6 class="text-muted mb-2">Рекомендации:</h6>
                        <ul class="list-unstyled text-muted small">
                            ${fallbackData.suggestions
                              .map((suggestion) => `<li>• ${suggestion}</li>`)
                              .join("")}
                        </ul>
                    </div>
                `
                    : ""
                }
                
                <button class="btn btn-outline-primary btn-sm mt-3" onclick="location.reload()">
                    <i class="fas fa-refresh me-1"></i>
                    Обновить данные
                </button>
            </div>
        `;

    element.innerHTML = fallbackHTML;
    element.classList.add("marketplace-fallback");
  }

  /**
   * Отобразить результаты валидации
   *
   * @param {HTMLElement} element - элемент для отображения
   * @param {Object} validationResult - результаты валидации
   */
  renderValidationResults(element, validationResult) {
    const severity = this.getValidationSeverity(validationResult);
    const severityClass =
      {
        low: "info",
        medium: "warning",
        high: "danger",
        critical: "danger",
      }[severity] || "info";

    const validationHTML = `
            <div class="marketplace-validation-results">
                <div class="alert alert-${severityClass}" role="alert">
                    <h6 class="alert-heading">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Результаты проверки данных
                    </h6>
                    
                    <div class="validation-summary mb-3">
                        <strong>Статус:</strong> ${this.getStatusText(
                          validationResult.overall_status
                        )}
                    </div>
                    
                    ${
                      validationResult.errors &&
                      validationResult.errors.length > 0
                        ? `
                        <div class="validation-errors mb-3">
                            <strong>Критические проблемы:</strong>
                            <ul class="mb-0 mt-1">
                                ${validationResult.errors
                                  .map((error) => `<li>${error}</li>`)
                                  .join("")}
                            </ul>
                        </div>
                    `
                        : ""
                    }
                    
                    ${
                      validationResult.warnings &&
                      validationResult.warnings.length > 0
                        ? `
                        <div class="validation-warnings mb-3">
                            <strong>Предупреждения:</strong>
                            <ul class="mb-0 mt-1">
                                ${validationResult.warnings
                                  .map((warning) => `<li>${warning}</li>`)
                                  .join("")}
                            </ul>
                        </div>
                    `
                        : ""
                    }
                    
                    ${
                      validationResult.recommendations &&
                      validationResult.recommendations.length > 0
                        ? `
                        <div class="validation-recommendations">
                            <strong>Рекомендации:</strong>
                            <ul class="mb-0 mt-1">
                                ${validationResult.recommendations
                                  .map((rec) => `<li>${rec}</li>`)
                                  .join("")}
                            </ul>
                        </div>
                    `
                        : ""
                    }
                </div>
            </div>
        `;

    element.innerHTML = validationHTML;
  }

  /**
   * Показать toast уведомление
   *
   * @param {Object} options - параметры уведомления
   */
  showToast(options) {
    if (!this.options.showToasts) return;

    const toastId = "toast-" + Date.now();
    const typeClass =
      {
        success: "text-bg-success",
        warning: "text-bg-warning",
        error: "text-bg-danger",
        info: "text-bg-info",
      }[options.type] || "text-bg-info";

    const toastHTML = `
            <div id="${toastId}" class="toast ${typeClass}" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header">
                    <strong class="me-auto">${
                      options.title || "Уведомление"
                    }</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    ${options.message}
                    ${
                      options.suggestions
                        ? `
                        <div class="mt-2">
                            <small class="text-muted">
                                ${options.suggestions.slice(0, 2).join("; ")}
                            </small>
                        </div>
                    `
                        : ""
                    }
                </div>
            </div>
        `;

    this.toastContainer.insertAdjacentHTML("beforeend", toastHTML);

    const toastElement = document.getElementById(toastId);
    const toast = new bootstrap.Toast(toastElement, {
      autohide: !options.persistent && this.options.autoHideToasts,
      delay: options.duration || this.options.toastDuration,
    });

    toast.show();

    // Удаляем элемент после скрытия
    toastElement.addEventListener("hidden.bs.toast", () => {
      toastElement.remove();
    });
  }

  /**
   * Создать пользовательское сообщение из ошибки API
   *
   * @param {Object} apiError - ошибка API
   * @returns {string} пользовательское сообщение
   */
  createUserFriendlyMessage(apiError) {
    const errorMessages = {
      NO_DATA: "Нет данных за выбранный период",
      MARKETPLACE_NOT_FOUND: "Не удалось определить маркетплейс",
      INVALID_MARKETPLACE: "Указан неподдерживаемый маркетплейс",
      DATA_INCONSISTENCY: "Обнаружены расхождения в данных",
      DATABASE_ERROR: "Временные проблемы с доступом к данным",
      VALIDATION_FAILED: "Ошибка валидации данных",
    };

    return (
      errorMessages[apiError.code] ||
      apiError.message ||
      "Произошла неожиданная ошибка"
    );
  }

  /**
   * Получить уровень серьезности валидации
   *
   * @param {Object} validationResult - результат валидации
   * @returns {string} уровень серьезности
   */
  getValidationSeverity(validationResult) {
    if (validationResult.errors && validationResult.errors.length > 0) {
      return "critical";
    } else if (
      validationResult.warnings &&
      validationResult.warnings.length > 0
    ) {
      return "medium";
    } else if (validationResult.overall_status === "warning") {
      return "medium";
    } else if (validationResult.overall_status === "failed") {
      return "high";
    } else {
      return "low";
    }
  }

  /**
   * Получить текстовое описание статуса
   *
   * @param {string} status - статус
   * @returns {string} текстовое описание
   */
  getStatusText(status) {
    const statusTexts = {
      passed: "✅ Все проверки пройдены",
      warning: "⚠️ Есть предупреждения",
      failed: "❌ Обнаружены критические проблемы",
      error: "🔧 Ошибка при проверке",
    };

    return statusTexts[status] || status;
  }

  /**
   * Очистить все уведомления
   */
  clearAllToasts() {
    const toasts = this.toastContainer.querySelectorAll(".toast");
    toasts.forEach((toast) => {
      const bsToast = bootstrap.Toast.getInstance(toast);
      if (bsToast) {
        bsToast.hide();
      }
    });
  }

  /**
   * Показать индикатор загрузки с возможностью отмены
   *
   * @param {HTMLElement} element - элемент для отображения
   * @param {string} message - сообщение
   * @returns {Function} функция для скрытия индикатора
   */
  showLoadingState(element, message = "Загрузка данных...") {
    const loadingHTML = `
            <div class="marketplace-loading-state text-center p-4">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Загрузка...</span>
                </div>
                <p class="text-muted">${message}</p>
            </div>
        `;

    element.innerHTML = loadingHTML;
    element.classList.add("marketplace-loading");

    return () => {
      element.classList.remove("marketplace-loading");
    };
  }
}

// Экспорт для использования в других модулях
if (typeof module !== "undefined" && module.exports) {
  module.exports = MarketplaceErrorHandler;
}
