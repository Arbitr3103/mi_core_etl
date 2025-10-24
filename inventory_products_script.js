// Inventory Products Dashboard JavaScript
class InventoryProductsDashboard {
  constructor() {
    this.apiBaseUrl = "api/inventory-analytics.php";
    this.currentActivityFilter = "active";
    this.currentPage = 1;
    this.itemsPerPage = 50;
    this.currentSort = { field: "total_stock", direction: "desc" };
    this.searchQuery = "";
    this.products = [];
    this.filteredProducts = [];
    this.activityStats = null;

    this.init();
  }

  async init() {
    this.showLoading(true);
    await this.loadProducts();
    this.setupEventListeners();
    this.renderDashboard();
    this.showLoading(false);
    this.updateConnectionStatus("connected");
  }

  async loadProducts() {
    try {
      const response = await fetch(
        `${this.apiBaseUrl}?action=dashboard&activity_filter=${this.currentActivityFilter}&limit=all`
      );
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data = await response.json();

      if (data.status === "success") {
        // Combine all products from different categories
        this.products = [];

        if (data.data.critical_products && data.data.critical_products.items) {
          this.products = this.products.concat(
            data.data.critical_products.items
          );
        }
        if (
          data.data.low_stock_products &&
          data.data.low_stock_products.items
        ) {
          this.products = this.products.concat(
            data.data.low_stock_products.items
          );
        }
        if (
          data.data.overstock_products &&
          data.data.overstock_products.items
        ) {
          this.products = this.products.concat(
            data.data.overstock_products.items
          );
        }

        this.activityStats = data.metadata.activity_stats;
        this.applyFiltersAndSort();
        this.updateLastUpdateTime();
      } else {
        throw new Error(data.message || "Failed to load products");
      }
    } catch (error) {
      console.error("Error loading products:", error);
      this.showError("Ошибка загрузки данных: " + error.message);
      this.updateConnectionStatus("error");
    }
  }

  setupEventListeners() {
    // Activity filter buttons
    document.querySelectorAll(".activity-filter-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        this.setActiveActivityFilter(e.target);
        this.currentActivityFilter = e.target.dataset.activity;
        this.currentPage = 1;
        this.refreshProducts();
      });
    });

    // Search input
    const searchInput = document.getElementById("searchInput");
    searchInput.addEventListener("input", (e) => {
      this.searchQuery = e.target.value.toLowerCase();
      this.currentPage = 1;
      this.applyFiltersAndSort();
      this.renderProductsTable();
      this.renderPagination();
    });

    // Sort headers
    document.querySelectorAll(".sortable").forEach((header) => {
      header.addEventListener("click", (e) => {
        const field = e.target.dataset.sort;
        this.handleSort(field);
      });
    });
  }

  setActiveActivityFilter(activeBtn) {
    document.querySelectorAll(".activity-filter-btn").forEach((btn) => {
      btn.classList.remove("active");
    });
    activeBtn.classList.add("active");
  }

  handleSort(field) {
    if (this.currentSort.field === field) {
      this.currentSort.direction =
        this.currentSort.direction === "asc" ? "desc" : "asc";
    } else {
      this.currentSort.field = field;
      this.currentSort.direction = "desc";
    }

    this.applyFiltersAndSort();
    this.renderProductsTable();
    this.updateSortHeaders();
  }

  updateSortHeaders() {
    document.querySelectorAll(".sortable").forEach((header) => {
      header.classList.remove("asc", "desc");
      if (header.dataset.sort === this.currentSort.field) {
        header.classList.add(this.currentSort.direction);
      }
    });
  }

  applyFiltersAndSort() {
    // Apply search filter
    this.filteredProducts = this.products.filter((product) => {
      if (!this.searchQuery) return true;

      const searchFields = [
        product.sku?.toString() || "",
        product.name || "",
        product.warehouse || "",
      ];

      return searchFields.some((field) =>
        field.toLowerCase().includes(this.searchQuery)
      );
    });

    // Apply sorting
    this.filteredProducts.sort((a, b) => {
      let aValue = a[this.currentSort.field];
      let bValue = b[this.currentSort.field];

      // Handle different data types
      if (typeof aValue === "string") {
        aValue = aValue.toLowerCase();
        bValue = bValue.toLowerCase();
      }

      if (aValue < bValue) {
        return this.currentSort.direction === "asc" ? -1 : 1;
      }
      if (aValue > bValue) {
        return this.currentSort.direction === "asc" ? 1 : -1;
      }
      return 0;
    });
  }

  renderDashboard() {
    this.renderActivityStats();
    this.renderProductsTable();
    this.renderPagination();
    this.updateActivityFilterDisplay();
  }

  renderActivityStats() {
    const container = document.getElementById("activityStatsGrid");
    if (!container || !this.activityStats) return;

    const stats = this.activityStats;
    const totalProducts = stats.active_count + stats.inactive_count;

    // Calculate filtered stats
    let displayedProducts = 0;
    let displayedLabel = "";

    switch (this.currentActivityFilter) {
      case "active":
        displayedProducts = stats.active_count;
        displayedLabel = "активных товаров";
        break;
      case "inactive":
        displayedProducts = stats.inactive_count;
        displayedLabel = "неактивных товаров";
        break;
      case "all":
        displayedProducts = totalProducts;
        displayedLabel = "всего товаров";
        break;
    }

    container.innerHTML = `
            <div class="stat-item total-stat">
                <div class="stat-number">${this.filteredProducts.length.toLocaleString()}</div>
                <div class="stat-label">Отображено</div>
                <div class="stat-percentage">${displayedLabel}</div>
            </div>
            
            <div class="stat-item active-stat">
                <div class="stat-number">${stats.active_count.toLocaleString()}</div>
                <div class="stat-label">Активные товары</div>
                <div class="stat-percentage">${
                  stats.active_percentage
                }% от общего</div>
            </div>
            
            <div class="stat-item inactive-stat">
                <div class="stat-number">${stats.inactive_count.toLocaleString()}</div>
                <div class="stat-label">Неактивные товары</div>
                <div class="stat-percentage">${
                  stats.inactive_percentage
                }% от общего</div>
            </div>
            
            <div class="stat-item">
                <div class="stat-number">${totalProducts.toLocaleString()}</div>
                <div class="stat-label">Всего товаров</div>
                <div class="stat-percentage">в системе</div>
            </div>
        `;
  }

  renderProductsTable() {
    const tbody = document.getElementById("productsTableBody");
    const productsCount = document.getElementById("productsCount");

    if (!tbody) return;

    // Update products count
    productsCount.textContent = `${this.filteredProducts.length} товаров`;
    productsCount.className = `metric-change ${
      this.filteredProducts.length > 0 ? "positive" : "neutral"
    }`;

    if (this.filteredProducts.length === 0) {
      tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="table-loading">
                        ${
                          this.searchQuery
                            ? `Товары не найдены по запросу "${this.searchQuery}"`
                            : "Нет товаров для отображения"
                        }
                    </td>
                </tr>
            `;
      return;
    }

    // Calculate pagination
    const startIndex = (this.currentPage - 1) * this.itemsPerPage;
    const endIndex = startIndex + this.itemsPerPage;
    const pageProducts = this.filteredProducts.slice(startIndex, endIndex);

    tbody.innerHTML = pageProducts
      .map((product) => {
        const isActive = product.activity_status === "active";
        const isZeroStock = product.total_stock === 0;
        const isVeryLowStock =
          product.total_stock > 0 && product.total_stock <= 2;

        // Determine stock cell class
        let stockCellClass = product.activity_status;
        if (isZeroStock) {
          stockCellClass = "zero-stock";
        } else if (isVeryLowStock) {
          stockCellClass = "very-low-stock";
        }

        return `
                <tr class="product-row ${product.activity_status}">
                    <td class="product-sku"><strong>${product.sku}</strong></td>
                    <td class="product-name">${product.name}</td>
                    <td>${product.warehouse}</td>
                    <td class="total-stock-cell ${stockCellClass}">
                        ${product.total_stock.toLocaleString()}
                    </td>
                    <td>${(product.quantity_present || 0).toLocaleString()}</td>
                    <td>${(product.available_stock || 0).toLocaleString()}</td>
                    <td>${(product.reserved_stock || 0).toLocaleString()}</td>
                    <td>
                        <span class="activity-badge ${product.activity_status}">
                            ${isActive ? "✅ Активный" : "❌ Неактивный"}
                        </span>
                    </td>
                    <td>${this.formatDate(product.last_updated)}</td>
                </tr>
            `;
      })
      .join("");
  }

  renderPagination() {
    const container = document.getElementById("pagination");
    if (!container) return;

    const totalPages = Math.ceil(
      this.filteredProducts.length / this.itemsPerPage
    );

    if (totalPages <= 1) {
      container.innerHTML = "";
      return;
    }

    let paginationHTML = "";

    // Previous button
    paginationHTML += `
            <button ${
              this.currentPage === 1 ? "disabled" : ""
            } onclick="dashboard.goToPage(${this.currentPage - 1})">
                ← Предыдущая
            </button>
        `;

    // Page numbers
    const startPage = Math.max(1, this.currentPage - 2);
    const endPage = Math.min(totalPages, this.currentPage + 2);

    if (startPage > 1) {
      paginationHTML += `<button onclick="dashboard.goToPage(1)">1</button>`;
      if (startPage > 2) {
        paginationHTML += `<span>...</span>`;
      }
    }

    for (let i = startPage; i <= endPage; i++) {
      paginationHTML += `
                <button class="${
                  i === this.currentPage ? "current-page" : ""
                }" onclick="dashboard.goToPage(${i})">
                    ${i}
                </button>
            `;
    }

    if (endPage < totalPages) {
      if (endPage < totalPages - 1) {
        paginationHTML += `<span>...</span>`;
      }
      paginationHTML += `<button onclick="dashboard.goToPage(${totalPages})">${totalPages}</button>`;
    }

    // Next button
    paginationHTML += `
            <button ${
              this.currentPage === totalPages ? "disabled" : ""
            } onclick="dashboard.goToPage(${this.currentPage + 1})">
                Следующая →
            </button>
        `;

    container.innerHTML = paginationHTML;
  }

  goToPage(page) {
    this.currentPage = page;
    this.renderProductsTable();
    this.renderPagination();
  }

  updateActivityFilterDisplay() {
    const statusElement = document.getElementById("connectionStatus");
    if (statusElement) {
      const filterText = this.getActivityFilterText();
      const filterIcon = this.getActivityFilterIcon();
      statusElement.innerHTML = `${filterIcon} ${filterText}`;
    }

    const statsIndicator = document.getElementById("statsFilterIndicator");
    if (statsIndicator) {
      const filterText = this.getActivityFilterText();
      const filterIcon = this.getActivityFilterIcon();
      statsIndicator.innerHTML = `${filterIcon} Показаны: ${filterText}`;
    }
  }

  getActivityFilterText() {
    switch (this.currentActivityFilter) {
      case "active":
        return "активных товаров";
      case "inactive":
        return "неактивных товаров";
      case "all":
        return "всего товаров";
      default:
        return "товаров";
    }
  }

  getActivityFilterIcon() {
    switch (this.currentActivityFilter) {
      case "active":
        return "✅";
      case "inactive":
        return "❌";
      case "all":
        return "📋";
      default:
        return "📊";
    }
  }

  formatDate(dateString) {
    if (!dateString) return "N/A";

    try {
      const date = new Date(dateString);
      return date.toLocaleString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    } catch (error) {
      return dateString;
    }
  }

  showLoading(show) {
    const overlay = document.getElementById("loadingOverlay");
    if (overlay) {
      overlay.style.display = show ? "flex" : "none";
    }
  }

  showError(message) {
    alert("Ошибка: " + message);
  }

  updateLastUpdateTime() {
    const element = document.getElementById("lastUpdate");
    if (element) {
      element.textContent = "Обновлено: " + new Date().toLocaleString("ru-RU");
    }
  }

  updateConnectionStatus(status) {
    const statusElement = document.getElementById("connectionStatus");
    const apiStatusElement = document.getElementById("apiStatus");

    const statuses = {
      connected: { text: "🟢 Подключено", color: "#38a169" },
      error: { text: "🔴 Ошибка", color: "#e53e3e" },
      loading: { text: "🔄 Загрузка...", color: "#d69e2e" },
    };

    const statusInfo = statuses[status] || statuses.loading;

    if (statusElement) {
      statusElement.textContent = statusInfo.text;
      statusElement.style.color = statusInfo.color;
    }

    if (apiStatusElement) {
      apiStatusElement.textContent = statusInfo.text;
      apiStatusElement.style.color = statusInfo.color;
    }
  }

  async refreshProducts() {
    this.updateConnectionStatus("loading");
    try {
      await this.loadProducts();
      this.renderDashboard();
      this.updateConnectionStatus("connected");
    } catch (error) {
      this.updateConnectionStatus("error");
      this.showError("Не удалось обновить данные");
    }
  }
}

// Global functions for HTML onclick handlers
function refreshProducts() {
  dashboard.refreshProducts();
}

// Initialize dashboard when DOM is loaded
let dashboard;
document.addEventListener("DOMContentLoaded", function () {
  dashboard = new InventoryProductsDashboard();
});
