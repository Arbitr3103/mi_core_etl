/**
 * MarketplaceDataRenderer - Handles rendering of marketplace-specific data
 * Provides functions to render KPI cards, charts, and product tables for separated view
 */
class MarketplaceDataRenderer {
  constructor(options = {}) {
    this.options = {
      chartLibrary: "Chart.js", // or 'chartjs'
      currencySymbol: "₽",
      dateFormat: "DD.MM.YYYY",
      numberFormat: "ru-RU",
      ...options,
    };

    // Chart instances storage
    this.chartInstances = new Map();

    // Templates cache
    this.templates = new Map();

    this.init();
  }

  /**
   * Initialize the renderer
   */
  init() {
    this.loadTemplates();
    this.setupChartDefaults();
  }

  /**
   * Load HTML templates
   */
  loadTemplates() {
    const productRowTemplate = document.getElementById("product-row-template");
    const emptyProductsTemplate = document.getElementById(
      "empty-products-template"
    );

    if (productRowTemplate) {
      this.templates.set("productRow", productRowTemplate.innerHTML);
    }

    if (emptyProductsTemplate) {
      this.templates.set("emptyProducts", emptyProductsTemplate.innerHTML);
    }
  }

  /**
   * Setup Chart.js defaults
   */
  setupChartDefaults() {
    if (typeof Chart !== "undefined") {
      Chart.defaults.font.family = "'Inter', 'Segoe UI', 'Roboto', sans-serif";
      Chart.defaults.font.size = 12;
      Chart.defaults.color = "#495057";
    }
  }

  /**
   * Render marketplace-specific KPI cards
   * @param {string} marketplace - 'ozon' or 'wildberries'
   * @param {Object} kpiData - KPI data object
   */
  renderKPICards(marketplace, kpiData) {
    try {
      const elements = {
        revenue: document.getElementById(`${marketplace}-revenue`),
        revenueChange: document.getElementById(`${marketplace}-revenue-change`),
        orders: document.getElementById(`${marketplace}-orders`),
        ordersChange: document.getElementById(`${marketplace}-orders-change`),
        margin: document.getElementById(`${marketplace}-margin`),
        marginChange: document.getElementById(`${marketplace}-margin-change`),
        avgOrder: document.getElementById(`${marketplace}-avg-order`),
        avgOrderChange: document.getElementById(
          `${marketplace}-avg-order-change`
        ),
      };

      // Render revenue
      if (elements.revenue && kpiData.revenue !== undefined) {
        elements.revenue.textContent = this.formatCurrency(kpiData.revenue);
      }

      if (elements.revenueChange && kpiData.revenue_change !== undefined) {
        this.renderChangeIndicator(
          elements.revenueChange,
          kpiData.revenue_change
        );
      }

      // Render orders
      if (elements.orders && kpiData.orders !== undefined) {
        elements.orders.textContent = this.formatNumber(kpiData.orders);
      }

      if (elements.ordersChange && kpiData.orders_change !== undefined) {
        this.renderChangeIndicator(
          elements.ordersChange,
          kpiData.orders_change
        );
      }

      // Render margin
      if (elements.margin && kpiData.margin_percent !== undefined) {
        elements.margin.textContent = this.formatPercent(
          kpiData.margin_percent
        );
      }

      if (elements.marginChange && kpiData.margin_change !== undefined) {
        this.renderChangeIndicator(
          elements.marginChange,
          kpiData.margin_change
        );
      }

      // Render average order value
      if (elements.avgOrder && kpiData.avg_order_value !== undefined) {
        elements.avgOrder.textContent = this.formatCurrency(
          kpiData.avg_order_value
        );
      }

      if (elements.avgOrderChange && kpiData.avg_order_change !== undefined) {
        this.renderChangeIndicator(
          elements.avgOrderChange,
          kpiData.avg_order_change
        );
      }
    } catch (error) {
      console.error(`Error rendering KPI cards for ${marketplace}:`, error);
      this.showKPIError(marketplace, error.message);
    }
  }

  /**
   * Render change indicator with appropriate styling
   * @param {HTMLElement} element - Element to render change in
   * @param {number} change - Change value (positive/negative)
   */
  renderChangeIndicator(element, change) {
    if (!element || change === undefined || change === null) return;

    const absChange = Math.abs(change);
    const formattedChange = this.formatPercent(absChange);

    // Clear existing classes
    element.classList.remove("positive", "negative", "neutral");

    if (change > 0) {
      element.classList.add("positive");
      element.innerHTML = `<i class="fas fa-arrow-up"></i> +${formattedChange}`;
    } else if (change < 0) {
      element.classList.add("negative");
      element.innerHTML = `<i class="fas fa-arrow-down"></i> -${formattedChange}`;
    } else {
      element.classList.add("neutral");
      element.innerHTML = `<i class="fas fa-minus"></i> ${formattedChange}`;
    }
  }

  /**
   * Render daily chart for marketplace
   * @param {string} marketplace - 'ozon' or 'wildberries'
   * @param {Array} chartData - Array of daily data points
   */
  renderDailyChart(marketplace, chartData) {
    try {
      const canvasId = `${marketplace}-daily-chart`;
      const canvas = document.getElementById(canvasId);
      const loadingElement = document.getElementById(
        `${marketplace}-chart-loading`
      );
      const errorElement = document.getElementById(
        `${marketplace}-chart-error`
      );

      if (!canvas) {
        console.error(`Canvas element ${canvasId} not found`);
        return;
      }

      // Hide loading and error states
      if (loadingElement) loadingElement.style.display = "none";
      if (errorElement) errorElement.style.display = "none";

      // Destroy existing chart if exists
      if (this.chartInstances.has(canvasId)) {
        this.chartInstances.get(canvasId).destroy();
      }

      // Prepare chart data
      const labels = chartData.map((item) => this.formatDate(item.date));
      const revenueData = chartData.map((item) => item.revenue || 0);
      const ordersData = chartData.map((item) => item.orders || 0);

      // Chart configuration
      const config = {
        type: "line",
        data: {
          labels: labels,
          datasets: [
            {
              label: "Выручка",
              data: revenueData,
              borderColor: marketplace === "ozon" ? "#2196f3" : "#e91e63",
              backgroundColor:
                marketplace === "ozon"
                  ? "rgba(33, 150, 243, 0.1)"
                  : "rgba(233, 30, 99, 0.1)",
              borderWidth: 2,
              fill: true,
              tension: 0.4,
              yAxisID: "y",
            },
            {
              label: "Заказы",
              data: ordersData,
              borderColor: marketplace === "ozon" ? "#1565c0" : "#ad1457",
              backgroundColor: "transparent",
              borderWidth: 2,
              borderDash: [5, 5],
              fill: false,
              tension: 0.4,
              yAxisID: "y1",
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: "index",
            intersect: false,
          },
          plugins: {
            legend: {
              position: "top",
              labels: {
                usePointStyle: true,
                padding: 20,
              },
            },
            tooltip: {
              callbacks: {
                label: (context) => {
                  const label = context.dataset.label || "";
                  const value = context.parsed.y;

                  if (label === "Выручка") {
                    return `${label}: ${this.formatCurrency(value)}`;
                  } else {
                    return `${label}: ${this.formatNumber(value)}`;
                  }
                },
              },
            },
          },
          scales: {
            x: {
              display: true,
              title: {
                display: true,
                text: "Дата",
              },
              grid: {
                display: false,
              },
            },
            y: {
              type: "linear",
              display: true,
              position: "left",
              title: {
                display: true,
                text: "Выручка (₽)",
              },
              ticks: {
                callback: (value) => this.formatCurrency(value, false),
              },
            },
            y1: {
              type: "linear",
              display: true,
              position: "right",
              title: {
                display: true,
                text: "Заказы",
              },
              grid: {
                drawOnChartArea: false,
              },
              ticks: {
                callback: (value) => this.formatNumber(value),
              },
            },
          },
        },
      };

      // Create chart
      const ctx = canvas.getContext("2d");
      const chart = new Chart(ctx, config);
      this.chartInstances.set(canvasId, chart);
    } catch (error) {
      console.error(`Error rendering chart for ${marketplace}:`, error);
      this.showChartError(marketplace, error.message);
    }
  }

  /**
   * Render top products table for marketplace
   * @param {string} marketplace - 'ozon' or 'wildberries'
   * @param {Array} productsData - Array of product data
   */
  renderTopProducts(marketplace, productsData) {
    try {
      const tbody = document.getElementById(`${marketplace}-products-tbody`);
      if (!tbody) {
        console.error(`Products table body for ${marketplace} not found`);
        return;
      }

      // Clear existing content
      tbody.innerHTML = "";

      if (!productsData || productsData.length === 0) {
        tbody.innerHTML =
          this.templates.get("emptyProducts") ||
          '<tr><td colspan="5" class="text-center text-muted">Нет данных</td></tr>';
        return;
      }

      // Render each product row
      productsData.forEach((product, index) => {
        const row = this.renderProductRow(product, marketplace);
        tbody.appendChild(row);
      });
    } catch (error) {
      console.error(`Error rendering products for ${marketplace}:`, error);
      this.showProductsError(marketplace, error.message);
    }
  }

  /**
   * Render individual product row
   * @param {Object} product - Product data
   * @param {string} marketplace - Marketplace identifier
   * @returns {HTMLElement} Table row element
   */
  renderProductRow(product, marketplace) {
    const row = document.createElement("tr");

    // Determine SKU field based on marketplace
    const skuField = marketplace === "ozon" ? "sku_ozon" : "sku_wb";
    const sku = product[skuField] || product.sku || "-";

    // Determine margin class for styling
    const marginPercent = parseFloat(product.margin_percent) || 0;
    let marginClass = "medium";
    if (marginPercent >= 50) marginClass = "high";
    else if (marginPercent < 30) marginClass = "low";

    row.innerHTML = `
            <td>
                <span class="product-sku">${this.escapeHtml(sku)}</span>
            </td>
            <td title="${this.escapeHtml(
              product.name || ""
            )}">${this.truncateText(product.name || "-", 30)}</td>
            <td class="product-revenue">${this.formatCurrency(
              product.revenue || 0
            )}</td>
            <td class="product-margin ${marginClass}">${this.formatPercent(
      marginPercent
    )}</td>
            <td>${this.formatNumber(product.orders || 0)}</td>
        `;

    return row;
  }

  /**
   * Show loading state for marketplace section
   * @param {string} marketplace - Marketplace identifier
   */
  showLoadingState(marketplace) {
    const elements = {
      kpiGrid: document.getElementById(`${marketplace}-kpi-grid`),
      chartLoading: document.getElementById(`${marketplace}-chart-loading`),
      productsTable: document.getElementById(`${marketplace}-products-tbody`),
      emptyState: document.getElementById(`${marketplace}-empty-state`),
      errorState: document.getElementById(`${marketplace}-error-state`),
    };

    // Hide error and empty states
    if (elements.emptyState) elements.emptyState.style.display = "none";
    if (elements.errorState) elements.errorState.style.display = "none";

    // Show loading for chart
    if (elements.chartLoading) elements.chartLoading.style.display = "flex";

    // Show loading for products table
    if (elements.productsTable) {
      elements.productsTable.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted">
                        <div class="marketplace-loading">
                            <div class="spinner-border spinner-border-sm" role="status"></div>
                            <span>Загрузка данных...</span>
                        </div>
                    </td>
                </tr>
            `;
    }

    // Reset KPI values
    this.resetKPIValues(marketplace);
  }

  /**
   * Show error state for marketplace section
   * @param {string} marketplace - Marketplace identifier
   * @param {string} errorMessage - Error message to display
   */
  showErrorState(marketplace, errorMessage) {
    const errorElement = document.getElementById(`${marketplace}-error-state`);
    const errorMessageElement = document.getElementById(
      `${marketplace}-error-message`
    );
    const emptyState = document.getElementById(`${marketplace}-empty-state`);
    const chartLoading = document.getElementById(
      `${marketplace}-chart-loading`
    );

    // Hide other states
    if (emptyState) emptyState.style.display = "none";
    if (chartLoading) chartLoading.style.display = "none";

    // Show error state
    if (errorElement) {
      errorElement.style.display = "block";
      if (errorMessageElement) {
        errorMessageElement.textContent = errorMessage;
      }
    }

    this.showProductsError(marketplace, errorMessage);
    this.showChartError(marketplace, errorMessage);
  }

  /**
   * Show empty state for marketplace section
   * @param {string} marketplace - Marketplace identifier
   */
  showEmptyState(marketplace) {
    const emptyState = document.getElementById(`${marketplace}-empty-state`);
    const errorState = document.getElementById(`${marketplace}-error-state`);
    const chartLoading = document.getElementById(
      `${marketplace}-chart-loading`
    );

    // Hide other states
    if (errorState) errorState.style.display = "none";
    if (chartLoading) chartLoading.style.display = "none";

    // Show empty state
    if (emptyState) {
      emptyState.style.display = "block";
    }

    // Clear products table
    const tbody = document.getElementById(`${marketplace}-products-tbody`);
    if (tbody) {
      tbody.innerHTML =
        this.templates.get("emptyProducts") ||
        '<tr><td colspan="5" class="text-center text-muted">Нет данных за выбранный период</td></tr>';
    }

    // Reset KPI values
    this.resetKPIValues(marketplace);
  }

  /**
   * Reset KPI values to default state
   * @param {string} marketplace - Marketplace identifier
   */
  resetKPIValues(marketplace) {
    const kpiElements = [
      `${marketplace}-revenue`,
      `${marketplace}-orders`,
      `${marketplace}-margin`,
      `${marketplace}-avg-order`,
    ];

    kpiElements.forEach((elementId) => {
      const element = document.getElementById(elementId);
      if (element) {
        element.textContent = "-";
      }
    });

    const changeElements = [
      `${marketplace}-revenue-change`,
      `${marketplace}-orders-change`,
      `${marketplace}-margin-change`,
      `${marketplace}-avg-order-change`,
    ];

    changeElements.forEach((elementId) => {
      const element = document.getElementById(elementId);
      if (element) {
        element.textContent = "";
        element.classList.remove("positive", "negative", "neutral");
        element.classList.add("neutral");
      }
    });
  }

  /**
   * Show KPI error
   * @param {string} marketplace - Marketplace identifier
   * @param {string} errorMessage - Error message
   */
  showKPIError(marketplace, errorMessage) {
    console.error(`KPI Error for ${marketplace}:`, errorMessage);
    // KPI errors are handled silently, values remain as '-'
  }

  /**
   * Show chart error
   * @param {string} marketplace - Marketplace identifier
   * @param {string} errorMessage - Error message
   */
  showChartError(marketplace, errorMessage) {
    const chartError = document.getElementById(`${marketplace}-chart-error`);
    const chartLoading = document.getElementById(
      `${marketplace}-chart-loading`
    );

    if (chartLoading) chartLoading.style.display = "none";
    if (chartError) chartError.style.display = "block";
  }

  /**
   * Show products table error
   * @param {string} marketplace - Marketplace identifier
   * @param {string} errorMessage - Error message
   */
  showProductsError(marketplace, errorMessage) {
    const tbody = document.getElementById(`${marketplace}-products-tbody`);
    if (tbody) {
      tbody.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-danger">
                        <i class="fas fa-exclamation-triangle"></i>
                        Ошибка загрузки товаров
                    </td>
                </tr>
            `;
    }
  }

  /**
   * Format currency value
   * @param {number} value - Numeric value
   * @param {boolean} includeSymbol - Whether to include currency symbol
   * @returns {string} Formatted currency string
   */
  formatCurrency(value, includeSymbol = true) {
    if (value === null || value === undefined || isNaN(value)) {
      return "-";
    }

    const formatted = new Intl.NumberFormat(this.options.numberFormat, {
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(value);

    return includeSymbol
      ? `${formatted} ${this.options.currencySymbol}`
      : formatted;
  }

  /**
   * Format percentage value
   * @param {number} value - Numeric value
   * @returns {string} Formatted percentage string
   */
  formatPercent(value) {
    if (value === null || value === undefined || isNaN(value)) {
      return "-";
    }

    return new Intl.NumberFormat(this.options.numberFormat, {
      minimumFractionDigits: 1,
      maximumFractionDigits: 1,
    }).format(value);
  }

  /**
   * Format number value
   * @param {number} value - Numeric value
   * @returns {string} Formatted number string
   */
  formatNumber(value) {
    if (value === null || value === undefined || isNaN(value)) {
      return "-";
    }

    return new Intl.NumberFormat(this.options.numberFormat).format(value);
  }

  /**
   * Format date value
   * @param {string|Date} date - Date value
   * @returns {string} Formatted date string
   */
  formatDate(date) {
    if (!date) return "-";

    try {
      const dateObj = typeof date === "string" ? new Date(date) : date;
      return dateObj.toLocaleDateString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
      });
    } catch (error) {
      return date.toString();
    }
  }

  /**
   * Truncate text to specified length
   * @param {string} text - Text to truncate
   * @param {number} maxLength - Maximum length
   * @returns {string} Truncated text
   */
  truncateText(text, maxLength) {
    if (!text || text.length <= maxLength) {
      return text;
    }

    return text.substring(0, maxLength - 3) + "...";
  }

  /**
   * Escape HTML characters
   * @param {string} text - Text to escape
   * @returns {string} Escaped text
   */
  escapeHtml(text) {
    if (!text) return "";

    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }

  /**
   * Destroy all chart instances
   */
  destroyCharts() {
    this.chartInstances.forEach((chart, canvasId) => {
      try {
        chart.destroy();
      } catch (error) {
        console.warn(`Error destroying chart ${canvasId}:`, error);
      }
    });
    this.chartInstances.clear();
  }

  /**
   * Destroy the renderer and clean up resources
   */
  destroy() {
    this.destroyCharts();
    this.templates.clear();
  }
}

// Export for module systems
if (typeof module !== "undefined" && module.exports) {
  module.exports = MarketplaceDataRenderer;
}
