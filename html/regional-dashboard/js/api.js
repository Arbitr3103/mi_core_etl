/**
 * API Client for Regional Analytics Dashboard
 * Handles all API communication with the backend analytics service
 */

class AnalyticsAPI {
  constructor() {
    this.baseUrl = API_CONFIG.baseUrl;
    this.timeout = API_CONFIG.timeout;
    this.retryAttempts = API_CONFIG.retryAttempts;
  }

  /**
   * Make HTTP request with error handling and retries
   * @param {string} endpoint - API endpoint
   * @param {Object} options - Request options
   * @returns {Promise<Object>} API response
   */
  async makeRequest(endpoint, options = {}) {
    const url = `${this.baseUrl}${endpoint}`;
    const defaultOptions = {
      method: "GET",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
      },
      timeout: this.timeout,
    };

    const requestOptions = { ...defaultOptions, ...options };

    for (let attempt = 1; attempt <= this.retryAttempts; attempt++) {
      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.timeout);

        const response = await fetch(url, {
          ...requestOptions,
          signal: controller.signal,
        });

        clearTimeout(timeoutId);

        if (!response.ok) {
          throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();
        return data;
      } catch (error) {
        console.warn(`API request attempt ${attempt} failed:`, error.message);

        if (attempt === this.retryAttempts) {
          throw new Error(
            `API request failed after ${this.retryAttempts} attempts: ${error.message}`
          );
        }

        // Wait before retry (exponential backoff)
        await new Promise((resolve) =>
          setTimeout(resolve, Math.pow(2, attempt) * 1000)
        );
      }
    }
  }

  /**
   * Build query string from parameters
   * @param {Object} params - Query parameters
   * @returns {string} Query string
   */
  buildQueryString(params) {
    const filteredParams = Object.entries(params)
      .filter(
        ([key, value]) => value !== null && value !== undefined && value !== ""
      )
      .map(
        ([key, value]) =>
          `${encodeURIComponent(key)}=${encodeURIComponent(value)}`
      );

    return filteredParams.length > 0 ? `?${filteredParams.join("&")}` : "";
  }

  /**
   * Get marketplace comparison data
   * @param {Object} filters - Date and marketplace filters
   * @returns {Promise<Object>} Marketplace comparison data
   */
  async getMarketplaceComparison(filters = {}) {
    const queryString = this.buildQueryString(filters);
    return await this.makeRequest(`/marketplace-comparison${queryString}`);
  }

  /**
   * Get top products by marketplace
   * @param {Object} filters - Marketplace and limit filters
   * @returns {Promise<Object>} Top products data
   */
  async getTopProducts(filters = {}) {
    const queryString = this.buildQueryString(filters);
    return await this.makeRequest(`/top-products${queryString}`);
  }

  /**
   * Get sales dynamics data
   * @param {Object} filters - Period and marketplace filters
   * @returns {Promise<Object>} Sales dynamics data
   */
  async getSalesDynamics(filters = {}) {
    const queryString = this.buildQueryString(filters);
    return await this.makeRequest(`/sales-dynamics${queryString}`);
  }

  /**
   * Get regional sales data (Phase 2)
   * @param {Object} filters - Date, region, and marketplace filters
   * @returns {Promise<Object>} Regional sales data
   */
  async getRegionalSales(filters = {}) {
    const queryString = this.buildQueryString(filters);
    return await this.makeRequest(`/regional-sales${queryString}`);
  }

  /**
   * Get dashboard summary data
   * @param {Object} filters - Date and marketplace filters
   * @returns {Promise<Object>} Dashboard summary data
   */
  async getDashboardSummary(filters = {}) {
    const queryString = this.buildQueryString(filters);
    return await this.makeRequest(`/dashboard-summary${queryString}`);
  }

  /**
   * Trigger Ozon data synchronization (Phase 2)
   * @returns {Promise<Object>} Sync status
   */
  async triggerOzonSync() {
    return await this.makeRequest("/ozon/sync-regional-data", {
      method: "POST",
    });
  }

  /**
   * Get sync status (Phase 2)
   * @returns {Promise<Object>} Current sync status
   */
  async getSyncStatus() {
    return await this.makeRequest("/ozon/sync-status");
  }
}

// Global API instance
window.analyticsAPI = new AnalyticsAPI();
