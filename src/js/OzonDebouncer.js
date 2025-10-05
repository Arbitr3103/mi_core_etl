/**
 * Ozon Debouncer Utility
 *
 * Provides debouncing functionality for filters, search, and other user interactions
 * Optimizes performance by reducing API calls and improving user experience
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonDebouncer {
  constructor() {
    this.timers = new Map();
    this.callbacks = new Map();
    this.lastValues = new Map();
  }

  /**
   * Debounce a function call
   * @param {string} key - Unique key for this debounced function
   * @param {Function} callback - Function to debounce
   * @param {number} delay - Delay in milliseconds
   * @param {boolean} immediate - Execute immediately on first call
   * @returns {Function} Debounced function
   */
  debounce(key, callback, delay = 300, immediate = false) {
    return (...args) => {
      const callNow = immediate && !this.timers.has(key);

      // Clear existing timer
      if (this.timers.has(key)) {
        clearTimeout(this.timers.get(key));
      }

      // Set new timer
      const timer = setTimeout(() => {
        this.timers.delete(key);
        if (!immediate) {
          callback.apply(this, args);
        }
      }, delay);

      this.timers.set(key, timer);

      // Execute immediately if requested
      if (callNow) {
        callback.apply(this, args);
      }
    };
  }

  /**
   * Throttle a function call
   * @param {string} key - Unique key for this throttled function
   * @param {Function} callback - Function to throttle
   * @param {number} limit - Time limit in milliseconds
   * @returns {Function} Throttled function
   */
  throttle(key, callback, limit = 100) {
    let inThrottle = false;

    return (...args) => {
      if (!inThrottle) {
        callback.apply(this, args);
        inThrottle = true;
        setTimeout(() => {
          inThrottle = false;
        }, limit);
      }
    };
  }

  /**
   * Cancel a debounced function
   * @param {string} key - Key of the debounced function to cancel
   */
  cancel(key) {
    if (this.timers.has(key)) {
      clearTimeout(this.timers.get(key));
      this.timers.delete(key);
    }
  }

  /**
   * Cancel all debounced functions
   */
  cancelAll() {
    this.timers.forEach((timer) => {
      clearTimeout(timer);
    });
    this.timers.clear();
  }

  /**
   * Check if a debounced function is pending
   * @param {string} key - Key to check
   * @returns {boolean} True if pending
   */
  isPending(key) {
    return this.timers.has(key);
  }

  /**
   * Get all pending keys
   * @returns {Array} Array of pending keys
   */
  getPendingKeys() {
    return Array.from(this.timers.keys());
  }

  /**
   * Flush a debounced function (execute immediately)
   * @param {string} key - Key of the function to flush
   */
  flush(key) {
    if (this.timers.has(key) && this.callbacks.has(key)) {
      clearTimeout(this.timers.get(key));
      this.timers.delete(key);

      const callback = this.callbacks.get(key);
      callback();
    }
  }

  /**
   * Create a debounced filter handler
   * @param {string} filterId - Filter element ID
   * @param {Function} callback - Callback function
   * @param {Object} options - Options
   * @returns {Function} Event handler
   */
  createFilterHandler(filterId, callback, options = {}) {
    const {
      delay = 500,
      minLength = 0,
      immediate = false,
      onChange = true,
      onInput = true,
      onKeyup = false,
    } = options;

    const element = document.getElementById(filterId);
    if (!element) {
      console.warn(`Filter element with ID '${filterId}' not found`);
      return null;
    }

    const debouncedCallback = this.debounce(
      `filter_${filterId}`,
      (value, element) => {
        if (value.length >= minLength) {
          callback(value, element);
        }
      },
      delay,
      immediate
    );

    const handler = (event) => {
      const value = event.target.value.trim();
      const lastValue = this.lastValues.get(filterId);

      // Only proceed if value changed
      if (value !== lastValue) {
        this.lastValues.set(filterId, value);
        debouncedCallback(value, element);
      }
    };

    // Bind events
    if (onChange) {
      element.addEventListener("change", handler);
    }
    if (onInput) {
      element.addEventListener("input", handler);
    }
    if (onKeyup) {
      element.addEventListener("keyup", handler);
    }

    return handler;
  }

  /**
   * Create a debounced search handler
   * @param {string} searchId - Search element ID
   * @param {Function} callback - Search callback
   * @param {Object} options - Options
   * @returns {Function} Event handler
   */
  createSearchHandler(searchId, callback, options = {}) {
    const {
      delay = 300,
      minLength = 2,
      immediate = false,
      clearOnEmpty = true,
    } = options;

    const element = document.getElementById(searchId);
    if (!element) {
      console.warn(`Search element with ID '${searchId}' not found`);
      return null;
    }

    const debouncedCallback = this.debounce(
      `search_${searchId}`,
      (query, element) => {
        if (query.length >= minLength) {
          callback(query, element);
        } else if (clearOnEmpty && query.length === 0) {
          callback("", element);
        }
      },
      delay,
      immediate
    );

    const handler = (event) => {
      const query = event.target.value.trim();
      const lastQuery = this.lastValues.get(searchId);

      if (query !== lastQuery) {
        this.lastValues.set(searchId, query);
        debouncedCallback(query, element);
      }
    };

    element.addEventListener("input", handler);

    // Handle Enter key for immediate search
    element.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        this.flush(`search_${searchId}`);
      }
    });

    return handler;
  }

  /**
   * Create a debounced form handler
   * @param {string} formId - Form element ID
   * @param {Function} callback - Form callback
   * @param {Object} options - Options
   * @returns {Function} Event handler
   */
  createFormHandler(formId, callback, options = {}) {
    const {
      delay = 500,
      immediate = false,
      watchFields = [], // Specific fields to watch
      excludeFields = [], // Fields to exclude
    } = options;

    const form = document.getElementById(formId);
    if (!form) {
      console.warn(`Form element with ID '${formId}' not found`);
      return null;
    }

    const debouncedCallback = this.debounce(
      `form_${formId}`,
      (formData, form) => {
        callback(formData, form);
      },
      delay,
      immediate
    );

    const getFormData = () => {
      const formData = new FormData(form);
      const data = {};

      for (const [key, value] of formData.entries()) {
        // Skip excluded fields
        if (excludeFields.includes(key)) continue;

        // Only include watched fields if specified
        if (watchFields.length > 0 && !watchFields.includes(key)) continue;

        data[key] = value;
      }

      return data;
    };

    const handler = (event) => {
      // Skip if event target is excluded
      if (excludeFields.includes(event.target.name)) return;

      // Skip if watching specific fields and this isn't one of them
      if (watchFields.length > 0 && !watchFields.includes(event.target.name))
        return;

      const formData = getFormData();
      const formDataString = JSON.stringify(formData);
      const lastFormData = this.lastValues.get(formId);

      if (formDataString !== lastFormData) {
        this.lastValues.set(formId, formDataString);
        debouncedCallback(formData, form);
      }
    };

    // Listen to various form events
    form.addEventListener("input", handler);
    form.addEventListener("change", handler);

    return handler;
  }

  /**
   * Create a debounced scroll handler
   * @param {string|HTMLElement} element - Element or element ID
   * @param {Function} callback - Scroll callback
   * @param {Object} options - Options
   * @returns {Function} Event handler
   */
  createScrollHandler(element, callback, options = {}) {
    const {
      delay = 100,
      immediate = false,
      threshold = 0, // Threshold for triggering callback
    } = options;

    const el =
      typeof element === "string" ? document.getElementById(element) : element;
    if (!el) {
      console.warn("Scroll element not found");
      return null;
    }

    const elementId = el.id || "scroll_" + Date.now();

    const debouncedCallback = this.debounce(
      `scroll_${elementId}`,
      (scrollData) => {
        callback(scrollData);
      },
      delay,
      immediate
    );

    const handler = (event) => {
      const scrollTop = el.scrollTop;
      const scrollHeight = el.scrollHeight;
      const clientHeight = el.clientHeight;
      const scrollPercentage =
        (scrollTop / (scrollHeight - clientHeight)) * 100;

      const scrollData = {
        scrollTop,
        scrollHeight,
        clientHeight,
        scrollPercentage,
        nearBottom: scrollPercentage >= 100 - threshold,
        element: el,
        event,
      };

      debouncedCallback(scrollData);
    };

    el.addEventListener("scroll", handler, { passive: true });
    return handler;
  }

  /**
   * Create a debounced resize handler
   * @param {Function} callback - Resize callback
   * @param {Object} options - Options
   * @returns {Function} Event handler
   */
  createResizeHandler(callback, options = {}) {
    const { delay = 250, immediate = false } = options;

    const debouncedCallback = this.debounce(
      "window_resize",
      (resizeData) => {
        callback(resizeData);
      },
      delay,
      immediate
    );

    const handler = (event) => {
      const resizeData = {
        width: window.innerWidth,
        height: window.innerHeight,
        event,
      };

      debouncedCallback(resizeData);
    };

    window.addEventListener("resize", handler);
    return handler;
  }

  /**
   * Destroy the debouncer and clean up
   */
  destroy() {
    this.cancelAll();
    this.callbacks.clear();
    this.lastValues.clear();
  }
}

// Create global instance
const ozonDebouncer = new OzonDebouncer();

// Export for use in other modules
if (typeof module !== "undefined" && module.exports) {
  module.exports = { OzonDebouncer, ozonDebouncer };
} else {
  window.OzonDebouncer = OzonDebouncer;
  window.ozonDebouncer = ozonDebouncer;
}
