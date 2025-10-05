/**
 * OzonFunnelChart - Класс для отображения воронки продаж Ozon
 */
class OzonFunnelChart {
  constructor(containerId) {
    this.containerId = containerId;
    this.container = document.getElementById(containerId);
    this.chart = null;
    this.data = [];
  }

  /**
   * Инициализация графика
   */
  init() {
    if (!this.container) {
      console.error("Container not found:", this.containerId);
      return;
    }

    // Создаем canvas для Chart.js
    this.container.innerHTML =
      '<canvas id="' + this.containerId + '_canvas"></canvas>';

    const canvas = document.getElementById(this.containerId + "_canvas");
    const ctx = canvas.getContext("2d");

    // Инициализируем Chart.js
    this.chart = new Chart(ctx, {
      type: "bar",
      data: {
        labels: [],
        datasets: [
          {
            label: "Воронка продаж",
            data: [],
            backgroundColor: [
              "rgba(54, 162, 235, 0.8)",
              "rgba(255, 206, 86, 0.8)",
              "rgba(75, 192, 192, 0.8)",
            ],
            borderColor: [
              "rgba(54, 162, 235, 1)",
              "rgba(255, 206, 86, 1)",
              "rgba(75, 192, 192, 1)",
            ],
            borderWidth: 1,
          },
        ],
      },
      options: {
        responsive: true,
        plugins: {
          title: {
            display: true,
            text: "Воронка продаж Ozon",
          },
          legend: {
            display: false,
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

    console.log("OzonFunnelChart initialized");
  }

  /**
   * Обновление данных графика
   */
  updateData(data) {
    if (!this.chart || !data || !Array.isArray(data)) {
      console.error("Chart not initialized or invalid data");
      return;
    }

    this.data = data;

    // Агрегируем данные
    let totalViews = 0;
    let totalCartAdditions = 0;
    let totalOrders = 0;
    let totalRevenue = 0;

    data.forEach((item) => {
      totalViews += item.views || 0;
      totalCartAdditions += item.cart_additions || 0;
      totalOrders += item.orders || 0;
      totalRevenue += item.revenue || 0;
    });

    // Обновляем график
    this.chart.data.labels = ["Просмотры", "В корзину", "Заказы"];
    this.chart.data.datasets[0].data = [
      totalViews,
      totalCartAdditions,
      totalOrders,
    ];

    this.chart.update();

    // Обновляем статистику
    this.updateStats({
      views: totalViews,
      cart_additions: totalCartAdditions,
      orders: totalOrders,
      revenue: totalRevenue,
    });

    console.log("OzonFunnelChart data updated:", {
      views: totalViews,
      cart_additions: totalCartAdditions,
      orders: totalOrders,
      revenue: totalRevenue,
    });
  }

  /**
   * Обновление статистики
   */
  updateStats(stats) {
    // Обновляем метрики конверсии напрямую через классы
    const conversionRates = document.querySelectorAll(".conversion-rate");
    if (conversionRates.length < 3) return;

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

    // Обновляем метрики конверсии в существующих элементах
    conversionRates[0].textContent = conversionViewToCart + "%";
    conversionRates[1].textContent = conversionCartToOrder + "%";
    conversionRates[2].textContent = conversionOverall + "%";

    console.log("Conversion rates updated:", {
      viewToCart: conversionViewToCart + "%",
      cartToOrder: conversionCartToOrder + "%",
      overall: conversionOverall + "%",
    });
  }

  /**
   * Показать сообщение об отсутствии данных
   */
  showNoData() {
    if (!this.container) return;

    this.container.innerHTML = `
            <div class="alert alert-info text-center">
                <h4>📊 Нет данных для отображения</h4>
                <p>Выберите другой период или проверьте подключение к API Ozon</p>
            </div>
        `;
  }

  /**
   * Показать ошибку
   */
  showError(message) {
    if (!this.container) return;

    this.container.innerHTML = `
            <div class="alert alert-danger text-center">
                <h4>❌ Ошибка загрузки данных</h4>
                <p>${message}</p>
            </div>
        `;
  }
}

// Экспортируем класс для использования
window.OzonFunnelChart = OzonFunnelChart;
