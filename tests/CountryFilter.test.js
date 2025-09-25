/**
 * Unit тесты для компонента CountryFilter
 *
 * Проверяет основную функциональность фильтра по стране изготовления
 * Включает тесты для всех методов, обработки ошибок, кэширования и мобильной оптимизации
 *
 * @version 1.1
 * @author ZUZ System
 */

// Мок для fetch API с поддержкой AbortSignal
global.fetch = jest.fn();
global.AbortSignal = {
  timeout: jest.fn().mockReturnValue({ aborted: false }),
};

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
};

// Подключаем класс CountryFilter
const CountryFilter = require("../js/CountryFilter.js");

describe("CountryFilter", () => {
  let countryFilter;
  let mockOnChange;

  beforeEach(() => {
    // Сброс моков
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
        default:
          return null;
      }
    });

    mockOnChange = jest.fn();

    // Создание экземпляра
    countryFilter = new CountryFilter("test-container", mockOnChange);
  });

  afterEach(() => {
    if (countryFilter) {
      countryFilter.destroy();
    }
  });

  describe("Инициализация", () => {
    test("должен создаваться с корректными параметрами", () => {
      expect(countryFilter.container).toBe(mockContainer);
      expect(countryFilter.onChange).toBe(mockOnChange);
      expect(countryFilter.countries).toEqual([]);
      expect(countryFilter.selectedCountry).toBeNull();
    });

    test("должен создавать HTML структуру", () => {
      expect(mockContainer.innerHTML).toContain("country-filter");
      expect(mockContainer.innerHTML).toContain("country-select");
      expect(mockContainer.innerHTML).toContain("Страна изготовления");
    });

    test("должен привязывать обработчики событий", () => {
      expect(mockSelectElement.addEventListener).toHaveBeenCalledWith(
        "change",
        expect.any(Function)
      );
    });
  });

  describe("Загрузка стран", () => {
    test("должен загружать все страны", async () => {
      const mockCountries = [
        { id: 1, name: "Германия" },
        { id: 2, name: "Япония" },
      ];

      fetch.mockResolvedValueOnce({
        json: () =>
          Promise.resolve({
            success: true,
            data: mockCountries,
          }),
      });

      const result = await countryFilter.loadCountries();

      expect(fetch).toHaveBeenCalledWith("/api/countries.php");
      expect(result).toEqual(mockCountries);
      expect(countryFilter.countries).toEqual(mockCountries);
    });

    test("должен загружать страны для марки", async () => {
      const mockCountries = [{ id: 1, name: "Германия" }];

      fetch.mockResolvedValueOnce({
        json: () =>
          Promise.resolve({
            success: true,
            data: mockCountries,
          }),
      });

      const result = await countryFilter.loadCountriesForBrand(1);

      expect(fetch).toHaveBeenCalledWith(
        "/api/countries-by-brand.php?brand_id=1"
      );
      expect(result).toEqual(mockCountries);
    });

    test("должен загружать страны для модели", async () => {
      const mockCountries = [{ id: 1, name: "Германия" }];

      fetch.mockResolvedValueOnce({
        json: () =>
          Promise.resolve({
            success: true,
            data: mockCountries,
          }),
      });

      const result = await countryFilter.loadCountriesForModel(5);

      expect(fetch).toHaveBeenCalledWith(
        "/api/countries-by-model.php?model_id=5"
      );
      expect(result).toEqual(mockCountries);
    });

    test("должен обрабатывать ошибки API", async () => {
      fetch.mockResolvedValueOnce({
        json: () =>
          Promise.resolve({
            success: false,
            error: "Ошибка сервера",
          }),
      });

      const result = await countryFilter.loadCountries();

      expect(result).toEqual([]);
      expect(mockErrorElement.style.display).toBe("block");
    });

    test("должен обрабатывать сетевые ошибки", async () => {
      fetch.mockRejectedValueOnce(new Error("Network error"));

      const result = await countryFilter.loadCountries();

      expect(result).toEqual([]);
      expect(mockErrorElement.style.display).toBe("block");
    });
  });

  describe("Кэширование", () => {
    test("должен кэшировать результаты", async () => {
      const mockCountries = [{ id: 1, name: "Германия" }];

      fetch.mockResolvedValueOnce({
        json: () =>
          Promise.resolve({
            success: true,
            data: mockCountries,
          }),
      });

      // Первый запрос
      await countryFilter.loadCountries();
      expect(fetch).toHaveBeenCalledTimes(1);

      // Второй запрос должен использовать кэш
      await countryFilter.loadCountries();
      expect(fetch).toHaveBeenCalledTimes(1);
    });

    test("должен очищать кэш", () => {
      countryFilter.cache.set("test", { data: [], timestamp: Date.now() });
      expect(countryFilter.cache.size).toBe(1);

      countryFilter.clearCache();
      expect(countryFilter.cache.size).toBe(0);
    });
  });

  describe("Управление выбором", () => {
    test("должен устанавливать выбранную страну", () => {
      countryFilter.setSelectedCountry(1);
      expect(countryFilter.selectedCountry).toBe(1);
      expect(mockSelectElement.value).toBe(1);
    });

    test("должен возвращать выбранную страну", () => {
      countryFilter.selectedCountry = 1;
      expect(countryFilter.getSelectedCountry()).toBe(1);
    });

    test("должен сбрасывать выбор", () => {
      countryFilter.selectedCountry = 1;
      countryFilter.reset();

      expect(countryFilter.selectedCountry).toBeNull();
      expect(mockSelectElement.value).toBe("");
    });

    test("должен сбрасывать выбор с вызовом callback по умолчанию", () => {
      countryFilter.selectedCountry = 1;
      mockOnChange.mockClear();

      countryFilter.reset();

      expect(countryFilter.selectedCountry).toBeNull();
      expect(mockOnChange).toHaveBeenCalledWith(null);
    });

    test("должен сбрасывать выбор без вызова callback если указано", () => {
      countryFilter.selectedCountry = 1;
      mockOnChange.mockClear();

      countryFilter.reset(false);

      expect(countryFilter.selectedCountry).toBeNull();
      expect(mockOnChange).not.toHaveBeenCalled();
    });

    test("должен скрывать ошибки при сбросе", () => {
      countryFilter.showError("Тестовая ошибка");
      expect(mockErrorElement.style.display).toBe("block");

      countryFilter.reset();

      expect(mockErrorElement.style.display).toBe("none");
    });

    test("должен проверять наличие выбора", () => {
      expect(countryFilter.hasSelection()).toBe(false);

      countryFilter.selectedCountry = 1;
      expect(countryFilter.hasSelection()).toBe(true);
    });

    test("должен возвращать информацию о выбранной стране", () => {
      countryFilter.countries = [
        { id: 1, name: "Германия" },
        { id: 2, name: "Япония" },
      ];
      countryFilter.selectedCountry = 1;

      const info = countryFilter.getSelectedCountryInfo();
      expect(info).toEqual({ id: 1, name: "Германия" });
    });
  });

  describe("UI состояния", () => {
    test("должен показывать индикатор загрузки", () => {
      countryFilter.showLoading();

      expect(mockLoadingElement.style.display).toBe("block");
      expect(mockSelectElement.disabled).toBe(true);
    });

    test("должен скрывать индикатор загрузки", () => {
      countryFilter.hideLoading();

      expect(mockLoadingElement.style.display).toBe("none");
      expect(mockSelectElement.disabled).toBe(false);
    });

    test("должен показывать ошибки", () => {
      const errorMessage = "Тестовая ошибка";
      countryFilter.showError(errorMessage);

      expect(mockErrorElement.style.display).toBe("block");
    });

    test("должен скрывать ошибки", () => {
      countryFilter.hideError();

      expect(mockErrorElement.style.display).toBe("none");
    });
  });

  describe("Заполнение select элемента", () => {
    test("должен заполнять select странами", () => {
      const mockCountries = [
        { id: 1, name: "Германия" },
        { id: 2, name: "Япония" },
      ];

      countryFilter.populateSelect(mockCountries);

      expect(mockSelectElement.innerHTML).toContain("Все страны");
      expect(mockSelectElement.appendChild).toHaveBeenCalledTimes(2);
    });

    test("должен сохранять выбранное значение при обновлении", () => {
      const mockCountries = [{ id: 1, name: "Германия" }];
      countryFilter.selectedCountry = 1;

      countryFilter.populateSelect(mockCountries);

      expect(mockSelectElement.value).toBe(1);
    });

    test("должен сбрасывать выбор если страна недоступна", () => {
      const mockCountries = [{ id: 2, name: "Япония" }];
      countryFilter.selectedCountry = 1; // Германия

      countryFilter.populateSelect(mockCountries);

      expect(countryFilter.selectedCountry).toBeNull();
      expect(mockOnChange).toHaveBeenCalledWith(null);
    });
  });

  describe("Обработка событий", () => {
    test("должен вызывать callback при изменении", () => {
      countryFilter.selectedCountry = 1;
      countryFilter.triggerChange();

      expect(mockOnChange).toHaveBeenCalledWith(1);
    });

    test("должен обрабатывать изменение select элемента", () => {
      // Имитируем событие change
      const changeHandler = mockSelectElement.addEventListener.mock.calls[0][1];
      const mockEvent = { target: { value: "1" } };

      changeHandler(mockEvent);

      expect(countryFilter.selectedCountry).toBe("1");
      expect(mockOnChange).toHaveBeenCalledWith("1");
    });
  });

  describe("Уничтожение компонента", () => {
    test("должен корректно уничтожаться", () => {
      countryFilter.destroy();

      expect(mockSelectElement.removeEventListener).toHaveBeenCalled();
      expect(countryFilter.container).toBeNull();
      expect(countryFilter.selectElement).toBeNull();
      expect(countryFilter.countries).toEqual([]);
      expect(countryFilter.selectedCountry).toBeNull();
    });
  });

  describe("Утилитарные методы", () => {
    test("должен возвращать все загруженные страны", () => {
      const mockCountries = [{ id: 1, name: "Германия" }];
      countryFilter.countries = mockCountries;

      const result = countryFilter.getCountries();

      expect(result).toEqual(mockCountries);
      expect(result).not.toBe(mockCountries); // Должна быть копия
    });
  });

  describe("Обработка граничных случаев", () => {
    test("должен обрабатывать отсутствие контейнера", () => {
      document.getElementById.mockReturnValueOnce(null);

      const filter = new CountryFilter("non-existent", mockOnChange);

      // Не должно выбрасывать ошибку
      expect(filter.container).toBeNull();
    });

    test("должен обрабатывать пустые параметры", async () => {
      const result1 = await countryFilter.loadCountriesForBrand(null);
      const result2 = await countryFilter.loadCountriesForModel("");

      // Должны вызывать loadCountries()
      expect(fetch).toHaveBeenCalledWith("/api/countries.php");
    });

    test("должен обрабатывать некорректные данные API", async () => {
      fetch.mockResolvedValueOnce({
        json: () =>
          Promise.resolve({
            success: true,
            data: null,
          }),
      });

      const result = await countryFilter.loadCountries();
      expect(result).toEqual([]);
    });
  });
});

// Интеграционные тесты
describe("CountryFilter Integration", () => {
  test("должен интегрироваться с реальным DOM", () => {
    // Создаем реальный DOM элемент
    const container = document.createElement("div");
    container.id = "real-container";
    document.body.appendChild(container);

    // Переопределяем getElementById для возврата реального элемента
    const originalGetElementById = document.getElementById;
    document.getElementById = jest.fn().mockReturnValue(container);

    const filter = new CountryFilter("real-container", jest.fn());

    expect(container.innerHTML).toContain("country-filter");
    expect(container.querySelector("#country-select")).toBeTruthy();

    filter.destroy();
    document.body.removeChild(container);
    document.getElementById = originalGetElementById;
  });

  test("должен корректно работать с событиями", () => {
    const container = document.createElement("div");
    container.id = "event-container";
    document.body.appendChild(container);

    const originalGetElementById = document.getElementById;
    document.getElementById = jest.fn().mockReturnValue(container);

    const mockCallback = jest.fn();
    const filter = new CountryFilter("event-container", mockCallback);

    const select = container.querySelector("#country-select");
    expect(select).toBeTruthy();

    // Имитируем изменение значения
    select.value = "1";
    select.dispatchEvent(new Event("change"));

    expect(mockCallback).toHaveBeenCalledWith("1");

    filter.destroy();
    document.body.removeChild(container);
    document.getElementById = originalGetElementById;
  });
});

// Тесты производительности
describe("CountryFilter Performance", () => {
  test("должен эффективно обрабатывать большое количество стран", () => {
    const largeCountryList = Array.from({ length: 1000 }, (_, i) => ({
      id: i + 1,
      name: `Страна ${i + 1}`,
    }));

    const startTime = performance.now();
    countryFilter.populateSelect(largeCountryList);
    const endTime = performance.now();

    // Операция должна выполняться быстро (менее 100мс)
    expect(endTime - startTime).toBeLessThan(100);
  });

  test("должен эффективно работать с кэшем", async () => {
    const mockCountries = [{ id: 1, name: "Германия" }];

    fetch.mockResolvedValue({
      json: () =>
        Promise.resolve({
          success: true,
          data: mockCountries,
        }),
    });

    // Множественные запросы
    const promises = Array.from({ length: 10 }, () =>
      countryFilter.loadCountries()
    );
    await Promise.all(promises);

    // Должен быть только один реальный запрос к API
    expect(fetch).toHaveBeenCalledTimes(1);
  });
});

// Тесты валидации
describe("CountryFilter Validation", () => {
  test("должен валидировать ID параметры", () => {
    expect(countryFilter.validateId(1, "тест")).toBe(true);
    expect(countryFilter.validateId("1", "тест")).toBe(true);
    expect(countryFilter.validateId(0, "тест")).toBe(false);
    expect(countryFilter.validateId(-1, "тест")).toBe(false);
    expect(countryFilter.validateId("abc", "тест")).toBe(false);
    expect(countryFilter.validateId(null, "тест")).toBe(false);
    expect(countryFilter.validateId(undefined, "тест")).toBe(false);
    expect(countryFilter.validateId("", "тест")).toBe(false);
    expect(countryFilter.validateId(1000000, "тест")).toBe(false);
  });
});

// Тесты мобильной функциональности
describe("CountryFilter Mobile", () => {
  test("должен определять мобильные устройства", () => {
    // Мокаем navigator.userAgent для мобильного устройства
    Object.defineProperty(navigator, "userAgent", {
      writable: true,
      value: "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)",
    });

    const mobileFilter = new CountryFilter("test-container", jest.fn());
    expect(mobileFilter.isMobile).toBe(true);

    mobileFilter.destroy();
  });

  test("должен настраивать мобильные оптимизации", () => {
    Object.defineProperty(navigator, "userAgent", {
      writable: true,
      value: "Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)",
    });

    const mobileFilter = new CountryFilter("test-container", jest.fn());

    expect(mobileFilter.cacheTimeout).toBeGreaterThan(15 * 60 * 1000);
    expect(mobileFilter.debounceDelay).toBeGreaterThan(300);

    mobileFilter.destroy();
  });
});

// Тесты обработки ошибок
describe("CountryFilter Error Handling", () => {
  test("должен обрабатывать ошибки сети", async () => {
    fetch.mockRejectedValueOnce(new Error("Network error"));

    const result = await countryFilter.loadCountries();

    expect(result).toEqual([]);
    expect(mockErrorElement.style.display).toBe("block");
  });

  test("должен обрабатывать таймауты", async () => {
    const abortError = new Error("Timeout");
    abortError.name = "AbortError";
    fetch.mockRejectedValueOnce(abortError);

    const result = await countryFilter.loadCountries();

    expect(result).toEqual([]);
    expect(mockErrorElement.style.display).toBe("block");
  });

  test("должен обрабатывать HTTP ошибки", async () => {
    fetch.mockResolvedValueOnce({
      ok: false,
      status: 500,
      statusText: "Internal Server Error",
      json: () => Promise.resolve({}),
    });

    const result = await countryFilter.loadCountries();

    expect(result).toEqual([]);
    expect(mockErrorElement.style.display).toBe("block");
  });
});

// Тесты кэширования
describe("CountryFilter Caching", () => {
  test("должен управлять размером кэша", () => {
    // Заполняем кэш до максимума
    for (let i = 0; i < countryFilter.maxCacheSize + 5; i++) {
      countryFilter.setCacheData(`key_${i}`, { data: `value_${i}` });
    }

    expect(countryFilter.cache.size).toBeLessThanOrEqual(
      countryFilter.maxCacheSize
    );
  });

  test("должен очищать устаревший кэш", () => {
    // Добавляем устаревшую запись
    const oldTimestamp = Date.now() - (countryFilter.cacheTimeout + 1000);
    countryFilter.cache.set("old_key", {
      data: "old_data",
      timestamp: oldTimestamp,
    });

    countryFilter.cleanExpiredCache();

    expect(countryFilter.cache.has("old_key")).toBe(false);
  });

  test("должен возвращать статистику кэша", () => {
    countryFilter.setCacheData("valid_key", { data: "valid_data" });

    const stats = countryFilter.getCacheStats();

    expect(stats).toHaveProperty("totalEntries");
    expect(stats).toHaveProperty("validEntries");
    expect(stats).toHaveProperty("expiredEntries");
    expect(stats).toHaveProperty("maxSize");
    expect(stats).toHaveProperty("cacheTimeout");
  });
});

// Тесты retry функциональности
describe("CountryFilter Retry", () => {
  test("должен повторять запросы при ошибках", async () => {
    fetch
      .mockRejectedValueOnce(new Error("Network error"))
      .mockResolvedValueOnce({
        ok: true,
        json: () =>
          Promise.resolve({
            success: true,
            data: [{ id: 1, name: "Германия" }],
          }),
      });

    const result = await countryFilter.loadCountries();

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(result).toEqual([{ id: 1, name: "Германия" }]);
  });
});

// Тесты API доступности
describe("CountryFilter API Availability", () => {
  test("должен проверять доступность API", async () => {
    fetch.mockResolvedValueOnce({ ok: true });

    const isAvailable = await countryFilter.checkApiAvailability();

    expect(isAvailable).toBe(true);
    expect(fetch).toHaveBeenCalledWith("/api/countries.php", {
      method: "HEAD",
      headers: { Accept: "application/json" },
      signal: expect.any(Object),
    });
  });

  test("должен обрабатывать недоступность API", async () => {
    fetch.mockRejectedValueOnce(new Error("Network error"));

    const isAvailable = await countryFilter.checkApiAvailability();

    expect(isAvailable).toBe(false);
  });
});

module.exports = {
  CountryFilter,
};
