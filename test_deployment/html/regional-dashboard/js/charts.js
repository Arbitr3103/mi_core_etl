/**
 * Chart Management for Regional Analytics Dashboard
 * Handles Chart.js initialization and data visualization
 */

class AnalyticsCharts {
  constructor() {
    this.charts = {};
    this.chartColors = {
      primary: "#0d6efd",
      success: "#198754",
      info: "#0dcaf0",
      warning: "#ffc107",
      danger: "#dc3545",
      secondary: "#6c757d",
    };
  }

  /**
   * Initialize all dashboard charts
   */
  initializeCharts() {
    this.initMarketplaceChart();
    this.initSalesDynamicsChart();
  }

  /**
   * Initialize marketplace comparison pie chart
   */
  initMarketplaceChart() {
    const ctx = document.getElementById("marketplaceChart");
    if (!ctx) return;

    this.charts.marketplace = new Chart(ctx, {
      type: "doughnut",
      data: {
        labels: ["Ozon", "Wildberries"],
        datasets: [
          {
            data: [0, 0],
            backgroundColor: [
              this.chartColors.primary,
              this.chartColors.success,
            ],
            borderWidth: 2,
            borderColor: "#ffffff",
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: "bottom",
            labels: {
              padding: 20,
              usePointStyle: true,
            },
          },
          tooltip: {
            callbacks: {
              label: function (context) {
                const label = context.label || "";
                const value = context.parsed || 0;
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage =
                  total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                return `${label}: ${value.toLocaleString(
                  "ru-RU"
                )} ₽ (${percentage}%)`;
              },
            },
          },
        },
        cutout: "60%",
      },
    });
  }

  /**
   * Initialize sales dynamics line chart
   */
  initSalesDynamicsChart() {
    const ctx = document.getElementById("salesDynamicsChart");
    if (!ctx) return;

    this.charts.salesDynamics = new Chart(ctx, {
      type: "line",
      data: {
        labels: [],
        datasets: [
          {
            label: "Ozon",
            data: [],
            borderColor: this.chartColors.primary,
            backgroundColor: this.chartColors.primary + "20",
            fill: false,
            tension: 0.4,
          },
          {
            label: "Wildberries",
            data: [],
            borderColor: this.chartColors.success,
            backgroundColor: this.chartColors.success + "20",
            fill: false,
            tension: 0.4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: "top",
            labels: {
              usePointStyle: true,
            },
          },
          tooltip: {
            mode: "index",
            intersect: false,
            callbacks: {
              label: function (context) {
                const label = context.dataset.label || "";
                const value = context.parsed.y || 0;
                return `${label}: ${value.toLocaleString("ru-RU")} ₽`;
              },
            },
          },
        },
        scales: {
          x: {
            display: true,
            title: {
              display: true,
              text: "Период",
            },
          },
          y: {
            display: true,
            title: {
              display: true,
              text: "Выручка (₽)",
            },
            ticks: {
              callback: function (value) {
                return value.toLocaleString("ru-RU") + " ₽";
              },
            },
          },
        },
        interaction: {
          mode: "nearest",
          axis: "x",
          intersect: false,
        },
      },
    });
  }

  /**
   * Update marketplace comparison chart with new data
   * @param {Object} data - Marketplace comparison data
   */
  updateMarketplaceChart(data) {
    if (!this.charts.marketplace || !data) return;

    const ozonRevenue = data.ozon?.total_revenue || 0;
    const wbRevenue = data.wildberries?.total_revenue || 0;

    this.charts.marketplace.data.datasets[0].data = [ozonRevenue, wbRevenue];
    this.charts.marketplace.update("active");
  }

  /**
   * Update sales dynamics chart with new data
   * @param {Object} data - Sales dynamics data
   */
  updateSalesDynamicsChart(data) {
    if (!this.charts.salesDynamics || !data || !data.periods) return;

    const labels = data.periods.map((period) => period.label);
    const ozonData = data.periods.map((period) => period.ozon_revenue || 0);
    const wbData = data.periods.map((period) => period.wb_revenue || 0);

    this.charts.salesDynamics.data.labels = labels;
    this.charts.salesDynamics.data.datasets[0].data = ozonData;
    this.charts.salesDynamics.data.datasets[1].data = wbData;
    this.charts.salesDynamics.update("active");
  }

  /**
   * Show loading state for a specific chart
   * @param {string} chartName - Name of the chart
   */
  showChartLoading(chartName) {
    const chart = this.charts[chartName];
    if (!chart) return;

    // Clear data and show loading message
    if (chartName === "marketplace") {
      chart.data.datasets[0].data = [0, 0];
    } else if (chartName === "salesDynamics") {
      chart.data.labels = ["Загрузка..."];
      chart.data.datasets[0].data = [0];
      chart.data.datasets[1].data = [0];
    }

    chart.update("none");
  }

  /**
   * Show error state for a specific chart
   * @param {string} chartName - Name of the chart
   * @param {string} errorMessage - Error message to display
   */
  showChartError(chartName, errorMessage) {
    const chart = this.charts[chartName];
    if (!chart) return;

    console.error(`Chart ${chartName} error:`, errorMessage);

    // You could implement error visualization here
    // For now, just clear the chart
    if (chartName === "marketplace") {
      chart.data.datasets[0].data = [0, 0];
    } else if (chartName === "salesDynamics") {
      chart.data.labels = ["Ошибка загрузки"];
      chart.data.datasets[0].data = [0];
      chart.data.datasets[1].data = [0];
    }

    chart.update("none");
  }

  /**
   * Destroy all charts (cleanup)
   */
  destroyCharts() {
    Object.values(this.charts).forEach((chart) => {
      if (chart && typeof chart.destroy === "function") {
        chart.destroy();
      }
    });
    this.charts = {};
  }

  /**
   * Resize all charts (useful for responsive design)
   */
  resizeCharts() {
    Object.values(this.charts).forEach((chart) => {
      if (chart && typeof chart.resize === "function") {
        chart.resize();
      }
    });
  }
}

// Global charts instance
window.analyticsCharts = new AnalyticsCharts();
