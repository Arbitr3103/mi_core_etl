// Warehouse Dashboard Advanced JavaScript
class WarehouseDashboard {
  constructor() {
    this.apiBaseUrl = "warehouse_dashboard_api.php";
    this.refreshInterval = 30000; // 30 seconds
    this.init();
  }

  async init() {
    this.showLoading(true);
    await this.loadDashboardData();
    this.setupEventListeners();
    this.startAutoRefresh();
    this.showLoading(false);
  }

  showLoading(show) {
    const overlay = document.getElementById("loadingOverlay");
    if (overlay) {
      overlay.style.display = show ? "flex" : "none";
    }
  }

  async loadDashboardData() {
    try {
      const response = await fetch(
        `${this.apiBaseUrl}?endpoint=summary&activity_filter=active`
      );
      const result = await response.json();

      if (result.success) {
        this.updateSummaryCards(result.data);
        this.updateActivityStats(result.data);
        this.updateStatus("✅ Подключено", "connected");
      } else {
        throw new Error(result.error || "API Error");
      }
    } catch (error) {
      console.error("Error loading dashboard data:", error);
      this.updateStatus("❌ Ошибка подключения", "error");
    }
  }

  updateSummaryCards(data) {
    const summaryCards = document.getElementById("summaryCards");
    if (!summaryCards) return;

    summaryCards.innerHTML = `
            <div class="summary-card">
                <div class="card-icon">🏪</div>
                <div class="card-content">
                    <div class="card-title">Всего складов</div>
                    <div class="card-value">${data.totalWarehouses || 0}</div>
                </div>
            </div>
            <div class="summary-card">
                <div class="card-icon">📦</div>
                <div class="card-content">
                    <div class="card-title">Уникальных товаров</div>
                    <div class="card-value">${(
                      data.uniqueProducts || 0
                    ).toLocaleString()}</div>
                </div>
            </div>
            <div class="summary-card">
                <div class="card-icon">📊</div>
                <div class="card-content">
                    <div class="card-title">Общий запас</div>
                    <div class="card-value">${(
                      data.totalStock || 0
                    ).toLocaleString()}</div>
                </div>
            </div>
            <div class="summary-card">
                <div class="card-icon">💰</div>
                <div class="card-content">
                    <div class="card-title">Продажи (28д)</div>
                    <div class="card-value">${(
                      data.sales28d || 0
                    ).toLocaleString()}</div>
                </div>
            </div>
        `;
  }

  updateActivityStats(data) {
    const statsGrid = document.getElementById("activityStatsGrid");
    if (!statsGrid) return;

    statsGrid.innerHTML = `
            <div class="activity-stat-card active">
                <div class="stat-number">${data.activeProducts || 0}</div>
                <div class="stat-label">Активные товары</div>
            </div>
            <div class="activity-stat-card critical">
                <div class="stat-number">${data.criticalProducts || 0}</div>
                <div class="stat-label">Критические</div>
            </div>
            <div class="activity-stat-card attention">
                <div class="stat-number">${data.attentionProducts || 0}</div>
                <div class="stat-label">Требуют внимания</div>
            </div>
            <div class="activity-stat-card excess">
                <div class="stat-number">${data.excessProducts || 0}</div>
                <div class="stat-label">Избыточные</div>
            </div>
        `;
  }

  updateStatus(message, status) {
    const statusElement = document.getElementById("connectionStatus");
    const apiStatusElement = document.getElementById("apiStatus");

    if (statusElement) {
      statusElement.textContent = message;
      statusElement.className = `status-indicator ${status}`;
    }

    if (apiStatusElement) {
      apiStatusElement.textContent = message;
    }

    const lastUpdate = document.getElementById("lastUpdate");
    if (lastUpdate) {
      lastUpdate.textContent = new Date().toLocaleString("ru-RU");
    }
  }

  setupEventListeners() {
    const refreshInterval = document.getElementById("refreshInterval");
    if (refreshInterval) {
      refreshInterval.addEventListener("change", (e) => {
        this.refreshInterval = parseInt(e.target.value) * 1000;
        this.startAutoRefresh();
      });
    }
  }

  startAutoRefresh() {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
    }

    this.refreshTimer = setInterval(() => {
      this.loadDashboardData();
    }, this.refreshInterval);
  }
}

// Initialize dashboard when page loads
document.addEventListener("DOMContentLoaded", () => {
  new WarehouseDashboard();
});
