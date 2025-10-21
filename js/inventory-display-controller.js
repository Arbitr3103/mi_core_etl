/**
 * Контроллер для управления отображением полных списков товаров в дашборде
 * Поддерживает переключение между режимами "Показать все" и "Топ-10"
 */
class InventoryDisplayController {
  constructor() {
    this.currentMode =
      localStorage.getItem("inventory-display-mode") || "top10";
    this.apiBaseUrl = "../api/inventory-analytics.php";
    this.loadingStates = {
      dashboard: false,
      critical: false,
      lowStock: false,
      overstock: false,
    };

    this.initializeControls();
    console.log(
      "InventoryDisplayController initialized with mode:",
      this.currentMode
    );
  }

  /**
   * Инициализация элементов управления отображением
   */
  initializeControls() {
    // Создаем контролы после загрузки DOM
    document.addEventListener("DOMContentLoaded", () => {
      this.createDisplayControls();
      this.attachEventListeners();
    });
  }

  /**
   * Создание элементов управления отображением для каждой секции
   */
  createDisplayControls() {
    const sections = [
      {
        id: "critical-products",
        title: "Критические товары",
        category: "critical",
      },
      {
        id: "low-stock-products",
        title: "Низкие остатки",
        category: "low_stock",
      },
      {
        id: "overstock-products",
        title: "Избыток товаров",
        category: "overstock",
      },
    ];

    sections.forEach((section) => {
      this.createSectionControls(section);
    });
  }

  /**
   * Создание контролов для конкретной секции
   */
  createSectionControls(section) {
    const sectionElement = document.querySelector(
      `[data-section="${section.id}"]`
    );
    if (!sectionElement) {
      console.warn(`Section ${section.id} not found, will create on data load`);
      return;
    }

    const header = sectionElement.querySelector("h3");
    if (!header) return;

    // Создаем контейнер для контролов
    const controlsContainer = document.createElement("div");
    controlsContainer.className = "display-controls";
    controlsContainer.innerHTML = `
            <div class="controls-wrapper">
                <span class="item-counter" data-category="${section.category}">
                    Загрузка...
                </span>
                <div class="toggle-switch">
                    <button class="toggle-btn ${
                      this.currentMode === "top10" ? "active" : ""
                    }" 
                            data-mode="top10" data-category="${
                              section.category
                            }">
                        Топ-10
                    </button>
                    <button class="toggle-btn ${
                      this.currentMode === "all" ? "active" : ""
                    }" 
                            data-mode="all" data-category="${section.category}">
                        Показать все
                    </button>
                </div>
            </div>
        `;

    // Вставляем контролы после заголовка
    header.parentNode.insertBefore(controlsContainer, header.nextSibling);
  }

  /**
   * Подключение обработчиков событий
   */
  attachEventListeners() {
    document.addEventListener("click", (e) => {
      if (e.target.classList.contains("toggle-btn")) {
        const mode = e.target.dataset.mode;
        const category = e.target.dataset.category;
        this.switchMode(mode, category);
      }
    });
  }

  /**
   * Переключение режима отображения
   */
  async switchMode(mode, category = null) {
    console.log(
      `Switching mode to: ${mode} for category: ${category || "all"}`
    );

    this.currentMode = mode;
    localStorage.setItem("inventory-display-mode", mode);

    // Обновляем активные кнопки
    this.updateActiveButtons(mode, category);

    // Показываем индикатор загрузки
    this.showLoadingIndicator(category);

    try {
      // Перезагружаем данные с новыми параметрами
      if (category) {
        await this.loadCategoryData(category);
      } else {
        await this.loadAllData();
      }
    } catch (error) {
      console.error("Error switching mode:", error);
      this.showError(`Ошибка переключения режима: ${error.message}`);
    }
  }

  /**
   * Обновление активных кнопок
   */
  updateActiveButtons(mode, category) {
    const selector = category
      ? `[data-category="${category}"] .toggle-btn`
      : ".toggle-btn";

    document.querySelectorAll(selector).forEach((btn) => {
      btn.classList.toggle("active", btn.dataset.mode === mode);
    });
  }

  /**
   * Показ индикатора загрузки
   */
  showLoadingIndicator(category) {
    if (category) {
      this.loadingStates[category] = true;
      const counter = document.querySelector(
        `[data-category="${category}"] .item-counter`
      );
      if (counter) {
        counter.textContent = "Загрузка...";
      }
    } else {
      Object.keys(this.loadingStates).forEach((key) => {
        this.loadingStates[key] = true;
      });
    }
  }

  /**
   * Загрузка данных для всех категорий
   */
  async loadAllData() {
    const limit = this.currentMode === "all" ? "all" : "10";

    try {
      const response = await fetch(
        `${this.apiBaseUrl}?action=dashboard&limit=${limit}`
      );
      const data = await response.json();

      if (data.status === "success") {
        this.renderAllProducts(data.data);
        this.updateAllCounters(data.data);
      } else {
        throw new Error(data.message || "Ошибка загрузки данных");
      }
    } catch (error) {
      console.error("Error loading all data:", error);
      this.showError("Не удалось загрузить данные");
    }
  }

  /**
   * Загрузка данных для конкретной категории
   */
  async loadCategoryData(category) {
    const limit = this.currentMode === "all" ? "all" : "10";
    let action = "dashboard";

    // Определяем нужный API endpoint
    switch (category) {
      case "critical":
        action = "critical-products";
        break;
      case "low_stock":
        action = "low-stock-products";
        break;
      case "overstock":
        action = "overstock-products";
        break;
    }

    try {
      const response = await fetch(
        `${this.apiBaseUrl}?action=${action}&limit=${limit}`
      );
      const data = await response.json();

      if (data.status === "success") {
        this.renderCategoryProducts(category, data.data);
        this.updateCategoryCounter(category, data.data);
      } else {
        throw new Error(data.message || "Ошибка загрузки данных");
      }
    } catch (error) {
      console.error(`Error loading ${category} data:`, error);
      this.showError(`Не удалось загрузить данные для категории ${category}`);
    }
  }

  /**
   * Отображение всех товаров
   */
  renderAllProducts(data) {
    this.renderCategoryProducts("critical", data.critical_products);
    this.renderCategoryProducts("low_stock", data.low_stock_products);
    this.renderCategoryProducts("overstock", data.overstock_products);
  }

  /**
   * Отображение товаров конкретной категории
   */
  renderCategoryProducts(category, products) {
    const container = document.querySelector(
      `[data-category="${category}"] .products-container`
    );
    if (!container) {
      console.warn(`Container for category ${category} not found`);
      return;
    }

    // Определяем данные товаров
    const items = products.items || products || [];

    if (items.length === 0) {
      container.innerHTML = '<div class="no-data">Товары не найдены</div>';
      return;
    }

    // Применяем компактный режим если показываем все товары
    const isCompactMode = this.currentMode === "all";
    container.className = `products-container ${
      isCompactMode ? "compact-mode" : ""
    }`;

    // Генерируем HTML для товаров
    container.innerHTML = items
      .map((product) =>
        this.createProductHTML(product, category, isCompactMode)
      )
      .join("");

    this.loadingStates[category] = false;
  }

  /**
   * Создание HTML для товара
   */
  createProductHTML(product, category, isCompact) {
    const statusClass = this.getStatusClass(category);
    const compactClass = isCompact ? "compact" : "";

    return `
            <div class="product-item ${statusClass} ${compactClass}">
                <div class="product-sku">${product.sku}</div>
                <div class="product-name" title="${
                  product.name || "Товар " + product.sku
                }">
                    ${product.name || "Товар " + product.sku}
                </div>
                <div class="product-stock ${statusClass}">${
      product.stock
    } шт</div>
                <div class="product-warehouse">${
                  product.warehouse || "Не указан"
                }</div>
                ${
                  !isCompact
                    ? `
                    <div class="product-details">
                        ${
                          product.available_stock !== undefined
                            ? `Доступно: ${product.available_stock} | `
                            : ""
                        }
                        ${
                          product.reserved_stock !== undefined
                            ? `Резерв: ${product.reserved_stock}`
                            : ""
                        }
                        ${
                          product.excess_stock !== undefined
                            ? `Избыток: ${product.excess_stock}`
                            : ""
                        }
                    </div>
                `
                    : ""
                }
            </div>
        `;
  }

  /**
   * Получение CSS класса для статуса
   */
  getStatusClass(category) {
    switch (category) {
      case "critical":
        return "critical";
      case "low_stock":
        return "low";
      case "overstock":
        return "overstock";
      default:
        return "normal";
    }
  }

  /**
   * Обновление всех счетчиков
   */
  updateAllCounters(data) {
    this.updateCategoryCounter("critical", data.critical_products);
    this.updateCategoryCounter("low_stock", data.low_stock_products);
    this.updateCategoryCounter("overstock", data.overstock_products);
  }

  /**
   * Обновление счетчика для категории
   */
  updateCategoryCounter(category, products) {
    const counter = document.querySelector(
      `[data-category="${category}"] .item-counter`
    );
    if (!counter) return;

    const totalCount =
      products.count ||
      (products.items ? products.items.length : products.length);
    const displayedCount = products.items
      ? products.items.length
      : products.length;

    if (this.currentMode === "all") {
      counter.textContent = `Показано ${totalCount} из ${totalCount} товаров`;
    } else {
      counter.textContent = `Показано ${displayedCount} из ${totalCount} товаров`;
    }

    this.loadingStates[category] = false;
  }

  /**
   * Показ ошибки
   */
  showError(message) {
    const notification = document.createElement("div");
    notification.className = "error-notification";
    notification.style.cssText = `
            position: fixed; top: 20px; right: 20px; z-index: 1000;
            background: #f8d7da; color: #721c24; padding: 12px 20px;
            border: 1px solid #f5c6cb; border-radius: 5px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            max-width: 400px;
        `;
    notification.innerHTML = `
            <strong>Ошибка:</strong> ${message}
            <button onclick="this.parentElement.remove()" 
                    style="float: right; background: none; border: none; font-size: 18px; cursor: pointer; margin-left: 10px;">×</button>
        `;

    document.body.appendChild(notification);

    setTimeout(() => {
      if (notification.parentElement) {
        notification.remove();
      }
    }, 5000);
  }

  /**
   * Получение текущего режима отображения
   */
  getCurrentMode() {
    return this.currentMode;
  }

  /**
   * Принудительная загрузка данных
   */
  async refresh() {
    console.log("Refreshing inventory display...");
    await this.loadAllData();
  }

  /**
   * Проверка состояния загрузки
   */
  isLoading(category = null) {
    if (category) {
      return this.loadingStates[category] || false;
    }
    return Object.values(this.loadingStates).some((state) => state);
  }
}

// Экспорт для использования в других модулях
if (typeof module !== "undefined" && module.exports) {
  module.exports = InventoryDisplayController;
}
