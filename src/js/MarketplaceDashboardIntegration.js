/**
 * MarketplaceDashboardIntegration - Main integration class
 * Combines MarketplaceViewToggle and MarketplaceDataRenderer for complete functionality
 */
class MarketplaceDashboardIntegration {
  constructor(containerId, apiEndpoint, options = {}) {
    this.containerId = containerId;
    this.apiEndpoint = apiEndpoint;
    this.options = {
      autoInit: true,
      enableComparison: false,
      refreshInterval: null, // Auto-refresh interval in ms
      ...options,
    };

    // Component instances
    this.viewToggle = null;
    this.dataRenderer = null;
    this.refreshTimer = null;

    // State
    this.currentData = null;
    this.isInitialized = false;

    if (this.options.autoInit) {
      this.init();
    }
  }

  /**
   * Initialize the integration
   */
  async init() {
    try {
      // Initialize data renderer
      this.dataRenderer = new MarketplaceDataRenderer(this.options);

      // Initialize view toggle with callbacks
      this.viewToggle = new MarketplaceViewToggle(
        this.containerId,
        this.apiEndpoint,
        {
          onViewChange: this.handleViewChange.bind(this),
          onDataLoad: this.handleDataLoad.bind(this),
          onError: this.handleError.bind(this),
          ...this.options,
        }
      );

      // Setup auto-refresh if enabled
      if (this.options.refreshInterval) {
        this.setupAutoRefresh();
      }

      // Setup event listeners
      this.setupEventListeners();

      this.isInitialized = true;

      // Trigger initial data load
      await this.refreshData();
    } catch (error) {
      console.error(
        "Error initializing MarketplaceDashboardIntegration:",
        error
      );
      this.handleError(error, "initialization");
    }
  }

  /**
   * Handle view mode change
   * @param {string} newView - New view mode
   * @param {string} previousView - Previous view mode
   */
  handleViewChange(newView, previousView) {
    console.log(`View changed from ${previousView} to ${newView}`);

    // Clear existing data display
    if (newView === "separated") {
      this.showLoadingForBothMarketplaces();
    }

    // Trigger custom event
    this.dispatchEvent("viewChanged", {
      newView,
      previousView,
      timestamp: new Date(),
    });
  }

  /**
   * Handle data load completion
   * @param {Object} data - Loaded data
   * @param {string} viewMode - Current view mode
   */
  handleDataLoad(data, viewMode) {
    this.currentData = data;

    if (viewMode === "separated") {
      this.renderSeparatedView(data);
    } else {
      this.renderCombinedView(data);
    }

    // Trigger custom event
    this.dispatchEvent("dataLoaded", {
      data,
      viewMode,
      timestamp: new Date(),
    });
  }

  /**
   * Handle errors
   * @param {Error} error - Error object
   * @param {string} context - Error context
   */
  handleError(error, context) {
    console.error(`Error in ${context}:`, error);

    const currentView = this.viewToggle?.getCurrentView() || "unknown";

    if (currentView === "separated") {
      // Show error for both marketplaces
      this.dataRenderer?.showErrorState("ozon", error.message);
      this.dataRenderer?.showErrorState("wildberries", error.message);
    }

    // Trigger custom event
    this.dispatchEvent("error", {
      error,
      context,
      viewMode: currentView,
      timestamp: new Date(),
    });
  }

  /**
   * Render separated view with marketplace data
   * @param {Object} data - Data object containing marketplace information
   */
  renderSeparatedView(data) {
    if (!data || !data.marketplaces) {
      this.dataRenderer?.showEmptyState("ozon");
      this.dataRenderer?.showEmptyState("wildberries");
      return;
    }

    const { ozon, wildberries } = data.marketplaces;

    // Render Ozon data
    if (ozon && ozon.kpi) {
      this.dataRenderer?.renderKPICards("ozon", ozon.kpi);

      if (ozon.daily_chart && ozon.daily_chart.length > 0) {
        this.dataRenderer?.renderDailyChart("ozon", ozon.daily_chart);
      }

      if (ozon.top_products) {
        this.dataRenderer?.renderTopProducts("ozon", ozon.top_products);
      }
    } else {
      this.dataRenderer?.showEmptyState("ozon");
    }

    // Render Wildberries data
    if (wildberries && wildberries.kpi) {
      this.dataRenderer?.renderKPICards("wildberries", wildberries.kpi);

      if (wildberries.daily_chart && wildberries.daily_chart.length > 0) {
        this.dataRenderer?.renderDailyChart(
          "wildberries",
          wildberries.daily_chart
        );
      }

      if (wildberries.top_products) {
        this.dataRenderer?.renderTopProducts(
          "wildberries",
          wildberries.top_products
        );
      }
    } else {
      this.dataRenderer?.showEmptyState("wildberries");
    }

    // Render comparison if enabled and data available
    if (this.options.enableComparison && data.comparison) {
      this.renderComparisonSection(data.comparison);
    }
  }

  /**
   * Render combined view (existing dashboard functionality)
   * @param {Object} data - Combined data object
   */
  renderCombinedView(data) {
    // This method should integrate with existing dashboard rendering logic
    // For now, we'll just trigger a custom event that existing code can listen to
    this.dispatchEvent("renderCombined", {
      data,
      timestamp: new Date(),
    });
  }

  /**
   * Render comparison section
   * @param {Object} comparisonData - Comparison data
   */
  renderComparisonSection(comparisonData) {
    const comparisonSection = document.getElementById(
      "marketplace-comparison-section"
    );
    if (!comparisonSection || !comparisonData) return;

    comparisonSection.style.display = "block";

    // Render comparison chart if data available
    if (comparisonData.chart_data) {
      this.renderComparisonChart(comparisonData.chart_data);
    }
  }

  /**
   * Render comparison chart
   * @param {Array} chartData - Chart data for comparison
   */
  renderComparisonChart(chartData) {
    // Implementation for comparison chart rendering
    // This would be similar to renderDailyChart but with multiple datasets
    console.log("Rendering comparison chart:", chartData);
  }

  /**
   * Show loading state for both marketplaces
   */
  showLoadingForBothMarketplaces() {
    this.dataRenderer?.showLoadingState("ozon");
    this.dataRenderer?.showLoadingState("wildberries");
  }

  /**
   * Setup auto-refresh functionality
   */
  setupAutoRefresh() {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
    }

    this.refreshTimer = setInterval(() => {
      if (!this.viewToggle?.isCurrentlyLoading()) {
        this.refreshData();
      }
    }, this.options.refreshInterval);
  }

  /**
   * Setup event listeners for external interactions
   */
  setupEventListeners() {
    // Listen for date range changes
    document.addEventListener("dateRangeChanged", (event) => {
      this.refreshData();
    });

    // Listen for filter changes
    document.addEventListener("filtersChanged", (event) => {
      this.refreshData();
    });

    // Listen for window resize for chart responsiveness
    window.addEventListener(
      "resize",
      this.debounce(() => {
        this.handleResize();
      }, 250)
    );
  }

  /**
   * Handle window resize
   */
  handleResize() {
    // Trigger chart resize if needed
    if (this.dataRenderer && this.dataRenderer.chartInstances) {
      this.dataRenderer.chartInstances.forEach((chart) => {
        if (chart && typeof chart.resize === "function") {
          chart.resize();
        }
      });
    }
  }

  /**
   * Refresh data manually
   */
  async refreshData() {
    if (!this.isInitialized || !this.viewToggle) {
      return;
    }

    try {
      await this.viewToggle.refreshData();
    } catch (error) {
      this.handleError(error, "manual_refresh");
    }
  }

  /**
   * Get current view mode
   * @returns {string} Current view mode
   */
  getCurrentView() {
    return this.viewToggle?.getCurrentView() || "combined";
  }

  /**
   * Switch to specific view mode
   * @param {string} viewMode - View mode to switch to
   */
  switchToView(viewMode) {
    if (this.viewToggle) {
      this.viewToggle.toggleView(viewMode);
    }
  }

  /**
   * Get current data
   * @returns {Object|null} Current data object
   */
  getCurrentData() {
    return this.currentData;
  }

  /**
   * Dispatch custom event
   * @param {string} eventName - Event name
   * @param {Object} detail - Event detail data
   */
  dispatchEvent(eventName, detail) {
    const event = new CustomEvent(`marketplace:${eventName}`, {
      detail,
      bubbles: true,
      cancelable: true,
    });

    document.dispatchEvent(event);
  }

  /**
   * Debounce utility function
   * @param {Function} func - Function to debounce
   * @param {number} wait - Wait time in milliseconds
   * @returns {Function} Debounced function
   */
  debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  /**
   * Destroy the integration and clean up resources
   */
  destroy() {
    // Clear auto-refresh timer
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }

    // Destroy components
    if (this.viewToggle) {
      this.viewToggle.destroy();
      this.viewToggle = null;
    }

    if (this.dataRenderer) {
      this.dataRenderer.destroy();
      this.dataRenderer = null;
    }

    // Clear data
    this.currentData = null;
    this.isInitialized = false;

    // Remove event listeners
    window.removeEventListener("resize", this.handleResize);
  }
}

// Export for module systems
if (typeof module !== "undefined" && module.exports) {
  module.exports = MarketplaceDashboardIntegration;
}

// Global initialization helper
window.initializeMarketplaceDashboard = function (
  containerId,
  apiEndpoint,
  options = {}
) {
  return new MarketplaceDashboardIntegration(containerId, apiEndpoint, options);
};
