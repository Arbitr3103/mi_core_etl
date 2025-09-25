/**
 * Пример интеграции API фильтра по странам с frontend
 * Этот файл показывает, как интегрировать новые API endpoints
 * с существующей системой фильтрации товаров
 */

class CountryFilterIntegration {
  constructor() {
    this.apiBaseUrl = "/api";
    this.cache = new Map();
    this.cacheTimeout = 5 * 60 * 1000; // 5 минут
  }

  /**
   * Получить все доступные страны изготовления
   */
  async getAllCountries() {
    const cacheKey = "all_countries";

    // Проверяем кэш
    if (this.cache.has(cacheKey)) {
      const cached = this.cache.get(cacheKey);
      if (Date.now() - cached.timestamp < this.cacheTimeout) {
        return cached.data;
      }
    }

    try {
      const response = await fetch(`${this.apiBaseUrl}/countries.php`);
      const result = await response.json();

      if (result.success) {
        // Кэшируем результат
        this.cache.set(cacheKey, {
          data: result.data,
          timestamp: Date.now(),
        });
        return result.data;
      } else {
        console.error("Ошибка загрузки стран:", result.error);
        return [];
      }
    } catch (error) {
      console.error("Ошибка запроса стран:", error);
      return [];
    }
  }

  /**
   * Получить страны для конкретной марки
   */
  async getCountriesForBrand(brandId) {
    if (!brandId) return [];

    const cacheKey = `countries_brand_${brandId}`;

    // Проверяем кэш
    if (this.cache.has(cacheKey)) {
      const cached = this.cache.get(cacheKey);
      if (Date.now() - cached.timestamp < this.cacheTimeout) {
        return cached.data;
      }
    }

    try {
      const response = await fetch(
        `${this.apiBaseUrl}/countries-by-brand.php?brand_id=${brandId}`
      );
      const result = await response.json();

      if (result.success) {
        // Кэшируем результат
        this.cache.set(cacheKey, {
          data: result.data,
          timestamp: Date.now(),
        });
        return result.data;
      } else {
        console.error("Ошибка загрузки стран для марки:", result.error);
        return [];
      }
    } catch (error) {
      console.error("Ошибка запроса стран для марки:", error);
      return [];
    }
  }

  /**
   * Получить страны для конкретной модели
   */
  async getCountriesForModel(modelId) {
    if (!modelId) return [];

    const cacheKey = `countries_model_${modelId}`;

    // Проверяем кэш
    if (this.cache.has(cacheKey)) {
      const cached = this.cache.get(cacheKey);
      if (Date.now() - cached.timestamp < this.cacheTimeout) {
        return cached.data;
      }
    }

    try {
      const response = await fetch(
        `${this.apiBaseUrl}/countries-by-model.php?model_id=${modelId}`
      );
      const result = await response.json();

      if (result.success) {
        // Кэшируем результат
        this.cache.set(cacheKey, {
          data: result.data,
          timestamp: Date.now(),
        });
        return result.data;
      } else {
        console.error("Ошибка загрузки стран для модели:", result.error);
        return [];
      }
    } catch (error) {
      console.error("Ошибка запроса стран для модели:", error);
      return [];
    }
  }

  /**
   * Фильтрация товаров с поддержкой страны
   */
  async filterProducts(filters) {
    try {
      const params = new URLSearchParams();

      // Добавляем только непустые параметры
      Object.keys(filters).forEach((key) => {
        if (
          filters[key] !== null &&
          filters[key] !== undefined &&
          filters[key] !== ""
        ) {
          params.append(key, filters[key]);
        }
      });

      const response = await fetch(
        `${this.apiBaseUrl}/products-filter.php?${params.toString()}`
      );
      const result = await response.json();

      if (result.success) {
        return result;
      } else {
        console.error("Ошибка фильтрации товаров:", result.error);
        return { data: [], pagination: { total: 0 } };
      }
    } catch (error) {
      console.error("Ошибка запроса фильтрации:", error);
      return { data: [], pagination: { total: 0 } };
    }
  }

  /**
   * Очистить кэш
   */
  clearCache() {
    this.cache.clear();
  }
}

/**
 * Класс для управления UI фильтра по стране
 */
class CountryFilterUI {
  constructor(containerId, countryApi) {
    this.container = document.getElementById(containerId);
    this.countryApi = countryApi;
    this.selectedCountry = null;
    this.onChangeCallback = null;

    this.init();
  }

  init() {
    if (!this.container) {
      console.error("Контейнер для фильтра по стране не найден");
      return;
    }

    // Создаем HTML структуру
    this.container.innerHTML = `
            <div class="country-filter">
                <label for="country-select">Страна изготовления:</label>
                <select id="country-select" class="form-control">
                    <option value="">Все страны</option>
                </select>
                <div class="loading" style="display: none;">Загрузка...</div>
                <div class="error" style="display: none; color: red;"></div>
            </div>
        `;

    this.selectElement = this.container.querySelector("#country-select");
    this.loadingElement = this.container.querySelector(".loading");
    this.errorElement = this.container.querySelector(".error");

    // Добавляем обработчик изменения
    this.selectElement.addEventListener("change", (e) => {
      this.selectedCountry = e.target.value || null;
      if (this.onChangeCallback) {
        this.onChangeCallback(this.selectedCountry);
      }
    });
  }

  /**
   * Загрузить все страны
   */
  async loadAllCountries() {
    this.showLoading();

    try {
      const countries = await this.countryApi.getAllCountries();
      this.populateSelect(countries);
      this.hideLoading();
    } catch (error) {
      this.showError("Ошибка загрузки стран");
      this.hideLoading();
    }
  }

  /**
   * Загрузить страны для марки
   */
  async loadCountriesForBrand(brandId) {
    if (!brandId) {
      await this.loadAllCountries();
      return;
    }

    this.showLoading();

    try {
      const countries = await this.countryApi.getCountriesForBrand(brandId);
      this.populateSelect(countries);
      this.hideLoading();
    } catch (error) {
      this.showError("Ошибка загрузки стран для марки");
      this.hideLoading();
    }
  }

  /**
   * Загрузить страны для модели
   */
  async loadCountriesForModel(modelId) {
    if (!modelId) {
      await this.loadAllCountries();
      return;
    }

    this.showLoading();

    try {
      const countries = await this.countryApi.getCountriesForModel(modelId);
      this.populateSelect(countries);
      this.hideLoading();
    } catch (error) {
      this.showError("Ошибка загрузки стран для модели");
      this.hideLoading();
    }
  }

  /**
   * Заполнить select элемент странами
   */
  populateSelect(countries) {
    // Очищаем текущие опции (кроме "Все страны")
    this.selectElement.innerHTML = '<option value="">Все страны</option>';

    // Добавляем страны
    countries.forEach((country) => {
      const option = document.createElement("option");
      option.value = country.id;
      option.textContent = country.name;
      this.selectElement.appendChild(option);
    });

    // Восстанавливаем выбранное значение если оно есть
    if (this.selectedCountry) {
      this.selectElement.value = this.selectedCountry;
    }
  }

  /**
   * Получить выбранную страну
   */
  getSelectedCountry() {
    return this.selectedCountry;
  }

  /**
   * Установить выбранную страну
   */
  setSelectedCountry(countryId) {
    this.selectedCountry = countryId;
    if (this.selectElement) {
      this.selectElement.value = countryId || "";
    }
  }

  /**
   * Сбросить выбор
   */
  reset() {
    this.selectedCountry = null;
    if (this.selectElement) {
      this.selectElement.value = "";
    }
  }

  /**
   * Установить callback для изменения
   */
  onChange(callback) {
    this.onChangeCallback = callback;
  }

  // Вспомогательные методы для UI
  showLoading() {
    this.loadingElement.style.display = "block";
    this.errorElement.style.display = "none";
    this.selectElement.disabled = true;
  }

  hideLoading() {
    this.loadingElement.style.display = "none";
    this.selectElement.disabled = false;
  }

  showError(message) {
    this.errorElement.textContent = message;
    this.errorElement.style.display = "block";
    this.loadingElement.style.display = "none";
  }
}

/**
 * Расширенный менеджер фильтров с поддержкой страны
 */
class ExtendedFilterManager {
  constructor() {
    this.countryApi = new CountryFilterIntegration();
    this.countryFilter = null;

    // Текущие фильтры
    this.filters = {
      brand_id: null,
      model_id: null,
      year: null,
      country_id: null,
    };

    this.onFiltersChangeCallback = null;
  }

  /**
   * Инициализация фильтра по стране
   */
  initCountryFilter(containerId) {
    this.countryFilter = new CountryFilterUI(containerId, this.countryApi);

    // Устанавливаем callback для изменения страны
    this.countryFilter.onChange((countryId) => {
      this.filters.country_id = countryId;
      this.onFiltersChange();
    });

    // Загружаем все страны при инициализации
    this.countryFilter.loadAllCountries();
  }

  /**
   * Обработчик изменения марки
   */
  onBrandChange(brandId) {
    this.filters.brand_id = brandId;
    this.filters.model_id = null; // Сбрасываем модель

    // Обновляем доступные страны для выбранной марки
    if (this.countryFilter) {
      this.countryFilter.loadCountriesForBrand(brandId);
    }

    this.onFiltersChange();
  }

  /**
   * Обработчик изменения модели
   */
  onModelChange(modelId) {
    this.filters.model_id = modelId;

    // Обновляем доступные страны для выбранной модели
    if (this.countryFilter) {
      this.countryFilter.loadCountriesForModel(modelId);
    }

    this.onFiltersChange();
  }

  /**
   * Обработчик изменения года
   */
  onYearChange(year) {
    this.filters.year = year;
    this.onFiltersChange();
  }

  /**
   * Применить все фильтры
   */
  async applyFilters() {
    if (this.onFiltersChangeCallback) {
      // Получаем отфильтрованные товары
      const result = await this.countryApi.filterProducts(this.filters);
      this.onFiltersChangeCallback(result);
    }
  }

  /**
   * Сбросить все фильтры
   */
  resetAllFilters() {
    this.filters = {
      brand_id: null,
      model_id: null,
      year: null,
      country_id: null,
    };

    // Сбрасываем UI фильтра по стране
    if (this.countryFilter) {
      this.countryFilter.reset();
      this.countryFilter.loadAllCountries();
    }

    this.onFiltersChange();
  }

  /**
   * Получить текущие фильтры
   */
  getCurrentFilters() {
    return { ...this.filters };
  }

  /**
   * Установить callback для изменения фильтров
   */
  onFiltersChange(callback) {
    if (typeof callback === "function") {
      this.onFiltersChangeCallback = callback;
    } else {
      // Если callback не передан, применяем фильтры
      this.applyFilters();
    }
  }
}

// Пример использования
document.addEventListener("DOMContentLoaded", function () {
  // Инициализируем расширенный менеджер фильтров
  const filterManager = new ExtendedFilterManager();

  // Инициализируем фильтр по стране
  filterManager.initCountryFilter("country-filter-container");

  // Устанавливаем callback для обновления результатов
  filterManager.onFiltersChange((result) => {
    console.log("Фильтры изменились:", filterManager.getCurrentFilters());
    console.log("Найдено товаров:", result.data.length);

    // Здесь можно обновить UI с результатами фильтрации
    updateProductsList(result.data);
    updatePagination(result.pagination);
  });

  // Пример интеграции с существующими фильтрами
  document.getElementById("brand-select")?.addEventListener("change", (e) => {
    filterManager.onBrandChange(e.target.value || null);
  });

  document.getElementById("model-select")?.addEventListener("change", (e) => {
    filterManager.onModelChange(e.target.value || null);
  });

  document.getElementById("year-select")?.addEventListener("change", (e) => {
    filterManager.onYearChange(e.target.value || null);
  });

  // Кнопка сброса всех фильтров
  document.getElementById("reset-filters")?.addEventListener("click", () => {
    filterManager.resetAllFilters();
  });
});

// Вспомогательные функции для обновления UI
function updateProductsList(products) {
  const container = document.getElementById("products-list");
  if (!container) return;

  if (products.length === 0) {
    container.innerHTML = "<p>Товары не найдены</p>";
    return;
  }

  const html = products
    .map(
      (product) => `
        <div class="product-item">
            <h3>${product.product_name}</h3>
            <p>Марка: ${product.brand_name || "Не указана"}</p>
            <p>Модель: ${product.model_name || "Не указана"}</p>
            <p>Страна: ${product.country_name || "Не указана"}</p>
            <p>Цена: ${
              product.cost_price ? product.cost_price + " руб." : "Не указана"
            }</p>
        </div>
    `
    )
    .join("");

  container.innerHTML = html;
}

function updatePagination(pagination) {
  const container = document.getElementById("pagination");
  if (!container) return;

  container.innerHTML = `
        <p>Показано: ${pagination.limit} из ${pagination.total}</p>
        ${
          pagination.has_more
            ? '<button onclick="loadMore()">Загрузить еще</button>'
            : ""
        }
    `;
}

// Экспортируем классы для использования в других модулях
if (typeof module !== "undefined" && module.exports) {
  module.exports = {
    CountryFilterIntegration,
    CountryFilterUI,
    ExtendedFilterManager,
  };
}
