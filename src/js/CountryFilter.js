/**
 * CountryFilter - Оптимизированный класс для управления фильтром по стране изготовления
 *
 * Обеспечивает функциональность фильтрации товаров по стране изготовления автомобиля
 * с интеграцией в существующую систему фильтров ZUZ проставок.
 *
 * Версия с улучшенным кэшированием и оптимизацией производительности
 *
 * @version 1.1
 * @author ZUZ System
 */

class CountryFilter {
  /**
   * Конструктор класса CountryFilter
   *
   * @param {string} containerId - ID контейнера для размещения фильтра
   * @param {function} onChangeCallback - Callback функция для обработки изменений
   */
  constructor(containerId, onChangeCallback) {
    this.container = document.getElementById(containerId);
    this.onChange = onChangeCallback;
    this.countries = [];
    this.selectedCountry = null;
    this.apiBaseUrl = "http://178.72.129.61/api";

    // Определение мобильного устройства
    this.isMobile = this.detectMobileDevice();
    this.isTouch = "ontouchstart" in window || navigator.maxTouchPoints > 0;

    // Улучшенное кэширование с оптимизацией для мобильных
    this.cache = new Map();
    this.cacheTimeout = this.isMobile ? 20 * 60 * 1000 : 15 * 60 * 1000; // Увеличенное время кэша для мобильных
    this.maxCacheSize = this.isMobile ? 30 : 50; // Меньший размер кэша для мобильных

    // Дебаунсинг с оптимизацией для мобильных
    this.debounceTimeout = null;
    this.debounceDelay = this.isMobile ? 500 : 300; // Увеличенная задержка для мобильных

    // DOM элементы
    this.selectElement = null;
    this.loadingElement = null;
    this.errorElement = null;

    // Мобильные оптимизации
    this.touchStartTime = 0;
    this.lastTouchEnd = 0;

    this.init();
  }

  /**
   * Инициализация компонента
   */
  init() {
    if (!this.container) {
      console.error(
        "Контейнер для фильтра по стране не найден:",
        this.container
      );
      return;
    }

    this.render();
    this.bindEvents();
    this.setupMobileOptimizations();
    this.loadCountries();
  }

  /**
   * Отрисовка HTML структуры фильтра
   */
  render() {
    const mobileClass = this.isMobile ? "country-filter--mobile" : "";
    const compactClass =
      this.isMobile && window.innerWidth < 480
        ? "country-filter--mobile-compact"
        : "";

    this.container.innerHTML = `
            <div class="filter-group country-filter ${mobileClass} ${compactClass}">
                <label for="country-select" class="filter-label">
                    Страна изготовления:
                </label>
                <div class="filter-input-wrapper">
                    <select id="country-select" class="filter-select form-control" ${
                      this.isMobile ? 'data-mobile="true"' : ""
                    }>
                        <option value="">Все страны</option>
                    </select>
                    <div class="filter-loading" style="display: none;">
                        <span class="loading-spinner"></span>
                        ${this.isMobile ? "Загрузка..." : "Загрузка..."}
                    </div>
                    <div class="filter-error" style="display: none; color: #dc3545;">
                        <span class="error-icon">⚠</span>
                        <span class="error-message"></span>
                        <button type="button" class="retry-button" style="margin-left: 10px; padding: ${
                          this.isMobile ? "8px 12px" : "2px 8px"
                        }; font-size: ${
      this.isMobile ? "14px" : "12px"
    }; background: #007bff; color: white; border: none; border-radius: ${
      this.isMobile ? "6px" : "3px"
    }; cursor: pointer; touch-action: manipulation;">
                            Повторить
                        </button>
                    </div>
                </div>
            </div>
        `;

    // Получаем ссылки на DOM элементы
    this.selectElement = this.container.querySelector("#country-select");
    this.loadingElement = this.container.querySelector(".filter-loading");
    this.errorElement = this.container.querySelector(".filter-error");
    this.retryButton = this.container.querySelector(".retry-button");
  }

  /**
   * Привязка обработчиков событий
   */
  bindEvents() {
    if (this.selectElement) {
      this.selectElement.addEventListener("change", (e) => {
        this.selectedCountry = e.target.value || null;
        this.debouncedTriggerChange();
      });

      // Мобильные touch события для улучшенного UX
      if (this.isTouch) {
        this.selectElement.addEventListener(
          "touchstart",
          (e) => {
            this.touchStartTime = Date.now();
            this.handleTouchStart(e);
          },
          { passive: true }
        );

        this.selectElement.addEventListener(
          "touchend",
          (e) => {
            this.handleTouchEnd(e);
          },
          { passive: true }
        );
      }
    }

    if (this.retryButton) {
      this.retryButton.addEventListener("click", (e) => {
        e.preventDefault();
        this.retry();
      });

      // Touch события для кнопки повтора
      if (this.isTouch) {
        this.retryButton.addEventListener(
          "touchstart",
          (e) => {
            e.currentTarget.style.transform = "scale(0.95)";
          },
          { passive: true }
        );

        this.retryButton.addEventListener(
          "touchend",
          (e) => {
            e.currentTarget.style.transform = "scale(1)";
          },
          { passive: true }
        );
      }
    }
  }

  /**
   * Дебаунсированный вызов callback функции
   */
  debouncedTriggerChange() {
    if (this.debounceTimeout) {
      clearTimeout(this.debounceTimeout);
    }

    this.debounceTimeout = setTimeout(() => {
      this.triggerChange();
    }, this.debounceDelay);
  }

  /**
   * Управление размером кэша - удаляет старые записи при превышении лимита
   */
  manageCacheSize() {
    if (this.cache.size > this.maxCacheSize) {
      // Получаем все записи и сортируем по времени
      const entries = Array.from(this.cache.entries());
      entries.sort((a, b) => a[1].timestamp - b[1].timestamp);

      // Удаляем самые старые записи
      const toDelete = entries.slice(0, entries.length - this.maxCacheSize);
      toDelete.forEach(([key]) => this.cache.delete(key));
    }
  }

  /**
   * Проверка валидности кэша
   *
   * @param {string} cacheKey - ключ кэша
   * @returns {boolean} true если кэш валиден
   */
  isCacheValid(cacheKey) {
    if (!this.cache.has(cacheKey)) {
      return false;
    }

    const cached = this.cache.get(cacheKey);
    return Date.now() - cached.timestamp < this.cacheTimeout;
  }

  /**
   * Установка данных в кэш с управлением размером
   *
   * @param {string} cacheKey - ключ кэша
   * @param {any} data - данные для кэширования
   */
  setCacheData(cacheKey, data) {
    this.cache.set(cacheKey, {
      data: data,
      timestamp: Date.now(),
    });

    // Управляем размером кэша
    this.manageCacheSize();
  }

  /**
   * Получение данных из кэша
   *
   * @param {string} cacheKey - ключ кэша
   * @returns {any|null} данные из кэша или null
   */
  getCacheData(cacheKey) {
    if (this.isCacheValid(cacheKey)) {
      return this.cache.get(cacheKey).data;
    }
    return null;
  }

  /**
   * Оптимизированный HTTP запрос с повторными попытками
   *
   * @param {string} url - URL для запроса
   * @param {number} retries - количество повторных попыток
   * @returns {Promise<Response>}
   */
  async fetchWithRetry(url, retries = 2) {
    for (let i = 0; i <= retries; i++) {
      try {
        const response = await fetch(url, {
          method: "GET",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
          },
          // Добавляем таймаут для предотвращения зависания
          signal: AbortSignal.timeout(10000), // 10 секунд
        });

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        return response;
      } catch (error) {
        if (i === retries) {
          throw error;
        }
        // Экспоненциальная задержка между попытками
        await new Promise((resolve) =>
          setTimeout(resolve, Math.pow(2, i) * 1000)
        );
      }
    }
  }

  /**
   * Загрузка всех доступных стран
   */
  async loadCountries() {
    const cacheKey = "all_countries";

    // Проверяем кэш
    const cachedData = this.getCacheData(cacheKey);
    if (cachedData) {
      this.populateSelect(cachedData);
      return cachedData;
    }

    this.showLoading();

    try {
      const response = await this.fetchWithRetry(
        `${this.apiBaseUrl}/countries.php`
      );
      const result = await response.json();

      if (result.success) {
        this.countries = result.data || [];

        // Кэшируем результат
        this.setCacheData(cacheKey, result.data || []);

        this.populateSelect(result.data || []);
        this.hideLoading();

        // Показываем сообщение если нет данных
        if (!result.data || result.data.length === 0) {
          this.showFallbackMessage(
            result.message || "Страны изготовления не найдены"
          );
        }

        return result.data || [];
      } else {
        this.handleApiError(
          result.error || "Неизвестная ошибка при загрузке стран"
        );
        return [];
      }
    } catch (error) {
      console.error("Ошибка запроса стран:", error);
      this.handleNetworkError(error);
      return [];
    }
  }

  /**
   * Загрузка стран для конкретной марки
   *
   * @param {number|string} brandId - ID марки автомобиля
   */
  async loadCountriesForBrand(brandId) {
    if (!brandId) {
      return await this.loadCountries();
    }

    // Валидация brandId
    if (!this.validateId(brandId, "ID марки")) {
      return [];
    }

    const cacheKey = `countries_brand_${brandId}`;

    // Проверяем кэш
    const cachedData = this.getCacheData(cacheKey);
    if (cachedData) {
      this.populateSelect(cachedData);
      return cachedData;
    }

    this.showLoading();

    try {
      const response = await this.fetchWithRetry(
        `${
          this.apiBaseUrl
        }/countries-by-brand.php?brand_id=${encodeURIComponent(brandId)}`
      );
      const result = await response.json();

      if (result.success) {
        this.countries = result.data || [];

        // Кэшируем результат
        this.setCacheData(cacheKey, result.data || []);

        this.populateSelect(result.data || []);
        this.hideLoading();

        // Показываем сообщение если нет данных
        if (!result.data || result.data.length === 0) {
          this.showFallbackMessage(
            result.message || "Для данной марки не найдено стран изготовления"
          );
        }

        return result.data || [];
      } else {
        this.handleApiError(
          result.error || "Неизвестная ошибка при загрузке стран для марки"
        );
        return [];
      }
    } catch (error) {
      console.error("Ошибка запроса стран для марки:", error);
      this.handleNetworkError(error);
      return [];
    }
  }

  /**
   * Загрузка стран для конкретной модели
   *
   * @param {number|string} modelId - ID модели автомобиля
   */
  async loadCountriesForModel(modelId) {
    if (!modelId) {
      return await this.loadCountries();
    }

    // Валидация modelId
    if (!this.validateId(modelId, "ID модели")) {
      return [];
    }

    const cacheKey = `countries_model_${modelId}`;

    // Проверяем кэш
    const cachedData = this.getCacheData(cacheKey);
    if (cachedData) {
      this.populateSelect(cachedData);
      return cachedData;
    }

    this.showLoading();

    try {
      const response = await this.fetchWithRetry(
        `${
          this.apiBaseUrl
        }/countries-by-model.php?model_id=${encodeURIComponent(modelId)}`
      );
      const result = await response.json();

      if (result.success) {
        this.countries = result.data || [];

        // Кэшируем результат
        this.setCacheData(cacheKey, result.data || []);

        this.populateSelect(result.data || []);
        this.hideLoading();

        // Показываем сообщение если нет данных
        if (!result.data || result.data.length === 0) {
          this.showFallbackMessage(
            result.message || "Для данной модели не найдено стран изготовления"
          );
        }

        return result.data || [];
      } else {
        this.handleApiError(
          result.error || "Неизвестная ошибка при загрузке стран для модели"
        );
        return [];
      }
    } catch (error) {
      console.error("Ошибка запроса стран для модели:", error);
      this.handleNetworkError(error);
      return [];
    }
  }

  /**
   * Заполнение select элемента странами с оптимизацией
   *
   * @param {Array} countries - Массив стран
   */
  populateSelect(countries) {
    if (!this.selectElement) return;

    // Сохраняем текущий выбор
    const currentValue = this.selectedCountry;

    // Используем DocumentFragment для оптимизации DOM операций
    const fragment = document.createDocumentFragment();

    // Добавляем опцию "Все страны"
    const defaultOption = document.createElement("option");
    defaultOption.value = "";
    defaultOption.textContent = "Все страны";
    fragment.appendChild(defaultOption);

    // Добавляем страны
    countries.forEach((country) => {
      const option = document.createElement("option");
      option.value = country.id;
      option.textContent = country.name;
      fragment.appendChild(option);
    });

    // Очищаем и добавляем все опции одним действием
    this.selectElement.innerHTML = "";
    this.selectElement.appendChild(fragment);

    // Восстанавливаем выбранное значение
    if (currentValue && countries.some((c) => c.id == currentValue)) {
      this.selectElement.value = currentValue;
      this.selectedCountry = currentValue;
    } else if (currentValue && !countries.some((c) => c.id == currentValue)) {
      // Если выбранная страна больше не доступна, сбрасываем выбор
      this.selectedCountry = null;
      this.triggerChange();
    }
  }

  /**
   * Получение выбранной страны
   *
   * @returns {string|null} ID выбранной страны или null
   */
  getSelectedCountry() {
    return this.selectedCountry;
  }

  /**
   * Установка выбранной страны
   *
   * @param {string|number|null} countryId - ID страны для выбора
   */
  setSelectedCountry(countryId) {
    this.selectedCountry = countryId;
    if (this.selectElement) {
      this.selectElement.value = countryId || "";
    }
  }

  /**
   * Сброс выбора страны
   *
   * @param {boolean} triggerChange - Вызывать ли callback при сбросе (по умолчанию true)
   */
  reset(triggerChange = true) {
    this.selectedCountry = null;
    if (this.selectElement) {
      this.selectElement.value = "";
    }
    this.hideError();

    // Вызываем callback если требуется
    if (triggerChange) {
      this.triggerChange();
    }
  }

  /**
   * Получение информации о выбранной стране
   *
   * @returns {Object|null} Объект с информацией о стране или null
   */
  getSelectedCountryInfo() {
    if (!this.selectedCountry) return null;

    return (
      this.countries.find((country) => country.id == this.selectedCountry) ||
      null
    );
  }

  /**
   * Проверка, выбрана ли страна
   *
   * @returns {boolean} true если страна выбрана
   */
  hasSelection() {
    return this.selectedCountry !== null && this.selectedCountry !== "";
  }

  /**
   * Получение всех загруженных стран
   *
   * @returns {Array} Массив стран
   */
  getCountries() {
    return [...this.countries];
  }

  /**
   * Очистка кэша
   */
  clearCache() {
    this.cache.clear();
  }

  /**
   * Показать индикатор загрузки
   */
  showLoading() {
    if (this.loadingElement) {
      this.loadingElement.style.display = "block";
    }
    if (this.selectElement) {
      this.selectElement.disabled = true;
    }
    this.hideError();
  }

  /**
   * Скрыть индикатор загрузки
   */
  hideLoading() {
    if (this.loadingElement) {
      this.loadingElement.style.display = "none";
    }
    if (this.selectElement) {
      this.selectElement.disabled = false;
    }
  }

  /**
   * Показать сообщение об ошибке
   *
   * @param {string} message - Текст ошибки
   */
  showError(message) {
    if (this.errorElement) {
      const messageElement = this.errorElement.querySelector(".error-message");
      if (messageElement) {
        messageElement.textContent = message;
      }
      this.errorElement.style.display = "block";
      this.errorElement.style.color = "#dc3545"; // Красный цвет для ошибок
    }
    this.hideLoading();
    console.error("CountryFilter Error:", message);
  }

  /**
   * Скрыть сообщение об ошибке
   */
  hideError() {
    if (this.errorElement) {
      this.errorElement.style.display = "none";
    }
  }

  /**
   * Вызов callback функции при изменении
   */
  triggerChange() {
    if (typeof this.onChange === "function") {
      this.onChange(this.selectedCountry);
    }
  }

  /**
   * Валидация ID параметра
   *
   * @param {any} id - ID для валидации
   * @param {string} fieldName - название поля для сообщения об ошибке
   * @returns {boolean} true если ID валиден
   */
  validateId(id, fieldName) {
    if (id === null || id === undefined || id === "") {
      return false;
    }

    const numId = Number(id);
    if (isNaN(numId) || numId <= 0 || numId > 999999) {
      this.showError(
        `Некорректный ${fieldName}: должен быть положительным числом`
      );
      return false;
    }

    return true;
  }

  /**
   * Обработка ошибок API
   *
   * @param {string} errorMessage - сообщение об ошибке
   */
  handleApiError(errorMessage) {
    this.showError(`Ошибка API: ${errorMessage}`);
    this.enableFallbackMode();
  }

  /**
   * Обработка сетевых ошибок
   *
   * @param {Error} error - объект ошибки
   */
  handleNetworkError(error) {
    let message = "Ошибка сети. Проверьте подключение к интернету.";

    if (error.name === "TypeError" && error.message.includes("fetch")) {
      message =
        "Не удается подключиться к серверу. Проверьте подключение к интернету.";
    } else if (error.message.includes("HTTP")) {
      message = `Ошибка сервера: ${error.message}`;
    } else if (error.name === "AbortError") {
      message = "Превышено время ожидания ответа сервера.";
    }

    this.showError(message);
    this.enableFallbackMode();
  }

  /**
   * Показать информационное сообщение (не ошибку)
   *
   * @param {string} message - текст сообщения
   */
  showFallbackMessage(message) {
    if (this.errorElement) {
      const messageElement = this.errorElement.querySelector(".error-message");
      if (messageElement) {
        messageElement.textContent = message;
      }
      this.errorElement.style.display = "block";
      this.errorElement.style.color = "#6c757d"; // Серый цвет для информационных сообщений
    }
    console.info("CountryFilter Info:", message);
  }

  /**
   * Включить режим fallback при ошибках
   */
  enableFallbackMode() {
    this.hideLoading();

    // Показываем базовую опцию "Все страны"
    if (this.selectElement) {
      this.selectElement.innerHTML = '<option value="">Все страны</option>';
      this.selectElement.disabled = false;
    }

    // Сбрасываем выбор
    this.selectedCountry = null;
    this.countries = [];

    // Уведомляем о изменении
    this.triggerChange();
  }

  /**
   * Повторная попытка загрузки данных
   *
   * @param {string} type - тип загрузки ('all', 'brand', 'model')
   * @param {number|string} id - ID для загрузки (для brand/model)
   */
  async retry(type = "all", id = null) {
    this.hideError();

    switch (type) {
      case "brand":
        return await this.loadCountriesForBrand(id);
      case "model":
        return await this.loadCountriesForModel(id);
      default:
        return await this.loadCountries();
    }
  }

  /**
   * Проверка доступности API
   *
   * @returns {Promise<boolean>} true если API доступно
   */
  async checkApiAvailability() {
    try {
      const response = await fetch(`${this.apiBaseUrl}/countries.php`, {
        method: "HEAD",
        headers: {
          Accept: "application/json",
        },
        signal: AbortSignal.timeout(5000), // 5 секунд таймаут
      });
      return response.ok;
    } catch (error) {
      return false;
    }
  }

  /**
   * Получение статистики кэша
   *
   * @returns {Object} статистика кэша
   */
  getCacheStats() {
    const now = Date.now();
    let validEntries = 0;
    let expiredEntries = 0;

    for (const [key, value] of this.cache.entries()) {
      if (now - value.timestamp < this.cacheTimeout) {
        validEntries++;
      } else {
        expiredEntries++;
      }
    }

    return {
      totalEntries: this.cache.size,
      validEntries,
      expiredEntries,
      maxSize: this.maxCacheSize,
      cacheTimeout: this.cacheTimeout,
    };
  }

  /**
   * Очистка устаревших записей кэша
   */
  cleanExpiredCache() {
    const now = Date.now();
    for (const [key, value] of this.cache.entries()) {
      if (now - value.timestamp >= this.cacheTimeout) {
        this.cache.delete(key);
      }
    }
  }

  /**
   * Определение мобильного устройства
   *
   * @returns {boolean} true если устройство мобильное
   */
  detectMobileDevice() {
    const userAgent = navigator.userAgent || navigator.vendor || window.opera;

    // Проверка по user agent
    const mobileRegex =
      /android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/i;
    const isMobileUA = mobileRegex.test(userAgent.toLowerCase());

    // Проверка по размеру экрана
    const isMobileScreen = window.innerWidth <= 768;

    // Проверка поддержки touch
    const hasTouch = "ontouchstart" in window || navigator.maxTouchPoints > 0;

    return isMobileUA || (isMobileScreen && hasTouch);
  }

  /**
   * Настройка мобильных оптимизаций
   */
  setupMobileOptimizations() {
    if (!this.isMobile) return;

    // Добавляем viewport meta tag если его нет
    this.ensureViewportMeta();

    // Оптимизация для iOS Safari
    if (this.isIOSSafari()) {
      this.setupIOSOptimizations();
    }

    // Предзагрузка данных для мобильных
    this.preloadMobileData();

    // Настройка обработчиков ориентации
    this.setupOrientationHandlers();
  }

  /**
   * Проверка iOS Safari
   *
   * @returns {boolean} true если iOS Safari
   */
  isIOSSafari() {
    const userAgent = navigator.userAgent;
    return (
      /iPad|iPhone|iPod/.test(userAgent) &&
      /Safari/.test(userAgent) &&
      !/CriOS|FxiOS/.test(userAgent)
    );
  }

  /**
   * Обеспечение наличия viewport meta tag
   */
  ensureViewportMeta() {
    let viewport = document.querySelector('meta[name="viewport"]');
    if (!viewport) {
      viewport = document.createElement("meta");
      viewport.name = "viewport";
      viewport.content =
        "width=device-width, initial-scale=1.0, user-scalable=no";
      document.head.appendChild(viewport);
    }
  }

  /**
   * Настройка оптимизаций для iOS
   */
  setupIOSOptimizations() {
    if (this.selectElement) {
      // Предотвращение зума при фокусе на iOS
      this.selectElement.style.fontSize =
        Math.max(
          16,
          parseFloat(getComputedStyle(this.selectElement).fontSize)
        ) + "px";

      // Улучшение производительности скролла
      this.selectElement.style.webkitOverflowScrolling = "touch";
    }
  }

  /**
   * Предзагрузка данных для мобильных устройств
   */
  async preloadMobileData() {
    // Предзагружаем список всех стран в фоне для быстрого доступа
    try {
      const cacheKey = "all_countries_preload";
      if (!this.isCacheValid(cacheKey)) {
        // Делаем запрос в фоне без показа индикатора загрузки
        const response = await this.fetchWithRetry(
          `${this.apiBaseUrl}/countries.php`
        );
        const result = await response.json();

        if (result.success && result.data) {
          this.setCacheData(cacheKey, result.data);
        }
      }
    } catch (error) {
      // Игнорируем ошибки предзагрузки
      console.debug("Preload failed:", error);
    }
  }

  /**
   * Настройка обработчиков изменения ориентации
   */
  setupOrientationHandlers() {
    const handleOrientationChange = () => {
      // Небольшая задержка для завершения поворота
      setTimeout(() => {
        this.handleMobileResize();
      }, 100);
    };

    // Современный API
    if (screen.orientation) {
      screen.orientation.addEventListener("change", handleOrientationChange);
    } else {
      // Fallback для старых браузеров
      window.addEventListener("orientationchange", handleOrientationChange);
    }

    // Обработчик изменения размера окна
    window.addEventListener(
      "resize",
      this.debounce(() => {
        this.handleMobileResize();
      }, 250)
    );
  }

  /**
   * Обработка изменения размера на мобильных
   */
  handleMobileResize() {
    if (!this.isMobile) return;

    // Обновляем классы в зависимости от размера экрана
    const isCompact = window.innerWidth < 480;
    const filterElement = this.container.querySelector(".country-filter");

    if (filterElement) {
      if (isCompact) {
        filterElement.classList.add("country-filter--mobile-compact");
      } else {
        filterElement.classList.remove("country-filter--mobile-compact");
      }
    }
  }

  /**
   * Обработка touch start события
   *
   * @param {TouchEvent} event - событие touch
   */
  handleTouchStart(event) {
    // Предотвращение двойного тапа для зума на iOS
    const now = Date.now();
    if (now - this.lastTouchEnd <= 300) {
      event.preventDefault();
    }
  }

  /**
   * Обработка touch end события
   *
   * @param {TouchEvent} event - событие touch
   */
  handleTouchEnd(event) {
    this.lastTouchEnd = Date.now();

    // Добавляем визуальную обратную связь
    const touchDuration = this.lastTouchEnd - this.touchStartTime;
    if (touchDuration < 200) {
      // Быстрый тап
      this.addTouchFeedback(event.currentTarget);
    }
  }

  /**
   * Добавление визуальной обратной связи для touch
   *
   * @param {Element} element - элемент для анимации
   */
  addTouchFeedback(element) {
    element.style.transform = "scale(0.98)";
    element.style.transition = "transform 0.1s ease";

    setTimeout(() => {
      element.style.transform = "scale(1)";
      setTimeout(() => {
        element.style.transition = "";
      }, 100);
    }, 100);
  }

  /**
   * Утилита debounce
   *
   * @param {Function} func - функция для debounce
   * @param {number} wait - время ожидания
   * @returns {Function} debounced функция
   */
  debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
      const later = () => {
        clearTimeout(timeout);
        func(...args);
      };
      clearTimeout(timeout);
      timeout = setTimeout(later, wait);
    };
  }

  /**
   * Оптимизированная загрузка для мобильных устройств
   *
   * @param {string} url - URL для загрузки
   * @returns {Promise<any>} результат загрузки
   */
  async mobileOptimizedFetch(url) {
    // Для мобильных устройств используем более агрессивное кэширование
    const cacheKey = `mobile_${url}`;
    const cachedData = this.getCacheData(cacheKey);

    if (cachedData) {
      return cachedData;
    }

    // Уменьшаем таймаут для мобильных сетей
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 8000); // 8 секунд для мобильных

    try {
      const response = await fetch(url, {
        method: "GET",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
        },
        signal: controller.signal,
      });

      clearTimeout(timeoutId);

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();

      // Кэшируем результат с увеличенным временем для мобильных
      this.setCacheData(cacheKey, result);

      return result;
    } catch (error) {
      clearTimeout(timeoutId);
      throw error;
    }
  }

  /**
   * Проверка качества соединения
   *
   * @returns {string} тип соединения
   */
  getConnectionType() {
    if ("connection" in navigator) {
      return navigator.connection.effectiveType || "unknown";
    }
    return "unknown";
  }

  /**
   * Адаптация поведения под тип соединения
   */
  adaptToConnection() {
    const connectionType = this.getConnectionType();

    switch (connectionType) {
      case "slow-2g":
      case "2g":
        // Увеличиваем время кэша и debounce для медленных соединений
        this.cacheTimeout = 30 * 60 * 1000; // 30 минут
        this.debounceDelay = 1000; // 1 секунда
        break;
      case "3g":
        this.cacheTimeout = 20 * 60 * 1000; // 20 минут
        this.debounceDelay = 700; // 700ms
        break;
      case "4g":
      default:
        // Стандартные настройки
        break;
    }
  }

  /**
   * Уничтожение компонента
   */
  destroy() {
    // Очищаем таймауты
    if (this.debounceTimeout) {
      clearTimeout(this.debounceTimeout);
    }

    // Удаляем обработчики событий
    if (this.selectElement) {
      this.selectElement.removeEventListener(
        "change",
        this.debouncedTriggerChange
      );
    }

    // Удаляем мобильные обработчики
    if (this.isTouch && this.selectElement) {
      this.selectElement.removeEventListener(
        "touchstart",
        this.handleTouchStart
      );
      this.selectElement.removeEventListener("touchend", this.handleTouchEnd);
    }

    this.clearCache();

    if (this.container) {
      this.container.innerHTML = "";
    }

    this.container = null;
    this.selectElement = null;
    this.loadingElement = null;
    this.errorElement = null;
    this.onChange = null;
    this.countries = [];
    this.selectedCountry = null;
  }
}

// Экспорт для использования в других модулях
if (typeof module !== "undefined" && module.exports) {
  module.exports = CountryFilter;
}
