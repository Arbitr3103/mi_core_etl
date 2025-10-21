/**
 * Regional Analytics Dashboard Configuration
 * Configuration settings and constants for the dashboard
 */

// ===================================================================
// API CONFIGURATION
// ===================================================================

const API_CONFIG = {
  // Base URL for analytics API
  baseUrl: "/api/analytics",

  // API endpoints
  endpoints: {
    marketplaceComparison: "/marketplace-comparison",
    topProducts: "/top-products",
    salesDynamics: "/sales-dynamics",
    regions: "/regions",
    health: "/health",
  },

  // Request settings
  timeout: 30000, // 30 seconds
  retryAttempts: 3,
  retryDelay: 1000, // 1 second

  // Default parameters
  defaultParams: {
    limit: 20,
    marketplace: "all",
    period: "monthly",
  },
};

// ===================================================================
// DASHBOARD CONFIGURATION
// ===================================================================

const DASHBOARD_CONFIG = {
  // Date range settings
  dateRange: {
    defaultDays: 30,
    maxDays: 365,
    minDate: "2024-01-01",
  },

  // Chart settings
  charts: {
    colors: {
      primary: "#0d6efd",
      success: "#198754",
      info: "#0dcaf0",
      warning: "#ffc107",
      danger: "#dc3545",
      ozon: "#005bff",
      wildberries: "#cb11ab",
    },

    // Chart.js default options
    defaultOptions: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom",
          labels: {
            padding: 20,
            usePointStyle: true,
          },
        },
        tooltip: {
          backgroundColor: "rgba(0, 0, 0, 0.8)",
          titleColor: "#fff",
          bodyColor: "#fff",
          borderColor: "#fff",
          borderWidth: 1,
          cornerRadius: 6,
          displayColors: true,
        },
      },
      animation: {
        duration: 1000,
        easing: "easeInOutQuart",
      },
    },
  },

  // Table settings
  tables: {
    pageSize: 20,
    sortable: true,
    searchable: true,
  },

  // Update intervals (in milliseconds)
  updateIntervals: {
    kpi: 300000, // 5 minutes
    charts: 600000, // 10 minutes
    tables: 300000, // 5 minutes
  },

  // Loading states
  loading: {
    showDelay: 500, // Show loading after 500ms
    minDuration: 1000, // Minimum loading duration
  },
};

// ===================================================================
// FORMATTING CONFIGURATION
// ===================================================================

const FORMAT_CONFIG = {
  // Number formatting
  numbers: {
    locale: "ru-RU",
    currency: "RUB",

    // Format options
    options: {
      currency: {
        style: "currency",
        currency: "RUB",
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
      },

      decimal: {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
      },

      percent: {
        style: "percent",
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
      },

      compact: {
        notation: "compact",
        compactDisplay: "short",
      },
    },
  },

  // Date formatting
  dates: {
    locale: "ru-RU",
    timezone: "Europe/Moscow",

    formats: {
      short: { day: "2-digit", month: "2-digit", year: "numeric" },
      medium: { day: "2-digit", month: "short", year: "numeric" },
      long: { weekday: "long", day: "2-digit", month: "long", year: "numeric" },
      monthYear: { month: "long", year: "numeric" },
    },
  },
};

// ===================================================================
// UI CONFIGURATION
// ===================================================================

const UI_CONFIG = {
  // Animation settings
  animations: {
    duration: 300,
    easing: "ease-in-out",
  },

  // Notification settings
  notifications: {
    duration: 5000,
    position: "top-right",
  },

  // Modal settings
  modals: {
    backdrop: true,
    keyboard: true,
    focus: true,
  },

  // Responsive breakpoints (Bootstrap 5)
  breakpoints: {
    xs: 0,
    sm: 576,
    md: 768,
    lg: 992,
    xl: 1200,
    xxl: 1400,
  },
};

// ===================================================================
// ERROR MESSAGES
// ===================================================================

const ERROR_MESSAGES = {
  // API errors
  api: {
    network: "Ошибка сети. Проверьте подключение к интернету.",
    timeout: "Превышено время ожидания запроса.",
    server: "Ошибка сервера. Попробуйте позже.",
    notFound: "Запрашиваемые данные не найдены.",
    unauthorized: "Нет доступа к данным.",
    validation: "Некорректные параметры запроса.",
  },

  // Data errors
  data: {
    empty: "Нет данных для отображения.",
    invalid: "Получены некорректные данные.",
    parsing: "Ошибка обработки данных.",
  },

  // UI errors
  ui: {
    chartRender: "Ошибка отображения графика.",
    tableRender: "Ошибка отображения таблицы.",
    dateRange: "Некорректный диапазон дат.",
  },
};

// ===================================================================
// SUCCESS MESSAGES
// ===================================================================

const SUCCESS_MESSAGES = {
  dataLoaded: "Данные успешно загружены",
  filtersApplied: "Фильтры применены",
  exportCompleted: "Экспорт завершен",
};

// ===================================================================
// UTILITY FUNCTIONS
// ===================================================================

/**
 * Get current date in YYYY-MM-DD format
 * @returns {string}
 */
function getCurrentDate() {
  return new Date().toISOString().split("T")[0];
}

/**
 * Get date N days ago in YYYY-MM-DD format
 * @param {number} days
 * @returns {string}
 */
function getDateDaysAgo(days) {
  const date = new Date();
  date.setDate(date.getDate() - days);
  return date.toISOString().split("T")[0];
}

/**
 * Validate date range
 * @param {string} dateFrom
 * @param {string} dateTo
 * @returns {boolean}
 */
function isValidDateRange(dateFrom, dateTo) {
  const from = new Date(dateFrom);
  const to = new Date(dateTo);
  const minDate = new Date(DASHBOARD_CONFIG.dateRange.minDate);
  const maxDate = new Date();

  return (
    from <= to &&
    from >= minDate &&
    to <= maxDate &&
    (to - from) / (1000 * 60 * 60 * 24) <= DASHBOARD_CONFIG.dateRange.maxDays
  );
}

/**
 * Get API URL for endpoint
 * @param {string} endpoint
 * @returns {string}
 */
function getApiUrl(endpoint) {
  return API_CONFIG.baseUrl + (API_CONFIG.endpoints[endpoint] || endpoint);
}

/**
 * Deep merge objects
 * @param {object} target
 * @param {object} source
 * @returns {object}
 */
function deepMerge(target, source) {
  const result = { ...target };

  for (const key in source) {
    if (
      source[key] &&
      typeof source[key] === "object" &&
      !Array.isArray(source[key])
    ) {
      result[key] = deepMerge(result[key] || {}, source[key]);
    } else {
      result[key] = source[key];
    }
  }

  return result;
}

// ===================================================================
// EXPORT CONFIGURATION
// ===================================================================

// Make configuration available globally
window.API_CONFIG = API_CONFIG;
window.DASHBOARD_CONFIG = DASHBOARD_CONFIG;
window.FORMAT_CONFIG = FORMAT_CONFIG;
window.UI_CONFIG = UI_CONFIG;
window.ERROR_MESSAGES = ERROR_MESSAGES;
window.SUCCESS_MESSAGES = SUCCESS_MESSAGES;

// Export utility functions
window.getCurrentDate = getCurrentDate;
window.getDateDaysAgo = getDateDaysAgo;
window.isValidDateRange = isValidDateRange;
window.getApiUrl = getApiUrl;
window.deepMerge = deepMerge;
