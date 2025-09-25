/**
 * Comprehensive tests for reset functionality in CountryFilter and FilterManager
 *
 * Tests various scenarios of resetting filters to ensure correctness
 * according to requirements 1.4 and 4.3
 *
 * @version 1.0
 * @author ZUZ System
 */

// Мок для fetch API
global.fetch = jest.fn();

// Мок для DOM
const mockContainer = {
  innerHTML: "",
  querySelector: jest.fn(),
  id: "test-container",
};

const mockSelectElement = {
  addEventListener: jest.fn(),
  removeEventListener: jest.fn(),
  value: "",
  disabled: false,
  innerHTML: "",
  appendChild: jest.fn(),
};

const mockLoadingElement = {
  style: { display: "none" },
};

const mockErrorElement = {
  style: { display: "none" },
  querySelector: jest.fn().mockReturnValue({ textContent: "" }),
};

// Мок для document.getElementById
global.document = {
  getElementById: jest.fn().mockReturnValue(mockContainer),
  createElement: jest
    .fn()
    .mockReturnValue({ value: "", textContent: "", appendChild: jest.fn() }),
  createDocumentFragment: jest.fn().mockReturnValue({
    appendChild: jest.fn(),
  }),
};

// Подключаем классы
const CountryFilter = require("../js/CountryFilter.js");
const FilterManager = require("../js/FilterManager.js");

describe("CountryFilter Reset Functionality", () => {
  let countryFilter;
  let mockOnChange;

  beforeEach(() => {
    jest.clearAllMocks();

    // Настройка моков
    mockContainer.querySelector.mockImplementation((selector) => {
      switch (selector) {
        case "#country-select":
          return mockSelectElement;
        case ".filter-loading":
          return mockLoadingElement;
        case ".filter-error":
          return mockErrorElement;
        case ".retry-button":
          return { addEventListener: jest.fn() };
        default:
          return null;
      }
    });

    mockOnChange = jest.fn();
    countryFilter = new CountryFilter("test-container", mockOnChange);

    // Мокаем успешный API ответ
    fetch.mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          success: true,
          data: [
            { id: 1, name: "Германия" },
            { id: 2, name: "Япония" },
          ],
        }),
    });
  });

  afterEach(() => {
    if (countryFilter) {
      countryFilter.destroy();
    }
  });

  describe("Базовый сброс CountryFilter", () => {
    test("должен сбрасывать выбранную страну", () => {
      // Устанавливаем страну
      countryFilter.setSelectedCountry("1");
      expect(countryFilter.getSelectedCountry()).toBe("1");

      // Сбрасываем
      countryFilter.reset();

      expect(countryFilter.getSelectedCountry()).toBeNull();
      expect(mockSelectElement.value).toBe("");
    });

    test("должен вызывать callback при сбросе по умолчанию", () => {
      countryFilter.setSelectedCountry("1");
      mockOnChange.mockClear();

      countryFilter.reset();

      expect(mockOnChange).toHaveBeenCalledWith(null);
    });

    test("должен не вызывать callback если указано triggerChange=false", () => {
      countryFilter.setSelectedCountry("1");
      mockOnChange.mockClear();

      countryFilter.reset(false);

      expect(mockOnChange).not.toHaveBeenCalled();
    });

    test("должен скрывать ошибки при сбросе", () => {
      countryFilter.showError("Тестовая ошибка");
      expect(mockErrorElement.style.display).toBe("block");

      countryFilter.reset();

      expect(mockErrorElement.style.display).toBe("none");
    });

    test("должен корректно работать при повторном сбросе", () => {
      countryFilter.setSelectedCountry("1");
      countryFilter.reset();

      // Повторный сброс не должен вызывать ошибок
      expect(() => countryFilter.reset()).not.toThrow();
      expect(countryFilter.getSelectedCountry()).toBeNull();
    });

    test("должен корректно работать если страна не была выбрана", () => {
      expect(countryFilter.getSelectedCountry()).toBeNull();

      // Сброс пустого состояния не должен вызывать ошибок
      expect(() => countryFilter.reset()).not.toThrow();
      expect(countryFilter.getSelectedCountry()).toBeNull();
    });
  });

  describe("Сброс с различными состояниями", () => {
    test("должен сбрасывать при наличии загруженных стран", async () => {
      await countryFilter.loadCountries();
      countryFilter.setSelectedCountry("1");

      countryFilter.reset();

      expect(countryFilter.getSelectedCountry()).toBeNull();
      expect(countryFilter.getCountries()).toHaveLength(2); // Страны должны остаться
    });

    test("должен сбрасывать при ошибке загрузки", async () => {
      fetch.mockRejectedValueOnce(new Error("Network error"));

      await countryFilter.loadCountries();
      countryFilter.setSelectedCountry("1");

      countryFilter.reset();

      expect(countryFilter.getSelectedCountry()).toBeNull();
    });

    test("должен сбрасывать во время загрузки", () => {
      // Имитируем состояние загрузки
      countryFilter.showLoading();
      countryFilter.setSelectedCountry("1");

      countryFilter.reset();

      expect(countryFilter.getSelectedCountry()).toBeNull();
      expect(mockErrorElement.style.display).toBe("none");
    });
  });
});

describe("FilterManager Reset Functionality", () => {
  let filterManager;
  let mockCallback;

  beforeEach(() => {
    jest.clearAllMocks();

    mockCallback = jest.fn();
    filterManager = new FilterManager({
      apiBaseUrl: "/api",
      onFiltersChange: mockCallback,
    });

    // Инициализируем фильтр по стране
    filterManager.initCountryFilter("test-container");

    // Мокаем успешный API ответ
    fetch.mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          success: true,
          data: [
            { id: 1, name: "Германия" },
            { id: 2, name: "Япония" },
          ],
          pagination: { total: 2 },
        }),
    });
  });

  afterEach(() => {
    if (filterManager) {
      filterManager.destroy();
    }
  });

  describe("Сброс всех фильтров", () => {
    test("должен сбрасывать все фильтры", async () => {
      // Устанавливаем все фильтры
      filterManager.setFilter("brand_id", "1");
      filterManager.setFilter("model_id", "1");
      filterManager.setFilter("year", "2023");
      filterManager.setFilter("country_id", "1");

      expect(filterManager.hasActiveFilters()).toBe(true);

      // Сбрасываем все
      await filterManager.resetAllFilters();

      expect(filterManager.getCurrentFilters()).toEqual({
        brand_id: null,
        model_id: null,
        year: null,
        country_id: null,
      });
      expect(filterManager.hasActiveFilters()).toBe(false);
    });

    test("должен сбрасывать фильтр по стране", async () => {
      filterManager.setFilter("country_id", "1");
      filterManager.countryFilter.setSelectedCountry("1");

      await filterManager.resetAllFilters();

      expect(filterManager.getFilter("country_id")).toBeNull();
      expect(filterManager.countryFilter.getSelectedCountry()).toBeNull();
    });

    test("должен перезагружать страны по умолчанию", async () => {
      await filterManager.resetAllFilters();

      // Должен быть вызван API для загрузки всех стран
      expect(fetch).toHaveBeenCalledWith(
        "/api/countries.php",
        expect.any(Object)
      );
    });

    test("должен не перезагружать страны если указано reloadCountries=false", async () => {
      fetch.mockClear();

      await filterManager.resetAllFilters(false);

      // API не должен вызываться
      expect(fetch).not.toHaveBeenCalled();
    });

    test("должен вызывать callback после сброса", async () => {
      mockCallback.mockClear();

      await filterManager.resetAllFilters();

      expect(mockCallback).toHaveBeenCalled();
    });

    test("должен обрабатывать ошибки при сбросе", async () => {
      fetch.mockRejectedValueOnce(new Error("Network error"));

      // Не должно выбрасывать ошибку
      await expect(filterManager.resetAllFilters()).resolves.not.toThrow();

      // Фильтры должны быть сброшены несмотря на ошибку
      expect(filterManager.hasActiveFilters()).toBe(false);
    });
  });

  describe("Сброс только фильтра по стране", () => {
    test("должен сбрасывать только фильтр по стране", async () => {
      // Устанавливаем все фильтры
      filterManager.setFilter("brand_id", "1");
      filterManager.setFilter("model_id", "1");
      filterManager.setFilter("country_id", "1");

      await filterManager.resetCountryFilter();

      // Только страна должна сброситься
      expect(filterManager.getFilter("brand_id")).toBe("1");
      expect(filterManager.getFilter("model_id")).toBe("1");
      expect(filterManager.getFilter("country_id")).toBeNull();
    });

    test("должен перезагружать страны для текущей модели", async () => {
      filterManager.setFilter("model_id", "1");
      filterManager.setFilter("country_id", "1");

      await filterManager.resetCountryFilter(true);

      expect(fetch).toHaveBeenCalledWith(
        "/api/countries-by-model.php?model_id=1",
        expect.any(Object)
      );
    });

    test("должен перезагружать страны для текущей марки", async () => {
      filterManager.setFilter("brand_id", "1");
      filterManager.setFilter("country_id", "1");

      await filterManager.resetCountryFilter(true);

      expect(fetch).toHaveBeenCalledWith(
        "/api/countries-by-brand.php?brand_id=1",
        expect.any(Object)
      );
    });

    test("должен перезагружать все страны если нет марки и модели", async () => {
      filterManager.setFilter("country_id", "1");

      await filterManager.resetCountryFilter(true);

      expect(fetch).toHaveBeenCalledWith(
        "/api/countries.php",
        expect.any(Object)
      );
    });

    test("должен не перезагружать страны по умолчанию", async () => {
      filterManager.setFilter("country_id", "1");
      fetch.mockClear();

      await filterManager.resetCountryFilter();

      expect(fetch).not.toHaveBeenCalled();
    });
  });

  describe("Интеграция сброса с другими фильтрами", () => {
    test("должен корректно работать при изменении марки после сброса", async () => {
      // Сбрасываем все
      await filterManager.resetAllFilters();

      // Выбираем марку
      await filterManager.onBrandChange("1");

      expect(filterManager.getFilter("brand_id")).toBe("1");
      expect(filterManager.getFilter("country_id")).toBeNull();
    });

    test("должен корректно работать при изменении модели после сброса", async () => {
      await filterManager.resetAllFilters();

      await filterManager.onModelChange("1");

      expect(filterManager.getFilter("model_id")).toBe("1");
      expect(filterManager.getFilter("country_id")).toBeNull();
    });

    test("должен сохранять другие фильтры при сбросе только страны", async () => {
      filterManager.setFilter("brand_id", "1");
      filterManager.setFilter("year", "2023");
      filterManager.setFilter("country_id", "1");

      await filterManager.resetCountryFilter();

      expect(filterManager.getActiveFilters()).toEqual({
        brand_id: "1",
        year: "2023",
      });
    });
  });

  describe("Предотвращение циклических обновлений при сбросе", () => {
    test("должен предотвращать циклические обновления при сбросе всех фильтров", async () => {
      filterManager.isUpdating = true;

      await filterManager.resetAllFilters();

      // Фильтры не должны измениться
      expect(filterManager.getCurrentFilters()).not.toEqual({
        brand_id: null,
        model_id: null,
        year: null,
        country_id: null,
      });
    });

    test("должен корректно устанавливать флаг isUpdating", async () => {
      expect(filterManager.isUpdating).toBe(false);

      const resetPromise = filterManager.resetAllFilters();

      // Во время выполнения флаг должен быть true
      expect(filterManager.isUpdating).toBe(true);

      await resetPromise;

      // После выполнения флаг должен сброситься
      expect(filterManager.isUpdating).toBe(false);
    });
  });
});

describe("Reset Functionality Edge Cases", () => {
  let countryFilter;
  let filterManager;

  beforeEach(() => {
    jest.clearAllMocks();

    // Настройка моков
    mockContainer.querySelector.mockImplementation((selector) => {
      switch (selector) {
        case "#country-select":
          return mockSelectElement;
        case ".filter-loading":
          return mockLoadingElement;
        case ".filter-error":
          return mockErrorElement;
        case ".retry-button":
          return { addEventListener: jest.fn() };
        default:
          return null;
      }
    });

    countryFilter = new CountryFilter("test-container", jest.fn());
    filterManager = new FilterManager({ apiBaseUrl: "/api" });
    filterManager.initCountryFilter("test-container");
  });

  afterEach(() => {
    if (countryFilter) countryFilter.destroy();
    if (filterManager) filterManager.destroy();
  });

  describe("Граничные случаи", () => {
    test("должен обрабатывать сброс при отсутствии DOM элементов", () => {
      countryFilter.selectElement = null;

      expect(() => countryFilter.reset()).not.toThrow();
      expect(countryFilter.getSelectedCountry()).toBeNull();
    });

    test("должен обрабатывать сброс при отсутствии countryFilter в FilterManager", async () => {
      filterManager.countryFilter = null;

      await expect(filterManager.resetAllFilters()).resolves.not.toThrow();
      await expect(filterManager.resetCountryFilter()).resolves.not.toThrow();
    });

    test("должен обрабатывать множественные быстрые сбросы", async () => {
      filterManager.setFilter("brand_id", "1");
      filterManager.setFilter("country_id", "1");

      // Множественные быстрые сбросы
      const promises = [
        filterManager.resetAllFilters(),
        filterManager.resetAllFilters(),
        filterManager.resetCountryFilter(),
      ];

      await expect(Promise.all(promises)).resolves.not.toThrow();
      expect(filterManager.hasActiveFilters()).toBe(false);
    });

    test("должен корректно работать с некорректными значениями", () => {
      countryFilter.selectedCountry = undefined;

      expect(() => countryFilter.reset()).not.toThrow();
      expect(countryFilter.getSelectedCountry()).toBeNull();
    });
  });

  describe("Производительность сброса", () => {
    test("должен быстро выполнять сброс", () => {
      const startTime = performance.now();

      countryFilter.reset();

      const endTime = performance.now();
      expect(endTime - startTime).toBeLessThan(10); // Менее 10мс
    });

    test("должен эффективно сбрасывать множественные фильтры", async () => {
      // Устанавливаем много фильтров
      for (let i = 0; i < 100; i++) {
        filterManager.setFilter("brand_id", `${i}`);
      }

      const startTime = performance.now();

      await filterManager.resetAllFilters();

      const endTime = performance.now();
      expect(endTime - startTime).toBeLessThan(100); // Менее 100мс
    });
  });
});

// Интеграционные тесты сброса
describe("Reset Integration Tests", () => {
  let filterManager;
  let mockCallback;

  beforeEach(() => {
    jest.clearAllMocks();

    mockCallback = jest.fn();
    filterManager = new FilterManager({
      apiBaseUrl: "/api",
      onFiltersChange: mockCallback,
    });

    filterManager.initCountryFilter("test-container");

    // Мокаем API ответы
    fetch.mockImplementation((url) => {
      if (url.includes("/countries.php")) {
        return Promise.resolve({
          ok: true,
          json: () =>
            Promise.resolve({
              success: true,
              data: [
                { id: 1, name: "Германия" },
                { id: 2, name: "Япония" },
              ],
            }),
        });
      }

      if (url.includes("/products-filter.php")) {
        return Promise.resolve({
          ok: true,
          json: () =>
            Promise.resolve({
              success: true,
              data: [],
              pagination: { total: 0 },
            }),
        });
      }

      return Promise.reject(new Error(`Unexpected URL: ${url}`));
    });
  });

  afterEach(() => {
    if (filterManager) {
      filterManager.destroy();
    }
  });

  test("Сценарий: Полный цикл с сбросом", async () => {
    // 1. Устанавливаем фильтры
    await filterManager.onBrandChange("1");
    await filterManager.onModelChange("1");
    filterManager.onCountryChange("1");

    expect(filterManager.hasActiveFilters()).toBe(true);

    // 2. Сбрасываем все
    await filterManager.resetAllFilters();

    expect(filterManager.hasActiveFilters()).toBe(false);
    expect(filterManager.countryFilter.getSelectedCountry()).toBeNull();

    // 3. Устанавливаем новые фильтры
    await filterManager.onBrandChange("2");
    filterManager.onCountryChange("2");

    expect(filterManager.getActiveFilters()).toEqual({
      brand_id: "2",
      country_id: "2",
    });
  });

  test("Сценарий: Частичный сброс и восстановление", async () => {
    // Устанавливаем фильтры
    await filterManager.onBrandChange("1");
    filterManager.onCountryChange("1");

    // Сбрасываем только страну
    await filterManager.resetCountryFilter();

    expect(filterManager.getFilter("brand_id")).toBe("1");
    expect(filterManager.getFilter("country_id")).toBeNull();

    // Выбираем новую страну
    filterManager.onCountryChange("2");

    expect(filterManager.getActiveFilters()).toEqual({
      brand_id: "1",
      country_id: "2",
    });
  });
});

module.exports = {
  // Экспортируем для использования в других тестах
  mockContainer,
  mockSelectElement,
  mockLoadingElement,
  mockErrorElement,
};
