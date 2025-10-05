/**
 * Ozon Funnel Chart Component
 *
 * Создает и управляет визуализацией воронки продаж Ozon с использованием Chart.js
 * Поддерживает отображение конверсий между этапами и обновление при изменении фильтров
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonFunnelChart {
  constructor(containerId, options = {}) {
    this.containerId = containerId;
    this.container = document.getElementById(containerId);
    this.chart = null;
    this.data = null;

    // Default options
    this.options = {
      responsive: true,
      maintainAspectRatio: false,
      showConversions: true,
      showPercentages: true,
      animationDuration: 800,
      colors: {
        views: "#0066cc",
        cartAdditions: "#4CAF50",
        orders: "#FF9800",
        conversions: "#9C27B0",
      },
      ...options,
    };

    this.init();
  }

  /**
   * Initialize the chart container
   */
  init() {
    if (!this.container) {
      console.error(`Container with ID '${this.containerId}' not found`);
      return;
    }

    // Create canvas element if it doesn't exist
    let canvas = this.container.querySelector("canvas");
    if (!canvas) {
      canvas = document.createElement("canvas");
      canvas.id = this.containerId + "_canvas";
      this.container.appendChild(canvas);
    }

    this.canvas = canvas;
    this.ctx = canvas.getContext("2d");

    // Loading state is handled by OzonErrorHandler
  }

  /**
   * Render the funnel chart with provided data
   * @param {Object} data - Funnel data from API
   */
  renderFunnel(data) {
    if (!data || !this.ctx) {
      console.error("Invalid data or canvas context");
      return;
    }

    this.data = data;

    // Destroy existing chart
    if (this.chart) {
      this.chart.destroy();
    }

    // Prepare chart data
    const chartData = this.prepareChartData(data);

    // Create new chart
    this.chart = new Chart(this.ctx, {
      type: "bar",
      data: chartData,
      options: this.getChartOptions(),
    });

    // Add conversion labels if enabled
    if (this.options.showConversions) {
      this.addConversionLabels(data);
    }
  }

  /**
   * Prepare data for Chart.js
   * @param {Object} data - Raw funnel data
   * @returns {Object} Chart.js compatible data
   */
  prepareChartData(data) {
    const totals = data.totals || {};
    const views = totals.views || 0;
    const cartAdditions = totals.cart_additions || 0;
    const orders = totals.orders || 0;

    return {
      labels: ["👁️ Просмотры", "🛒 В корзину", "📦 Заказы"],
      datasets: [
        {
          label: "Количество",
          data: [views, cartAdditions, orders],
          backgroundColor: [
            this.options.colors.views,
            this.options.colors.cartAdditions,
            this.options.colors.orders,
          ],
          borderColor: [
            this.options.colors.views,
            this.options.colors.cartAdditions,
            this.options.colors.orders,
          ],
          borderWidth: 2,
          borderRadius: 8,
          borderSkipped: false,
        },
      ],
    };
  }

  /**
   * Get Chart.js options configuration
   * @returns {Object} Chart options
   */
  getChartOptions() {
    return {
      responsive: this.options.responsive,
      maintainAspectRatio: this.options.maintainAspectRatio,
      animation: {
        duration: this.options.animationDuration,
        easing: "easeInOutQuart",
      },
      plugins: {
        title: {
          display: true,
          text: "🔄 Воронка продаж Ozon",
          font: {
            size: 18,
            weight: "bold",
          },
          padding: 20,
        },
        legend: {
          display: false,
        },
        tooltip: {
          callbacks: {
            label: (context) => {
              const value = context.parsed.y;
              const percentage = this.calculateStagePercentage(
                context.dataIndex,
                value
              );
              return `${
                context.label
              }: ${value.toLocaleString()} (${percentage}%)`;
            },
          },
        },
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function (value) {
              return value.toLocaleString();
            },
          },
          title: {
            display: true,
            text: "Количество",
          },
        },
        x: {
          title: {
            display: true,
            text: "Этапы воронки",
          },
        },
      },
      onHover: (event, activeElements) => {
        event.native.target.style.cursor =
          activeElements.length > 0 ? "pointer" : "default";
      },
      onClick: (event, activeElements) => {
        if (activeElements.length > 0) {
          const dataIndex = activeElements[0].index;
          this.onStageClick(dataIndex);
        }
      },
    };
  }

  /**
   * Calculate percentage for a specific stage
   * @param {number} stageIndex - Stage index (0=views, 1=cart, 2=orders)
   * @param {number} value - Stage value
   * @returns {string} Percentage string
   */
  calculateStagePercentage(stageIndex, value) {
    if (!this.data || !this.data.totals) return "0.0";

    const totals = this.data.totals;
    const views = totals.views || 0;

    if (views === 0) return "0.0";

    const percentage = (value / views) * 100;
    return percentage.toFixed(1);
  }

  /**
   * Add conversion rate labels between stages
   * @param {Object} data - Funnel data
   */
  addConversionLabels(data) {
    const totals = data.totals || {};
    const conversionViewToCart = totals.conversion_view_to_cart || 0;
    const conversionCartToOrder = totals.conversion_cart_to_order || 0;
    const conversionOverall = totals.conversion_overall || 0;

    // Create conversion labels container
    let labelsContainer = this.container.querySelector(".conversion-labels");
    if (!labelsContainer) {
      labelsContainer = document.createElement("div");
      labelsContainer.className = "conversion-labels mt-3";
      this.container.appendChild(labelsContainer);
    }

    labelsContainer.innerHTML = `
            <div class="row text-center">
                <div class="col-md-4">
                    <div class="conversion-metric">
                        <div class="conversion-arrow">👁️ → 🛒</div>
                        <div class="conversion-rate">${conversionViewToCart.toFixed(
                          1
                        )}%</div>
                        <div class="conversion-label">Просмотры в корзину</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="conversion-metric">
                        <div class="conversion-arrow">🛒 → 📦</div>
                        <div class="conversion-rate">${conversionCartToOrder.toFixed(
                          1
                        )}%</div>
                        <div class="conversion-label">Корзина в заказ</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="conversion-metric">
                        <div class="conversion-arrow">👁️ → 📦</div>
                        <div class="conversion-rate overall">${conversionOverall.toFixed(
                          1
                        )}%</div>
                        <div class="conversion-label">Общая конверсия</div>
                    </div>
                </div>
            </div>
        `;

    // Add CSS styles if not already present
    this.addConversionStyles();
  }

  /**
   * Add CSS styles for conversion labels
   */
  addConversionStyles() {
    const styleId = "ozon-funnel-styles";
    if (document.getElementById(styleId)) return;

    const style = document.createElement("style");
    style.id = styleId;
    style.textContent = `
            .conversion-labels {
                background: #f8f9fa;
                border-radius: 10px;
                padding: 20px;
                margin-top: 20px;
            }
            
            .conversion-metric {
                padding: 15px;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                margin-bottom: 10px;
                transition: transform 0.2s ease;
            }
            
            .conversion-metric:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0,0,0,0.15);
            }
            
            .conversion-arrow {
                font-size: 1.2rem;
                margin-bottom: 8px;
                color: #6c757d;
            }
            
            .conversion-rate {
                font-size: 1.8rem;
                font-weight: bold;
                color: #0066cc;
                margin-bottom: 5px;
            }
            
            .conversion-rate.overall {
                color: #28a745;
                font-size: 2rem;
            }
            
            .conversion-label {
                font-size: 0.9rem;
                color: #6c757d;
                font-weight: 500;
            }
            
            .funnel-loading {
                display: flex;
                justify-content: center;
                align-items: center;
                height: 300px;
                flex-direction: column;
                color: #6c757d;
            }
            
            .funnel-loading .spinner-border {
                margin-bottom: 15px;
            }
            
            .funnel-error {
                display: flex;
                justify-content: center;
                align-items: center;
                height: 300px;
                flex-direction: column;
                color: #dc3545;
                background: #fff5f5;
                border-radius: 8px;
                border: 1px solid #f5c6cb;
            }
            
            .funnel-error .error-icon {
                font-size: 3rem;
                margin-bottom: 15px;
            }
            
            @media (max-width: 768px) {
                .conversion-metric {
                    margin-bottom: 15px;
                }
                
                .conversion-rate {
                    font-size: 1.5rem;
                }
                
                .conversion-rate.overall {
                    font-size: 1.7rem;
                }
            }
        `;

    document.head.appendChild(style);
  }

  /**
   * Update chart data without full re-render
   * @param {Object} newData - New funnel data
   */
  updateData(newData) {
    if (!this.chart || !newData) {
      this.renderFunnel(newData);
      return;
    }

    this.data = newData;
    const totals = newData.totals || {};

    // Update chart data
    this.chart.data.datasets[0].data = [
      totals.views || 0,
      totals.cart_additions || 0,
      totals.orders || 0,
    ];

    // Update chart
    this.chart.update("active");

    // Update conversion labels
    if (this.options.showConversions) {
      this.addConversionLabels(newData);
    }
  }

  /**
   * Handle stage click events
   * @param {number} stageIndex - Clicked stage index
   */
  onStageClick(stageIndex) {
    const stages = ["views", "cart_additions", "orders"];
    const stageName = stages[stageIndex];

    // Emit custom event
    const event = new CustomEvent("funnelStageClick", {
      detail: {
        stage: stageName,
        stageIndex: stageIndex,
        data: this.data,
      },
    });

    this.container.dispatchEvent(event);
  }

  /**
   * Show loading state (deprecated - handled by OzonErrorHandler)
   * @deprecated Use OzonErrorHandler for loading states
   */
  showLoading() {
    // Loading state is now handled by OzonErrorHandler
    console.warn(
      "showLoading() is deprecated. Loading states are handled by OzonErrorHandler."
    );
  }

  /**
   * Hide loading state (deprecated - handled by OzonErrorHandler)
   * @deprecated Use OzonErrorHandler for loading states
   */
  hideLoading() {
    // Loading state is now handled by OzonErrorHandler
    const loading = this.container.querySelector(".funnel-loading");
    if (loading) {
      loading.remove();
    }
  }

  /**
   * Show error state (deprecated - handled by OzonErrorHandler)
   * @deprecated Use OzonErrorHandler for error states
   * @param {string} message - Error message
   */
  showError(message = "Ошибка загрузки данных") {
    // Error state is now handled by OzonErrorHandler
    console.warn(
      "showError() is deprecated. Error states are handled by OzonErrorHandler."
    );
  }

  /**
   * Resize chart (useful for responsive layouts)
   */
  resize() {
    if (this.chart) {
      this.chart.resize();
    }
  }

  /**
   * Destroy chart and clean up
   */
  destroy() {
    if (this.chart) {
      this.chart.destroy();
      this.chart = null;
    }

    // Remove conversion labels
    const labelsContainer = this.container.querySelector(".conversion-labels");
    if (labelsContainer) {
      labelsContainer.remove();
    }

    this.data = null;
  }

  /**
   * Export chart as image
   * @param {string} format - Image format ('png', 'jpeg')
   * @returns {string} Base64 encoded image
   */
  exportAsImage(format = "png") {
    if (!this.chart) {
      console.error("Chart not initialized");
      return null;
    }

    return this.chart.toBase64Image(`image/${format}`, 1.0);
  }

  /**
   * Get current chart data
   * @returns {Object} Current data
   */
  getData() {
    return this.data;
  }

  /**
   * Set chart options
   * @param {Object} newOptions - New options to merge
   */
  setOptions(newOptions) {
    this.options = { ...this.options, ...newOptions };

    if (this.chart && this.data) {
      this.renderFunnel(this.data);
    }
  }
}

// Export for use in other modules
if (typeof module !== "undefined" && module.exports) {
  module.exports = OzonFunnelChart;
}
