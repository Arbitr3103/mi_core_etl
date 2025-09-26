/**
 * Конфигурация для Country Filter API
 *
 * Этот файл содержит основные настройки для работы с API
 */

// Основная конфигурация API
const CountryFilterConfig = {
  // Базовый URL API сервера
  API_BASE_URL: "https://api.zavodprostavok.ru/api",

  // Альтернативные URL для разных окружений
  ENVIRONMENTS: {
    production: "https://api.zavodprostavok.ru/api",
    development: "http://localhost/api",
    local: "/api",
  },

  // Настройки таймаутов
  TIMEOUTS: {
    default: 10000, // 10 секунд
    mobile: 8000, // 8 секунд для мобильных
    retry: 5000, // 5 секунд для повторных попыток
  },

  // Настройки кэширования
  CACHE: {
    timeout: 15 * 60 * 1000, // 15 минут
    mobileTimeout: 20 * 60 * 1000, // 20 минут для мобильных
    maxSize: 50, // максимальный размер кэша
    mobileMaxSize: 30, // для мобильных устройств
  },

  // Настройки дебаунсинга
  DEBOUNCE: {
    default: 300, // 300ms
    mobile: 500, // 500ms для мобильных
    slow: 1000, // 1 секунда для медленных соединений
  },

  // Endpoints
  ENDPOINTS: {
    countries: "/countries.php",
    countriesByBrand: "/countries-by-brand.php",
    countriesByModel: "/countries-by-model.php",
    brands: "/brands.php",
    models: "/models.php",
    years: "/years.php",
    products: "/products.php",
    productsFilter: "/products-filter.php",
  },
};

// Функция для получения текущего API URL
CountryFilterConfig.getApiUrl = function (environment = "production") {
  return this.ENVIRONMENTS[environment] || this.API_BASE_URL;
};

// Функция для получения полного URL endpoint'а
CountryFilterConfig.getEndpointUrl = function (
  endpoint,
  environment = "production"
) {
  const baseUrl = this.getApiUrl(environment);
  const endpointPath = this.ENDPOINTS[endpoint] || endpoint;
  return baseUrl + endpointPath;
};

// Автоопределение окружения
CountryFilterConfig.detectEnvironment = function () {
  const hostname = window.location.hostname;

  if (hostname === "localhost" || hostname === "127.0.0.1") {
    return "local";
  } else if (hostname.includes("dev") || hostname.includes("test")) {
    return "development";
  } else {
    return "production";
  }
};

// Экспорт для использования в других файлах
if (typeof module !== "undefined" && module.exports) {
  module.exports = CountryFilterConfig;
} else if (typeof window !== "undefined") {
  window.CountryFilterConfig = CountryFilterConfig;
}
