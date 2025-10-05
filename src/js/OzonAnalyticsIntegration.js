/**
 * OzonAnalyticsIntegration - Основной класс интеграции с Ozon Analytics
 */
class OzonAnalyticsIntegration {
  constructor(options = {}) {
    this.apiBaseUrl = options.apiBaseUrl || "/api/ozon-analytics.php";
    this.funnelChartContainer =
      options.funnelChartContainer || "ozon-funnel-chart";
    this.kpiContainer = options.kpiContainer || "analyticsKPI";
    this.autoRefresh = options.autoRefresh || false;
    this.refreshInterval = options.refreshInterval || 300000;
    this.funnelChart = null;
    this.isLoading = false;
    this.currentData = null;
  }

  /**
   * Инициализация интеграции
   */
  init() {
    console.log("Initializing Ozon Analytics Integration...");

    // Инициализируем компоненты
    this.initFunnelChart();
    this.bindEvents();

    // Загружаем начальные данные
    this.loadInitialData();

    console.log("Ozon Analytics Integration initialized");
  }

  /**
   * Инициализация графика воронки
   */
  initFunnelChart() {
    console.log(
      "Initializing funnel chart with container ID:",
      this.funnelChartContainer
    );
    if (typeof OzonFunnelChart !== "undefined") {
      this.funnelChart = new OzonFunnelChart(this.funnelChartContainer);
      this.funnelChart.init();
    } else {
      console.warn("OzonFunnelChart not available");
    }
  }

  /**
   * Инициализация демографических данных - ОТКЛЮЧЕНО
   * Демографические данные недоступны в API Ozon
   */
  initDemographics() {
    console.log("Demographics disabled - not available in Ozon API");
  }

  /**
   * Привязка событий
   */
  bindEvents() {
    // Обновление данных при изменении фильтров
    document.addEventListener("filtersChanged", (event) => {
      this.handleFiltersChange(event.detail);
    });

    // Обновление данных при изменении периода
    document.addEventListener("dateRangeChanged", (event) => {
      this.handleDateRangeChange(event.detail);
    });
  }

  /**
   * Загрузка начальных данных
   */
  loadInitialData() {
    // Получаем даты из полей формы или URL
    let startDate, endDate;

    // Сначала пробуем получить из полей аналитики
    const analyticsStartDate = document.getElementById("analyticsStartDate");
    const analyticsEndDate = document.getElementById("analyticsEndDate");

    if (
      analyticsStartDate &&
      analyticsEndDate &&
      analyticsStartDate.value &&
      analyticsEndDate.value
    ) {
      startDate = analyticsStartDate.value;
      endDate = analyticsEndDate.value;
      console.log("Got dates from analytics form fields:", {
        startDate,
        endDate,
      });
    } else {
      // Если нет полей аналитики, пробуем основные поля формы
      const mainStartDate = document.querySelector('input[name="start_date"]');
      const mainEndDate = document.querySelector('input[name="end_date"]');

      if (
        mainStartDate &&
        mainEndDate &&
        mainStartDate.value &&
        mainEndDate.value
      ) {
        startDate = mainStartDate.value;
        endDate = mainEndDate.value;
        console.log("Got dates from main form fields:", { startDate, endDate });
      } else {
        // В крайнем случае используем URL параметры или значения по умолчанию
        const urlParams = new URLSearchParams(window.location.search);
        startDate = urlParams.get("start_date") || this.getDefaultStartDate();
        endDate = urlParams.get("end_date") || this.getDefaultEndDate();
        console.log("Got dates from URL or defaults:", { startDate, endDate });
      }
    }

    this.loadData(startDate, endDate);
  }

  /**
   * Получение даты по умолчанию (начало)
   */
  getDefaultStartDate() {
    const date = new Date();
    date.setDate(date.getDate() - 30);
    return date.toISOString().split("T")[0];
  }

  /**
   * Получение даты по умолчанию (конец)
   */
  getDefaultEndDate() {
    return new Date().toISOString().split("T")[0];
  }

  /**
   * Загрузка данных
   */
  async loadData(startDate, endDate, filters = {}) {
    if (this.isLoading) {
      console.log("Already loading data...");
      return;
    }

    this.isLoading = true;
    this.showLoading();

    try {
      console.log("Loading Ozon data:", { startDate, endDate, filters });

      // Загружаем данные воронки
      const funnelData = await this.loadFunnelData(startDate, endDate, filters);

      // Обновляем компоненты (без демографии)
      this.updateComponents(funnelData, null);

      this.currentData = {
        funnel: funnelData,
        period: { startDate, endDate },
        filters,
      };

      this.hideLoading();
      console.log("Ozon data loaded successfully");
    } catch (error) {
      console.error("Error loading Ozon data:", error);
      this.showError("Ошибка загрузки данных: " + error.message);
      this.hideLoading();
    } finally {
      this.isLoading = false;
    }
  }

  /**
   * Загрузка данных воронки
   */
  async loadFunnelData(startDate, endDate, filters = {}) {
    const params = new URLSearchParams({
      action: "funnel-data",
      start_date: startDate,
      end_date: endDate,
      ...filters,
    });

    const response = await fetch(`${this.apiBaseUrl}?${params}`);

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const data = await response.json();

    if (!data.success) {
      throw new Error(data.message || "Ошибка API");
    }

    return data.data || [];
  }

  /**
   * Загрузка демографических данных - ОТКЛЮЧЕНО
   * Демографические данные недоступны в API Ozon
   */
  async loadDemographicsData(startDate, endDate, filters = {}) {
    console.log("Demographics loading skipped - not available in Ozon API");
    return null;
  }

  /**
   * Обновление компонентов
   */
  updateComponents(funnelData, demographicsData) {
    // Обновляем график воронки
    if (this.funnelChart) {
      if (funnelData && funnelData.length > 0) {
        this.funnelChart.updateData(funnelData);
      } else {
        this.funnelChart.showNoData();
      }
    }

    // Демографические данные отключены - недоступны в API
    // if (this.demographics) { ... }

    // Обновляем таблицу данных
    this.updateDataTable(funnelData);
  }

  /**
   * Обновление таблицы данных
   */
  updateDataTable(data) {
    const tableContainer = document.getElementById("ozon-data-table");
    if (!tableContainer || !data || data.length === 0) return;

    let tableHtml = `
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Товар</th>
                            <th>Просмотры</th>
                            <th>В корзину</th>
                            <th>Заказы</th>
                            <th>Выручка</th>
                            <th>Конверсия</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

    data.forEach((item) => {
      const productName = item.product_id || "Общие данные";
      const conversion = item.conversion_overall || 0;

      tableHtml += `
                <tr>
                    <td>${productName}</td>
                    <td>${(item.views || 0).toLocaleString()}</td>
                    <td>${(item.cart_additions || 0).toLocaleString()}</td>
                    <td>${(item.orders || 0).toLocaleString()}</td>
                    <td>${(item.revenue || 0).toLocaleString()} ₽</td>
                    <td>${conversion}%</td>
                </tr>
            `;
    });

    tableHtml += `
                    </tbody>
                </table>
            </div>
        `;

    tableContainer.innerHTML = tableHtml;
  }

  /**
   * Показать индикатор загрузки
   */
  showLoading() {
    const loadingElements = document.querySelectorAll(".ozon-loading");
    loadingElements.forEach((el) => {
      el.style.display = "block";
    });

    const contentElements = document.querySelectorAll(".ozon-content");
    contentElements.forEach((el) => {
      el.style.opacity = "0.5";
    });
  }

  /**
   * Скрыть индикатор загрузки
   */
  hideLoading() {
    const loadingElements = document.querySelectorAll(".ozon-loading");
    loadingElements.forEach((el) => {
      el.style.display = "none";
    });

    const contentElements = document.querySelectorAll(".ozon-content");
    contentElements.forEach((el) => {
      el.style.opacity = "1";
    });
  }

  /**
   * Показать ошибку
   */
  showError(message) {
    const errorContainer = document.getElementById("ozon-error-container");
    if (errorContainer) {
      errorContainer.innerHTML = `
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Ошибка!</strong> ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
    }
  }

  /**
   * Обработка изменения фильтров
   */
  handleFiltersChange(filters) {
    if (this.currentData) {
      this.loadData(
        this.currentData.period.startDate,
        this.currentData.period.endDate,
        filters
      );
    }
  }

  /**
   * Обработка изменения периода
   */
  handleDateRangeChange(dateRange) {
    this.loadData(
      dateRange.startDate,
      dateRange.endDate,
      this.currentData?.filters || {}
    );
  }

  /**
   * Экспорт данных
   */
  async exportData(format = "json") {
    if (!this.currentData) {
      alert("Нет данных для экспорта");
      return;
    }

    try {
      const params = new URLSearchParams({
        action: "export-data",
        data_type: "funnel",
        format: format,
        start_date: this.currentData.period.startDate,
        end_date: this.currentData.period.endDate,
      });

      const response = await fetch(this.apiBaseUrl, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          action: "export-data",
          data_type: "funnel",
          format: format,
          date_from: this.currentData.period.startDate,
          date_to: this.currentData.period.endDate,
        }),
      });

      if (format === "csv") {
        const blob = await response.blob();
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = `ozon_data_${this.currentData.period.startDate}_${this.currentData.period.endDate}.csv`;
        a.click();
        window.URL.revokeObjectURL(url);
      } else {
        const data = await response.json();
        const blob = new Blob([JSON.stringify(data, null, 2)], {
          type: "application/json",
        });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = `ozon_data_${this.currentData.period.startDate}_${this.currentData.period.endDate}.json`;
        a.click();
        window.URL.revokeObjectURL(url);
      }
    } catch (error) {
      console.error("Export error:", error);
      alert("Ошибка экспорта данных: " + error.message);
    }
  }
}

// Экспортируем класс для использования
window.OzonAnalyticsIntegration = OzonAnalyticsIntegration;

// Автоматическая инициализация при загрузке страницы
document.addEventListener("DOMContentLoaded", function () {
  if (window.location.search.includes("view=ozon-analytics")) {
    const ozonAnalytics = new OzonAnalyticsIntegration();
    ozonAnalytics.init();

    // Делаем доступным глобально
    window.ozonAnalytics = ozonAnalytics;
  }
});
