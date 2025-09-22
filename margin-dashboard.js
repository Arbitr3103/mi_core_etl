/**
 * JavaScript библиотека для работы с API маржинальности
 */

class MarginDashboard {
  constructor(apiUrl = "margin_api.php") {
    this.apiUrl = apiUrl;
    this.charts = {};
  }

  /**
   * Выполнить запрос к API
   */
  async apiRequest(action, params = {}) {
    const url = new URL(this.apiUrl, window.location.origin);
    url.searchParams.append("action", action);

    Object.keys(params).forEach((key) => {
      if (params[key] !== null && params[key] !== undefined) {
        url.searchParams.append(key, params[key]);
      }
    });

    try {
      const response = await fetch(url);
      const data = await response.json();

      if (!data.success) {
        throw new Error(data.error || "Ошибка API");
      }

      return data.data;
    } catch (error) {
      console.error("API Error:", error);
      throw error;
    }
  }

  /**
   * Получить KPI метрики
   */
  async getKPIMetrics(startDate, endDate, clientId = null) {
    return await this.apiRequest("kpi", {
      start_date: startDate,
      end_date: endDate,
      client_id: clientId,
    });
  }

  /**
   * Получить данные для графика
   */
  async getChartData(startDate, endDate, clientId = null) {
    return await this.apiRequest("chart", {
      start_date: startDate,
      end_date: endDate,
      client_id: clientId,
    });
  }

  /**
   * Получить структуру расходов
   */
  async getCostBreakdown(startDate, endDate, clientId = null) {
    return await this.apiRequest("breakdown", {
      start_date: startDate,
      end_date: endDate,
      client_id: clientId,
    });
  }

  /**
   * Обновить KPI виджеты
   */
  updateKPIWidgets(kpiData) {
    Object.keys(kpiData).forEach((key) => {
      const element = document.getElementById(`kpi-${key}`);
      if (element) {
        const metric = kpiData[key];
        let displayValue = metric.value;

        if (metric.format === "currency") {
          displayValue += " ₽";
        } else if (metric.format === "percent") {
          displayValue += "%";
        }

        element.textContent = displayValue;
      }
    });
  }

  /**
   * Создать график динамики маржинальности
   */
  createMarginChart(canvasId, chartData) {
    const ctx = document.getElementById(canvasId).getContext("2d");

    // Уничтожаем существующий график
    if (this.charts[canvasId]) {
      this.charts[canvasId].destroy();
    }

    this.charts[canvasId] = new Chart(ctx, {
      type: "line",
      data: {
        labels: chartData.map((item) => {
          const date = new Date(item.metric_date);
          return date.toLocaleDateString("ru-RU", {
            day: "2-digit",
            month: "2-digit",
          });
        }),
        datasets: [
          {
            label: "Выручка (₽)",
            data: chartData.map((item) => item.revenue),
            borderColor: "rgb(75, 192, 192)",
            backgroundColor: "rgba(75, 192, 192, 0.1)",
            yAxisID: "y",
            tension: 0.1,
          },
          {
            label: "Прибыль (₽)",
            data: chartData.map((item) => item.profit),
            borderColor: "rgb(255, 99, 132)",
            backgroundColor: "rgba(255, 99, 132, 0.1)",
            yAxisID: "y",
            tension: 0.1,
          },
          {
            label: "Маржинальность (%)",
            data: chartData.map((item) => item.margin_percent),
            borderColor: "rgb(54, 162, 235)",
            backgroundColor: "rgba(54, 162, 235, 0.1)",
            yAxisID: "y1",
            tension: 0.1,
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
        scales: {
          y: {
            type: "linear",
            display: true,
            position: "left",
            title: {
              display: true,
              text: "Сумма (₽)",
            },
          },
          y1: {
            type: "linear",
            display: true,
            position: "right",
            title: {
              display: true,
              text: "Маржинальность (%)",
            },
            grid: {
              drawOnChartArea: false,
            },
          },
        },
        plugins: {
          legend: {
            position: "top",
          },
          tooltip: {
            callbacks: {
              label: function (context) {
                let label = context.dataset.label || "";
                if (label) {
                  label += ": ";
                }

                if (context.dataset.yAxisID === "y1") {
                  label += context.parsed.y + "%";
                } else {
                  label +=
                    new Intl.NumberFormat("ru-RU").format(context.parsed.y) +
                    " ₽";
                }

                return label;
              },
            },
          },
        },
      },
    });
  }

  /**
   * Создать график структуры расходов
   */
  createCostChart(canvasId, costData) {
    const ctx = document.getElementById(canvasId).getContext("2d");

    // Уничтожаем существующий график
    if (this.charts[canvasId]) {
      this.charts[canvasId].destroy();
    }

    this.charts[canvasId] = new Chart(ctx, {
      type: "doughnut",
      data: {
        labels: [
          "Себестоимость",
          "Комиссии",
          "Логистика",
          "Прочие расходы",
          "Прибыль",
        ],
        datasets: [
          {
            data: [
              costData.cogs,
              costData.commission,
              costData.shipping,
              costData.other_expenses,
              costData.profit,
            ],
            backgroundColor: [
              "#FF6384",
              "#36A2EB",
              "#FFCE56",
              "#FF9F40",
              "#4BC0C0",
            ],
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
                const label = context.label || "";
                const value = context.parsed;
                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                const percentage = ((value / total) * 100).toFixed(1);
                return `${label}: ${value.toLocaleString(
                  "ru-RU"
                )} ₽ (${percentage}%)`;
              },
            },
          },
        },
      },
    });
  }

  /**
   * Показать индикатор загрузки
   */
  showLoading(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
      element.innerHTML =
        '<div class="text-center"><div class="spinner-border" role="status"></div></div>';
    }
  }

  /**
   * Скрыть индикатор загрузки
   */
  hideLoading(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
      element.innerHTML = "";
    }
  }

  /**
   * Показать ошибку
   */
  showError(elementId, message) {
    const element = document.getElementById(elementId);
    if (element) {
      element.innerHTML = `<div class="alert alert-danger">${message}</div>`;
    }
  }

  /**
   * Обновить весь дашборд
   */
  async updateDashboard(startDate, endDate, clientId = null) {
    try {
      // Показываем индикаторы загрузки
      this.showLoading("kpi-container");
      this.showLoading("chart-container");
      this.showLoading("cost-chart-container");

      // Загружаем данные параллельно
      const [kpiData, chartData, costData] = await Promise.all([
        this.getKPIMetrics(startDate, endDate, clientId),
        this.getChartData(startDate, endDate, clientId),
        this.getCostBreakdown(startDate, endDate, clientId),
      ]);

      // Обновляем виджеты
      this.updateKPIWidgets(kpiData);
      this.createMarginChart("marginChart", chartData);
      this.createCostChart("costChart", costData);

      // Скрываем индикаторы загрузки
      this.hideLoading("kpi-container");
      this.hideLoading("chart-container");
      this.hideLoading("cost-chart-container");
    } catch (error) {
      console.error("Dashboard update error:", error);
      this.showError(
        "kpi-container",
        "Ошибка загрузки данных: " + error.message
      );
    }
  }

  /**
   * Экспорт данных в CSV
   */
  async exportToCSV(startDate, endDate, clientId = null) {
    const url = new URL(this.apiUrl, window.location.origin);
    url.searchParams.append("action", "export");
    url.searchParams.append("start_date", startDate);
    url.searchParams.append("end_date", endDate);

    if (clientId) {
      url.searchParams.append("client_id", clientId);
    }

    // Открываем ссылку для скачивания
    window.open(url.toString(), "_blank");
  }

  /**
   * Форматировать число как валюту
   */
  formatCurrency(value) {
    return new Intl.NumberFormat("ru-RU", {
      style: "currency",
      currency: "RUB",
    }).format(value);
  }

  /**
   * Форматировать число как процент
   */
  formatPercent(value) {
    return new Intl.NumberFormat("ru-RU", {
      style: "percent",
      minimumFractionDigits: 2,
    }).format(value / 100);
  }
}

// Инициализация при загрузке страницы
document.addEventListener("DOMContentLoaded", function () {
  window.marginDashboard = new MarginDashboard();

  // Автоматическое обновление при изменении фильтров
  const filterForm = document.getElementById("filter-form");
  if (filterForm) {
    filterForm.addEventListener("submit", function (e) {
      e.preventDefault();

      const formData = new FormData(filterForm);
      const startDate = formData.get("start_date");
      const endDate = formData.get("end_date");
      const clientId = formData.get("client_id") || null;

      window.marginDashboard.updateDashboard(startDate, endDate, clientId);
    });
  }
});

// Экспорт для использования в других скриптах
if (typeof module !== "undefined" && module.exports) {
  module.exports = MarginDashboard;
}
