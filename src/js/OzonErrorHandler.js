/**
 * OzonErrorHandler - Специализированная обработка ошибок для Ozon Analytics
 *
 * Расширяет MarketplaceErrorHandler для обработки специфичных ошибок Ozon API,
 * индикаторов загрузки и graceful degradation при недоступности API
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonErrorHandler extends MarketplaceErrorHandler {
  constructor(options = {}) {
    super({
      containerSelector: ".ozon-error-container",
      showToasts: true,
      autoHideToasts: true,
      toastDuration: 6000,
      logErrors: true,
      ...options,
    });

    this.ozonOptions = {
      maxRetries: 3,
      retryDelay: 2000,
      fallbackDataEnabled: true,
      gracefulDegradation: true,
      loadingTimeout: 30000, // 30 seconds
      ...options.ozonOptions,
    };

    this.activeRequests = new Map();
    this.loadingStates = new Map();
    this.retryAttempts = new Map();

    this.initOzonSpecificHandling();
  }

  /**
   * Инициализация специфичной для Ozon обработки
   */
  initOzonSpecificHandling() {
    // Создать контейнер для Ozon ошибок если его нет
    this.createOzonErrorContainer();

    // Настроить обработку специфичных ошибок Ozon API
    this.setupOzonAPIErrorHandling();

    // Настроить мониторинг производительности
    this.setupPerformanceMonitoring();
  }

  /**
   * Создать контейнер для ошибок Ozon
   */
  createOzonErrorContainer() {
    let container = document.querySelector(".ozon-error-container");
    if (!container) {
      container = document.createElement("div");
      container.className = "ozon-error-container";

      // Найти контейнер аналитики Ozon
      const analyticsContainer = document.getElementById("ozonAnalyticsView");
      if (analyticsContainer) {
        analyticsContainer.appendChild(container);
      } else {
        document.body.appendChild(container);
      }
    }
    this.ozonErrorContainer = container;
  }

  /**
   * Настроить обработку ошибок Ozon API
   */
  setupOzonAPIErrorHandling() {
    // Перехватываем fetch запросы к Ozon API
    const originalFetch = window.fetch;
    window.fetch = async (...args) => {
      const [url] = args;

      // Проверяем, это запрос к Ozon API
      if (typeof url === "string" && url.includes("ozon-analytics.php")) {
        return this.handleOzonAPIRequest(originalFetch, ...args);
      }

      return originalFetch(...args);
    };
  }

  /**
   * Обработать запрос к Ozon API с retry логикой
   */
  async handleOzonAPIRequest(originalFetch, ...args) {
    const [url] = args;
    const requestId = this.generateRequestId(url);

    try {
      // Показать индикатор загрузки
      this.showOzonLoadingState(requestId, url);

      // Выполнить запрос с retry логикой
      const response = await this.executeWithRetry(
        originalFetch,
        requestId,
        ...args
      );

      // Скрыть индикатор загрузки
      this.hideLoadingState(requestId);

      return response;
    } catch (error) {
      // Скрыть индикатор загрузки
      this.hideLoadingState(requestId);

      // Обработать ошибку
      this.handleOzonAPIError(error, url, requestId);

      throw error;
    }
  }

  /**
   * Выполнить запрос с retry логикой
   */
  async executeWithRetry(originalFetch, requestId, ...args) {
    const maxRetries = this.ozonOptions.maxRetries;
    let lastError;

    for (let attempt = 0; attempt <= maxRetries; attempt++) {
      try {
        // Обновить счетчик попыток
        this.retryAttempts.set(requestId, attempt);

        // Добавить таймаут для запроса
        const controller = new AbortController();
        const timeoutId = setTimeout(
          () => controller.abort(),
          this.ozonOptions.loadingTimeout
        );

        const modifiedArgs = [...args];
        if (modifiedArgs[1]) {
          modifiedArgs[1] = { ...modifiedArgs[1], signal: controller.signal };
        } else {
          modifiedArgs[1] = { signal: controller.signal };
        }

        const response = await originalFetch(...modifiedArgs);
        clearTimeout(timeoutId);

        // Проверить статус ответа
        if (!response.ok) {
          const errorData = await response.json().catch(() => ({}));
          throw new OzonAPIError(
            errorData.message || `HTTP ${response.status}`,
            response.status,
            this.getOzonErrorType(response.status),
            errorData
          );
        }

        // Проверить содержимое ответа
        const data = await response.json();
        if (!data.success && data.error) {
          throw new OzonAPIError(
            data.error.message || "API Error",
            data.error.code || "API_ERROR",
            this.getOzonErrorType(data.error.code),
            data.error
          );
        }

        return { ...response, json: () => Promise.resolve(data) };
      } catch (error) {
        lastError = error;

        // Не повторяем для определенных типов ошибок
        if (this.shouldNotRetry(error)) {
          break;
        }

        // Ждем перед следующей попыткой
        if (attempt < maxRetries) {
          await this.delay(this.ozonOptions.retryDelay * (attempt + 1));
          this.showRetryNotification(attempt + 1, maxRetries);
        }
      }
    }

    throw lastError;
  }

  /**
   * Показать индикатор загрузки для Ozon
   */
  showOzonLoadingState(requestId, url) {
    const loadingInfo = {
      requestId,
      url,
      startTime: Date.now(),
      type: this.getRequestType(url),
    };

    this.activeRequests.set(requestId, loadingInfo);

    // Показать индикатор в соответствующем компоненте
    this.showComponentLoadingState(loadingInfo);

    // Показать глобальный индикатор если это первый запрос
    if (this.activeRequests.size === 1) {
      this.showGlobalLoadingIndicator();
    }
  }

  /**
   * Показать индикатор загрузки для конкретного компонента
   */
  showComponentLoadingState(loadingInfo) {
    const { type, requestId } = loadingInfo;

    switch (type) {
      case "funnel-data":
        this.showFunnelLoadingState(requestId);
        break;
      case "demographics":
        this.showDemographicsLoadingState(requestId);
        break;
      case "campaigns":
        this.showCampaignsLoadingState(requestId);
        break;
      case "export":
        this.showExportLoadingState(requestId);
        break;
      default:
        this.showGenericLoadingState(requestId);
    }
  }

  /**
   * Показать индикатор загрузки для воронки
   */
  showFunnelLoadingState(requestId) {
    const funnelContainer = document.getElementById("ozonFunnelChart");
    if (funnelContainer) {
      const loadingHTML = `
        <div class="ozon-loading-state" data-request-id="${requestId}">
          <div class="d-flex flex-column align-items-center justify-content-center p-4">
            <div class="spinner-border text-primary mb-3" role="status">
              <span class="visually-hidden">Загрузка...</span>
            </div>
            <h6 class="text-muted mb-2">Загрузка данных воронки</h6>
            <p class="text-muted small mb-0">Получение данных из Ozon API...</p>
            <div class="progress mt-3" style="width: 200px; height: 4px;">
              <div class="progress-bar progress-bar-striped progress-bar-animated" 
                   role="progressbar" style="width: 100%"></div>
            </div>
          </div>
        </div>
      `;

      funnelContainer.innerHTML = loadingHTML;
      this.loadingStates.set(requestId, {
        element: funnelContainer,
        type: "funnel",
      });
    }
  }

  /**
   * Показать индикатор загрузки для демографии
   */
  showDemographicsLoadingState(requestId) {
    const demographicsContainer = document.getElementById("ozonDemographics");
    if (demographicsContainer) {
      const loadingHTML = `
        <div class="ozon-loading-state" data-request-id="${requestId}">
          <div class="d-flex flex-column align-items-center justify-content-center p-4">
            <div class="spinner-grow text-info mb-3" role="status">
              <span class="visually-hidden">Загрузка...</span>
            </div>
            <h6 class="text-muted mb-2">Загрузка демографических данных</h6>
            <p class="text-muted small mb-0">Анализ аудитории...</p>
          </div>
        </div>
      `;

      demographicsContainer.innerHTML = loadingHTML;
      this.loadingStates.set(requestId, {
        element: demographicsContainer,
        type: "demographics",
      });
    }
  }

  /**
   * Показать индикатор загрузки для кампаний
   */
  showCampaignsLoadingState(requestId) {
    const campaignsContainer = document.getElementById("ozonCampaigns");
    if (campaignsContainer) {
      const loadingHTML = `
        <div class="ozon-loading-state" data-request-id="${requestId}">
          <div class="d-flex flex-column align-items-center justify-content-center p-3">
            <div class="spinner-border spinner-border-sm text-success mb-2" role="status">
              <span class="visually-hidden">Загрузка...</span>
            </div>
            <p class="text-muted small mb-0">Загрузка данных кампаний...</p>
          </div>
        </div>
      `;

      campaignsContainer.innerHTML = loadingHTML;
      this.loadingStates.set(requestId, {
        element: campaignsContainer,
        type: "campaigns",
      });
    }
  }

  /**
   * Показать индикатор загрузки для экспорта
   */
  showExportLoadingState(requestId) {
    const exportButton = document.getElementById("exportAnalyticsData");
    if (exportButton) {
      exportButton.disabled = true;
      exportButton.innerHTML = `
        <span class="spinner-border spinner-border-sm me-2" role="status"></span>
        Экспорт...
      `;

      this.loadingStates.set(requestId, {
        element: exportButton,
        type: "export",
      });
    }
  }

  /**
   * Показать общий индикатор загрузки
   */
  showGenericLoadingState(requestId) {
    const container =
      document.querySelector(".ozon-analytics-container") ||
      document.getElementById("ozonAnalyticsView");

    if (container) {
      let loadingOverlay = container.querySelector(".ozon-loading-overlay");
      if (!loadingOverlay) {
        loadingOverlay = document.createElement("div");
        loadingOverlay.className = "ozon-loading-overlay";
        loadingOverlay.innerHTML = `
          <div class="d-flex align-items-center justify-content-center h-100">
            <div class="text-center">
              <div class="spinner-border text-primary mb-3" role="status">
                <span class="visually-hidden">Загрузка...</span>
              </div>
              <p class="text-muted">Загрузка данных Ozon...</p>
            </div>
          </div>
        `;
        container.appendChild(loadingOverlay);
      }

      loadingOverlay.style.display = "flex";
      this.loadingStates.set(requestId, {
        element: loadingOverlay,
        type: "generic",
      });
    }
  }

  /**
   * Показать глобальный индикатор загрузки
   */
  showGlobalLoadingIndicator() {
    // Добавить класс загрузки к body
    document.body.classList.add("ozon-loading");

    // Показать индикатор в навигации
    const navIndicator = document.querySelector(".ozon-nav-loading");
    if (navIndicator) {
      navIndicator.style.display = "inline-block";
    }
  }

  /**
   * Скрыть индикатор загрузки
   */
  hideLoadingState(requestId) {
    // Удалить из активных запросов
    this.activeRequests.delete(requestId);

    // Скрыть индикатор компонента
    const loadingState = this.loadingStates.get(requestId);
    if (loadingState) {
      this.hideComponentLoadingState(loadingState);
      this.loadingStates.delete(requestId);
    }

    // Скрыть глобальный индикатор если нет активных запросов
    if (this.activeRequests.size === 0) {
      this.hideGlobalLoadingIndicator();
    }
  }

  /**
   * Скрыть индикатор загрузки компонента
   */
  hideComponentLoadingState(loadingState) {
    const { element, type } = loadingState;

    switch (type) {
      case "export":
        // Восстановить кнопку экспорта
        element.disabled = false;
        element.innerHTML = `
          <i class="fas fa-download me-2"></i>
          Экспорт данных
        `;
        break;
      case "generic":
        // Скрыть overlay
        element.style.display = "none";
        break;
      default:
        // Для других типов индикатор будет заменен данными
        break;
    }
  }

  /**
   * Скрыть глобальный индикатор загрузки
   */
  hideGlobalLoadingIndicator() {
    document.body.classList.remove("ozon-loading");

    const navIndicator = document.querySelector(".ozon-nav-loading");
    if (navIndicator) {
      navIndicator.style.display = "none";
    }
  }

  /**
   * Обработать ошибку Ozon API
   */
  handleOzonAPIError(error, url, requestId) {
    const processedError = this.processOzonError(error, url);

    if (this.options.logErrors) {
      console.error("Ozon API Error:", processedError);
    }

    // Показать fallback состояние
    this.showOzonFallbackState(processedError, requestId);

    // Показать уведомление пользователю
    this.showOzonErrorNotification(processedError);

    // Попытаться graceful degradation
    if (this.ozonOptions.gracefulDegradation) {
      this.attemptGracefulDegradation(processedError, requestId);
    }
  }

  /**
   * Обработать и нормализовать ошибку Ozon
   */
  processOzonError(error, url) {
    const requestType = this.getRequestType(url);

    // Если это уже обработанная ошибка OzonAPIError
    if (error instanceof OzonAPIError) {
      return {
        error_code: error.code,
        error_type: error.type,
        message: error.message,
        user_message: this.createOzonUserMessage(error),
        request_type: requestType,
        url: url,
        timestamp: new Date().toISOString(),
        severity: this.getOzonErrorSeverity(error),
        suggestions: this.getOzonErrorSuggestions(error),
        retry_possible: this.isRetryPossible(error),
      };
    }

    // Обработка стандартных ошибок
    if (error.name === "AbortError") {
      return {
        error_code: "TIMEOUT",
        error_type: "timeout",
        message: "Превышено время ожидания ответа",
        user_message: "Запрос занял слишком много времени. Попробуйте еще раз.",
        request_type: requestType,
        severity: "medium",
        suggestions: [
          "Проверьте интернет-соединение",
          "Попробуйте уменьшить период данных",
          "Повторите запрос через несколько минут",
        ],
        retry_possible: true,
      };
    }

    // Обработка сетевых ошибок
    if (error.message.includes("fetch")) {
      return {
        error_code: "NETWORK_ERROR",
        error_type: "network",
        message: "Ошибка сети",
        user_message:
          "Проблемы с подключением к серверу. Проверьте интернет-соединение.",
        request_type: requestType,
        severity: "high",
        suggestions: [
          "Проверьте интернет-соединение",
          "Попробуйте обновить страницу",
          "Обратитесь к администратору если проблема повторяется",
        ],
        retry_possible: true,
      };
    }

    // Общий случай
    return this.processError(error, `Ozon API (${requestType})`);
  }

  /**
   * Создать пользовательское сообщение для ошибки Ozon
   */
  createOzonUserMessage(error) {
    const messageMap = {
      AUTHENTICATION_ERROR:
        "Ошибка аутентификации в Ozon API. Проверьте настройки подключения.",
      RATE_LIMIT_EXCEEDED:
        "Превышен лимит запросов к Ozon API. Попробуйте позже.",
      API_UNAVAILABLE: "Сервис Ozon временно недоступен. Попробуйте позже.",
      INVALID_PARAMETERS: "Неверные параметры запроса. Проверьте фильтры.",
      TOKEN_EXPIRED: "Токен доступа истек. Обновление токена...",
      NO_DATA: "Нет данных за выбранный период.",
      INSUFFICIENT_PERMISSIONS: "Недостаточно прав для доступа к данным.",
      QUOTA_EXCEEDED: "Превышена квота запросов на сегодня.",
    };

    return (
      messageMap[error.code] ||
      error.message ||
      "Произошла ошибка при работе с Ozon API"
    );
  }

  /**
   * Получить уровень серьезности ошибки Ozon
   */
  getOzonErrorSeverity(error) {
    const severityMap = {
      AUTHENTICATION_ERROR: "critical",
      RATE_LIMIT_EXCEEDED: "medium",
      API_UNAVAILABLE: "high",
      INVALID_PARAMETERS: "low",
      TOKEN_EXPIRED: "medium",
      NO_DATA: "low",
      TIMEOUT: "medium",
      NETWORK_ERROR: "high",
    };

    return severityMap[error.code] || "medium";
  }

  /**
   * Получить предложения по исправлению ошибки
   */
  getOzonErrorSuggestions(error) {
    const suggestionsMap = {
      AUTHENTICATION_ERROR: [
        "Проверьте Client ID и API Key в настройках",
        "Убедитесь, что учетные данные актуальны",
        "Обратитесь к администратору системы",
      ],
      RATE_LIMIT_EXCEEDED: [
        "Подождите несколько минут перед следующим запросом",
        "Уменьшите частоту обновления данных",
        "Используйте кэшированные данные",
      ],
      API_UNAVAILABLE: [
        "Попробуйте позже",
        "Проверьте статус сервисов Ozon",
        "Используйте кэшированные данные если доступны",
      ],
      NO_DATA: [
        "Выберите другой период",
        "Проверьте настройки фильтров",
        "Убедитесь, что данные импортируются корректно",
      ],
    };

    return (
      suggestionsMap[error.code] || [
        "Попробуйте обновить страницу",
        "Проверьте интернет-соединение",
        "Обратитесь к поддержке если проблема повторяется",
      ]
    );
  }

  /**
   * Показать fallback состояние для Ozon
   */
  showOzonFallbackState(error, requestId) {
    const loadingState = this.loadingStates.get(requestId);
    if (!loadingState) return;

    const { element, type } = loadingState;

    const fallbackHTML = this.createOzonFallbackHTML(error, type);

    if (type === "export") {
      // Для кнопки экспорта просто восстанавливаем состояние
      element.disabled = false;
      element.innerHTML = `
        <i class="fas fa-download me-2"></i>
        Экспорт данных
      `;
    } else {
      element.innerHTML = fallbackHTML;
    }
  }

  /**
   * Создать HTML для fallback состояния Ozon
   */
  createOzonFallbackHTML(error, type) {
    const typeNames = {
      funnel: "воронки продаж",
      demographics: "демографических данных",
      campaigns: "данных кампаний",
      generic: "данных",
    };

    const typeName = typeNames[type] || "данных";
    const iconClass = this.getErrorIcon(error.error_type);

    return `
      <div class="ozon-fallback-state text-center p-4">
        <div class="fallback-icon mb-3">
          <i class="${iconClass} fa-3x text-muted"></i>
        </div>
        <h5 class="fallback-title text-muted mb-2">
          Не удалось загрузить ${typeName}
        </h5>
        <p class="fallback-description text-muted mb-3">
          ${error.user_message}
        </p>
        
        ${
          error.suggestions
            ? `
          <div class="fallback-suggestions mb-3">
            <h6 class="text-muted mb-2">Что можно сделать:</h6>
            <ul class="list-unstyled text-muted small">
              ${error.suggestions
                .slice(0, 3)
                .map((suggestion) => `<li class="mb-1">• ${suggestion}</li>`)
                .join("")}
            </ul>
          </div>
        `
            : ""
        }
        
        <div class="fallback-actions">
          ${
            error.retry_possible
              ? `
            <button class="btn btn-outline-primary btn-sm me-2" 
                    onclick="window.ozonAnalytics?.loadFunnelData(true)">
              <i class="fas fa-refresh me-1"></i>
              Повторить
            </button>
          `
              : ""
          }
          
          <button class="btn btn-outline-secondary btn-sm" 
                  onclick="this.closest('.ozon-fallback-state').style.display='none'">
            <i class="fas fa-times me-1"></i>
            Скрыть
          </button>
        </div>
        
        ${
          this.ozonOptions.fallbackDataEnabled
            ? `
          <div class="fallback-data-notice mt-3">
            <small class="text-muted">
              <i class="fas fa-info-circle me-1"></i>
              Показаны кэшированные данные или данные по умолчанию
            </small>
          </div>
        `
            : ""
        }
      </div>
    `;
  }

  /**
   * Показать уведомление об ошибке Ozon
   */
  showOzonErrorNotification(error) {
    const toastType =
      {
        low: "info",
        medium: "warning",
        high: "error",
        critical: "error",
      }[error.severity] || "error";

    this.showToast({
      type: toastType,
      title: "Ozon Analytics",
      message: error.user_message,
      suggestions: error.suggestions?.slice(0, 2),
      persistent: error.severity === "critical",
      duration: error.severity === "critical" ? 0 : this.options.toastDuration,
    });
  }

  /**
   * Попытаться graceful degradation
   */
  attemptGracefulDegradation(error, requestId) {
    const requestType = error.request_type;

    switch (requestType) {
      case "funnel-data":
        this.provideFallbackFunnelData(requestId);
        break;
      case "demographics":
        this.provideFallbackDemographicsData(requestId);
        break;
      case "campaigns":
        this.provideFallbackCampaignsData(requestId);
        break;
    }
  }

  /**
   * Предоставить fallback данные для воронки
   */
  provideFallbackFunnelData(requestId) {
    if (!this.ozonOptions.fallbackDataEnabled) return;

    // Попытаться получить кэшированные данные
    const cachedData = this.getCachedData("funnel");
    if (cachedData) {
      this.showCachedDataNotification("воронки продаж");

      // Обновить график с кэшированными данными
      if (window.ozonAnalytics?.funnelChart) {
        window.ozonAnalytics.funnelChart.renderFunnel(cachedData);
      }
      return;
    }

    // Показать пустые данные с объяснением
    const fallbackData = {
      totals: { views: 0, cart_additions: 0, orders: 0, conversion_overall: 0 },
      daily: [],
      message: "Данные временно недоступны",
    };

    if (window.ozonAnalytics?.funnelChart) {
      window.ozonAnalytics.funnelChart.renderFunnel(fallbackData);
    }
  }

  /**
   * Предоставить fallback данные для демографии
   */
  provideFallbackDemographicsData(requestId) {
    if (!this.ozonOptions.fallbackDataEnabled) return;

    const cachedData = this.getCachedData("demographics");
    if (cachedData) {
      this.showCachedDataNotification("демографических данных");

      if (window.ozonDemographics) {
        window.ozonDemographics.renderDemographics(cachedData);
      }
    }
  }

  /**
   * Предоставить fallback данные для кампаний
   */
  provideFallbackCampaignsData(requestId) {
    if (!this.ozonOptions.fallbackDataEnabled) return;

    const cachedData = this.getCachedData("campaigns");
    if (cachedData) {
      this.showCachedDataNotification("данных кампаний");

      // Обновить таблицу кампаний
      this.updateCampaignsTable(cachedData);
    }
  }

  /**
   * Показать уведомление о кэшированных данных
   */
  showCachedDataNotification(dataType) {
    this.showToast({
      type: "info",
      title: "Кэшированные данные",
      message: `Показаны сохраненные данные ${dataType}`,
      duration: 4000,
    });
  }

  /**
   * Показать уведомление о повторной попытке
   */
  showRetryNotification(attempt, maxAttempts) {
    this.showToast({
      type: "info",
      title: "Повторная попытка",
      message: `Попытка ${attempt} из ${maxAttempts}...`,
      duration: 2000,
    });
  }

  /**
   * Настроить мониторинг производительности
   */
  setupPerformanceMonitoring() {
    // Мониторинг времени ответа API
    this.performanceMetrics = {
      requestTimes: [],
      errorRates: new Map(),
      lastUpdate: Date.now(),
    };

    // Периодически анализировать производительность
    setInterval(() => {
      this.analyzePerformance();
    }, 60000); // каждую минуту
  }

  /**
   * Анализировать производительность
   */
  analyzePerformance() {
    const now = Date.now();
    const metrics = this.performanceMetrics;

    // Очистить старые метрики (старше 10 минут)
    metrics.requestTimes = metrics.requestTimes.filter(
      (time) => now - time.timestamp < 600000
    );

    // Проверить среднее время ответа
    if (metrics.requestTimes.length > 0) {
      const avgTime =
        metrics.requestTimes.reduce((sum, item) => sum + item.duration, 0) /
        metrics.requestTimes.length;

      if (avgTime > 10000) {
        // более 10 секунд
        this.showPerformanceWarning(
          "Медленный ответ API",
          `Среднее время ответа: ${(avgTime / 1000).toFixed(1)}с`
        );
      }
    }
  }

  /**
   * Показать предупреждение о производительности
   */
  showPerformanceWarning(title, message) {
    this.showToast({
      type: "warning",
      title: title,
      message: message,
      suggestions: [
        "Попробуйте уменьшить период данных",
        "Проверьте интернет-соединение",
      ],
    });
  }

  // Вспомогательные методы

  generateRequestId(url) {
    return `ozon_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  }

  getRequestType(url) {
    if (url.includes("funnel-data")) return "funnel-data";
    if (url.includes("demographics")) return "demographics";
    if (url.includes("campaigns")) return "campaigns";
    if (url.includes("export")) return "export";
    return "generic";
  }

  getOzonErrorType(code) {
    if (typeof code === "number") {
      if (code === 401) return "authentication";
      if (code === 429) return "rate_limit";
      if (code === 503) return "service_unavailable";
      if (code >= 500) return "server_error";
      if (code >= 400) return "client_error";
    }

    if (typeof code === "string") {
      if (code.includes("AUTH")) return "authentication";
      if (code.includes("RATE")) return "rate_limit";
      if (code.includes("TIMEOUT")) return "timeout";
    }

    return "unknown";
  }

  shouldNotRetry(error) {
    const noRetryErrors = [
      "AUTHENTICATION_ERROR",
      "INVALID_PARAMETERS",
      "INSUFFICIENT_PERMISSIONS",
    ];

    return error instanceof OzonAPIError && noRetryErrors.includes(error.code);
  }

  isRetryPossible(error) {
    return !this.shouldNotRetry(error);
  }

  getErrorIcon(errorType) {
    const iconMap = {
      authentication: "fas fa-lock",
      rate_limit: "fas fa-clock",
      timeout: "fas fa-hourglass-half",
      network: "fas fa-wifi",
      server_error: "fas fa-server",
      no_data: "fas fa-chart-line",
    };

    return iconMap[errorType] || "fas fa-exclamation-triangle";
  }

  getCachedData(type) {
    // Попытаться получить данные из localStorage
    try {
      const cached = localStorage.getItem(`ozon_${type}_cache`);
      if (cached) {
        const data = JSON.parse(cached);
        // Проверить, не устарели ли данные (максимум 1 час)
        if (Date.now() - data.timestamp < 3600000) {
          return data.content;
        }
      }
    } catch (error) {
      console.warn("Error reading cached data:", error);
    }

    return null;
  }

  updateCampaignsTable(data) {
    const table = document.getElementById("ozonCampaignsTable");
    if (table && data.campaigns) {
      // Простая реализация обновления таблицы
      // В реальном проекте здесь была бы более сложная логика
      console.log("Updating campaigns table with cached data:", data);
    }
  }

  delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }
}

/**
 * Класс для специфичных ошибок Ozon API
 */
class OzonAPIError extends Error {
  constructor(message, code, type, details = {}) {
    super(message);
    this.name = "OzonAPIError";
    this.code = code;
    this.type = type;
    this.details = details;
    this.timestamp = new Date().toISOString();
  }
}

// Экспорт для использования в других модулях
if (typeof module !== "undefined" && module.exports) {
  module.exports = { OzonErrorHandler, OzonAPIError };
}
