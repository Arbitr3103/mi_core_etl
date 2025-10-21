/**
 * Main Dashboard Controller for Regional Analytics
 * Coordinates data loading, UI updates, and user interactions
 */

class RegionalAnalyticsDashboard {
  constructor() {
    this.currentFilters = {
      dateFrom: null,
      dateTo: null,
      marketplace: "all",
    };

    this.isLoading = false;
    this.loadingTimeout = null;
  }

  /**
   * Initialize the dashboard
   */
  async init() {
    console.log("Initializing Regional Analytics Dashboard...");

    try {
      this.setupEventListeners();
      this.initializeDateFilters();
      analyticsCharts.initializeCharts();

      // Load initial data
      await this.loadDashboardData();

      console.log("Dashboard initialized successfully");
    } catch (error) {
      console.error("Dashboard initialization failed:", error);
      this.showError("Ошибка инициализации дашборда: " + error.message);
    }
  }

  /**
   * Set up event listeners for UI interactions
   */
  setupEventListeners() {
    // Filter controls
    document.getElementById("applyFilters")?.addEventListener("click", () => {
      this.applyFilters();
    });

    document.getElementById("resetFilters")?.addEventListener("click", () => {
      this.resetFilters();
    });

    // Product sorting controls
    document.querySelectorAll('input[name="productSort"]').forEach((radio) => {
      radio.addEventListener("change", (e) => {
        this.updateProductTable(this.lastProductData, e.target.id);
      });
    });

    // Window resize handler for charts
    window.addEventListener("resize", () => {
      analyticsCharts.resizeCharts();
    });

    // Keyboard shortcuts
    document.addEventListener("keydown", (e) => {
      if (e.ctrlKey && e.key === "r") {
        e.preventDefault();
        this.refreshData();
      }
    });
  }

  /**
   * Initialize date filter inputs with default values
   */
  initializeDateFilters() {
    const today = new Date();
    const thirtyDaysAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);

    const dateFromInput = document.getElementById("dateFrom");
    const dateToInput = document.getElementById("dateTo");

    if (dateFromInput) {
      dateFromInput.value = thirtyDaysAgo.toISOString().split("T")[0];
      this.currentFilters.dateFrom = dateFromInput.value;
    }

    if (dateToInput) {
      dateToInput.value = today.toISOString().split("T")[0];
      this.currentFilters.dateTo = dateToInput.value;
    }
  }

  /**
   * Apply current filter settings and reload data
   */
  async applyFilters() {
    const dateFrom = document.getElementById("dateFrom")?.value;
    const dateTo = document.getElementById("dateTo")?.value;
    const marketplace = document.getElementById("marketplaceFilter")?.value;

    // Validate date range
    if (dateFrom && dateTo && new Date(dateFrom) > new Date(dateTo)) {
      this.showError("Дата начала не может быть больше даты окончания");
      return;
    }

    this.currentFilters = {
      dateFrom: dateFrom || null,
      dateTo: dateTo || null,
      marketplace: marketplace || "all",
    };

    await this.loadDashboardData();
  }

  /**
   * Reset filters to default values
   */
  async resetFilters() {
    this.initializeDateFilters();

    const marketplaceFilter = document.getElementById("marketplaceFilter");
    if (marketplaceFilter) {
      marketplaceFilter.value = "all";
    }

    this.currentFilters.marketplace = "all";
    await this.loadDashboardData();
  }

  /**
   * Load all dashboard data
   */
  async loadDashboardData() {
    if (this.isLoading) return;

    this.showLoading(true);

    try {
      // Load data in parallel
      const [summaryData, marketplaceData, topProductsData, salesDynamicsData] =
        await Promise.all([
          analyticsAPI.getDashboardSummary(this.currentFilters),
          analyticsAPI.getMarketplaceComparison(this.currentFilters),
          analyticsAPI.getTopProducts({ ...this.currentFilters, limit: 10 }),
          analyticsAPI.getSalesDynamics({
            ...this.currentFilters,
            period: "month",
          }),
        ]);

      // Update UI components
      this.updateKPICards(summaryData);
      analyticsCharts.updateMarketplaceChart(marketplaceData);
      this.updateProductTable(topProductsData);
      analyticsCharts.updateSalesDynamicsChart(salesDynamicsData);
      this.updateRegionalList(summaryData.top_regions || []);

      // Store data for sorting
      this.lastProductData = topProductsData;
    } catch (error) {
      console.error("Failed to load dashboard data:", error);
      this.showError("Ошибка загрузки данных: " + error.message);
    } finally {
      this.showLoading(false);
    }
  }

  /**
   * Update KPI cards with summary data
   * @param {Object} data - Dashboard summary data
   */
  updateKPICards(data) {
    if (!data) return;

    // Total Revenue
    const totalRevenueEl = document.getElementById("totalRevenue");
    if (totalRevenueEl && data.total_revenue !== undefined) {
      totalRevenueEl.textContent = this.formatCurrency(data.total_revenue);
    }

    // Total Sales
    const totalSalesEl = document.getElementById("totalSales");
    if (totalSalesEl && data.total_orders !== undefined) {
      totalSalesEl.textContent = this.formatNumber(data.total_orders);
    }

    // Active Regions
    const activeRegionsEl = document.getElementById("activeRegions");
    if (activeRegionsEl && data.active_regions !== undefined) {
      activeRegionsEl.textContent = this.formatNumber(data.active_regions);
    }

    // Average Order
    const averageOrderEl = document.getElementById("averageOrder");
    if (averageOrderEl && data.average_order_value !== undefined) {
      averageOrderEl.textContent = this.formatCurrency(
        data.average_order_value
      );
    }

    // Update change indicators (if available)
    this.updateChangeIndicator("revenueChange", data.revenue_change);
    this.updateChangeIndicator("salesChange", data.sales_change);
    this.updateChangeIndicator("regionsChange", data.regions_change);
    this.updateChangeIndicator("orderChange", data.order_change);
  }

  /**
   * Update change indicator with percentage change
   * @param {string} elementId - Element ID
   * @param {number} change - Percentage change
   */
  updateChangeIndicator(elementId, change) {
    const element = document.getElementById(elementId);
    if (!element || change === undefined) return;

    const isPositive = change >= 0;
    const icon = isPositive ? "fa-arrow-up" : "fa-arrow-down";
    const colorClass = isPositive ? "text-success" : "text-danger";

    element.innerHTML = `<i class="fas ${icon} me-1"></i>${Math.abs(
      change
    ).toFixed(1)}%`;
    element.className = `text-light ${colorClass}`;
  }

  /**
   * Update top products table
   * @param {Object} data - Top products data
   * @param {string} sortBy - Sort criteria (sortByRevenue or sortByQuantity)
   */
  updateProductTable(data, sortBy = "sortByRevenue") {
    const tableBody = document.querySelector("#topProductsTable tbody");
    if (!tableBody || !data || !data.products) return;

    // Sort products based on criteria
    let sortedProducts = [...data.products];
    if (sortBy === "sortByQuantity") {
      sortedProducts.sort((a, b) => (b.total_qty || 0) - (a.total_qty || 0));
    } else {
      sortedProducts.sort(
        (a, b) => (b.total_revenue || 0) - (a.total_revenue || 0)
      );
    }

    // Clear existing rows
    tableBody.innerHTML = "";

    if (sortedProducts.length === 0) {
      tableBody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted">
                        Нет данных для отображения
                    </td>
                </tr>
            `;
      return;
    }

    // Add product rows
    sortedProducts.forEach((product, index) => {
      const row = document.createElement("tr");
      row.innerHTML = `
                <td>
                    <div class="fw-bold">${this.escapeHtml(
                      product.product_name || "Неизвестный товар"
                    )}</div>
                    <small class="text-muted">SKU: ${this.escapeHtml(
                      product.sku || "N/A"
                    )}</small>
                </td>
                <td>
                    <div>${this.formatCurrency(product.ozon_revenue || 0)}</div>
                    <small class="text-muted">${this.formatNumber(
                      product.ozon_qty || 0
                    )} шт.</small>
                </td>
                <td>
                    <div>${this.formatCurrency(product.wb_revenue || 0)}</div>
                    <small class="text-muted">${this.formatNumber(
                      product.wb_qty || 0
                    )} шт.</small>
                </td>
                <td>
                    <div class="fw-bold">${this.formatCurrency(
                      product.total_revenue || 0
                    )}</div>
                </td>
                <td>
                    <div class="fw-bold">${this.formatNumber(
                      product.total_qty || 0
                    )}</div>
                </td>
            `;
      tableBody.appendChild(row);
    });
  }

  /**
   * Update regional performance list
   * @param {Array} regions - Top regions data
   */
  updateRegionalList(regions) {
    const container = document.getElementById("topRegionsList");
    if (!container) return;

    if (!regions || regions.length === 0) {
      container.innerHTML = `
                <div class="text-center text-muted">
                    <i class="fas fa-info-circle me-2"></i>
                    Региональные данные недоступны
                </div>
            `;
      return;
    }

    container.innerHTML = regions
      .map(
        (region, index) => `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div>
                    <div class="fw-bold">${this.escapeHtml(
                      region.region_name || "Неизвестный регион"
                    )}</div>
                    <small class="text-muted">${this.escapeHtml(
                      region.federal_district || ""
                    )}</small>
                </div>
                <div class="text-end">
                    <div class="fw-bold">${this.formatCurrency(
                      region.total_revenue || 0
                    )}</div>
                    <small class="text-muted">${this.formatNumber(
                      region.total_orders || 0
                    )} заказов</small>
                </div>
            </div>
        `
      )
      .join("");
  }

  /**
   * Show/hide loading overlay
   * @param {boolean} show - Whether to show loading
   */
  showLoading(show) {
    this.isLoading = show;
    const overlay = document.getElementById("loadingOverlay");

    if (show) {
      overlay?.classList.remove("d-none");
      // Set timeout to prevent infinite loading
      this.loadingTimeout = setTimeout(() => {
        this.showLoading(false);
        this.showError("Превышено время ожидания загрузки данных");
      }, 30000);
    } else {
      overlay?.classList.add("d-none");
      if (this.loadingTimeout) {
        clearTimeout(this.loadingTimeout);
        this.loadingTimeout = null;
      }
    }
  }

  /**
   * Show error message to user
   * @param {string} message - Error message
   */
  showError(message) {
    console.error("Dashboard error:", message);

    // You could implement a toast notification system here
    // For now, just use alert
    alert(message);
  }

  /**
   * Refresh all dashboard data
   */
  async refreshData() {
    await this.loadDashboardData();
  }

  /**
   * Format currency value
   * @param {number} value - Numeric value
   * @returns {string} Formatted currency
   */
  formatCurrency(value) {
    if (value === null || value === undefined) return "0 ₽";
    return new Intl.NumberFormat("ru-RU", {
      style: "currency",
      currency: "RUB",
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(value);
  }

  /**
   * Format number value
   * @param {number} value - Numeric value
   * @returns {string} Formatted number
   */
  formatNumber(value) {
    if (value === null || value === undefined) return "0";
    return new Intl.NumberFormat("ru-RU").format(value);
  }

  /**
   * Escape HTML to prevent XSS
   * @param {string} text - Text to escape
   * @returns {string} Escaped text
   */
  escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }
}

// Initialize dashboard when DOM is loaded
document.addEventListener("DOMContentLoaded", () => {
  window.dashboard = new RegionalAnalyticsDashboard();
  window.dashboard.init();
});

// Handle page visibility changes
document.addEventListener("visibilitychange", () => {
  if (!document.hidden && window.dashboard) {
    // Refresh data when page becomes visible again
    window.dashboard.refreshData();
  }
});
