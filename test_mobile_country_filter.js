/**
 * Тестирование мобильной адаптации фильтра по стране изготовления
 *
 * Этот скрипт тестирует:
 * 1. Адаптацию для мобильных устройств
 * 2. Удобство использования на сенсорных экранах
 * 3. Оптимизацию времени загрузки для мобильных устройств
 *
 * @version 1.0
 * @author ZUZ System
 */

class MobileCountryFilterTester {
  constructor() {
    this.testResults = {
      mobileAdaptation: [],
      touchUsability: [],
      loadingOptimization: [],
    };

    this.performanceMetrics = {
      loadTimes: [],
      responseTimes: [],
      memoryUsage: [],
      cacheEfficiency: 0,
    };
  }

  /**
   * Запуск всех тестов
   */
  async runAllTests() {
    console.log("🧪 Начало тестирования мобильной адаптации CountryFilter");

    try {
      await this.testMobileAdaptation();
      await this.testTouchUsability();
      await this.testLoadingOptimization();

      this.generateReport();
    } catch (error) {
      console.error("❌ Ошибка при выполнении тестов:", error);
    }
  }

  /**
   * Тест 1: Адаптация для мобильных устройств
   */
  async testMobileAdaptation() {
    console.log("📱 Тестирование адаптации для мобильных устройств...");

    const tests = [
      this.testMobileDetection(),
      this.testResponsiveLayout(),
      this.testMobileClasses(),
      this.testViewportHandling(),
      this.testOrientationChange(),
    ];

    const results = await Promise.allSettled(tests);

    results.forEach((result, index) => {
      if (result.status === "fulfilled") {
        this.testResults.mobileAdaptation.push(result.value);
      } else {
        this.testResults.mobileAdaptation.push({
          test: `Mobile Adaptation Test ${index + 1}`,
          status: "failed",
          error: result.reason.message,
        });
      }
    });
  }

  /**
   * Тест определения мобильного устройства
   */
  async testMobileDetection() {
    const startTime = performance.now();

    // Создаем временный контейнер
    const container = document.createElement("div");
    container.id = "test-mobile-detection";
    document.body.appendChild(container);

    try {
      // Имитируем мобильный user agent
      const originalUserAgent = navigator.userAgent;
      Object.defineProperty(navigator, "userAgent", {
        value:
          "Mozilla/5.0 (iPhone; CPU iPhone OS 14_7_1 like Mac OS X) AppleWebKit/605.1.15",
        configurable: true,
      });

      const filter = new CountryFilter("test-mobile-detection", () => {});

      const isMobileDetected = filter.isMobile;
      const hasTouchSupport = filter.isTouch;

      // Восстанавливаем оригинальный user agent
      Object.defineProperty(navigator, "userAgent", {
        value: originalUserAgent,
        configurable: true,
      });

      const endTime = performance.now();

      return {
        test: "Mobile Device Detection",
        status: isMobileDetected ? "passed" : "failed",
        details: {
          mobileDetected: isMobileDetected,
          touchSupport: hasTouchSupport,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест адаптивного макета
   */
  async testResponsiveLayout() {
    const startTime = performance.now();

    const container = document.createElement("div");
    container.id = "test-responsive-layout";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-responsive-layout", () => {});

      // Тестируем различные размеры экрана
      const screenSizes = [
        { width: 320, height: 568, name: "iPhone SE" },
        { width: 375, height: 667, name: "iPhone 8" },
        { width: 414, height: 896, name: "iPhone 11" },
        { width: 768, height: 1024, name: "iPad" },
      ];

      const layoutTests = [];

      for (const size of screenSizes) {
        // Имитируем изменение размера экрана
        Object.defineProperty(window, "innerWidth", {
          value: size.width,
          configurable: true,
        });
        Object.defineProperty(window, "innerHeight", {
          value: size.height,
          configurable: true,
        });

        // Проверяем адаптацию
        filter.handleMobileResize();

        const filterElement = container.querySelector(".country-filter");
        const hasCompactClass = filterElement?.classList.contains(
          "country-filter--mobile-compact"
        );
        const selectElement = container.querySelector(".filter-select");
        const selectHeight = selectElement ? selectElement.offsetHeight : 0;

        layoutTests.push({
          screenSize: size.name,
          dimensions: `${size.width}x${size.height}`,
          compactMode: hasCompactClass,
          selectHeight: selectHeight,
          minTouchTarget: selectHeight >= 44, // Минимальный размер для touch
        });
      }

      const endTime = performance.now();

      return {
        test: "Responsive Layout",
        status: layoutTests.every((t) => t.minTouchTarget)
          ? "passed"
          : "failed",
        details: {
          screenTests: layoutTests,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест мобильных CSS классов
   */
  async testMobileClasses() {
    const startTime = performance.now();

    const container = document.createElement("div");
    container.id = "test-mobile-classes";
    document.body.appendChild(container);

    try {
      // Имитируем мобильное устройство
      Object.defineProperty(window, "innerWidth", {
        value: 375,
        configurable: true,
      });

      const filter = new CountryFilter("test-mobile-classes", () => {});

      const filterElement = container.querySelector(".country-filter");
      const hasMobileClass = filterElement?.classList.contains(
        "country-filter--mobile"
      );
      const selectElement = container.querySelector(".filter-select");
      const hasMobileAttribute = selectElement?.hasAttribute("data-mobile");

      const endTime = performance.now();

      return {
        test: "Mobile CSS Classes",
        status: hasMobileClass || hasMobileAttribute ? "passed" : "failed",
        details: {
          mobileClass: hasMobileClass,
          mobileAttribute: hasMobileAttribute,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест обработки viewport
   */
  async testViewportHandling() {
    const startTime = performance.now();

    const container = document.createElement("div");
    container.id = "test-viewport-handling";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-viewport-handling", () => {});

      // Проверяем наличие viewport meta tag
      const viewportMeta = document.querySelector('meta[name="viewport"]');
      const hasViewport = !!viewportMeta;
      const viewportContent = viewportMeta?.getAttribute("content") || "";

      const endTime = performance.now();

      return {
        test: "Viewport Handling",
        status: hasViewport ? "passed" : "failed",
        details: {
          hasViewportMeta: hasViewport,
          viewportContent: viewportContent,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест изменения ориентации
   */
  async testOrientationChange() {
    const startTime = performance.now();

    const container = document.createElement("div");
    container.id = "test-orientation-change";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-orientation-change", () => {});

      // Имитируем изменение ориентации
      const originalWidth = window.innerWidth;
      const originalHeight = window.innerHeight;

      // Portrait -> Landscape
      Object.defineProperty(window, "innerWidth", {
        value: 896,
        configurable: true,
      });
      Object.defineProperty(window, "innerHeight", {
        value: 414,
        configurable: true,
      });

      // Вызываем обработчик изменения размера
      filter.handleMobileResize();

      // Проверяем адаптацию
      const filterElement = container.querySelector(".country-filter");
      const adaptedToLandscape = !filterElement?.classList.contains(
        "country-filter--mobile-compact"
      );

      // Восстанавливаем размеры
      Object.defineProperty(window, "innerWidth", {
        value: originalWidth,
        configurable: true,
      });
      Object.defineProperty(window, "innerHeight", {
        value: originalHeight,
        configurable: true,
      });

      const endTime = performance.now();

      return {
        test: "Orientation Change",
        status: adaptedToLandscape ? "passed" : "failed",
        details: {
          landscapeAdaptation: adaptedToLandscape,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест 2: Удобство использования на сенсорных экранах
   */
  async testTouchUsability() {
    console.log(
      "👆 Тестирование удобства использования на сенсорных экранах..."
    );

    const tests = [
      this.testTouchTargetSize(),
      this.testTouchEvents(),
      this.testTouchFeedback(),
      this.testDoubleTapPrevention(),
      this.testScrollBehavior(),
    ];

    const results = await Promise.allSettled(tests);

    results.forEach((result, index) => {
      if (result.status === "fulfilled") {
        this.testResults.touchUsability.push(result.value);
      } else {
        this.testResults.touchUsability.push({
          test: `Touch Usability Test ${index + 1}`,
          status: "failed",
          error: result.reason.message,
        });
      }
    });
  }

  /**
   * Тест размера touch targets
   */
  async testTouchTargetSize() {
    const startTime = performance.now();

    const container = document.createElement("div");
    container.id = "test-touch-target-size";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-touch-target-size", () => {});

      const selectElement = container.querySelector(".filter-select");
      const retryButton = container.querySelector(".retry-button");

      const selectRect = selectElement?.getBoundingClientRect();
      const buttonRect = retryButton?.getBoundingClientRect();

      const selectTouchSize = selectRect
        ? Math.min(selectRect.width, selectRect.height)
        : 0;
      const buttonTouchSize = buttonRect
        ? Math.min(buttonRect.width, buttonRect.height)
        : 0;

      const minTouchSize = 44; // Рекомендуемый минимум для iOS

      const endTime = performance.now();

      return {
        test: "Touch Target Size",
        status:
          selectTouchSize >= minTouchSize && buttonTouchSize >= 32
            ? "passed"
            : "failed",
        details: {
          selectSize: selectTouchSize,
          buttonSize: buttonTouchSize,
          minRequirement: minTouchSize,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест touch событий
   */
  async testTouchEvents() {
    const startTime = performance.now();

    const container = document.createElement("div");
    container.id = "test-touch-events";
    document.body.appendChild(container);

    try {
      let touchStartCalled = false;
      let touchEndCalled = false;

      const filter = new CountryFilter("test-touch-events", () => {});
      const selectElement = container.querySelector(".filter-select");

      // Добавляем временные обработчики для тестирования
      const originalHandleTouchStart = filter.handleTouchStart;
      const originalHandleTouchEnd = filter.handleTouchEnd;

      filter.handleTouchStart = function (event) {
        touchStartCalled = true;
        if (originalHandleTouchStart) {
          originalHandleTouchStart.call(this, event);
        }
      };

      filter.handleTouchEnd = function (event) {
        touchEndCalled = true;
        if (originalHandleTouchEnd) {
          originalHandleTouchEnd.call(this, event);
        }
      };

      // Имитируем touch события
      if (selectElement) {
        const touchStartEvent = new TouchEvent("touchstart", {
          touches: [{ clientX: 100, clientY: 100 }],
          bubbles: true,
        });

        const touchEndEvent = new TouchEvent("touchend", {
          touches: [],
          bubbles: true,
        });

        selectElement.dispatchEvent(touchStartEvent);
        selectElement.dispatchEvent(touchEndEvent);
      }

      const endTime = performance.now();

      return {
        test: "Touch Events",
        status: touchStartCalled && touchEndCalled ? "passed" : "failed",
        details: {
          touchStartHandled: touchStartCalled,
          touchEndHandled: touchEndCalled,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест визуальной обратной связи при touch
   */
  async testTouchFeedback() {
    const startTime = performance.now();

    const container = document.createElement("div");
    container.id = "test-touch-feedback";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-touch-feedback", () => {});
      const selectElement = container.querySelector(".filter-select");

      let feedbackProvided = false;

      // Проверяем наличие CSS transitions
      if (selectElement) {
        const computedStyle = getComputedStyle(selectElement);
        const hasTransition =
          computedStyle.transition !== "none" &&
          computedStyle.transition !== "";

        // Проверяем focus стили
        selectElement.focus();
        const focusStyle = getComputedStyle(selectElement);
        const hasFocusStyles =
          focusStyle.borderColor !== computedStyle.borderColor ||
          focusStyle.boxShadow !== "none";

        feedbackProvided = hasTransition || hasFocusStyles;
      }

      const endTime = performance.now();

      return {
        test: "Touch Feedback",
        status: feedbackProvided ? "passed" : "failed",
        details: {
          visualFeedback: feedbackProvided,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест предотвращения двойного тапа
   */
  async testDoubleTapPrevention() {
    const startTime = performance.now();

    const container = document.createElement("div");
    container.id = "test-double-tap-prevention";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-double-tap-prevention", () => {});
      const selectElement = container.querySelector(".filter-select");

      let preventDefaultCalled = false;

      // Переопределяем preventDefault для тестирования
      const originalPreventDefault = Event.prototype.preventDefault;
      Event.prototype.preventDefault = function () {
        preventDefaultCalled = true;
        originalPreventDefault.call(this);
      };

      // Имитируем быстрые последовательные touch события
      if (selectElement) {
        const now = Date.now();
        filter.lastTouchEnd = now - 200; // Устанавливаем предыдущий touch

        const touchEvent = new TouchEvent("touchstart", {
          touches: [{ clientX: 100, clientY: 100 }],
          bubbles: true,
        });

        filter.handleTouchStart(touchEvent);
      }

      // Восстанавливаем оригинальный preventDefault
      Event.prototype.preventDefault = originalPreventDefault;

      const endTime = performance.now();

      return {
        test: "Double Tap Prevention",
        status: preventDefaultCalled ? "passed" : "warning",
        details: {
          preventionImplemented: preventDefaultCalled,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест поведения скролла
   */
  async testScrollBehavior() {
    const startTime = performance.now();

    const container = document.createElement("div");
    container.id = "test-scroll-behavior";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-scroll-behavior", () => {});
      const selectElement = container.querySelector(".filter-select");

      let hasScrollOptimization = false;

      if (selectElement) {
        const computedStyle = getComputedStyle(selectElement);
        hasScrollOptimization =
          computedStyle.webkitOverflowScrolling === "touch" ||
          computedStyle.overflowScrolling === "touch";
      }

      const endTime = performance.now();

      return {
        test: "Scroll Behavior",
        status: hasScrollOptimization ? "passed" : "warning",
        details: {
          scrollOptimization: hasScrollOptimization,
          executionTime: Math.round(endTime - startTime),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест 3: Оптимизация времени загрузки для мобильных устройств
   */
  async testLoadingOptimization() {
    console.log("⚡ Тестирование оптимизации времени загрузки...");

    const tests = [
      this.testInitializationTime(),
      this.testCacheEfficiency(),
      this.testNetworkOptimization(),
      this.testMemoryUsage(),
      this.testConnectionAdaptation(),
    ];

    const results = await Promise.allSettled(tests);

    results.forEach((result, index) => {
      if (result.status === "fulfilled") {
        this.testResults.loadingOptimization.push(result.value);
      } else {
        this.testResults.loadingOptimization.push({
          test: `Loading Optimization Test ${index + 1}`,
          status: "failed",
          error: result.reason.message,
        });
      }
    });
  }

  /**
   * Тест времени инициализации
   */
  async testInitializationTime() {
    const container = document.createElement("div");
    container.id = "test-initialization-time";
    document.body.appendChild(container);

    try {
      const iterations = 5;
      const times = [];

      for (let i = 0; i < iterations; i++) {
        const startTime = performance.now();
        const filter = new CountryFilter("test-initialization-time", () => {});
        const endTime = performance.now();

        times.push(endTime - startTime);

        // Очищаем для следующей итерации
        container.innerHTML = "";
      }

      const avgTime = times.reduce((a, b) => a + b, 0) / times.length;
      const maxAcceptableTime = 100; // 100ms максимум для инициализации

      return {
        test: "Initialization Time",
        status: avgTime <= maxAcceptableTime ? "passed" : "failed",
        details: {
          averageTime: Math.round(avgTime),
          maxTime: Math.max(...times),
          minTime: Math.min(...times),
          maxAcceptable: maxAcceptableTime,
          iterations: iterations,
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест эффективности кэша
   */
  async testCacheEfficiency() {
    const container = document.createElement("div");
    container.id = "test-cache-efficiency";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-cache-efficiency", () => {});

      // Настраиваем мок API
      let apiCallCount = 0;
      const originalFetch = window.fetch;

      window.fetch = async function (url) {
        apiCallCount++;
        return {
          ok: true,
          json: () =>
            Promise.resolve({
              success: true,
              data: [{ id: 1, name: "Test Country" }],
            }),
        };
      };

      // Первый запрос (должен вызвать API)
      const startTime1 = performance.now();
      await filter.loadCountries();
      const endTime1 = performance.now();

      // Второй запрос (должен использовать кэш)
      const startTime2 = performance.now();
      await filter.loadCountries();
      const endTime2 = performance.now();

      // Восстанавливаем fetch
      window.fetch = originalFetch;

      const firstCallTime = endTime1 - startTime1;
      const secondCallTime = endTime2 - startTime2;
      const cacheEfficiency =
        ((firstCallTime - secondCallTime) / firstCallTime) * 100;

      return {
        test: "Cache Efficiency",
        status:
          apiCallCount === 1 && cacheEfficiency > 50 ? "passed" : "failed",
        details: {
          apiCalls: apiCallCount,
          firstCallTime: Math.round(firstCallTime),
          secondCallTime: Math.round(secondCallTime),
          cacheEfficiency: Math.round(cacheEfficiency),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест сетевой оптимизации
   */
  async testNetworkOptimization() {
    const container = document.createElement("div");
    container.id = "test-network-optimization";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-network-optimization", () => {});

      // Проверяем настройки таймаута для мобильных
      const isMobileOptimized =
        filter.isMobile && filter.cacheTimeout > 15 * 60 * 1000;
      const hasDebouncing = filter.debounceDelay > 0;
      const hasRetryLogic = typeof filter.fetchWithRetry === "function";

      return {
        test: "Network Optimization",
        status:
          isMobileOptimized && hasDebouncing && hasRetryLogic
            ? "passed"
            : "failed",
        details: {
          mobileOptimized: isMobileOptimized,
          debouncing: hasDebouncing,
          retryLogic: hasRetryLogic,
          cacheTimeout: filter.cacheTimeout,
          debounceDelay: filter.debounceDelay,
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест использования памяти
   */
  async testMemoryUsage() {
    const container = document.createElement("div");
    container.id = "test-memory-usage";
    document.body.appendChild(container);

    try {
      const initialMemory = performance.memory
        ? performance.memory.usedJSHeapSize
        : 0;

      // Создаем несколько экземпляров фильтра
      const filters = [];
      for (let i = 0; i < 10; i++) {
        const testContainer = document.createElement("div");
        testContainer.id = `test-memory-${i}`;
        container.appendChild(testContainer);

        filters.push(new CountryFilter(`test-memory-${i}`, () => {}));
      }

      const afterCreationMemory = performance.memory
        ? performance.memory.usedJSHeapSize
        : 0;

      // Уничтожаем фильтры
      filters.forEach((filter) => filter.destroy());

      // Принудительная сборка мусора (если доступна)
      if (window.gc) {
        window.gc();
      }

      const afterDestroyMemory = performance.memory
        ? performance.memory.usedJSHeapSize
        : 0;

      const memoryIncrease = afterCreationMemory - initialMemory;
      const memoryLeakage = afterDestroyMemory - initialMemory;

      return {
        test: "Memory Usage",
        status: memoryLeakage < memoryIncrease * 0.5 ? "passed" : "warning",
        details: {
          initialMemory: Math.round(initialMemory / 1024),
          afterCreation: Math.round(afterCreationMemory / 1024),
          afterDestroy: Math.round(afterDestroyMemory / 1024),
          memoryIncrease: Math.round(memoryIncrease / 1024),
          memoryLeakage: Math.round(memoryLeakage / 1024),
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Тест адаптации к типу соединения
   */
  async testConnectionAdaptation() {
    const container = document.createElement("div");
    container.id = "test-connection-adaptation";
    document.body.appendChild(container);

    try {
      const filter = new CountryFilter("test-connection-adaptation", () => {});

      // Проверяем наличие методов адаптации к соединению
      const hasConnectionDetection =
        typeof filter.getConnectionType === "function";
      const hasConnectionAdaptation =
        typeof filter.adaptToConnection === "function";

      let connectionTypeDetected = "unknown";
      if (hasConnectionDetection) {
        connectionTypeDetected = filter.getConnectionType();
      }

      return {
        test: "Connection Adaptation",
        status: hasConnectionDetection ? "passed" : "warning",
        details: {
          connectionDetection: hasConnectionDetection,
          connectionAdaptation: hasConnectionAdaptation,
          detectedConnection: connectionTypeDetected,
        },
      };
    } finally {
      document.body.removeChild(container);
    }
  }

  /**
   * Генерация отчета о тестировании
   */
  generateReport() {
    console.log("\n📊 ОТЧЕТ О ТЕСТИРОВАНИИ МОБИЛЬНОЙ АДАПТАЦИИ");
    console.log("=".repeat(50));

    const allTests = [
      ...this.testResults.mobileAdaptation,
      ...this.testResults.touchUsability,
      ...this.testResults.loadingOptimization,
    ];

    const passedTests = allTests.filter((t) => t.status === "passed").length;
    const failedTests = allTests.filter((t) => t.status === "failed").length;
    const warningTests = allTests.filter((t) => t.status === "warning").length;

    console.log(`\n📈 ОБЩАЯ СТАТИСТИКА:`);
    console.log(`✅ Пройдено: ${passedTests}`);
    console.log(`❌ Провалено: ${failedTests}`);
    console.log(`⚠️  Предупреждения: ${warningTests}`);
    console.log(`📊 Всего тестов: ${allTests.length}`);
    console.log(
      `🎯 Успешность: ${Math.round((passedTests / allTests.length) * 100)}%`
    );

    // Детальные результаты по категориям
    this.printCategoryResults(
      "📱 АДАПТАЦИЯ ДЛЯ МОБИЛЬНЫХ УСТРОЙСТВ",
      this.testResults.mobileAdaptation
    );
    this.printCategoryResults(
      "👆 УДОБСТВО ИСПОЛЬЗОВАНИЯ НА СЕНСОРНЫХ ЭКРАНАХ",
      this.testResults.touchUsability
    );
    this.printCategoryResults(
      "⚡ ОПТИМИЗАЦИЯ ВРЕМЕНИ ЗАГРУЗКИ",
      this.testResults.loadingOptimization
    );

    // Рекомендации
    this.printRecommendations(allTests);
  }

  /**
   * Вывод результатов по категории
   */
  printCategoryResults(categoryName, results) {
    console.log(`\n${categoryName}:`);
    console.log("-".repeat(categoryName.length));

    results.forEach((result) => {
      const statusIcon =
        result.status === "passed"
          ? "✅"
          : result.status === "failed"
          ? "❌"
          : "⚠️";

      console.log(`${statusIcon} ${result.test}`);

      if (result.details) {
        Object.entries(result.details).forEach(([key, value]) => {
          console.log(`   ${key}: ${value}`);
        });
      }

      if (result.error) {
        console.log(`   Ошибка: ${result.error}`);
      }
    });
  }

  /**
   * Вывод рекомендаций
   */
  printRecommendations(allTests) {
    console.log("\n💡 РЕКОМЕНДАЦИИ:");
    console.log("-".repeat(15));

    const failedTests = allTests.filter((t) => t.status === "failed");
    const warningTests = allTests.filter((t) => t.status === "warning");

    if (failedTests.length === 0 && warningTests.length === 0) {
      console.log(
        "🎉 Все тесты пройдены успешно! Мобильная адаптация работает отлично."
      );
      return;
    }

    if (failedTests.some((t) => t.test.includes("Touch Target"))) {
      console.log(
        "• Увеличьте размер touch targets до минимум 44px для лучшего UX"
      );
    }

    if (failedTests.some((t) => t.test.includes("Mobile Detection"))) {
      console.log("• Улучшите алгоритм определения мобильных устройств");
    }

    if (failedTests.some((t) => t.test.includes("Cache Efficiency"))) {
      console.log("• Оптимизируйте кэширование для лучшей производительности");
    }

    if (warningTests.some((t) => t.test.includes("Memory Usage"))) {
      console.log(
        "• Проверьте возможные утечки памяти при уничтожении компонентов"
      );
    }

    if (warningTests.some((t) => t.test.includes("Connection"))) {
      console.log("• Добавьте адаптацию к различным типам сетевых соединений");
    }
  }
}

// Запуск тестов при загрузке страницы
document.addEventListener("DOMContentLoaded", async () => {
  const tester = new MobileCountryFilterTester();
  await tester.runAllTests();
});

// Экспорт для использования в других модулях
if (typeof module !== "undefined" && module.exports) {
  module.exports = MobileCountryFilterTester;
}
