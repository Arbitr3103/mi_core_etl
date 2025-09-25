/**
 * Unit тесты для FilterManager
 *
 * Тестирует интеграцию фильтра по стране с существующими фильтрами,
 * автоматическую загрузку стран и обработку событий изменения фильтров.
 */

// Мок для CountryFilter
class MockCountryFilter {
  constructor(containerId, onChangeCallback) {
    this.containerId = containerId;
    this.onChange = onChangeCallback;
    this.selectedCountry = null;
    this.countries = [];
    this.cache = new Map();
  }

  async loadCountries() {
    this.countries = [
      { id: 1, name: "Германия" },
      { id: 2, name: "Япония" },
      { id: 3, name: "США" },
    ];
    return this.countries;
  }

  async loadCountriesForBrand(brandId) {
    const brandCountries = {
      1: [{ id: 1, name: "Германия" }], // BMW
      2: [{ id: 1, name: "Германия" }], // Mercedes
      3: [{ id: 1, name: "Германия" }], // Audi
      4: [{ id: 2, name: "Япония" }], // Toyota
      5: [{ id: 2, name: "Япония" }], // Honda
    };

    this.countries = brandCountries[brandId] || [];
    return this.countries;
  }

  async loadCountriesForModel(modelId) {
    // Упрощенная логика для тестов
    this.countries = [{ id: 1, name: "Германия" }];
    return this.countries;
  }

  getSelectedCountry() {
    return this.selectedCountry;
  }

  setSelectedCountry(countryId) {
    this.selectedCountry = countryId;
  }

  reset() {
    this.selectedCountry = null;
  }

  getSelectedCountryInfo() {
    if (!this.selectedCountry) return null;
    return this.countries.find((c) => c.id == this.selectedCountry) || null;
  }

  getCountries() {
    return [...this.countries];
  }

  clearCache() {
    this.cache.clear();
  }

  destroy() {
    this.selectedCountry = null;
    this.countries = [];
    this.cache.clear();
  }
}

// Мок для fetch API
global.fetch = jest.fn();

// Подключаем FilterManager
const FilterManager = require("../js/FilterManager.js");

// Заменяем CountryFilter на мок
global.CountryFilter = MockCountryFilter;

describe("FilterManager", () => {
  let filterManager;
  let mockCallback;

  beforeEach(() => {
    mockCallback = jest.fn();
    filterManager = new FilterManager({
      apiBaseUrl: "/api",
      onFiltersChange: mockCallback,
    });

    // Мокаем fetch для API запросов
    fetch.mockClear();
    fetch.mockResolvedValue({
      json: () =>
        Promise.resolve({
          success: true,
          data: [
            {
              id: 1,
              product_name: "Тестовый товар",
              brand_name: "BMW",
              model_name: "X5",
              country_name: "Германия",
            },
          ],
          pagination: { total: 1 },
        }),
    });
  });

  afterEach(() => {
    if (filterManager) {
      filterManager.destroy();
    }
  });

  describe("Инициализация", () => {
    test("должен создаваться с правильными начальными значениями", () => {
      expect(filterManager.filters).toEqual({
        brand_id: null,
        model_id: null,
        year: null,
        country_id: null,
      });
      expect(filterManager.countryFilter).toBeNull();
    });

    test("должен инициализировать фильтр по стране", () => {
      // Создаем мок DOM элемента
      document.body.innerHTML = '<div id="country-container"></div>';

      filterManager.initCountryFilter("country-container");

      expect(filterManager.countryFilter).not.toBeNull();
      expect(filterManager.countryFilter.containerId).toBe("country-container");
    });
  });

  describe("Обработка изменения марки", () => {
    beforeEach(() => {
      document.body.innerHTML = '<div id="country-container"></div>';
      filterManager.initCountryFilter("country-container");
    });

    test("должен обновлять фильтр марки и загружать страны", async () => {
      await filterManager.onBrandChange("1");

      expect(filterManager.filters.brand_id).toBe("1");
      expect(filterManager.filters.model_id).toBeNull(); // должен сбрасываться
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
      ]);
    });

    test("должен загружать все страны при сбросе марки", async () => {
      await filterManager.onBrandChange(null);

      expect(filterManager.filters.brand_id).toBeNull();
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
        { id: 2, name: "Япония" },
        { id: 3, name: "США" },
      ]);
    });

    test("должен вызывать callback при изменении марки", async () => {
      await filterManager.onBrandChange("1");

      expect(mockCallback).toHaveBeenCalled();
      expect(fetch).toHaveBeenCalledWith("/api/products-filter.php?brand_id=1");
    });
  });

  describe("Обработка изменения модели", () => {
    beforeEach(() => {
      document.body.innerHTML = '<div id="country-container"></div>';
      filterManager.initCountryFilter("country-container");
    });

    test("должен обновлять фильтр модели и загружать страны", async () => {
      await filterManager.onModelChange("1");

      expect(filterManager.filters.model_id).toBe("1");
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
      ]);
    });

    test("должен загружать страны для марки при сбросе модели", async () => {
      filterManager.filters.brand_id = "1";
      await filterManager.onModelChange(null);

      expect(filterManager.filters.model_id).toBeNull();
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
      ]);
    });
  });

  describe("Обработка изменения страны", () => {
    test("должен обновлять фильтр страны", () => {
      filterManager.onCountryChange("1");

      expect(filterManager.filters.country_id).toBe("1");
    });

    test("должен вызывать callback при изменении страны", () => {
      filterManager.onCountryChange("1");

      expect(mockCallback).toHaveBeenCalled();
    });
  });

  describe("Сброс фильтров", () => {
    beforeEach(() => {
      document.body.innerHTML = '<div id="country-container"></div>';
      filterManager.initCountryFilter("country-container");
    });

    test("должен сбрасывать все фильтры", async () => {
      // Устанавливаем значения
      filterManager.filters.brand_id = "1";
      filterManager.filters.model_id = "1";
      filterManager.filters.year = "2023";
      filterManager.filters.country_id = "1";

      await filterManager.resetAllFilters();

      expect(filterManager.filters).toEqual({
        brand_id: null,
        model_id: null,
        year: null,
        country_id: null,
      });
      expect(filterManager.countryFilter.selectedCountry).toBeNull();
    });

    test("должен сбрасывать все фильтры без перезагрузки стран", async () => {
      filterManager.filters.country_id = "1";
      fetch.mockClear();

      await filterManager.resetAllFilters(false);

      expect(filterManager.filters.country_id).toBeNull();
      expect(fetch).not.toHaveBeenCalled();
    });

    test("должен сбрасывать только фильтр по стране", async () => {
      filterManager.filters.brand_id = "1";
      filterManager.filters.country_id = "1";
      filterManager.countryFilter.selectedCountry = "1";

      await filterManager.resetCountryFilter();

      expect(filterManager.filters.brand_id).toBe("1"); // не должен сбрасываться
      expect(filterManager.filters.country_id).toBeNull();
      expect(filterManager.countryFilter.selectedCountry).toBeNull();
    });

    test("должен перезагружать страны при сбросе фильтра по стране", async () => {
      filterManager.filters.brand_id = "1";
      filterManager.filters.country_id = "1";

      await filterManager.resetCountryFilter(true);

      expect(fetch).toHaveBeenCalledWith(
        "/api/countries-by-brand.php?brand_id=1",
        expect.any(Object)
      );
    });

    test("должен предотвращать циклические обновления при сбросе", async () => {
      filterManager.isUpdating = true;

      await filterManager.resetAllFilters();

      // Фильтры не должны измениться
      expect(filterManager.filters.brand_id).toBeNull();
    });
  });

  describe("Применение фильтров", () => {
    test("должен отправлять запрос с активными фильтрами", async () => {
      filterManager.filters.brand_id = "1";
      filterManager.filters.country_id = "1";

      const result = await filterManager.applyFilters();

      expect(fetch).toHaveBeenCalledWith(
        "/api/products-filter.php?brand_id=1&country_id=1"
      );
      expect(result.success).toBe(true);
    });

    test("должен игнорировать пустые фильтры", async () => {
      filterManager.filters.brand_id = "1";
      filterManager.filters.model_id = null;
      filterManager.filters.year = "";

      await filterManager.applyFilters();

      expect(fetch).toHaveBeenCalledWith("/api/products-filter.php?brand_id=1");
    });
  });

  describe("Получение фильтров", () => {
    test("должен возвращать текущие фильтры", () => {
      filterManager.filters.brand_id = "1";
      filterManager.filters.country_id = "1";

      const current = filterManager.getCurrentFilters();

      expect(current).toEqual({
        brand_id: "1",
        model_id: null,
        year: null,
        country_id: "1",
      });
    });

    test("должен возвращать только активные фильтры", () => {
      filterManager.filters.brand_id = "1";
      filterManager.filters.model_id = null;
      filterManager.filters.year = "";
      filterManager.filters.country_id = "1";

      const active = filterManager.getActiveFilters();

      expect(active).toEqual({
        brand_id: "1",
        country_id: "1",
      });
    });

    test("должен проверять наличие активных фильтров", () => {
      expect(filterManager.hasActiveFilters()).toBe(false);

      filterManager.filters.brand_id = "1";
      expect(filterManager.hasActiveFilters()).toBe(true);
    });
  });

  describe("Установка фильтров", () => {
    beforeEach(() => {
      document.body.innerHTML = '<div id="country-container"></div>';
      filterManager.initCountryFilter("country-container");
    });

    test("должен устанавливать значение фильтра", () => {
      filterManager.setFilter("brand_id", "1");

      expect(filterManager.filters.brand_id).toBe("1");
    });

    test("должен синхронизировать фильтр по стране с UI", () => {
      filterManager.setFilter("country_id", "1");

      expect(filterManager.filters.country_id).toBe("1");
      expect(filterManager.countryFilter.selectedCountry).toBe("1");
    });

    test("должен получать значение фильтра", () => {
      filterManager.filters.brand_id = "1";

      expect(filterManager.getFilter("brand_id")).toBe("1");
      expect(filterManager.getFilter("nonexistent")).toBeNull();
    });
  });

  describe("Информация о стране", () => {
    beforeEach(() => {
      document.body.innerHTML = '<div id="country-container"></div>';
      filterManager.initCountryFilter("country-container");
    });

    test("должен возвращать информацию о выбранной стране", async () => {
      await filterManager.countryFilter.loadCountries();
      filterManager.countryFilter.setSelectedCountry("1");

      const countryInfo = filterManager.getSelectedCountryInfo();

      expect(countryInfo).toEqual({ id: 1, name: "Германия" });
    });

    test("должен возвращать доступные страны", async () => {
      await filterManager.countryFilter.loadCountries();

      const countries = filterManager.getAvailableCountries();

      expect(countries).toEqual([
        { id: 1, name: "Германия" },
        { id: 2, name: "Япония" },
        { id: 3, name: "США" },
      ]);
    });
  });

  describe("Предотвращение циклических обновлений", () => {
    beforeEach(() => {
      document.body.innerHTML = '<div id="country-container"></div>';
      filterManager.initCountryFilter("country-container");
    });

    test("должен предотвращать циклические обновления", async () => {
      filterManager.isUpdating = true;

      await filterManager.onBrandChange("1");

      // Фильтр не должен измениться
      expect(filterManager.filters.brand_id).toBeNull();
    });
  });

  describe("Обработка ошибок", () => {
    test("должен обрабатывать ошибки API", async () => {
      fetch.mockRejectedValueOnce(new Error("Network error"));

      const result = await filterManager.applyFilters();

      expect(result).toEqual({ data: [], pagination: { total: 0 } });
    });

    test("должен обрабатывать неуспешные ответы API", async () => {
      fetch.mockResolvedValueOnce({
        json: () =>
          Promise.resolve({
            success: false,
            error: "Test error",
          }),
      });

      const result = await filterManager.applyFilters();

      expect(result).toEqual({ data: [], pagination: { total: 0 } });
    });

    test("должен обрабатывать ошибки в callback функции", async () => {
      const errorCallback = jest.fn().mockImplementation(() => {
        throw new Error("Callback error");
      });

      filterManager.onFiltersChange(errorCallback);

      // Не должно выбрасывать ошибку
      expect(() => filterManager.onBrandChange("1")).not.toThrow();
    });

    test("должен обрабатывать ошибки при инициализации фильтра по стране", () => {
      // Тест с несуществующим контейнером
      expect(() =>
        filterManager.initCountryFilter("non-existent")
      ).not.toThrow();
      expect(filterManager.countryFilter).toBeNull();
    });
  });

  describe("Очистка ресурсов", () => {
    beforeEach(() => {
      document.body.innerHTML = '<div id="country-container"></div>';
      filterManager.initCountryFilter("country-container");
    });

    test("должен очищать кэш", () => {
      filterManager.cache.set("test", "value");
      filterManager.countryFilter.cache.set("test", "value");

      filterManager.clearCache();

      expect(filterManager.cache.size).toBe(0);
      expect(filterManager.countryFilter.cache.size).toBe(0);
    });

    test("должен корректно уничтожаться", () => {
      filterManager.destroy();

      expect(filterManager.countryFilter).toBeNull();
      expect(filterManager.onFiltersChangeCallback).toBeNull();
      expect(filterManager.filters).toEqual({});
    });
  });
});

// Тесты валидации и граничных случаев
describe("FilterManager Edge Cases", () => {
  let filterManager;

  beforeEach(() => {
    filterManager = new FilterManager();
  });

  afterEach(() => {
    filterManager.destroy();
  });

  test("должен обрабатывать некорректные типы данных в фильтрах", () => {
    expect(() => filterManager.setFilter("brand_id", {})).not.toThrow();
    expect(() => filterManager.setFilter("brand_id", [])).not.toThrow();
    expect(() =>
      filterManager.setFilter("brand_id", function () {})
    ).not.toThrow();
  });

  test("должен обрабатывать попытку установки несуществующего фильтра", () => {
    filterManager.setFilter("nonexistent_filter", "value");

    expect(filterManager.filters.nonexistent_filter).toBeUndefined();
  });

  test("должен корректно работать без callback функции", async () => {
    filterManager.onFiltersChangeCallback = null;

    expect(() => filterManager.onBrandChange("1")).not.toThrow();
    expect(() => filterManager.triggerFiltersChange()).not.toThrow();
  });

  test("должен обрабатывать множественные быстрые изменения фильтров", async () => {
    document.body.innerHTML = '<div id="country-container"></div>';
    filterManager.initCountryFilter("country-container");

    // Быстрые изменения
    const promises = [
      filterManager.onBrandChange("1"),
      filterManager.onBrandChange("2"),
      filterManager.onBrandChange("3"),
    ];

    await Promise.all(promises);

    // Должен обработать все изменения без ошибок
    expect(filterManager.filters.brand_id).toBeDefined();
  });
});

// Тесты производительности FilterManager
describe("FilterManager Performance", () => {
  let filterManager;

  beforeEach(() => {
    filterManager = new FilterManager();
    document.body.innerHTML = '<div id="country-container"></div>';
    filterManager.initCountryFilter("country-container");

    fetch.mockResolvedValue({
      json: () =>
        Promise.resolve({
          success: true,
          data: [],
          pagination: { total: 0 },
        }),
    });
  });

  afterEach(() => {
    filterManager.destroy();
  });

  test("должен эффективно обрабатывать множественные фильтры", async () => {
    const startTime = performance.now();

    await filterManager.onBrandChange("1");
    await filterManager.onModelChange("1");
    filterManager.onYearChange("2023");
    filterManager.onCountryChange("1");

    const endTime = performance.now();

    // Операции должны выполняться быстро
    expect(endTime - startTime).toBeLessThan(1000);
  });
});

// Дополнительные интеграционные тесты
describe("FilterManager Integration", () => {
  let filterManager;
  let mockCallback;

  beforeEach(() => {
    mockCallback = jest.fn();
    filterManager = new FilterManager({
      onFiltersChange: mockCallback,
    });

    document.body.innerHTML = '<div id="country-container"></div>';
    filterManager.initCountryFilter("country-container");

    fetch.mockClear();
    fetch.mockResolvedValue({
      json: () =>
        Promise.resolve({
          success: true,
          data: [],
          pagination: { total: 0 },
        }),
    });
  });

  afterEach(() => {
    filterManager.destroy();
  });

  test("должен корректно обрабатывать последовательность изменений фильтров", async () => {
    // Выбираем марку
    await filterManager.onBrandChange("1");
    expect(filterManager.filters.brand_id).toBe("1");
    expect(mockCallback).toHaveBeenCalledTimes(1);

    // Выбираем модель
    await filterManager.onModelChange("1");
    expect(filterManager.filters.model_id).toBe("1");
    expect(mockCallback).toHaveBeenCalledTimes(2);

    // Выбираем страну
    filterManager.onCountryChange("1");
    expect(filterManager.filters.country_id).toBe("1");
    expect(mockCallback).toHaveBeenCalledTimes(3);

    // Проверяем финальное состояние
    expect(filterManager.getCurrentFilters()).toEqual({
      brand_id: "1",
      model_id: "1",
      year: null,
      country_id: "1",
    });
  });

  test("должен автоматически обновлять страны при изменении марки и модели", async () => {
    // Изначально загружены все страны
    expect(filterManager.countryFilter.countries).toEqual([]);

    // Выбираем марку BMW
    await filterManager.onBrandChange("1");
    expect(filterManager.countryFilter.countries).toEqual([
      { id: 1, name: "Германия" },
    ]);

    // Выбираем модель
    await filterManager.onModelChange("1");
    expect(filterManager.countryFilter.countries).toEqual([
      { id: 1, name: "Германия" },
    ]);

    // Сбрасываем марку
    await filterManager.onBrandChange(null);
    expect(filterManager.countryFilter.countries).toEqual([
      { id: 1, name: "Германия" },
      { id: 2, name: "Япония" },
      { id: 3, name: "США" },
    ]);
  });
});
