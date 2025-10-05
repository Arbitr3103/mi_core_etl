/**
 * Ozon Demographics Dashboard Component
 *
 * Управляет отображением демографических данных покупателей Ozon
 * Включает возрастное распределение, гендерное распределение и географическую карту
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonDemographics {
  constructor(options = {}) {
    this.options = {
      apiBaseUrl: "/src/api/ozon-analytics.php",
      containerId: "demographicsContainer",
      autoRefresh: false,
      refreshInterval: 300000, // 5 minutes
      ...options,
    };

    this.charts = {
      ageDistribution: null,
      genderDistribution: null,
      regionMap: null,
    };

    this.currentFilters = {};
    this.refreshTimer = null;

    this.init();
  }

  /**
   * Initialize the demographics dashboard
   */
  init() {
    this.initializeContainer();
    this.initializeCharts();
    this.bindEvents();
    this.loadDemographicsData();

    if (this.options.autoRefresh) {
      this.startAutoRefresh();
    }
  }

  /**
   * Initialize the main container structure
   */
  initializeContainer() {
    const container = document.getElementById(this.options.containerId);
    if (!container) {
      console.error(
        "Demographics container not found:",
        this.options.containerId
      );
      return;
    }

    container.innerHTML = `
      <div class="row mb-4">
        <div class="col-12">
          <div class="d-flex justify-content-between align-items-center">
            <h5>👥 Демографический анализ покупателей</h5>
            <div class="btn-group" role="group">
              <button type="button" id="refreshDemographics" class="btn btn-outline-primary btn-sm">
                🔄 Обновить
              </button>
              <button type="button" id="exportDemographics" class="btn btn-outline-success btn-sm">
                📤 Экспорт
              </button>
            </div>
          </div>
        </div>
      </div>
      
      <div class="row">
        <!-- Age Distribution Chart -->
        <div class="col-md-6 mb-4">
          <div class="card">
            <div class="card-header">
              <h6 class="mb-0">📊 Возрастное распределение</h6>
            </div>
            <div class="card-body">
              <div class="chart-container" style="height: 300px;">
                <canvas id="ageDistributionChart"></canvas>
              </div>
              <div id="ageDistributionLoading" class="text-center py-4" style="display: none;">
                <div class="spinner-border spinner-border-sm" role="status">
                  <span class="visually-hidden">Загрузка...</span>
                </div>
                <div class="mt-2">Загрузка данных...</div>
              </div>
              <div id="ageDistributionError" class="alert alert-warning" style="display: none;">
                <small>Данные временно недоступны</small>
              </div>
            </div>
          </div>
        </div>

        <!-- Gender Distribution Chart -->
        <div class="col-md-6 mb-4">
          <div class="card">
            <div class="card-header">
              <h6 class="mb-0">⚥ Гендерное распределение</h6>
            </div>
            <div class="card-body">
              <div class="chart-container" style="height: 300px;">
                <canvas id="genderDistributionChart"></canvas>
              </div>
              <div id="genderDistributionLoading" class="text-center py-4" style="display: none;">
                <div class="spinner-border spinner-border-sm" role="status">
                  <span class="visually-hidden">Загрузка...</span>
                </div>
                <div class="mt-2">Загрузка данных...</div>
              </div>
              <div id="genderDistributionError" class="alert alert-warning" style="display: none;">
                <small>Данные временно недоступны</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row">
        <!-- Regional Distribution -->
        <div class="col-12 mb-4">
          <div class="card">
            <div class="card-header">
              <h6 class="mb-0">🗺️ Географическое распределение покупателей</h6>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-8">
                  <div id="regionMapContainer" style="height: 400px; background: #f8f9fa; border-radius: 8px; position: relative;">
                    <div class="d-flex align-items-center justify-content-center h-100">
                      <div class="text-center">
                        <div class="mb-3">🗺️</div>
                        <div class="text-muted">Интерактивная карта регионов</div>
                        <small class="text-muted">Данные загружаются...</small>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-md-4">
                  <div id="regionStatsContainer">
                    <h6>📈 Топ регионы по заказам</h6>
                    <div id="regionStatsList" class="list-group list-group-flush">
                      <!-- Region stats will be loaded here -->
                    </div>
                  </div>
                </div>
              </div>
              <div id="regionMapLoading" class="text-center py-4" style="display: none;">
                <div class="spinner-border spinner-border-sm" role="status">
                  <span class="visually-hidden">Загрузка...</span>
                </div>
                <div class="mt-2">Загрузка географических данных...</div>
              </div>
              <div id="regionMapError" class="alert alert-warning" style="display: none;">
                <small>Географические данные временно недоступны</small>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Demographics Summary -->
      <div class="row">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h6 class="mb-0">📋 Сводка по демографии</h6>
            </div>
            <div class="card-body">
              <div id="demographicsSummary" class="row">
                <!-- Summary stats will be loaded here -->
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  }

  /**
   * Initialize all charts
   */
  initializeCharts() {
    this.initializeAgeDistributionChart();
    this.initializeGenderDistributionChart();
    this.initializeRegionMap();
  }

  /**
   * Initialize age distribution chart
   */
  initializeAgeDistributionChart() {
    const canvas = document.getElementById("ageDistributionChart");
    if (!canvas) return;

    const ctx = canvas.getContext("2d");
    this.charts.ageDistribution = new Chart(ctx, {
      type: "bar",
      data: {
        labels: [],
        datasets: [
          {
            label: "Количество заказов",
            data: [],
            backgroundColor: [
              "#FF6384",
              "#36A2EB",
              "#FFCE56",
              "#4BC0C0",
              "#9966FF",
              "#FF9F40",
            ],
            borderColor: [
              "#FF6384",
              "#36A2EB",
              "#FFCE56",
              "#4BC0C0",
              "#9966FF",
              "#FF9F40",
            ],
            borderWidth: 1,
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
          tooltip: {
            callbacks: {
              label: function (context) {
                const value = context.parsed.y;
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage =
                  total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                return `${value.toLocaleString()} заказов (${percentage}%)`;
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
          },
        },
      },
    });
  }

  /**
   * Initialize gender distribution chart
   */
  initializeGenderDistributionChart() {
    const canvas = document.getElementById("genderDistributionChart");
    if (!canvas) return;

    const ctx = canvas.getContext("2d");
    this.charts.genderDistribution = new Chart(ctx, {
      type: "doughnut",
      data: {
        labels: [],
        datasets: [
          {
            data: [],
            backgroundColor: ["#36A2EB", "#FF6384", "#FFCE56"],
            borderColor: ["#36A2EB", "#FF6384", "#FFCE56"],
            borderWidth: 2,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: "bottom",
          },
          tooltip: {
            callbacks: {
              label: function (context) {
                const value = context.parsed;
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage =
                  total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                return `${
                  context.label
                }: ${value.toLocaleString()} (${percentage}%)`;
              },
            },
          },
        },
      },
    });
  }

  /**
   * Initialize region map (simplified version)
   */
  initializeRegionMap() {
    // For now, we'll create a simple visual representation
    // In a full implementation, you would integrate with a mapping library like Leaflet or Google Maps
    const container = document.getElementById("regionMapContainer");
    if (!container) return;

    // This is a placeholder implementation
    // In production, you would use a proper mapping library
    this.charts.regionMap = {
      container: container,
      data: [],
      update: (data) => {
        this.updateRegionMapVisualization(data);
      },
    };
  }

  /**
   * Update region map visualization
   */
  updateRegionMapVisualization(data) {
    const container = document.getElementById("regionMapContainer");
    if (!container || !data || !Array.isArray(data)) return;

    // Create a simple grid-based visualization for regions
    const regions = data.filter((item) => item.region).slice(0, 20); // Top 20 regions

    if (regions.length === 0) {
      container.innerHTML = `
        <div class="d-flex align-items-center justify-content-center h-100">
          <div class="text-center">
            <div class="mb-3">🗺️</div>
            <div class="text-muted">Нет данных по регионам</div>
          </div>
        </div>
      `;
      return;
    }

    // Calculate max orders for scaling
    const maxOrders = Math.max(...regions.map((r) => r.orders_count));

    // Create region blocks
    const regionBlocks = regions
      .map((region) => {
        const intensity =
          maxOrders > 0 ? (region.orders_count / maxOrders) * 100 : 0;
        const opacity = Math.max(0.2, intensity / 100);

        return `
        <div class="region-block" 
             style="
               background-color: rgba(54, 162, 235, ${opacity});
               border: 1px solid #36A2EB;
               border-radius: 4px;
               padding: 8px;
               margin: 2px;
               display: inline-block;
               min-width: 80px;
               text-align: center;
               cursor: pointer;
               transition: all 0.3s ease;
             "
             title="${
               region.region
             }: ${region.orders_count.toLocaleString()} заказов"
             data-region="${region.region}"
             data-orders="${region.orders_count}">
          <div style="font-size: 0.8rem; font-weight: bold;">${
            region.region
          }</div>
          <div style="font-size: 0.7rem;">${region.orders_count.toLocaleString()}</div>
        </div>
      `;
      })
      .join("");

    container.innerHTML = `
      <div style="padding: 20px; height: 100%; overflow-y: auto;">
        <div style="display: flex; flex-wrap: wrap; justify-content: center; align-items: flex-start;">
          ${regionBlocks}
        </div>
        <div class="mt-3 text-center">
          <small class="text-muted">
            Интенсивность цвета отражает количество заказов в регионе
          </small>
        </div>
      </div>
    `;

    // Add hover effects
    container.querySelectorAll(".region-block").forEach((block) => {
      block.addEventListener("mouseenter", function () {
        this.style.transform = "scale(1.05)";
        this.style.boxShadow = "0 4px 8px rgba(0,0,0,0.2)";
      });

      block.addEventListener("mouseleave", function () {
        this.style.transform = "scale(1)";
        this.style.boxShadow = "none";
      });

      block.addEventListener("click", function () {
        const region = this.dataset.region;
        const orders = this.dataset.orders;
        alert(`${region}: ${parseInt(orders).toLocaleString()} заказов`);
      });
    });
  }

  /**
   * Bind event listeners
   */
  bindEvents() {
    // Refresh button
    const refreshButton = document.getElementById("refreshDemographics");
    if (refreshButton) {
      refreshButton.addEventListener("click", () => {
        this.loadDemographicsData(true);
      });
    }

    // Export button
    const exportButton = document.getElementById("exportDemographics");
    if (exportButton) {
      exportButton.addEventListener("click", () => {
        this.exportDemographicsData();
      });
    }

    // Window resize event
    window.addEventListener("resize", () => {
      this.resizeCharts();
    });
  }

  /**
   * Load demographics data from API
   */
  async loadDemographicsData(forceRefresh = false) {
    try {
      this.showLoading();

      // Get current filters from the main dashboard
      const dateFrom =
        document.getElementById("analyticsStartDate")?.value ||
        this.getDefaultStartDate();
      const dateTo =
        document.getElementById("analyticsEndDate")?.value ||
        this.getDefaultEndDate();

      const params = new URLSearchParams({
        action: "demographics",
        date_from: dateFrom,
        date_to: dateTo,
        use_cache: !forceRefresh,
      });

      const url = `${this.options.apiBaseUrl}?${params.toString()}`;
      const response = await fetch(url);
      const result = await response.json();

      if (!response.ok) {
        throw new Error(result.message || "Ошибка загрузки данных");
      }

      if (!result.success) {
        throw new Error(result.message || "Ошибка API");
      }

      this.updateCharts(result.data);
      this.updateSummary(result.data);
      this.hideLoading();
    } catch (error) {
      console.error("Error loading demographics data:", error);
      this.showError(error.message);
    }
  }

  /**
   * Update all charts with new data
   */
  updateCharts(data) {
    if (!data || !Array.isArray(data)) {
      console.warn("Invalid demographics data received");
      return;
    }

    this.updateAgeDistributionChart(data);
    this.updateGenderDistributionChart(data);
    this.updateRegionMap(data);
    this.updateRegionStats(data);
  }

  /**
   * Update age distribution chart
   */
  updateAgeDistributionChart(data) {
    if (!this.charts.ageDistribution) return;

    // Aggregate data by age group
    const ageGroups = {};
    data.forEach((item) => {
      if (item.age_group) {
        ageGroups[item.age_group] =
          (ageGroups[item.age_group] || 0) + (item.orders_count || 0);
      }
    });

    // Sort age groups logically
    const sortedAgeGroups = Object.entries(ageGroups).sort(([a], [b]) => {
      const ageOrder = ["18-24", "25-34", "35-44", "45-54", "55-64", "65+"];
      return ageOrder.indexOf(a) - ageOrder.indexOf(b);
    });

    this.charts.ageDistribution.data.labels = sortedAgeGroups.map(
      ([age]) => age
    );
    this.charts.ageDistribution.data.datasets[0].data = sortedAgeGroups.map(
      ([, count]) => count
    );
    this.charts.ageDistribution.update();
  }

  /**
   * Update gender distribution chart
   */
  updateGenderDistributionChart(data) {
    if (!this.charts.genderDistribution) return;

    // Aggregate data by gender
    const genderGroups = {};
    data.forEach((item) => {
      if (item.gender) {
        const gender =
          item.gender === "M"
            ? "Мужчины"
            : item.gender === "F"
            ? "Женщины"
            : "Не указано";
        genderGroups[gender] =
          (genderGroups[gender] || 0) + (item.orders_count || 0);
      }
    });

    this.charts.genderDistribution.data.labels = Object.keys(genderGroups);
    this.charts.genderDistribution.data.datasets[0].data =
      Object.values(genderGroups);
    this.charts.genderDistribution.update();
  }

  /**
   * Update region map
   */
  updateRegionMap(data) {
    if (!this.charts.regionMap) return;

    // Aggregate data by region
    const regionGroups = {};
    data.forEach((item) => {
      if (item.region) {
        if (!regionGroups[item.region]) {
          regionGroups[item.region] = {
            region: item.region,
            orders_count: 0,
            revenue: 0,
          };
        }
        regionGroups[item.region].orders_count += item.orders_count || 0;
        regionGroups[item.region].revenue += item.revenue || 0;
      }
    });

    const regionData = Object.values(regionGroups).sort(
      (a, b) => b.orders_count - a.orders_count
    );

    this.charts.regionMap.update(regionData);
  }

  /**
   * Update region statistics list
   */
  updateRegionStats(data) {
    const container = document.getElementById("regionStatsList");
    if (!container) return;

    // Aggregate data by region
    const regionGroups = {};
    data.forEach((item) => {
      if (item.region) {
        if (!regionGroups[item.region]) {
          regionGroups[item.region] = {
            region: item.region,
            orders_count: 0,
            revenue: 0,
          };
        }
        regionGroups[item.region].orders_count += item.orders_count || 0;
        regionGroups[item.region].revenue += item.revenue || 0;
      }
    });

    const topRegions = Object.values(regionGroups)
      .sort((a, b) => b.orders_count - a.orders_count)
      .slice(0, 10);

    if (topRegions.length === 0) {
      container.innerHTML =
        '<div class="text-muted text-center py-3">Нет данных по регионам</div>';
      return;
    }

    const regionItems = topRegions
      .map(
        (region, index) => `
      <div class="list-group-item d-flex justify-content-between align-items-center">
        <div>
          <div class="fw-bold">${index + 1}. ${region.region}</div>
          <small class="text-muted">${region.revenue.toLocaleString()} ₽ выручка</small>
        </div>
        <span class="badge bg-primary rounded-pill">${region.orders_count.toLocaleString()}</span>
      </div>
    `
      )
      .join("");

    container.innerHTML = regionItems;
  }

  /**
   * Update demographics summary
   */
  updateSummary(data) {
    const container = document.getElementById("demographicsSummary");
    if (!container || !data || !Array.isArray(data)) return;

    // Calculate summary statistics
    const totalOrders = data.reduce(
      (sum, item) => sum + (item.orders_count || 0),
      0
    );
    const totalRevenue = data.reduce(
      (sum, item) => sum + (item.revenue || 0),
      0
    );
    const uniqueRegions = new Set(
      data.filter((item) => item.region).map((item) => item.region)
    ).size;
    const avgOrderValue = totalOrders > 0 ? totalRevenue / totalOrders : 0;

    container.innerHTML = `
      <div class="col-md-3">
        <div class="text-center">
          <div class="h4 text-primary">${totalOrders.toLocaleString()}</div>
          <div class="text-muted">Всего заказов</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="text-center">
          <div class="h4 text-success">${totalRevenue.toLocaleString()} ₽</div>
          <div class="text-muted">Общая выручка</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="text-center">
          <div class="h4 text-info">${uniqueRegions}</div>
          <div class="text-muted">Регионов</div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="text-center">
          <div class="h4 text-warning">${avgOrderValue.toLocaleString()} ₽</div>
          <div class="text-muted">Средний чек</div>
        </div>
      </div>
    `;
  }

  /**
   * Show loading state
   */
  showLoading() {
    const loadingElements = [
      "ageDistributionLoading",
      "genderDistributionLoading",
      "regionMapLoading",
    ];

    loadingElements.forEach((id) => {
      const element = document.getElementById(id);
      if (element) {
        element.style.display = "block";
      }
    });

    this.hideError();
  }

  /**
   * Hide loading state
   */
  hideLoading() {
    const loadingElements = [
      "ageDistributionLoading",
      "genderDistributionLoading",
      "regionMapLoading",
    ];

    loadingElements.forEach((id) => {
      const element = document.getElementById(id);
      if (element) {
        element.style.display = "none";
      }
    });
  }

  /**
   * Show error state
   */
  showError(message) {
    this.hideLoading();

    const errorElements = [
      "ageDistributionError",
      "genderDistributionError",
      "regionMapError",
    ];

    errorElements.forEach((id) => {
      const element = document.getElementById(id);
      if (element) {
        element.style.display = "block";
        element.innerHTML = `<small>${message}</small>`;
      }
    });
  }

  /**
   * Hide error state
   */
  hideError() {
    const errorElements = [
      "ageDistributionError",
      "genderDistributionError",
      "regionMapError",
    ];

    errorElements.forEach((id) => {
      const element = document.getElementById(id);
      if (element) {
        element.style.display = "none";
      }
    });
  }

  /**
   * Export demographics data
   */
  async exportDemographicsData() {
    try {
      const dateFrom =
        document.getElementById("analyticsStartDate")?.value ||
        this.getDefaultStartDate();
      const dateTo =
        document.getElementById("analyticsEndDate")?.value ||
        this.getDefaultEndDate();

      const exportData = {
        data_type: "demographics",
        format: "csv",
        date_from: dateFrom,
        date_to: dateTo,
      };

      const response = await fetch(this.options.apiBaseUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify(exportData),
      });

      if (response.ok) {
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = `ozon_demographics_export_${
          new Date().toISOString().split("T")[0]
        }.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);

        this.showSuccessNotification(
          "Демографические данные экспортированы успешно"
        );
      } else {
        throw new Error("Ошибка экспорта данных");
      }
    } catch (error) {
      console.error("Export error:", error);
      this.showErrorNotification("Ошибка экспорта: " + error.message);
    }
  }

  /**
   * Resize all charts
   */
  resizeCharts() {
    Object.values(this.charts).forEach((chart) => {
      if (chart && typeof chart.resize === "function") {
        chart.resize();
      }
    });
  }

  /**
   * Start auto-refresh timer
   */
  startAutoRefresh() {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
    }

    this.refreshTimer = setInterval(() => {
      this.loadDemographicsData();
    }, this.options.refreshInterval);
  }

  /**
   * Stop auto-refresh timer
   */
  stopAutoRefresh() {
    if (this.refreshTimer) {
      clearInterval(this.refreshTimer);
      this.refreshTimer = null;
    }
  }

  /**
   * Get default start date (30 days ago)
   */
  getDefaultStartDate() {
    const date = new Date();
    date.setDate(date.getDate() - 30);
    return date.toISOString().split("T")[0];
  }

  /**
   * Get default end date (today)
   */
  getDefaultEndDate() {
    return new Date().toISOString().split("T")[0];
  }

  /**
   * Show success notification
   */
  showSuccessNotification(message) {
    console.log("Success:", message);
    if (typeof showNotification === "function") {
      showNotification(message, "success");
    }
  }

  /**
   * Show error notification
   */
  showErrorNotification(message) {
    console.error("Error:", message);
    if (typeof showNotification === "function") {
      showNotification(message, "error");
    }
  }

  /**
   * Destroy the component and clean up
   */
  destroy() {
    this.stopAutoRefresh();

    Object.values(this.charts).forEach((chart) => {
      if (chart && typeof chart.destroy === "function") {
        chart.destroy();
      }
    });

    this.charts = {};
  }
}

// Export for use in other modules
if (typeof module !== "undefined" && module.exports) {
  module.exports = OzonDemographics;
}
