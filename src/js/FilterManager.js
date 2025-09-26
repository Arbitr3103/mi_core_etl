/**
 * FilterManager - Менеджер фильтров с поддержкой фильтра по стране изготовления
 *
 * Обеспечивает интеграцию фильтра по стране с существующими фильтрами
 * (марка, модель, год выпуска) и автоматическую загрузку доступных стран
 * при изменении других фильтров.
 *
 * @version 1.0
 * @author ZUZ System
 */

class FilterManager {
  /**
   * Конструктор FilterManager
   *
   * @param {Object} options - Опции конфигурации
   * @param {string} options.apiBaseUrl - Базовый URL для API запросов
   * @param {function} options.onFiltersChange - Callback для изменения фильтров
   */
  constructor(options = {}) {
    this.apiBaseUrl = options.apiBaseUrl || "http://178.72.129.61/api";
    this.onFiltersChangeCallback = options.onFiltersChange || null;

    // Текущие значения фильтров
    this.filters = {
      brand_id: null,
      model_id: null,
      year: null,
      country_id: null,
    };

    // Компоненты фильтров
    this.countryFilter = null;

    // Кэш для оптимизации запросов
    this.cache = new Map();
    this.cacheTimeout = 5 * 60 * 1000; // 5 минут

    // Флаг для предотвращения циклических обновлений
    this.isUpdating = false;
  }

  /**
   * Инициализация фильтра по стране
   *
   * @param {string} containerId - ID контейнера для фильтра по стране
   */
  initCountryFilter(containerId) {
    if (!containerId) {
      console.error("Не указан ID контейнера для фильтра по стране");
      return;
    }

    // Создаем экземпляр фильтра по стране
    this.countryFilter = new CountryFilter(containerId, (countryId) => {
      this.onCountryChange(countryId);
    });

    console.log("Фильтр по стране инициализирован");
  }

  /**
   * Обработчик изменения марки автомобиля
   *
   * @param {string|number|null} brandId - ID марки
   */
  async onBrandChange(brandId) {
    if (this.isUpdating) return;

    this.isUpdating = true;

    try {
      // Обновляем значение фильтра
      this.filters.brand_id = brandId;

      // Сбрасываем зависимые фильтры
      this.filters.model_id = null;

      // Автоматически загружаем доступные страны для выбранной марки
      if (this.countryFilter) {
        if (brandId) {
          await this.countryFilter.loadCountriesForBrand(brandId);
        } else {
          await this.countryFilter.loadCountries();
        }
      }

      // Уведомляем об изменении фильтров
      this.triggerFiltersChange();
    } catch (error) {
      console.error("Ошибка при изменении марки:", error);
    } finally {
      this.isUpdating = false;
    }
  }

  /**
   * Обработчик изменения модели автомобиля
   *
   * @param {string|number|null} modelId - ID модели
   */
  async onModelChange(modelId) {
    if (this.isUpdating) return;

    this.isUpdating = true;

    try {
      // Обновляем значение фильтра
      this.filters.model_id = modelId;

      // Автоматически загружаем доступные страны для выбранной модели
      if (this.countryFilter) {
        if (modelId) {
          await this.countryFilter.loadCountriesForModel(modelId);
        } else if (this.filters.brand_id) {
          await this.countryFilter.loadCountriesForBrand(this.filters.brand_id);
        } else {
          await this.countryFilter.loadCountries();
        }
      }

      // Уведомляем об изменении фильтров
      this.triggerFiltersChange();
    } catch (error) {
      console.error("Ошибка при изменении модели:", error);
    } finally {
      this.isUpdating = false;
    }
  }

  /**
   * Обработчик изменения года выпуска
   *
   * @param {string|number|null} year - Год выпуска
   */
  onYearChange(year) {
    if (this.isUpdating) return;

    // Обновляем значение фильтра
    this.filters.year = year;

    // Уведомляем об изменении фильтров
    this.triggerFiltersChange();
  }

  /**
   * Обработчик изменения страны изготовления
   *
   * @param {string|number|null} countryId - ID страны
   */
  onCountryChange(countryId) {
    if (this.isUpdating) return;

    // Обновляем значение фильтра
    this.filters.country_id = countryId;

    // Уведомляем об изменении фильтров
    this.triggerFiltersChange();
  }

  /**
   * Применение всех активных фильтров
   *
   * @returns {Promise<Object>} Результат фильтрации
   */
  async applyFilters() {
    try {
      const result = await this.filterProducts(this.filters);
      return result;
    } catch (error) {
      console.error("Ошибка применения фильтров:", error);
      return { data: [], pagination: { total: 0 } };
    }
  }

  /**
   * Фильтрация товаров через API
   *
   * @param {Object} filters - Объект с параметрами фильтрации
   * @returns {Promise<Object>} Результат фильтрации
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
   * Сброс всех фильтров
   *
   * @param {boolean} reloadCountries - Перезагружать ли список стран (по умолчанию true)
   */
  async resetAllFilters(reloadCountries = true) {
    if (this.isUpdating) return;

    this.isUpdating = true;

    try {
      // Сбрасываем все значения фильтров
      this.filters = {
        brand_id: null,
        model_id: null,
        year: null,
        country_id: null,
      };

      // Сбрасываем фильтр по стране
      if (this.countryFilter) {
        // Сбрасываем без вызова callback чтобы избежать двойного срабатывания
        this.countryFilter.reset(false);

        // Перезагружаем список стран если требуется
        if (reloadCountries) {
          await this.countryFilter.loadCountries();
        }
      }

      // Уведомляем об изменении фильтров
      this.triggerFiltersChange();
    } catch (error) {
      console.error("Ошибка при сбросе фильтров:", error);
    } finally {
      this.isUpdating = false;
    }
  }

  /**
   * Сброс фильтра по стране
   *
   * @param {boolean} reloadCountries - Перезагружать ли список стран (по умолчанию false)
   */
  async resetCountryFilter(reloadCountries = false) {
    if (this.countryFilter) {
      // Сбрасываем без вызова callback чтобы избежать двойного срабатывания
      this.countryFilter.reset(false);
      this.filters.country_id = null;

      // Перезагружаем список стран если требуется
      if (reloadCountries) {
        if (this.filters.model_id) {
          await this.countryFilter.loadCountriesForModel(this.filters.model_id);
        } else if (this.filters.brand_id) {
          await this.countryFilter.loadCountriesForBrand(this.filters.brand_id);
        } else {
          await this.countryFilter.loadCountries();
        }
      }

      this.triggerFiltersChange();
    }
  }

  /**
   * Получение текущих значений всех фильтров
   *
   * @returns {Object} Объект с текущими значениями фильтров
   */
  getCurrentFilters() {
    return { ...this.filters };
  }

  /**
   * Получение активных фильтров (только с непустыми значениями)
   *
   * @returns {Object} Объект с активными фильтрами
   */
  getActiveFilters() {
    const activeFilters = {};

    Object.keys(this.filters).forEach((key) => {
      if (
        this.filters[key] !== null &&
        this.filters[key] !== undefined &&
        this.filters[key] !== ""
      ) {
        activeFilters[key] = this.filters[key];
      }
    });

    return activeFilters;
  }

  /**
   * Проверка наличия активных фильтров
   *
   * @returns {boolean} true если есть активные фильтры
   */
  hasActiveFilters() {
    return Object.keys(this.getActiveFilters()).length > 0;
  }

  /**
   * Установка callback функции для изменения фильтров
   *
   * @param {function} callback - Callback функция
   */
  onFiltersChange(callback) {
    if (typeof callback === "function") {
      this.onFiltersChangeCallback = callback;
    }
  }

  /**
   * Вызов callback функции при изменении фильтров
   */
  async triggerFiltersChange() {
    if (typeof this.onFiltersChangeCallback === "function") {
      try {
        const result = await this.applyFilters();
        this.onFiltersChangeCallback(result, this.getCurrentFilters());
      } catch (error) {
        console.error("Ошибка в callback изменения фильтров:", error);
      }
    }
  }

  /**
   * Установка значения конкретного фильтра
   *
   * @param {string} filterName - Название фильтра
   * @param {*} value - Значение фильтра
   */
  setFilter(filterName, value) {
    if (this.filters.hasOwnProperty(filterName)) {
      this.filters[filterName] = value;

      // Обновляем UI фильтра по стране если это необходимо
      if (filterName === "country_id" && this.countryFilter) {
        this.countryFilter.setSelectedCountry(value);
      }

      this.triggerFiltersChange();
    }
  }

  /**
   * Получение значения конкретного фильтра
   *
   * @param {string} filterName - Название фильтра
   * @returns {*} Значение фильтра
   */
  getFilter(filterName) {
    return this.filters[filterName] || null;
  }

  /**
   * Получение информации о выбранной стране
   *
   * @returns {Object|null} Информация о стране или null
   */
  getSelectedCountryInfo() {
    if (this.countryFilter) {
      return this.countryFilter.getSelectedCountryInfo();
    }
    return null;
  }

  /**
   * Получение всех доступных стран
   *
   * @returns {Array} Массив стран
   */
  getAvailableCountries() {
    if (this.countryFilter) {
      return this.countryFilter.getCountries();
    }
    return [];
  }

  /**
   * Очистка кэша
   */
  clearCache() {
    this.cache.clear();
    if (this.countryFilter) {
      this.countryFilter.clearCache();
    }
  }

  /**
   * Уничтожение менеджера фильтров
   */
  destroy() {
    // Уничтожаем фильтр по стране
    if (this.countryFilter) {
      this.countryFilter.destroy();
      this.countryFilter = null;
    }

    // Очищаем кэш
    this.clearCache();

    // Сбрасываем callback
    this.onFiltersChangeCallback = null;

    // Очищаем фильтры
    this.filters = {};
  }
}

// Экспорт для использования в других модулях
if (typeof module !== "undefined" && module.exports) {
  module.exports = FilterManager;
}
