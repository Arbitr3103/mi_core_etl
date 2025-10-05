/**
 * Ozon Lazy Loader Component
 *
 * Implements lazy loading for large datasets in Ozon analytics
 * Supports virtual scrolling, pagination, and progressive data loading
 *
 * @version 1.0
 * @author Manhattan System
 */

class OzonLazyLoader {
  constructor(containerId, options = {}) {
    this.containerId = containerId;
    this.container = document.getElementById(containerId);

    this.options = {
      pageSize: 50,
      bufferSize: 10, // Number of items to load ahead
      threshold: 200, // Pixels from bottom to trigger loading
      apiEndpoint: "/src/api/ozon-analytics.php",
      loadingTemplate: this.getDefaultLoadingTemplate(),
      errorTemplate: this.getDefaultErrorTemplate(),
      emptyTemplate: this.getDefaultEmptyTemplate(),
      itemTemplate: null, // Must be provided by user
      virtualScrolling: false,
      estimatedItemHeight: 60,
      ...options,
    };

    this.state = {
      currentPage: 1,
      totalPages: 0,
      totalItems: 0,
      isLoading: false,
      hasError: false,
      items: [],
      renderedItems: new Set(),
      scrollTop: 0,
      containerHeight: 0,
      visibleStartIndex: 0,
      visibleEndIndex: 0,
    };

    this.observers = {
      intersection: null,
      resize: null,
    };

    this.cache = new Map();
    this.loadingPromises = new Map();

    this.init();
  }

  /**
   * Initialize the lazy loader
   */
  init() {
    if (!this.container) {
      console.error(`Container with ID '${this.containerId}' not found`);
      return;
    }

    if (!this.options.itemTemplate) {
      console.error("itemTemplate option is required");
      return;
    }

    this.setupContainer();
    this.setupIntersectionObserver();
    this.setupResizeObserver();
    this.bindEvents();

    // Load initial data
    this.loadPage(1);
  }

  /**
   * Setup container structure
   */
  setupContainer() {
    this.container.classList.add("ozon-lazy-container");

    if (this.options.virtualScrolling) {
      this.container.style.position = "relative";
      this.container.style.overflow = "auto";
      this.container.style.height = this.container.style.height || "400px";

      // Create virtual scroll elements
      this.scrollContainer = document.createElement("div");
      this.scrollContainer.className = "virtual-scroll-container";
      this.scrollContainer.style.position = "absolute";
      this.scrollContainer.style.top = "0";
      this.scrollContainer.style.left = "0";
      this.scrollContainer.style.right = "0";

      this.viewport = document.createElement("div");
      this.viewport.className = "virtual-viewport";
      this.viewport.style.position = "relative";

      this.scrollContainer.appendChild(this.viewport);
      this.container.appendChild(this.scrollContainer);
    } else {
      // Create items container
      this.itemsContainer = document.createElement("div");
      this.itemsContainer.className = "lazy-items-container";
      this.container.appendChild(this.itemsContainer);

      // Create loading trigger element
      this.loadingTrigger = document.createElement("div");
      this.loadingTrigger.className = "loading-trigger";
      this.loadingTrigger.style.height = "1px";
      this.container.appendChild(this.loadingTrigger);
    }

    // Create status container
    this.statusContainer = document.createElement("div");
    this.statusContainer.className = "lazy-status-container";
    this.container.appendChild(this.statusContainer);
  }

  /**
   * Setup intersection observer for infinite scrolling
   */
  setupIntersectionObserver() {
    if (!this.options.virtualScrolling && "IntersectionObserver" in window) {
      this.observers.intersection = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (
              entry.isIntersecting &&
              !this.state.isLoading &&
              this.hasMorePages()
            ) {
              this.loadNextPage();
            }
          });
        },
        {
          root: this.container,
          rootMargin: `${this.options.threshold}px`,
          threshold: 0.1,
        }
      );

      if (this.loadingTrigger) {
        this.observers.intersection.observe(this.loadingTrigger);
      }
    }
  }

  /**
   * Setup resize observer for virtual scrolling
   */
  setupResizeObserver() {
    if (this.options.virtualScrolling && "ResizeObserver" in window) {
      this.observers.resize = new ResizeObserver((entries) => {
        for (const entry of entries) {
          this.state.containerHeight = entry.contentRect.height;
          this.updateVirtualScroll();
        }
      });

      this.observers.resize.observe(this.container);
    }
  }

  /**
   * Bind event listeners
   */
  bindEvents() {
    if (this.options.virtualScrolling) {
      this.container.addEventListener(
        "scroll",
        this.handleVirtualScroll.bind(this)
      );
    }

    // Handle retry buttons
    this.container.addEventListener("click", (event) => {
      if (event.target.classList.contains("retry-button")) {
        this.retry();
      }
    });
  }

  /**
   * Load a specific page
   * @param {number} page - Page number to load
   * @param {Object} filters - Additional filters
   * @returns {Promise} Loading promise
   */
  async loadPage(page, filters = {}) {
    const cacheKey = this.getCacheKey(page, filters);

    // Check cache first
    if (this.cache.has(cacheKey)) {
      const cachedData = this.cache.get(cacheKey);
      if (Date.now() - cachedData.timestamp < 300000) {
        // 5 minutes cache
        this.handlePageData(cachedData.data, page);
        return cachedData.data;
      }
    }

    // Check if already loading this page
    if (this.loadingPromises.has(cacheKey)) {
      return this.loadingPromises.get(cacheKey);
    }

    this.state.isLoading = true;
    this.state.hasError = false;
    this.updateStatus();

    const loadingPromise = this.fetchPageData(page, filters);
    this.loadingPromises.set(cacheKey, loadingPromise);

    try {
      const data = await loadingPromise;

      // Cache the result
      this.cache.set(cacheKey, {
        data: data,
        timestamp: Date.now(),
      });

      this.handlePageData(data, page);
      return data;
    } catch (error) {
      this.handleError(error);
      throw error;
    } finally {
      this.state.isLoading = false;
      this.loadingPromises.delete(cacheKey);
      this.updateStatus();
    }
  }

  /**
   * Load next page
   */
  async loadNextPage() {
    if (this.hasMorePages() && !this.state.isLoading) {
      await this.loadPage(this.state.currentPage + 1);
    }
  }

  /**
   * Fetch page data from API
   * @param {number} page - Page number
   * @param {Object} filters - Filters
   * @returns {Promise} API response
   */
  async fetchPageData(page, filters = {}) {
    const params = new URLSearchParams({
      action: "paginated-data",
      page: page,
      page_size: this.options.pageSize,
      ...filters,
    });

    const response = await fetch(
      `${this.options.apiEndpoint}?${params.toString()}`
    );

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    const result = await response.json();

    if (result.error) {
      throw new Error(result.error);
    }

    return result;
  }

  /**
   * Handle page data response
   * @param {Object} data - API response data
   * @param {number} page - Page number
   */
  handlePageData(data, page) {
    this.state.totalPages = data.pagination?.total_pages || 1;
    this.state.totalItems = data.pagination?.total_records || 0;
    this.state.currentPage = page;

    if (page === 1) {
      this.state.items = data.data || [];
    } else {
      this.state.items = [...this.state.items, ...(data.data || [])];
    }

    if (this.options.virtualScrolling) {
      this.updateVirtualScroll();
    } else {
      this.renderItems(data.data || []);
    }

    this.updateStatus();
  }

  /**
   * Handle loading error
   * @param {Error} error - Error object
   */
  handleError(error) {
    console.error("Lazy loading error:", error);
    this.state.hasError = true;
    this.updateStatus();
  }

  /**
   * Render items (non-virtual scrolling)
   * @param {Array} items - Items to render
   */
  renderItems(items) {
    if (!this.itemsContainer) return;

    const fragment = document.createDocumentFragment();

    items.forEach((item, index) => {
      const itemElement = this.createItemElement(
        item,
        this.state.items.length - items.length + index
      );
      fragment.appendChild(itemElement);
    });

    this.itemsContainer.appendChild(fragment);
  }

  /**
   * Create item element from template
   * @param {Object} item - Item data
   * @param {number} index - Item index
   * @returns {HTMLElement} Item element
   */
  createItemElement(item, index) {
    const template = document.createElement("div");
    template.innerHTML = this.options.itemTemplate(item, index);
    return template.firstElementChild;
  }

  /**
   * Handle virtual scrolling
   */
  handleVirtualScroll() {
    if (!this.options.virtualScrolling) return;

    this.state.scrollTop = this.container.scrollTop;
    this.updateVirtualScroll();
  }

  /**
   * Update virtual scroll rendering
   */
  updateVirtualScroll() {
    if (!this.options.virtualScrolling || !this.viewport) return;

    const itemHeight = this.options.estimatedItemHeight;
    const containerHeight =
      this.state.containerHeight || this.container.clientHeight;
    const totalHeight = this.state.items.length * itemHeight;

    // Calculate visible range
    const startIndex = Math.floor(this.state.scrollTop / itemHeight);
    const endIndex = Math.min(
      startIndex +
        Math.ceil(containerHeight / itemHeight) +
        this.options.bufferSize,
      this.state.items.length
    );

    this.state.visibleStartIndex = Math.max(
      0,
      startIndex - this.options.bufferSize
    );
    this.state.visibleEndIndex = endIndex;

    // Update scroll container height
    this.scrollContainer.style.height = `${totalHeight}px`;

    // Render visible items
    this.renderVirtualItems();

    // Load more data if needed
    if (
      endIndex >= this.state.items.length - this.options.bufferSize &&
      this.hasMorePages() &&
      !this.state.isLoading
    ) {
      this.loadNextPage();
    }
  }

  /**
   * Render virtual items
   */
  renderVirtualItems() {
    if (!this.viewport) return;

    // Clear viewport
    this.viewport.innerHTML = "";

    const fragment = document.createDocumentFragment();
    const itemHeight = this.options.estimatedItemHeight;

    for (
      let i = this.state.visibleStartIndex;
      i < this.state.visibleEndIndex;
      i++
    ) {
      if (i >= this.state.items.length) break;

      const item = this.state.items[i];
      const itemElement = this.createItemElement(item, i);

      // Position the item
      itemElement.style.position = "absolute";
      itemElement.style.top = `${i * itemHeight}px`;
      itemElement.style.left = "0";
      itemElement.style.right = "0";
      itemElement.style.height = `${itemHeight}px`;

      fragment.appendChild(itemElement);
    }

    this.viewport.appendChild(fragment);
  }

  /**
   * Update status display
   */
  updateStatus() {
    if (!this.statusContainer) return;

    let content = "";

    if (this.state.isLoading && this.state.items.length === 0) {
      content = this.options.loadingTemplate;
    } else if (this.state.hasError) {
      content = this.options.errorTemplate;
    } else if (this.state.items.length === 0) {
      content = this.options.emptyTemplate;
    } else if (this.state.isLoading) {
      content =
        '<div class="loading-more">Загрузка дополнительных данных...</div>';
    }

    this.statusContainer.innerHTML = content;
  }

  /**
   * Check if there are more pages to load
   * @returns {boolean} True if more pages available
   */
  hasMorePages() {
    return this.state.currentPage < this.state.totalPages;
  }

  /**
   * Generate cache key
   * @param {number} page - Page number
   * @param {Object} filters - Filters
   * @returns {string} Cache key
   */
  getCacheKey(page, filters) {
    return `page_${page}_${JSON.stringify(filters)}`;
  }

  /**
   * Retry loading after error
   */
  retry() {
    this.state.hasError = false;
    this.loadPage(this.state.currentPage);
  }

  /**
   * Refresh data (clear cache and reload)
   * @param {Object} filters - New filters
   */
  refresh(filters = {}) {
    this.cache.clear();
    this.state.items = [];
    this.state.currentPage = 1;
    this.state.hasError = false;

    if (this.itemsContainer) {
      this.itemsContainer.innerHTML = "";
    }

    if (this.viewport) {
      this.viewport.innerHTML = "";
    }

    this.loadPage(1, filters);
  }

  /**
   * Get current items
   * @returns {Array} Current items
   */
  getItems() {
    return this.state.items;
  }

  /**
   * Get loading state
   * @returns {boolean} True if loading
   */
  isLoading() {
    return this.state.isLoading;
  }

  /**
   * Get default loading template
   * @returns {string} HTML template
   */
  getDefaultLoadingTemplate() {
    return `
      <div class="lazy-loading">
        <div class="spinner-border text-primary" role="status">
          <span class="visually-hidden">Загрузка...</span>
        </div>
        <div class="mt-2">Загрузка данных...</div>
      </div>
    `;
  }

  /**
   * Get default error template
   * @returns {string} HTML template
   */
  getDefaultErrorTemplate() {
    return `
      <div class="lazy-error">
        <div class="text-danger mb-2">
          <i class="fas fa-exclamation-triangle"></i>
          Ошибка загрузки данных
        </div>
        <button type="button" class="btn btn-outline-primary btn-sm retry-button">
          Повторить попытку
        </button>
      </div>
    `;
  }

  /**
   * Get default empty template
   * @returns {string} HTML template
   */
  getDefaultEmptyTemplate() {
    return `
      <div class="lazy-empty">
        <div class="text-muted">
          <i class="fas fa-inbox"></i>
          <div class="mt-2">Нет данных для отображения</div>
        </div>
      </div>
    `;
  }

  /**
   * Destroy the lazy loader
   */
  destroy() {
    // Disconnect observers
    if (this.observers.intersection) {
      this.observers.intersection.disconnect();
    }
    if (this.observers.resize) {
      this.observers.resize.disconnect();
    }

    // Clear cache and promises
    this.cache.clear();
    this.loadingPromises.clear();

    // Remove event listeners
    this.container.removeEventListener("scroll", this.handleVirtualScroll);

    // Clear container
    if (this.container) {
      this.container.innerHTML = "";
      this.container.classList.remove("ozon-lazy-container");
    }
  }
}

// Export for use in other modules
if (typeof module !== "undefined" && module.exports) {
  module.exports = OzonLazyLoader;
}
