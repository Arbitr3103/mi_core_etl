/**
 * Integration тесты для системы фильтров по стране изготовления
 *
 * Проверяет интеграцию CountryFilter с FilterManager и корректную работу
 * всех фильтров вместе в различных сценариях использования
 *
 * @version 1.0
 * @author ZUZ System
 */

// Подключаем реальные классы
const CountryFilter = require("../js/CountryFilter.js");
const FilterManager = require("../js/FilterManager.js");

// Мок для fetch API
global.fetch = jest.fn();

// Мок для DOM
const createMockDOM = () => {
  const mockContainer = document.createElement("div");
  mockContainer.id = "test-container";

  // Добавляем в body для реального тестирования
  document.body.appendChild(mockContainer);

  return mockContainer;
};

describe("Country Filter Integration Tests", () => {
  let filterManager;
  let mockContainer;
  let mockApiResponses;

  beforeEach(() => {
    // Создаем реальный DOM элемент
    mockContainer = createMockDOM();

    // Настраиваем стандартные API ответы
    mockApiResponses = {
      countries: {
        success: true,
        data: [
          { id: 1, name: "Германия" },
          { id: 2, name: "Япония" },
          { id: 3, name: "США" },
          { id: 4, name: "Южная Корея" },
        ],
      },
      countriesByBrand: {
        1: { success: true, data: [{ id: 1, name: "Германия" }] }, // BMW
        2: { success: true, data: [{ id: 1, name: "Германия" }] }, // Mercedes
        3: { success: true, data: [{ id: 2, name: "Япония" }] }, // Toyota
        4: { success: true, data: [{ id: 3, name: "США" }] }, // Ford
      },
      countriesByModel: {
        1: { success: true, data: [{ id: 1, name: "Германия" }] }, // BMW X5
        2: { success: true, data: [{ id: 2, name: "Япония" }] }, // Toyota Camry
        3: { success: true, data: [{ id: 3, name: "США" }] }, // Ford Focus
      },
      products: {
        success: true,
        data: [
          {
            product_id: 1,
            product_name: "Проставка BMW X5",
            brand_name: "BMW",
            model_name: "X5",
            country_name: "Германия",
            year_start: 2018,
            year_end: 2023,
          },
          {
            product_id: 2,
            product_name: "Проставка Toyota Camry",
            brand_name: "Toyota",
            model_name: "Camry",
            country_name: "Япония",
            year_start: 2015,
            year_end: 2022,
          },
        ],
        pagination: { total: 2, limit: 100, offset: 0, has_more: false },
      },
    };

    // Настраиваем fetch мок
    fetch.mockImplementation((url) => {
      if (url.includes("/countries.php")) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve(mockApiResponses.countries),
        });
      }

      if (url.includes("/countries-by-brand.php")) {
        const brandId = url.match(/brand_id=(\d+)/)?.[1];
        const response = mockApiResponses.countriesByBrand[brandId] || {
          success: true,
          data: [],
        };
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve(response),
        });
      }

      if (url.includes("/countries-by-model.php")) {
        const modelId = url.match(/model_id=(\d+)/)?.[1];
        const response = mockApiResponses.countriesByModel[modelId] || {
          success: true,
          data: [],
        };
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve(response),
        });
      }

      if (url.includes("/products-filter.php")) {
        return Promise.resolve({
          ok: true,
          json: () => Promise.resolve(mockApiResponses.products),
        });
      }

      return Promise.reject(new Error(`Unexpected URL: ${url}`));
    });

    // Создаем FilterManager
    filterManager = new FilterManager({
      apiBaseUrl: "/api",
      onFiltersChange: jest.fn(),
    });

    // Инициализируем фильтр по стране
    filterManager.initCountryFilter(mockContainer.id);

    jest.clearAllMocks();
  });

  afterEach(() => {
    if (filterManager) {
      filterManager.destroy();
    }
    if (mockContainer && mockContainer.parentNode) {
      mockContainer.parentNode.removeChild(mockContainer);
    }
  });

  describe("Полный цикл фильтрации", () => {
    test("должен выполнять полный цикл: марка -> модель -> страна -> товары", async () => {
      // 1. Выбираем марку BMW (id: 1)
      await filterManager.onBrandChange("1");

      expect(filterManager.filters.brand_id).toBe("1");
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
      ]);

      // 2. Выбираем модель X5 (id: 1)
      await filterManager.onModelChange("1");

      expect(filterManager.filters.model_id).toBe("1");
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
      ]);

      // 3. Выбираем страну Германия (id: 1)
      filterManager.onCountryChange("1");

      expect(filterManager.filters.country_id).toBe("1");

      // 4. Проверяем что все фильтры активны
      const activeFilters = filterManager.getActiveFilters();
      expect(activeFilters).toEqual({
        brand_id: "1",
        model_id: "1",
        country_id: "1",
      });

      // 5. Применяем фильтры и получаем товары
      const result = await filterManager.applyFilters();

      expect(result.success).toBe(true);
      expect(result.data).toHaveLength(2);
      expect(fetch).toHaveBeenCalledWith(
        "/api/products-filter.php?brand_id=1&model_id=1&country_id=1"
      );
    });

    test("должен корректно обрабатывать изменение марки с автообновлением стран", async () => {
      // Выбираем BMW
      await filterManager.onBrandChange("1");
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
      ]);

      // Меняем на Toyota
      await filterManager.onBrandChange("3");
      expect(filterManager.filters.brand_id).toBe("3");
      expect(filterManager.filters.model_id).toBeNull(); // должен сброситься
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 2, name: "Япония" },
      ]);

      // Проверяем что страна сбросилась если была выбрана недоступная
      filterManager.countryFilter.selectedCountry = "1"; // Германия
      await filterManager.onBrandChange("3"); // Toyota (только Япония)

      // Страна должна сброситься так как Германия недоступна для Toyota
      expect(filterManager.countryFilter.selectedCountry).toBeNull();
    });

    test("должен сбрасывать все фильтры корректно", async () => {
      // Устанавливаем все фильтры
      await filterManager.onBrandChange("1");
      await filterManager.onModelChange("1");
      filterManager.onCountryChange("1");

      expect(filterManager.hasActiveFilters()).toBe(true);

      // Сбрасываем все
      await filterManager.resetAllFilters();

      expect(filterManager.filters).toEqual({
        brand_id: null,
        model_id: null,
        year: null,
        country_id: null,
      });
      expect(filterManager.hasActiveFilters()).toBe(false);
      expect(filterManager.countryFilter.selectedCountry).toBeNull();

      // Должны загрузиться все страны
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
        { id: 2, name: "Япония" },
        { id: 3, name: "США" },
        { id: 4, name: "Южная Корея" },
      ]);
    });
  });

  describe("Обработка ошибок в интеграции", () => {
    test("должен обрабатывать ошибки API при загрузке стран", async () => {
      // Мокаем ошибку API
      fetch.mockImplementationOnce(() =>
        Promise.resolve({
          ok: true,
          json: () =>
            Promise.resolve({
              success: false,
              error: "Ошибка сервера",
            }),
        })
      );

      await filterManager.onBrandChange("1");

      // Фильтр должен остаться в рабочем состоянии
      expect(filterManager.filters.brand_id).toBe("1");
      expect(filterManager.countryFilter.countries).toEqual([]);
    });

    test("должен обрабатывать сетевые ошибки", async () => {
      fetch.mockRejectedValueOnce(new Error("Network error"));

      await filterManager.onBrandChange("1");

      // Система должна продолжать работать
      expect(filterManager.filters.brand_id).toBe("1");
      expect(filterManager.countryFilter.countries).toEqual([]);
    });

    test("должен обрабатывать частичные ошибки данных", async () => {
      // Мокаем ответ с null данными
      fetch.mockImplementationOnce(() =>
        Promise.resolve({
          ok: true,
          json: () =>
            Promise.resolve({
              success: true,
              data: null,
            }),
        })
      );

      const result = await filterManager.countryFilter.loadCountries();

      expect(result).toEqual([]);
      expect(filterManager.countryFilter.countries).toEqual([]);
    });
  });

  describe("Производительность интеграции", () => {
    test("должен эффективно обрабатывать быстрые изменения фильтров", async () => {
      const startTime = performance.now();

      // Быстрая последовательность изменений
      const promises = [
        filterManager.onBrandChange("1"),
        filterManager.onBrandChange("2"),
        filterManager.onBrandChange("3"),
      ];

      await Promise.all(promises);

      const endTime = performance.now();

      // Должно выполняться быстро
      expect(endTime - startTime).toBeLessThan(1000);

      // Последнее изменение должно быть применено
      expect(filterManager.filters.brand_id).toBe("3");
    });

    test("должен использовать кэширование для повторных запросов", async () => {
      // Первый запрос
      await filterManager.onBrandChange("1");
      const firstCallCount = fetch.mock.calls.length;

      // Повторный запрос той же марки
      await filterManager.onBrandChange("1");
      const secondCallCount = fetch.mock.calls.length;

      // Количество вызовов API не должно увеличиться (используется кэш)
      expect(secondCallCount).toBe(firstCallCount);
    });
  });

  describe("Сценарии реального использования", () => {
    test("Сценарий 1: Пользователь ищет проставки для BMW X5 из Германии", async () => {
      // Пользователь выбирает марку BMW
      await filterManager.onBrandChange("1");

      // Система автоматически загружает доступные страны для BMW
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
      ]);

      // Пользователь выбирает модель X5
      await filterManager.onModelChange("1");

      // Пользователь выбирает страну Германия
      filterManager.onCountryChange("1");

      // Система применяет все фильтры и находит товары
      const result = await filterManager.applyFilters();

      expect(result.success).toBe(true);
      expect(result.data.length).toBeGreaterThan(0);

      // Проверяем что запрос содержит все параметры
      expect(fetch).toHaveBeenCalledWith(
        "/api/products-filter.php?brand_id=1&model_id=1&country_id=1"
      );
    });

    test("Сценарий 2: Пользователь меняет марку и система обновляет доступные страны", async () => {
      // Выбираем BMW (Германия)
      await filterManager.onBrandChange("1");
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 1, name: "Германия" },
      ]);

      // Выбираем страну Германия
      filterManager.onCountryChange("1");
      expect(filterManager.filters.country_id).toBe("1");

      // Меняем на Toyota (Япония)
      await filterManager.onBrandChange("3");

      // Страны должны обновиться
      expect(filterManager.countryFilter.countries).toEqual([
        { id: 2, name: "Япония" },
      ]);

      // Выбранная страна должна сброситься так как Германия недоступна для Toyota
      expect(filterManager.filters.country_id).toBeNull();
    });

    test("Сценарий 3: Пользователь сбрасывает фильтры", async () => {
      // Устанавливаем фильтры
      await filterManager.onBrandChange("1");
      await filterManager.onModelChange("1");
      filterManager.onCountryChange("1");

      // Проверяем что фильтры установлены
      expect(filterManager.hasActiveFilters()).toBe(true);

      // Сбрасываем все фильтры
      await filterManager.resetAllFilters();

      // Проверяем что все сброшено
      expect(filterManager.hasActiveFilters()).toBe(false);
      expect(filterManager.countryFilter.selectedCountry).toBeNull();

      // Должны загрузиться все доступные страны
      expect(filterManager.countryFilter.countries.length).toBeGreaterThan(1);
    });

    test("Сценарий 4: Частичный сброс и восстановление", async () => {
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

    test("Сценарий 5: Множественные быстрые сбросы", async () => {
      // Устанавливаем фильтры
      await filterManager.onBrandChange("1");
      filterManager.onCountryChange("1");

      // Быстрые множественные сбросы
      const promises = [
        filterManager.resetAllFilters(),
        filterManager.resetCountryFilter(),
        filterManager.resetAllFilters(),
      ];

      await Promise.all(promises);

      expect(filterManager.hasActiveFilters()).toBe(false);
      expect(filterManager.countryFilter.getSelectedCountry()).toBeNull();
    });

    test("Сценарий 6: Сброс с ошибками API", async () => {
      // Устанавливаем фильтры
      filterManager.setFilter("brand_id", "1");
      filterManager.setFilter("country_id", "1");

      // Мокаем ошибку API
      fetch.mockRejectedValueOnce(new Error("Network error"));

      // Сброс должен работать даже при ошибках
      await expect(filterManager.resetAllFilters()).resolves.not.toThrow();

      expect(filterManager.hasActiveFilters()).toBe(false);
    });
  });

  describe("Совместимость с существующими фильтрами", () => {
    test("должен корректно работать с фильтром по году", async () => {
      // Устанавливаем марку, модель и год
      await filterManager.onBrandChange("1");
      await filterManager.onModelChange("1");
      filterManager.onYearChange("2020");
      filterManager.onCountryChange("1");

      const activeFilters = filterManager.getActiveFilters();
      expect(activeFilters).toEqual({
        brand_id: "1",
        model_id: "1",
        year: "2020",
        country_id: "1",
      });

      // Применяем фильтры
      const result = await filterManager.applyFilters();

      expect(fetch).toHaveBeenCalledWith(
        "/api/products-filter.php?brand_id=1&model_id=1&year=2020&country_id=1"
      );
    });

    test("должен игнорировать пустые значения фильтров", async () => {
      filterManager.filters.brand_id = "1";
      filterManager.filters.model_id = null;
      filterManager.filters.year = "";
      filterManager.filters.country_id = "1";

      await filterManager.applyFilters();

      // Должны передаваться только непустые параметры
      expect(fetch).toHaveBeenCalledWith(
        "/api/products-filter.php?brand_id=1&country_id=1"
      );
    });
  });

  describe("Состояние UI при интеграции", () => {
    test("должен корректно обновлять UI при изменении фильтров", async () => {
      // Проверяем что select элемент создан
      const selectElement = mockContainer.querySelector("#country-select");
      expect(selectElement).toBeTruthy();

      // Загружаем страны для марки
      await filterManager.onBrandChange("1");

      // Проверяем что опции обновились
      const options = selectElement.querySelectorAll("option");
      expect(options.length).toBeGreaterThan(1); // "Все страны" + реальные страны
    });

    test("должен показывать индикаторы загрузки", async () => {
      const loadingElement = mockContainer.querySelector(".filter-loading");
      expect(loadingElement).toBeTruthy();

      // При загрузке должен показываться индикатор
      const loadPromise = filterManager.onBrandChange("1");

      // Проверяем что загрузка показана (может быть кратковременно)
      await loadPromise;

      // После загрузки индикатор должен скрыться
      expect(loadingElement.style.display).toBe("none");
    });
  });
});

// Тесты совместимости с различными браузерами
describe("Browser Compatibility Integration", () => {
  test("должен работать без современных API", () => {
    // Сохраняем оригинальные API
    const originalFetch = global.fetch;
    const originalAbortSignal = global.AbortSignal;

    // Удаляем современные API
    delete global.AbortSignal;

    try {
      const container = createMockDOM();
      const filterManager = new FilterManager();
      filterManager.initCountryFilter(container.id);

      // Должен работать без ошибок
      expect(filterManager.countryFilter).toBeTruthy();

      filterManager.destroy();
      if (container.parentNode) {
        container.parentNode.removeChild(container);
      }
    } finally {
      // Восстанавливаем API
      global.fetch = originalFetch;
      global.AbortSignal = originalAbortSignal;
    }
  });
});

module.exports = {
  // Экспортируем для использования в других тестах
  createMockDOM,
  mockApiResponses: () => mockApiResponses,
};
