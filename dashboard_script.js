// Dashboard JavaScript
class WarehouseDashboard {
  constructor() {
    this.apiBaseUrl = "warehouse_dashboard_api.php";
    this.autoRefreshInterval = null;
    this.refreshIntervalSeconds = 60;
    this.charts = {};
    this.currentFilter = "all";
    this.currentActivityFilter = "active"; // Default to active products
    this.data = {
      summary: null,
      warehouses: [],
      urgent: [],
      charts: null,
    };

    this.init();
  }

  async init() {
    this.showLoading(true);
    await this.loadAllData();
    this.setupEventListeners();
    this.renderDashboard();
    this.initCharts();
    this.startAutoRefresh();
    this.showLoading(false);
    this.updateConnectionStatus("connected");
  }

  async loadAllData() {
    try {
      const activityParam = `activity_filter=${this.currentActivityFilter}`;
      const [summary, warehouses, urgent, charts] = await Promise.all([
        this.fetchData(`summary&${activityParam}`),
        this.fetchData(`warehouses&${activityParam}`),
        this.fetchData(`urgent&${activityParam}`),
        this.fetchData(`charts&${activityParam}`),
      ]);

      this.data.summary = summary.data;
      this.data.warehouses = warehouses.data;
      this.data.urgent = urgent.data;
      this.data.charts = charts.data;

      this.updateLastUpdateTime();
    } catch (error) {
      console.error("Error loading data:", error);
      this.showError("Ошибка загрузки данных: " + error.message);
      this.updateConnectionStatus("error");
    }
  }

  async fetchData(endpoint) {
    const response = await fetch(`${this.apiBaseUrl}?endpoint=${endpoint}`);
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    return await response.json();
  }

  setupEventListeners() {
    // Activity filter buttons
    document.querySelectorAll(".activity-filter-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        this.setActiveActivityFilter(e.target);
        this.currentActivityFilter = e.target.dataset.activity;
        this.refreshData();
      });
    });

    // Warehouse status filter buttons
    document.querySelectorAll(".filter-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        this.setActiveFilter(e.target);
        this.currentFilter = e.target.dataset.filter;
        this.renderWarehouses();
      });
    });

    // Chart type buttons
    document.querySelectorAll(".chart-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        document
          .querySelectorAll(".chart-btn")
          .forEach((b) => b.classList.remove("active"));
        e.target.classList.add("active");
        this.updateChart("salesChart", e.target.dataset.chart);
      });
    });

    // Refresh interval selector
    document
      .getElementById("refreshInterval")
      .addEventListener("change", (e) => {
        this.refreshIntervalSeconds = parseInt(e.target.value);
        if (this.autoRefreshInterval) {
          this.startAutoRefresh();
        }
      });
  }

  setActiveFilter(activeBtn) {
    document.querySelectorAll(".filter-btn").forEach((btn) => {
      btn.classList.remove("active");
    });
    activeBtn.classList.add("active");
  }

  setActiveActivityFilter(activeBtn) {
    document.querySelectorAll(".activity-filter-btn").forEach((btn) => {
      btn.classList.remove("active");
    });
    activeBtn.classList.add("active");
  }

  renderDashboard() {
    this.renderSummaryCards();
    this.renderWarehouses();
    this.renderAlerts();
    this.renderActivityStats();
    this.updateActivityFilterDisplay();
  }

  updateActivityFilterDisplay() {
    // Update the connection status to show current activity filter
    const statusElement = document.getElementById("connectionStatus");
    if (statusElement) {
      const filterText = this.getActivityFilterText();
      const filterIcon = this.getActivityFilterIcon();
      statusElement.innerHTML = `${filterIcon} ${filterText}`;
    }

    // Update the stats filter indicator
    const statsIndicator = document.getElementById("statsFilterIndicator");
    if (statsIndicator) {
      const filterText = this.getActivityFilterText();
      const filterIcon = this.getActivityFilterIcon();
      statsIndicator.innerHTML = `${filterIcon} Показаны: ${filterText}`;
    }
  }

  renderActivityStats() {
    const container = document.getElementById("activityStatsGrid");
    const summary = this.data.summary;

    if (!container || !summary) return;

    const totalProducts =
      (summary.activeProducts || 0) + (summary.inactiveProducts || 0);
    const activePercentage =
      totalProducts > 0
        ? (((summary.activeProducts || 0) / totalProducts) * 100).toFixed(1)
        : 0;
    const inactivePercentage =
      totalProducts > 0
        ? (((summary.inactiveProducts || 0) / totalProducts) * 100).toFixed(1)
        : 0;

    // Calculate filtered stats based on current activity filter
    let displayedProducts = 0;
    let displayedLabel = "";

    switch (this.currentActivityFilter) {
      case "active":
        displayedProducts = summary.activeProducts || 0;
        displayedLabel = "активных товаров";
        break;
      case "inactive":
        displayedProducts = summary.inactiveProducts || 0;
        displayedLabel = "неактивных товаров";
        break;
      case "all":
        displayedProducts = totalProducts;
        displayedLabel = "всего товаров";
        break;
    }

    container.innerHTML = `
      <div class="stat-item total-stat">
        <div class="stat-number">${displayedProducts.toLocaleString()}</div>
        <div class="stat-label">Отображено</div>
        <div class="stat-percentage">${displayedLabel}</div>
      </div>
      
      <div class="stat-item active-stat">
        <div class="stat-number">${(
          summary.activeProducts || 0
        ).toLocaleString()}</div>
        <div class="stat-label">Активные товары</div>
        <div class="stat-percentage">${activePercentage}% от общего</div>
      </div>
      
      <div class="stat-item inactive-stat">
        <div class="stat-number">${(
          summary.inactiveProducts || 0
        ).toLocaleString()}</div>
        <div class="stat-label">Неактивные товары</div>
        <div class="stat-percentage">${inactivePercentage}% от общего</div>
      </div>
      
      <div class="stat-item">
        <div class="stat-number">${summary.totalWarehouses || 0}</div>
        <div class="stat-label">Складов</div>
        <div class="stat-percentage">в системе</div>
      </div>
      
      <div class="stat-item">
        <div class="stat-number">${(
          summary.totalStock || 0
        ).toLocaleString()}</div>
        <div class="stat-label">Общий остаток</div>
        <div class="stat-percentage">единиц товара</div>
      </div>
      
      <div class="stat-item">
        <div class="stat-number">${(
          summary.criticalProducts || 0
        ).toLocaleString()}</div>
        <div class="stat-label">Критические</div>
        <div class="stat-percentage">требуют пополнения</div>
      </div>
    `;
  }

  renderSummaryCards() {
    const container = document.getElementById("summaryCards");
    const summary = this.data.summary;

    // Get activity filter display text
    const activityText = this.getActivityFilterText();
    const activityIcon = this.getActivityFilterIcon();

    container.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">📦</div>
                    <div class="card-title">Общие остатки</div>
                </div>
                <div class="metric-value">${summary.totalStock.toLocaleString()}</div>
                <div class="metric-label">единиц на ${
                  summary.totalWarehouses
                } складах</div>
                <div class="metric-change positive">+${
                  summary.uniqueProducts
                } товаров</div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">${activityIcon}</div>
                    <div class="card-title">Активность товаров</div>
                </div>
                <div class="metric-value">${summary.activeProducts || 0}</div>
                <div class="metric-label">${activityText}</div>
                <div class="metric-change ${
                  this.currentActivityFilter === "active"
                    ? "positive"
                    : this.currentActivityFilter === "inactive"
                    ? "negative"
                    : "neutral"
                }">
                    ${summary.inactiveProducts || 0} неактивных
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">⚠️</div>
                    <div class="card-title">Требуют пополнения</div>
                </div>
                <div class="metric-value">${summary.criticalProducts}</div>
                <div class="metric-label">товаров срочно</div>
                <div class="metric-change ${
                  summary.criticalProducts > 0 ? "negative" : "positive"
                }">
                    ${summary.attentionProducts} требуют внимания
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <div class="card-icon">💰</div>
                    <div class="card-title">Инвестиции</div>
                </div>
                <div class="metric-value">${summary.investmentNeeded}М</div>
                <div class="metric-label">рублей на пополнение</div>
                <div class="metric-change positive">${
                  summary.replenishmentNeed
                } единиц</div>
            </div>
        `;
  }

  getActivityFilterText() {
    switch (this.currentActivityFilter) {
      case "active":
        return "активных товаров";
      case "inactive":
        return "неактивных товаров";
      case "all":
        return "всего товаров";
      default:
        return "товаров";
    }
  }

  getActivityFilterIcon() {
    switch (this.currentActivityFilter) {
      case "active":
        return "✅";
      case "inactive":
        return "❌";
      case "all":
        return "📋";
      default:
        return "📊";
    }
  }

  renderWarehouses() {
    const container = document.getElementById("warehousesGrid");
    let warehouses = this.data.warehouses;

    // Apply warehouse status filter
    if (this.currentFilter !== "all") {
      warehouses = warehouses.filter((w) => w.status === this.currentFilter);
    }

    // Note: Activity filtering is handled at the API level, so we don't need to filter here
    // The warehouses data already reflects the current activity filter

    container.innerHTML = warehouses
      .map(
        (warehouse) => `
            <div class="warehouse-card status-${warehouse.status}">
                <div class="warehouse-header">
                    <div class="warehouse-name">${warehouse.name}</div>
                    <span class="warehouse-status status-${warehouse.status}">
                        ${this.getStatusText(warehouse.status)}
                    </span>
                </div>
                
                <div class="warehouse-metrics">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div class="metric">
                            <div class="metric-number">${
                              warehouse.products
                            }</div>
                            <div class="metric-text">Товаров</div>
                        </div>
                        <div class="metric">
                            <div class="metric-number">${warehouse.totalStock.toLocaleString()}</div>
                            <div class="metric-text">Остаток</div>
                        </div>
                        <div class="metric">
                            <div class="metric-number">${
                              warehouse.sales28d
                            }</div>
                            <div class="metric-text">Продажи 28д</div>
                        </div>
                        <div class="metric">
                            <div class="metric-number">${
                              warehouse.monthsOfStock
                                ? warehouse.monthsOfStock.toFixed(1)
                                : "N/A"
                            }</div>
                            <div class="metric-text">Месяцев запаса</div>
                        </div>
                    </div>
                    
                    <div style="margin: 1rem 0;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span style="font-size: 0.9rem; color: #718096;">Оборачиваемость</span>
                            <span style="font-size: 0.9rem; font-weight: 600;">${warehouse.turnoverRate.toFixed(
                              1
                            )}x/год</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill progress-${
                              warehouse.status
                            }" 
                                 style="width: ${Math.min(
                                   warehouse.turnoverRate * 10,
                                   100
                                 )}%"></div>
                        </div>
                    </div>
                    
                    ${
                      warehouse.recommendedOrder > 0
                        ? `
                        <div style="background: #f7fafc; padding: 1rem; border-radius: 8px; margin-top: 1rem;">
                            <div style="font-weight: 600; color: #2d3748; margin-bottom: 0.5rem;">
                                💰 Рекомендуемый заказ
                            </div>
                            <div style="font-size: 1.2rem; font-weight: 700; color: #667eea;">
                                ${warehouse.recommendedOrder.toLocaleString()} единиц
                            </div>
                            <div style="font-size: 0.9rem; color: #718096;">
                                ≈ ${warehouse.estimatedOrderValue} млн руб
                            </div>
                            <button class="export-btn" style="margin-top: 0.5rem; padding: 0.5rem 1rem; font-size: 0.8rem;" 
                                    onclick="dashboard.createWarehouseOrder('${
                                      warehouse.name
                                    }')">
                                📋 Создать заявку
                            </button>
                        </div>
                    `
                        : `
                        <div style="background: #c6f6d5; padding: 1rem; border-radius: 8px; margin-top: 1rem; text-align: center;">
                            <span style="color: #38a169; font-weight: 600;">✅ Пополнение не требуется</span>
                        </div>
                    `
                    }
                </div>
            </div>
        `
      )
      .join("");
  }

  renderAlerts() {
    const urgentItems = this.data.urgent.filter(
      (item) =>
        item.urgencyLevel === "immediate" || item.urgencyLevel === "urgent"
    );

    const alertsSection = document.getElementById("alertsSection");
    const alertsList = document.getElementById("alertsList");

    if (urgentItems.length > 0) {
      alertsSection.style.display = "block";
      alertsList.innerHTML = urgentItems
        .map(
          (item) => `
                <div class="urgent-item">
                    <div class="urgent-info">
                        <div class="urgent-product">
                            Товар ${item.productId} на складе ${
            item.warehouseName
          }
                        </div>
                        <div class="urgent-details">
                            Остаток: ${item.currentStock} • Продажи: ${
            item.dailySales
          }/день • 
                            До исчерпания: ${
                              item.daysUntilStockout || "N/A"
                            } дней
                        </div>
                    </div>
                    <button class="urgent-action" onclick="dashboard.createUrgentOrder(${
                      item.productId
                    }, '${item.warehouseName}')">
                        Заказать ${item.recommendedQty}
                    </button>
                </div>
            `
        )
        .join("");
    } else {
      alertsSection.style.display = "none";
    }
  }

  initCharts() {
    this.createSalesChart();
    this.createDistributionChart();
  }

  createSalesChart() {
    const ctx = document.getElementById("salesChart").getContext("2d");
    const chartData = this.data.charts.salesTrend;

    this.charts.salesChart = new Chart(ctx, {
      type: "bar",
      data: {
        labels: chartData.map((item) => item.warehouse_name),
        datasets: [
          {
            label: "Остатки",
            data: chartData.map((item) => item.total_stock),
            backgroundColor: "rgba(102, 126, 234, 0.6)",
            borderColor: "rgba(102, 126, 234, 1)",
            borderWidth: 2,
            yAxisID: "y",
          },
          {
            label: "Продажи за 28 дней",
            data: chartData.map((item) => item.sales_28d),
            backgroundColor: "rgba(118, 75, 162, 0.6)",
            borderColor: "rgba(118, 75, 162, 1)",
            borderWidth: 2,
            yAxisID: "y1",
          },
        ],
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            position: "top",
          },
        },
        scales: {
          y: {
            type: "linear",
            display: true,
            position: "left",
            title: {
              display: true,
              text: "Остатки",
            },
          },
          y1: {
            type: "linear",
            display: true,
            position: "right",
            title: {
              display: true,
              text: "Продажи",
            },
            grid: {
              drawOnChartArea: false,
            },
          },
        },
      },
    });
  }

  createDistributionChart() {
    const ctx = document.getElementById("distributionChart").getContext("2d");
    const chartData = this.data.charts.stockDistribution;

    const labels = {
      critical: "Критические",
      low: "Низкие",
      normal: "Нормальные",
      excess: "Избыточные",
      no_sales: "Без продаж",
    };

    const colors = {
      critical: "#f56565",
      low: "#ed8936",
      normal: "#48bb78",
      excess: "#4299e1",
      no_sales: "#a0aec0",
    };

    this.charts.distributionChart = new Chart(ctx, {
      type: "doughnut",
      data: {
        labels: chartData.map(
          (item) => labels[item.stock_category] || item.stock_category
        ),
        datasets: [
          {
            data: chartData.map((item) => item.count),
            backgroundColor: chartData.map(
              (item) => colors[item.stock_category] || "#a0aec0"
            ),
            borderWidth: 2,
            borderColor: "#ffffff",
          },
        ],
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            position: "bottom",
          },
        },
      },
    });
  }

  updateChart(chartId, type) {
    if (this.charts[chartId]) {
      this.charts[chartId].config.type = type;
      this.charts[chartId].update();
    }
  }

  getStatusText(status) {
    const texts = {
      critical: "Критический",
      warning: "Требует внимания",
      normal: "Нормальный",
      excess: "Избыточный",
    };
    return texts[status] || "Неизвестно";
  }

  showLoading(show) {
    const overlay = document.getElementById("loadingOverlay");
    overlay.style.display = show ? "flex" : "none";
  }

  showError(message) {
    // Simple error display - in production, use a proper notification system
    alert("Ошибка: " + message);
  }

  updateLastUpdateTime() {
    document.getElementById("lastUpdate").textContent =
      "Обновлено: " + new Date().toLocaleString("ru-RU");
  }

  updateConnectionStatus(status) {
    const statusElement = document.getElementById("connectionStatus");
    const apiStatusElement = document.getElementById("apiStatus");

    const statuses = {
      connected: { text: "🟢 Подключено", color: "#38a169" },
      error: { text: "🔴 Ошибка", color: "#e53e3e" },
      loading: { text: "🔄 Загрузка...", color: "#d69e2e" },
    };

    const statusInfo = statuses[status] || statuses.loading;
    statusElement.textContent = statusInfo.text;
    statusElement.style.color = statusInfo.color;

    if (apiStatusElement) {
      apiStatusElement.textContent = statusInfo.text;
      apiStatusElement.style.color = statusInfo.color;
    }
  }

  async refreshData() {
    this.updateConnectionStatus("loading");
    try {
      await this.loadAllData();
      this.renderDashboard();
      this.updateConnectionStatus("connected");
    } catch (error) {
      this.updateConnectionStatus("error");
      this.showError("Не удалось обновить данные");
    }
  }

  startAutoRefresh() {
    if (this.autoRefreshInterval) {
      clearInterval(this.autoRefreshInterval);
    }

    this.autoRefreshInterval = setInterval(() => {
      this.refreshData();
    }, this.refreshIntervalSeconds * 1000);
  }

  toggleAutoRefresh() {
    if (this.autoRefreshInterval) {
      clearInterval(this.autoRefreshInterval);
      this.autoRefreshInterval = null;
      alert("Авто-обновление отключено");
    } else {
      this.startAutoRefresh();
      alert("Авто-обновление включено");
    }
  }

  createWarehouseOrder(warehouseName) {
    const warehouse = this.data.warehouses.find(
      (w) => w.name === warehouseName
    );
    if (warehouse) {
      alert(
        `Создание заявки на пополнение для склада ${warehouseName}:\n\n` +
          `Количество: ${warehouse.recommendedOrder} единиц\n` +
          `Стоимость: ${warehouse.estimatedOrderValue} млн руб\n\n` +
          `Функция будет реализована в следующей версии.`
      );
    }
  }

  createUrgentOrder(productId, warehouseName) {
    const item = this.data.urgent.find(
      (u) => u.productId === productId && u.warehouseName === warehouseName
    );
    if (item) {
      alert(
        `Срочная заявка на пополнение:\n\n` +
          `Товар: ${productId}\n` +
          `Склад: ${warehouseName}\n` +
          `Количество: ${item.recommendedQty} единиц\n\n` +
          `Функция будет реализована в следующей версии.`
      );
    }
  }
}

// Global functions for HTML onclick handlers
function refreshData() {
  dashboard.refreshData();
}

function toggleAutoRefresh() {
  dashboard.toggleAutoRefresh();
}

function closeAlerts() {
  document.getElementById("alertsSection").style.display = "none";
}

function exportReport(format) {
  alert(
    `Экспорт отчета в формате ${format.toUpperCase()} будет реализован в следующей версии.`
  );
}

function exportProcurementOrder() {
  const totalOrder = dashboard.data.warehouses.reduce(
    (sum, w) => sum + w.recommendedOrder,
    0
  );
  const totalValue = dashboard.data.warehouses.reduce(
    (sum, w) => sum + w.estimatedOrderValue,
    0
  );

  alert(
    `Общая заявка на пополнение:\n\n` +
      `Общее количество: ${totalOrder.toLocaleString()} единиц\n` +
      `Общая стоимость: ${totalValue.toFixed(2)} млн руб\n\n` +
      `Функция экспорта будет реализована в следующей версии.`
  );
}

function printReport() {
  window.print();
}

// Initialize dashboard when DOM is loaded
let dashboard;
document.addEventListener("DOMContentLoaded", function () {
  dashboard = new WarehouseDashboard();
});
