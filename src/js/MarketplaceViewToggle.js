/**
 * MarketplaceViewToggle - Handles switching between combined and separated marketplace views
 * Provides toggle functionality with localStorage persistence and event handling
 */
class MarketplaceViewToggle {
  constructor(containerId, apiEndpoint, options = {}) {
    this.containerId = containerId;
    this.apiEndpoint = apiEndpoint;
    this.currentView = this.getStoredViewMode() || "combined";
    this.isLoading = false;

    // Configuration options
    this.options = {
      storageKey: "marketplace_view_mode",
      loadingClass: "loading",
      activeClass: "active",
      ...options,
    };

    // Event callbacks
    this.onViewChange = options.onViewChange || null;
    this.onDataLoad = options.onDataLoad || null;
    this.onError = options.onError || null;

    this.init();
  }

  /**
   * Initialize the toggle component
   */
  init() {
    this.createToggleButtons();
    this.bindEvents();
    this.setInitialView();
  }

  /**
   * Create toggle buttons HTML structure
   */
  createToggleButtons() {
    const container = document.getElementById(this.containerId);
    if (!container) {
      console.error(`Container with ID "${this.containerId}" not found`);
      return;
    }

    const toggleHTML = `
            <div class="view-controls mb-3">
                <div class="btn-group" role="group" aria-label="View mode toggle">
                    <button type="button" 
                            class="btn btn-outline-primary view-toggle-btn" 
                            data-view="combined"
                            aria-pressed="false">
                        <i class="fas fa-layer-group me-1"></i>
                        Общий вид
                    </button>
                    <button type="button" 
                            class="btn btn-outline-primary view-toggle-btn" 
                            data-view="separated"
                            aria-pressed="false">
                        <i class="fas fa-columns me-1"></i>
                        По маркетплейсам
                    </button>
                </div>
                <div class="view-loading ms-3" style="display: none;">
                    <div class="spinner-border spinner-border-sm" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                    <span class="ms-2">Загрузка данных...</span>
                </div>
            </div>
        `;

    container.insertAdjacentHTML("afterbegin", toggleHTML);
  }

  /**
   * Bind event handlers to toggle buttons
   */
  bindEvents() {
    const buttons = document.querySelectorAll(
      `#${this.containerId} .view-toggle-btn`
    );

    buttons.forEach((button) => {
      button.addEventListener("click", (e) => {
        e.preventDefault();
        const newView = button.getAttribute("data-view");
        this.toggleView(newView);
      });
    });
  }

  /**
   * Set initial view based on stored preference
   */
  setInitialView() {
    this.updateButtonStates();
    this.showView(this.currentView);
  }

  /**
   * Toggle between view modes
   * @param {string} mode - 'combined' or 'separated'
   */
  toggleView(mode) {
    if (this.isLoading || mode === this.currentView) {
      return;
    }

    if (!["combined", "separated"].includes(mode)) {
      console.error(`Invalid view mode: ${mode}`);
      return;
    }

    const previousView = this.currentView;
    this.currentView = mode;

    // Store preference
    this.storeViewMode(mode);

    // Update UI
    this.updateButtonStates();
    this.showLoadingState();

    // Trigger view change callback
    if (this.onViewChange) {
      this.onViewChange(mode, previousView);
    }

    // Load data for new view
    this.refreshData();
  }

  /**
   * Update button active states
   */
  updateButtonStates() {
    const buttons = document.querySelectorAll(
      `#${this.containerId} .view-toggle-btn`
    );

    buttons.forEach((button) => {
      const buttonView = button.getAttribute("data-view");
      const isActive = buttonView === this.currentView;

      button.classList.toggle(this.options.activeClass, isActive);
      button.classList.toggle("btn-primary", isActive);
      button.classList.toggle("btn-outline-primary", !isActive);
      button.setAttribute("aria-pressed", isActive.toString());
    });
  }

  /**
   * Show loading state
   */
  showLoadingState() {
    this.isLoading = true;
    const loadingElement = document.querySelector(
      `#${this.containerId} .view-loading`
    );
    if (loadingElement) {
      loadingElement.style.display = "flex";
    }

    // Disable buttons during loading
    const buttons = document.querySelectorAll(
      `#${this.containerId} .view-toggle-btn`
    );
    buttons.forEach((button) => {
      button.disabled = true;
    });
  }

  /**
   * Hide loading state
   */
  hideLoadingState() {
    this.isLoading = false;
    const loadingElement = document.querySelector(
      `#${this.containerId} .view-loading`
    );
    if (loadingElement) {
      loadingElement.style.display = "none";
    }

    // Re-enable buttons
    const buttons = document.querySelectorAll(
      `#${this.containerId} .view-toggle-btn`
    );
    buttons.forEach((button) => {
      button.disabled = false;
    });
  }

  /**
   * Show specific view container
   * @param {string} viewMode - 'combined' or 'separated'
   */
  showView(viewMode) {
    const combinedView = document.getElementById("combined-view");
    const separatedView = document.getElementById("separated-view");

    if (combinedView && separatedView) {
      if (viewMode === "combined") {
        combinedView.style.display = "block";
        separatedView.style.display = "none";
      } else {
        combinedView.style.display = "none";
        separatedView.style.display = "block";
      }
    }
  }

  /**
   * Refresh data for current view
   */
  async refreshData() {
    try {
      this.showLoadingState();

      const response = await this.fetchData();

      if (response.success) {
        if (this.onDataLoad) {
          this.onDataLoad(response.data, this.currentView);
        }
        this.showView(this.currentView);
      } else {
        throw new Error(response.error?.message || "Failed to load data");
      }
    } catch (error) {
      console.error("Error refreshing data:", error);

      if (this.onError) {
        this.onError(error, this.currentView);
      } else {
        this.showDefaultError(error.message);
      }
    } finally {
      this.hideLoadingState();
    }
  }

  /**
   * Fetch data from API
   * @returns {Promise<Object>} API response
   */
  async fetchData() {
    const url = new URL(this.apiEndpoint, window.location.origin);
    url.searchParams.set("view_mode", this.currentView);

    // Add current date range if available
    const dateRange = this.getCurrentDateRange();
    if (dateRange.start && dateRange.end) {
      url.searchParams.set("start_date", dateRange.start);
      url.searchParams.set("end_date", dateRange.end);
    }

    const response = await fetch(url.toString(), {
      method: "GET",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    return await response.json();
  }

  /**
   * Get current date range from UI elements
   * @returns {Object} Date range object
   */
  getCurrentDateRange() {
    const startDateInput = document.querySelector(
      'input[name="start_date"], #start_date'
    );
    const endDateInput = document.querySelector(
      'input[name="end_date"], #end_date'
    );

    return {
      start: startDateInput?.value || null,
      end: endDateInput?.value || null,
    };
  }

  /**
   * Show default error message
   * @param {string} message - Error message
   */
  showDefaultError(message) {
    const container = document.getElementById(this.containerId);
    if (!container) return;

    const errorHTML = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Ошибка загрузки данных:</strong> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        `;

    // Remove existing error alerts
    const existingAlerts = container.querySelectorAll(".alert-danger");
    existingAlerts.forEach((alert) => alert.remove());

    // Add new error alert
    const viewControls = container.querySelector(".view-controls");
    if (viewControls) {
      viewControls.insertAdjacentHTML("afterend", errorHTML);
    }
  }

  /**
   * Store view mode preference in localStorage
   * @param {string} mode - View mode to store
   */
  storeViewMode(mode) {
    try {
      localStorage.setItem(this.options.storageKey, mode);
    } catch (error) {
      console.warn("Failed to store view mode preference:", error);
    }
  }

  /**
   * Get stored view mode preference from localStorage
   * @returns {string|null} Stored view mode or null
   */
  getStoredViewMode() {
    try {
      return localStorage.getItem(this.options.storageKey);
    } catch (error) {
      console.warn("Failed to retrieve view mode preference:", error);
      return null;
    }
  }

  /**
   * Get current view mode
   * @returns {string} Current view mode
   */
  getCurrentView() {
    return this.currentView;
  }

  /**
   * Check if currently loading
   * @returns {boolean} Loading state
   */
  isCurrentlyLoading() {
    return this.isLoading;
  }

  /**
   * Destroy the toggle component
   */
  destroy() {
    const buttons = document.querySelectorAll(
      `#${this.containerId} .view-toggle-btn`
    );
    buttons.forEach((button) => {
      button.removeEventListener("click", this.toggleView);
    });

    const viewControls = document.querySelector(
      `#${this.containerId} .view-controls`
    );
    if (viewControls) {
      viewControls.remove();
    }
  }
}

// Export for module systems
if (typeof module !== "undefined" && module.exports) {
  module.exports = MarketplaceViewToggle;
}
