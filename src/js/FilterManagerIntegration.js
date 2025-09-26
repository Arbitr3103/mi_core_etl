/**
 * FilterManagerIntegration - Пример интеграции FilterManager с существующими элементами формы
 *
 * Показывает, как подключить новый FilterManager к существующим HTML элементам
 * фильтров и обеспечить автоматическую загрузку стран при изменении марки/модели.
 *
 * @version 1.0
 * @author ZUZ System
 */

class FilterManagerIntegration {
  /**
   * Конструктор интеграции
   *
   * @param {Object} options - Опции конфигурации
   * @param {string} options.brandSelectId - ID элемента выбора марки
   * @param {string} options.modelSelectId - ID элемента выбора модели
   * @param {string} options.yearSelectId - ID элемента выбора года
   * @param {string} options.countryContainerId - ID контейнера для фильтра по стране
   * @param {string} options.resetButtonId - ID кнопки сброса фильтров
   * @param {string} options.resultsContainerId - ID контейнера для результатов
   * @param {string} options.apiBaseUrl - Базовый URL для API
   */
  constructor(options = {}) {
    this.options = {
      brandSelectId: "brand-select",
      modelSelectId: "model-select",
      yearSelectId: "year-select",
      countryContainerId: "country-filter-container",
      resetButtonId: "reset-filters",
      resultsContainerId: "products-list",
      apiBaseUrl: "http://178.72.129.61/api",
      ...options,
    };

    // Создаем экземпляр FilterManager
    this.filterManager = new FilterManager({
      apiBaseUrl: this.options.apiBaseUrl,
      onFiltersChange: (result, filters) =>
        this.handleFiltersChange(result, filters),
    });

    // DOM элементы
    this.elements = {};

    // Флаг инициализации
    this.isInitialized = false;

    this.init();
  }

  /**
   * Инициализация интеграции
   */
  init() {
    // Ждем загрузки DOM
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", () =>
        this.initializeElements()
      );
    } else {
      this.initializeElements();
    }
  }

  /**
   * Инициализация DOM элементов и обработчиков событий
   */
  initializeElements() {
    try {
      // Получаем ссылки на DOM элементы
      this.elements = {
        brandSelect: document.getElementById(this.options.brandSelectId),
        modelSelect: document.getElementById(this.options.modelSelectId),
        yearSelect: document.getElementById(this.options.yearSelectId),
        resetButton: document.getElementById(this.options.resetButtonId),
        resultsContainer: document.getElementById(
          this.options.resultsContainerId
        ),
      };

      // Проверяем наличие обязательных элементов
      this.validateElements();

      // Инициализируем фильтр по стране
      this.filterManager.initCountryFilter(this.options.countryContainerId);

      // Привязываем обработчики событий
      this.bindEventHandlers();

      // Устанавливаем начальные значения из элементов формы
      this.syncInitialValues();

      this.isInitialized = true;
      console.log("FilterManagerIntegration успешно инициализирован");
    } catch (error) {
      console.error("Ошибка инициализации FilterManagerIntegration:", error);
    }
  }

  /**
   * Проверка наличия необходимых DOM элементов
   */
  validateElements() {
    const requiredElements = ["brandSelect", "modelSelect", "yearSelect"];
    const missingElements = [];

    requiredElements.forEach((elementKey) => {
      if (!this.elements[elementKey]) {
        missingElements.push(this.options[elementKey + "Id"]);
      }
    });

    if (missingElements.length > 0) {
      console.warn("Не найдены следующие элементы:", missingElements);
    }
  }

  /**
   * Привязка обработчиков событий к элементам формы
   */
  bindEventHandlers() {
    // Обработчик изменения марки
    if (this.elements.brandSelect) {
      this.elements.brandSelect.addEventListener("change", (e) => {
        const brandId = e.target.value || null;
        this.filterManager.onBrandChange(brandId);

        // Сбрасываем модель при изменении марки
        if (this.elements.modelSelect) {
          this.elements.modelSelect.value = "";
        }
      });
    }

    // Обработчик изменения модели
    if (this.elements.modelSelect) {
      this.elements.modelSelect.addEventListener("change", (e) => {
        const modelId = e.target.value || null;
        this.filterManager.onModelChange(modelId);
      });
    }

    // Обработчик изменения года
    if (this.elements.yearSelect) {
      this.elements.yearSelect.addEventListener("change", (e) => {
        const year = e.target.value || null;
        this.filterManager.onYearChange(year);
      });
    }

    // Обработчик кнопки сброса
    if (this.elements.resetButton) {
      this.elements.resetButton.addEventListener("click", () => {
        this.resetAllFilters();
      });
    }
  }

  /**
   * Синхронизация начальных значений из элементов формы
   */
  syncInitialValues() {
    // Получаем текущие значения из элементов формы
    const initialFilters = {};

    if (this.elements.brandSelect && this.elements.brandSelect.value) {
      initialFilters.brand_id = this.elements.brandSelect.value;
    }

    if (this.elements.modelSelect && this.elements.modelSelect.value) {
      initialFilters.model_id = this.elements.modelSelect.value;
    }

    if (this.elements.yearSelect && this.elements.yearSelect.value) {
      initialFilters.year = this.elements.yearSelect.value;
    }

    // Устанавливаем значения в FilterManager
    Object.keys(initialFilters).forEach((key) => {
      this.filterManager.setFilter(key, initialFilters[key]);
    });
  }

  /**
   * Обработчик изменения фильтров
   *
   * @param {Object} result - Результат фильтрации
   * @param {Object} filters - Текущие фильтры
   */
  handleFiltersChange(result, filters) {
    console.log("Фильтры изменились:", filters);
    console.log("Результат фильтрации:", result);

    // Обновляем отображение результатов
    this.updateResults(result);

    // Обновляем счетчик результатов
    this.updateResultsCounter(result);

    // Можно добавить дополнительную логику обработки
    this.onFiltersChangeCustom(result, filters);
  }

  /**
   * Обновление отображения результатов поиска
   *
   * @param {Object} result - Результат фильтрации
   */
  updateResults(result) {
    if (!this.elements.resultsContainer) return;

    const products = result.data || [];

    if (products.length === 0) {
      this.elements.resultsContainer.innerHTML = `
        <div class="no-results">
          <p>По выбранным критериям товары не найдены</p>
          <button onclick="filterIntegration.resetAllFilters()" class="btn btn-secondary">
            Сбросить фильтры
          </button>
        </div>
      `;
      return;
    }

    // Генерируем HTML для товаров
    const productsHtml = products
      .map(
        (product) => `
      <div class="product-item">
        <div class="product-info">
          <h3 class="product-name">${
            product.product_name || "Без названия"
          }</h3>
          <div class="product-details">
            <span class="product-brand">Марка: ${
              product.brand_name || "Не указана"
            }</span>
            <span class="product-model">Модель: ${
              product.model_name || "Не указана"
            }</span>
            <span class="product-country">Страна: ${
              product.country_name || "Не указана"
            }</span>
            ${
              product.year
                ? `<span class="product-year">Год: ${product.year}</span>`
                : ""
            }
          </div>
          <div class="product-price">
            ${
              product.cost_price
                ? `${product.cost_price} руб.`
                : "Цена не указана"
            }
          </div>
        </div>
      </div>
    `
      )
      .join("");

    this.elements.resultsContainer.innerHTML = productsHtml;
  }

  /**
   * Обновление счетчика результатов
   *
   * @param {Object} result - Результат фильтрации
   */
  updateResultsCounter(result) {
    const counterElement = document.getElementById("results-counter");
    if (!counterElement) return;

    const total = result.pagination?.total || result.data?.length || 0;
    const shown = result.data?.length || 0;

    counterElement.textContent = `Показано: ${shown} из ${total}`;
  }

  /**
   * Сброс всех фильтров
   */
  async resetAllFilters() {
    // Сбрасываем значения в элементах формы
    if (this.elements.brandSelect) {
      this.elements.brandSelect.value = "";
    }

    if (this.elements.modelSelect) {
      this.elements.modelSelect.value = "";
    }

    if (this.elements.yearSelect) {
      this.elements.yearSelect.value = "";
    }

    // Сбрасываем фильтры в FilterManager
    await this.filterManager.resetAllFilters();
  }

  /**
   * Получение текущих фильтров
   *
   * @returns {Object} Текущие фильтры
   */
  getCurrentFilters() {
    return this.filterManager.getCurrentFilters();
  }

  /**
   * Получение активных фильтров
   *
   * @returns {Object} Активные фильтры
   */
  getActiveFilters() {
    return this.filterManager.getActiveFilters();
  }

  /**
   * Проверка наличия активных фильтров
   *
   * @returns {boolean} true если есть активные фильтры
   */
  hasActiveFilters() {
    return this.filterManager.hasActiveFilters();
  }

  /**
   * Установка значения фильтра программно
   *
   * @param {string} filterName - Название фильтра
   * @param {*} value - Значение фильтра
   */
  setFilter(filterName, value) {
    this.filterManager.setFilter(filterName, value);

    // Синхронизируем с элементами формы
    this.syncFilterToForm(filterName, value);
  }

  /**
   * Синхронизация значения фильтра с элементом формы
   *
   * @param {string} filterName - Название фильтра
   * @param {*} value - Значение фильтра
   */
  syncFilterToForm(filterName, value) {
    switch (filterName) {
      case "brand_id":
        if (this.elements.brandSelect) {
          this.elements.brandSelect.value = value || "";
        }
        break;
      case "model_id":
        if (this.elements.modelSelect) {
          this.elements.modelSelect.value = value || "";
        }
        break;
      case "year":
        if (this.elements.yearSelect) {
          this.elements.yearSelect.value = value || "";
        }
        break;
    }
  }

  /**
   * Кастомный обработчик изменения фильтров (для переопределения)
   *
   * @param {Object} result - Результат фильтрации
   * @param {Object} filters - Текущие фильтры
   */
  onFiltersChangeCustom(result, filters) {
    // Переопределите этот метод для добавления кастомной логики
  }

  /**
   * Получение экземпляра FilterManager
   *
   * @returns {FilterManager} Экземпляр FilterManager
   */
  getFilterManager() {
    return this.filterManager;
  }

  /**
   * Проверка инициализации
   *
   * @returns {boolean} true если интеграция инициализирована
   */
  isReady() {
    return this.isInitialized;
  }

  /**
   * Уничтожение интеграции
   */
  destroy() {
    if (this.filterManager) {
      this.filterManager.destroy();
      this.filterManager = null;
    }

    this.elements = {};
    this.isInitialized = false;
  }
}

// Глобальная переменная для доступа к интеграции
let filterIntegration = null;

// Автоматическая инициализация при загрузке страницы
document.addEventListener("DOMContentLoaded", function () {
  // Инициализируем интеграцию с настройками по умолчанию
  filterIntegration = new FilterManagerIntegration();

  // Делаем доступной глобально для отладки
  window.filterIntegration = filterIntegration;
});

// Экспорт для использования в других модулях
if (typeof module !== "undefined" && module.exports) {
  module.exports = FilterManagerIntegration;
}
