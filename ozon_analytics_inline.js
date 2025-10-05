/**
 * Встроенная версия Ozon Analytics для дашборда
 * Все компоненты в одном файле
 */

// Основной класс интеграции
class OzonAnalyticsInline {
  constructor() {
    this.apiBaseUrl = "/api/ozon-analytics.php";
    this.isLoading = false;
    this.currentData = null;
    console.log("OzonAnalyticsInline initialized");
  }

  // Инициализация
  init() {
    console.log("Initializing Ozon Analytics...");
    this.createUI();
    this.loadInitialData();
  }

  // Создание интерфейса
  createUI() {
    const container =
      document.getElementById("ozon-analytics-container") ||
      document.querySelector(".ozon-content") ||
      document.body;

    container.innerHTML = `
            <div id="ozon-loading" style="display: none;" class="text-center p-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Загрузка...</span>
                </div>
                <p class="mt-2">Загрузка данных Ozon...</p>
            </div>
            
            <div id="ozon-error" class="alert alert-danger" style="display: none;"></div>
            
            <div id="ozon-content">
                <div class="row mb-4">
                    <div class="col-12">
                        <h4>📊 Воронка продаж Ozon</h4>
                    </div>
                </div>
                
                <div id="ozon-stats" class="row mb-4">
                    <!-- Статистика будет здесь -->
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5>График воронки</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="ozon-chart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5>Топ товары</h5>
                            </div>
                            <div class="card-body" id="ozon-products">
                                <!-- Список товаров -->
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5>Детальные данные</h5>
                            </div>
                            <div class="card-body">
                                <div id="ozon-table" class="table-responsive">
                                    <!-- Таблица данных -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
  }

  // Загрузка начальных данных
  loadInitialData() {
    const urlParams = new URLSearchParams(window.location.search);
    const startDate = urlParams.get("start_date") || "2025-09-01";
    const endDate = urlParams.get("end_date") || "2025-09-28";

    this.loadData(startDate, endDate);
  }

  // Загрузка данных
  async loadData(startDate, endDate) {
    if (this.isLoading) return;

    this.isLoading = true;
    this.showLoading();

    try {
      console.log("Loading Ozon data:", { startDate, endDate });

      const params = new URLSearchParams({
        action: "funnel-data",
        start_date: startDate,
        end_date: endDate,
      });

      const response = await fetch(`${this.apiBaseUrl}?${params}`);

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();

      if (!result.success) {
        throw new Error(result.message || "Ошибка API");
      }

      this.currentData = result.data || [];
      this.updateUI(this.currentData);
      this.hideLoading();

      console.log("Ozon data loaded:", this.currentData);
    } catch (error) {
      console.error("Error loading Ozon data:", error);
      this.showError("Ошибка загрузки данных: " + error.message);
      this.hideLoading();
    } finally {
      this.isLoading = false;
    }
  }

  // Обновление интерфейса
  updateUI(data) {
    if (!data || data.length === 0) {
      this.showNoData();
      return;
    }

    // Агрегируем данные
    const stats = this.aggregateData(data);

    // Обновляем компоненты
    this.updateStats(stats);
    this.updateChart(stats);
    this.updateProductsList(data);
    this.updateTable(data);
  }

  // Агрегация данных
  aggregateData(data) {
    return data.reduce(
      (acc, item) => {
        acc.views += item.views || 0;
        acc.cart_additions += item.cart_additions || 0;
        acc.orders += item.orders || 0;
        acc.revenue += item.revenue || 0;
        return acc;
      },
      { views: 0, cart_additions: 0, orders: 0, revenue: 0 }
    );
  }

  // Обновление статистики
  updateStats(stats) {
    const container = document.getElementById("ozon-stats");
    if (!container) return;

    const conversionViewToCart =
      stats.views > 0
        ? ((stats.cart_additions / stats.views) * 100).toFixed(2)
        : 0;
    const conversionCartToOrder =
      stats.cart_additions > 0
        ? ((stats.orders / stats.cart_additions) * 100).toFixed(2)
        : 0;
    const conversionOverall =
      stats.views > 0 ? ((stats.orders / stats.views) * 100).toFixed(2) : 0;

    container.innerHTML = `
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h5>Просмотры</h5>
                        <h3>${stats.views.toLocaleString()}</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body text-center">
                        <h5>В корзину</h5>
                        <h3>${stats.cart_additions.toLocaleString()}</h3>
                        <small>${conversionViewToCart}% от просмотров</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h5>Заказы</h5>
                        <h3>${stats.orders.toLocaleString()}</h3>
                        <small>${conversionCartToOrder}% от корзины</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h5>Выручка</h5>
                        <h3>${stats.revenue.toLocaleString()} ₽</h3>
                        <small>${conversionOverall}% общая конверсия</small>
                    </div>
                </div>
            </div>
        `;
  }

  // Обновление графика (простая версия без Chart.js)
  updateChart(stats) {
    const canvas = document.getElementById("ozon-chart");
    if (!canvas) return;

    const ctx = canvas.getContext("2d");
    const width = canvas.width;
    const height = canvas.height;

    // Очищаем canvas
    ctx.clearRect(0, 0, width, height);

    // Данные для воронки
    const data = [
      { label: "Просмотры", value: stats.views, color: "#007bff" },
      { label: "В корзину", value: stats.cart_additions, color: "#ffc107" },
      { label: "Заказы", value: stats.orders, color: "#28a745" },
    ];

    const maxValue = Math.max(...data.map((d) => d.value));
    const barHeight = 40;
    const barSpacing = 60;
    const startY = 50;

    data.forEach((item, index) => {
      const barWidth =
        maxValue > 0 ? (item.value / maxValue) * (width - 200) : 0;
      const y = startY + index * barSpacing;

      // Рисуем полосу
      ctx.fillStyle = item.color;
      ctx.fillRect(150, y, barWidth, barHeight);

      // Рисуем текст
      ctx.fillStyle = "#333";
      ctx.font = "14px Arial";
      ctx.fillText(item.label, 10, y + 25);
      ctx.fillText(item.value.toLocaleString(), 160 + barWidth, y + 25);
    });
  }

  // Обновление списка товаров
  updateProductsList(data) {
    const container = document.getElementById("ozon-products");
    if (!container) return;

    // Сортируем по выручке
    const sortedData = [...data]
      .sort((a, b) => (b.revenue || 0) - (a.revenue || 0))
      .slice(0, 5);

    let html = '<div class="list-group list-group-flush">';

    sortedData.forEach((item, index) => {
      const productName = item.product_id || `Товар ${index + 1}`;
      html += `
                <div class="list-group-item d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1">${productName}</h6>
                        <small class="text-muted">${
                          item.orders || 0
                        } заказов</small>
                    </div>
                    <span class="badge bg-primary rounded-pill">${(
                      item.revenue || 0
                    ).toLocaleString()} ₽</span>
                </div>
            `;
    });

    html += "</div>";
    container.innerHTML = html;
  }

  // Обновление таблицы
  updateTable(data) {
    const container = document.getElementById("ozon-table");
    if (!container) return;

    let html = `
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

    data.forEach((item, index) => {
      const productName = item.product_id || `Товар ${index + 1}`;
      const conversion = item.conversion_overall || 0;

      html += `
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

    html += "</tbody></table>";
    container.innerHTML = html;
  }

  // Показать загрузку
  showLoading() {
    const loading = document.getElementById("ozon-loading");
    const content = document.getElementById("ozon-content");
    const error = document.getElementById("ozon-error");

    if (loading) loading.style.display = "block";
    if (content) content.style.opacity = "0.5";
    if (error) error.style.display = "none";
  }

  // Скрыть загрузку
  hideLoading() {
    const loading = document.getElementById("ozon-loading");
    const content = document.getElementById("ozon-content");

    if (loading) loading.style.display = "none";
    if (content) content.style.opacity = "1";
  }

  // Показать ошибку
  showError(message) {
    const error = document.getElementById("ozon-error");
    const content = document.getElementById("ozon-content");

    if (error) {
      error.innerHTML = `<strong>Ошибка!</strong> ${message}`;
      error.style.display = "block";
    }
    if (content) content.style.display = "none";
  }

  // Показать отсутствие данных
  showNoData() {
    const content = document.getElementById("ozon-content");
    if (content) {
      content.innerHTML = `
                <div class="alert alert-info text-center">
                    <h4>📊 Нет данных для отображения</h4>
                    <p>Выберите другой период или проверьте подключение к API Ozon</p>
                </div>
            `;
    }
  }
}

// Автоматическая инициализация
document.addEventListener("DOMContentLoaded", function () {
  if (
    window.location.search.includes("view=ozon-analytics") ||
    document.querySelector(".ozon-content") ||
    document.getElementById("ozon-analytics-container")
  ) {
    console.log("Initializing Ozon Analytics Inline...");
    const ozonAnalytics = new OzonAnalyticsInline();
    ozonAnalytics.init();

    // Делаем доступным глобально
    window.ozonAnalytics = ozonAnalytics;
  }
});

// Экспортируем для использования
window.OzonAnalyticsInline = OzonAnalyticsInline;
