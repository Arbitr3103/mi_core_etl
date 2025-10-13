/**
 * Оптимизированный JavaScript для дашборда складских остатков
 * Включает ленивую загрузку, кэширование и оптимизацию производительности
 * Создано для задачи 7: Оптимизировать производительность
 */

class InventoryDashboardOptimized {
  constructor() {
    this.cache = new Map();
    this.loadingStates = new Map();
    this.refreshIntervals = new Map();
    this.config = {
      cacheTimeout: 5 * 60 * 1000, // 5 минут
      refreshInterval: 30 * 1000, // 30 секунд
      maxRetries: 3,
      retryDelay: 1000,
    };

    this.init();
  }

  init() {
    this.setupEventListeners();
    this.loadInitialData();
    this.setupAutoRefresh();
    this.setupVisibilityChangeHandler();
  }

  /**
   * Настройка обработчиков событий
   */
  setupEventListeners() {
    // Обработчик для кнопки обновления
    const refreshBtn = document.getElementById("refreshData");
    if (refreshBtn) {
      refreshBtn.addEventListener("click", () => this.forceRefresh());
    }

    // Обработчики для модальных окон
    document.addEventListener("click", (e) => {
      if (e.target.matches("[data-action]")) {
        this.handleActionClick(e.target);
      }
    });

    // Обработчик для закрытия модальных окон
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        this.closeAllModals();
      }
    });
  }

  /**
   * Загрузка начальных данных с приоритизацией
   */
  async loadInitialData() {
    try {
      this.showGlobalLoading(true);

      // Загружаем критически важные данные сначала
      const criticalData = await this.loadDashboardData();
      if (criticalData) {
        this.updateDashboardUI(criticalData);
      }

      // Затем загружаем дополнительные данные в фоне
      this.loadSecondaryData();
    } catch (error) {
      console.error("Ошибка загрузки начальных данных:", error);
      this.showError("Не удалось загрузить данные дашборда");
    } finally {
      this.showGlobalLoading(false);
    }
  }

  /**
   * Загрузка вторичных данных в фоне
   */
  async loadSecondaryData() {
    const secondaryTasks = [
      this.loadWarehouseSummary(),
      this.loadRecommendations(),
    ];

    // Загружаем параллельно, но не блокируем UI
    Promise.allSettled(secondaryTasks).then((results) => {
      results.forEach((result, index) => {
        if (result.status === "rejected") {
          console.warn(
            `Вторичная задача ${index} не выполнена:`,
            result.reason
          );
        }
      });
    });
  }

  /**
   * Кэшированная загрузка данных дашборда
   */
  async loadDashboardData() {
    const cacheKey = "dashboard_data";

    // Проверяем кэш
    const cached = this.getFromCache(cacheKey);
    if (cached) {
      return cached;
    }

    // Проверяем, не загружаем ли уже эти данные
    if (this.loadingStates.get(cacheKey)) {
      return this.loadingStates.get(cacheKey);
    }

    // Создаем промис загрузки
    const loadingPromise = this.fetchWithRetry(
      "../api/inventory-analytics.php?action=dashboard"
    )
      .then((response) => response.json())
      .then((data) => {
        if (data.status === "success") {
          this.setCache(cacheKey, data);
          return data;
        }
        throw new Error(data.message || "Ошибка загрузки данных");
      })
      .finally(() => {
        this.loadingStates.delete(cacheKey);
      });

    this.loadingStates.set(cacheKey, loadingPromise);
    return loadingPromise;
  }

  /**
   * Загрузка сводки по складам
   */
  async loadWarehouseSummary() {
    const cacheKey = "warehouse_summary";

    const cached = this.getFromCache(cacheKey);
    if (cached) {
      this.updateWarehouseSummaryUI(cached);
      return cached;
    }

    try {
      const response = await this.fetchWithRetry(
        "../api/inventory-analytics.php?action=warehouse-summary"
      );
      const data = await response.json();

      if (data.status === "success") {
        this.setCache(cacheKey, data);
        this.updateWarehouseSummaryUI(data);
        return data;
      }
    } catch (error) {
      console.error("Ошибка загрузки данных складов:", error);
    }
  }

  /**
   * Загрузка рекомендаций
   */
  async loadRecommendations() {
    const cacheKey = "recommendations";

    const cached = this.getFromCache(cacheKey);
    if (cached) {
      this.updateRecommendationsUI(cached);
      return cached;
    }

    try {
      const response = await this.fetchWithRetry(
        "../api/inventory-analytics.php?action=recommendations"
      );
      const data = await response.json();

      if (data.status === "success") {
        this.setCache(cacheKey, data);
        this.updateRecommendationsUI(data);
        return data;
      }
    } catch (error) {
      console.error("Ошибка загрузки рекомендаций:", error);
    }
  }

  /**
   * Fetch с повторными попытками
   */
  async fetchWithRetry(url, options = {}, retries = this.config.maxRetries) {
    try {
      const response = await fetch(url, {
        ...options,
        headers: {
          "Cache-Control": "no-cache",
          ...options.headers,
        },
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      return response;
    } catch (error) {
      if (retries > 0) {
        console.warn(
          `Повторная попытка загрузки ${url}, осталось попыток: ${retries - 1}`
        );
        await this.delay(this.config.retryDelay);
        return this.fetchWithRetry(url, options, retries - 1);
      }
      throw error;
    }
  }

  /**
   * Обновление UI дашборда
   */
  updateDashboardUI(data) {
    if (!data.data) return;

    const dashboardData = data.data;

    // Обновляем KPI карточки
    this.updateKPICards(dashboardData);

    // Обновляем списки товаров
    this.updateProductLists(dashboardData);

    // Обновляем время последнего обновления
    this.updateLastRefreshTime();

    // Показываем предупреждения если есть
    if (data.metadata && data.metadata.warnings) {
      this.showWarnings(data.metadata.warnings);
    }
  }

  /**
   * Обновление KPI карточек
   */
  updateKPICards(data) {
    const updates = [
      { id: "criticalCount", value: data.critical_stock_count || 0 },
      { id: "lowStockCount", value: data.low_stock_count || 0 },
      { id: "overstockCount", value: data.overstock_count || 0 },
      {
        id: "totalValue",
        value: this.formatCurrency(data.total_inventory_value || 0),
      },
    ];

    updates.forEach((update) => {
      const element = document.getElementById(update.id);
      if (element) {
        this.animateValueChange(element, update.value);
      }
    });
  }

  /**
   * Анимированное обновление значений
   */
  animateValueChange(element, newValue) {
    const currentValue = element.textContent;
    if (currentValue !== newValue.toString()) {
      element.style.transition = "all 0.3s ease";
      element.style.transform = "scale(1.1)";
      element.textContent = newValue;

      setTimeout(() => {
        element.style.transform = "scale(1)";
      }, 300);
    }
  }

  /**
   * Обновление списков товаров с виртуализацией
   */
  updateProductLists(data) {
    const lists = [
      {
        containerId: "criticalProductsList",
        products: data.critical_products || [],
        type: "critical",
      },
      {
        containerId: "lowStockProductsList",
        products: data.low_stock_products || [],
        type: "low",
      },
      {
        containerId: "overstockProductsList",
        products: data.overstock_products || [],
        type: "overstock",
      },
    ];

    lists.forEach((list) => {
      this.renderProductList(list.containerId, list.products, list.type);
    });
  }

  /**
   * Рендеринг списка товаров с оптимизацией
   */
  renderProductList(containerId, products, type) {
    const container = document.getElementById(containerId);
    if (!container) return;

    // Очищаем контейнер
    container.innerHTML = "";

    if (products.length === 0) {
      container.innerHTML = '<div class="no-products">Товары не найдены</div>';
      return;
    }

    // Создаем фрагмент для оптимизации DOM операций
    const fragment = document.createDocumentFragment();

    // Ограничиваем количество отображаемых товаров для производительности
    const maxItems = 10;
    const itemsToShow = products.slice(0, maxItems);

    itemsToShow.forEach((product) => {
      const productElement = this.createProductElement(product, type);
      fragment.appendChild(productElement);
    });

    // Если товаров больше, добавляем кнопку "Показать еще"
    if (products.length > maxItems) {
      const showMoreBtn = this.createShowMoreButton(
        products.length - maxItems,
        type
      );
      fragment.appendChild(showMoreBtn);
    }

    container.appendChild(fragment);
  }

  /**
   * Создание элемента товара
   */
  createProductElement(product, type) {
    const div = document.createElement("div");
    div.className = `product-item ${type}`;

    const stockClass = this.getStockStatusClass(product.stock);
    const urgencyIcon = this.getUrgencyIcon(type);

    div.innerHTML = `
            <div class="product-info">
                <div class="product-name" title="${product.name}">
                    ${urgencyIcon} ${this.truncateText(product.name, 40)}
                </div>
                <div class="product-details">
                    <span class="sku">SKU: ${product.sku}</span>
                    <span class="warehouse">📍 ${product.warehouse}</span>
                </div>
            </div>
            <div class="product-stock">
                <span class="stock-value ${stockClass}">${product.stock}</span>
                <span class="stock-label">шт.</span>
            </div>
        `;

    return div;
  }

  /**
   * Настройка автообновления
   */
  setupAutoRefresh() {
    // Автообновление основных данных
    const mainRefreshInterval = setInterval(() => {
      if (document.visibilityState === "visible") {
        this.refreshDashboardData();
      }
    }, this.config.refreshInterval);

    this.refreshIntervals.set("main", mainRefreshInterval);

    // Менее частое обновление рекомендаций
    const recommendationsInterval = setInterval(() => {
      if (document.visibilityState === "visible") {
        this.refreshRecommendations();
      }
    }, this.config.refreshInterval * 4); // Каждые 2 минуты

    this.refreshIntervals.set("recommendations", recommendationsInterval);
  }

  /**
   * Обработчик изменения видимости страницы
   */
  setupVisibilityChangeHandler() {
    document.addEventListener("visibilitychange", () => {
      if (document.visibilityState === "visible") {
        // Страница стала видимой - обновляем данные
        this.refreshDashboardData();
      }
    });
  }

  /**
   * Принудительное обновление всех данных
   */
  async forceRefresh() {
    this.clearCache();
    this.showGlobalLoading(true);

    try {
      await this.loadInitialData();
      this.showSuccessMessage("Данные обновлены");
    } catch (error) {
      this.showError("Ошибка обновления данных");
    } finally {
      this.showGlobalLoading(false);
    }
  }

  /**
   * Тихое обновление данных дашборда
   */
  async refreshDashboardData() {
    try {
      const cacheKey = "dashboard_data";
      this.cache.delete(cacheKey); // Удаляем из кэша

      const data = await this.loadDashboardData();
      if (data) {
        this.updateDashboardUI(data);
      }
    } catch (error) {
      console.error("Ошибка тихого обновления:", error);
    }
  }

  /**
   * Обновление рекомендаций
   */
  async refreshRecommendations() {
    try {
      const cacheKey = "recommendations";
      this.cache.delete(cacheKey);
      await this.loadRecommendations();
    } catch (error) {
      console.error("Ошибка обновления рекомендаций:", error);
    }
  }

  /**
   * Работа с кэшем
   */
  getFromCache(key) {
    const cached = this.cache.get(key);
    if (cached && Date.now() - cached.timestamp < this.config.cacheTimeout) {
      return cached.data;
    }
    this.cache.delete(key);
    return null;
  }

  setCache(key, data) {
    this.cache.set(key, {
      data: data,
      timestamp: Date.now(),
    });
  }

  clearCache() {
    this.cache.clear();
  }

  /**
   * Утилиты UI
   */
  showGlobalLoading(show) {
    const loader = document.getElementById("globalLoader");
    if (loader) {
      loader.style.display = show ? "flex" : "none";
    }
  }

  showError(message) {
    this.showNotification(message, "error");
  }

  showSuccessMessage(message) {
    this.showNotification(message, "success");
  }

  showNotification(message, type = "info") {
    // Создаем уведомление
    const notification = document.createElement("div");
    notification.className = `notification ${type}`;
    notification.textContent = message;

    // Добавляем в контейнер уведомлений
    let container = document.getElementById("notificationContainer");
    if (!container) {
      container = document.createElement("div");
      container.id = "notificationContainer";
      container.className = "notification-container";
      document.body.appendChild(container);
    }

    container.appendChild(notification);

    // Автоматически удаляем через 5 секунд
    setTimeout(() => {
      if (notification.parentNode) {
        notification.parentNode.removeChild(notification);
      }
    }, 5000);
  }

  /**
   * Утилиты форматирования
   */
  formatCurrency(value) {
    return new Intl.NumberFormat("ru-RU", {
      style: "currency",
      currency: "RUB",
      minimumFractionDigits: 0,
    }).format(value);
  }

  truncateText(text, maxLength) {
    return text.length > maxLength
      ? text.substring(0, maxLength) + "..."
      : text;
  }

  getStockStatusClass(stock) {
    if (stock <= 5) return "critical";
    if (stock <= 20) return "low";
    if (stock > 100) return "overstock";
    return "normal";
  }

  getUrgencyIcon(type) {
    const icons = {
      critical: "🚨",
      low: "⚠️",
      overstock: "📦",
    };
    return icons[type] || "📋";
  }

  delay(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  updateLastRefreshTime() {
    const element = document.getElementById("lastRefreshTime");
    if (element) {
      element.textContent = new Date().toLocaleTimeString("ru-RU");
    }
  }

  /**
   * Очистка ресурсов
   */
  destroy() {
    // Очищаем интервалы
    this.refreshIntervals.forEach((interval) => clearInterval(interval));
    this.refreshIntervals.clear();

    // Очищаем кэш
    this.clearCache();

    // Очищаем состояния загрузки
    this.loadingStates.clear();
  }
}

// Инициализация при загрузке страницы
document.addEventListener("DOMContentLoaded", () => {
  window.inventoryDashboard = new InventoryDashboardOptimized();
});

// Очистка при выгрузке страницы
window.addEventListener("beforeunload", () => {
  if (window.inventoryDashboard) {
    window.inventoryDashboard.destroy();
  }
});
