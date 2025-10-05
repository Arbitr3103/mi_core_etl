/**
 * OzonDemographics - Класс для отображения демографических данных Ozon
 */
class OzonDemographics {
  constructor(containerId) {
    this.containerId = containerId;
    this.container = document.getElementById(containerId);
    this.data = [];
  }

  /**
   * Инициализация компонента
   */
  init() {
    if (!this.container) {
      console.error("Demographics container not found:", this.containerId);
      return;
    }

    // Создаем базовую структуру
    this.container.innerHTML = `
            <div class="row">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5>По возрасту</h5>
                        </div>
                        <div class="card-body" id="${this.containerId}_age">
                            <div class="text-center text-muted">Нет данных</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5>По полу</h5>
                        </div>
                        <div class="card-body" id="${this.containerId}_gender">
                            <div class="text-center text-muted">Нет данных</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5>По регионам</h5>
                        </div>
                        <div class="card-body" id="${this.containerId}_region">
                            <div class="text-center text-muted">Нет данных</div>
                        </div>
                    </div>
                </div>
            </div>
        `;

    console.log("OzonDemographics initialized");
  }

  /**
   * Обновление данных
   */
  updateData(data) {
    if (!data || !Array.isArray(data) || data.length === 0) {
      this.showNoData();
      return;
    }

    this.data = data;

    // Группируем данные
    const ageGroups = this.groupByField(data, "age_group");
    const genderGroups = this.groupByField(data, "gender");
    const regionGroups = this.groupByField(data, "region");

    // Обновляем каждую секцию
    this.updateAgeChart(ageGroups);
    this.updateGenderChart(genderGroups);
    this.updateRegionChart(regionGroups);

    console.log("Demographics data updated");
  }

  /**
   * Группировка данных по полю
   */
  groupByField(data, field) {
    const groups = {};

    data.forEach((item) => {
      const key = item[field] || "Не указано";
      if (!groups[key]) {
        groups[key] = {
          orders_count: 0,
          revenue: 0,
        };
      }
      groups[key].orders_count += item.orders_count || 0;
      groups[key].revenue += item.revenue || 0;
    });

    return groups;
  }

  /**
   * Обновление графика по возрасту
   */
  updateAgeChart(ageGroups) {
    const container = document.getElementById(this.containerId + "_age");
    if (!container) return;

    if (Object.keys(ageGroups).length === 0) {
      container.innerHTML =
        '<div class="text-center text-muted">Нет данных</div>';
      return;
    }

    let html = '<div class="demographics-list">';

    // Сортируем по количеству заказов
    const sortedGroups = Object.entries(ageGroups).sort(
      ([, a], [, b]) => b.orders_count - a.orders_count
    );

    sortedGroups.forEach(([ageGroup, stats]) => {
      const percentage = this.calculatePercentage(
        stats.orders_count,
        sortedGroups
      );

      html += `
                <div class="demographics-item mb-2">
                    <div class="d-flex justify-content-between">
                        <span>${ageGroup}</span>
                        <span>${stats.orders_count} заказов</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-primary" style="width: ${percentage}%"></div>
                    </div>
                    <small class="text-muted">${stats.revenue.toLocaleString()} ₽</small>
                </div>
            `;
    });

    html += "</div>";
    container.innerHTML = html;
  }

  /**
   * Обновление графика по полу
   */
  updateGenderChart(genderGroups) {
    const container = document.getElementById(this.containerId + "_gender");
    if (!container) return;

    if (Object.keys(genderGroups).length === 0) {
      container.innerHTML =
        '<div class="text-center text-muted">Нет данных</div>';
      return;
    }

    let html = '<div class="demographics-list">';

    // Сортируем по количеству заказов
    const sortedGroups = Object.entries(genderGroups).sort(
      ([, a], [, b]) => b.orders_count - a.orders_count
    );

    const colors = ["bg-info", "bg-warning", "bg-success"];

    sortedGroups.forEach(([gender, stats], index) => {
      const percentage = this.calculatePercentage(
        stats.orders_count,
        sortedGroups
      );
      const colorClass = colors[index % colors.length];

      const genderName =
        gender === "male"
          ? "Мужчины"
          : gender === "female"
          ? "Женщины"
          : gender;

      html += `
                <div class="demographics-item mb-2">
                    <div class="d-flex justify-content-between">
                        <span>${genderName}</span>
                        <span>${stats.orders_count} заказов</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar ${colorClass}" style="width: ${percentage}%"></div>
                    </div>
                    <small class="text-muted">${stats.revenue.toLocaleString()} ₽</small>
                </div>
            `;
    });

    html += "</div>";
    container.innerHTML = html;
  }

  /**
   * Обновление графика по регионам
   */
  updateRegionChart(regionGroups) {
    const container = document.getElementById(this.containerId + "_region");
    if (!container) return;

    if (Object.keys(regionGroups).length === 0) {
      container.innerHTML =
        '<div class="text-center text-muted">Нет данных</div>';
      return;
    }

    let html = '<div class="demographics-list">';

    // Сортируем по количеству заказов и берем топ-5
    const sortedGroups = Object.entries(regionGroups)
      .sort(([, a], [, b]) => b.orders_count - a.orders_count)
      .slice(0, 5);

    sortedGroups.forEach(([region, stats]) => {
      const percentage = this.calculatePercentage(
        stats.orders_count,
        sortedGroups
      );

      html += `
                <div class="demographics-item mb-2">
                    <div class="d-flex justify-content-between">
                        <span>${region}</span>
                        <span>${stats.orders_count}</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" style="width: ${percentage}%"></div>
                    </div>
                    <small class="text-muted">${stats.revenue.toLocaleString()} ₽</small>
                </div>
            `;
    });

    html += "</div>";
    container.innerHTML = html;
  }

  /**
   * Расчет процентного соотношения
   */
  calculatePercentage(value, allGroups) {
    const total = allGroups.reduce(
      (sum, [, stats]) => sum + stats.orders_count,
      0
    );
    return total > 0 ? Math.round((value / total) * 100) : 0;
  }

  /**
   * Показать сообщение об отсутствии данных
   */
  showNoData() {
    if (!this.container) return;

    const ageContainer = document.getElementById(this.containerId + "_age");
    const genderContainer = document.getElementById(
      this.containerId + "_gender"
    );
    const regionContainer = document.getElementById(
      this.containerId + "_region"
    );

    if (ageContainer) {
      ageContainer.innerHTML =
        '<div class="text-center text-muted">Нет данных</div>';
    }
    if (genderContainer) {
      genderContainer.innerHTML =
        '<div class="text-center text-muted">Нет данных</div>';
    }
    if (regionContainer) {
      regionContainer.innerHTML =
        '<div class="text-center text-muted">Нет данных</div>';
    }
  }

  /**
   * Показать ошибку
   */
  showError(message) {
    if (!this.container) return;

    this.container.innerHTML = `
            <div class="alert alert-warning text-center">
                <h5>⚠️ Демографические данные недоступны</h5>
                <p>${message}</p>
            </div>
        `;
  }
}

// Экспортируем класс для использования
window.OzonDemographics = OzonDemographics;
