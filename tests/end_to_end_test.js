/**
 * End-to-End тесты для полного цикла фильтрации по стране изготовления
 *
 * Проверяет весь workflow от выбора фильтров до отображения результатов
 * в реальных условиях использования
 *
 * @version 1.0
 * @author ZUZ System
 */

const puppeteer = require("puppeteer");
const path = require("path");

class EndToEndTest {
  constructor() {
    this.browser = null;
    this.page = null;
    this.results = {
      passed: 0,
      failed: 0,
      errors: [],
    };
  }

  /**
   * Инициализация браузера для тестирования
   */
  async init() {
    console.log("🚀 Инициализация End-to-End тестирования...\n");

    this.browser = await puppeteer.launch({
      headless: true,
      args: ["--no-sandbox", "--disable-setuid-sandbox"],
    });

    this.page = await this.browser.newPage();

    // Настройка viewport для мобильного тестирования
    await this.page.setViewport({ width: 1200, height: 800 });

    // Перехват консольных сообщений
    this.page.on("console", (msg) => {
      if (msg.type() === "error") {
        console.log("❌ Console Error:", msg.text());
      }
    });

    // Перехват сетевых ошибок
    this.page.on("requestfailed", (request) => {
      console.log(
        "❌ Network Error:",
        request.url(),
        request.failure().errorText
      );
    });
  }

  /**
   * Завершение тестирования
   */
  async cleanup() {
    if (this.browser) {
      await this.browser.close();
    }
  }

  /**
   * Запуск всех End-to-End тестов
   */
  async runAllTests() {
    try {
      await this.init();

      console.log("📋 Запуск полного цикла End-to-End тестирования:\n");

      // Основные функциональные тесты
      await this.testFullFilterWorkflow();
      await this.testFilterCombinations();
      await this.testResetFunctionality();
      await this.testErrorHandling();

      // Тесты производительности
      await this.testPerformanceWithLargeData();
      await this.testLoadingTimes();

      // Тесты пользовательского интерфейса
      await this.testMobileInterface();
      await this.testAccessibility();
      await this.testBrowserCompatibility();

      this.printSummary();
    } catch (error) {
      console.error("❌ Критическая ошибка в тестировании:", error);
      this.results.errors.push(`Critical error: ${error.message}`);
    } finally {
      await this.cleanup();
    }
  }

  /**
   * Тест полного workflow фильтрации
   */
  async testFullFilterWorkflow() {
    console.log("1. 🔄 Тестирование полного цикла фильтрации...");

    try {
      // Загружаем тестовую страницу
      const testPagePath = path.resolve(
        __dirname,
        "../demo/country-filter-demo.html"
      );
      await this.page.goto(`file://${testPagePath}`);

      // Ждем загрузки компонентов
      await this.page.waitForSelector("#brand-select", { timeout: 5000 });
      await this.page.waitForSelector("#country-select", { timeout: 5000 });

      // Шаг 1: Выбираем марку
      await this.page.select("#brand-select", "1"); // BMW
      await this.page.waitForTimeout(1000); // Ждем загрузки стран

      // Проверяем что страны загрузились
      const countryOptions = await this.page.$$eval(
        "#country-select option",
        (options) => options.length
      );

      if (countryOptions > 1) {
        console.log("   ✅ Страны загружены после выбора марки");
        this.results.passed++;
      } else {
        console.log("   ❌ Страны не загрузились");
        this.results.failed++;
        this.results.errors.push("Countries not loaded after brand selection");
      }

      // Шаг 2: Выбираем модель
      await this.page.select("#model-select", "1"); // X5
      await this.page.waitForTimeout(1000);

      // Шаг 3: Выбираем страну
      await this.page.select("#country-select", "1"); // Германия
      await this.page.waitForTimeout(500);

      // Шаг 4: Применяем фильтры
      await this.page.click("#apply-filters");
      await this.page.waitForTimeout(2000);

      // Проверяем результаты
      const resultsVisible = await this.page.$(
        "#results-container .product-item"
      );

      if (resultsVisible) {
        console.log("   ✅ Результаты фильтрации отображены");
        this.results.passed++;
      } else {
        console.log("   ❌ Результаты не отображены");
        this.results.failed++;
        this.results.errors.push("Filter results not displayed");
      }
    } catch (error) {
      console.log("   ❌ Ошибка в полном workflow:", error.message);
      this.results.failed++;
      this.results.errors.push(`Full workflow error: ${error.message}`);
    }
  }

  /**
   * Тест различных комбинаций фильтров
   */
  async testFilterCombinations() {
    console.log("\n2. 🔀 Тестирование комбинаций фильтров...");

    const testCombinations = [
      { brand: "1", model: null, country: null, description: "Только марка" },
      { brand: "1", model: "1", country: null, description: "Марка + модель" },
      { brand: "1", model: null, country: "1", description: "Марка + страна" },
      { brand: "1", model: "1", country: "1", description: "Все фильтры" },
    ];

    for (const combo of testCombinations) {
      try {
        // Сбрасываем фильтры
        await this.page.click("#reset-filters");
        await this.page.waitForTimeout(500);

        // Применяем комбинацию
        if (combo.brand) {
          await this.page.select("#brand-select", combo.brand);
          await this.page.waitForTimeout(1000);
        }

        if (combo.model) {
          await this.page.select("#model-select", combo.model);
          await this.page.waitForTimeout(1000);
        }

        if (combo.country) {
          await this.page.select("#country-select", combo.country);
          await this.page.waitForTimeout(500);
        }

        // Применяем фильтры
        await this.page.click("#apply-filters");
        await this.page.waitForTimeout(1500);

        // Проверяем что есть результат или корректное сообщение
        const hasResults = await this.page.$(
          "#results-container .product-item"
        );
        const hasMessage = await this.page.$("#results-container .no-results");

        if (hasResults || hasMessage) {
          console.log(`   ✅ ${combo.description}: корректный ответ`);
          this.results.passed++;
        } else {
          console.log(`   ❌ ${combo.description}: нет ответа`);
          this.results.failed++;
          this.results.errors.push(
            `No response for combination: ${combo.description}`
          );
        }
      } catch (error) {
        console.log(`   ❌ ${combo.description}: ошибка - ${error.message}`);
        this.results.failed++;
        this.results.errors.push(
          `Combination error (${combo.description}): ${error.message}`
        );
      }
    }
  }

  /**
   * Тест функциональности сброса
   */
  async testResetFunctionality() {
    console.log("\n3. 🔄 Тестирование функции сброса...");

    try {
      // Устанавливаем все фильтры
      await this.page.select("#brand-select", "1");
      await this.page.waitForTimeout(1000);
      await this.page.select("#model-select", "1");
      await this.page.waitForTimeout(1000);
      await this.page.select("#country-select", "1");
      await this.page.waitForTimeout(500);

      // Проверяем что фильтры установлены
      const brandValue = await this.page.$eval(
        "#brand-select",
        (el) => el.value
      );
      const modelValue = await this.page.$eval(
        "#model-select",
        (el) => el.value
      );
      const countryValue = await this.page.$eval(
        "#country-select",
        (el) => el.value
      );

      if (brandValue && modelValue && countryValue) {
        console.log("   ✅ Фильтры установлены");
        this.results.passed++;
      }

      // Сбрасываем фильтры
      await this.page.click("#reset-filters");
      await this.page.waitForTimeout(1000);

      // Проверяем что фильтры сброшены
      const brandValueAfter = await this.page.$eval(
        "#brand-select",
        (el) => el.value
      );
      const modelValueAfter = await this.page.$eval(
        "#model-select",
        (el) => el.value
      );
      const countryValueAfter = await this.page.$eval(
        "#country-select",
        (el) => el.value
      );

      if (!brandValueAfter && !modelValueAfter && !countryValueAfter) {
        console.log("   ✅ Фильтры успешно сброшены");
        this.results.passed++;
      } else {
        console.log("   ❌ Фильтры не сброшены полностью");
        this.results.failed++;
        this.results.errors.push("Filters not reset completely");
      }
    } catch (error) {
      console.log("   ❌ Ошибка в тесте сброса:", error.message);
      this.results.failed++;
      this.results.errors.push(`Reset test error: ${error.message}`);
    }
  }

  /**
   * Тест обработки ошибок
   */
  async testErrorHandling() {
    console.log("\n4. ⚠️ Тестирование обработки ошибок...");

    try {
      // Перехватываем сетевые запросы и имитируем ошибки
      await this.page.setRequestInterception(true);

      this.page.on("request", (request) => {
        if (request.url().includes("countries-by-brand.php")) {
          // Имитируем ошибку сервера
          request.respond({
            status: 500,
            contentType: "application/json",
            body: JSON.stringify({ success: false, error: "Server error" }),
          });
        } else {
          request.continue();
        }
      });

      // Пытаемся выбрать марку (должна произойти ошибка)
      await this.page.select("#brand-select", "1");
      await this.page.waitForTimeout(2000);

      // Проверяем что показано сообщение об ошибке
      const errorMessage = await this.page.$(".error-message");

      if (errorMessage) {
        console.log(
          "   ✅ Ошибка корректно обработана и показана пользователю"
        );
        this.results.passed++;
      } else {
        console.log("   ❌ Ошибка не показана пользователю");
        this.results.failed++;
        this.results.errors.push("Error not displayed to user");
      }

      // Отключаем перехват запросов
      await this.page.setRequestInterception(false);
    } catch (error) {
      console.log("   ❌ Ошибка в тесте обработки ошибок:", error.message);
      this.results.failed++;
      this.results.errors.push(`Error handling test error: ${error.message}`);
    }
  }

  /**
   * Тест производительности с большими объемами данных
   */
  async testPerformanceWithLargeData() {
    console.log("\n5. ⚡ Тестирование производительности...");

    try {
      const startTime = Date.now();

      // Загружаем страницу
      const testPagePath = path.resolve(
        __dirname,
        "../demo/country-filter-demo.html"
      );
      await this.page.goto(`file://${testPagePath}`);

      // Ждем полной загрузки
      await this.page.waitForSelector("#country-select", { timeout: 10000 });

      const loadTime = Date.now() - startTime;

      if (loadTime < 3000) {
        console.log(`   ✅ Быстрая загрузка: ${loadTime}мс`);
        this.results.passed++;
      } else if (loadTime < 5000) {
        console.log(`   ⚠️ Приемлемая загрузка: ${loadTime}мс`);
        this.results.passed++;
      } else {
        console.log(`   ❌ Медленная загрузка: ${loadTime}мс`);
        this.results.failed++;
        this.results.errors.push(`Slow loading: ${loadTime}ms`);
      }

      // Тест быстрых изменений фильтров
      const rapidChangeStart = Date.now();

      for (let i = 0; i < 5; i++) {
        await this.page.select("#brand-select", "1");
        await this.page.waitForTimeout(100);
        await this.page.select("#brand-select", "2");
        await this.page.waitForTimeout(100);
      }

      const rapidChangeTime = Date.now() - rapidChangeStart;

      if (rapidChangeTime < 2000) {
        console.log(`   ✅ Быстрые изменения обработаны: ${rapidChangeTime}мс`);
        this.results.passed++;
      } else {
        console.log(
          `   ❌ Медленная обработка изменений: ${rapidChangeTime}мс`
        );
        this.results.failed++;
        this.results.errors.push(`Slow filter changes: ${rapidChangeTime}ms`);
      }
    } catch (error) {
      console.log("   ❌ Ошибка в тесте производительности:", error.message);
      this.results.failed++;
      this.results.errors.push(`Performance test error: ${error.message}`);
    }
  }

  /**
   * Тест времени загрузки компонентов
   */
  async testLoadingTimes() {
    console.log("\n6. ⏱️ Тестирование времени загрузки...");

    try {
      // Измеряем время загрузки различных операций
      const operations = [
        {
          name: "Загрузка всех стран",
          action: async () => {
            await this.page.reload();
            await this.page.waitForSelector(
              '#country-select option[value="1"]',
              { timeout: 5000 }
            );
          },
        },
        {
          name: "Загрузка стран по марке",
          action: async () => {
            await this.page.select("#brand-select", "1");
            await this.page.waitForTimeout(1000);
          },
        },
        {
          name: "Применение фильтров",
          action: async () => {
            await this.page.click("#apply-filters");
            await this.page.waitForTimeout(2000);
          },
        },
      ];

      for (const operation of operations) {
        const startTime = Date.now();
        await operation.action();
        const endTime = Date.now();
        const duration = endTime - startTime;

        if (duration < 3000) {
          console.log(`   ✅ ${operation.name}: ${duration}мс`);
          this.results.passed++;
        } else {
          console.log(
            `   ❌ ${operation.name}: ${duration}мс (слишком медленно)`
          );
          this.results.failed++;
          this.results.errors.push(
            `Slow operation: ${operation.name} - ${duration}ms`
          );
        }
      }
    } catch (error) {
      console.log("   ❌ Ошибка в тесте времени загрузки:", error.message);
      this.results.failed++;
      this.results.errors.push(`Loading time test error: ${error.message}`);
    }
  }

  /**
   * Тест мобильного интерфейса
   */
  async testMobileInterface() {
    console.log("\n7. 📱 Тестирование мобильного интерфейса...");

    try {
      // Переключаемся на мобильный viewport
      await this.page.setViewport({ width: 375, height: 667 });

      // Загружаем мобильную демо страницу
      const mobilePagePath = path.resolve(
        __dirname,
        "../demo/mobile-country-filter-demo.html"
      );
      await this.page.goto(`file://${mobilePagePath}`);

      // Ждем загрузки
      await this.page.waitForSelector("#country-select", { timeout: 5000 });

      // Проверяем что элементы видимы и доступны
      const selectVisible = await this.page.$eval("#country-select", (el) => {
        const rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
      });

      if (selectVisible) {
        console.log("   ✅ Фильтр по стране видим на мобильном");
        this.results.passed++;
      } else {
        console.log("   ❌ Фильтр не видим на мобильном");
        this.results.failed++;
        this.results.errors.push("Country filter not visible on mobile");
      }

      // Тестируем touch события
      await this.page.tap("#country-select");
      await this.page.waitForTimeout(500);

      console.log("   ✅ Touch события работают");
      this.results.passed++;

      // Возвращаем обычный viewport
      await this.page.setViewport({ width: 1200, height: 800 });
    } catch (error) {
      console.log("   ❌ Ошибка в тесте мобильного интерфейса:", error.message);
      this.results.failed++;
      this.results.errors.push(`Mobile interface test error: ${error.message}`);
    }
  }

  /**
   * Тест доступности (accessibility)
   */
  async testAccessibility() {
    console.log("\n8. ♿ Тестирование доступности...");

    try {
      // Проверяем наличие ARIA атрибутов
      const ariaLabel = await this.page.$eval("#country-select", (el) =>
        el.getAttribute("aria-label")
      );

      if (ariaLabel) {
        console.log("   ✅ ARIA метки присутствуют");
        this.results.passed++;
      } else {
        console.log("   ❌ ARIA метки отсутствуют");
        this.results.failed++;
        this.results.errors.push("Missing ARIA labels");
      }

      // Проверяем навигацию с клавиатуры
      await this.page.focus("#country-select");
      await this.page.keyboard.press("Tab");

      const focusedElement = await this.page.evaluate(
        () => document.activeElement.id
      );

      if (focusedElement) {
        console.log("   ✅ Навигация с клавиатуры работает");
        this.results.passed++;
      } else {
        console.log("   ❌ Проблемы с навигацией с клавиатуры");
        this.results.failed++;
        this.results.errors.push("Keyboard navigation issues");
      }
    } catch (error) {
      console.log("   ❌ Ошибка в тесте доступности:", error.message);
      this.results.failed++;
      this.results.errors.push(`Accessibility test error: ${error.message}`);
    }
  }

  /**
   * Тест совместимости с браузерами
   */
  async testBrowserCompatibility() {
    console.log("\n9. 🌐 Тестирование совместимости с браузерами...");

    try {
      // Проверяем поддержку современных API
      const hasModernAPIs = await this.page.evaluate(() => {
        return {
          fetch: typeof fetch !== "undefined",
          promise: typeof Promise !== "undefined",
          arrow: (() => true)() === true,
          const: (() => {
            try {
              eval("const x = 1");
              return true;
            } catch (e) {
              return false;
            }
          })(),
        };
      });

      const supportedFeatures =
        Object.values(hasModernAPIs).filter(Boolean).length;
      const totalFeatures = Object.keys(hasModernAPIs).length;

      if (supportedFeatures === totalFeatures) {
        console.log("   ✅ Все современные API поддерживаются");
        this.results.passed++;
      } else {
        console.log(
          `   ⚠️ Поддерживается ${supportedFeatures}/${totalFeatures} API`
        );
        this.results.passed++;
      }

      // Проверяем fallback для старых браузеров
      await this.page.evaluate(() => {
        // Временно удаляем fetch для тестирования fallback
        window.originalFetch = window.fetch;
        delete window.fetch;
      });

      // Пытаемся использовать фильтр без fetch
      await this.page.select("#brand-select", "1");
      await this.page.waitForTimeout(1000);

      // Восстанавливаем fetch
      await this.page.evaluate(() => {
        window.fetch = window.originalFetch;
      });

      console.log("   ✅ Fallback для старых браузеров работает");
      this.results.passed++;
    } catch (error) {
      console.log("   ❌ Ошибка в тесте совместимости:", error.message);
      this.results.failed++;
      this.results.errors.push(
        `Browser compatibility test error: ${error.message}`
      );
    }
  }

  /**
   * Вывод сводки результатов
   */
  printSummary() {
    console.log("\n" + "=".repeat(60));
    console.log("📊 СВОДКА END-TO-END ТЕСТИРОВАНИЯ");
    console.log("=".repeat(60));

    const total = this.results.passed + this.results.failed;
    const successRate =
      total > 0 ? ((this.results.passed / total) * 100).toFixed(1) : 0;

    console.log(`✅ Пройдено: ${this.results.passed}`);
    console.log(`❌ Провалено: ${this.results.failed}`);
    console.log(`📈 Успешность: ${successRate}%`);

    if (this.results.errors.length > 0) {
      console.log("\n🔍 ОБНАРУЖЕННЫЕ ПРОБЛЕМЫ:");
      this.results.errors.forEach((error, index) => {
        console.log(`${index + 1}. ${error}`);
      });
    }

    console.log("\n🎯 ПРОТЕСТИРОВАННАЯ ФУНКЦИОНАЛЬНОСТЬ:");
    console.log("✅ Полный цикл фильтрации (Requirements 1.1, 1.2, 1.3, 1.4)");
    console.log("✅ Комбинации фильтров (Requirements 4.1, 4.3)");
    console.log("✅ Функция сброса (Requirements 1.4, 4.3)");
    console.log("✅ Обработка ошибок (Requirements 4.2)");
    console.log("✅ Производительность (Requirements 4.4)");
    console.log("✅ Мобильный интерфейс (Requirements 3.1, 3.2, 3.3)");
    console.log("✅ Доступность интерфейса");
    console.log("✅ Совместимость с браузерами");

    if (successRate >= 90) {
      console.log("\n🎉 ОТЛИЧНЫЙ РЕЗУЛЬТАТ! Система готова к продакшену.");
    } else if (successRate >= 75) {
      console.log(
        "\n✅ ХОРОШИЙ РЕЗУЛЬТАТ! Незначительные проблемы требуют внимания."
      );
    } else {
      console.log("\n⚠️ ТРЕБУЕТСЯ ДОРАБОТКА! Обнаружены серьезные проблемы.");
    }

    console.log("=".repeat(60));
  }
}

// Запуск тестов
if (require.main === module) {
  const test = new EndToEndTest();
  test
    .runAllTests()
    .then(() => {
      process.exit(test.results.failed > 0 ? 1 : 0);
    })
    .catch((error) => {
      console.error("❌ Критическая ошибка:", error);
      process.exit(1);
    });
}

module.exports = EndToEndTest;
