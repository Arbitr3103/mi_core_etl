/**
 * Ozon Analytics Integration Component
 *
 * Управляет интеграцией аналитики Ozon с основным дашбордом
 * Обрабатывает фильтры, загрузку данных и обновление графиков
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonAnalyticsIntegration {
  constructor(options = {}) {
    this.options = {
      apiBaseUrl: "/src/api/ozon-analytics.php",
      autoRefresh: false,
      refreshInterval: 300000, // 5 minutes
      cacheTimeout: 60000, // 1 minute
      ...options,
    };

    this.funnelChart = null;
    this.currentFilters = {};
    this.refreshTimer = null;
    this.cache = new Map();
    this.lazyLoaders = new Map();

    // Инициализировать обработчик ошибок Ozon
    this.errorHandler = new OzonErrorHandler({
      showToasts: true,
      autoHideToasts: true,
      toastDuration: 5000,
      ozonOptions: {
        maxRetries: 3,
        retryDelay: 2000,
        fallbackDataEnabled: true,
        gracefulDegradation: true,
        loadingTimeout: 30000,
      },
    });

    this.init();
  }

  /**
   * Initialize the integration
   */
  init() {
    this.initializeFilters();
    this.initializeFunnelChart();
    this.initializeLazyLoaders();
    this.bindEvents();
    this.loadInitialData();

    if (this.options.autoRefresh) {
      this.startAutoRefresh();
    }
  }

  /**
   * Initialize filter controls
   */
  initializeFilters() {
    // Get current filter values from the main dashboard
    this.currentFilters = {
      dateFrom:
        document.getElementById("analyticsStartDate")?.value ||
        this.getDefaultStartDate(),
      dateTo:
        document.getElementById("analyticsEndDate")?.value ||
        this.getDefaultEndDate(),
      productId: document.getElementById("analyticsProduct")?.value || null,
      campaignId: document.getElementById("analyticsCampaign")?.value || null,
    };
  }

  /**
   * Initialize funnel chart
   */
  initializeFunnelChart() {
    const funnelContainer = document.getElementById("ozonFunnelChart");
    if (funnelContainer) {
      this.funnelChart = new OzonFunnelChart("ozonFunnelChart", {
        responsive: true,
        maintainAspectRatio: false,
        showConversions: true,
        animationDuration: 600,
      });

      // Listen for stage click events
      funnelContainer.addEventListener("funnelStageClick", (event) => {
        this.handleStageClick(event.detail);
      });

      // Listen for retry events
      funnelContainer.addEventListener("retryLoad", () => {
        this.loadFunnelData(true);
      });
    }
  }

  /**
   * Initialize lazy loaders for data tables
   */
  initializeLazyLoaders() {
    // Initialize demographics table lazy loader
    const demographicsTable = document.getElementById("ozonDemographicsTable");
    if (demographicsTable && typeof OzonLazyLoader !== "undefined") {
      this.lazyLoaders.set(
        "demographics",
        new OzonLazyLoader("ozonDemographicsTable", {
          pageSize: 50,
          apiEndpoint: this.options.apiBaseUrl,
          itemTemplate: (item, index) =>
            this.getDemographicsRowTemplate(item, index),
          virtualScrolling: true,
          estimatedItemHeight: 45,
        })
      );
    }

    // Initialize campaigns table lazy loader
    const campaignsTable = document.getElementById("ozonCampaignsTable");
    if (campaignsTable && typeof OzonLazyLoader !== "undefined") {
      this.lazyLoaders.set(
        "campaigns",
        new OzonLazyLoader("ozonCampaignsTable", {
          pageSize: 30,
          apiEndpoint: this.options.apiBaseUrl,
          itemTemplate: (item, index) =>
            this.getCampaignsRowTemplate(item, index),
          virtualScrolling: false,
        })
      );
    }
  }

  /**
   * Bind event listeners
   */
  bindEvents() {
    // Initialize debouncer if available
    if (typeof ozonDebouncer !== "undefined") {
      // Create debounced filter handlers
      ozonDebouncer.createFilterHandler(
        "analyticsStartDate",
        (value) => {
          this.updateFiltersFromForm();
          this.loadFunnelData();
        },
        { delay: 500 }
      );

      ozonDebouncer.createFilterHandler(
        "analyticsEndDate",
        (value) => {
          this.updateFiltersFromForm();
          this.loadFunnelData();
        },
        { delay: 500 }
      );

      ozonDebouncer.createFilterHandler(
        "analyticsProduct",
        (value) => {
          this.updateFiltersFromForm();
          this.loadFunnelData();
        },
        { delay: 300 }
      );

      ozonDebouncer.createFilterHandler(
        "analyticsCampaign",
        (value) => {
          this.updateFiltersFromForm();
          this.loadFunnelData();
        },
        { delay: 300 }
      );
    } else {
      // Fallback to regular event listeners
      const filterElements = [
        "analyticsStartDate",
        "analyticsEndDate",
        "analyticsProduct",
        "analyticsCampaign",
      ];

      filterElements.forEach((elementId) => {
        const element = document.getElementById(elementId);
        if (element) {
          element.addEventListener("change", () => {
            this.updateFiltersFromForm();
            this.loadFunnelData();
          });
        }
      });
    }

    // Apply filters button
    const applyButton = document.getElementById("applyAnalyticsFilters");
    if (applyButton) {
      applyButton.addEventListener("click", () => {
        this.updateFiltersFromForm();
        this.loadFunnelData(true); // Force refresh
      });
    }

    // Reset filters button
    const resetButton = document.getElementById("resetAnalyticsFilters");
    if (resetButton) {
      resetButton.addEventListener("click", () => {
        this.resetFilters();
      });
    }

    // Refresh button
    const refreshButton = document.getElementById("refreshAnalytics");
    if (refreshButton) {
      refreshButton.addEventListener("click", () => {
        this.loadFunnelData(true);
      });
    }

    // Export button
    const exportButton = document.getElementById("exportAnalyticsData");
    if (exportButton) {
      exportButton.addEventListener("click", () => {
        this.exportData();
      });
    }

    // Window resize event
    window.addEventListener("resize", () => {
      if (this.funnelChart) {
        this.funnelChart.resize();
      }
    });

    // Tab switch event (when switching to analytics tab)
    const analyticsTab = document.getElementById("funnel-tab");
    if (analyticsTab) {
      analyticsTab.addEventListener("shown.bs.tab", () => {
        // Refresh chart when tab becomes visible
        setTimeout(() => {
          if (this.funnelChart) {
            this.funnelChart.resize();
          }
        }, 100);
      });
    }
  }

  /**
   * Load initial data
   */
  loadInitialData() {
    this.loadFunnelData();
    this.loadProductOptions();
    this.loadCampaignOptions();
  }

  /**
   * Update filters from form elements
   */
  updateFiltersFromForm() {
    this.currentFilters = {
      dateFrom:
        document.getElementById("analyticsStartDate")?.value ||
        this.currentFilters.dateFrom,
      dateTo:
        document.getElementById("analyticsEndDate")?.value ||
        this.currentFilters.dateTo,
      productId: document.getElementById("analyticsProduct")?.value || null,
      campaignId: document.getElementById("analyticsCampaign")?.value || null,
    };
  }

  /**
   * Load funnel data from API
   * @param {boolean} forceRefresh - Force refresh ignoring cache
   */
  async loadFunnelData(forceRefresh = false) {
    try {
      // Check cache first
      const cacheKey = this.getCacheKey("funnel", this.currentFilters);
      if (!forceRefresh && this.cache.has(cacheKey)) {
        const cachedData = this.cache.get(cacheKey);
        if (Date.now() - cachedData.timestamp < this.options.cacheTimeout) {
          this.updateFunnelChart(cachedData.data);
          return;
        }
      }

      // Build API URL
      const params = new URLSearchParams({
        action: "funnel-data",
        date_from: this.currentFilters.dateFrom,
        date_to: this.currentFilters.dateTo,
        use_cache: !forceRefresh,
      });

      if (this.currentFilters.productId) {
        params.append("product_id", this.currentFilters.productId);
      }

      if (this.currentFilters.campaignId) {
        params.append("campaign_id", this.currentFilters.campaignId);
      }

      const url = `${this.options.apiBaseUrl}?${params.toString()}`;

      // Make API request (error handler will automatically handle loading states and retries)
      const response = await fetch(url);
      const result = await response.json();

      // Cache the result
      this.cache.set(cacheKey, {
        data: result.data,
        timestamp: Date.now(),
      });

      // Сохранить в localStorage для fallback
      try {
        localStorage.setItem(
          "ozon_funnel_cache",
          JSON.stringify({
            content: result.data,
            timestamp: Date.now(),
          })
        );
      } catch (e) {
        console.warn("Could not save to localStorage:", e);
      }

      // Update chart
      this.updateFunnelChart(result.data);

      // Update KPI
      this.updateKPI(result.data);

      // Показать уведомление об успешной загрузке если были проблемы ранее
      this.showSuccessNotification("Данные воронки обновлены");
    } catch (error) {
      // Ошибка уже обработана OzonErrorHandler через перехват fetch
      console.error("Error loading funnel data:", error);
    }
  }

  /**
   * Update funnel chart with new data
   * @param {Object} data - Funnel data from API
   */
  updateFunnelChart(data) {
    if (this.funnelChart && data) {
      this.funnelChart.renderFunnel(data);
    }
  }

  /**
   * Update KPI cards with funnel data
   * @param {Object} data - Funnel data from API
   */
  updateKPI(data) {
    const totals = data.totals || {};

    // Update KPI values
    this.updateKPIValue("totalViews", totals.views || 0);
    this.updateKPIValue("totalCartAdditions", totals.cart_additions || 0);
    this.updateKPIValue("totalOrders", totals.orders || 0);
    this.updateKPIValue(
      "overallConversion",
      (totals.conversion_overall || 0).toFixed(1) + "%"
    );
  }

  /**
   * Update individual KPI value
   * @param {string} elementId - KPI element ID
   * @param {string|number} value - New value
   */
  updateKPIValue(elementId, value) {
    const element = document.getElementById(elementId);
    if (element) {
      // Add animation class
      element.classList.add("updating");

      setTimeout(() => {
        element.textContent =
          typeof value === "number" ? value.toLocaleString() : value;
        element.classList.remove("updating");
      }, 150);
    }
  }

  /**
   * Load product options for filter dropdown
   */
  async loadProductOptions() {
    try {
      // This would typically come from a products API endpoint
      // For now, we'll use a placeholder implementation
      const productSelect = document.getElementById("analyticsProduct");
      if (productSelect) {
        // Add some example options
        const options = [
          { value: "", text: "Все товары" },
          { value: "product_1", text: "Товар 1" },
          { value: "product_2", text: "Товар 2" },
        ];

        productSelect.innerHTML = options
          .map((opt) => `<option value="${opt.value}">${opt.text}</option>`)
          .join("");
      }
    } catch (error) {
      console.error("Error loading product options:", error);
    }
  }

  /**
   * Load campaign options for filter dropdown
   */
  async loadCampaignOptions() {
    try {
      const campaignSelect = document.getElementById("analyticsCampaign");
      if (campaignSelect) {
        // Add some example options
        const options = [
          { value: "", text: "Все кампании" },
          { value: "campaign_1", text: "Кампания 1" },
          { value: "campaign_2", text: "Кампания 2" },
        ];

        campaignSelect.innerHTML = options
          .map((opt) => `<option value="${opt.value}">${opt.text}</option>`)
          .join("");
      }
    } catch (error) {
      console.error("Error loading campaign options:", error);
    }
  }

  /**
   * Handle funnel stage click
   * @param {Object} detail - Click event detail
   */
  handleStageClick(detail) {
    console.log("Funnel stage clicked:", detail);

    // You can add custom logic here, such as:
    // - Showing detailed breakdown for the stage
    // - Filtering other charts based on the stage
    // - Opening a modal with more information

    this.showStageDetails(detail);
  }

  /**
   * Show detailed information for a funnel stage
   * @param {Object} stageDetail - Stage click detail
   */
  showStageDetails(stageDetail) {
    const stageNames = {
      views: "Просмотры",
      cart_additions: "Добавления в корзину",
      orders: "Заказы",
    };

    const stageName = stageNames[stageDetail.stage] || stageDetail.stage;
    const stageData = stageDetail.data.totals || {};
    const stageValue = stageData[stageDetail.stage] || 0;

    // Show a simple alert for now (you can replace with a modal)
    alert(`${stageName}: ${stageValue.toLocaleString()}`);
  }

  /**
   * Reset filters to default values
   */
  resetFilters() {
    this.currentFilters = {
      dateFrom: this.getDefaultStartDate(),
      dateTo: this.getDefaultEndDate(),
      productId: null,
      campaignId: null,
    };

    // Update form elements
    const startDateEl = document.getElementById("analyticsStartDate");
    const endDateEl = document.getElementById("analyticsEndDate");
    const productEl = document.getElementById("analyticsProduct");
    const campaignEl = document.getElementById("analyticsCampaign");

    if (startDateEl) startDateEl.value = this.currentFilters.dateFrom;
    if (endDateEl) endDateEl.value = this.currentFilters.dateTo;
    if (productEl) productEl.value = "";
    if (campaignEl) campaignEl.value = "";

    // Reload data
    this.loadFunnelData(true);
  }

  /**
   * Export analytics data
   */
  async exportData() {
    try {
      const exportData = {
        data_type: "funnel",
        format: "csv",
        date_from: this.currentFilters.dateFrom,
        date_to: this.currentFilters.dateTo,
      };

      if (this.currentFilters.productId) {
        exportData.product_id = this.currentFilters.productId;
      }

      if (this.currentFilters.campaignId) {
        exportData.campaign_id = this.currentFilters.campaignId;
      }

      const response = await fetch(this.options.apiBaseUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(exportData),
      });

      if (response.ok) {
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = `ozon_funnel_export_${
          new Date().toISOString().split("T")[0]
        }.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);

        this.showSuccessNotification("Данные экспортированы успешно");
      } else {
        throw new Error("Ошибка экспорта данных");
      }
    } catch (error) {
      console.error("Export error:", error);
      this.showErrorNotification("Ошибка экспорта: " + error.message);
    }
  }

  /**
   * Start auto-refresh timer
   */
  startAutoRefresh() {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
    }

    this.refreshTimer = setInterval(() => {
      this.loadFunnelData();
    }, this.options.refreshInterval);
  }

  /**
   * Stop auto-refresh timer
   */
  stopAutoRefresh() {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }
  }

  /**
   * Get cache key for data
   * @param {string} type - Data type
   * @param {Object} filters - Current filters
   * @returns {string} Cache key
   */
  getCacheKey(type, filters) {
    return `${type}_${JSON.stringify(filters)}`;
  }

  /**
   * Get default start date (30 days ago)
   * @returns {string} Date string in YYYY-MM-DD format
   */
  getDefaultStartDate() {
    const date = new Date();
    date.setDate(date.getDate() - 30);
    return date.toISOString().split("T")[0];
  }

  /**
   * Get default end date (today)
   * @returns {string} Date string in YYYY-MM-DD format
   */
  getDefaultEndDate() {
    return new Date().toISOString().split("T")[0];
  }

  /**
   * Show success notification
   * @param {string} message - Success message
   */
  showSuccessNotification(message) {
    if (this.errorHandler) {
      this.errorHandler.showToast({
        type: "success",
        title: "Успешно",
        message: message,
        duration: 3000,
      });
    } else {
      console.log("Success:", message);
    }
  }

  /**
   * Show error notification
   * @param {string} message - Error message
   */
  showErrorNotification(message) {
    if (this.errorHandler) {
      this.errorHandler.showToast({
        type: "error",
        title: "Ошибка",
        message: message,
      });
    } else {
      console.error("Error:", message);
    }
  }

  /**
   * Get demographics row template for lazy loading
   * @param {Object} item - Demographics data item
   * @param {number} index - Row index
   * @returns {string} HTML template
   */
  getDemographicsRowTemplate(item, index) {
    return `
      <tr class="demographics-row" data-index="${index}">
        <td>${item.age_group || "Не указано"}</td>
        <td>${
          item.gender === "male"
            ? "Мужской"
            : item.gender === "female"
            ? "Женский"
            : "Не указано"
        }</td>
        <td>${item.region || "Не указано"}</td>
        <td class="text-end">${(item.orders_count || 0).toLocaleString()}</td>
        <td class="text-end">${(item.revenue || 0).toLocaleString("ru-RU", {
          style: "currency",
          currency: "RUB",
        })}</td>
        <td class="text-end">
          <div class="progress" style="height: 20px;">
            <div class="progress-bar" role="progressbar" 
                 style="width: ${item.orders_percentage || 0}%" 
                 aria-valuenow="${item.orders_percentage || 0}" 
                 aria-valuemin="0" aria-valuemax="100">
              ${(item.orders_percentage || 0).toFixed(1)}%
            </div>
          </div>
        </td>
      </tr>
    `;
  }

  /**
   * Get campaigns row template for lazy loading
   * @param {Object} item - Campaign data item
   * @param {number} index - Row index
   * @returns {string} HTML template
   */
  getCampaignsRowTemplate(item, index) {
    const roasClass =
      (item.roas || 0) >= 2
        ? "text-success"
        : (item.roas || 0) >= 1
        ? "text-warning"
        : "text-danger";
    const ctrClass =
      (item.ctr || 0) >= 2
        ? "text-success"
        : (item.ctr || 0) >= 1
        ? "text-warning"
        : "text-danger";

    return `
      <tr class="campaigns-row" data-index="${index}">
        <td>
          <div class="fw-bold">${
            item.campaign_name || "Кампания " + item.campaign_id
          }</div>
          <small class="text-muted">${item.campaign_id}</small>
        </td>
        <td class="text-end">${(item.impressions || 0).toLocaleString()}</td>
        <td class="text-end">${(item.clicks || 0).toLocaleString()}</td>
        <td class="text-end ${ctrClass}">${(item.ctr || 0).toFixed(2)}%</td>
        <td class="text-end">${(item.spend || 0).toLocaleString("ru-RU", {
          style: "currency",
          currency: "RUB",
        })}</td>
        <td class="text-end">${(item.orders || 0).toLocaleString()}</td>
        <td class="text-end">${(item.revenue || 0).toLocaleString("ru-RU", {
          style: "currency",
          currency: "RUB",
        })}</td>
        <td class="text-end ${roasClass} fw-bold">${(item.roas || 0).toFixed(
      2
    )}</td>
      </tr>
    `;
  }

  /**
   * Refresh lazy loaders with new filters
   */
  refreshLazyLoaders() {
    this.lazyLoaders.forEach((loader, key) => {
      const filters = {
        ...this.currentFilters,
        data_type: key,
      };
      loader.refresh(filters);
    });
  }

  /**
   * Destroy the integration and clean up
   */
  destroy() {
    this.stopAutoRefresh();

    if (this.funnelChart) {
      this.funnelChart.destroy();
      this.funnelChart = null;
    }

    // Destroy lazy loaders
    this.lazyLoaders.forEach((loader) => {
      loader.destroy();
    });
    this.lazyLoaders.clear();

    // Cancel any pending debounced operations
    if (typeof ozonDebouncer !== "undefined") {
      ozonDebouncer.cancelAll();
    }

    this.cache.clear();
  }
}

// Auto-initialize when DOM is ready
document.addEventListener("DOMContentLoaded", () => {
  // Only initialize if we're on the analytics view
  if (document.getElementById("ozonAnalyticsView")) {
    window.ozonAnalytics = new OzonAnalyticsIntegration();
  }
});

// Export for use in other modules
if (typeof module !== "undefined" && module.exports) {
  module.exports = OzonAnalyticsIntegration;
}
