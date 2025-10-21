/**
 * Простой контроллер для отображения полных списков товаров
 */
class InventoryDisplayController {
  constructor() {
    this.currentMode =
      localStorage.getItem("inventory-display-mode") || "top10";
    console.log("InventoryDisplayController initialized");
    this.init();
  }

  init() {
    // Ждем загрузки DOM
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", () => this.setup());
    } else {
      this.setup();
    }
  }

  setup() {
    this.createControls();
    this.attachEvents();
  }

  createControls() {
    // Находим секции товаров и добавляем контролы
    const sections = document.querySelectorAll(".products-card");
    sections.forEach((section, index) => {
      const h3 = section.querySelector("h3");
      if (!h3) return;

      const category =
        index === 0 ? "critical" : index === 1 ? "low_stock" : "overstock";

      const controls = document.createElement("div");
      controls.className = "display-controls";
      controls.innerHTML = `
                <div style="display: flex; justify-content: space-between; align-items: center; margin: 10px 0; padding: 10px; background: #f5f5f5; border-radius: 5px;">
                    <span class="item-counter" data-category="${category}">Загрузка...</span>
                    <div class="toggle-switch" style="display: flex; gap: 5px;">
                        <button class="toggle-btn ${
                          this.currentMode === "top10" ? "active" : ""
                        }" 
                                data-mode="top10" data-category="${category}"
                                style="padding: 5px 10px; border: 1px solid #ccc; background: ${
                                  this.currentMode === "top10"
                                    ? "#007bff"
                                    : "#fff"
                                }; color: ${
        this.currentMode === "top10" ? "#fff" : "#000"
      }; border-radius: 3px; cursor: pointer;">
                            Топ-10
                        </button>
                        <button class="toggle-btn ${
                          this.currentMode === "all" ? "active" : ""
                        }" 
                                data-mode="all" data-category="${category}"
                                style="padding: 5px 10px; border: 1px solid #ccc; background: ${
                                  this.currentMode === "all"
                                    ? "#007bff"
                                    : "#fff"
                                }; color: ${
        this.currentMode === "all" ? "#fff" : "#000"
      }; border-radius: 3px; cursor: pointer;">
                            Показать все
                        </button>
                    </div>
                </div>
            `;

      h3.parentNode.insertBefore(controls, h3.nextSibling);
    });
  }

  attachEvents() {
    document.addEventListener("click", (e) => {
      if (e.target.classList.contains("toggle-btn")) {
        const mode = e.target.dataset.mode;
        const category = e.target.dataset.category;
        this.switchMode(mode, category);
      }
    });
  }

  async switchMode(mode, category) {
    console.log("Switching to mode:", mode, "for category:", category);

    this.currentMode = mode;
    localStorage.setItem("inventory-display-mode", mode);

    // Обновляем кнопки
    document
      .querySelectorAll(`[data-category="${category}"] .toggle-btn`)
      .forEach((btn) => {
        const isActive = btn.dataset.mode === mode;
        btn.classList.toggle("active", isActive);
        btn.style.background = isActive ? "#007bff" : "#fff";
        btn.style.color = isActive ? "#fff" : "#000";
      });

    // Загружаем данные
    try {
      const limit = mode === "all" ? "all" : "10";
      const response = await fetch(
        `../api/inventory-analytics.php?action=dashboard&limit=${limit}`
      );
      const data = await response.json();

      if (data.status === "success") {
        this.updateDisplay(data.data);
      }
    } catch (error) {
      console.error("Error loading data:", error);
    }
  }

  updateDisplay(data) {
    // Обновляем счетчики
    this.updateCounter("critical", data.critical_products);
    this.updateCounter("low_stock", data.low_stock_products);
    this.updateCounter("overstock", data.overstock_products);
  }

  updateCounter(category, products) {
    const counter = document.querySelector(
      `[data-category="${category}"] .item-counter`
    );
    if (!counter) return;

    const totalCount = products.count || products.length || 0;
    const displayedCount = products.items
      ? products.items.length
      : products.length || 0;

    if (this.currentMode === "all") {
      counter.textContent = `Показано ${totalCount} из ${totalCount} товаров`;
    } else {
      counter.textContent = `Показано ${displayedCount} из ${totalCount} товаров`;
    }
  }

  getCurrentMode() {
    return this.currentMode;
  }
}

// Глобальная переменная для доступа
window.InventoryDisplayController = InventoryDisplayController;
