/**
 * Quality Monitoring Dashboard JavaScript
 * Handles interactive features and real-time updates
 */

class QualityMonitoringDashboard {
  constructor(data) {
    this.data = data;
    this.charts = {};
    this.refreshInterval = null;
    this.init();
  }

  init() {
    this.initializeEventListeners();
    this.initializeCharts();
    this.initializeDrillDown();
    this.initializeSchedulerControls();
    this.startAutoRefresh();
  }

  initializeEventListeners() {
    // Refresh button
    document.getElementById("refresh-btn").addEventListener("click", () => {
      this.refreshDashboard();
    });

    // Force check button
    document.getElementById("force-check-btn").addEventListener("click", () => {
      this.forceQualityCheck();
    });

    // Export report button
    document
      .getElementById("export-report-btn")
      .addEventListener("click", () => {
        this.exportReport();
      });

    // Metric cards click handlers
    document.querySelectorAll(".metric-card").forEach((card) => {
      card.addEventListener("click", () => {
        const metric = card.dataset.metric;
        this.showMetricDetails(metric);
      });
    });
  }

  initializeCharts() {
    this.initializeQualityTrendsChart();
    this.initializeMatchingAccuracyChart();
    this.initializeDonutCharts();
  }

  initializeQualityTrendsChart() {
    const ctx = document
      .getElementById("quality-trends-chart")
      .getContext("2d");

    // Fetch trends data
    fetch("/mdm/api/quality-trends?days=30")
      .then((response) => response.json())
      .then((data) => {
        const labels = Object.keys(data).reverse();
        const datasets = [
          {
            label: "Полнота данных",
            data: labels.map((date) => data[date]?.completeness || 0),
            borderColor: "#10b981",
            backgroundColor: "rgba(16, 185, 129, 0.1)",
            tension: 0.4,
          },
          {
            label: "Точность",
            data: labels.map((date) => data[date]?.accuracy || 0),
            borderColor: "#3b82f6",
            backgroundColor: "rgba(59, 130, 246, 0.1)",
            tension: 0.4,
          },
          {
            label: "Покрытие",
            data: labels.map((date) => data[date]?.coverage || 0),
            borderColor: "#f59e0b",
            backgroundColor: "rgba(245, 158, 11, 0.1)",
            tension: 0.4,
          },
        ];

        this.charts.qualityTrends = new Chart(ctx, {
          type: "line",
          data: { labels, datasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: "top",
              },
              tooltip: {
                mode: "index",
                intersect: false,
              },
            },
            scales: {
              y: {
                beginAtZero: true,
                max: 100,
                ticks: {
                  callback: function (value) {
                    return value + "%";
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
      })
      .catch((error) => {
        console.error("Error loading quality trends:", error);
        this.showError("Ошибка загрузки трендов качества");
      });
  }

  initializeMatchingAccuracyChart() {
    const ctx = document
      .getElementById("matching-accuracy-chart")
      .getContext("2d");

    fetch("/mdm/api/matching-trends?days=30")
      .then((response) => response.json())
      .then((data) => {
        const labels = data.map((item) => item.date).reverse();
        const datasets = [
          {
            label: "Высокая уверенность",
            data: data.map((item) => item.high_confidence).reverse(),
            backgroundColor: "#10b981",
            stack: "Stack 0",
          },
          {
            label: "Средняя уверенность",
            data: data.map((item) => item.medium_confidence).reverse(),
            backgroundColor: "#f59e0b",
            stack: "Stack 0",
          },
          {
            label: "Низкая уверенность",
            data: data.map((item) => item.low_confidence).reverse(),
            backgroundColor: "#ef4444",
            stack: "Stack 0",
          },
        ];

        this.charts.matchingAccuracy = new Chart(ctx, {
          type: "bar",
          data: { labels, datasets },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                position: "top",
              },
            },
            scales: {
              x: {
                stacked: true,
              },
              y: {
                stacked: true,
                beginAtZero: true,
              },
            },
          },
        });
      })
      .catch((error) => {
        console.error("Error loading matching trends:", error);
        this.showError("Ошибка загрузки трендов сопоставления");
      });
  }

  initializeDonutCharts() {
    document.querySelectorAll(".donut-chart").forEach((chart) => {
      const percentage = parseFloat(chart.dataset.percentage);
      chart.style.setProperty("--percentage", percentage);
    });
  }

  initializeDrillDown() {
    const tabButtons = document.querySelectorAll(".tab-btn");
    const drillDownContent = document.getElementById("drill-down-content");

    tabButtons.forEach((button) => {
      button.addEventListener("click", () => {
        // Update active tab
        tabButtons.forEach((btn) => btn.classList.remove("active"));
        button.classList.add("active");

        // Load drill-down data
        const issue = button.dataset.issue;
        this.loadProblematicProducts(issue);
      });
    });

    // Load initial data
    this.loadProblematicProducts("unknown_brand");
  }

  loadProblematicProducts(issue) {
    const drillDownContent = document.getElementById("drill-down-content");
    drillDownContent.innerHTML = '<div class="loading">Загрузка...</div>';

    fetch(`/mdm/api/problematic-products?issue=${issue}&limit=50`)
      .then((response) => response.json())
      .then((data) => {
        this.renderProblematicProducts(data, issue);
      })
      .catch((error) => {
        console.error("Error loading problematic products:", error);
        drillDownContent.innerHTML =
          '<div class="error">Ошибка загрузки данных</div>';
      });
  }

  renderProblematicProducts(products, issue) {
    const drillDownContent = document.getElementById("drill-down-content");

    if (products.length === 0) {
      drillDownContent.innerHTML =
        '<div class="no-data">Проблемных товаров не найдено</div>';
      return;
    }

    let html = '<div class="problematic-products-table"><table><thead><tr>';

    // Table headers based on issue type
    switch (issue) {
      case "unknown_brand":
      case "no_category":
      case "no_description":
        html +=
          "<th>Master ID</th><th>Название</th><th>Бренд</th><th>Категория</th><th>Обновлено</th>";
        break;
      case "pending_verification":
        html +=
          "<th>Master ID</th><th>External SKU</th><th>Источник</th><th>Уверенность</th><th>Создано</th>";
        break;
    }

    html += "</tr></thead><tbody>";

    products.forEach((product) => {
      html += "<tr>";
      switch (issue) {
        case "unknown_brand":
        case "no_category":
        case "no_description":
          html += `
                        <td>${product.master_id}</td>
                        <td>${product.canonical_name || "Не указано"}</td>
                        <td>${product.canonical_brand || "Не указано"}</td>
                        <td>${product.canonical_category || "Не указано"}</td>
                        <td>${new Date(product.updated_at).toLocaleDateString(
                          "ru-RU"
                        )}</td>
                    `;
          break;
        case "pending_verification":
          html += `
                        <td>${product.master_id || "Новый"}</td>
                        <td>${product.external_sku}</td>
                        <td>${product.source}</td>
                        <td>${(product.confidence_score * 100).toFixed(1)}%</td>
                        <td>${new Date(product.created_at).toLocaleDateString(
                          "ru-RU"
                        )}</td>
                    `;
          break;
      }
      html += "</tr>";
    });

    html += "</tbody></table></div>";
    drillDownContent.innerHTML = html;
  }

  initializeSchedulerControls() {
    // Toggle switches for scheduler
    document.querySelectorAll(".toggle-switch input").forEach((toggle) => {
      toggle.addEventListener("change", (e) => {
        const checkName = e.target.dataset.check;
        const enabled = e.target.checked;
        this.toggleSchedulerCheck(checkName, enabled);
      });
    });
  }

  toggleSchedulerCheck(checkName, enabled) {
    fetch("/mdm/api/scheduler/toggle", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        check_name: checkName,
        enabled: enabled,
      }),
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          this.showNotification(data.message, "success");
        } else {
          this.showNotification(data.message, "error");
          // Revert toggle state
          document.querySelector(`input[data-check="${checkName}"]`).checked =
            !enabled;
        }
      })
      .catch((error) => {
        console.error("Error toggling scheduler check:", error);
        this.showNotification(
          "Ошибка изменения настроек планировщика",
          "error"
        );
        // Revert toggle state
        document.querySelector(`input[data-check="${checkName}"]`).checked =
          !enabled;
      });
  }

  forceQualityCheck() {
    const button = document.getElementById("force-check-btn");
    const originalText = button.textContent;

    button.innerHTML = '<span class="spinner"></span> Проверка...';
    button.disabled = true;

    fetch("/mdm/api/force-quality-check", {
      method: "POST",
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          this.showNotification(
            `Проверка завершена. Создано уведомлений: ${data.alerts_generated}`,
            "success"
          );
          this.refreshDashboard();
        } else {
          this.showNotification("Ошибка выполнения проверки качества", "error");
        }
      })
      .catch((error) => {
        console.error("Error forcing quality check:", error);
        this.showNotification("Ошибка выполнения проверки качества", "error");
      })
      .finally(() => {
        button.textContent = originalText;
        button.disabled = false;
      });
  }

  refreshDashboard() {
    const button = document.getElementById("refresh-btn");
    const originalText = button.textContent;

    button.innerHTML = '<span class="spinner"></span> Обновление...';
    button.disabled = true;

    // Refresh metrics
    fetch("/mdm/api/metrics")
      .then((response) => response.json())
      .then((data) => {
        this.updateMetrics(data);
        this.showNotification("Данные обновлены", "success");
      })
      .catch((error) => {
        console.error("Error refreshing metrics:", error);
        this.showNotification("Ошибка обновления данных", "error");
      })
      .finally(() => {
        button.textContent = originalText;
        button.disabled = false;
      });

    // Refresh charts
    this.refreshCharts();
  }

  updateMetrics(metrics) {
    // Update overall health score
    const overallScore =
      Object.values(metrics).reduce((sum, metric) => sum + metric.overall, 0) /
      Object.keys(metrics).length;
    document.getElementById("overall-health-score").textContent =
      overallScore.toFixed(1) + "%";

    // Update health status
    const healthStatus = document.getElementById("health-status");
    if (overallScore >= 90) {
      healthStatus.innerHTML = '<span class="status-excellent">Отличное</span>';
    } else if (overallScore >= 80) {
      healthStatus.innerHTML = '<span class="status-good">Хорошее</span>';
    } else if (overallScore >= 70) {
      healthStatus.innerHTML =
        '<span class="status-warning">Требует внимания</span>';
    } else {
      healthStatus.innerHTML =
        '<span class="status-critical">Критическое</span>';
    }

    // Update metric cards
    Object.entries(metrics).forEach(([metricName, metricData]) => {
      const card = document.querySelector(`[data-metric="${metricName}"]`);
      if (card) {
        const valueElement = card.querySelector(".metric-value");
        const progressFill = card.querySelector(".progress-fill");

        if (valueElement) {
          valueElement.textContent = metricData.overall.toFixed(1) + "%";
        }

        if (progressFill) {
          progressFill.style.width = metricData.overall + "%";
        }
      }
    });
  }

  refreshCharts() {
    // Refresh quality trends chart
    if (this.charts.qualityTrends) {
      fetch("/mdm/api/quality-trends?days=30")
        .then((response) => response.json())
        .then((data) => {
          const labels = Object.keys(data).reverse();
          this.charts.qualityTrends.data.labels = labels;
          this.charts.qualityTrends.data.datasets[0].data = labels.map(
            (date) => data[date]?.completeness || 0
          );
          this.charts.qualityTrends.data.datasets[1].data = labels.map(
            (date) => data[date]?.accuracy || 0
          );
          this.charts.qualityTrends.data.datasets[2].data = labels.map(
            (date) => data[date]?.coverage || 0
          );
          this.charts.qualityTrends.update();
        });
    }

    // Refresh matching accuracy chart
    if (this.charts.matchingAccuracy) {
      fetch("/mdm/api/matching-trends?days=30")
        .then((response) => response.json())
        .then((data) => {
          const labels = data.map((item) => item.date).reverse();
          this.charts.matchingAccuracy.data.labels = labels;
          this.charts.matchingAccuracy.data.datasets[0].data = data
            .map((item) => item.high_confidence)
            .reverse();
          this.charts.matchingAccuracy.data.datasets[1].data = data
            .map((item) => item.medium_confidence)
            .reverse();
          this.charts.matchingAccuracy.data.datasets[2].data = data
            .map((item) => item.low_confidence)
            .reverse();
          this.charts.matchingAccuracy.update();
        });
    }
  }

  exportReport() {
    const button = document.getElementById("export-report-btn");
    const originalText = button.textContent;

    button.innerHTML = '<span class="spinner"></span> Экспорт...';
    button.disabled = true;

    fetch("/mdm/api/report?type=current")
      .then((response) => response.json())
      .then((data) => {
        this.downloadReport(data);
        this.showNotification("Отчет экспортирован", "success");
      })
      .catch((error) => {
        console.error("Error exporting report:", error);
        this.showNotification("Ошибка экспорта отчета", "error");
      })
      .finally(() => {
        button.textContent = originalText;
        button.disabled = false;
      });
  }

  downloadReport(reportData) {
    const dataStr = JSON.stringify(reportData, null, 2);
    const dataBlob = new Blob([dataStr], { type: "application/json" });

    const link = document.createElement("a");
    link.href = URL.createObjectURL(dataBlob);
    link.download = `quality_report_${
      new Date().toISOString().split("T")[0]
    }.json`;
    link.click();
  }

  showMetricDetails(metric) {
    // Show detailed modal or navigate to detailed view
    console.log("Show details for metric:", metric);
    // Implementation depends on requirements
  }

  startAutoRefresh() {
    // Auto-refresh every 5 minutes
    this.refreshInterval = setInterval(() => {
      this.refreshDashboard();
    }, 5 * 60 * 1000);
  }

  stopAutoRefresh() {
    if (this.refreshInterval) {
      clearInterval(this.refreshInterval);
      this.refreshInterval = null;
    }
  }

  showNotification(message, type = "info") {
    // Create notification element
    const notification = document.createElement("div");
    notification.className = `notification notification-${type}`;
    notification.textContent = message;

    // Add to page
    document.body.appendChild(notification);

    // Auto-remove after 3 seconds
    setTimeout(() => {
      notification.remove();
    }, 3000);
  }

  showError(message) {
    this.showNotification(message, "error");
  }
}

// Global functions for inline event handlers
function forceRunCheck(checkName) {
  fetch(`/mdm/api/scheduler/force-run`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
    },
    body: JSON.stringify({ check_name: checkName }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        dashboard.showNotification(
          `Проверка '${checkName}' запущена`,
          "success"
        );
      } else {
        dashboard.showNotification(data.message, "error");
      }
    })
    .catch((error) => {
      console.error("Error forcing check run:", error);
      dashboard.showNotification("Ошибка запуска проверки", "error");
    });
}

// Initialize dashboard when data is available
function initializeQualityMonitoringDashboard(data) {
  window.dashboard = new QualityMonitoringDashboard(data);
}

// Notification styles (add to CSS if not already present)
const notificationStyles = `
.notification {
    position: fixed;
    top: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 6px;
    color: white;
    font-weight: 500;
    z-index: 1000;
    animation: slideIn 0.3s ease;
}

.notification-success {
    background-color: #10b981;
}

.notification-error {
    background-color: #ef4444;
}

.notification-info {
    background-color: #3b82f6;
}

.notification-warning {
    background-color: #f59e0b;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}
`;

// Add notification styles to page
const styleSheet = document.createElement("style");
styleSheet.textContent = notificationStyles;
document.head.appendChild(styleSheet);
