/**
 * MDM Dashboard JavaScript
 * Handles dashboard functionality, real-time updates, and user interactions
 */

class MDMDashboard {
  constructor() {
    this.refreshInterval = 30000; // 30 seconds
    this.charts = {};
    this.refreshTimer = null;

    this.init();
  }

  /**
   * Initialize dashboard
   */
  init() {
    this.initializeCharts();
    this.setupEventListeners();
    this.startAutoRefresh();
    this.updateLastSyncTime();
  }

  /**
   * Initialize all charts
   */
  initializeCharts() {
    this.initQualityChart();
    this.initActivityChart();
  }

  /**
   * Initialize data quality chart
   */
  initQualityChart() {
    const ctx = document.getElementById("qualityChart");
    if (!ctx) return;

    const qualityData = window.dashboardData.quality_metrics;

    this.charts.quality = new Chart(ctx, {
      type: "doughnut",
      data: {
        labels: ["Полнота", "Точность", "Согласованность", "Актуальность"],
        datasets: [
          {
            data: [
              qualityData.completeness.overall,
              qualityData.accuracy.overall,
              qualityData.consistency.overall,
              qualityData.freshness.overall,
            ],
            backgroundColor: [
              "#198754", // Success green
              "#0dcaf0", // Info cyan
              "#ffc107", // Warning yellow
              "#0d6efd", // Primary blue
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
                return context.label + ": " + context.parsed.toFixed(1) + "%";
              },
            },
          },
        },
      },
    });
  }

  /**
   * Initialize activity chart
   */
  initActivityChart() {
    const ctx = document.getElementById("activityChart");
    if (!ctx) return;

    const activityData = window.dashboardData.recent_activity;

    // Prepare data for last 7 days
    const labels = [];
    const data = [];

    for (let i = 6; i >= 0; i--) {
      const date = new Date();
      date.setDate(date.getDate() - i);
      const dateStr = date.toISOString().split("T")[0];

      labels.push(
        date.toLocaleDateString("ru-RU", {
          month: "short",
          day: "numeric",
        })
      );

      const dayData = activityData.find((item) => item.date === dateStr);
      data.push(dayData ? dayData.new_mappings : 0);
    }

    this.charts.activity = new Chart(ctx, {
      type: "line",
      data: {
        labels: labels,
        datasets: [
          {
            label: "Новые сопоставления",
            data: data,
            borderColor: "#0d6efd",
            backgroundColor: "rgba(13, 110, 253, 0.1)",
            borderWidth: 2,
            fill: true,
            tension: 0.4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            display: false,
          },
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              stepSize: 1,
            },
          },
        },
      },
    });
  }

  /**
   * Setup event listeners
   */
  setupEventListeners() {
    // Refresh button
    const refreshBtn = document.querySelector('[onclick="refreshDashboard()"]');
    if (refreshBtn) {
      refreshBtn.addEventListener("click", (e) => {
        e.preventDefault();
        this.refreshDashboard();
      });
    }

    // ETL run button
    const etlBtn = document.querySelector('[onclick="runETL()"]');
    if (etlBtn) {
      etlBtn.addEventListener("click", (e) => {
        e.preventDefault();
        this.runETL();
      });
    }

    // Auto-refresh toggle (if exists)
    const autoRefreshToggle = document.getElementById("autoRefreshToggle");
    if (autoRefreshToggle) {
      autoRefreshToggle.addEventListener("change", (e) => {
        if (e.target.checked) {
          this.startAutoRefresh();
        } else {
          this.stopAutoRefresh();
        }
      });
    }
  }

  /**
   * Refresh dashboard data
   */
  async refreshDashboard() {
    const syncIndicator = document.getElementById("sync-indicator");

    try {
      // Show loading state
      syncIndicator.classList.add("syncing");

      const response = await fetch("/mdm/dashboard/data", {
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const result = await response.json();

      if (result.success) {
        this.updateDashboardData(result.data);
        this.showNotification("Данные обновлены", "success");
      } else {
        throw new Error(result.error || "Ошибка обновления данных");
      }
    } catch (error) {
      console.error("Error refreshing dashboard:", error);
      this.showNotification(
        "Ошибка обновления данных: " + error.message,
        "error"
      );
    } finally {
      syncIndicator.classList.remove("syncing");
    }
  }

  /**
   * Update dashboard with new data
   */
  updateDashboardData(data) {
    // Update statistics
    this.updateElement(
      "total-master-products",
      this.formatNumber(data.statistics.total_master_products)
    );
    this.updateElement(
      "coverage-percentage",
      data.statistics.coverage_percentage.toFixed(1) + "%"
    );
    this.updateElement(
      "pending-verification",
      this.formatNumber(data.pending_items.pending_verification)
    );
    this.updateElement(
      "sources-count",
      this.formatNumber(data.statistics.sources_count)
    );

    // Update progress bars
    this.updateProgressBars(data.quality_metrics);

    // Update charts
    this.updateCharts(data);

    // Update last sync time
    this.updateLastSyncTime(data.system_health.last_sync);

    // Update system health
    this.updateSystemHealth(data.system_health);
  }

  /**
   * Update progress bars
   */
  updateProgressBars(qualityMetrics) {
    const progressBars = {
      completeness: qualityMetrics.completeness.overall,
      accuracy: qualityMetrics.accuracy.overall,
      consistency: qualityMetrics.consistency.overall,
      freshness: qualityMetrics.freshness.overall,
    };

    Object.entries(progressBars).forEach(([key, value]) => {
      const progressBar = document.querySelector(
        `[data-metric="${key}"] .progress-bar`
      );
      if (progressBar) {
        progressBar.style.width = value + "%";
        progressBar.textContent = value.toFixed(1) + "%";
      }
    });
  }

  /**
   * Update charts with new data
   */
  updateCharts(data) {
    // Update quality chart
    if (this.charts.quality) {
      const qualityData = data.quality_metrics;
      this.charts.quality.data.datasets[0].data = [
        qualityData.completeness.overall,
        qualityData.accuracy.overall,
        qualityData.consistency.overall,
        qualityData.freshness.overall,
      ];
      this.charts.quality.update();
    }

    // Update activity chart
    if (this.charts.activity && data.recent_activity) {
      // Prepare new activity data
      const labels = [];
      const chartData = [];

      for (let i = 6; i >= 0; i--) {
        const date = new Date();
        date.setDate(date.getDate() - i);
        const dateStr = date.toISOString().split("T")[0];

        labels.push(
          date.toLocaleDateString("ru-RU", {
            month: "short",
            day: "numeric",
          })
        );

        const dayData = data.recent_activity.find(
          (item) => item.date === dateStr
        );
        chartData.push(dayData ? dayData.new_mappings : 0);
      }

      this.charts.activity.data.labels = labels;
      this.charts.activity.data.datasets[0].data = chartData;
      this.charts.activity.update();
    }
  }

  /**
   * Update system health indicators
   */
  updateSystemHealth(systemHealth) {
    // Update ETL status
    const etlStatus = document.querySelector('[data-status="etl"]');
    if (etlStatus) {
      const badge = etlStatus.querySelector(".badge");
      if (badge) {
        badge.className = `badge bg-${
          systemHealth.etl_status.status === "completed" ? "success" : "warning"
        }`;
        badge.textContent = systemHealth.etl_status.status;
      }
    }

    // Update database status
    const dbStatus = document.querySelector('[data-status="database"]');
    if (dbStatus) {
      const badge = dbStatus.querySelector(".badge");
      if (badge) {
        badge.className = `badge bg-${
          systemHealth.database_status.status === "healthy"
            ? "success"
            : "danger"
        }`;
        badge.textContent = systemHealth.database_status.status;
      }
    }
  }

  /**
   * Run ETL process
   */
  async runETL() {
    try {
      this.showNotification("Запуск ETL процесса...", "info");

      const response = await fetch("/mdm/etl/run", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      const result = await response.json();

      if (result.success) {
        this.showNotification("ETL процесс запущен успешно", "success");
        // Refresh dashboard after a delay
        setTimeout(() => this.refreshDashboard(), 5000);
      } else {
        throw new Error(result.error || "Ошибка запуска ETL");
      }
    } catch (error) {
      console.error("Error running ETL:", error);
      this.showNotification("Ошибка запуска ETL: " + error.message, "error");
    }
  }

  /**
   * Start auto-refresh
   */
  startAutoRefresh() {
    this.stopAutoRefresh(); // Clear existing timer
    this.refreshTimer = setInterval(() => {
      this.refreshDashboard();
    }, this.refreshInterval);
  }

  /**
   * Stop auto-refresh
   */
  stopAutoRefresh() {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }
  }

  /**
   * Update last sync time
   */
  updateLastSyncTime(lastSync = null) {
    const lastSyncElement = document.getElementById("last-sync");
    if (lastSyncElement) {
      if (lastSync) {
        const date = new Date(lastSync);
        lastSyncElement.textContent = date.toLocaleString("ru-RU");
      } else {
        lastSyncElement.textContent = "Неизвестно";
      }
    }
  }

  /**
   * Show notification
   */
  showNotification(message, type = "info") {
    const alertsContainer = document.getElementById("system-alerts");
    if (!alertsContainer) return;

    const alertClass =
      {
        success: "alert-success",
        error: "alert-danger",
        warning: "alert-warning",
        info: "alert-info",
      }[type] || "alert-info";

    const alert = document.createElement("div");
    alert.className = `alert ${alertClass} alert-dismissible fade show`;
    alert.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

    alertsContainer.appendChild(alert);

    // Auto-remove after 5 seconds
    setTimeout(() => {
      if (alert.parentNode) {
        alert.remove();
      }
    }, 5000);
  }

  /**
   * Update element content
   */
  updateElement(id, content) {
    const element = document.getElementById(id);
    if (element) {
      element.textContent = content;
    }
  }

  /**
   * Format number with thousands separator
   */
  formatNumber(num) {
    return new Intl.NumberFormat("ru-RU").format(num);
  }
}

// Global functions for backward compatibility
window.refreshDashboard = function () {
  if (window.mdmDashboard) {
    window.mdmDashboard.refreshDashboard();
  }
};

window.runETL = function () {
  if (window.mdmDashboard) {
    window.mdmDashboard.runETL();
  }
};

// Initialize dashboard when DOM is loaded
document.addEventListener("DOMContentLoaded", function () {
  window.mdmDashboard = new MDMDashboard();
});
